<?php
// ─────────────────────────────────────────────────────────────────────────────
//  P2 PROOF HARNESS — CoverageCalculator (the moat). Proves the two liability
//  rules + the math: (1) every estimate is labelled "Estimated" with the
//  varies-disclaimer; (2) a 2-coat coverage is NEVER multiplied by 2 again
//  (F.2). Plus wall-area, unit conversion, buffer, pack plan, guide — and the
//  structural wiring (adapter + dispatch + customer UI → /api/coverage).
//  Pure math (no DB needed; config is default-safe). Run:
//    php tests/commerce/p2_coverage_vectors.php
// ─────────────────────────────────────────────────────────────────────────────
define('SYSTEM_ROOT', dirname(dirname(__DIR__)));
require_once SYSTEM_ROOT . '/lib/commerce/CoverageCalculator.php';

$PASS = 0; $FAIL = 0;
function ok($label, $got, $exp) {
    global $PASS, $FAIL;
    if (is_numeric($got) && is_numeric($exp)) { $g = number_format((float)$got, 2, '.', ''); $e = number_format((float)$exp, 2, '.', ''); }
    else { $g = $got; $e = $exp; }
    if ($g === $e) { $PASS++; printf("  PASS  %-52s got=%s\n", $label, var_export($g, true)); }
    else { $FAIL++; printf("  FAIL  %-52s got=%s exp=%s\n", $label, var_export($g, true), var_export($e, true)); }
}
function has(string $h,string $n):bool{ return strpos($h,$n)!==false; }
function get(string $p):string{ return is_file($p)?(string)file_get_contents($p):''; }

echo "\n== [A] litres math — NEVER double a 2-coat figure (F.2) ==\n";
ok('2-coat basis, 2 coats, no buffer = area/cov (3.20)', CoverageCalculator::litres(400,125,2,2,0), 3.20);
ok('  …is NOT doubled (≠ 6.40)', CoverageCalculator::litres(400,125,2,2,0) !== 6.40, true);
ok('per-coat basis, 2 coats = area*2/cov (6.40)', CoverageCalculator::litres(400,125,1,2,0), 6.40);
ok('2-coat basis, 3 coats scales by 3/2 (4.80)', CoverageCalculator::litres(400,125,2,3,0), 4.80);
ok('10% buffer applied (3.20 → 3.52)', CoverageCalculator::litres(400,125,2,2,10), 3.52);
ok('bad coverage (0) → 0 litres', CoverageCalculator::litres(400,0,2,2,10), 0.0);

echo "\n== [B] area helpers ==\n";
ok('wallArea 12×10×10, −40 openings = 400', CoverageCalculator::wallArea(12,10,10,false,40), 400.0);
ok('wallArea + ceiling (12×10) = 560', CoverageCalculator::wallArea(12,10,10,true,0), 560.0);
ok('toSqft 10 sqm → 107.64', CoverageCalculator::toSqft(10,'sqm'), 107.64);
ok('toSqft 50 sqft → 50', CoverageCalculator::toSqft(50,'sqft'), 50.0);

echo "\n== [C] pack plan ==\n";
$p1 = CoverageCalculator::packPlan(3.52,[1,4,10,20]);
ok('3.52L → single 4L pack (purchase 4)', $p1['purchased_litres'], 4.0);
ok('3.52L → packs = {4:1}', ($p1['packs']['4'] ?? 0), 1);
$p2 = CoverageCalculator::packPlan(25,[1,4,10,20]);
ok('25L → 20 + 10 (purchase 30)', $p2['purchased_litres'], 30.0);
ok('no pack sizes → null', CoverageCalculator::packPlan(3.5,[]), null);

echo "\n== [D] estimate() — Berger Easy Clean worked example (F.2) ==\n";
$e = CoverageCalculator::estimate(['net_area_sqft'=>400,'coverage_per_l'=>125,'pack_sizes'=>[1,4,10,20]], null);
ok('estimated litres 3.52', $e['estimated_litres'], 3.52);
ok('pack purchase 4L', $e['pack_plan']['purchased_litres'], 4.0);
ok('label = Estimated requirement', $e['label'], 'Estimated requirement');
ok('disclaimer says "Estimated"', has($e['disclaimer'],'Estimated'), true);
ok('disclaimer says "varies"', has($e['disclaimer'],'varies'), true);
ok('basis_coats defaulted to 2', $e['basis_coats'], 2);
ok('has assumptions', count($e['assumptions'])>=1, true);

echo "\n== [E] estimate() edge + room/unit paths ==\n";
$room = CoverageCalculator::estimate(['length_ft'=>12,'width_ft'=>10,'height_ft'=>10,'openings_sqft'=>40,'coverage_per_l'=>125,'pack_sizes'=>[1,4,10,20]], null);
ok('room path → net area 400', $room['net_area_sqft'], 400.0);
ok('room path → 3.52 litres', $room['estimated_litres'], 3.52);
$sqm = CoverageCalculator::estimate(['area_value'=>37.16,'area_unit'=>'sqm','coverage_per_l'=>125], null);
ok('sqm path → ~400 sqft', round($sqm['net_area_sqft']), 400.0);
$none = CoverageCalculator::estimate(['net_area_sqft'=>400], null);
ok('no coverage → 0 litres + hint', $none['estimated_litres'], 0.0);
ok('no coverage → orderable_hint set', is_string($none['orderable_hint']), true);
ok('estimate ALWAYS carries disclaimer', ($none['disclaimer'] ?? '') !== '', true);

echo "\n== [F] guide() ==\n";
$g = CoverageCalculator::guide();
ok('guide new-wall steps present', count($g['prep']['new_wall'])>=3, true);
ok('guide has interior situation', isset($g['situations']['interior']), true);
ok('guide notes carry the estimate caveat', has(implode(' ',$g['notes']),'varies'), true);

echo "\n== [G] structural wiring ==\n";
$adapter = get(SYSTEM_ROOT.'/heart/api/coverage/index.php');
$heart   = get(SYSTEM_ROOT.'/heart/index.php');
$ui      = get(SYSTEM_ROOT.'/paint-calculator/index.html');
ok('adapter exists', $adapter !== '', true);
ok('adapter has POST /api/coverage/estimate', has($adapter,'/api/coverage/estimate') && has($adapter,'POST'), true);
ok('adapter has GET /api/coverage/guide', has($adapter,'/api/coverage/guide') && has($adapter,'GET'), true);
ok('adapter calls CoverageCalculator', has($adapter,'CoverageCalculator::estimate'), true);
ok('heart dispatches /api/coverage', has($heart,'/api/coverage'), true);
ok('customer UI exists', $ui !== '', true);
ok('UI calls /api/coverage/estimate', has($ui,'/api/coverage/estimate'), true);
ok('UI loads the guide', has($ui,'/api/coverage/guide'), true);
ok('UI shows the "Estimated" label', has($ui,'Estimated requirement'), true);

echo "\n────────────────────────────────────\n";
printf("P2 RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
