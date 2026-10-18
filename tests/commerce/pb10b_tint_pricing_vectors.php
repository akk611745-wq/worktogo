<?php
// ─────────────────────────────────────────────────────────────────────────────
//  PB10b PROOF — custom-tint pricing + accept + advance.
//  Proves: the seller colour price runs through the SAME MarginEngine, then the
//  admin ceiling (× estimate) caps the final so colour can't balloon; the
//  customer gets ONE final-price popup; accept requires consent, creates the
//  order, records the advance intent + the repeat-lock + a confirm-call flag,
//  and the margin (subtotal − seller_subtotal) is on the ledger; making may start
//  only after the advance is captured, and then the advance is non-refundable
//  (PB16). Run: php tests/commerce/pb10b_tint_pricing_vectors.php
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
require_once SYSTEM_ROOT . '/lib/commerce/CommercePayment.php';
require_once SYSTEM_ROOT . '/lib/commerce/CommerceCancellation.php';
require_once SYSTEM_ROOT . '/lib/commerce/CommerceEventBus.php';
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
function setCfg(PDO $p,$k,$v){ global $CFG; $s=$p->prepare("SELECT setting_value FROM app_settings WHERE setting_key=?"); $s->execute([$k]); if(!array_key_exists($k,$CFG))$CFG[$k]=$s->fetchColumn(); $p->prepare("INSERT INTO app_settings (setting_key,setting_value,value_type,is_public) VALUES (?,?,'text',0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$k,$v]); }
register_shutdown_function(function() use ($pdo){ global $CFG; foreach($CFG as $k=>$v){ if($v===false) $pdo->prepare("DELETE FROM app_settings WHERE setting_key=?")->execute([$k]); else $pdo->prepare("UPDATE app_settings SET setting_value=? WHERE setting_key=?")->execute([$v,$k]); } });
function cleanup(PDO $p){
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $qids=$p->query("SELECT id,order_id FROM commerce_tint_quotes WHERE brand='PB10bBerger'")->fetchAll(PDO::FETCH_ASSOC);
    foreach($qids as $q){ if($q['order_id']){ $oid=(int)$q['order_id']; $p->exec("DELETE FROM commerce_order_payments WHERE order_id=$oid"); $p->exec("DELETE FROM commerce_routing_signals WHERE order_id=$oid"); $p->exec("DELETE FROM commerce_order_flags WHERE order_id=$oid"); $p->exec("DELETE FROM commerce_events WHERE order_id=$oid"); $p->exec("DELETE FROM orders WHERE id=$oid OR parent_order_id=$oid"); } }
    $p->exec("DELETE FROM commerce_tint_quotes WHERE brand='PB10bBerger'");
    $p->exec("DELETE FROM commerce_tint_order_log WHERE brand='PB10bBerger'");
    $p->exec("DELETE FROM commerce_paint_shades WHERE brand='PB10bBerger'");
    $p->exec("DELETE FROM orders WHERE order_number LIKE 'TINT-%' AND notes LIKE 'custom-tint%' AND user_id IN (SELECT id FROM users WHERE email LIKE 'pb10bt-%@wtg.test')");
    $vids=$p->query("SELECT id FROM vendors WHERE slug LIKE 'pb10bt-%'")->fetchAll(PDO::FETCH_COLUMN);
    if($vids){$in=implode(',',array_map('intval',$vids));
        $pids=$p->query("SELECT id FROM products WHERE vendor_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        if($pids){$pin=implode(',',array_map('intval',$pids)); $p->exec("DELETE FROM inventory WHERE product_id IN ($pin)"); $p->exec("DELETE FROM product_variations WHERE product_id IN ($pin)"); $p->exec("DELETE FROM products WHERE id IN ($pin)"); }
        $p->exec("DELETE FROM stores WHERE owner_vendor_id IN ($in)"); $p->exec("DELETE FROM vendors WHERE id IN ($in)"); }
    $p->exec("DELETE FROM users WHERE email LIKE 'pb10bt-%@wtg.test'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('PB10b',?, 'vendor_shopping','active',NOW(),NOW())")->execute([$e]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',1,10,NOW(),NOW())")->execute([$uid,'PB10b '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkStore(PDO $p,$slug,$v,$city){ $p->prepare("INSERT INTO stores (ownership,owner_vendor_id,city_id,name,slug,status,created_at,updated_at) VALUES ('vendor',?,?,?,?,'active',NOW(),NOW())")->execute([$v,$city,'PB10b S '.$slug,$slug]); return (int)$p->lastInsertId(); }

cleanup($pdo);
setCfg($pdo,'commerce_custom_tint_brands','[]');
setCfg($pdo,'commerce_tint_estimate_per_litre','250');
setCfg($pdo,'commerce_margin_slabs','{"enabled":true,"bands":[{"up_to":null,"flat":200}]}');
setCfg($pdo,'commerce_margin_multiplier','1');
setCfg($pdo,'commerce_tint_ceiling_mult','2.0');
setCfg($pdo,'commerce_advance_default_pct','50');
setCfg($pdo,'commerce_custom_tint_consent_required','1');
setCfg($pdo,'commerce_confirm_call','{"enabled":true,"min_value":1,"custom_always":true}');

$cityId=(int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if(!$cityId){ $pdo->exec("INSERT INTO cities (name,slug) VALUES ('PB10b City','pb10bt-city')"); $cityId=(int)$pdo->lastInsertId(); }
$cat=(int)$pdo->query("SELECT id FROM categories WHERE slug='paint-interior-emulsion' LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO commerce_paint_shades (brand,product_line,shade_code,shade_name,source,created_at) VALUES ('PB10bBerger','Silk','7D4','Ocean Blue','test',NOW())")->execute();
CustomTint::enableBrand($pdo,'PB10bBerger');
$uv=mkUser($pdo,'pb10bt-v@wtg.test'); $V=mkVendor($pdo,$uv,'pb10bt-v'); $St=mkStore($pdo,'pb10bt-s',$V,$cityId);
PaintCatalogue::createProduct($pdo,$V,['brand'=>'PB10bBerger','line'=>'Silk','category_id'=>$cat]);
$cust=mkUser($pdo,'pb10bt-c@wtg.test');

function newQuote($pdo,$cust,$cityId){ $r=CustomTintQuote::request($pdo,['user_id'=>$cust,'brand'=>'PB10bBerger','shade_code'=>'7D4','litres'=>10,'mode'=>'new','city_id'=>$cityId]); return (int)$r['quote_id']; }

echo "\n== [A] MarginEngine on the colour price + ceiling ==\n";
$q1=newQuote($pdo,$cust,$cityId);
$pr=CustomTintQuote::sellerRespond($pdo,$q1,$V,2000,'Deep Base');
ok('priced ok', $pr['ok'], true);
ok('final = colour 2000 + margin 200', (float)$pr['final_price'], 2200.0);
ok('margin = 200', (float)$pr['margin'], 200.0);
ok('no ceiling cap (under estimate×2)', $pr['ceiling_applied'], false);
// ceiling: a fat colour price would balloon → capped at estimate × mult. q1 seeded
// the band at ₹220/L, so the estimate is 220×10=2200 and the ceiling 2200×2=4400.
setCfg($pdo,'commerce_margin_slabs','{"enabled":true,"bands":[{"up_to":null,"flat":4000}]}');
$q2=newQuote($pdo,$cust,$cityId);
$pr2=CustomTintQuote::sellerRespond($pdo,$q2,$V,2000,'Deep Base');
ok('ceiling applied (6000 → 4400)', $pr2['ceiling_applied'], true);
ok('final capped at the band ceiling 4400', (float)$pr2['final_price'], 4400.0);
setCfg($pdo,'commerce_margin_slabs','{"enabled":true,"bands":[{"up_to":null,"flat":200}]}'); // restore normal

echo "\n== [B] one final-price popup ==\n";
$fin=CustomTintQuote::customerFinal($pdo,$q1,$cust);
ok('popup ready', $fin['ready'], true);
ok('final price 2200', (float)$fin['final_price'], 2200.0);
ok('advance 50% = 1100', (float)$fin['advance_amount'], 1100.0);
ok('balance at door 1100', (float)$fin['balance_at_door'], 1100.0);
ok('flagged non-returnable', $fin['non_returnable'], true);
ok('consent required', $fin['consent_required'], true);
ok('another customer cannot see it', CustomTintQuote::customerFinal($pdo,$q1,$cust+99999), null);

echo "\n== [C] accept: consent + order + advance + repeat-lock + confirm-call ==\n";
ok('accept without consent refused', CustomTintQuote::accept($pdo,$q1,$cust,false)['reason'], 'consent_required');
$ac=CustomTintQuote::accept($pdo,$q1,$cust,true);
ok('accept ok', $ac['ok'], true);
ok('advance recorded = 1100', (float)$ac['advance'], 1100.0);
ok('balance recorded = 1100', (float)$ac['balance'], 1100.0);
$oid=(int)$ac['order_id'];
ok('quote now ordered + linked', CustomTintQuote::get($pdo,$q1)['status'], 'ordered');
$adv=CommercePayment::advanceStatus($pdo,$oid);
ok('advance intent row exists (pending)', $adv['status'] ?? null, 'pending');
ok('margin on the ledger (subtotal−seller_subtotal=200)', (float)$pdo->query("SELECT (subtotal-seller_subtotal) FROM orders WHERE parent_order_id=$oid LIMIT 1")->fetchColumn(), 200.0);
ok('repeat-lock recorded', (int)$pdo->query("SELECT COUNT(*) FROM commerce_tint_order_log WHERE brand='PB10bBerger' AND shade_code='7D4' AND vendor_id=$V")->fetchColumn() >= 1, true);
ok('confirm-call flag raised for ops', in_array('confirm_call', CommerceEventBus::activeFlags($pdo,$oid), true), true);

echo "\n== [D] making starts only after advance captured → non-refundable ==\n";
ok('cannot start making before advance captured', CustomTintQuote::startMaking($pdo,$q1)['reason'], 'advance_not_captured');
$pdo->prepare("UPDATE commerce_order_payments SET status='captured', captured_amount=1100 WHERE order_id=$oid AND kind='advance'")->execute();
ok('advance refundable before making', CommerceCancellation::advanceRefundable($pdo,$oid), true);
ok('start making ok (advance captured)', CustomTintQuote::startMaking($pdo,$q1)['ok'], true);
ok('advance NON-refundable once making started', CommerceCancellation::advanceRefundable($pdo,$oid), false);

echo "\n== [E] routes wired ==\n";
$shop=get(SYSTEM_ROOT.'/heart/api/shop/index.php'); $sell=get(SYSTEM_ROOT.'/heart/api/commerce-seller/index.php');
ok('shop: final popup + accept routes', has($shop,'/tint/quotes/')&&has($shop,'/accept')&&has($shop,'customerFinal'), true);
ok('shop: accept starts the advance payment', has($shop,'initAdvance'), true);
ok('seller: making route', has($sell,'/making')&&has($sell,'startMaking'), true);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("PB10b TINT-PRICING RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
