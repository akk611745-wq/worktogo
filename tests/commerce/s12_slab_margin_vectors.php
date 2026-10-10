<?php
// ─────────────────────────────────────────────────────────────────────────────
//  S12 PROOF HARNESS — slab margin (flat/%/decreasing%) + growth knob + loss-proof.
//  Proves MarginEngine::priceFromBase / slabMargin / growthMultiplier against the
//  CEO's slab rule, with override precedence, slab-disabled fallback, min floor,
//  per-category override, and the unchanged loss-proof floor. Idempotent (restores
//  the 3 config keys it touches). Run: php tests/commerce/s12_slab_margin_vectors.php
// ─────────────────────────────────────────────────────────────────────────────
define('SYSTEM_ROOT', dirname(dirname(__DIR__)));
foreach (file(SYSTEM_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2); $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
    if (getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
}
require_once SYSTEM_ROOT . '/core/helpers/Database.php';
require_once SYSTEM_ROOT . '/lib/commerce/MarginEngine.php';
$pdo = Database::getConnection();

$PASS = 0; $FAIL = 0;
function ok($label, $got, $exp) {
    global $PASS, $FAIL;
    $g = is_float($got) ? number_format($got, 2, '.', '') : $got;
    $e = is_float($exp) ? number_format($exp, 2, '.', '') : $exp;
    if ($g === $e) { $PASS++; printf("  PASS  %-46s got=%s\n", $label, var_export($g, true)); }
    else { $FAIL++; printf("  FAIL  %-46s got=%s exp=%s\n", $label, var_export($g, true), var_export($e, true)); }
}
function setcfg(PDO $p, $k, $v, $t = 'text') {
    $p->prepare("INSERT INTO app_settings (setting_key,setting_value,value_type,is_public) VALUES (?,?,?,0)
                 ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$k, $v, $t]);
}
function marginOf(PDO $p, float $base, $pPct = null, $pFlat = null, $cPct = null, $cFlat = null, $cat = null): array {
    return MarginEngine::priceFromBase($p, $base, $pPct, $pFlat, $cPct, $cFlat, $cat);
}

// snapshot the 3 keys we mutate
$snap = [];
foreach (['commerce_margin_slabs','commerce_margin_multiplier','commerce_margin_policy'] as $k) {
    $s = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1"); $s->execute([$k]); $snap[$k] = $s->fetchColumn();
}

$SLAB = '{"enabled":true,"bands":[{"up_to":500,"flat":20},{"up_to":5000,"pct":8},{"up_to":20000,"pct":5},{"up_to":100000,"pct":3},{"pct":2}],"by_category":{"777":{"bands":[{"pct":12}]}}}';
setcfg($pdo, 'commerce_margin_slabs', $SLAB, 'json');
setcfg($pdo, 'commerce_margin_multiplier', '1.0', 'number');
setcfg($pdo, 'commerce_margin_policy', '{"platform_margin_pct":10,"platform_margin_flat":0,"min_margin_flat":0,"rounding":"none"}', 'json');

echo "\n== slab band selection (flat → % → decreasing %) ==\n";
ok('base 300 → flat ₹20',        marginOf($pdo,300)['margin'],    20.0);
ok('base 500 (boundary) → flat 20', marginOf($pdo,500)['margin'], 20.0);
ok('base 1000 → 8% = 80',        marginOf($pdo,1000)['margin'],   80.0);
ok('base 5000 (boundary) → 8% = 400', marginOf($pdo,5000)['margin'], 400.0);
ok('base 5001 → 5% = 250.05',    marginOf($pdo,5001)['margin'],    250.05);
ok('base 10000 → 5% = 500',      marginOf($pdo,10000)['margin'],   500.0);
ok('base 20000 (boundary) → 5% = 1000', marginOf($pdo,20000)['margin'], 1000.0);
ok('base 50000 → 3% = 1500',     marginOf($pdo,50000)['margin'],   1500.0);
ok('base 100000 (boundary) → 3% = 3000', marginOf($pdo,100000)['margin'], 3000.0);
ok('base 200000 → 2% = 4000',    marginOf($pdo,200000)['margin'],  4000.0);
ok('source is slab',             marginOf($pdo,10000)['source'],   'slab');

