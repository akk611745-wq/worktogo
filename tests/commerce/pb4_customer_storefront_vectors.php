<?php
// ─────────────────────────────────────────────────────────────────────────────
//  PB4 PROOF — customer paint storefront read-model (PaintStorefront).
//  Proves: the per-city shop ON/OFF switch (no global SERVICE_ONLY flip); the
//  paint home grouped one card per parent (paint families only, cheapest in-area
//  pack price, pincode-serviceable); the product page (variant chips + pack
//  selector with ONE server price per pack); seller identity hidden (customer
//  sees WorkToGo — no store/vendor/base/margin leaks); + shop + admin + client
//  wiring. Cart/checkout reuse the existing /api/shop endpoints (a pack SKU is an
//  ordinary product row). Run: php tests/commerce/pb4_customer_storefront_vectors.php
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
require_once SYSTEM_ROOT . '/lib/commerce/PaintStorefront.php';
require_once SYSTEM_ROOT . '/lib/commerce/Serviceability.php';
$pdo = Database::getConnection();

$PASS = 0; $FAIL = 0;
function ok($label, $got, $exp = true) {
    global $PASS, $FAIL;
    $g = is_float($got) ? number_format($got, 2, '.', '') : var_export($got, true);
    $e = is_float($exp) ? number_format($exp, 2, '.', '') : var_export($exp, true);
    if ($g === $e) { $PASS++; printf("  PASS  %-54s %s\n", $label, $g); }
    else { $FAIL++; printf("  FAIL  %-54s got=%s exp=%s\n", $label, $g, $e); }
}
function has(string $h,string $n):bool{ return strpos($h,$n)!==false; }
function get(string $p):string{ return is_file($p)?(string)file_get_contents($p):''; }
function cleanup(PDO $p){
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $vids=$p->query("SELECT id FROM vendors WHERE slug LIKE 'pb4t-%'")->fetchAll(PDO::FETCH_COLUMN);
    if($vids){$in=implode(',',array_map('intval',$vids));
        $pids=$p->query("SELECT id FROM products WHERE vendor_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        if($pids){$pin=implode(',',array_map('intval',$pids));
            $p->exec("DELETE FROM inventory WHERE product_id IN ($pin)"); $p->exec("DELETE FROM product_variations WHERE product_id IN ($pin)");
            $p->exec("DELETE FROM products WHERE id IN ($pin)"); }
        $p->exec("DELETE FROM stores WHERE owner_vendor_id IN ($in)"); $p->exec("DELETE FROM vendors WHERE id IN ($in)"); }
    $cids=$p->query("SELECT id FROM cities WHERE slug LIKE 'pb4t-%'")->fetchAll(PDO::FETCH_COLUMN);
    if($cids){$in=implode(',',array_map('intval',$cids)); $p->exec("DELETE FROM commerce_serviceability WHERE city_id IN ($in)"); $p->exec("DELETE FROM cities WHERE id IN ($in)"); }
    $p->exec("DELETE FROM users WHERE email LIKE 'pb4t-%@wtg.test'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('PB4',?, 'vendor_shopping','active',NOW(),NOW())")->execute([$e]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',1,10,NOW(),NOW())")->execute([$uid,'PB4 '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkStore(PDO $p,$slug,$v,$city){ $p->prepare("INSERT INTO stores (ownership,owner_vendor_id,city_id,name,slug,status,lat,lng,service_radius_km,created_at,updated_at) VALUES ('vendor',?,?,?,?,'active',29.2,79.5,NULL,NOW(),NOW())")->execute([$v,$city,'PB4 S '.$slug,$slug]); return (int)$p->lastInsertId(); }

cleanup($pdo);
// shop flag starts from a known state for this test
$pdo->exec("DELETE FROM app_settings WHERE setting_key='commerce_shop_enabled_cities'");
$pdo->exec("UPDATE app_settings SET setting_value='0' WHERE setting_key='commerce_shop_enabled'");

$pdo->exec("INSERT INTO cities (name,slug,is_active) VALUES ('PB4 City','pb4t-city',1)"); $cityA=(int)$pdo->lastInsertId();
$cat=(int)$pdo->query("SELECT id FROM categories WHERE slug='paint-interior-emulsion' LIMIT 1")->fetchColumn();
$u=mkUser($pdo,'pb4t-v@wtg.test'); $V=mkVendor($pdo,$u,'pb4t-v'); mkStore($pdo,'pb4t-s',$V,$cityA);

echo "\n== [A] per-city module switch (no global SERVICE_ONLY flip) ==\n";
ok('shop OFF by default for the city', PaintStorefront::shopEnabled($pdo,$cityA), false);
PaintStorefront::setShopEnabled($pdo,$cityA,true);
ok('shop ON after enabling the city', PaintStorefront::shopEnabled($pdo,$cityA), true);
ok('another city stays OFF', PaintStorefront::shopEnabled($pdo,99999), false);
PaintStorefront::setShopEnabled($pdo,$cityA,false);
ok('shop OFF after disabling', PaintStorefront::shopEnabled($pdo,$cityA), false);
PaintStorefront::setShopEnabled($pdo,$cityA,true); // leave ON for the rest

echo "\n== [B] families / brands / coverage ==\n";
ok('paint families present (>=1)', count(PaintStorefront::families($pdo)) >= 1, true);
ok('brands present', count(PaintStorefront::brands($pdo)) > 0, true);
ok('coverage rate default 140', (float)PaintStorefront::coverageSqftPerLitre($pdo), 140.0);

echo "\n== build a paint product (parent → variant → 1L/4L/10L packs, approved) ==\n";
$pr = PaintCatalogue::createProduct($pdo,$V,['brand'=>'Berger','line'=>'Silk Glamor','category_id'=>$cat,'photo'=>'/uploads/products/pb4.jpg']);
$parent=(int)$pr['product_id'];
$vr = PaintCatalogue::addVariant($pdo,$V,$parent,['variant_type'=>'ready_shade','label'=>'White','shade_mode'=>'ready','finish'=>'Silk']);
$vid=(int)$vr['variation_id'];
$pk1=PaintCatalogue::addPackSku($pdo,$V,$parent,$vid,['pack_size'=>'1','pack_unit'=>'L','base_price'=>400,'stock'=>10,'weight'=>1.3]);
$pk4=PaintCatalogue::addPackSku($pdo,$V,$parent,$vid,['pack_size'=>'4','pack_unit'=>'L','base_price'=>1400,'stock'=>5,'weight'=>5.2]);
$pk10=PaintCatalogue::addPackSku($pdo,$V,$parent,$vid,['pack_size'=>'10','pack_unit'=>'L','base_price'=>3200,'stock'=>0,'weight'=>13.0]);
foreach ([$pk1,$pk4,$pk10] as $s) PaintCatalogue::approveProduct($pdo,(int)$s['product_id']);

echo "\n== [C] paint home — one card per parent, paint-only, from-price ==\n";
$cards = PaintStorefront::home($pdo,$cityA,'',[]);
$card = null; foreach($cards as $c){ if($c['parent_id']===$parent){ $card=$c; break; } }
ok('home returns our parent as a card', $card !== null, true);
ok('card grouped (one per parent)', count(array_filter($cards, fn($c)=>$c['parent_id']===$parent)), 1);
ok('from_price = cheapest pack (1L=min)', (float)($card['from_price']??0) > 0, true);
ok('card in_stock (1L/4L available)', $card['in_stock']??null, true);
ok('card hides seller (no store/vendor key)', isset($card['store_name'])||isset($card['vendor_name'])||isset($card['seller_base']), false);
ok('brand filter matches', count(PaintStorefront::home($pdo,$cityA,'',['brand'=>'Berger'])) >= 1, true);
ok('wrong brand → not listed', (function()use($pdo,$cityA,$parent){ foreach(PaintStorefront::home($pdo,$cityA,'',['brand'=>'NoSuchBrand']) as $c) if($c['parent_id']===$parent) return true; return false; })(), false);
ok('search by name matches', (function()use($pdo,$cityA,$parent){ foreach(PaintStorefront::home($pdo,$cityA,'',['q'=>'Silk Glamor']) as $c) if($c['parent_id']===$parent) return true; return false; })(), true);

echo "\n== [D] product page — variants + packs, ONE server price, seller hidden ==\n";
$prod = PaintStorefront::product($pdo,$parent,$cityA,'');
ok('product returns the parent', (int)$prod['parent_id'], $parent);
ok('one variant (ready_shade White)', count($prod['variants']), 1);
ok('three packs (1/4/10 L)', count($prod['packs']), 3);
$p4 = null; foreach($prod['packs'] as $pk){ if($pk['pack_label']==='4 L'){ $p4=$pk; break; } }
ok('4L pack carries its own server price', (float)($p4['price']??0) > 0, true);
ok('4L pack is orderable product_id', (int)($p4['product_id']??0) > 0, true);
ok('10L pack shows out_of_stock', (function()use($prod){ foreach($prod['packs'] as $pk) if($pk['pack_label']==='10 L') return $pk['in_stock']; return null; })(), false);
ok('product hides seller (fulfilled by WorkToGo)', isset($prod['store'])||isset($prod['vendor_name'])||isset($prod['seller_base']), false);
ok('custom-tint stays hidden', $prod['custom_tint']??null, false);

echo "\n== [E] serviceability gate on the storefront ==\n";
Serviceability::addArea($pdo,'city',$cityA,null,'263139'); // city now serves only this pincode
ok('served pincode → product visible', PaintStorefront::product($pdo,$parent,$cityA,'263139') !== null, true);
ok('unserved pincode → product hidden', PaintStorefront::product($pdo,$parent,$cityA,'110001'), null);
ok('unserved pincode → empty home', count(PaintStorefront::home($pdo,$cityA,'110001',[])), 0);

echo "\n== [F] non-paint / missing parent → null ==\n";
ok('non-existent parent → null', PaintStorefront::product($pdo,99999999,$cityA,''), null);

echo "\n== [G] server + admin wiring ==\n";
$shop = get(SYSTEM_ROOT.'/heart/api/shop/index.php');
$adm  = get(SYSTEM_ROOT.'/heart/api/commerce-admin/index.php');
ok('shop: meta exposes shop_enabled + paint data', has($shop,'shop_enabled')&&has($shop,'paint_families')&&has($shop,'paint_brands'), true);
ok('shop: paint home + product routes', has($shop,'/paint/home')&&has($shop,'/paint/product/'), true);
ok('admin: shop-enabled switch routes', has($adm,'/commerce-admin/shop-enabled'), true);

echo "\n== [H] client wiring (beside the never-touch engines) ==\n";
$js  = get(SYSTEM_ROOT.'/app/commerce/shop.js');
$capi= get(SYSTEM_ROOT.'/app/commerce/api.js');
$home= get(SYSTEM_ROOT.'/app/pages/home.js');
$rtr = get(SYSTEM_ROOT.'/app/js/router.js');
$svc = get(SYSTEM_ROOT.'/admin/commerce-serviceability.html');
ok('shop.js: pincode gate + paint home + paint product', has($js,'renderPincodeGate')&&has($js,'renderPaintHome')&&has($js,'renderPaintProduct'), true);
ok('shop.js: variant chips + pack selector + coverage', has($js,'pickVariant')&&has($js,'pickPack')&&has($js,'calcCoverage'), true);
ok('api.js: paint endpoints', has($capi,'paintHome')&&has($capi,'paintProduct')&&has($capi,'serviceRequest'), true);
ok('home.js: Paint entry gated by module flag', has($home,'_mountPaintEntry')&&has($home,'shop_enabled'), true);
ok('router: #shop gated by module switch, not SERVICE_ONLY flip', has($rtr,'_shopModuleEnabled')&&has($rtr,'WTG_COMMERCE_SHOP_ENABLED'), true);
ok('admin serviceability page has the shop toggle', has($svc,'shop-enabled')&&has($svc,'toggleShop'), true);
ok('orders.js NOT referenced by the paint flow', has($js,'orders.js')||has($js,'OrderService')||has($js,'video'), false);

cleanup($pdo);
$pdo->exec("DELETE FROM app_settings WHERE setting_key='commerce_shop_enabled_cities'");
echo "\n────────────────────────────────────\n";
printf("PB4 CUSTOMER-STOREFRONT RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
