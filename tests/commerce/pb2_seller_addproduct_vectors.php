<?php
// ─────────────────────────────────────────────────────────────────────────────
//  PB2 PROOF HARNESS — Seller add-product (on the PB1 model) + edits + guard.
//  Proves: paint-meta (brands/7 categories); my-products grouping + status;
//  parent product is NEVER orderable even after approve (guard, item 8); a pack's
//  stock/price edits after live DON'T re-trigger approval and ARE logged
//  (item 7); seller sees only own products (item 9); + structural wiring of the
//  seller screen + adapter routes.
//  Run: php tests/commerce/pb2_seller_addproduct_vectors.php   (local 10.11)
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
function has(string $h,string $n):bool{ return strpos($h,$n)!==false; }
function get(string $p):string{ return is_file($p)?(string)file_get_contents($p):''; }
function cleanup(PDO $p){
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $vids=$p->query("SELECT id FROM vendors WHERE slug LIKE 'pb2t-%'")->fetchAll(PDO::FETCH_COLUMN);
    if($vids){$in=implode(',',array_map('intval',$vids));
        $pids=$p->query("SELECT id FROM products WHERE vendor_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        if($pids){$pin=implode(',',array_map('intval',$pids));
            $p->exec("DELETE FROM inventory WHERE product_id IN ($pin)");
            $p->exec("DELETE FROM product_variations WHERE product_id IN ($pin)");
            $p->exec("DELETE FROM commerce_offer_changes WHERE product_id IN ($pin)");
            $p->exec("DELETE FROM products WHERE id IN ($pin)"); }
        $p->exec("DELETE FROM commerce_offer_changes WHERE vendor_id IN ($in)");
        $p->exec("DELETE FROM stores WHERE owner_vendor_id IN ($in)");
        $p->exec("DELETE FROM vendors WHERE id IN ($in)"); }
    $p->exec("DELETE FROM users WHERE email LIKE 'pb2t-%@wtg.test'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('PB2',?, 'vendor_shopping','active',NOW(),NOW())")->execute([$e]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',1,10,NOW(),NOW())")->execute([$uid,'PB2 '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkStore(PDO $p,$slug,$v,$city){ $p->prepare("INSERT INTO stores (ownership,owner_vendor_id,city_id,name,slug,status,lat,lng,service_radius_km,created_at,updated_at) VALUES ('vendor',?,?,?,?,'active',29.2,79.5,50,NOW(),NOW())")->execute([$v,$city,'PB2 S '.$slug,$slug]); return (int)$p->lastInsertId(); }
function pcol(PDO $p,$pid,$c){ $s=$p->prepare("SELECT $c FROM products WHERE id=?"); $s->execute([$pid]); return $s->fetchColumn(); }

cleanup($pdo);
$cityId=(int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if(!$cityId){ $pdo->exec("INSERT INTO cities (name,slug) VALUES ('PB2 City','pb2t-city')"); $cityId=(int)$pdo->lastInsertId(); }
$cat=(int)$pdo->query("SELECT id FROM categories WHERE slug='paint-interior-emulsion' LIMIT 1")->fetchColumn();
$u=mkUser($pdo,'pb2t-v@wtg.test'); $V=mkVendor($pdo,$u,'pb2t-v'); $St=mkStore($pdo,'pb2t-s',$V,$cityId);

echo "\n== [A] paint-meta ==\n";
ok('brands non-empty', count(PaintCatalogue::brands($pdo)) > 0, true);
ok('paint categories = 7', count(PaintCatalogue::paintCategories($pdo)), 7);

echo "\n== [B] add product → variant → packs ==\n";
$pr = PaintCatalogue::createProduct($pdo,$V,['brand'=>'Berger','line'=>'Easy Clean','category_id'=>$cat,'photo'=>'/uploads/products/pb2.jpg']);
$parent=(int)$pr['product_id'];
$vr = PaintCatalogue::addVariant($pdo,$V,$parent,['variant_type'=>'ready_shade','label'=>'White','shade_mode'=>'ready']);
$vid=(int)$vr['variation_id'];
$s4 = PaintCatalogue::addPackSku($pdo,$V,$parent,$vid,['pack_size'=>'4','pack_unit'=>'L','base_price'=>1000,'stock'=>10,'weight'=>5.3]);
$sku=(int)$s4['product_id'];
ok('pack SKU created (draft)', $s4['status'] ?? null, 'draft');

echo "\n== [C] GUARD: parent is NEVER orderable, even after approve ==\n";
SellerCatalogue::approve($pdo,$sku);    // approve the SKU
SellerCatalogue::approve($pdo,$parent); // attempt to approve the parent
UnitConfig::resetCache();
ok('parent status still inactive (approve is a no-op)', pcol($pdo,$parent,'status'), 'inactive');
ok('parent NOT orderable', OrderabilityResolver::check($pdo,$parent,$cityId)['orderable'], false);
ok('pack SKU IS orderable (approved)', OrderabilityResolver::check($pdo,$sku,$cityId)['reason'], 'ok');

echo "\n== [D] edit stock/price after live: no re-approval + logged ==\n";
$before = (int)$pdo->query("SELECT COUNT(*) FROM commerce_offer_changes WHERE product_id=$sku")->fetchColumn();
$rs = PaintCatalogue::updatePackStock($pdo,$V,$sku,99);
ok('stock updated', $rs['ok'] ?? false, true);
ok('status still active (no re-approval)', pcol($pdo,$sku,'status'), 'active');
$rp = PaintCatalogue::updatePackPrice($pdo,$V,$sku,1500);
ok('price updated (recomputed)', $rp['ok'] ?? false, true);
ok('new seller_base = 1500', SellerCatalogue::sellerBaseOf($pdo,$sku), 1500.0);
ok('status STILL active after price change', pcol($pdo,$sku,'status'), 'active');
$after = (int)$pdo->query("SELECT COUNT(*) FROM commerce_offer_changes WHERE product_id=$sku")->fetchColumn();
ok('2 changes logged (stock + price)', $after - $before, 2);

echo "\n== [E] my-products grouping + status chips data ==\n";
$mine = PaintCatalogue::myProducts($pdo,$V);
ok('one product grouped', count($mine), 1);
ok('product has its pack SKU', count($mine[0]['skus']), 1);
ok('sku status surfaced', $mine[0]['skus'][0]['status'], 'active');

echo "\n== [F] seller sees ONLY own products ==\n";
$u2=mkUser($pdo,'pb2t-v2@wtg.test'); $V2=mkVendor($pdo,$u2,'pb2t-v2'); mkStore($pdo,'pb2t-s2',$V2,$cityId);
$pr2=PaintCatalogue::createProduct($pdo,$V2,['brand'=>'Asian Paints','line'=>'Royale','category_id'=>$cat]);
ok('vendor1 does not see vendor2 product', count(PaintCatalogue::myProducts($pdo,$V)), 1);
ok('vendor2 sees only its own',            count(PaintCatalogue::myProducts($pdo,$V2)), 1);

echo "\n== [G] structural wiring (seller screen + adapter) ==\n";
$js = get(SYSTEM_ROOT.'/seller/seller.js'); $ad = get(SYSTEM_ROOT.'/heart/api/commerce-seller/index.php');
ok('UI: brand datalist', has($js,'cp-brands') && has($js,'list="cp-brands"'), true);
ok('UI: category select + line + photo upload', has($js,'cp-cat')&&has($js,'cp-line')&&has($js,'catPhoto'), true);
ok('UI: packs table + submit', has($js,'pk-price-')&&has($js,'catAdd'), true);
ok('UI: status chips', has($js,'Waiting for verification')&&has($js,'Live'), true);
ok('UI: inline edit save', has($js,'catSave'), true);
ok('adapter: products + variants + packs routes', has($ad,'/products')&&has($ad,'/variants')&&has($ad,'/packs/'), true);
ok('adapter: paint-meta route', has($ad,'/paint-meta'), true);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("PB2 ADD-PRODUCT RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
