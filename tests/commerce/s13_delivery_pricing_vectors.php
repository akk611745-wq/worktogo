<?php
// ─────────────────────────────────────────────────────────────────────────────
//  S13 PROOF HARNESS — delivery absorb + no-flicker composed price + area cache.
//  Proves DeliveryPricing::absorb (pure math), composeFinalPrice (S12 margin +
//  absorb end-to-end), and cachedAreaFee (hit/miss/expiry). Idempotent.
//  Run: php tests/commerce/s13_delivery_pricing_vectors.php
// ─────────────────────────────────────────────────────────────────────────────
define('SYSTEM_ROOT', dirname(dirname(__DIR__)));
foreach (file(SYSTEM_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2); $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
    if (getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
}
require_once SYSTEM_ROOT . '/core/helpers/Database.php';
require_once SYSTEM_ROOT . '/lib/commerce/DeliveryPricing.php';
$pdo = Database::getConnection();

$PASS = 0; $FAIL = 0;
function ok($label, $got, $exp) {
    global $PASS, $FAIL;
    $g = is_float($got) ? number_format($got, 2, '.', '') : $got;
    $e = is_float($exp) ? number_format($exp, 2, '.', '') : $exp;
    if ($g === $e) { $PASS++; printf("  PASS  %-48s got=%s\n", $label, var_export($g, true)); }
    else { $FAIL++; printf("  FAIL  %-48s got=%s exp=%s\n", $label, var_export($g, true), var_export($e, true)); }
}
function setcfg(PDO $p, $k, $v, $t = 'text') {
    $p->prepare("INSERT INTO app_settings (setting_key,setting_value,value_type,is_public) VALUES (?,?,?,0)
                 ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$k, $v, $t]);
}
$MP = ['rounding' => 'none', 'min_margin_flat' => 0.0];           // margin policy for pure-math tests
$POL = ['enabled' => true, 'hide_ratio' => 0.5, 'absorb_cap_pct' => 100, 'min_shown_delivery' => 0];

echo "\n== absorb: high-margin item → FREE delivery (WorkToGo eats fee) ==\n";
$a = DeliveryPricing::absorb(1000, 80, 30, 0, $POL, $MP);
ok('absorbed = full fee 30',   $a['absorbed'],       30.0);
ok('shown delivery = 0',       $a['shown_delivery'], 0.0);
ok('free_delivery',            $a['free_delivery'],  true);
ok('product_price 1080',       $a['product_price'],  1080.0);
ok('total 1080',               $a['total'],          1080.0);
ok('wtg keeps 50',             $a['wtg_net_margin'], 50.0);
ok('loss_proof_ok',            $a['loss_proof_ok'],  true);

echo "\n== absorb: cheap item → no jarring ₹30 on ₹50 (hide part, show small) ==\n";
$b = DeliveryPricing::absorb(50, 20, 30, 0, $POL, $MP);
ok('eaten from margin 20',     $b['absorbed'],       20.0);
ok('hidden in price 5',        $b['hidden_in_price'],5.0);
ok('shown delivery only 5',    $b['shown_delivery'], 5.0);
ok('product_price 75',         $b['product_price'],  75.0);
ok('total 80',                 $b['total'],          80.0);
ok('wtg net 0 (break-even)',   $b['wtg_net_margin'], 0.0);
ok('loss_proof_ok (0>=0)',     $b['loss_proof_ok'],  true);

echo "\n== absorb: loss-proof floor caps how much we can eat ==\n";
$c = DeliveryPricing::absorb(50, 20, 30, 10, $POL, $MP);
ok('floor 10 → eat only 10',   $c['absorbed'],       10.0);
ok('wtg net stays at floor 10',$c['wtg_net_margin'], 10.0);
ok('loss_proof_ok',            $c['loss_proof_ok'],  true);

echo "\n== absorb: genuine loss → flagged (checkout rejects) ==\n";
$d = DeliveryPricing::absorb(50, 5, 30, 10, $POL, $MP);
ok('cannot eat (margin<floor)',$d['absorbed'],       0.0);
ok('loss_proof_ok FALSE',      $d['loss_proof_ok'],  false);

