<?php
// ─────────────────────────────────────────────────────────────────────────────
//  PB10c PROOF — price band + repeat-lock.
//  Proves: every priced quote feeds the (brand,shade) band; the estimate uses the
//  band median once there's data; a quote far above the band is an OUTLIER —
//  hidden from the customer, rerouted, and surfaced in the PB9 ops queue (until
//  enough data, no rejection); a repeat order of a shade the customer already
//  ordered is priced INSTANTLY from the locked per-litre, same seller, no re-ask.
//  Run: php tests/commerce/pb10c_tint_band_vectors.php
// ─────────────────────────────────────────────────────────────────────────────
define('SYSTEM_ROOT', dirname(dirname(__DIR__)));
foreach (file(SYSTEM_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2); $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
    if (getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
}
require_once SYSTEM_ROOT . '/core/helpers/Database.php';
require_once SYSTEM_ROOT . '/core/helpers/Logger.php';
if (!class_exists('Core\\Helpers\\Database')) class_alias('Database', 'Core\\Helpers\\Database');
if (!class_exists('Core\\Helpers\\Logger'))   class_alias('Logger', 'Core\\Helpers\\Logger');
require_once SYSTEM_ROOT . '/lib/commerce/PaintCatalogue.php';
require_once SYSTEM_ROOT . '/lib/commerce/CustomTint.php';
require_once SYSTEM_ROOT . '/lib/commerce/CustomTintQuote.php';
require_once SYSTEM_ROOT . '/lib/commerce/CustomTintBand.php';
require_once SYSTEM_ROOT . '/lib/commerce/CommerceOpsQueue.php';
$pdo = Database::getConnection();

$PASS = 0; $FAIL = 0; $CFG = [];
function ok($label, $got, $exp = true) {
    global $PASS, $FAIL;
    $g = is_float($got) ? number_format($got, 2, '.', '') : var_export($got, true);
    $e = is_float($exp) ? number_format($exp, 2, '.', '') : var_export($exp, true);
    if ($g === $e) { $PASS++; printf("  PASS  %-54s %s\n", $label, $g); }
    else { $FAIL++; printf("  FAIL  %-54s got=%s exp=%s\n", $label, $g, $e); }
}
function has(string $h,string $n):bool{ return strpos($h,$n)!==false; }
function get(string $p):string{ return is_file($p)?(string)file_get_contents($p):''; }
function setCfg(PDO $p,$k,$v){ global $CFG; if(!array_key_exists($k,$CFG)){ $s=$p->prepare("SELECT setting_value FROM app_settings WHERE setting_key=?"); $s->execute([$k]); $CFG[$k]=$s->fetchColumn(); } $p->prepare("INSERT INTO app_settings (setting_key,setting_value,value_type,is_public) VALUES (?,?,'text',0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$k,$v]); }
register_shutdown_function(function() use ($pdo){ global $CFG; foreach($CFG as $k=>$v){ if($v===false) $pdo->prepare("DELETE FROM app_settings WHERE setting_key=?")->execute([$k]); else $pdo->prepare("UPDATE app_settings SET setting_value=? WHERE setting_key=?")->execute([$v,$k]); } });
function cleanup(PDO $p){
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $qs=$p->query("SELECT order_id FROM commerce_tint_quotes WHERE brand='PB10cBerger' AND order_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    foreach($qs as $oid){ $oid=(int)$oid; $p->exec("DELETE FROM commerce_order_payments WHERE order_id=$oid"); $p->exec("DELETE FROM commerce_routing_signals WHERE order_id=$oid"); $p->exec("DELETE FROM commerce_order_flags WHERE order_id=$oid"); $p->exec("DELETE FROM commerce_events WHERE order_id=$oid"); $p->exec("DELETE FROM orders WHERE id=$oid OR parent_order_id=$oid"); }
    $p->exec("DELETE FROM commerce_tint_quotes WHERE brand='PB10cBerger'");
    $p->exec("DELETE FROM commerce_tint_order_log WHERE brand='PB10cBerger'");
    $p->exec("DELETE FROM commerce_paint_shades WHERE brand='PB10cBerger'");
    $vids=$p->query("SELECT id FROM vendors WHERE slug LIKE 'pb10ct-%'")->fetchAll(PDO::FETCH_COLUMN);
    if($vids){$in=implode(',',array_map('intval',$vids));
        $pids=$p->query("SELECT id FROM products WHERE vendor_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        if($pids){$pin=implode(',',array_map('intval',$pids)); $p->exec("DELETE FROM inventory WHERE product_id IN ($pin)"); $p->exec("DELETE FROM product_variations WHERE product_id IN ($pin)"); $p->exec("DELETE FROM products WHERE id IN ($pin)"); }
        $p->exec("DELETE FROM stores WHERE owner_vendor_id IN ($in)"); $p->exec("DELETE FROM vendors WHERE id IN ($in)"); }
    $p->exec("DELETE FROM users WHERE email LIKE 'pb10ct-%@wtg.test'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('PB10c',?, 'vendor_shopping','active',NOW(),NOW())")->execute([$e]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',1,10,NOW(),NOW())")->execute([$uid,'PB10c '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkStore(PDO $p,$slug,$v,$city){ $p->prepare("INSERT INTO stores (ownership,owner_vendor_id,city_id,name,slug,status,created_at,updated_at) VALUES ('vendor',?,?,?,?,'active',NOW(),NOW())")->execute([$v,$city,'PB10c S '.$slug,$slug]); return (int)$p->lastInsertId(); }
function reqQuote($pdo,$u,$city,$litres,$mode='new'){ return CustomTintQuote::request($pdo,['user_id'=>$u,'brand'=>'PB10cBerger','shade_code'=>'7D4','litres'=>$litres,'mode'=>$mode,'city_id'=>$city]); }

cleanup($pdo);
setCfg($pdo,'commerce_custom_tint_brands','[]');
setCfg($pdo,'commerce_tint_estimate_per_litre','250');
setCfg($pdo,'commerce_margin_slabs','{"enabled":true,"bands":[{"up_to":null,"flat":200}]}');
setCfg($pdo,'commerce_margin_multiplier','1');
setCfg($pdo,'commerce_tint_ceiling_mult','3.0');
setCfg($pdo,'commerce_advance_default_pct','50');
setCfg($pdo,'commerce_custom_tint_consent_required','1');
setCfg($pdo,'commerce_tint_band_min_samples','3');
setCfg($pdo,'commerce_tint_band_outlier_pct','50');

$cityId=(int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if(!$cityId){ $pdo->exec("INSERT INTO cities (name,slug) VALUES ('PB10c City','pb10ct-city')"); $cityId=(int)$pdo->lastInsertId(); }
$cat=(int)$pdo->query("SELECT id FROM categories WHERE slug='paint-interior-emulsion' LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO commerce_paint_shades (brand,product_line,shade_code,shade_name,source,created_at) VALUES ('PB10cBerger','Silk','7D4','Ocean Blue','test',NOW())")->execute();
CustomTint::enableBrand($pdo,'PB10cBerger');
$u1=mkUser($pdo,'pb10ct-v1@wtg.test'); $V1=mkVendor($pdo,$u1,'pb10ct-v1'); mkStore($pdo,'pb10ct-s1',$V1,$cityId);
$u2=mkUser($pdo,'pb10ct-v2@wtg.test'); $V2=mkVendor($pdo,$u2,'pb10ct-v2'); mkStore($pdo,'pb10ct-s2',$V2,$cityId);
PaintCatalogue::createProduct($pdo,$V1,['brand'=>'PB10cBerger','line'=>'Silk','category_id'=>$cat]);
PaintCatalogue::createProduct($pdo,$V2,['brand'=>'PB10cBerger','line'=>'Silk','category_id'=>$cat]);
$A=mkUser($pdo,'pb10ct-a@wtg.test'); $B=mkUser($pdo,'pb10ct-b@wtg.test');

echo "\n== [A] band fills from priced quotes; estimate uses the median ==\n";
// seed 3 in-band quotes (colour 2000, 10 L → final 2200 → ₹220/L), all to V1 (customer A, new)
for ($i=0;$i<3;$i++){ $r=reqQuote($pdo,$A,$cityId,10); CustomTintQuote::sellerRespond($pdo,(int)$r['quote_id'],$V1,2000,'Deep Base'); }
$st=CustomTintBand::stats($pdo,'PB10cBerger','7D4');
ok('band has 3 samples', $st['count'], 3);
ok('band median = 220/L', (float)$st['median'], 220.0);
$est=CustomTintQuote::estimate($pdo,'PB10cBerger','7D4',10);
ok('estimate now uses the band median', $est['source'], 'band_median');
ok('estimate = 220 x 10 = 2200', (float)$est['approx'], 2200.0);

echo "\n== [B] outlier is hidden + rerouted + surfaced to ops ==\n";
$ro=reqQuote($pdo,$A,$cityId,10); $qo=(int)$ro['quote_id']; $first=(int)$ro['vendor_id'];
// a colour price whose per-litre lands far above the 220 band (ceiling mult 3 lets it through uncapped)
$resp=CustomTintQuote::sellerRespond($pdo,$qo,$first,4000,'Deep Base'); // final 4200 → 420/L > 220*1.5=330
ok('priced-as-outlier (not shown)', $resp['outlier'] ?? false, true);
ok('outlier was rerouted to the other seller', (int)CustomTintQuote::get($pdo,$qo)['vendor_id'] !== $first, true);
ok('quote has no customer final price', CustomTintQuote::get($pdo,$qo)['final_price'], null);
ok('outlier flagged', (int)CustomTintQuote::get($pdo,$qo)['outlier_flag'], 1);
ok('outlier is in the ops queue', (function()use($pdo,$qo){ foreach(CommerceOpsQueue::build($pdo) as $it) if(($it['kind']??'')==='tint_price_outlier' && (int)($it['ref_id']??0)===$qo) return true; return false; })(), true);
// below the min-samples the SAME high price would NOT be rejected
ok('not an outlier below min samples', CustomTintBand::isOutlier($pdo,'PB10cFresh','ZZ9',99999), false);

echo "\n== [C] repeat-lock: same shade prices instantly, same seller, no re-ask ==\n";
$rb=reqQuote($pdo,$B,$cityId,10); $qb=(int)$rb['quote_id']; $sellerB=(int)$rb['vendor_id'];
CustomTintQuote::sellerRespond($pdo,$qb,$sellerB,2000,'Deep Base'); // 220/L
$acc=CustomTintQuote::accept($pdo,$qb,$B,true);
ok('first order placed', $acc['ok'], true);
$rep=CustomTintQuote::request($pdo,['user_id'=>$B,'brand'=>'PB10cBerger','shade_code'=>'7D4','litres'=>4,'mode'=>'repaint','city_id'=>$cityId]);
ok('repeat is instantly priced (no seller re-ask)', $rep['status'], 'priced');
ok('repeat is flagged locked', $rep['locked'] ?? false, true);
ok('repeat price = 220/L x 4 = 880', (float)$rep['final_price'], 880.0);
ok('repeat stays with the same seller', (int)$rep['vendor_id'], $sellerB);
ok('repeat needs no seller inbox entry', (function()use($pdo,$sellerB,$rep){ foreach(CustomTintQuote::sellerInbox($pdo,$sellerB) as $x) if((int)$x['id']===(int)$rep['quote_id']) return true; return false; })(), false);

echo "\n== [D] wiring ==\n";
$ops=get(SYSTEM_ROOT.'/lib/commerce/CommerceOpsQueue.php'); $cq=get(SYSTEM_ROOT.'/lib/commerce/CustomTintQuote.php');
ok('ops queue has the price-outlier feed', has($ops,'tint_price_outlier')&&has($ops,'CustomTintBand::opsOutliers'), true);
ok('quote machinery checks the band for outliers', has($cq,'CustomTintBand::isOutlier'), true);
ok('request honours the repeat-lock', has($cq,'CustomTintBand::lockFor'), true);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("PB10c TINT-BAND RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
