<?php
// ─────────────────────────────────────────────────────────────────────────────
//  PB14 PROOF — pincode / area serviceability.
//  Proves: a customer sees/orders only products deliverable to THEIR pincode,
//  gated SERVER-side via Serviceability (platform city pincodes + per-seller
//  areas, with the single-city "no rows = whole city / radius fallback" default);
//  OrderabilityResolver::check honours the pincode; the "Notify me" demand list;
//  + shop + admin adapter/screen wiring. No pincode given ⇒ unchanged behaviour.
//  Run: php tests/commerce/pb14_serviceability_vectors.php   (local 10.11)
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
require_once SYSTEM_ROOT . '/lib/commerce/Serviceability.php';
require_once SYSTEM_ROOT . '/lib/commerce/OrderabilityResolver.php';
$pdo = Database::getConnection();

$PASS = 0; $FAIL = 0;
function ok($label, $got, $exp = true) {
    global $PASS, $FAIL;
    $g = var_export($got, true); $e = var_export($exp, true);
    if ($g === $e) { $PASS++; printf("  PASS  %-54s %s\n", $label, $g); }
    else { $FAIL++; printf("  FAIL  %-54s got=%s exp=%s\n", $label, $g, $e); }
}
function has(string $h,string $n):bool{ return strpos($h,$n)!==false; }
function get(string $p):string{ return is_file($p)?(string)file_get_contents($p):''; }
function cleanup(PDO $p){
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $sids=$p->query("SELECT id FROM stores WHERE slug LIKE 'pb14t-%'")->fetchAll(PDO::FETCH_COLUMN);
    if($sids){$in=implode(',',array_map('intval',$sids));
        $p->exec("DELETE FROM commerce_serviceability WHERE store_id IN ($in)");
        $pids=$p->query("SELECT id FROM products WHERE store_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        if($pids){$pin=implode(',',array_map('intval',$pids)); $p->exec("DELETE FROM inventory WHERE product_id IN ($pin)"); $p->exec("DELETE FROM products WHERE id IN ($pin)");}
        $p->exec("DELETE FROM stores WHERE id IN ($in)"); }
    $cids=$p->query("SELECT id FROM cities WHERE slug LIKE 'pb14t-%'")->fetchAll(PDO::FETCH_COLUMN);
    if($cids){$in=implode(',',array_map('intval',$cids)); $p->exec("DELETE FROM commerce_serviceability WHERE city_id IN ($in)"); $p->exec("DELETE FROM commerce_service_requests WHERE city_id IN ($in)"); $p->exec("DELETE FROM cities WHERE id IN ($in)"); }
    $p->exec("DELETE FROM vendors WHERE slug LIKE 'pb14t-%'");
    $p->exec("DELETE FROM users   WHERE email LIKE 'pb14t-%@wtg.test'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkStore(PDO $p,$slug,$own,$ownerV,$city){ $p->prepare("INSERT INTO stores (ownership,owner_vendor_id,city_id,name,slug,status,lat,lng,service_radius_km,created_at,updated_at) VALUES (?,?,?,?,?,'active',29.2,79.5,NULL,NOW(),NOW())")->execute([$own,$ownerV,$city,'PB14 '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkProd(PDO $p,$vendorId,$storeId,$city,$slug){ $p->prepare("INSERT INTO products (vendor_id,store_id,city_id,name,slug,sku,price,status,created_at,updated_at) VALUES (?,?,?,?,?,?,199.00,'active',NOW(),NOW())")->execute([$vendorId,$storeId,$city,'PB14 P '.$slug,$slug,$slug]); $pid=(int)$p->lastInsertId(); $p->prepare("INSERT INTO inventory (product_id,quantity,reserved,low_stock_alert,track_inventory,allow_backorder,updated_at) VALUES (?,10,0,2,1,0,NOW())")->execute([$pid]); return $pid; }

cleanup($pdo);
$pdo->exec("INSERT INTO cities (name,slug,is_active) VALUES ('PB14 City','pb14t-city',1)"); $cityA=(int)$pdo->lastInsertId();
$uv=null; $pdo->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('PB14',?, 'vendor_shopping','active',NOW(),NOW())")->execute(['pb14t-v@wtg.test']); $uv=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',1,10,NOW(),NOW())")->execute([$uv,'PB14 V','pb14t-v']); $vON=(int)$pdo->lastInsertId();
$stCo=mkStore($pdo,'pb14t-co','company',null,$cityA);
$stV =mkStore($pdo,'pb14t-v',  'vendor',$vON,$cityA);
$pCo=mkProd($pdo,$vON,$stCo,$cityA,'pb14t-co-1'); // vendor_id is a non-null FK; company store skips the seller ON/BUSY gate
$pV =mkProd($pdo,$vON,$stV,$cityA,'pb14t-v-1');
$IN='246001'; $INB='246010'; $OUT='110001';

echo "\n== [A] normalize + no-config fallbacks ==\n";
ok('normalize 6-digit', Serviceability::normalizePincode(' 246001 '), '246001');
ok('normalize junk → empty', Serviceability::normalizePincode('abc12'), '');
ok('no pincode → ok', Serviceability::check($pdo,$stCo,$cityA,'')['ok'], true);
ok('no city rows → city served whole', Serviceability::cityServes($pdo,$cityA,$IN), true);
ok('no seller rows → seller served (radius fallback)', Serviceability::sellerServes($pdo,$stV,$IN), true);

echo "\n== [B] platform (city) pincode gate ==\n";
Serviceability::addArea($pdo,'city',$cityA,null,$IN);
Serviceability::addArea($pdo,'city',$cityA,null,$INB);
ok('listed pincode served', Serviceability::cityServes($pdo,$cityA,$IN), true);
ok('unlisted pincode NOT served', Serviceability::cityServes($pdo,$cityA,$OUT), false);
ok('check out-of-area → area_not_served', Serviceability::check($pdo,$stCo,$cityA,$OUT)['reason'], 'area_not_served');
ok('check in-area → ok', Serviceability::check($pdo,$stCo,$cityA,$IN)['reason'], 'ok');

echo "\n== [C] seller delivery area ==\n";
Serviceability::addArea($pdo,'seller',null,$stV,$INB); // storeV delivers to 246010 only
ok('seller serves its pincode', Serviceability::sellerServes($pdo,$stV,$INB), true);
ok('seller NOT in another served pincode', Serviceability::sellerServes($pdo,$stV,$IN), false);
ok('check seller out-of-its-area → seller_not_in_area', Serviceability::check($pdo,$stV,$cityA,$IN)['reason'], 'seller_not_in_area');
ok('company store (no list) still served at 246001', Serviceability::check($pdo,$stCo,$cityA,$IN)['reason'], 'ok');

echo "\n== [D] filterStoreIds (browse list) ==\n";
ok('246001 → only company store', Serviceability::filterStoreIds($pdo,[$stCo,$stV],$cityA,$IN), [$stCo]);
sort_assert('246010 → both stores', Serviceability::filterStoreIds($pdo,[$stCo,$stV],$cityA,$INB), [$stCo,$stV]);
ok('out-of-area pincode → no stores', Serviceability::filterStoreIds($pdo,[$stCo,$stV],$cityA,$OUT), []);
ok('no pincode → unchanged list', Serviceability::filterStoreIds($pdo,[$stCo,$stV],$cityA,''), [$stCo,$stV]);

echo "\n== [E] OrderabilityResolver honours the pincode ==\n";
ok('company product orderable in-area', OrderabilityResolver::check($pdo,$pCo,$cityA,$IN)['orderable'], true);
ok('company product blocked out-of-area', OrderabilityResolver::check($pdo,$pCo,$cityA,$OUT)['orderable'], false);
ok('out-of-area reason', OrderabilityResolver::check($pdo,$pCo,$cityA,$OUT)['reason'], 'area_not_served');
ok('seller product blocked outside its area', OrderabilityResolver::check($pdo,$pV,$cityA,$IN)['reason'], 'seller_not_in_area');
ok('seller product ok in its area', OrderabilityResolver::check($pdo,$pV,$cityA,$INB)['orderable'], true);
ok('NO pincode → unchanged (orderable)', OrderabilityResolver::check($pdo,$pCo,$cityA)['orderable'], true);

echo "\n== [F] Notify-me demand list ==\n";
ok('bad pincode rejected', Serviceability::saveServiceRequest($pdo,'12','9998887776')['ok'], false);
ok('bad phone rejected',   Serviceability::saveServiceRequest($pdo,$OUT,'12')['ok'], false);
Serviceability::saveServiceRequest($pdo,$OUT,'9998887776',$cityA);
Serviceability::saveServiceRequest($pdo,$OUT,'9995550000',$cityA);
$dem = Serviceability::demandList($pdo);
$row = null; foreach($dem as $d){ if($d['pincode']===$OUT){ $row=$d; break; } }
ok('demand lists the requested pincode', $row !== null, true);
ok('demand counts 2 requests', (int)($row['requests']??0) >= 2, true);
ok('demand counts 2 distinct people', (int)($row['people']??0) >= 2, true);

echo "\n== [G] shop + admin wiring ==\n";
$shop = get(SYSTEM_ROOT.'/heart/api/shop/index.php');
$adm  = get(SYSTEM_ROOT.'/heart/api/commerce-admin/index.php');
$scr  = get(SYSTEM_ROOT.'/admin/commerce-serviceability.html');
$sh   = get(SYSTEM_ROOT.'/admin/shared/shell.js');
ok('shop: public serviceability + notify routes', has($shop,"/api/shop/serviceability")&&has($shop,"/api/shop/service-request"), true);
ok('shop: lists filter by pincode', has($shop,'Serviceability::filterStoreIds')&&has($shop,'$shopPincode'), true);
ok('shop: checkout gates by delivery pincode', has($shop,'pincodeFromAddress')&&has($shop,'$coPincode'), true);
ok('admin: serviceability + demand routes', has($adm,'/commerce-admin/serviceability')&&has($adm,'/commerce-admin/service-requests'), true);
ok('admin screen calls the routes', has($scr,'/commerce-admin/serviceability')&&has($scr,'service-requests'), true);
ok('admin nav shows Serviceability', has($sh,'commerce-serviceability.html'), true);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("PB14 SERVICEABILITY RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);

// order-insensitive list compare
function sort_assert($label,$got,$exp){ $g=array_map('intval',(array)$got); $e=array_map('intval',(array)$exp); sort($g); sort($e); ok($label,$g,$e); }