echo "\n== the CEO rule: effective % FALLS as base rises ==\n";
$e = fn($b) => round(marginOf($pdo,$b)['margin'] / $b * 100, 2);
ok('eff% @1000',   $e(1000),  8.0);
ok('eff% @10000',  $e(10000), 5.0);
ok('eff% @50000',  $e(50000), 3.0);
ok('eff% @200000', $e(200000),2.0);
$falls = ($e(1000) > $e(10000)) && ($e(10000) > $e(50000)) && ($e(50000) > $e(200000));
ok('% strictly decreasing with value', $falls, true);

echo "\n== customer_price = base + margin ==\n";
ok('price @1000 = 1080',  marginOf($pdo,1000)['customer_price'],  1080.0);
ok('price @300  = 320',   marginOf($pdo,300)['customer_price'],   320.0);

echo "\n== growth knob (multiplier) ==\n";
setcfg($pdo,'commerce_margin_multiplier','0.5','number');
ok('mult 0.5 @10000 → 250', marginOf($pdo,10000)['margin'], 250.0);
setcfg($pdo,'commerce_margin_multiplier','0','number');
ok('mult 0 @10000 → 0 (near-free)', marginOf($pdo,10000)['margin'], 0.0);

echo "\n== minimum floor beats a thinned margin ==\n";
setcfg($pdo,'commerce_margin_policy','{"platform_margin_pct":10,"platform_margin_flat":0,"min_margin_flat":15,"rounding":"none"}','json');
ok('mult 0 but min_margin_flat 15 → 15', marginOf($pdo,10000)['margin'], 15.0);
setcfg($pdo,'commerce_margin_multiplier','1.0','number');
setcfg($pdo,'commerce_margin_policy','{"platform_margin_pct":10,"platform_margin_flat":0,"min_margin_flat":0,"rounding":"none"}','json');

echo "\n== override precedence (explicit product/category wins over slab) ==\n";
$ov = marginOf($pdo,10000, 15.0, null, null, null); // product pct 15
ok('product pct 15 overrides slab → 1500', $ov['margin'], 1500.0);
ok('override source',                      $ov['source'],  'override');

echo "\n== per-category slab override ==\n";
ok('cat 777 bands (12%) @1000 → 120', marginOf($pdo,1000,null,null,null,null,777)['margin'], 120.0);
ok('cat 888 (no override) → default 8% → 80', marginOf($pdo,1000,null,null,null,null,888)['margin'], 80.0);

echo "\n== slab disabled → platform flat fallback ==\n";
setcfg($pdo,'commerce_margin_slabs','{"enabled":false,"bands":[]}','json');
$fl = marginOf($pdo,10000);
ok('disabled slab → flat 10% = 1000', $fl['margin'], 1000.0);
ok('fallback source flat',            $fl['source'],  'flat');
setcfg($pdo,'commerce_margin_slabs',$SLAB,'json');

echo "\n== loss-proof floor unchanged (reused) ==\n";
ok('cheap: margin 20 vs exposure 30 → reject', MarginEngine::lossProofOk(20, 30, 0), false);
ok('ok:    margin 400 vs exposure 30 → accept', MarginEngine::lossProofOk(400, 30, 0), true);
ok('floor: margin 40 vs exposure 30, floor 15 → reject', MarginEngine::lossProofOk(40, 30, 15), false);

// restore config
foreach ($snap as $k => $v) { if ($v !== false && $v !== null) setcfg($pdo, $k, $v, 'json'); }

echo "\n────────────────────────────────────\n";
printf("S12 RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
