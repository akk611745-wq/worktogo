<?php
// ─────────────────────────────────────────────────────────────────────────────
//  S10 PROOF HARNESS — Customer-app composer (StorefrontComposer):
//    seller-hidden policy · platform label · unit-UI config · related products.
//  Proves the DB-config, Unit-driven storefront logic that the /api/shop adapter
//  exposes to the standalone customer app. Idempotent (markers s10t-*).
//  Run: php tests/commerce/s10_customer_app_vectors.php   (local DB)
// ─────────────────────────────────────────────────────────────────────────────
define('SYSTEM_ROOT', dirname(dirname(__DIR__)));
foreach (file(SYSTEM_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2); $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
    if (getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
}
require_once SYSTEM_ROOT . '/core/helpers/Database.php';
require_once SYSTEM_ROOT . '/lib/commerce/StorefrontComposer.php';
$pdo = Database::getConnection();

$PASS = 0; $FAIL = 0;
function ok($label, $got, $exp) {
    global $PASS, $FAIL;
    if ($got === $exp) { $PASS++; printf("  PASS  %-46s got=%s\n", $label, var_export($got, true)); }
    else { $FAIL++; printf("  FAIL  %-46s got=%s exp=%s\n", $label, var_export($got, true), var_export($exp, true)); }
}
function setcfg(PDO $p, $k, $v) {
    $p->prepare("INSERT INTO app_settings (setting_key,setting_value,value_type,is_public) VALUES (?,?,'text',0)
                 ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$k, $v]);
}
function cleanup(PDO $p) {
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $p->exec("DELETE i FROM inventory i JOIN products pr ON pr.id=i.product_id WHERE pr.slug LIKE 's10t-%'");
    $p->exec("DELETE FROM products   WHERE slug LIKE 's10t-%'");
    $p->exec("DELETE FROM stores     WHERE slug LIKE 's10t-%'");
    $p->exec("DELETE FROM vendors    WHERE slug LIKE 's10t-%'");
    $p->exec("DELETE FROM users      WHERE email LIKE 's10t-%@wtg.test'");
    $p->exec("DELETE FROM categories WHERE slug LIKE 's10t-%'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('S10 User',?, 'vendor_shopping','active',NOW(),NOW())")->execute([$e]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug,$online){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,busy_until,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',?,NULL,10,NOW(),NOW())")->execute([$uid,'S10 Vendor '.$slug,$slug,$online]); return (int)$p->lastInsertId(); }
function mkStore(PDO $p,$slug,$ownership,$ownerVendor,$cityId){ $p->prepare("INSERT INTO stores (ownership,owner_vendor_id,city_id,name,slug,status,created_at,updated_at) VALUES (?,?,?,?,?, 'active',NOW(),NOW())")->execute([$ownership,$ownerVendor,$cityId,'S10 Store '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkCat(PDO $p,$slug){ $p->prepare("INSERT INTO categories (name,slug,type,is_active,created_at,updated_at) VALUES (?,?,'product',1,NOW(),NOW())")->execute(['S10 Cat '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkProduct(PDO $p,$vendorId,$storeId,$cityId,$catId,$slug){ $p->prepare("INSERT INTO products (vendor_id,store_id,city_id,category_id,name,slug,price,status,created_at,updated_at) VALUES (?,?,?,?,?,?,199.00,'active',NOW(),NOW())")->execute([$vendorId,$storeId,$cityId,$catId,'S10 Product '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkInv(PDO $p,$pid,$qty,$reserved){ $p->prepare("INSERT INTO inventory (product_id,quantity,reserved,low_stock_alert,track_inventory,allow_backorder,updated_at) VALUES (?,?,?,5,1,0,NOW())")->execute([$pid,$qty,$reserved]); }

cleanup($pdo);

// snapshot config to restore afterwards
$snap = [];
foreach (['commerce_seller_visibility','commerce_platform_label','commerce_unit_ui','commerce_related_limit','commerce_enabled'] as $k) {
    $s = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1"); $s->execute([$k]); $snap[$k] = $s->fetchColumn();
}
setcfg($pdo, 'commerce_enabled', '1');

$cityId = (int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if (!$cityId) { $pdo->exec("INSERT INTO cities (name,slug) VALUES ('S10 City','s10t-city')"); $cityId = (int)$pdo->lastInsertId(); }

$cat1 = mkCat($pdo,'s10t-cat');   // test category under test
$cat2 = mkCat($pdo,'s10t-cat2');  // sibling category (related must not leak across)

$uOn  = mkUser($pdo,'s10t-on@wtg.test');  $vOn  = mkVendor($pdo,$uOn, 's10t-von', 1);
$uOff = mkUser($pdo,'s10t-off@wtg.test'); $vOff = mkVendor($pdo,$uOff,'s10t-voff',0);
$uCo  = mkUser($pdo,'s10t-co@wtg.test');  $vCo  = mkVendor($pdo,$uCo, 's10t-vco', 1); // satisfies company product NOT NULL vendor_id

$stOn   = mkStore($pdo,'s10t-on',  'vendor', $vOn,  $cityId);
$stOff  = mkStore($pdo,'s10t-off', 'vendor', $vOff, $cityId);
$stCo   = mkStore($pdo,'s10t-co',  'company',null,  $cityId);

$pSelf  = mkProduct($pdo,$vOn,$stOn,  $cityId,$cat1,'s10t-self');   mkInv($pdo,$pSelf,10,0);
$pA     = mkProduct($pdo,$vOn,$stOn,  $cityId,$cat1,'s10t-a');      mkInv($pdo,$pA,10,0);     // related ✓
$pCoP   = mkProduct($pdo,$vCo,$stCo,  $cityId,$cat1,'s10t-co-p');   mkInv($pdo,$pCoP,10,0);   // related ✓ (company always on)
$pOff   = mkProduct($pdo,$vOff,$stOff,$cityId,$cat1,'s10t-offp');   mkInv($pdo,$pOff,10,0);   // excluded (seller OFF)
$pNo    = mkProduct($pdo,$vOn,$stOn,  $cityId,$cat1,'s10t-nostock');mkInv($pdo,$pNo,5,5);     // excluded (no stock)
$pOther = mkProduct($pdo,$vOn,$stOn,  $cityId,$cat2,'s10t-other');  mkInv($pdo,$pOther,10,0); // excluded (other category)

/* ── seller visibility + platform label ───────────────────────────────────── */
echo "\n== platform label + seller visibility (config-driven) ==\n";
setcfg($pdo,'commerce_platform_label','WTGtest');
ok('platformLabel reads config',            StorefrontComposer::platformLabel($pdo), 'WTGtest');

setcfg($pdo,'commerce_seller_visibility', json_encode(['default'=>'shown','hidden'=>[],'shown'=>[]]));
ok('default shown → cat1 shown',            StorefrontComposer::sellerVisibility($pdo,$cat1), 'shown');

$shownP = StorefrontComposer::composeProduct($pdo, ['id'=>1,'store_name'=>'Shop X','vendor_name'=>'V','is_company'=>false,'category_id'=>$cat1]);
ok('shown: seller_hidden false',            $shownP['seller_hidden'], false);
ok('shown: fulfilled_by = store name',      $shownP['fulfilled_by'],  'Shop X');
ok('shown: store_name kept',                $shownP['store_name'],    'Shop X');

echo "\n== seller HIDDEN (default) ==\n";
setcfg($pdo,'commerce_seller_visibility', json_encode(['default'=>'hidden','hidden'=>[],'shown'=>[]]));
ok('default hidden → cat1 hidden',          StorefrontComposer::sellerVisibility($pdo,$cat1), 'hidden');
$hidP = StorefrontComposer::composeProduct($pdo, ['id'=>1,'store_name'=>'Shop X','vendor_name'=>'V','is_company'=>true,'category_id'=>$cat1,'store'=>['name'=>'Shop X','is_company'=>true,'locality'=>'Mukhani','logo_url'=>'x.png']]);
ok('hidden: seller_hidden true',            $hidP['seller_hidden'], true);
ok('hidden: store_name = platform label',   $hidP['store_name'],    'WTGtest');
ok('hidden: vendor_name nulled',            $hidP['vendor_name'],   null);
ok('hidden: is_company nulled',             $hidP['is_company'],    null);
ok('hidden: fulfilled_by = platform label', $hidP['fulfilled_by'],  'WTGtest');
ok('hidden: store.name masked',             $hidP['store']['name'], 'WTGtest');
ok('hidden: store.logo dropped',            $hidP['store']['logo_url'], null);

echo "\n== per-category override wins over default ==\n";
setcfg($pdo,'commerce_seller_visibility', json_encode(['default'=>'hidden','hidden'=>[$cat2],'shown'=>[$cat1]]));
ok('override: cat1 forced shown',           StorefrontComposer::sellerVisibility($pdo,$cat1), 'shown');
ok('override: cat2 forced hidden',          StorefrontComposer::sellerVisibility($pdo,$cat2), 'hidden');

/* ── unit UI config ────────────────────────────────────────────────────────── */
echo "\n== unit-UI meta (Unit-driven, founder-free) ==\n";
setcfg($pdo,'commerce_related_limit','8');
$ui = StorefrontComposer::unitUi($pdo);
ok('unitUi: platform_label present',        $ui['platform_label'], 'WTGtest');
ok('unitUi: related_limit int',             $ui['related_limit'],  8);
ok('unitUi: seller_visibility present',     is_array($ui['seller_visibility']) && isset($ui['seller_visibility']['default']), true);
ok('unitUi: ui block present',              is_array($ui['ui']), true);

/* ── related products ──────────────────────────────────────────────────────── */
echo "\n== related products (same category, in-range, orderable) ==\n";
setcfg($pdo,'commerce_seller_visibility', json_encode(['default'=>'shown','hidden'=>[],'shown'=>[]]));
$rel = StorefrontComposer::related($pdo, $pSelf, $cityId, 20);
$ids = array_map(fn($p)=>(int)$p['id'], $rel);
ok('related includes ON-seller product',    in_array($pA,   $ids, true), true);
ok('related includes company product',      in_array($pCoP, $ids, true), true);
ok('related excludes self',                 in_array($pSelf,$ids, true), false);
ok('related excludes OFF-seller product',   in_array($pOff, $ids, true), false);
ok('related excludes out-of-stock',         in_array($pNo,  $ids, true), false);
ok('related excludes other category',       in_array($pOther,$ids,true), false);

echo "\n== related respects limit + kill-switch + seller-hidden ==\n";
$rel1 = StorefrontComposer::related($pdo, $pSelf, $cityId, 1);
ok('related honours limit=1',               count($rel1), 1);

setcfg($pdo,'commerce_seller_visibility', json_encode(['default'=>'hidden','hidden'=>[],'shown'=>[]]));
$relH = StorefrontComposer::related($pdo, $pSelf, $cityId, 20);
$allHidden = $relH ? array_reduce($relH, fn($c,$p)=>$c && $p['seller_hidden']===true && $p['store_name']==='WTGtest', true) : false;
ok('related items seller-composed (hidden)', $allHidden, true);

setcfg($pdo,'commerce_enabled','0');
ok('related empty when commerce paused',    StorefrontComposer::related($pdo, $pSelf, $cityId, 20), []);
setcfg($pdo,'commerce_enabled','1');

// restore config + fixtures
foreach ($snap as $k=>$v) { if ($v !== false && $v !== null) setcfg($pdo,$k,$v); }
cleanup($pdo);

echo "\n────────────────────────────────────\n";
printf("S10 RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
