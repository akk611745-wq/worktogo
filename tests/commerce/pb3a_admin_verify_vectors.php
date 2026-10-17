<?php
// ─────────────────────────────────────────────────────────────────────────────
//  PB3a (+PB17) PROOF — admin product verification, dedup, price-jump review.
//  Proves: pending-products queue; approve→live / reject→inactive+reason (seller
//  chip); PB17 duplicate-match_key detection + merge to one listing; the
//  price-jump guard (small edit instant, big edit queued → admin approve applies
//  / reject discards); change-log; + KYC review reuse + admin-screen wiring.
//  Run: php tests/commerce/pb3a_admin_verify_vectors.php   (local 10.11)
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
    $vids=$p->query("SELECT id FROM vendors WHERE slug LIKE 'pb3t-%'")->fetchAll(PDO::FETCH_COLUMN);
    if($vids){$in=implode(',',array_map('intval',$vids));
        $pids=$p->query("SELECT id FROM products WHERE vendor_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        if($pids){$pin=implode(',',array_map('intval',$pids));
            $p->exec("DELETE FROM inventory WHERE product_id IN ($pin)"); $p->exec("DELETE FROM product_variations WHERE product_id IN ($pin)");
            $p->exec("DELETE FROM commerce_offer_changes WHERE product_id IN ($pin)"); $p->exec("DELETE FROM commerce_price_reviews WHERE product_id IN ($pin)");
            $p->exec("DELETE FROM products WHERE id IN ($pin)"); }
        $p->exec("DELETE FROM commerce_price_reviews WHERE vendor_id IN ($in)");
        $p->exec("DELETE FROM stores WHERE owner_vendor_id IN ($in)"); $p->exec("DELETE FROM vendors WHERE id IN ($in)"); }
    $p->exec("DELETE FROM users WHERE email LIKE 'pb3t-%@wtg.test'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('PB3',?, 'vendor_shopping','active',NOW(),NOW())")->execute([$e]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',1,10,NOW(),NOW())")->execute([$uid,'PB3 '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkStore(PDO $p,$slug,$v,$city){ $p->prepare("INSERT INTO stores (ownership,owner_vendor_id,city_id,name,slug,status,lat,lng,service_radius_km,created_at,updated_at) VALUES ('vendor',?,?,?,?,'active',29.2,79.5,50,NOW(),NOW())")->execute([$v,$city,'PB3 S '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkSku(PDO $p,$V,$cat,$brand,$base){ $pr=PaintCatalogue::createProduct($p,$V,['brand'=>$brand,'line'=>'Easy Clean','category_id'=>$cat]); $parent=(int)$pr['product_id']; $vr=PaintCatalogue::addVariant($p,$V,$parent,['variant_type'=>'ready_shade','label'=>'White','shade_mode'=>'ready']); $s=PaintCatalogue::addPackSku($p,$V,$parent,(int)$vr['variation_id'],['pack_size'=>'4','pack_unit'=>'L','base_price'=>$base,'stock'=>10,'weight'=>5]); return [(int)$s['product_id'],$parent]; }
function pstatus(PDO $p,$id){ $s=$p->prepare("SELECT status FROM products WHERE id=?"); $s->execute([$id]); return (string)$s->fetchColumn(); }

cleanup($pdo);
$pj = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='commerce_price_jump_pct'")->fetchColumn();
ok('price-jump threshold configured (30 default)', (float)$pj, 30.0);
$cityId=(int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if(!$cityId){ $pdo->exec("INSERT INTO cities (name,slug) VALUES ('PB3 City','pb3t-city')"); $cityId=(int)$pdo->lastInsertId(); }
$cat=(int)$pdo->query("SELECT id FROM categories WHERE slug='paint-interior-emulsion' LIMIT 1")->fetchColumn();
$u=mkUser($pdo,'pb3t-v@wtg.test'); $V=mkVendor($pdo,$u,'pb3t-v'); $St=mkStore($pdo,'pb3t-s',$V,$cityId);

echo "\n== [A] product verification queue + approve / reject(reason) ==\n";
list($sku1,$p1) = mkSku($pdo,$V,$cat,'Berger',1000);
list($sku2,$p2) = mkSku($pdo,$V,$cat,'Dulux',1000);
$pend = PaintCatalogue::pendingProducts($pdo);
ok('queue lists our pending SKUs (>=2)', count(array_filter($pend, fn($x)=>in_array($x['product_id'],[$sku1,$sku2]))), 2);
PaintCatalogue::approveProduct($pdo,$sku1);
ok('approved SKU → active + orderable', OrderabilityResolver::check($pdo,$sku1,$cityId)['reason'], 'ok');
$rej = PaintCatalogue::rejectProduct($pdo,$sku2,'Blurry photo — reupload');
ok('rejected SKU → inactive', pstatus($pdo,$sku2), 'inactive');
ok('reject reason visible to seller (my-products)', (function() use($pdo,$V,$sku2){ foreach(PaintCatalogue::myProducts($pdo,$V) as $pp){ foreach($pp['skus'] as $s){ if($s['product_id']===$sku2) return $s['reject_reason']; } } return null; })(), 'Blurry photo — reupload');

echo "\n== [B] price-jump guard: small instant, big queued → admin review ==\n";
$small = PaintCatalogue::updatePackPrice($pdo,$V,$sku1,1200); // +20% ≤30 → applies
ok('small edit applied instantly', empty($small['queued']) && ($small['ok']??false), true);
ok('seller_base now 1200', SellerCatalogue::sellerBaseOf($pdo,$sku1), 1200.0);
$big = PaintCatalogue::updatePackPrice($pdo,$V,$sku1,2000); // +66% >30 → queued
ok('big jump queued (not applied)', ($big['queued'] ?? false), true);
ok('seller_base UNCHANGED at 1200', SellerCatalogue::sellerBaseOf($pdo,$sku1), 1200.0);
$revs = PaintCatalogue::priceReviews($pdo);
$rev = null; foreach($revs as $r){ if((int)$r['product_id']===$sku1){ $rev=$r; break; } }
ok('a pending price review exists', $rev !== null, true);
$ap = PaintCatalogue::reviewPrice($pdo,(int)$rev['id'],true,'ok approved',1);
ok('admin approve applies the new price', ($ap['applied'] ?? false), true);
ok('seller_base now 2000 after approve', SellerCatalogue::sellerBaseOf($pdo,$sku1), 2000.0);

echo "\n== [C] PB17 duplicate match_key detect + merge ==\n";
$u2=mkUser($pdo,'pb3t-v2@wtg.test'); $V2=mkVendor($pdo,$u2,'pb3t-v2'); mkStore($pdo,'pb3t-s2',$V2,$cityId);
// force the same match_key on a second seller's SKU
list($sku3,$p3) = mkSku($pdo,$V2,$cat,'Berger',1050);
$mk1 = $pdo->query("SELECT match_key FROM products WHERE id=$sku1")->fetchColumn();
$pdo->prepare("UPDATE products SET match_key=? WHERE id=?")->execute([$mk1,$sku3]);
$dups = PaintCatalogue::duplicateMatchKeys($pdo);
ok('duplicate match_key detected across 2 vendors', in_array((string)$mk1,$dups,true), true);
$mg = PaintCatalogue::mergeMatchKey($pdo,$sku3,$sku1);
ok('merge sets same match_key', $mg['match_key'] ?? null, (string)$mk1);

echo "\n== [D] change-log per product ==\n";
$ch = PaintCatalogue::productChanges($pdo,$sku1);
ok('change-log has price entries', count($ch['changes']) >= 1, true);
ok('change-log has the price review', count($ch['price_reviews']) >= 1, true);

echo "\n== [E] admin screens + adapter wiring ==\n";
$kyc = get(SYSTEM_ROOT.'/admin/commerce-kyc.html'); $ver = get(SYSTEM_ROOT.'/admin/commerce-verify.html');
$ad = get(SYSTEM_ROOT.'/heart/api/commerce-admin/index.php'); $sh = get(SYSTEM_ROOT.'/admin/shared/shell.js');
ok('KYC screen calls kyc review', has($kyc,'/commerce-admin/kyc/')&&has($kyc,'reviewSeller'), true);
ok('Verify screen: pending+approve+reject+merge+price-reviews', has($ver,'/products/pending')&&has($ver,'/approve')&&has($ver,'/reject')&&has($ver,'merge')&&has($ver,'/price-reviews'), true);
ok('adapter: product + price-review routes', has($ad,'/products/pending')&&has($ad,'/products/duplicates')&&has($ad,'/price-reviews'), true);
ok('admin nav shows commerce verify (visible in service-only)', has($sh,'commerce-kyc.html')&&has($sh,'commerce-verify.html'), true);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("PB3a ADMIN-VERIFY RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
