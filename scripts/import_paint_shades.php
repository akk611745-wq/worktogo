<?php
// ============================================================================
//  WorkToGo — Paint shade importer CLI  (Paint Unit P6)
// ----------------------------------------------------------------------------
//  Load the Prisma Pro fandeck export into commerce_paint_shades, then (with
//  --enable) turn custom tint on once the data is in. Idempotent + validated;
//  safe to re-run. The export file is DATA (read, never executed).
//
//  Usage:
//    php scripts/import_paint_shades.php <file.csv|file.json> [--dry-run] [--format=csv|json]
//    php scripts/import_paint_shades.php --readiness          # just report readiness
//    php scripts/import_paint_shades.php <file> --enable       # import then enable (readiness-gated)
//
//  No file + no flag → prints enable-readiness only. ADDITIVE; no engine edit.
// ============================================================================

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(2); }
define('SYSTEM_ROOT', dirname(__DIR__));
foreach (file(SYSTEM_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2); $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
    if (getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
}
require_once SYSTEM_ROOT . '/core/helpers/Database.php';
require_once SYSTEM_ROOT . '/lib/commerce/PaintShadeImporter.php';
$db = Database::getConnection();

$args = array_slice($argv, 1);
$flags = array_values(array_filter($args, fn($a) => str_starts_with($a, '--')));
$files = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));
$dryRun   = in_array('--dry-run', $flags, true);
$doEnable = in_array('--enable', $flags, true);
$enableNote = "NOTE: custom tint go-live is PER-BRAND — enable each brand via AdminControlRoom (commerce_custom_tint_brands) or CustomTint::enableBrand(\$db,'Berger'). The importer only loads data.";
$format   = null;
foreach ($flags as $f) { if (str_starts_with($f, '--format=')) $format = substr($f, 9); }

function out($label, $data) { echo $label . ': ' . json_encode($data, JSON_UNESCAPED_SLASHES) . "\n"; }

// No file → readiness report only.
if (empty($files) || in_array('--readiness', $flags, true)) {
    out('readiness', PaintShadeImporter::enableReadiness($db));
    if ($doEnable) echo $enableNote . "\n";
    exit(0);
}

$path = $files[0];
if (!is_file($path)) { fwrite(STDERR, "File not found: $path\n"); exit(2); }
$text = (string)file_get_contents($path);
$fmt  = $format ?: (preg_match('/\.json$/i', $path) ? 'json' : 'csv');
$rows = $fmt === 'json' ? PaintShadeImporter::parseJson($text) : PaintShadeImporter::parseCsv($text);
echo "Parsed " . count($rows) . " row(s) as $fmt" . ($dryRun ? " (dry-run)" : "") . ".\n";

$res = PaintShadeImporter::import($db, $rows, $dryRun);
out('import', ['inserted' => $res['inserted'], 'updated' => $res['updated'], 'invalid' => $res['invalid'], 'total' => $res['total']]);
if ($res['invalid'] > 0) out('invalid_rows', array_slice($res['errors'], 0, 20));

out('readiness', PaintShadeImporter::enableReadiness($db));
if ($doEnable) echo $enableNote . "\n";

exit($res['invalid'] > 0 ? 1 : 0);
