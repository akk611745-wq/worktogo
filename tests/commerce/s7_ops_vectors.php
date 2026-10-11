<?php
// ─────────────────────────────────────────────────────────────────────────────
//  S7 PROOF HARNESS — OpsConsole: exception tickets, human-decision, overrides,
//  order-tied comms, owner view. Run: php tests/commerce/s7_ops_vectors.php
// ─────────────────────────────────────────────────────────────────────────────
define('SYSTEM_ROOT', dirname(dirname(__DIR__)));
foreach (file(SYSTEM_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2); $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
    if (getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
}
require_once SYSTEM_ROOT . '/core/helpers/Database.php';
require_once SYSTEM_ROOT . '/lib/commerce/OpsConsole.php';
require_once SYSTEM_ROOT . '/lib/commerce/CommerceEventBus.php';
require_once SYSTEM_ROOT . '/lib/commerce/CommerceDelivery.php';
$pdo = Database::getConnection();

$PASS=0;$FAIL=0;
function ok($l,$g,$e){ global $PASS,$FAIL; $g=is_bool($g)?var_export($g,true):(string)$g; $e=is_bool($e)?var_export($e,true):(string)$e;
  if($g===$e){$PASS++;printf("  PASS  %-46s %s\n",$l,$g);}else{$FAIL++;printf("  FAIL  %-46s got=%s exp=%s\n",$l,$g,$e);} }
function inQueue($q,$oid,$flag){ foreach($q as $r){ if((int)$r['order_id']===$oid && $r['flag']===$flag) return $r; } return null; }
function cleanup(PDO $p){
  $p->exec("SET FOREIGN_KEY_CHECKS=0");
  $oids=$p->query("SELECT id FROM orders WHERE order_number LIKE 'S7T-%'")->fetchAll(PDO::FETCH_COLUMN);
  if($oids){$in=implode(',',array_map('intval',$oids));
    foreach(['commerce_order_flags','commerce_events','commerce_messages'] as $t) $p->exec("DELETE FROM $t WHERE order_id IN ($in)");
    $p->exec("DELETE FROM deliveries WHERE order_id IN ($in)");
    $p->exec("DELETE FROM wallet_transactions WHERE reference_type='order' AND reference_id IN ($in)");
    $p->exec("DELETE FROM order_items WHERE order_id IN ($in)");
    $p->exec("DELETE FROM orders WHERE id IN ($in)"); }
  $uids=$p->query("SELECT id FROM users WHERE email LIKE 's7t-%@wtg.test'")->fetchAll(PDO::FETCH_COLUMN);
  if($uids){$in=implode(',',array_map('intval',$uids)); $p->exec("DELETE FROM delivery_riders WHERE rider_id IN ($in)"); $p->exec("DELETE FROM notifications WHERE user_id IN ($in)"); $p->exec("DELETE FROM users WHERE id IN ($in)"); }
  $p->exec("DELETE i FROM inventory i JOIN products pr ON pr.id=i.product_id WHERE pr.slug LIKE 's7t-%'");
  $p->exec("DELETE FROM products WHERE slug LIKE 's7t-%'");
  $p->exec("DELETE FROM stores   WHERE slug LIKE 's7t-%'");
  $p->exec("DELETE FROM vendors  WHERE slug LIKE 's7t-%'");
  $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e,$r='customer'){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('S7',?,?, 'active',NOW(),NOW())")->execute([$e,$r]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',1,10,NOW(),NOW())")->execute([$uid,'S7 V '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkStore(PDO $p,$slug,$city){ $p->prepare("INSERT INTO stores (ownership,city_id,name,slug,status,lat,lng,created_at,updated_at) VALUES ('company',?,?,?,'active',29.21,79.51,NOW(),NOW())")->execute([$city,'S7 S '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkProd(PDO $p,$v,$s,$c,$slug){ $p->prepare("INSERT INTO products (vendor_id,store_id,city_id,name,slug,sku,price,status,created_at,updated_at) VALUES (?,?,?,?,?,?,199,'active',NOW(),NOW())")->execute([$v,$s,$c,'S7 P '.$slug,$slug,$slug]); $pid=(int)$p->lastInsertId(); $p->prepare("INSERT INTO inventory (product_id,quantity,reserved,low_stock_alert,track_inventory,allow_backorder,updated_at) VALUES (?,20,0,5,1,0,NOW())")->execute([$pid]); return $pid; }
function mkOrder(PDO $p,$num,$uid,$parent,$vendor,$store,$city,$total,$status='pending'){ $p->prepare("INSERT INTO orders (order_number,parent_order_id,user_id,vendor_id,store_id,city_id,status,payment_status,payment_method,subtotal,total,shipping_address,delivery_status,ledger_status,created_at,updated_at) VALUES (?,?,?,?,?,?,?, 'unpaid','cod',?,?,'{}','pending','pending',NOW(),NOW())")->execute([$num,$parent,$uid,$vendor,$store,$city,$status,$total,$total]); return (int)$p->lastInsertId(); }
function mkItem(PDO $p,$oid,$pid,$vendor,$qty){ $p->prepare("INSERT INTO order_items (order_id,product_id,vendor_id,product_name,quantity,unit_price,line_total,created_at) VALUES (?,?,?,?,?,199,199,NOW())")->execute([$oid,$pid,$vendor,'S7 item',$qty]); }
function reserve(PDO $p,$pid,$q){ $p->prepare("UPDATE inventory SET reserved=reserved+? WHERE product_id=?")->execute([$q,$pid]); }
function mkDelivery(PDO $p,$oid,$driver,$city){ $p->prepare("INSERT INTO deliveries (order_id,city_id,status,driver_id,pickup_lat,pickup_lng,assigned_at,assign_attempts,created_at) VALUES (?,?, 'assigned',?,29.21,79.51,NOW(),0,NOW())")->execute([$oid,$city,$driver]); }
function mkRider(PDO $p,$rid,$city,$lat,$lng){ $p->prepare("INSERT INTO delivery_riders (rider_id,city_id,is_online,lat,lng,last_ping_at,vehicle_type,max_active,active_count,created_at,updated_at) VALUES (?,?,1,?,?,NOW(),'bike',3,1,NOW(),NOW())")->execute([$rid,$city,$lat,$lng]); }
function evCount(PDO $p,$oid,$t){ $s=$p->prepare("SELECT COUNT(*) FROM commerce_events WHERE order_id=? AND event_type=?"); $s->execute([$oid,$t]); return (int)$s->fetchColumn(); }
function ocol(PDO $p,$oid,$c){ $s=$p->prepare("SELECT $c FROM orders WHERE id=?"); $s->execute([$oid]); return $s->fetchColumn(); }

cleanup($pdo);
$cityId=(int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if(!$cityId){ $pdo->exec("INSERT INTO cities (name,slug) VALUES ('S7 City','s7t-city')"); $cityId=(int)$pdo->lastInsertId(); }
$cust=mkUser($pdo,'s7t-c@wtg.test'); $ops=mkUser($pdo,'s7t-ops@wtg.test','admin');
$vu=mkUser($pdo,'s7t-vu@wtg.test','vendor_shopping'); $vend=mkVendor($pdo,$vu,'s7t-v'); $st=mkStore($pdo,'s7t-s',$cityId);

echo "\n== exception queue (tickets from flags) ==\n";
$o1=mkOrder($pdo,'S7T-EX',$cust,null,null,null,$cityId,300);
CommerceEventBus::emit($pdo,'order_timeout',['order_id'=>$o1,'reason'=>'seller late']); // → needs_ops
$t1=inQueue(OpsConsole::exceptionQueue($pdo),$o1,'needs_ops');
ok('T1 ticket present (needs_ops)', $t1!==null, true);
ok('T1 not yet SLA-breached', $t1 ? $t1['sla_breached'] : true, false);

echo "\n== claim / escalate / resolve ==\n";
ok('T2 claim sets owner', OpsConsole::claim($pdo,$o1,'needs_ops',$ops), true);
$t1b=inQueue(OpsConsole::exceptionQueue($pdo),$o1,'needs_ops');
ok('T2 owner reflected', (int)$t1b['owner_user_id'], $ops);
ok('T3 escalate ok', OpsConsole::escalate($pdo,$o1,'needs_ops',$ops,'no response'), true);
ok('T3 escalated event', evCount($pdo,$o1,'ticket_escalated'), 1);
ok('T4 resolve ok', OpsConsole::resolve($pdo,$o1,'needs_ops',$ops,'fixed'), true);
ok('T4 ticket gone from queue', inQueue(OpsConsole::exceptionQueue($pdo),$o1,'needs_ops')===null, true);
ok('T4 resolved event', evCount($pdo,$o1,'ticket_resolved'), 1);

echo "\n== human-decision: approve / reject hold ==\n";
$hi=mkOrder($pdo,'S7T-HI',$cust,null,null,null,$cityId,6000); mkOrder($pdo,'S7T-HI-S',$cust,$hi,$vend,$st,$cityId,6000);
CommerceEventBus::emit($pdo,'order_created',['order_id'=>$hi]); // total 6000 ≥ 5000 → high_value_hold
ok('T5 hold raised', in_array('high_value_hold',CommerceEventBus::activeFlags($pdo,$hi),true), true);
ok('T5 approveHold ok', OpsConsole::approveHold($pdo,$hi,$ops), true);
ok('T5 hold cleared', in_array('high_value_hold',CommerceEventBus::activeFlags($pdo,$hi),true), false);
ok('T5 order_approved event', evCount($pdo,$hi,'order_approved'), 1);

echo "\n== override: cancel (reuses OrderService::cancel, releases stock) ==\n";
$cp=mkProd($pdo,$vend,$st,$cityId,'s7t-p');
$co=mkOrder($pdo,'S7T-CAN',$cust,null,null,null,$cityId,398,'pending'); $cs=mkOrder($pdo,'S7T-CAN-S',$cust,$co,$vend,$st,$cityId,398,'pending');
mkItem($pdo,$cs,$cp,$vend,3); reserve($pdo,$cp,3);
$rescan=OpsConsole::cancelOrder($pdo,$co,$ops,'customer request');
ok('T7 cancel ok', !empty($rescan['cancelled']), true);
ok('T7 order cancelled', ocol($pdo,$co,'status'), 'cancelled');
ok('T7 reserved released to 0', (string)$pdo->query("SELECT reserved FROM inventory WHERE product_id=$cp")->fetchColumn(), '0');
ok('T7 cancelled event', evCount($pdo,$co,'cancelled'), 1);

echo "\n== override: reassign rider (detach on 10.4; re-pick ≥10.5) ==\n";
$rA=mkUser($pdo,'s7t-rA@wtg.test','delivery'); mkRider($pdo,$rA,$cityId,29.211,79.511);
$ro=mkOrder($pdo,'S7T-RA',$cust,null,null,null,$cityId,300,'confirmed'); mkDelivery($pdo,$ro,$rA,$cityId);
$rr=OpsConsole::reassignRider($pdo,$ro,null);
ok('T6 reassign invoked (freed/attempted)', (int)$pdo->query("SELECT COALESCE(driver_id,0) FROM deliveries WHERE order_id=$ro")->fetchColumn() !== $rA, true);
ok('T6 rider_reassigned event', evCount($pdo,$ro,'rider_reassigned'), 1);

echo "\n== order-tied comms ==\n";
OpsConsole::postMessage($pdo,$o1,'customer',$cust,'Where is my order?','ops');
OpsConsole::postMessage($pdo,$o1,'ops',$ops,'On the way, arriving soon.','customer');
$th=OpsConsole::thread($pdo,$o1);
ok('T8 two messages in thread', count($th), 2);
ok('T8 thread order (customer first)', $th[0]['from_type'], 'customer');

echo "\n== owner view + SLA ==\n";
$sum=OpsConsole::ownerSummary($pdo);
ok('T9 ownerSummary has counts', isset($sum['open_tickets']) && isset($sum['orders_today']), true);
$sp=mkOrder($pdo,'S7T-SLA',$cust,null,null,null,$cityId,300);
CommerceEventBus::emit($pdo,'order_timeout',['order_id'=>$sp]);
$pdo->prepare("UPDATE commerce_order_flags SET created_at = NOW() - INTERVAL 20 MINUTE WHERE order_id=? AND flag='needs_ops'")->execute([$sp]);
$ts=inQueue(OpsConsole::exceptionQueue($pdo),$sp,'needs_ops');
ok('T10 SLA breached (20min > 15min)', $ts ? $ts['sla_breached'] : false, true);
ok('T10 sla config', OpsConsole::ticketSlaSec($pdo), 900);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("S7 RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL>0?1:0);