echo "\n== absorb: disabled → nothing eaten, split shown ==\n";
$e = DeliveryPricing::absorb(1000, 80, 30, 0, ['enabled' => false, 'hide_ratio' => 0.5], $MP);
ok('disabled: absorbed 0',     $e['absorbed'],       0.0);
ok('disabled: hidden 15',      $e['hidden_in_price'],15.0);
ok('disabled: shown 15',       $e['shown_delivery'], 15.0);
ok('disabled: free false',     $e['free_delivery'],  false);

echo "\n== absorb: min_shown_delivery floor on the visible line ==\n";
$f = DeliveryPricing::absorb(50, 20, 30, 0, ['enabled' => true, 'hide_ratio' => 0.5, 'absorb_cap_pct' => 100, 'min_shown_delivery' => 8], $MP);
ok('min_shown forces shown 8', $f['shown_delivery'], 8.0);
ok('hidden adjusts to 2',      $f['hidden_in_price'],2.0);
ok('total unchanged 80',       $f['total'],          80.0);

echo "\n== composeFinalPrice: S12 slab + absorb end-to-end (DB config) ==\n";
$snap = [];
foreach (['commerce_margin_slabs','commerce_margin_multiplier','commerce_margin_policy','commerce_delivery_absorb','commerce_loss_proof_floor'] as $k) {
    $s = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1"); $s->execute([$k]); $snap[$k] = $s->fetchColumn();
}
setcfg($pdo,'commerce_margin_slabs','{"enabled":true,"bands":[{"up_to":500,"flat":20},{"up_to":5000,"pct":8},{"pct":2}],"by_category":{}}','json');
setcfg($pdo,'commerce_margin_multiplier','1.0','number');
setcfg($pdo,'commerce_margin_policy','{"platform_margin_pct":0,"platform_margin_flat":0,"min_margin_flat":0,"rounding":"none"}','json');
setcfg($pdo,'commerce_delivery_absorb','{"enabled":true,"hide_ratio":0.5,"absorb_cap_pct":100,"min_shown_delivery":0}','json');
setcfg($pdo,'commerce_loss_proof_floor','0','number');

$hi = DeliveryPricing::composeFinalPrice($pdo, 1000, null, 30);   // slab 8% = 80 margin
ok('compose hi: margin 80 (slab)', $hi['margin'],         80.0);
ok('compose hi: free delivery',    $hi['free_delivery'],  true);
ok('compose hi: total 1080',       $hi['total'],          1080.0);
ok('compose hi: source slab',      $hi['margin_source'],  'slab');
$lo = DeliveryPricing::composeFinalPrice($pdo, 50, null, 30);     // slab flat 20 margin
ok('compose lo: margin 20 (flat band)', $lo['margin'],        20.0);
ok('compose lo: shown only 5',          $lo['shown_delivery'],5.0);
ok('compose lo: total 80',              $lo['total'],         80.0);

echo "\n== cachedAreaFee: miss → live(store) ; hit → cached ; expiry → live again ==\n";
$pdo->prepare("DELETE FROM commerce_delivery_rate_cache WHERE area_key=?")->execute(['s13t-key']);
$calls = 0;
$live = function () use (&$calls) { $calls++; return ['available' => true, 'price' => 42.0, 'distance_km' => 3.0]; };
$q1 = DeliveryPricing::cachedAreaFee($pdo, 's13t-key', $live, 900);
ok('miss → cached:false',   $q1['cached'], false);
ok('miss → live called 1',  $calls,        1);
ok('fee carried 42',        (float)$q1['price'], 42.0);
$q2 = DeliveryPricing::cachedAreaFee($pdo, 's13t-key', $live, 900);
ok('hit → cached:true',     $q2['cached'], true);
ok('hit → live NOT called', $calls,        1);
// age the row past TTL
$pdo->prepare("UPDATE commerce_delivery_rate_cache SET cached_at = (NOW() - INTERVAL 10000 SECOND) WHERE area_key=?")->execute(['s13t-key']);
$q3 = DeliveryPricing::cachedAreaFee($pdo, 's13t-key', $live, 900);
ok('expiry → cached:false', $q3['cached'], false);
ok('expiry → live called 2',$calls,        2);
$pdo->prepare("DELETE FROM commerce_delivery_rate_cache WHERE area_key=?")->execute(['s13t-key']);

// restore config
foreach ($snap as $k => $v) { if ($v !== false && $v !== null) setcfg($pdo, $k, $v, 'json'); }

echo "\n────────────────────────────────────\n";
printf("S13 RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
