<?php
// ─────────────────────────────────────────────────────────────────────────────
//  PB1 PROOF HARNESS — Product → Variant → Pack SKU catalogue model.
//  Proves: a PRODUCT (brand+line, ONE photo) is not orderable; VARIANTs are
//  product_variations rows; each PACK SKU is an orderable products row with its
//  OWN slab-margined price + inventory + WEIGHT, inheriting the product photo;
//  the SKU weight feeds requiredVehicle (closes gap #3 for paint); draft→approve
//  → orderable; seller_base persists for the ledger.
//  Run: php tests/commerce/p8_catalogue_hierarchy_vectors.php   (local 10.11)
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
require_once SYSTEM_ROOT . '/lib/commerce/SellerCatalogue.php';
require_once SYSTEM_ROOT . '/lib/commerce/OrderabilityResolver.php';
require_once SYSTEM_ROOT . '/lib/commerce/CommerceDelivery.php';
require_once SYSTEM_ROOT . '/lib/commerce/UnitConfig.php';
$pdo = Database::getConnection();

$PASS = 0; $FAIL = 0;
function ok($label, $got, $exp = true) {
    global $PASS, $FAIL;
    $g = is_float($got) ? number_format($got, 2, '.', '') : $got;
    $e = is_float($exp) ? number_format($exp, 2, '.', '') : $exp;
    if ($g === $e) { $PASS++; printf("  PASS  %-52s got=%s\n", $label, var_export($g, true)); }
    else { $FAIL++; printf("  FAIL  %-52s got=%s exp=%s\n", $label, var_export($g, true), var_export($e, true)); }
}
function setcfg(PDO $p,$k,$v){ $p->prepare("INSERT INTO app_settings (setting_key,setting_value,value_type,is_public) VALUES (?,?,'text',0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$k,$v]); UnitConfig::resetCache(); }
function cleanup(PDO $p){
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $ids=$p->query("SELECT id FROM products WHERE name LIKE 'P8 %' OR slug LIKE 'p%-p8-%' OR match_key LIKE 'p8t-%'")->fetchAll(PDO::FETCH_COLUMN);
    // identify by vendor marker instead — delete via vendor
    $vids=$p->query("SELECT id FROM vendors WHERE slug LIKE 'p8t-%'")->fetchAll(PDO::FETCH_COLUMN);
    if($vids){$in=implode(',',array_map('intval',$vids));
        $pids=$p->query("SELECT id FROM products WHERE vendor_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        if($pids){$pin=implode(',',array_map('intval',$pids));
            $p->exec("DELETE FROM inventory WHERE product_id IN ($pin)");
            $p->exec("DELETE FROM product_variations WHERE product_id IN ($pin)");
            $p->exec("DELETE FROM order_items WHERE product_id IN ($pin)");
            $p->exec("DELETE FROM products WHERE id IN ($pin)");
        }
        $p->exec("DELETE FROM stores WHERE owner_vendor_id IN ($in)");
        $p->exec("DELETE FROM vendors WHERE id IN ($in)");
    }
    $p->exec("DELETE FROM orders WHERE order_number LIKE 'P8T-%'");
    $p->exec("DELETE FROM users WHERE email LIKE 'p8t-%@wtg.test'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('P8',?, 'vendor_shopping','active',NOW(),NOW())")->execute([$e]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',1,10,NOW(),NOW())")->execute([$uid,'P8 '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkStore(PDO $p,$slug,$v,$city){ $p->prepare("INSERT INTO stores (ownership,owner_vendor_id,city_id,name,slug,status,lat,lng,service_radius_km,created_at,updated_at) VALUES ('vendor',?,?,?,?,'active',29.2,79.5,50,NOW(),NOW())")->execute([$v,$city,'P8 S '.$slug,$slug]); return (int)$p->lastInsertId(); }
function pcol(PDO $p,$pid,$c){ $s=$p->prepare("SELECT $c FROM products WHERE id=?"); $s->execute([$pid]); return $s->fetchColumn(); }

cleanup($pdo);
// deterministic paint-category slab (5% up to 5000) — snapshot/restore
$slabSnap = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='commerce_margin_slabs'")->fetchColumn();
register_shutdown_function(function() use($pdo,$slabSnap){ try{ if($slabSnap!==false&&$slabSnap!==null) $pdo->prepare("UPDATE app_settings SET setting_value=? WHERE setting_key='commerce_margin_slabs'")->execute([$slabSnap]); }catch(\Throwable $e){} });
$catId = (int)$pdo->query("SELECT id FROM categories WHERE slug='paint-interior-emulsion' LIMIT 1")->fetchColumn();
setcfg($pdo,'commerce_margin_slabs', '{"enabled":true,"bands":[{"up_to":500,"flat":20},{"up_to":5000,"pct":8},{"pct":2}],"by_category":{"'.$catId.'":{"bands":[{"up_to":5000,"pct":5},{"pct":3}]}}}');

$cityId=(int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if(!$cityId){ $pdo->exec("INSERT INTO cities (name,slug) VALUES ('P8 City','p8t-city')"); $cityId=(int)$pdo->lastInsertId(); }
$u=mkUser($pdo,'p8t-v@wtg.test'); $V=mkVendor($pdo,$u,'p8t-v'); $St=mkStore($pdo,'p8t-s',$V,$cityId);

echo "\n== [A] PRODUCT (brand+line, one photo) — not orderable ==\n";
$PHOTO = '/uploads/products/p8-berger.jpg';
$pr = PaintCatalogue::createProduct($pdo,$V,['brand'=>'Berger','line'=>'Easy Clean','category_id'=>$catId,'photo'=>$PHOTO]);
ok('createProduct ok', $pr['ok'] ?? false, true);
$parent = (int)$pr['product_id'];
ok('parent status inactive', pcol($pdo,$parent,'status'), 'inactive');
ok('parent carries the one photo', json_decode((string)pcol($pdo,$parent,'images'),true)[0] ?? null, $PHOTO);
ok('parent NOT orderable', OrderabilityResolver::check($pdo,$parent,$cityId)['orderable'], false);

echo "\n== [B] VARIANT (ready shade White) ==\n";
$vr = PaintCatalogue::addVariant($pdo,$V,$parent,['variant_type'=>'ready_shade','label'=>'White','shade_mode'=>'ready']);
ok('addVariant ok', $vr['ok'] ?? false, true);
$variation = (int)$vr['variation_id'];
ok('variation row exists', (int)$pdo->query("SELECT COUNT(*) FROM product_variations WHERE id=$variation AND product_id=$parent")->fetchColumn(), 1);

echo "\n== [C] PACK SKUs (4L/10L/20L) — priced by slab, own stock + WEIGHT, photo inherited ==\n";
$s4  = PaintCatalogue::addPackSku($pdo,$V,$parent,$variation,['pack_size'=>'4','pack_unit'=>'L','base_price'=>1000,'stock'=>10,'weight'=>5.0]);
$s10 = PaintCatalogue::addPackSku($pdo,$V,$parent,$variation,['pack_size'=>'10','pack_unit'=>'L','base_price'=>2200,'stock'=>8,'weight'=>12.0]);
$s20 = PaintCatalogue::addPackSku($pdo,$V,$parent,$variation,['pack_size'=>'20','pack_unit'=>'L','base_price'=>4000,'stock'=>5,'weight'=>24.0]);
ok('4L price = 1000 + 5% = 1050',  (float)$s4['customer_price'], 1050.0);
ok('10L price = 2200 + 5% = 2310', (float)$s10['customer_price'], 2310.0);
ok('20L price = 4000 + 5% = 4200', (float)$s20['customer_price'], 4200.0);
ok('4L weight stored = 5',   (float)pcol($pdo,(int)$s4['product_id'],'weight'), 5.0);
ok('20L weight stored = 24', (float)pcol($pdo,(int)$s20['product_id'],'weight'), 24.0);
ok('4L status draft (verify gate)', pcol($pdo,(int)$s4['product_id'],'status'), 'draft');
ok('SKU inherits the product photo', json_decode((string)pcol($pdo,(int)$s20['product_id'],'images'),true)[0] ?? null, $PHOTO);
ok('SKU seller_base recorded = 4000', SellerCatalogue::sellerBaseOf($pdo,(int)$s20['product_id']), 4000.0);

echo "\n== [D] product tree reads back (1 parent, 1 variant, 3 skus) ==\n";
$tree = PaintCatalogue::productTree($pdo,$V,$parent);
ok('tree variants = 1', count($tree['variants']), 1);
ok('tree skus = 3',     count($tree['skus']), 3);

echo "\n== [E] draft SKU not orderable → approve → orderable (ready = not brand-gated) ==\n";
ok('4L draft NOT orderable', OrderabilityResolver::check($pdo,(int)$s4['product_id'],$cityId)['reason'], 'inactive_product');
SellerCatalogue::approve($pdo,(int)$s4['product_id']);
SellerCatalogue::approve($pdo,(int)$s20['product_id']);
UnitConfig::resetCache();
ok('4L approved → orderable', OrderabilityResolver::check($pdo,(int)$s4['product_id'],$cityId)['reason'], 'ok');

echo "\n== [F] WEIGHT → vehicle (closes gap #3 for paint) ==\n";
ok('requiredVehicle(5kg) = bike',        CommerceDelivery::requiredVehicle($pdo,5.0), 'bike');
ok('requiredVehicle(48kg,2x20L) = e_rickshaw', CommerceDelivery::requiredVehicle($pdo,48.0), 'e_rickshaw');
// enrichHandoff picks up the SKU weight from an order
$uc=mkUser($pdo,'p8t-c@wtg.test');
$P=$pdo->prepare("INSERT INTO orders (order_number,user_id,vendor_id,store_id,city_id,status,payment_status,payment_method,subtotal,delivery_fee,total,shipping_address,delivery_status,ledger_status,created_at,updated_at) VALUES ('P8T-P',?,?,?,?, 'confirmed','unpaid','cod',8400,0,8400,'{}','pending','pending',NOW(),NOW())");
$P->execute([$uc,$V,$St,$cityId]); $parentOrder=(int)$pdo->lastInsertId();
$S=$pdo->prepare("INSERT INTO orders (order_number,parent_order_id,user_id,vendor_id,store_id,city_id,status,payment_status,payment_method,subtotal,delivery_fee,total,shipping_address,delivery_status,ledger_status,created_at,updated_at) VALUES ('P8T-S',?,?,?,?,?, 'confirmed','unpaid','cod',8400,0,8400,'{}','pending','pending',NOW(),NOW())");
$S->execute([$parentOrder,$uc,$V,$St,$cityId]); $sub=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO order_items (order_id,product_id,vendor_id,product_name,quantity,unit_price,line_total,created_at) VALUES (?,?,?,?,2,4200,8400,NOW())")->execute([$sub,(int)$s20['product_id'],$V,'P8 20L']);
$h = CommerceDelivery::enrichHandoff($pdo,$parentOrder);
ok('handoff weight = 48 (2 × 20L×24kg? no: 2×24=48)', (float)$h['weight_kg'], 48.0);
ok('handoff vehicle = e_rickshaw', $h['required_vehicle'], 'e_rickshaw');

echo "\n== [G] seller_base persists to the ledger wiring ==\n";
$psb = SellerCatalogue::persistSellerBase($pdo,$parentOrder);
ok('seller_subtotal set (2 × 4000 = 8000)', (float)$pdo->query("SELECT seller_subtotal FROM orders WHERE id=$sub")->fetchColumn(), 8000.0);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("PB1 CATALOGUE RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
