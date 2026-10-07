<?php
// ─────────────────────────────────────────────────────────────────────────────
//  S6 PROOF HARNESS — CommerceDelivery seam over the native DeliveryEngine:
//  handoff enrichment (weight→vehicle, prepaid/COD), pickup/deliver proof+OTP,
//  auto-reassign on accept-timeout. Run: php tests/commerce/s6_delivery_vectors.php
// ─────────────────────────────────────────────────────────────────────────────
define('SYSTEM_ROOT', dirname(dirname(__DIR__)));
foreach (file(SYSTEM_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2); $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
    if (getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
}
require_once SYSTEM_ROOT . '/core/helpers/Database.php';
require_once SYSTEM_ROOT . '/lib/commerce/CommerceDelivery.php';
require_once SYSTEM_ROOT . '/lib/commerce/CommerceEventBus.php';
$pdo = Database::getConnection();

$PASS=0;$FAIL=0;
function ok($l,$g,$e){ global $PASS,$FAIL; $g=is_bool($g)?var_export($g,true):(string)$g; $e=is_bool($e)?var_export($e,true):(string)$e;
  if($g===$e){$PASS++;printf("  PASS  %-44s %s\n",$l,$g);}else{$FAIL++;printf("  FAIL  %-44s got=%s exp=%s\n",$l,$g,$e);} }
function cleanup(PDO $p){
  $p->exec("SET FOREIGN_KEY_CHECKS=0");
  $oids=$p->query("SELECT id FROM orders WHERE order_number LIKE 'S6T-%'")->fetchAll(PDO::FETCH_COLUMN);
  if($oids){$in=implode(',',array_map('intval',$oids));
    $p->exec("DELETE FROM deliveries WHERE order_id IN ($in)");
    $p->exec("DELETE FROM commerce_events WHERE order_id IN ($in)");
    $p->exec("DELETE FROM commerce_order_flags WHERE order_id IN ($in)");
    $p->exec("DELETE FROM wallet_transactions WHERE reference_type='order' AND reference_id IN ($in)");
    $p->exec("DELETE FROM order_items WHERE order_id IN ($in)");
    $p->exec("DELETE FROM orders WHERE id IN ($in)"); }
  $uids=$p->query("SELECT id FROM users WHERE email LIKE 's6t-%@wtg.test'")->fetchAll(PDO::FETCH_COLUMN);
  if($uids){$in=implode(',',array_map('intval',$uids));
    $p->exec("DELETE FROM delivery_riders WHERE rider_id IN ($in)");
    $p->exec("DELETE FROM vendor_wallets WHERE vendor_id IN (SELECT id FROM vendors WHERE slug LIKE 's6t-%')");
    $p->exec("DELETE FROM users WHERE id IN ($in)"); }
  $p->exec("DELETE i FROM inventory i JOIN products pr ON pr.id=i.product_id WHERE pr.slug LIKE 's6t-%'");
  $p->exec("DELETE FROM products WHERE slug LIKE 's6t-%'");
  $p->exec("DELETE FROM stores   WHERE slug LIKE 's6t-%'");
  $p->exec("DELETE FROM vendors  WHERE slug LIKE 's6t-%'");
  $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e,$role='customer'){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('S6',?,?, 'active',NOW(),NOW())")->execute([$e,$role]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',1,10,NOW(),NOW())")->execute([$uid,'S6 V '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkStore(PDO $p,$slug,$city){ $p->prepare("INSERT INTO stores (ownership,city_id,name,slug,status,lat,lng,created_at,updated_at) VALUES ('company',?,?,?,'active',29.21,79.51,NOW(),NOW())")->execute([$city,'S6 S '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkProd(PDO $p,$vendor,$store,$city,$slug,$weight){ $p->prepare("INSERT INTO products (vendor_id,store_id,city_id,name,slug,sku,price,weight,status,created_at,updated_at) VALUES (?,?,?,?,?,?,199.00,?,'active',NOW(),NOW())")->execute([$vendor,$store,$city,'S6 P '.$slug,$slug,$slug,$weight]); return (int)$p->lastInsertId(); }
function mkOrder(PDO $p,$num,$uid,$parent,$vendor,$city,$total,$pm,$sellerSub=null){ $p->prepare("INSERT INTO orders (order_number,parent_order_id,user_id,vendor_id,city_id,status,payment_status,payment_method,subtotal,seller_subtotal,total,shipping_address,delivery_status,ledger_status,created_at,updated_at) VALUES (?,?,?,?,?, 'pending',?,?,?,?,?,'{}','pending','pending',NOW(),NOW())")->execute([$num,$parent,$uid,$vendor,$city,($pm==='online'?'paid':'unpaid'),$pm,$total,$sellerSub,$total]); return (int)$p->lastInsertId(); }
function mkItem(PDO $p,$oid,$pid,$vendor,$qty){ $p->prepare("INSERT INTO order_items (order_id,product_id,vendor_id,product_name,quantity,unit_price,line_total,created_at) VALUES (?,?,?,?,?,199.00,199.00,NOW())")->execute([$oid,$pid,$vendor,'S6 item',$qty]); }
function mkDelivery(PDO $p,$oid,$driver,$status,$city){ $p->prepare("INSERT INTO deliveries (order_id,city_id,status,driver_id,pickup_lat,pickup_lng,drop_lat,drop_lng,assigned_at,accepted_at,assign_attempts,created_at) VALUES (?,?,?,?,29.21,79.51,29.22,79.52, ".($driver?'NOW()':'NULL').", NULL, 0, NOW())")->execute([$oid,$city,$status,$driver]); return (int)$p->lastInsertId(); }
function mkRider(PDO $p,$rid,$city,$lat,$lng){ $p->prepare("INSERT INTO delivery_riders (rider_id,city_id,is_online,lat,lng,last_ping_at,vehicle_type,max_active,active_count,created_at,updated_at) VALUES (?,?,1,?,?,NOW(),'bike',3,0,NOW(),NOW())")->execute([$rid,$city,$lat,$lng]); }
function dcol(PDO $p,$did,$c){ $s=$p->prepare("SELECT $c FROM deliveries WHERE id=?"); $s->execute([$did]); return $s->fetchColumn(); }

cleanup($pdo);
$cityId=(int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if(!$cityId){ $pdo->exec("INSERT INTO cities (name,slug) VALUES ('S6 City','s6t-city')"); $cityId=(int)$pdo->lastInsertId(); }
$cu=mkUser($pdo,'s6t-c@wtg.test'); $vu=mkUser($pdo,'s6t-vu@wtg.test','vendor_shopping'); $vend=mkVendor($pdo,$vu,'s6t-v'); $st=mkStore($pdo,'s6t-store',$cityId);
$pHeavy=mkProd($pdo,$vend,$st,$cityId,'s6t-heavy',10.000); $pLight=mkProd($pdo,$vend,$st,$cityId,'s6t-light',5.000);

echo "\n== handoff enrichment (weight → vehicle, prepaid/COD) ==\n";
$cP=mkOrder($pdo,'S6T-COD-P',$cu,null,null,$cityId,500,'cod'); $cS=mkOrder($pdo,'S6T-COD-S',$cu,$cP,$vend,$cityId,500,'cod',400);
mkItem($pdo,$cS,$pHeavy,$vend,2); mkItem($pdo,$cS,$pLight,$vend,1); // 2*10 + 1*5 = 25kg
$dC=mkDelivery($pdo,$cP,null,'pending',$cityId);
$h=CommerceDelivery::enrichHandoff($pdo,$cP);
ok('D1 weight = 25kg', (string)$h['weight_kg'], '25');
ok('D1 vehicle = e_rickshaw (25kg)', $h['required_vehicle'], 'e_rickshaw');
ok('D1 COD amount = 500', (string)$h['cod_amount'], '500');
ok('D1 is_prepaid = 0', (string)$h['is_prepaid'], '0');
$pP=mkOrder($pdo,'S6T-PRE-P',$cu,null,null,$cityId,300,'online'); $pS=mkOrder($pdo,'S6T-PRE-S',$cu,$pP,$vend,$cityId,300,'online',250);
mkItem($pdo,$pS,$pLight,$vend,1); // 5kg
mkDelivery($pdo,$pP,null,'pending',$cityId);
$h2=CommerceDelivery::enrichHandoff($pdo,$pP);
ok('D1b light → bike (5kg)', $h2['required_vehicle'], 'bike');
ok('D1b prepaid=1, cod=0', $h2['is_prepaid'].'/'.$h2['cod_amount'], '1/0');

echo "\n== pickup proof + delivery OTP ==\n";
$riderA=mkUser($pdo,'s6t-rA@wtg.test','driver'); mkRider($pdo,$riderA,$cityId,29.211,79.511);
$oP=mkOrder($pdo,'S6T-OTP-P',$cu,null,null,$cityId,500,'cod'); $oS=mkOrder($pdo,'S6T-OTP-S',$cu,$oP,$vend,$cityId,500,'cod',400);
$dO=mkDelivery($pdo,$oP,$riderA,'assigned',$cityId);
$pk=CommerceDelivery::pickup($pdo,$dO,$riderA,'photo:pickup.jpg');
ok('D3 pickup status', $pk['status'], 'picked');
ok('D3 pickup proof stored', dcol($pdo,$dO,'pickup_proof'), 'photo:pickup.jpg');
$otp=CommerceDelivery::issueOtp($pdo,$oP);
ok('D2 otp is 4 digits', (bool)preg_match('/^\d{4}$/',(string)$otp), true);
$wrong=CommerceDelivery::deliver($pdo,$dO,$riderA,'0000'==$otp?'1111':'0000','photo:d.jpg');
ok('D2 wrong OTP rejected', $wrong['status'], 'rejected');
ok('D2 still not delivered', dcol($pdo,$dO,'status'), 'picked');
$good=CommerceDelivery::deliver($pdo,$dO,$riderA,(string)$otp,'photo:deliver.jpg');
ok('D2 correct OTP delivers', $good['status'], 'delivered');
ok('D2 delivery proof stored', dcol($pdo,$dO,'delivery_proof'), 'photo:deliver.jpg');

echo "\n== auto-reassign on accept-timeout (detect + free + attempt) ==\n";
// NOTE: the successful re-pick of the NEXT rider runs through DeliveryEngine::assignNearest,
// which uses `FOR UPDATE SKIP LOCKED` — MariaDB >= 10.5 only (S-b finding #1). On this local
// 10.4 the re-pick can't execute, so here we prove the detect/free/attempt orchestration;
// the A->B hop is a staging (>=10.5) verification.
$riderB=mkUser($pdo,'s6t-rB@wtg.test','driver'); mkRider($pdo,$riderB,$cityId,29.212,79.512);
$rP=mkOrder($pdo,'S6T-RA-P',$cu,null,null,$cityId,300,'cod');
$dR=mkDelivery($pdo,$rP,$riderA,'assigned',$cityId);
$pdo->prepare("UPDATE deliveries SET assigned_at = NOW() - INTERVAL 10 MINUTE, accepted_at = NULL WHERE id=?")->execute([$dR]);
$pdo->prepare("UPDATE delivery_riders SET active_count=1 WHERE rider_id=?")->execute([$riderA]);
CommerceDelivery::reassignUnaccepted($pdo);
ok('D4 unaccepted detected → attempt made (0→1)', (string)dcol($pdo,$dR,'assign_attempts'), '1');
ok('D4 freed from timed-out rider A', (int)dcol($pdo,$dR,'driver_id') !== $riderA, true);
ok('D4 rider A capacity released', (string)$pdo->query("SELECT active_count FROM delivery_riders WHERE rider_id=$riderA")->fetchColumn(), '0');

echo "\n== reassign exhausted → no_rider + ops ==\n";
$eP=mkOrder($pdo,'S6T-EX-P',$cu,null,null,$cityId,300,'cod');
$dE=mkDelivery($pdo,$eP,$riderA,'assigned',$cityId);
$pdo->prepare("UPDATE deliveries SET assigned_at = NOW() - INTERVAL 10 MINUTE, accepted_at=NULL, assign_attempts=3 WHERE id=?")->execute([$dE]);
$res2=CommerceDelivery::reassignUnaccepted($pdo);
ok('D5 flagged no_rider', dcol($pdo,$dE,'flag'), 'no_rider');
ok('D5 delivery_failed event', (int)$pdo->query("SELECT COUNT(*) FROM commerce_events WHERE order_id=$eP AND event_type='delivery_failed'")->fetchColumn(), 1);
ok('D5 order needs_ops flag', in_array('needs_ops', CommerceEventBus::activeFlags($pdo,$eP), true), true);

echo "\n== config ==\n";
ok('D6 accept timeout', CommerceDelivery::acceptTimeoutSec($pdo), 120);
ok('D6 max reassign', CommerceDelivery::maxReassign($pdo), 3);
ok('D6 vehicle 80kg → e_rickshaw', CommerceDelivery::requiredVehicle($pdo,80.0), 'e_rickshaw');
ok('D6 vehicle 200kg → loader', CommerceDelivery::requiredVehicle($pdo,200.0), 'loader');

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("S6 RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL>0?1:0);
