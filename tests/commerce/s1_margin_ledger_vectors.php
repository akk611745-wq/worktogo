<?php
// ─────────────────────────────────────────────────────────────────────────────
//  S1 PROOF HARNESS — Commerce margin + ledger (commerce-money-test-vectors.md)
//  Local CLI test. Proves MarginEngine pricing + the reworked
//  LedgerEngine::processCommerceOrderSettlement margin split on the local DB.
//  Run: php tests/commerce/s1_margin_ledger_vectors.php
//  Creates its own marked rows (S1T-… / s1t-…), asserts, cleans up. Idempotent.
// ─────────────────────────────────────────────────────────────────────────────

define('SYSTEM_ROOT', dirname(dirname(__DIR__)));

// 1. Load .env (same parse heart/index.php uses)
foreach (file(SYSTEM_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
    if (getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
}

require_once SYSTEM_ROOT . '/core/helpers/Logger.php';
require_once SYSTEM_ROOT . '/core/helpers/Database.php';
require_once SYSTEM_ROOT . '/lib/commerce/MarginEngine.php';
require_once SYSTEM_ROOT . '/core/helpers/LedgerEngine.php';
// LedgerEngine is namespaced (Core\Helpers) but Database/Logger are global — bridge for CLI.
if (!class_exists('Core\\Helpers\\Database')) class_alias('Database', 'Core\\Helpers\\Database');
if (!class_exists('Core\\Helpers\\Logger'))   class_alias('Logger', 'Core\\Helpers\\Logger');

$pdo = Database::getConnection();
use Core\Helpers\LedgerEngine;

$PASS = 0; $FAIL = 0;
function ok($label, $got, $exp, $tol = 0.005) {
    global $PASS, $FAIL;
    $good = is_bool($exp) ? ($got === $exp) : (abs((float)$got - (float)$exp) <= $tol);
    if ($good) { $PASS++; printf("  PASS  %-46s got=%s exp=%s\n", $label, var_export($got,true), var_export($exp,true)); }
    else       { $FAIL++; printf("  FAIL  %-46s got=%s exp=%s\n", $label, var_export($got,true), var_export($exp,true)); }
}

// ── cleanup prior test rows ───────────────────────────────────────────────────
function cleanup(PDO $pdo) {
    $oids = $pdo->query("SELECT id FROM orders WHERE order_number LIKE 'S1T-%'")->fetchAll(PDO::FETCH_COLUMN);
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    if ($oids) { $in = implode(',', array_map('intval', $oids));
        $pdo->exec("DELETE FROM wallet_transactions WHERE (reference_type='order' AND reference_id IN ($in)) OR order_id IN ($in)");
        $pdo->exec("DELETE FROM deliveries WHERE order_id IN ($in)");
        $pdo->exec("DELETE FROM order_items WHERE order_id IN ($in)");
        $pdo->exec("DELETE FROM orders WHERE id IN ($in)");
    }
    $vids = $pdo->query("SELECT id FROM vendors WHERE slug LIKE 's1t-%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($vids) { $in = implode(',', array_map('intval', $vids));
        $pdo->exec("DELETE FROM vendor_wallets WHERE vendor_id IN ($in)");
        $pdo->exec("DELETE FROM vendors WHERE id IN ($in)"); }
    $uids = $pdo->query("SELECT id FROM users WHERE email LIKE 's1t-%@wtg.test'")->fetchAll(PDO::FETCH_COLUMN);
    if ($uids) { $in = implode(',', array_map('intval', $uids));
        $pdo->exec("DELETE FROM driver_wallets WHERE driver_id IN ($in)");
        $pdo->exec("DELETE FROM users WHERE id IN ($in)"); }
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
}

// ── builders ──────────────────────────────────────────────────────────────────
function mkUser(PDO $p,$name,$email,$role='customer'){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES (?,?,?,'active',NOW(),NOW())")->execute([$name,$email,$role]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$name,$slug,$rate){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',?,NOW(),NOW())")->execute([$uid,$name,$slug,$rate]); return (int)$p->lastInsertId(); }
function mkOrder(PDO $p,$num,$uid,$parent,$vendor,$subtotal,$sellerSub,$deliveryFee,$pm,$total=null){
    $total = $total ?? $subtotal;
    $p->prepare("INSERT INTO orders (order_number,parent_order_id,user_id,vendor_id,status,payment_status,payment_method,subtotal,seller_subtotal,delivery_fee,total,shipping_address,ledger_status,created_at,updated_at)
                 VALUES (?,?,?,?,'delivered',?,?,?,?,?,?,'{}','pending',NOW(),NOW())")
      ->execute([$num,$parent,$uid,$vendor,($pm==='cod'?'unpaid':'paid'),$pm,$subtotal,$sellerSub,$deliveryFee,$total]);
    return (int)$p->lastInsertId();
}
function mkDelivery(PDO $p,$oid,$driver,$price,$payout,$fee){ $p->prepare("INSERT INTO deliveries (order_id,driver_id,status,price,rider_payout,platform_fee,created_at) VALUES (?,?,'delivered',?,?,?,NOW())")->execute([$oid,$driver,$price,$payout,$fee]); }
function txSum(PDO $p,$parent,$entity){ $s=$p->prepare("SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE entity_type=? AND reference_type='order' AND reference_id=?"); $s->execute([$entity,$parent]); return (float)$s->fetchColumn(); }
function txCount(PDO $p,$parent){ $s=$p->prepare("SELECT COUNT(*) FROM wallet_transactions WHERE reference_type='order' AND reference_id=?"); $s->execute([$parent]); return (int)$s->fetchColumn(); }
function driverCash(PDO $p,$did){ $s=$p->prepare("SELECT COALESCE(cash_in_hand,0) FROM driver_wallets WHERE driver_id=?"); $s->execute([$did]); return (float)$s->fetchColumn(); }

cleanup($pdo);
$cust = mkUser($pdo,'S1 Test Customer','s1t-cust@wtg.test','customer');

echo "\n== A. MarginEngine pricing ==\n";
$policy = MarginEngine::policy($pdo);
$e1 = MarginEngine::price(200, null,null, null,null, $policy); ok('E1 margin',$e1['margin'],16.00); ok('E1 customer',$e1['customer_price'],216);
$e2 = MarginEngine::price(10,  null,null, null,null, $policy); ok('E2 margin(min floor)',$e2['margin'],2.00); ok('E2 customer',$e2['customer_price'],12);
$e3 = MarginEngine::price(200, 15,null, null,null, $policy);   ok('E3 product-override margin',$e3['margin'],30.00); ok('E3 customer',$e3['customer_price'],230);
$e4 = MarginEngine::price(100, null,null, 12,null, $policy);   ok('E4 category-override margin',$e4['margin'],12.00); ok('E4 customer',$e4['customer_price'],112);
$floor = (float)($pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='commerce_loss_proof_floor'")->fetchColumn());
ok('loss-proof reject (margin16 < exposure30)', MarginEngine::lossProofOk(16,30,$floor), false);
ok('loss-proof ok (margin40 >= exposure30)',    MarginEngine::lossProofOk(40,30,$floor), true);

echo "\n== B. Settlement vectors ==\n";
// Vector A — vendor, prepaid
$vuA = mkUser($pdo,'S1 Vendor A','s1t-vA@wtg.test','vendor_shopping'); $vA = mkVendor($pdo,$vuA,'S1T Store A','s1t-store-a',10);
$drA = mkUser($pdo,'S1 Driver A','s1t-drA@wtg.test','delivery');
$pA = mkOrder($pdo,'S1T-A-P',$cust,null,null,240,200,30,'online');
mkOrder($pdo,'S1T-A-S1',$cust,$pA,$vA,240,200,0,'online');
mkDelivery($pdo,$pA,$drA,30,24,6);
LedgerEngine::processCommerceOrderSettlement($pA,$pdo);
ok('A vendor credit', txSum($pdo,$pA,'vendor'),200.00);
ok('A platform credit', txSum($pdo,$pA,'platform'),46.00);
ok('A rider credit', txSum($pdo,$pA,'driver'),24.00);
ok('A driver cash (prepaid=0)', driverCash($pdo,$drA),0.00);

// Vector B — company store, COD
$drB = mkUser($pdo,'S1 Driver B','s1t-drB@wtg.test','delivery');
$pB = mkOrder($pdo,'S1T-B-P',$cust,null,null,150,null,40,'cod');
mkOrder($pdo,'S1T-B-S1',$cust,$pB,null,150,null,0,'cod'); // company sub: vendor_id NULL
mkDelivery($pdo,$pB,$drB,40,32,8);
LedgerEngine::processCommerceOrderSettlement($pB,$pdo);
ok('B vendor credit (none)', txSum($pdo,$pB,'vendor'),0.00);
ok('B platform credit (full+del)', txSum($pdo,$pB,'platform'),158.00);
ok('B rider credit', txSum($pdo,$pB,'driver'),32.00);
ok('B driver cash (COD owed)', driverCash($pdo,$drB),158.00);

// Vector C — multi-vendor, prepaid
$vuC1=mkUser($pdo,'S1 Vendor C1','s1t-vC1@wtg.test','vendor_shopping'); $vC1=mkVendor($pdo,$vuC1,'S1T Store C1','s1t-store-c1',10);
$vuC2=mkUser($pdo,'S1 Vendor C2','s1t-vC2@wtg.test','vendor_shopping'); $vC2=mkVendor($pdo,$vuC2,'S1T Store C2','s1t-store-c2',10);
$drC = mkUser($pdo,'S1 Driver C','s1t-drC@wtg.test','delivery');
$pC = mkOrder($pdo,'S1T-C-P',$cust,null,null,185,150,30,'online');
mkOrder($pdo,'S1T-C-S1',$cust,$pC,$vC1,120,100,0,'online');
mkOrder($pdo,'S1T-C-S2',$cust,$pC,$vC2,65,50,0,'online');
mkDelivery($pdo,$pC,$drC,30,24,6);
LedgerEngine::processCommerceOrderSettlement($pC,$pdo);
ok('C vendor credit (100+50)', txSum($pdo,$pC,'vendor'),150.00);
ok('C platform credit (20+15+6)', txSum($pdo,$pC,'platform'),41.00);
ok('C rider credit', txSum($pdo,$pC,'driver'),24.00);

// Vector D — legacy (no seller_base) → commission back-compat, COD, no rider
$vuD=mkUser($pdo,'S1 Vendor D','s1t-vD@wtg.test','vendor_shopping'); $vD=mkVendor($pdo,$vuD,'S1T Store D','s1t-store-d',10);
$pD = mkOrder($pdo,'S1T-D-P',$cust,null,null,200,null,0,'cod');
mkOrder($pdo,'S1T-D-S1',$cust,$pD,$vD,200,null,0,'cod'); // seller_subtotal NULL → commission 10%
LedgerEngine::processCommerceOrderSettlement($pD,$pdo);
ok('D vendor credit (commission back-compat)', txSum($pdo,$pD,'vendor'),180.00);
ok('D platform credit (commission 10%)', txSum($pdo,$pD,'platform'),20.00);

echo "\n== C. Idempotency ==\n";
$before = txCount($pdo,$pA);
$r = LedgerEngine::processCommerceOrderSettlement($pA,$pdo); // second call = no-op
ok('A second-settle returns true', $r, true);
ok('A no duplicate tx rows', txCount($pdo,$pA), $before);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("S1 RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
