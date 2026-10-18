<?php
// ─────────────────────────────────────────────────────────────────────────────
//  PB10a PROOF — custom-tint request + quote machinery.
//  Proves: brand+shade-code guard (wrong brand / unknown code rejected; a code
//  must exist in THAT brand); instant estimate (safe default until the band has
//  data); request lands on a seller as a 'quoted' box; repeat repaint pins the
//  previous seller; seller prices it (base from the shade book, never faked);
//  the quote-timeout sweep reroutes to the next seller (→ ops if none); dormant
//  until a brand is enabled. Run: php tests/commerce/pb10a_tint_quote_vectors.php
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
function snap(PDO $p,$k){ global $CFG; $s=$p->prepare("SELECT setting_value FROM app_settings WHERE setting_key=?"); $s->execute([$k]); $CFG[$k]=$s->fetchColumn(); }
register_shutdown_function(function() use ($pdo){ global $CFG; foreach($CFG as $k=>$v){ if($v===false) $pdo->prepare("DELETE FROM app_settings WHERE setting_key=?")->execute([$k]); else $pdo->prepare("UPDATE app_settings SET setting_value=? WHERE setting_key=?")->execute([$v,$k]); } });
function cleanup(PDO $p){
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $p->exec("DELETE FROM commerce_tint_quotes WHERE brand='PB10Berger'");
    $p->exec("DELETE FROM commerce_tint_order_log WHERE brand='PB10Berger'");
    $p->exec("DELETE FROM commerce_paint_shades WHERE brand='PB10Berger'");
    $vids=$p->query("SELECT id FROM vendors WHERE slug LIKE 'pb10at-%'")->fetchAll(PDO::FETCH_COLUMN);
    if($vids){$in=implode(',',array_map('intval',$vids));
        $pids=$p->query("SELECT id FROM products WHERE vendor_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        if($pids){$pin=implode(',',array_map('intval',$pids)); $p->exec("DELETE FROM inventory WHERE product_id IN ($pin)"); $p->exec("DELETE FROM product_variations WHERE product_id IN ($pin)"); $p->exec("DELETE FROM products WHERE id IN ($pin)"); }
        $p->exec("DELETE FROM stores WHERE owner_vendor_id IN ($in)"); $p->exec("DELETE FROM vendors WHERE id IN ($in)"); }
    $p->exec("DELETE FROM users WHERE email LIKE 'pb10at-%@wtg.test'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('PB10A',?, 'vendor_shopping','active',NOW(),NOW())")->execute([$e]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',1,10,NOW(),NOW())")->execute([$uid,'PB10A '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkStore(PDO $p,$slug,$v,$city){ $p->prepare("INSERT INTO stores (ownership,owner_vendor_id,city_id,name,slug,status,lat,lng,service_radius_km,created_at,updated_at) VALUES ('vendor',?,?,?,?,'active',29.2,79.5,NULL,NOW(),NOW())")->execute([$v,$city,'PB10A S '.$slug,$slug]); return (int)$p->lastInsertId(); }

cleanup($pdo);
snap($pdo,'commerce_custom_tint_brands'); snap($pdo,'commerce_tint_estimate_per_litre'); snap($pdo,'commerce_quote_timeout_sec');
$pdo->prepare("INSERT INTO app_settings (setting_key,setting_value,value_type,is_public) VALUES ('commerce_tint_estimate_per_litre','250','number',0) ON DUPLICATE KEY UPDATE setting_value='250'")->execute();
$pdo->prepare("INSERT INTO app_settings (setting_key,setting_value,value_type,is_public) VALUES ('commerce_quote_timeout_sec','180','number',0) ON DUPLICATE KEY UPDATE setting_value='180'")->execute();

$cityId=(int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if(!$cityId){ $pdo->exec("INSERT INTO cities (name,slug) VALUES ('PB10A City','pb10at-city')"); $cityId=(int)$pdo->lastInsertId(); }
$cat=(int)$pdo->query("SELECT id FROM categories WHERE slug='paint-interior-emulsion' LIMIT 1")->fetchColumn();
// fandeck row for the brand+code
$pdo->prepare("INSERT INTO commerce_paint_shades (brand,product_line,shade_code,shade_name,base_type,finish,source,created_at) VALUES ('PB10Berger','Silk','7D4','Ocean Blue',NULL,'silk','test',NOW())")->execute();

echo "\n== [A] dormant until the brand is enabled ==\n";
CustomTint::disableBrand($pdo,'PB10Berger');
ok('brand not enabled → request refused', CustomTintQuote::request($pdo,['user_id'=>1,'brand'=>'PB10Berger','shade_code'=>'7D4','litres'=>10,'mode'=>'new','city_id'=>$cityId])['reason'], 'brand_not_enabled');
CustomTint::enableBrand($pdo,'PB10Berger');
ok('brand enabled now', CustomTint::brandEnabled($pdo,'PB10Berger'), true);

// two sellers who carry the brand
$u1=mkUser($pdo,'pb10at-v1@wtg.test'); $V1=mkVendor($pdo,$u1,'pb10at-v1'); mkStore($pdo,'pb10at-s1',$V1,$cityId);
$u2=mkUser($pdo,'pb10at-v2@wtg.test'); $V2=mkVendor($pdo,$u2,'pb10at-v2'); mkStore($pdo,'pb10at-s2',$V2,$cityId);
PaintCatalogue::createProduct($pdo,$V1,['brand'=>'PB10Berger','line'=>'Silk','category_id'=>$cat]);
PaintCatalogue::createProduct($pdo,$V2,['brand'=>'PB10Berger','line'=>'Silk','category_id'=>$cat]);
$cust=mkUser($pdo,'pb10at-c@wtg.test');

echo "\n== [B] brand+code guard + estimate ==\n";
$est=CustomTintQuote::estimate($pdo,'PB10Berger','7D4',10);
ok('estimate = 250 x 10 = 2500', (float)$est['approx'], 2500.0);
ok('estimate source is default (no band yet)', $est['source'], 'default');
ok('wrong code rejected', CustomTintQuote::request($pdo,['user_id'=>$cust,'brand'=>'PB10Berger','shade_code'=>'NOPE','litres'=>10,'city_id'=>$cityId])['reason'], 'shade_code_not_in_brand');
ok('code only valid within its brand', CustomTint::brandHasCode($pdo,'PB10OtherBrand','7D4'), false);

echo "\n== [C] request lands on a seller as a quote box ==\n";
$r=CustomTintQuote::request($pdo,['user_id'=>$cust,'brand'=>'PB10Berger','shade_code'=>'7D4','litres'=>10,'mode'=>'new','city_id'=>$cityId]);
ok('quote created', $r['ok'], true);
ok('status quoted', $r['status'], 'quoted');
ok('assigned to a carrying seller', in_array($r['vendor_id'],[$V1,$V2],true), true);
$qid=(int)$r['quote_id']; $assigned=(int)$r['vendor_id'];
$inbox=CustomTintQuote::sellerInbox($pdo,$assigned);
ok('seller sees the code in the box', (function()use($inbox,$qid){ foreach($inbox as $x) if((int)$x['id']===$qid) return $x['shade_code']; return null; })(), '7D4');
ok('base_type blank in the box (seller fills)', (function()use($inbox,$qid){ foreach($inbox as $x) if((int)$x['id']===$qid) return $x['base_type']; return 'x'; })(), null);

echo "\n== [D] seller prices it (base from the shade book) ==\n";
$other = $assigned===$V1 ? $V2 : $V1;
ok('another seller cannot price it', CustomTintQuote::sellerRespond($pdo,$qid,$other,3000)['reason'], 'not_yours');
ok('assigned seller prices it', CustomTintQuote::sellerRespond($pdo,$qid,$assigned,3000,'Deep Base')['ok'], true);
ok('status now priced', CustomTintQuote::get($pdo,$qid)['status'], 'priced');
ok('base recorded (seller-entered)', CustomTintQuote::get($pdo,$qid)['base_type'], 'Deep Base');

echo "\n== [E] repeat repaint pins the previous seller ==\n";
CustomTint::recordMade($pdo,$cust,null,'PB10Berger','7D4','Deep Base',$V2,null);
$rep=CustomTintQuote::request($pdo,['user_id'=>$cust,'brand'=>'PB10Berger','shade_code'=>'7D4','litres'=>4,'mode'=>'repaint','city_id'=>$cityId]);
ok('repeat repaint routes to the prior seller (V2)', (int)$rep['vendor_id'], $V2);

echo "\n== [F] quote-timeout sweep reroutes to the next seller ==\n";
$r2=CustomTintQuote::request($pdo,['user_id'=>$cust,'brand'=>'PB10Berger','shade_code'=>'7D4','litres'=>10,'mode'=>'new','city_id'=>$cityId]);
$qid2=(int)$r2['quote_id']; $first=(int)$r2['vendor_id'];
$pdo->prepare("UPDATE commerce_tint_quotes SET updated_at=(NOW() - INTERVAL 10 MINUTE) WHERE id=?")->execute([$qid2]);
$n=CustomTintQuote::expireStaleQuotes($pdo);
ok('sweep rerouted >=1', $n >= 1, true);
$after=CustomTintQuote::get($pdo,$qid2);
ok('moved to the other seller', (int)$after['vendor_id'] !== $first, true);
ok('reroute_count incremented', (int)$after['reroute_count'], 1);
ok('still quoted (awaiting the new seller)', $after['status'], 'quoted');

echo "\n== [G] routes + cron wiring ==\n";
$shop=get(SYSTEM_ROOT.'/heart/api/shop/index.php'); $sell=get(SYSTEM_ROOT.'/heart/api/commerce-seller/index.php'); $cron=get(SYSTEM_ROOT.'/cron/commerce_lifecycle.php');
ok('shop: tint estimate/request/quotes routes', has($shop,'/tint/estimate')&&has($shop,'/tint/request')&&has($shop,'/tint/quotes'), true);
ok('seller: tint-quotes inbox + price routes', has($sell,'/tint-quotes')&&has($sell,'sellerRespond'), true);
ok('cron runs the tint-quote sweep', has($cron,'CustomTintQuote::expireStaleQuotes'), true);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("PB10a TINT-QUOTE RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
