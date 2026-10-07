<?php
// ─────────────────────────────────────────────────────────────────────────────
//  S3 PROOF HARNESS — RoutingEngine (deterministic routing + signals).
//  Products have a UNIQUE sku per offer but a shared match_key across stores
//  (the cross-seller equivalence key). Run: php tests/commerce/s3_routing_vectors.php
// ─────────────────────────────────────────────────────────────────────────────
define('SYSTEM_ROOT', dirname(dirname(__DIR__)));
foreach (file(SYSTEM_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2); $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
    if (getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
}
require_once SYSTEM_ROOT . '/core/helpers/Database.php';
require_once SYSTEM_ROOT . '/lib/commerce/RoutingEngine.php';
$pdo = Database::getConnection();

$PASS = 0; $FAIL = 0;
function ok($label, $got, $exp) {
    global $PASS, $FAIL;
    $g = is_array($got) ? json_encode($got) : var_export($got, true);
    $e = is_array($exp) ? json_encode($exp) : var_export($exp, true);
    if ($g === $e) { $PASS++; printf("  PASS  %-44s %s\n", $label, $g); }
    else { $FAIL++; printf("  FAIL  %-44s got=%s exp=%s\n", $label, $g, $e); }
}
function cleanup(PDO $p) {
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $oids = $p->query("SELECT id FROM orders WHERE order_number LIKE 'S3T-%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($oids) { $in = implode(',', array_map('intval', $oids));
        $p->exec("DELETE FROM commerce_routing_signals WHERE order_id IN ($in) OR sub_order_id IN ($in)");
        $p->exec("DELETE FROM order_items WHERE order_id IN ($in)");
        $p->exec("DELETE FROM orders WHERE id IN ($in)"); }
    $p->exec("DELETE i FROM inventory i JOIN products pr ON pr.id=i.product_id WHERE pr.slug LIKE 's3t-%'");
    $p->exec("DELETE FROM products WHERE slug LIKE 's3t-%'");
    $p->exec("DELETE FROM stores   WHERE slug LIKE 's3t-%'");
    $p->exec("DELETE FROM vendors  WHERE slug LIKE 's3t-%'");
    $p->exec("DELETE FROM users    WHERE email LIKE 's3t-%@wtg.test'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('S3',?, 'vendor_shopping','active',NOW(),NOW())")->execute([$e]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',1,10,NOW(),NOW())")->execute([$uid,'S3 V '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkStore(PDO $p,$slug,$own,$ownerV,$city,$lat,$lng,$radius){ $p->prepare("INSERT INTO stores (ownership,owner_vendor_id,city_id,name,slug,status,lat,lng,service_radius_km,created_at,updated_at) VALUES (?,?,?,?,?,'active',?,?,?,NOW(),NOW())")->execute([$own,$ownerV,$city,'S3 S '.$slug,$slug,$lat,$lng,$radius]); return (int)$p->lastInsertId(); }
// unique sku (= slug), shared match_key across stores
function mkProd(PDO $p,$vendorId,$storeId,$city,$matchKey,$slug,$qty){ $p->prepare("INSERT INTO products (vendor_id,store_id,city_id,name,slug,sku,match_key,price,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,199.00,'active',NOW(),NOW())")->execute([$vendorId,$storeId,$city,'S3 P '.$slug,$slug,$slug,$matchKey]); $pid=(int)$p->lastInsertId(); $p->prepare("INSERT INTO inventory (product_id,quantity,reserved,low_stock_alert,track_inventory,allow_backorder,updated_at) VALUES (?,?,0,5,1,0,NOW())")->execute([$pid,$qty]); return $pid; }
function mkOrder(PDO $p,$num,$uid,$parent,$vendor,$store,$city,$ds){ $ds = $ds ?? 'pending'; $p->prepare("INSERT INTO orders (order_number,parent_order_id,user_id,vendor_id,store_id,city_id,status,payment_status,payment_method,subtotal,total,shipping_address,delivery_status,ledger_status,created_at,updated_at) VALUES (?,?,?,?,?,?, 'pending','unpaid','cod',199.00,199.00,'{}',?,'pending',NOW(),NOW())")->execute([$num,$parent,$uid,$vendor,$store,$city,$ds]); return (int)$p->lastInsertId(); }
function mkItem(PDO $p,$oid,$pid,$vendor,$qty){ $p->prepare("INSERT INTO order_items (order_id,product_id,vendor_id,product_name,quantity,unit_price,line_total,created_at) VALUES (?,?,?,?,?,199.00,199.00,NOW())")->execute([$oid,$pid,$vendor,'S3 item',$qty]); }
function sids($list){ return array_map(fn($r)=>$r['store_id'],$list); }

cleanup($pdo);
$cityId = (int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if (!$cityId) { $pdo->exec("INSERT INTO cities (name,slug) VALUES ('S3 City','s3t-city')"); $cityId=(int)$pdo->lastInsertId(); }
$CLAT=29.2100; $CLNG=79.5100;

$uc=mkUser($pdo,'s3t-cv@wtg.test'); $cv=mkVendor($pdo,$uc,'s3t-cv');
$uo=mkUser($pdo,'s3t-vo@wtg.test'); $vON=mkVendor($pdo,$uo,'s3t-von');
$uf=mkUser($pdo,'s3t-vf@wtg.test'); $vFar=mkVendor($pdo,$uf,'s3t-vfar');

$stCompany=mkStore($pdo,'s3t-company','company',null, $cityId,29.2105,79.5105,null);
$stNear   =mkStore($pdo,'s3t-near',   'vendor', $vON, $cityId,29.2110,79.5110,null);
$stFar50  =mkStore($pdo,'s3t-far50',  'vendor', $vFar,$cityId,29.3000,79.6000,50.0);
$stFar5   =mkStore($pdo,'s3t-far5',   'vendor', $vFar,$cityId,29.3000,79.6000,5.0);
$stX=mkStore($pdo,'s3t-x','vendor',$vON,$cityId,29.2106,79.5106,null);
$stA=mkStore($pdo,'s3t-a','vendor',$vON,$cityId,29.2112,79.5112,null);
$stB=mkStore($pdo,'s3t-b','vendor',$vFar,$cityId,29.2114,79.5114,null);

mkProd($pdo,$cv,$stCompany,$cityId,'MK-R1','s3t-r1-co',10);
mkProd($pdo,$vON,$stNear,$cityId,'MK-R1','s3t-r1-ne',10);
mkProd($pdo,$vFar,$stFar50,$cityId,'MK-R1','s3t-r1-f50',10);
mkProd($pdo,$vON,$stNear,$cityId,'MK-R2','s3t-r2-ne',10);
mkProd($pdo,$vFar,$stFar5,$cityId,'MK-R2','s3t-r2-f5',10);
mkProd($pdo,$vON,$stX,$cityId,'MK-A','s3t-a-x',10); mkProd($pdo,$vON,$stA,$cityId,'MK-A','s3t-a-a',10);
mkProd($pdo,$vON,$stX,$cityId,'MK-B','s3t-b-x',10); mkProd($pdo,$vFar,$stB,$cityId,'MK-B','s3t-b-b',10);
mkProd($pdo,$vON,$stA,$cityId,'MK-C','s3t-c-a',10);
mkProd($pdo,$vFar,$stB,$cityId,'MK-D','s3t-d-b',10);
$pRRnear=mkProd($pdo,$vON,$stNear,$cityId,'MK-RR','s3t-rr-ne',10);
mkProd($pdo,$cv,$stCompany,$cityId,'MK-RR','s3t-rr-co',10);
mkProd($pdo,$vFar,$stFar50,$cityId,'MK-RR','s3t-rr-f50',10);
$pTOnear=mkProd($pdo,$vON,$stNear,$cityId,'MK-TO','s3t-to-ne',10);
mkProd($pdo,$cv,$stCompany,$cityId,'MK-TO','s3t-to-co',10);

echo "\n== eligibility + ranking + range ==\n";
ok('R1 order company→near→far', sids(RoutingEngine::eligibleStores($pdo,'MK-R1',1,$cityId,$CLAT,$CLNG)), [$stCompany,$stNear,$stFar50]);
$r2 = sids(RoutingEngine::eligibleStores($pdo,'MK-R2',1,$cityId,$CLAT,$CLNG));
ok('R2 far (13km, radius 5) excluded', in_array($stFar5,$r2,true), false);
ok('R2 near included', in_array($stNear,$r2,true), true);

echo "\n== single-shop / soft-split / partial ==\n";
$p3 = RoutingEngine::planBasket($pdo,[['match_key'=>'MK-A','qty'=>1],['match_key'=>'MK-B','qty'=>1]],$cityId,$CLAT,$CLNG);
ok('R3 single-shop mode', $p3['mode'], 'single');
ok('R3 single store = stX', array_keys($p3['assignments']), [$stX]);
$p4 = RoutingEngine::planBasket($pdo,[['match_key'=>'MK-C','qty'=>1],['match_key'=>'MK-D','qty'=>1]],$cityId,$CLAT,$CLNG);
ok('R4 soft-split mode', $p4['mode'], 'split');
ok('R4 two stores assigned', count($p4['assignments']), 2);
$p5 = RoutingEngine::planBasket($pdo,[['match_key'=>'MK-A','qty'=>1],['match_key'=>'MK-NONE','qty'=>1]],$cityId,$CLAT,$CLNG);
ok('R5 partial mode', $p5['mode'], 'partial');
ok('R5 one unfulfilled', count($p5['unfulfilled']), 1);

echo "\n== signal capture (onOrderPlaced) ==\n";
$pP=mkOrder($pdo,'S3T-OP-P',$uc,null,null,null,$cityId,null);
$pS=mkOrder($pdo,'S3T-OP-S',$uc,$pP,$vON,$stNear,$cityId,null);
ok('R6 subs offered count', RoutingEngine::onOrderPlaced($pdo,$pP), 1);
ok('R6 sub delivery_status=offered', $pdo->query("SELECT delivery_status FROM orders WHERE id=$pS")->fetchColumn(), 'offered');
ok('R6 offered signal logged', (int)$pdo->query("SELECT COUNT(*) FROM commerce_routing_signals WHERE sub_order_id=$pS AND event='offered'")->fetchColumn(), 1);

echo "\n== reroute on failure (company-first) ==\n";
$rP=mkOrder($pdo,'S3T-RR-P',$uc,null,null,null,$cityId,null);
$rS=mkOrder($pdo,'S3T-RR-S',$uc,$rP,$vON,$stNear,$cityId,'offered');
mkItem($pdo,$rS,$pRRnear,$vON,1);
$rr = RoutingEngine::rerouteOnFailure($pdo,$rS);
ok('R7 rerouted=true', $rr['rerouted'], true);
ok('R7 moved to company store', (int)$pdo->query("SELECT store_id FROM orders WHERE id=$rS")->fetchColumn(), $stCompany);
ok('R7 rerouted signal logged', (int)$pdo->query("SELECT COUNT(*) FROM commerce_routing_signals WHERE sub_order_id=$rS AND event='rerouted'")->fetchColumn(), 1);

echo "\n== timeout auto-expire (SQL NOW(), TZ-safe) ==\n";
$tP=mkOrder($pdo,'S3T-TO-P',$uc,null,null,null,$cityId,null);
$tS=mkOrder($pdo,'S3T-TO-S',$uc,$tP,$vON,$stNear,$cityId,'offered');
mkItem($pdo,$tS,$pTOnear,$vON,1);
$pdo->prepare("INSERT INTO commerce_routing_signals (order_id,sub_order_id,store_id,vendor_id,event,created_at) VALUES (?,?,?,?, 'offered', NOW() - INTERVAL 10 MINUTE)")->execute([$tP,$tS,$stNear,$vON]);
ok('R8 stale offers processed >=1', RoutingEngine::expireStaleOffers($pdo) >= 1, true);
ok('R8 timeout signal logged', (int)$pdo->query("SELECT COUNT(*) FROM commerce_routing_signals WHERE sub_order_id=$tS AND event='timeout'")->fetchColumn(), 1);
ok('R8 rerouted to company', (int)$pdo->query("SELECT store_id FROM orders WHERE id=$tS")->fetchColumn(), $stCompany);

echo "\n== config (defaults, configurable) ==\n";
$cfg = RoutingEngine::config($pdo);
ok('R9 offer_timeout_sec', $cfg['offer_timeout_sec'], 120);
ok('R9 default_radius_km', $cfg['default_radius_km'], 8.0);
ok('R9 max_retries', $cfg['max_retries'], 3);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("S3 RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
