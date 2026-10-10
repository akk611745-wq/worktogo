<?php
// ─────────────────────────────────────────────────────────────────────────────
//  S16 PROOF HARNESS — Seller catalogue + the margin-EARNING wiring.
//  Proves: priceFor (base → customer price via S12 slab); createOffer (draft,
//  price=base+margin, seller_base recorded, inventory); the verification gate
//  (draft NOT orderable → approve → orderable); updateOffer reprice; setStock;
//  listMine + isolation; and persistSellerBase → orders.seller_subtotal /
//  order_items.seller_base_price so LedgerEngine's platform margin = subtotal −
//  seller_subtotal (margin earns). Idempotent (markers s16t-*).
//  Run: php tests/commerce/s16_seller_catalogue_vectors.php
// ─────────────────────────────────────────────────────────────────────────────
define('SYSTEM_ROOT', dirname(dirname(__DIR__)));
foreach (file(SYSTEM_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2); $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
    if (getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
}
require_once SYSTEM_ROOT . '/core/helpers/Database.php';
require_once SYSTEM_ROOT . '/lib/commerce/SellerCatalogue.php';
require_once SYSTEM_ROOT . '/lib/commerce/OrderabilityResolver.php';
$pdo = Database::getConnection();

$PASS = 0; $FAIL = 0;
function ok($label, $got, $exp) {
    global $PASS, $FAIL;
    $g = is_float($got) ? number_format($got, 2, '.', '') : $got;
    $e = is_float($exp) ? number_format($exp, 2, '.', '') : $exp;
    if ($g === $e) { $PASS++; printf("  PASS  %-46s got=%s\n", $label, var_export($g, true)); }
    else { $FAIL++; printf("  FAIL  %-46s got=%s exp=%s\n", $label, var_export($g, true), var_export($e, true)); }
}
function setcfg(PDO $p, $k, $v, $t='text') { $p->prepare("INSERT INTO app_settings (setting_key,setting_value,value_type,is_public) VALUES (?,?,?,0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$k,$v,$t]); }
function cleanup(PDO $p) {
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $ids=$p->query("SELECT id FROM orders WHERE order_number LIKE 's16t-%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids){ $in=implode(',',array_map('intval',$ids)); $p->exec("DELETE FROM order_items WHERE order_id IN ($in)"); }
    $p->exec("DELETE FROM orders WHERE order_number LIKE 's16t-%'");
    $p->exec("DELETE i FROM inventory i JOIN products pr ON pr.id=i.product_id WHERE pr.slug LIKE 's16t-%' OR pr.name LIKE 'S16T %'");
    $p->exec("DELETE FROM products WHERE slug LIKE 's16t-%' OR name LIKE 'S16T %'");
    $p->exec("DELETE FROM stores   WHERE slug LIKE 's16t-%'");
    $p->exec("DELETE FROM vendors  WHERE slug LIKE 's16t-%'");
    $p->exec("DELETE FROM users    WHERE email LIKE 's16t-%@wtg.test'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('S16',?, 'vendor_shopping','active',NOW(),NOW())")->execute([$e]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',1,10,NOW(),NOW())")->execute([$uid,'S16 '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkStore(PDO $p,$slug,$ownerV,$city){ $p->prepare("INSERT INTO stores (ownership,owner_vendor_id,city_id,name,slug,status,created_at,updated_at) VALUES ('vendor',?,?,?,?,'active',NOW(),NOW())")->execute([$ownerV,$city,'S16 S '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkOrder(PDO $p,$num,$uid,$vendor,$store,$city,$sub,$tot){ $p->prepare("INSERT INTO orders (order_number,user_id,vendor_id,store_id,city_id,status,payment_status,payment_method,subtotal,total,seller_subtotal,shipping_address,delivery_status,ledger_status,created_at,updated_at) VALUES (?,?,?,?,?, 'confirmed','unpaid','cod',?,?,NULL,'{}','pending','pending',NOW(),NOW())")->execute([$num,$uid,$vendor,$store,$city,$sub,$tot]); return (int)$p->lastInsertId(); }
function mkItem(PDO $p,$oid,$pid,$vendor,$qty,$unit){ $p->prepare("INSERT INTO order_items (order_id,product_id,vendor_id,product_name,quantity,unit_price,line_total,created_at) VALUES (?,?,?,?,?,?,?,NOW())")->execute([$oid,$pid,$vendor,'S16 item',$qty,$unit,$unit*$qty]); }

cleanup($pdo);
$snap = [];
foreach (['commerce_margin_slabs','commerce_margin_multiplier','commerce_margin_policy'] as $k) { $s=$pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1"); $s->execute([$k]); $snap[$k]=$s->fetchColumn(); }
// restore shared config even if the test fatals mid-way (never leak dirty config → S1 etc.)
register_shutdown_function(function() use ($pdo,$snap){ foreach($snap as $k=>$v){ if($v!==false && $v!==null){ try{ $pdo->prepare("UPDATE app_settings SET setting_value=? WHERE setting_key=?")->execute([$v,$k]); }catch(\Throwable $e){} } } });
setcfg($pdo,'commerce_margin_slabs','{"enabled":true,"bands":[{"up_to":500,"flat":20},{"up_to":5000,"pct":8},{"pct":2}],"by_category":{}}','json');
setcfg($pdo,'commerce_margin_multiplier','1.0','number');
setcfg($pdo,'commerce_margin_policy','{"platform_margin_pct":0,"platform_margin_flat":0,"min_margin_flat":0,"rounding":"none"}','json');

$cityId=(int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if (!$cityId){ $pdo->exec("INSERT INTO cities (name,slug) VALUES ('S16 City','s16t-city')"); $cityId=(int)$pdo->lastInsertId(); }
$u1=mkUser($pdo,'s16t-v1@wtg.test'); $V1=mkVendor($pdo,$u1,'s16t-v1'); $St1=mkStore($pdo,'s16t-s1',$V1,$cityId);
$u2=mkUser($pdo,'s16t-v2@wtg.test'); $V2=mkVendor($pdo,$u2,'s16t-v2'); $St2=mkStore($pdo,'s16t-s2',$V2,$cityId);
$uc=mkUser($pdo,'s16t-cust@wtg.test');

echo "\n== priceFor: base → customer price via S12 slab ==\n";
ok('base 1000 → price 1080',  SellerCatalogue::priceFor($pdo,1000)['customer_price'], 1080.0);
ok('base 300 → price 320 (flat band)', SellerCatalogue::priceFor($pdo,300)['customer_price'], 320.0);

echo "\n== createOffer: draft, priced, base recorded, stocked ==\n";
$off = SellerCatalogue::createOffer($pdo,$V1,['name'=>'S16T Berger 4L','base_price'=>1000,'stock'=>10,'match_key'=>'s16t-mk']);
ok('offer ok',               $off['ok'], true);
ok('status draft',           $off['status'], 'draft');
ok('customer price 1080',    $off['customer_price'], 1080.0);
$pid=(int)$off['product_id'];
ok('seller_base stored 1000',SellerCatalogue::sellerBaseOf($pdo,$pid), 1000.0);
ok('products.price = 1080',  (float)$pdo->query("SELECT price FROM products WHERE id=$pid")->fetchColumn(), 1080.0);
ok('inventory qty 10',       (int)$pdo->query("SELECT quantity FROM inventory WHERE product_id=$pid")->fetchColumn(), 10);

echo "\n== verification gate: draft NOT orderable → approve → orderable ==\n";
ok('draft → inactive_product', OrderabilityResolver::check($pdo,$pid,$cityId)['reason'], 'inactive_product');
ok('approve flips active',     SellerCatalogue::approve($pdo,$pid), true);
ok('active → orderable ok',    OrderabilityResolver::check($pdo,$pid,$cityId)['reason'], 'ok');

echo "\n== updateOffer reprice + setStock ==\n";
$up = SellerCatalogue::updateOffer($pdo,$V1,$pid,['base_price'=>2000]);
ok('reprice base 2000 → 8% → price 2160', $up['customer_price'], 2160.0);
ok('seller_base updated 2000', SellerCatalogue::sellerBaseOf($pdo,$pid), 2000.0);
ok('setStock 25', SellerCatalogue::setStock($pdo,$V1,$pid,25)['stock'], 25);

echo "\n== listMine + seller isolation ==\n";
SellerCatalogue::createOffer($pdo,$V2,['name'=>'S16T Indigo 10L','base_price'=>1500,'stock'=>5,'match_key'=>'s16t-mk2']);
ok('V1 sees 1 offer', count(SellerCatalogue::listMine($pdo,$V1)), 1);
ok('V2 sees 1 offer', count(SellerCatalogue::listMine($pdo,$V2)), 1);
ok('update other\'s offer rejected', SellerCatalogue::updateOffer($pdo,$V2,$pid,['base_price'=>9])['reason'] ?? null, 'not_found_or_not_yours');

echo "\n== persistSellerBase: margin EARNS end-to-end ==\n";
// an order for a fresh offer (base 1000 → price 1080), qty 2
$off2 = SellerCatalogue::createOffer($pdo,$V1,['name'=>'S16T Putty','base_price'=>1000,'stock'=>10,'match_key'=>'s16t-mk3']);
$pid2 = (int)$off2['product_id']; SellerCatalogue::approve($pdo,$pid2);
$O = mkOrder($pdo,'s16t-o1',$uc,$V1,$St1,$cityId,2160,2160);
mkItem($pdo,$O,$pid2,$V1,2,1080);
$res = SellerCatalogue::persistSellerBase($pdo,$O);
ok('one order set',          $res['orders_set'], 1);
ok('order_items.seller_base_price = 1000', (float)$pdo->query("SELECT seller_base_price FROM order_items WHERE order_id=$O")->fetchColumn(), 1000.0);
ok('orders.seller_subtotal = 2000',        (float)$pdo->query("SELECT seller_subtotal FROM orders WHERE id=$O")->fetchColumn(), 2000.0);
$subtotal = (float)$pdo->query("SELECT subtotal FROM orders WHERE id=$O")->fetchColumn();
$sellerSub = (float)$pdo->query("SELECT seller_subtotal FROM orders WHERE id=$O")->fetchColumn();
ok('platform margin = subtotal − seller_subtotal = 160', round($subtotal-$sellerSub,2), 160.0);

echo "\n== no recorded base → seller_subtotal stays NULL (commission back-compat) ==\n";
// a plain product with NO seller_base attribute (legacy/non-catalogue)
$pdo->prepare("INSERT INTO products (vendor_id,store_id,city_id,name,slug,price,status,created_at,updated_at) VALUES (?,?,?,?,?,500,'active',NOW(),NOW())")->execute([$V1,$St1,$cityId,'S16T Plain','s16t-plain']);
$pidPlain=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO inventory (product_id,quantity,reserved,low_stock_alert,track_inventory,allow_backorder,updated_at) VALUES (?,10,0,5,1,0,NOW())")->execute([$pidPlain]);
$On = mkOrder($pdo,'s16t-o2',$uc,$V1,$St1,$cityId,500,500);
mkItem($pdo,$On,$pidPlain,$V1,1,500); // product with no seller_base
SellerCatalogue::persistSellerBase($pdo,$On);
ok('seller_subtotal NULL when no base', $pdo->query("SELECT seller_subtotal FROM orders WHERE id=$On")->fetchColumn(), null);

foreach ($snap as $k=>$v){ if ($v!==false && $v!==null) setcfg($pdo,$k,$v,'json'); }
cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("S16 RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
