<?php
// ─────────────────────────────────────────────────────────────────────────────
//  P6 PROOF HARNESS — paint shade importer STRUCTURE (no real data). Proves the
//  importer is idempotent + validated and that the enable step is readiness-
//  gated, all with SYNTHETIC test rows (brand 'P6T-*', cleaned up). The real
//  Prisma data + the live enable are the CEO's step later. commerce_unit_config
//  is snapshot/restored, so custom tint stays OFF after this runs.
//  Run: php tests/commerce/p6_import_vectors.php   (local 10.11; idempotent)
// ─────────────────────────────────────────────────────────────────────────────
define('SYSTEM_ROOT', dirname(dirname(__DIR__)));
foreach (file(SYSTEM_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2); $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
    if (getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
}
require_once SYSTEM_ROOT . '/core/helpers/Database.php';
require_once SYSTEM_ROOT . '/lib/commerce/PaintShadeImporter.php';
require_once SYSTEM_ROOT . '/lib/commerce/UnitConfig.php';
$pdo = Database::getConnection();

$PASS = 0; $FAIL = 0;
function ok($label, $got, $exp = true) {
    global $PASS, $FAIL;
    if ($got === $exp) { $PASS++; printf("  PASS  %-52s got=%s\n", $label, var_export($got, true)); }
    else { $FAIL++; printf("  FAIL  %-52s got=%s exp=%s\n", $label, var_export($got, true), var_export($exp, true)); }
}
function has(string $h,string $n):bool{ return strpos($h,$n)!==false; }
function get(string $p):string{ return is_file($p)?(string)file_get_contents($p):''; }
function cleanup(PDO $p){ $p->exec("DELETE FROM commerce_paint_shades WHERE brand LIKE 'P6T-%' OR source='P6T-test'"); }

cleanup($pdo);
$cfgSnap = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='commerce_unit_config'")->fetchColumn();
register_shutdown_function(function() use($pdo,$cfgSnap){ try{ if($cfgSnap!==false&&$cfgSnap!==null){ $pdo->prepare("UPDATE app_settings SET setting_value=? WHERE setting_key='commerce_unit_config'")->execute([$cfgSnap]); } }catch(\Throwable $e){} });

echo "\n== [A] parse CSV ==\n";
$csv = "brand,product_line,shade_code,shade_name,base_type,finish\n".
       "P6T-Brand,Easy Clean,C1,Sunrise,white,matt\n".
       "P6T-Brand,Easy Clean,C2,Ocean,medium,sheen\n";
$rowsCsv = PaintShadeImporter::parseCsv($csv);
ok('CSV → 2 rows', count($rowsCsv), 2);
ok('CSV row maps shade_code', $rowsCsv[0]['shade_code'], 'C1');
ok('CSV row maps base_type',  $rowsCsv[1]['base_type'], 'medium');

echo "\n== [B] parse JSON (array + {shades:[]}) ==\n";
$rowsJson = PaintShadeImporter::parseJson('[{"brand":"P6T-Brand","shade_code":"C3","base_type":"deep"}]');
ok('JSON → 1 row', count($rowsJson), 1);
ok('JSON maps base_type', $rowsJson[0]['base_type'], 'deep');
ok('JSON {shades:[]} wrapper', count(PaintShadeImporter::parseJson('{"shades":[{"brand":"P6T-Brand","shade_code":"C4"}]}')), 1);

echo "\n== [C] row validation ==\n";
ok('valid row → no errors', PaintShadeImporter::validateRow(['brand'=>'P6T-Brand','shade_code'=>'C1']), []);
ok('missing brand → brand_required', PaintShadeImporter::validateRow(['shade_code'=>'C1']), ['brand_required']);
ok('missing code → shade_code_required', PaintShadeImporter::validateRow(['brand'=>'P6T-Brand']), ['shade_code_required']);

echo "\n== [D] readiness reflects data presence (go-live is per-brand) ==\n";
ok('shadeCount(P6T-Brand) starts 0', PaintShadeImporter::shadeCount($pdo,'P6T-Brand'), 0);
$rd0 = PaintShadeImporter::enableReadiness($pdo);
ok('readiness.ready matches shade_count>0', $rd0['ready'], ($rd0['shade_count'] > 0));
ok('readiness reports enabled_brands list', is_array($rd0['enabled_brands']), true);

echo "\n== [E] import (idempotent, validated) ==\n";
$res1 = PaintShadeImporter::import($pdo, PaintShadeImporter::parseCsv($csv));
ok('first import inserts 2', $res1['inserted'], 2);
ok('first import updates 0', $res1['updated'], 0);
ok('shadeCount(P6T) = 2',    PaintShadeImporter::shadeCount($pdo,'P6T-Brand'), 2);
$res2 = PaintShadeImporter::import($pdo, PaintShadeImporter::parseCsv($csv));
ok('re-import updates 2 (idempotent)', $res2['updated'], 2);
ok('re-import inserts 0',    $res2['inserted'], 0);
ok('no duplicates (still 2)', PaintShadeImporter::shadeCount($pdo,'P6T-Brand'), 2);

echo "\n== [F] upsert updates an existing shade ==\n";
$csv2 = "brand,shade_code,base_type\nP6T-Brand,C1,DEEP-CHANGED\n";
PaintShadeImporter::import($pdo, PaintShadeImporter::parseCsv($csv2));
$row = $pdo->query("SELECT base_type FROM commerce_paint_shades WHERE brand='P6T-Brand' AND shade_code='C1' LIMIT 1")->fetchColumn();
ok('existing shade base_type updated', $row, 'DEEP-CHANGED');

echo "\n== [G] invalid rows skipped, valid still imported ==\n";
$mixed = [['brand'=>'P6T-Brand','shade_code'=>'C5'], ['brand'=>'','shade_code'=>'X'], ['brand'=>'P6T-Brand','shade_code'=>'']];
$res3 = PaintShadeImporter::import($pdo, $mixed);
ok('1 inserted',  $res3['inserted'], 1);
ok('2 invalid',   $res3['invalid'], 2);

echo "\n== [H] dry-run writes nothing ==\n";
$before = PaintShadeImporter::shadeCount($pdo,'P6T-Brand');
$resDry = PaintShadeImporter::import($pdo, PaintShadeImporter::parseJson('[{"brand":"P6T-Brand","shade_code":"DRY1"}]'), true);
ok('dry-run reports 1 insert', $resDry['inserted'], 1);
ok('dry-run wrote nothing',    PaintShadeImporter::shadeCount($pdo,'P6T-Brand'), $before);

echo "\n== [I] readiness after import (data present) ==\n";
$rd = PaintShadeImporter::enableReadiness($pdo);
ok('readiness ready with data', $rd['ready'], true);
ok('shade_count > 0',           $rd['shade_count'] > 0, true);

echo "\n== [J] structural ==\n";
ok('importer lib exists', get(SYSTEM_ROOT.'/lib/commerce/PaintShadeImporter.php') !== '', true);
$cli = get(SYSTEM_ROOT.'/scripts/import_paint_shades.php');
ok('CLI wrapper exists', $cli !== '', true);
ok('CLI calls importer', has($cli,'PaintShadeImporter::import') && has($cli,'enableReadiness'), true);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("P6 IMPORTER RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
