<?php
// ─────────────────────────────────────────────────────────────────────────────
//  P7 PROOF HARNESS — PER-BRAND custom-tint go-live. Proves custom tint is
//  switched on one brand at a time (commerce_custom_tint_brands), not globally:
//    • no brand enabled → every custom-tint product is blocked
//    • enable Berger → Berger custom-tint orderable, Indigo still blocked
//    • ready paint is unaffected throughout
//    • enableBrand / disableBrand / brandEnabled (admin ops, case-insensitive)
//  Config is snapshot/restored so the live list returns to its real value.
//  Run: php tests/commerce/p7_per_brand_tint_vectors.php   (local 10.11)
// ─────────────────────────────────────────────────────────────────────────────
define('SYSTEM_ROOT', dirname(dirname(__DIR__)));
foreach (file(SYSTEM_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2); $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
    if (getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
}
require_once SYSTEM_ROOT . '/core/helpers/Database.php';
require_once SYSTEM_ROOT . '/lib/commerce/OrderabilityResolver.php';
require_once SYSTEM_ROOT . '/lib/commerce/CustomTint.php';
require_once SYSTEM_ROOT . '/lib/commerce/UnitConfig.php';
$pdo = Database::getConnection();

$PASS = 0; $FAIL = 0;
function ok($label, $got, $exp = true) {
    global $PASS, $FAIL;
    if ($got === $exp) { $PASS++; printf("  PASS  %-52s got=%s\n", $label, var_export($got, true)); }
    else { $FAIL++; printf("  FAIL  %-52s got=%s exp=%s\n", $label, var_export($got, true), var_export($exp, true)); }
}
function setcfg(PDO $p,$k,$v){ $p->prepare("INSERT INTO app_settings (setting_key,setting_value,value_type,is_public) VALUES (?,?,'text',0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$k,$v]); UnitConfig::resetCache(); }
function brands(PDO $p, array $arr){ $p->prepare("INSERT INTO app_settings (setting_key,setting_value,value_type,is_public) VALUES ('commerce_custom_tint_brands',?, 'text',0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([json_encode(array_values($arr))]); UnitConfig::resetCache(); }
function cleanup(PDO $p){
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $p->exec("DELETE i FROM inventory i JOIN products pr ON pr.id=i.product_id WHERE pr.name LIKE 'P7 %'");
    $p->exec("DELETE FROM products WHERE name LIKE 'P7 %'");
    $p->exec("DELETE FROM stores   WHERE slug LIKE 'p7t-%'");
    $p->exec("DELETE FROM vendors  WHERE slug LIKE 'p7t-%'");
    $p->exec("DELETE FROM users    WHERE email LIKE 'p7t-%@wtg.test'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('P7',?, 'vendor_shopping','active',NOW(),NOW())")->execute([$e]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',1,10,NOW(),NOW())")->execute([$uid,'P7 '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkStore(PDO $p,$slug,$v,$city){ $p->prepare("INSERT INTO stores (ownership,owner_vendor_id,city_id,name,slug,status,created_at,updated_at) VALUES ('vendor',?,?,?,?,'active',NOW(),NOW())")->execute([$v,$city,'P7 S '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkProduct(PDO $p,$v,$st,$city,$cat,$slug,$attrs){ $p->prepare("INSERT INTO products (vendor_id,store_id,city_id,category_id,name,slug,price,status,attributes,created_at,updated_at) VALUES (?,?,?,?,?,?,1200,'active',?,NOW(),NOW())")->execute([$v,$st,$city,$cat,'P7 '.$slug,$slug,$attrs]); $pid=(int)$p->lastInsertId(); $p->prepare("INSERT INTO inventory (product_id,quantity,reserved,low_stock_alert,track_inventory,allow_backorder,updated_at) VALUES (?,10,0,5,1,0,NOW())")->execute([$pid]); return $pid; }
function reason(PDO $p,$pid,$city){ return OrderabilityResolver::check($p,$pid,$city)['reason']; }

cleanup($pdo);
$cfgSnap = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='commerce_unit_config'")->fetchColumn();
$brSnap  = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='commerce_custom_tint_brands'")->fetchColumn();
register_shutdown_function(function() use($pdo,$cfgSnap,$brSnap){ try{
    if($cfgSnap!==false&&$cfgSnap!==null) $pdo->prepare("UPDATE app_settings SET setting_value=? WHERE setting_key='commerce_unit_config'")->execute([$cfgSnap]);
    if($brSnap!==false&&$brSnap!==null)   $pdo->prepare("UPDATE app_settings SET setting_value=? WHERE setting_key='commerce_custom_tint_brands'")->execute([$brSnap]);
}catch(\Throwable $e){} });
setcfg($pdo,'commerce_unit_config','{"units":{"paint":{"enabled":true}},"paint_category_slugs":["paint-interior-emulsion","paint-exterior-emulsion","paint-enamel","paint-distemper","paint-primer","paint-putty","paint-waterproofing"]}');

$cityId=(int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if(!$cityId){ $pdo->exec("INSERT INTO cities (name,slug) VALUES ('P7 City','p7t-city')"); $cityId=(int)$pdo->lastInsertId(); }
$cat=(int)$pdo->query("SELECT id FROM categories WHERE slug='paint-interior-emulsion' LIMIT 1")->fetchColumn();
$u=mkUser($pdo,'p7t-v@wtg.test'); $V=mkVendor($pdo,$u,'p7t-v'); $St=mkStore($pdo,'p7t-s',$V,$cityId);

$tint=fn($brand)=>json_encode(['brand'=>$brand,'product_name'=>$brand.' Tintable Base','shade_mode'=>'tintable','custom_tintable'=>'yes','base_type'=>'white','returnable'=>'no']);
$pBerger = mkProduct($pdo,$V,$St,$cityId,$cat,'p7t-berger',$tint('Berger'));
$pIndigo = mkProduct($pdo,$V,$St,$cityId,$cat,'p7t-indigo',$tint('Indigo'));
$pReady  = mkProduct($pdo,$V,$St,$cityId,$cat,'p7t-ready',json_encode(['brand'=>'Berger','product_name'=>'Berger Easy Clean','shade_mode'=>'ready','finish'=>'sheen','returnable'=>'yes']));

echo "\n== [A] NO brand enabled → all custom-tint blocked; ready paint OK ==\n";
brands($pdo,[]);
ok('Berger custom-tint blocked', reason($pdo,$pBerger,$cityId), 'custom_tint_off');
ok('Indigo custom-tint blocked', reason($pdo,$pIndigo,$cityId), 'custom_tint_off');
ok('ready paint orderable',      reason($pdo,$pReady,$cityId), 'ok');
ok('CustomTint::enabled() false (no brands)', CustomTint::enabled($pdo), false);

echo "\n== [B] enable Berger → only Berger custom-tint orderable ==\n";
$list = CustomTint::enableBrand($pdo,'Berger'); UnitConfig::resetCache();
ok('enabledBrands contains Berger', in_array('Berger',$list,true), true);
ok('brandEnabled(berger) case-insensitive', CustomTint::brandEnabled($pdo,'berger'), true);
ok('brandEnabled(Indigo) false',            CustomTint::brandEnabled($pdo,'Indigo'), false);
ok('Berger custom-tint NOW orderable', reason($pdo,$pBerger,$cityId), 'ok');
ok('Indigo custom-tint STILL blocked', reason($pdo,$pIndigo,$cityId), 'custom_tint_off');
ok('ready paint still orderable',      reason($pdo,$pReady,$cityId), 'ok');
ok('CustomTint::enabled() true (a brand live)', CustomTint::enabled($pdo), true);

echo "\n== [C] add Indigo too → both orderable ==\n";
CustomTint::enableBrand($pdo,'Indigo'); UnitConfig::resetCache();
ok('Berger orderable', reason($pdo,$pBerger,$cityId), 'ok');
ok('Indigo orderable', reason($pdo,$pIndigo,$cityId), 'ok');

echo "\n== [D] disable Berger → Berger blocked again, Indigo stays ==\n";
$list2 = CustomTint::disableBrand($pdo,'Berger'); UnitConfig::resetCache();
ok('Berger removed from list', in_array('Berger',$list2,true), false);
ok('Berger blocked again', reason($pdo,$pBerger,$cityId), 'custom_tint_off');
ok('Indigo still orderable', reason($pdo,$pIndigo,$cityId), 'ok');
ok('ready paint unaffected throughout', reason($pdo,$pReady,$cityId), 'ok');

echo "\n== [E] back to none → all blocked, ready OK ==\n";
brands($pdo,[]);
ok('Berger blocked', reason($pdo,$pBerger,$cityId), 'custom_tint_off');
ok('Indigo blocked', reason($pdo,$pIndigo,$cityId), 'custom_tint_off');
ok('ready paint orderable', reason($pdo,$pReady,$cityId), 'ok');

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("P7 PER-BRAND RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
