<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — PaintShadeImporter  (Paint Unit P6: structure, no data)
//  Loads the real shade/base/code fandeck (Prisma Pro export, CSV or JSON) into
//  commerce_paint_shades, then custom tint can be turned on. Built NOW, run with
//  the CEO's export LATER. Idempotent (upsert by brand+shade_code → no dupes on
//  re-run), validated (brand + shade_code required), dry-runnable, and the ENABLE
//  step is readiness-gated (refuses while the fandeck is empty). Pure lib +
//  config; no engine edit. The CLI wrapper is scripts/import_paint_shades.php.
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/UnitConfig.php';

class PaintShadeImporter
{
    /** Columns we accept from the export (others are ignored). */
    public const COLUMNS = ['brand', 'product_line', 'shade_code', 'shade_name', 'base_type', 'finish'];

    /** Parse CSV text → list of assoc rows keyed by (lowercased) header. */
    public static function parseCsv(string $text): array
    {
        $text = preg_replace("/^\xEF\xBB\xBF/", '', $text); // strip BOM
        $lines = preg_split('/\r\n|\r|\n/', trim($text));
        if (!$lines || count($lines) < 2) return [];
        $header = array_map(fn($h) => strtolower(trim($h)), str_getcsv(array_shift($lines)));
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $cells = str_getcsv($line);
            $row = [];
            foreach ($header as $i => $col) { $row[$col] = isset($cells[$i]) ? trim((string)$cells[$i]) : ''; }
            $rows[] = $row;
        }
        return $rows;
    }

    /** Parse JSON text (array of objects, or {shades:[...]}) → list of assoc rows. */
    public static function parseJson(string $text): array
    {
        $j = json_decode(trim($text), true);
        if (is_array($j) && isset($j['shades']) && is_array($j['shades'])) $j = $j['shades'];
        if (!is_array($j)) return [];
        $rows = [];
        foreach ($j as $r) {
            if (!is_array($r)) continue;
            $row = [];
            foreach ($r as $k => $v) { $row[strtolower(trim((string)$k))] = is_scalar($v) ? trim((string)$v) : ''; }
            $rows[] = $row;
        }
        return $rows;
    }

    /** Validate one row. Returns the list of errors (empty = valid). */
    public static function validateRow(array $r): array
    {
        $err = [];
        if (trim((string)($r['brand'] ?? '')) === '')      $err[] = 'brand_required';
        if (trim((string)($r['shade_code'] ?? '')) === '')  $err[] = 'shade_code_required';
        return $err;
    }

    /**
     * Import rows into commerce_paint_shades. Idempotent: an existing
     * (brand, shade_code) is updated, not duplicated. Dry-run writes nothing.
     * @return array{inserted:int, updated:int, invalid:int, total:int, errors:array}
     */
    public static function import(PDO $db, array $rows, bool $dryRun = false): array
    {
        $inserted = 0; $updated = 0; $invalid = 0; $errors = [];
        $sel = $db->prepare("SELECT id FROM commerce_paint_shades WHERE brand=? AND shade_code=? LIMIT 1");
        $ins = $db->prepare("INSERT INTO commerce_paint_shades (brand, product_line, shade_code, shade_name, base_type, finish, source, created_at) VALUES (?,?,?,?,?,?,?,NOW())");
        $upd = $db->prepare("UPDATE commerce_paint_shades SET product_line=?, shade_name=?, base_type=?, finish=?, source=? WHERE id=?");
        foreach ($rows as $n => $r) {
            $e = self::validateRow($r);
            if ($e) { $invalid++; $errors[] = ['row' => $n + 1, 'errors' => $e]; continue; }
            $brand = trim((string)$r['brand']); $code = trim((string)$r['shade_code']);
            $pl = $r['product_line'] ?? null; $sn = $r['shade_name'] ?? null;
            $bt = $r['base_type'] ?? null; $fin = $r['finish'] ?? null;
            $src = trim((string)($r['source'] ?? 'prisma')) ?: 'prisma';
            if ($dryRun) { $sel->execute([$brand, $code]); ($sel->fetchColumn() ? $updated++ : $inserted++); continue; }
            $sel->execute([$brand, $code]); $id = $sel->fetchColumn();
            if ($id) { $upd->execute([$pl, $sn, $bt, $fin, $src, (int)$id]); $updated++; }
            else     { $ins->execute([$brand, $pl, $code, $sn, $bt, $fin, $src]); $inserted++; }
        }
        return ['inserted' => $inserted, 'updated' => $updated, 'invalid' => $invalid, 'total' => count($rows), 'errors' => $errors];
    }

    /** How many shades are loaded (whole fandeck, or one brand). */
    public static function shadeCount(PDO $db, ?string $brand = null): int
    {
        if ($brand !== null) { $s = $db->prepare("SELECT COUNT(*) FROM commerce_paint_shades WHERE brand=?"); $s->execute([$brand]); return (int)$s->fetchColumn(); }
        return (int)$db->query("SELECT COUNT(*) FROM commerce_paint_shades")->fetchColumn();
    }

    /** Is custom tint safe to switch on? Needs fandeck data. */
    public static function enableReadiness(PDO $db): array
    {
        $count = self::shadeCount($db);
        $enabled = UnitConfig::unitFlag($db, 'paint', 'custom_tint_enabled', false);
        $reasons = [];
        if ($count === 0) $reasons[] = 'no_shade_data (import the Prisma export first)';
        if ($enabled)     $reasons[] = 'already_enabled';
        return ['ready' => $count > 0, 'shade_count' => $count, 'enabled' => $enabled, 'reasons' => $reasons];
    }

    /**
     * Turn custom tint ON — ONLY when readiness passes (fandeck has data).
     * The CEO runs this (import --enable) after the Prisma export lands.
     */
    public static function enable(PDO $db): array
    {
        $r = self::enableReadiness($db);
        if (!$r['ready']) return ['ok' => false, 'reason' => 'not_ready', 'readiness' => $r];

        $cur = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key='commerce_unit_config' LIMIT 1");
        $cur->execute();
        $cfg = json_decode((string)($cur->fetchColumn() ?: '{}'), true);
        if (!is_array($cfg)) $cfg = [];
        $cfg['units'] = is_array($cfg['units'] ?? null) ? $cfg['units'] : [];
        $cfg['units']['paint'] = is_array($cfg['units']['paint'] ?? null) ? $cfg['units']['paint'] : [];
        $cfg['units']['paint']['enabled'] = true;
        $cfg['units']['paint']['custom_tint_enabled'] = true;
        $db->prepare("INSERT INTO app_settings (setting_key,setting_value,value_type,is_public) VALUES ('commerce_unit_config',?, 'text',0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
           ->execute([json_encode($cfg)]);
        UnitConfig::resetCache();
        return ['ok' => true, 'enabled' => true, 'shade_count' => $r['shade_count']];
    }
}
