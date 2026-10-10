<?php
// ─────────────────────────────────────────────────────────────────────────────
//  S15 PROOF HARNESS — SellerConsole + the seller close of the S3 offer loop.
//  Proves: availability ON/OFF/BUSY; offered inbox (opaque customer ref); ACCEPT
//  (offered→accepted, order confirmed, 'accepted' signal) incl. accept-gates-
//  dispatch flag; REJECT + STOCK-OUT → reroute to the next seller (S3) with the
//  right signal; ownership + idempotency guards; earnings read; Customer-ID history
//  delegation. Idempotent (markers s15t-*). Run: php tests/commerce/s15_seller_console_vectors.php
// ─────────────────────────────────────────────────────────────────────────────
define('SYSTEM_ROOT', dirname(dirname(__DIR__)));
foreach (file(SYSTEM_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2); $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
    if (getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
}
require_once SYSTEM_ROOT . '/core/helpers/Database.php';
require_once SYSTEM_ROOT . '/lib/commerce/SellerConsole.php';
$pdo = Database::getConnection();

$PASS = 0; $FAIL = 0;
function ok($label, $got, $exp) {
    global $PASS, $FAIL;
    if ($got === $exp) { $PASS++; printf("  PASS  %-48s got=%s\n", $label, var_export($got, true)); }
    else { $FAIL++; printf("  FAIL  %-48s got=%s exp=%s\n", $label, var_export($got, true), var_export($exp, true)); }
}
function setcfg(PDO $p, $k, $v) { $p->prepare("INSERT INTO app_settings (setting_key,setting_value,value_type,is_public) VALUES (?,?,'boolean',0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$k,$v]); }
function cleanup(PDO $p) {
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $ids = $p->query("SELECT id FROM orders WHERE order_number LIKE 's15t-%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) { $in = implode(',', array_map('intval',$ids)); $p->exec("DELETE FROM commerce_routing_signals WHERE order_id IN ($in) OR sub_order_id IN ($in)"); $p->exec("DELETE FROM order_items WHERE order_id IN ($in)"); }
    $p->exec("DELETE FROM orders WHERE order_number LIKE 's15t-%'");
    $p->exec("DELETE i FROM inventory i JOIN products pr ON pr.id=i.product_id WHERE pr.slug LIKE 's15t-%'");
    $p->exec("DELETE FROM products WHERE slug LIKE 's15t-%'");
    $p->exec("DELETE FROM stores   WHERE slug LIKE 's15t-%'");
    $p->exec("DELETE cci FROM commerce_customer_ids cci JOIN users u ON u.id=cci.user_id WHERE u.email LIKE 's15t-%@wtg.test'");
    $p->exec("DELETE FROM vendors  WHERE slug LIKE 's15t-%'");
    $p->exec("DELETE FROM users    WHERE email LIKE 's15t-%@wtg.test'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('S15',?, 'vendor_shopping','active',NOW(),NOW())")->execute([$e]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',1,10,NOW(),NOW())")->execute([$uid,'S15 '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkStore(PDO $p,$slug,$ownerV,$city,$lat,$lng){ $p->prepare("INSERT INTO stores (ownership,owner_vendor_id,city_id,name,slug,status,lat,lng,service_radius_km,created_at,updated_at) VALUES ('vendor',?,?,?,?,'active',?,?,50,NOW(),NOW())")->execute([$ownerV,$city,'S15 S '.$slug,$slug,$lat,$lng]); return (int)$p->lastInsertId(); }
function mkProd(PDO $p,$vendorId,$storeId,$city,$mk,$slug,$qty){ $p->prepare("INSERT INTO products (vendor_id,store_id,city_id,name,slug,sku,match_key,price,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,199,'active',NOW(),NOW())")->execute([$vendorId,$storeId,$city,'S15 P '.$slug,$slug,$slug,$mk]); $pid=(int)$p->lastInsertId(); $p->prepare("INSERT INTO inventory (product_id,quantity,reserved,low_stock_alert,track_inventory,allow_backorder,updated_at) VALUES (?,?,0,5,1,0,NOW())")->execute([$pid,$qty]); return $pid; }
function mkOrder(PDO $p,$num,$uid,$parent,$vendor,$store,$city,$ds,$status='pending',$total=199){ $ds=$ds ?? 'pending'; $p->prepare("INSERT INTO orders (order_number,parent_order_id,user_id,vendor_id,store_id,city_id,status,payment_status,payment_method,subtotal,total,shipping_address,delivery_status,ledger_status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,'unpaid','cod',?,?,'{}',?,'pending',NOW(),NOW())")->execute([$num,$parent,$uid,$vendor,$store,$city,$status,$total,$total,$ds]); return (int)$p->lastInsertId(); }
function mkItem(PDO $p,$oid,$pid,$vendor,$qty){ $p->prepare("INSERT INTO order_items (order_id,product_id,vendor_id,product_name,quantity,unit_price,line_total,attributes,created_at) VALUES (?,?,?,?,?,199,?, '{\"shade_code\":\"2003\"}',NOW())")->execute([$oid,$pid,$vendor,'S15 item',$qty,199*$qty]); }
function dstatus(PDO $p,$id){ return $p->query("SELECT delivery_status FROM orders WHERE id=$id")->fetchColumn(); }
function ostatus(PDO $p,$id){ return $p->query("SELECT status FROM orders WHERE id=$id")->fetchColumn(); }
function sig(PDO $p,$sub,$ev){ return (int)$p->query("SELECT COUNT(*) FROM commerce_routing_signals WHERE sub_order_id=$sub AND event='$ev'")->fetchColumn(); }

cleanup($pdo);
$acceptSnap = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='commerce_accept_gates_dispatch'")->fetchColumn();

$cityId = (int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if (!$cityId) { $pdo->exec("INSERT INTO cities (name,slug) VALUES ('S15 City','s15t-city')"); $cityId=(int)$pdo->lastInsertId(); }

$u1=mkUser($pdo,'s15t-v1@wtg.test'); $V1=mkVendor($pdo,$u1,'s15t-v1');
$u2=mkUser($pdo,'s15t-v2@wtg.test'); $V2=mkVendor($pdo,$u2,'s15t-v2');
$uc=mkUser($pdo,'s15t-cust@wtg.test');
$S1=mkStore($pdo,'s15t-s1',$V1,$cityId,29.21,79.51);
$S2=mkStore($pdo,'s15t-s2',$V2,$cityId,29.22,79.52);
$pA1=mkProd($pdo,$V1,$S1,$cityId,'s15t-mk','s15t-pa1',10);
$pA2=mkProd($pdo,$V2,$S2,$cityId,'s15t-mk','s15t-pa2',10);

echo "\n== availability ON / OFF / BUSY ==\n";
ok('set online',  SellerConsole::setAvailability($pdo,$V1,'online')['status'],  'online');
ok('set busy',    SellerConsole::setAvailability($pdo,$V1,'busy',15)['status'], 'busy');
ok('set offline', SellerConsole::setAvailability($pdo,$V1,'offline')['status'], 'offline');
ok('bad status rejected', SellerConsole::setAvailability($pdo,$V1,'sleepy')['ok'] ?? null, false);
SellerConsole::setAvailability($pdo,$V1,'online'); SellerConsole::setAvailability($pdo,$V2,'online');

echo "\n== inbox shows offered orders (opaque customer ref, items) ==\n";
$P1=mkOrder($pdo,'s15t-p1',$uc,null,null,null,$cityId,null);
$SUB1=mkOrder($pdo,'s15t-sub1',$uc,$P1,$V1,$S1,$cityId,'offered');
mkItem($pdo,$SUB1,$pA1,$V1,2);
$pdo->prepare("INSERT INTO commerce_routing_signals (order_id,sub_order_id,store_id,vendor_id,event,created_at) VALUES (?,?,?,?, 'offered', NOW() - INTERVAL 30 SECOND)")->execute([$P1,$SUB1,$S1,$V1]);
$inbox=SellerConsole::inbox($pdo,$V1);
ok('inbox count 1',          count($inbox), 1);
ok('inbox item qty 2',       $inbox[0]['items'][0]['quantity'] ?? null, 2);
ok('inbox customer ref opaque', ($inbox[0]['customer_ref'][0] ?? '') === 'C', true);
ok('inbox carries shade attr', $inbox[0]['items'][0]['attributes']['shade_code'] ?? null, '2003');
ok('V2 inbox empty (isolation)', count(SellerConsole::inbox($pdo,$V2)), 0);

echo "\n== ACCEPT (flag OFF = pure lifecycle): offered→accepted, order confirmed ==\n";
setcfg($pdo,'commerce_accept_gates_dispatch','0');
$acc=SellerConsole::accept($pdo,$V1,$SUB1);
ok('accept ok',               $acc['ok'], true);
ok('gated false (flag off)',  $acc['gated'], false);
ok('delivery_status accepted',dstatus($pdo,$SUB1), 'accepted');
ok('order status confirmed',  ostatus($pdo,$SUB1), 'confirmed');
ok('accepted signal logged',  sig($pdo,$SUB1,'accepted'), 1);
ok('idempotent: re-accept → not_offered', SellerConsole::accept($pdo,$V1,$SUB1)['reason'] ?? null, 'not_offered');

echo "\n== ACCEPT (flag ON = gated dispatch) ==\n";
setcfg($pdo,'commerce_accept_gates_dispatch','1');
ok('acceptGatesDispatch reads flag', SellerConsole::acceptGatesDispatch($pdo), true);
$P1b=mkOrder($pdo,'s15t-p1b',$uc,null,null,null,$cityId,null);
$SUB1b=mkOrder($pdo,'s15t-sub1b',$uc,$P1b,$V1,$S1,$cityId,'offered');
mkItem($pdo,$SUB1b,$pA1,$V1,1);
$accb=SellerConsole::accept($pdo,$V1,$SUB1b);
ok('accept ok (gated)',       $accb['ok'], true);
ok('gated true (flag on)',    $accb['gated'], true);
ok('still transitions to accepted', dstatus($pdo,$SUB1b), 'accepted'); // dispatch is best-effort (10.4 SKIP LOCKED → may defer; accept stands)

echo "\n== REJECT → reroute to the next seller (S3) ==\n";
$P2=mkOrder($pdo,'s15t-p2',$uc,null,null,null,$cityId,null);
$SUB2=mkOrder($pdo,'s15t-sub2',$uc,$P2,$V1,$S1,$cityId,'offered');
mkItem($pdo,$SUB2,$pA1,$V1,1);
$rej=SellerConsole::reject($pdo,$V1,$SUB2,'too busy');
ok('reject ok',               $rej['ok'], true);
ok('rejected signal logged',  sig($pdo,$SUB2,'rejected'), 1);
ok('rerouted to alternative', $rej['reroute']['rerouted'] ?? null, true);
ok('sub moved to V2/S2',      (int)$pdo->query("SELECT vendor_id FROM orders WHERE id=$SUB2")->fetchColumn(), $V2);
ok('sub re-offered',          dstatus($pdo,$SUB2), 'offered');

echo "\n== STOCK-OUT → reroute + stockout signal ==\n";
$P3=mkOrder($pdo,'s15t-p3',$uc,null,null,null,$cityId,null);
$SUB3=mkOrder($pdo,'s15t-sub3',$uc,$P3,$V1,$S1,$cityId,'offered');
mkItem($pdo,$SUB3,$pA1,$V1,1);
$so=SellerConsole::stockOut($pdo,$V1,$SUB3,'none left');
ok('stock-out ok',            $so['ok'], true);
ok('stockout signal logged',  sig($pdo,$SUB3,'stockout'), 1);
ok('stock-out rerouted',      $so['reroute']['rerouted'] ?? null, true);

echo "\n== ownership guard + earnings + history ==\n";
$P4=mkOrder($pdo,'s15t-p4',$uc,null,null,null,$cityId,null);
$SUB4=mkOrder($pdo,'s15t-sub4',$uc,$P4,$V1,$S1,$cityId,'offered');
ok('V2 cannot accept V1\'s order', SellerConsole::accept($pdo,$V2,$SUB4)['reason'] ?? null, 'not_yours');
// a delivered order for earnings
mkOrder($pdo,'s15t-earn',$uc,null,$V1,$S1,$cityId,'delivered','delivered',500);
$earn=SellerConsole::earnings($pdo,$V1);
ok('earnings delivered_count 1', $earn['delivered_count'], 1);
ok('earnings gross 500',         $earn['gross_delivered'], 500.0);
$ref=CustomerId::forUser($pdo,$uc);
$hist=SellerConsole::customerHistory($pdo,$V1,$ref);
ok('customer history ≥1 item for V1', $hist['item_count'] >= 1, true);

// restore flag
if ($acceptSnap !== false && $acceptSnap !== null) setcfg($pdo,'commerce_accept_gates_dispatch',(string)$acceptSnap);
cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("S15 RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
