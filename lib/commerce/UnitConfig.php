<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — UnitConfig  (Paint Unit P1: the layer-4 unit-rule hook)
//  commerce blueprint §M. This is the consumer the OrderabilityResolver layer-4
//  hook always calls. It reads `app_settings.commerce_unit_config` (JSON) and
//  decides whether a product's UNIT lets it be ordered right now:
//    • resolve the product's unit from its category (paint categories → 'paint')
//    • a unit switched off in config            → not orderable (unit_unavailable)
//    • a custom-tint paint row while custom tint → not orderable (custom_tint_off)
//      is disabled in config (the pack's "custom tint stays OFF until Prisma")
//    • everything else                          → orderable
//  Default-safe: no config / no unit mapping ⇒ PASS, so non-paint products and
//  pre-migration DBs are untouched (full regression stays green). Additive; no
//  engine edit; reads config only (admin-tunable live via AdminControlRoom).
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/PaintUnit.php';

class UnitConfig
{
    private static ?array $cfgCache = null;
    private static ?array $catUnitCache = null; // categoryId => unit slug (paint cats only)

    public const SETTING_KEY = 'commerce_unit_config';

    /** Read + decode commerce_unit_config. Absent/invalid ⇒ safe empty default. */
    public static function config(PDO $db): array
    {
        if (self::$cfgCache !== null) return self::$cfgCache;
        $cfg = ['units' => [], 'paint_category_slugs' => []];
        try {
            $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
            $s->execute([self::SETTING_KEY]);
            $v = $s->fetchColumn();
            if ($v !== false && $v !== null && $v !== '') {
                $j = json_decode((string)$v, true);
                if (is_array($j)) {
                    $cfg['units'] = is_array($j['units'] ?? null) ? $j['units'] : [];
                    $cfg['paint_category_slugs'] = is_array($j['paint_category_slugs'] ?? null) ? $j['paint_category_slugs'] : [];
                }
            }
        } catch (\Throwable $e) { /* default-safe */ }
        return self::$cfgCache = $cfg;
    }

    /** Map a category id → unit slug (today: 'paint' for the 7 paint categories), else null. */
    public static function unitForCategory(PDO $db, ?int $categoryId): ?string
    {
        if ($categoryId === null) return null;
        if (self::$catUnitCache === null) {
            self::$catUnitCache = [];
            $slugs = self::config($db)['paint_category_slugs'];
            if (!empty($slugs)) {
                try {
                    $in = implode(',', array_fill(0, count($slugs), '?'));
                    $q = $db->prepare("SELECT id FROM categories WHERE slug IN ($in)");
                    $q->execute(array_values($slugs));
                    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $cid) self::$catUnitCache[(int)$cid] = 'paint';
                } catch (\Throwable $e) { /* default-safe */ }
            }
        }
        return self::$catUnitCache[$categoryId] ?? null;
    }

    /** Is a unit enabled in config? Missing ⇒ enabled (default-on). */
    public static function unitEnabled(PDO $db, string $unit): bool
    {
        $u = self::config($db)['units'][$unit] ?? null;
        if (!is_array($u)) return true;
        return ($u['enabled'] ?? true) !== false;
    }

    /** Is a per-unit flag on? Missing ⇒ the given default. */
    public static function unitFlag(PDO $db, string $unit, string $flag, bool $default = false): bool
    {
        $u = self::config($db)['units'][$unit] ?? null;
        if (!is_array($u) || !array_key_exists($flag, $u)) return $default;
        return $u[$flag] === true || in_array((string)$u[$flag], ['1', 'true', 'yes'], true);
    }

    /**
     * Layer-4 unit rule. $attrs = decoded products.attributes.
     * @return array{ok:bool, reason:string}
     */
    public static function orderabilityRule(PDO $db, ?int $categoryId, array $attrs): array
    {
        $unit = self::unitForCategory($db, $categoryId);
        if ($unit === null) return ['ok' => true, 'reason' => 'ok']; // not a unit-governed product

        if (!self::unitEnabled($db, $unit)) return ['ok' => false, 'reason' => 'unit_unavailable'];

        if ($unit === 'paint' && PaintUnit::isCustomTint($attrs)) {
            // Custom tint is PER-BRAND: orderable only if the product's brand is in
            // the admin "enabled brands" list (commerce_custom_tint_brands). Empty
            // list = no brand live yet (the default). Brands are switched on one at
            // a time as each manufacturer is onboarded.
            $brand = isset($attrs['brand']) && is_string($attrs['brand']) ? $attrs['brand'] : null;
            if (!self::customTintBrandEnabled($db, $brand)) {
                return ['ok' => false, 'reason' => 'custom_tint_off'];
            }
        }
        return ['ok' => true, 'reason' => 'ok'];
    }

    /** The admin "enabled brands" list for custom tint (commerce_custom_tint_brands). Read fresh. */
    public static function customTintBrands(PDO $db): array
    {
        try {
            $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key='commerce_custom_tint_brands' LIMIT 1");
            $s->execute();
            $v = $s->fetchColumn();
            if ($v) { $j = json_decode((string)$v, true); if (is_array($j)) return array_values(array_filter(array_map(fn($b) => trim((string)$b), $j), fn($b) => $b !== '')); }
        } catch (\Throwable $e) { /* default-safe: none enabled */ }
        return [];
    }

    /** Is a brand's custom tint switched on? (case-insensitive) */
    public static function customTintBrandEnabled(PDO $db, ?string $brand): bool
    {
        if ($brand === null || trim($brand) === '') return false;
        $want = strtolower(trim($brand));
        foreach (self::customTintBrands($db) as $b) if (strtolower($b) === $want) return true;
        return false;
    }

    /** Test/long-process hook: drop the static caches after a config change. */
    public static function resetCache(): void { self::$cfgCache = null; self::$catUnitCache = null; }
}
