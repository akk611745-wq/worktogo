<?php
// ─────────────────────────────────────────────────────────────────────────────
//  S4 PROOF HARNESS — CommerceEventBus (timeline / flags / notifications) +
//  RoutingEngine → bus integration. Run: php tests/commerce/s4_events_vectors.php
// ─────────────────────────────────────────────────────────────────────────────
define('SYSTEM_ROOT', dirname(dirname(__DIR__)));
foreach (file(SYSTEM_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2); $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
    if (getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
}
require_once SYSTEM_ROOT . '/core/helpers/Database.php';
require_once SYSTEM_ROOT . '/lib/commerce/CommerceEventBus.php';
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
    $uids = $p->query("SELECT id FROM users WHERE email LIKE 's4t-%@wtg.test'")->fetchAll(PDO::FETCH_COLUMN);
    if ($uids) { $in = implode(',', array_map('intval', $uids)); $p->exec("DELETE FROM notifications WHERE user_id IN ($in)"); }
    $oids = $p->query("SELECT id FROM orders WHERE order_number LIKE 'S4T-%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($oids) { $in = implode(',', array_map('intval', $oids));
        $p->exec("DELETE FROM commerce_events WHERE order_id IN ($in)");
        $p->exec("DELETE FROM commerce_order_flags WHERE order_id IN ($in)");
        $p->exec("DELETE FROM commerce_routing_signals WHERE order_id IN ($in) OR sub_order_id IN ($in)");
        $p->exec("DELETE FROM order_items WHERE order_id IN ($in)");
        $p->exec("DELETE FROM orders WHERE id IN ($in)"); }
    $p->exec("DELETE i FROM inventory i JOIN products pr ON pr.id=i.product_id WHERE pr.slug LIKE 's4t-%'");
    $p->exec("DELETE FROM products WHERE slug LIKE 's4t-%'");
    $p->exec("DELETE FROM stores   WHERE slug LIKE 's4t-%'");
    $p->exec("DELETE FROM vendors  WHERE slug LIKE 's4t-%'");
    $p->exec("DELETE FROM users    WHERE email LIKE 's4t-%@wtg.test'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e,$role='customer'){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('S4',?,?, 'active',NOW(),NOW())")->execute([$e,$role]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',1,10,NOW(),NOW())")->execute([$uid,'S4 V '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkStore(PDO $p,$slug,$own,$ownerV,$city){ $p->prepare("INSERT INTO stores (ownership,owner_vendor_id,city_id,name,slug,status,created_at,updated_at) VALUES (?,?,?,?,?,'active',NOW(),NOW())")->execute([$own,$ownerV,$city,'S4 S '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkProd(PDO $p,$vendorId,$storeId,$city,$mk,$slug,$qty){ $p->prepare("INSERT INTO products (vendor_id,store_id,city_id,name,slug,sku,match_key,price,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,199.00,'active',NOW(),NOW())")->execute([$vendorId,$storeId,$city,'S4 P '.$slug,$slug,$slug,$mk]); $pid=(int)$p->lastInsertId(); $p->prepare("INSERT INTO inventory (product_id,quantity,reserved,low_stock_alert,track_inventory,allow_backorder,updated_at) VALUES (?,?,0,5,1,0,NOW())")->execute([$pid,$qty]); return $pid; }
function mkOrder(PDO $p,$num,$uid,$parent,$vendor,$store,$city,$total,$ds='pending'){ $p->prepare("INSERT INTO orders (order_number,parent_order_id,user_id,vendor_id,store_id,city_id,status,payment_status,payment_method,subtotal,total,shipping_address,delivery_status,ledger_status,created_at,updated_at) VALUES (?,?,?,?,?,?, 'pending','unpaid','cod',?,?,'{}',?,'pending',NOW(),NOW())")->execute([$num,$parent,$uid,$vendor,$store,$city,$total,$total,$ds]); return (int)$p->lastInsertId(); }
function mkItem(PDO $p,$oid,$pid,$vendor,$qty){ $p->prepare("INSERT INTO order_items (order_id,product_id,vendor_id,product_name,quantity,unit_price,line_total,created_at) VALUES (?,?,?,?,?,199.00,199.00,NOW())")->execute([$oid,$pid,$vendor,'S4 item',$qty]); }
function nCount(PDO $p,$uid,$type){ $s=$p->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND type=?"); $s->execute([$uid,$type]); return (int)$s->fetchColumn(); }
function evCount(PDO $p,$oid,$type){ $s=$p->prepare("SELECT COUNT(*) FROM commerce_events WHERE order_id=? AND event_type=?"); $s->execute([$oid,$type]); return (int)$s->fetchColumn(); }

cleanup($pdo);
$cityId = (int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if (!$cityId) { $pdo->exec("INSERT INTO cities (name,slug) VALUES ('S4 City','s4t-city')"); $cityId=(int)$pdo->lastInsertId(); }
$cust = mkUser($pdo,'s4t-cust@wtg.test','customer');
$vu   = mkUser($pdo,'s4t-vu@wtg.test','vendor_shopping'); $vend = mkVendor($pdo,$vu,'s4t-v');
$stV  = mkStore($pdo,'s4t-store','vendor',$vend,$cityId);

echo "\n== order_created: timeline + notifications + high-value hold ==\n";
$hiP = mkOrder($pdo,'S4T-HI-P',$cust,null,null,null,$cityId,6000);  mkOrder($pdo,'S4T-HI-S',$cust,$hiP,$vend,$stV,$cityId,6000);
CommerceEventBus::emit($pdo,'order_created',['order_id'=>$hiP,'actor_type'=>'customer','actor_id'=>$cust,'to_state'=>'pending']);
ok('EV1 timeline has order_created', evCount($pdo,$hiP,'order_created'), 1);
ok('EV1 customer notified',   nCount($pdo,$cust,'order_created'), 1);
ok('EV1 seller notified',     nCount($pdo,$vu,'order_created'), 1);
ok('EV1 high_value_hold flag', in_array('high_value_hold', CommerceEventBus::activeFlags($pdo,$hiP), true), true);

$loP = mkOrder($pdo,'S4T-LO-P',$cust,null,null,null,$cityId,200); mkOrder($pdo,'S4T-LO-S',$cust,$loP,$vend,$stV,$cityId,200);
CommerceEventBus::emit($pdo,'order_created',['order_id'=>$loP,'to_state'=>'pending']);
ok('EV2 low-value: no hold flag', in_array('high_value_hold', CommerceEventBus::activeFlags($pdo,$loP), true), false);

echo "\n== timeline order ==\n";
CommerceEventBus::emit($pdo,'order_accepted',['order_id'=>$loP,'to_state'=>'confirmed']);
CommerceEventBus::emit($pdo,'out_for_delivery',['order_id'=>$loP,'to_state'=>'shipped']);
$tl = array_column(CommerceEventBus::timeline($pdo,$loP),'event_type');
ok('EV3 timeline chronological', $tl, ['order_created','order_accepted','out_for_delivery']);

echo "\n== audience targeting ==\n";
$accP = mkOrder($pdo,'S4T-AC-P',$cust,null,null,null,$cityId,300); mkOrder($pdo,'S4T-AC-S',$cust,$accP,$vend,$stV,$cityId,300);
CommerceEventBus::emit($pdo,'order_accepted',['order_id'=>$accP,'to_state'=>'confirmed']);
ok('EV5 customer gets order_accepted', nCount($pdo,$cust,'order_accepted') >= 1, true);
ok('EV5 seller NOT notified on accept', nCount($pdo,$vu,'order_accepted'), 0);

echo "\n== flags: raise / clear + payment issue ==\n";
$fP = mkOrder($pdo,'S4T-FL-P',$cust,null,null,null,$cityId,300);
CommerceEventBus::emit($pdo,'order_timeout',['order_id'=>$fP,'reason'=>'test']);
ok('EV4 needs_ops flag active', in_array('needs_ops', CommerceEventBus::activeFlags($pdo,$fP), true), true);
CommerceEventBus::clearFlag($pdo,$fP,'needs_ops');
ok('EV4 needs_ops cleared', in_array('needs_ops', CommerceEventBus::activeFlags($pdo,$fP), true), false);
CommerceEventBus::emit($pdo,'payment_failed',['order_id'=>$fP]);
ok('EV6 payment_issue flag', in_array('payment_issue', CommerceEventBus::activeFlags($pdo,$fP), true), true);
ok('EV6 customer notified payment_failed', nCount($pdo,$cust,'payment_failed'), 1);

echo "\n== RoutingEngine → bus integration (reroute fail raises ops flag) ==\n";
$pSingle = mkProd($pdo,$vend,$stV,$cityId,'MK-S4RR','s4t-rr-only',10); // match_key only at THIS store → no alternative
$iP = mkOrder($pdo,'S4T-RR-P',$cust,null,null,null,$cityId,199);
$iS = mkOrder($pdo,'S4T-RR-S',$cust,$iP,$vend,$stV,$cityId,199,'offered');
mkItem($pdo,$iS,$pSingle,$vend,1);
$rr = RoutingEngine::rerouteOnFailure($pdo,$iS);
ok('EV7 reroute had no alternative', $rr['rerouted'], false);
ok('EV7 reroute_failed event logged', evCount($pdo,$iP,'reroute_failed'), 1);
ok('EV7 needs_ops flag raised via bus', in_array('needs_ops', CommerceEventBus::activeFlags($pdo,$iP), true), true);

echo "\n== config ==\n";
ok('EV8 high-value threshold', CommerceEventBus::highValueThreshold($pdo), 5000.0);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("S4 RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
