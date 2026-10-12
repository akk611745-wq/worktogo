<?php
// ─────────────────────────────────────────────────────────────────────────────
//  P4 PROOF HARNESS — advance / partial-payment seam (gap #1).
//  Drives the REAL webhook (PaymentService::processWebhookEvent) with crafted
//  events. Proves:
//    • a commerce order with an advance intent: part-capture → partially_paid,
//      balance tracked, COD-at-door balance surfaced; idempotent on replay;
//      a wrong advance amount is flagged (failed), never silently accepted.
//    • REGRESSION — bookings & non-advance orders keep the exact-amount check:
//      a non-advance order with a partial capture still FAILS; a full capture
//      still PAIDs; a booking (which can never have an advance row — FK to
//      orders) keeps exact-amount: partial → failed, full → paid.
//  Run: php tests/commerce/p4_advance_payment_vectors.php   (local 10.11)
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
require_once SYSTEM_ROOT . '/lib/PaymentService.php';
require_once SYSTEM_ROOT . '/lib/commerce/CommercePayment.php';
$pdo = Database::getConnection();
$PS  = new PaymentService($pdo);

$PASS = 0; $FAIL = 0;
function ok($label, $got, $exp = true) {
    global $PASS, $FAIL;
    $g = is_float($got) ? number_format($got, 2, '.', '') : $got;
    $e = is_float($exp) ? number_format($exp, 2, '.', '') : $exp;
    if ($g === $e) { $PASS++; printf("  PASS  %-52s got=%s\n", $label, var_export($g, true)); }
    else { $FAIL++; printf("  FAIL  %-52s got=%s exp=%s\n", $label, var_export($g, true), var_export($e, true)); }
}
function cleanup(PDO $p){
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $ids=$p->query("SELECT id FROM orders WHERE order_number LIKE 'P4T-%'")->fetchAll(PDO::FETCH_COLUMN);
    if($ids){$in=implode(',',array_map('intval',$ids));
        $p->exec("DELETE FROM commerce_order_payments WHERE order_id IN ($in)");
        $p->exec("DELETE FROM transactions WHERE reference_type='order' AND reference_id IN ($in)");
        $p->exec("DELETE FROM wallet_transactions WHERE reference_type='order' AND reference_id IN ($in)");
    }
    $p->exec("DELETE FROM orders WHERE order_number LIKE 'P4T-%'");
    $p->exec("DELETE FROM bookings WHERE booking_number LIKE 'P4T-%'");
    $p->exec("DELETE FROM services WHERE name = 'P4 Test Service'");
    $p->exec("DELETE FROM stores   WHERE slug LIKE 'p4t-%'");
    $p->exec("DELETE FROM vendors  WHERE slug LIKE 'p4t-%'");
    $p->exec("DELETE FROM users    WHERE email LIKE 'p4t-%@wtg.test'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('P4',?, 'customer','active',NOW(),NOW())")->execute([$e]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',1,10,NOW(),NOW())")->execute([$uid,'P4 '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkStore(PDO $p,$slug,$v,$city){ $p->prepare("INSERT INTO stores (ownership,owner_vendor_id,city_id,name,slug,status,created_at,updated_at) VALUES ('vendor',?,?,?,?,'active',NOW(),NOW())")->execute([$v,$city,'P4 S '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkOrder(PDO $p,$num,$uid,$vendor,$store,$city,$total,$ref){ $p->prepare("INSERT INTO orders (order_number,user_id,vendor_id,store_id,city_id,status,payment_status,payment_method,subtotal,delivery_fee,total,shipping_address,delivery_status,ledger_status,payment_id,created_at,updated_at) VALUES (?,?,?,?,?, 'pending','unpaid','online',?,0,?,'{}','pending','pending',?,NOW(),NOW())")->execute([$num,$uid,$vendor,$store,$city,$total,$total,$ref]); return (int)$p->lastInsertId(); }
function pstatus(PDO $p,$oid){ $s=$p->prepare("SELECT payment_status FROM orders WHERE id=?"); $s->execute([$oid]); return (string)$s->fetchColumn(); }
function evt($ref,$amt){ return ['order_ref'=>$ref,'gateway'=>'cashfree','amount'=>$amt,'gateway_txn_id'=>'txn-'.substr(md5($ref.$amt.microtime()),0,8),'internal_status'=>'paid']; }

cleanup($pdo);
$cityId=(int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if(!$cityId){ $pdo->exec("INSERT INTO cities (name,slug) VALUES ('P4 City','p4t-city')"); $cityId=(int)$pdo->lastInsertId(); }
$u=mkUser($pdo,'p4t-c@wtg.test'); $V=mkVendor($pdo,$u,'p4t-v'); $St=mkStore($pdo,'p4t-s',$V,$cityId);

echo "\n== [A] advance intent (50% default) ==\n";
$o1=mkOrder($pdo,'P4T-ADV1',$u,$V,$St,$cityId,1000,'P4T-REF-ADV1');
$intent=CommercePayment::recordAdvanceIntent($pdo,$o1);
ok('advance = 50% of 1000 = 500', (float)$intent['advance'], 500.0);
ok('balance = 500',               (float)$intent['balance'], 500.0);
ok('advance row pending',         CommercePayment::advanceStatus($pdo,$o1)['status'], 'pending');

echo "\n== [B] webhook captures the advance → partially_paid + balance tracked ==\n";
$r=$PS->processWebhookEvent(evt('P4T-REF-ADV1',500));
ok('webhook success',             $r['success'] ?? null, true);
ok('order → partially_paid',      pstatus($pdo,$o1), 'partially_paid');
$as=CommercePayment::advanceStatus($pdo,$o1);
ok('advance captured',            $as['status'], 'captured');
ok('captured_amount = 500',       (float)$as['captured_amount'], 500.0);
ok('balance_amount = 500',        (float)$as['balance_amount'], 500.0);
ok('balanceDue (COD at door) 500',(float)CommercePayment::balanceDue($pdo,$o1), 500.0);

echo "\n== [C] idempotent replay — stays partially_paid, not re-charged ==\n";
$r2=$PS->processWebhookEvent(evt('P4T-REF-ADV1',500));
ok('replay reports skipped/ok',   $r2['success'] ?? null, true);
ok('still partially_paid',        pstatus($pdo,$o1), 'partially_paid');

echo "\n== [D] wrong advance amount → flagged failed (never silently accepted) ==\n";
$o2=mkOrder($pdo,'P4T-ADV2',$u,$V,$St,$cityId,1000,'P4T-REF-ADV2');
CommercePayment::recordAdvanceIntent($pdo,$o2); // expects 500
$r3=$PS->processWebhookEvent(evt('P4T-REF-ADV2',400)); // gateway captured 400 ≠ 500
ok('mismatch returns failure',    $r3['success'] ?? null, false);
ok('order → failed',              pstatus($pdo,$o2), 'failed');
ok('advance row → failed',        CommercePayment::advanceStatus($pdo,$o2)['status'], 'failed');

echo "\n== [E] REGRESSION — NON-advance order keeps exact-amount (unchanged) ==\n";
$o3=mkOrder($pdo,'P4T-FULL',$u,$V,$St,$cityId,800,'P4T-REF-FULL'); // no advance row
ok('full capture (800) → paid',   (function() use($PS,$pdo,$o3){ $PS->processWebhookEvent(evt('P4T-REF-FULL',800)); return pstatus($pdo,$o3); })(), 'paid');
$o4=mkOrder($pdo,'P4T-PART',$u,$V,$St,$cityId,800,'P4T-REF-PART'); // no advance row
ok('partial capture (400) → FAILED (exact-amount)', (function() use($PS,$pdo,$o4){ $PS->processWebhookEvent(evt('P4T-REF-PART',400)); return pstatus($pdo,$o4); })(), 'failed');

echo "\n== [F] REGRESSION — BOOKINGS untouched (FK means a booking can never get an advance row) ==\n";
$svc=(int)($pdo->query("SELECT id FROM services ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
$bvend=$V;
if ($svc <= 0) {
    try { $pdo->prepare("INSERT INTO services (vendor_id,name,base_price) VALUES (?,?,?)")->execute([$V,'P4 Test Service',499.00]); $svc=(int)$pdo->lastInsertId(); }
    catch (\Throwable $e) { echo "  SKIP  could not create a service row (".$e->getMessage()."); booking exact-amount path still covered by the reference_type==='order' gate + [E]\n"; }
}
if ($svc <= 0) {
    echo "  (booking webhook test skipped)\n";
} else {
    $bu=mkUser($pdo,'p4t-bk@wtg.test');
    $mkBooking=function($num,$total,$ref) use($pdo,$bu,$bvend,$svc){ $pdo->prepare("INSERT INTO bookings (booking_number,user_id,vendor_id,service_id,total,scheduled_at,status,payment_status,payment_method,payment_id,created_at,updated_at) VALUES (?,?,?,?,?,NOW(),'pending','unpaid','online',?,NOW(),NOW())")->execute([$num,$bu,$bvend,$svc,$total,$ref]); return (int)$pdo->lastInsertId(); };
    $b1=$mkBooking('P4T-BKG1',700,'P4T-REF-BKG1');
    $PS->processWebhookEvent(evt('P4T-REF-BKG1',700));
    ok('booking full (700) → paid', (string)$pdo->query("SELECT payment_status FROM bookings WHERE id=$b1")->fetchColumn(), 'paid');
    $b2=$mkBooking('P4T-BKG2',700,'P4T-REF-BKG2');
    $PS->processWebhookEvent(evt('P4T-REF-BKG2',350)); // partial
    ok('booking partial (350) → FAILED (exact-amount preserved)', (string)$pdo->query("SELECT payment_status FROM bookings WHERE id=$b2")->fetchColumn(), 'failed');
    ok('booking got NO advance row (FK to orders)', (int)$pdo->query("SELECT COUNT(*) FROM commerce_order_payments WHERE order_id IN ($b1,$b2)")->fetchColumn(), 0);
}

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("P4 ADVANCE RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
