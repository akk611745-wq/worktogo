<?php
// ─────────────────────────────────────────────────────────────────────────────
//  S5 PROOF HARNESS — commerce payment over the gateway-agnostic layer.
//  Uses STUB gateways injected via PaymentGatewayManager config (NO network) to
//  prove: method config, online init, primary→secondary FAILOVER, idempotent
//  webhook (sole paid authority), amount-mismatch rejection, COD.
//  The real Cashfree SANDBOX round-trip (creds + network) is a separate live step.
//  Run: php tests/commerce/s5_payment_vectors.php
// ─────────────────────────────────────────────────────────────────────────────
define('SYSTEM_ROOT', dirname(dirname(__DIR__)));
foreach (file(SYSTEM_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2); $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
    if (getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
}
require_once SYSTEM_ROOT . '/core/helpers/Database.php';
require_once SYSTEM_ROOT . '/lib/commerce/CommercePayment.php'; // pulls PaymentService + PaymentGatewayManager + PaymentGateway
$pdo = Database::getConnection();

// ── Stub gateways (implement the real PaymentGateway contract) ───────────────
class S5StubOk implements PaymentGateway {
    public function name(): string { return 'stub_ok'; }
    public function isConfigured(): bool { return true; }
    public function createOrder(array $r): array {
        return ['success'=>true,'gateway'=>'stub_ok','gateway_order_id'=>$r['order_ref'],
                'payment_session_id'=>'sess_'.$r['order_ref'],'order_token'=>'tok','mode'=>'test',
                'http_code'=>200,'retryable'=>false,'error'=>null];
    }
    public function verifyWebhook(string $b, array $h): bool { return true; }
    public function parseWebhook(string $b, array $d): array { return $d; }
}
class S5StubFail implements PaymentGateway { // retryable failure = gateway DOWN → should fail over
    public function name(): string { return 'stub_fail'; }
    public function isConfigured(): bool { return true; }
    public function createOrder(array $r): array {
        return ['success'=>false,'gateway'=>'stub_fail','http_code'=>503,'retryable'=>true,
                'error'=>'gateway down','payment_session_id'=>null];
    }
    public function verifyWebhook(string $b, array $h): bool { return true; }
    public function parseWebhook(string $b, array $d): array { return $d; }
}
// db-less manager: skips the prod DB routing override so the injected stub routing applies
// (order/webhook DB writes use PaymentService's own $pdo, not the manager's db).
function mgr(PDO $pdo, array $cfg){ return new PaymentGatewayManager(null, $cfg); }
$CFG_OK   = ['gateways'=>['stub_ok'=>['adapter'=>'S5StubOk','enabled'=>true]],
            'routing'=>['commerce'=>['primary'=>'stub_ok','secondary'=>null]]];
$CFG_FAIL = ['gateways'=>['stub_fail'=>['adapter'=>'S5StubFail','enabled'=>true],'stub_ok'=>['adapter'=>'S5StubOk','enabled'=>true]],
            'routing'=>['commerce'=>['primary'=>'stub_fail','secondary'=>'stub_ok']]];

$PASS=0; $FAIL=0;
function ok($l,$g,$e){ global $PASS,$FAIL; $g=is_bool($g)?var_export($g,true):(string)$g; $e=is_bool($e)?var_export($e,true):(string)$e;
  if($g===$e){$PASS++;printf("  PASS  %-44s %s\n",$l,$g);}else{$FAIL++;printf("  FAIL  %-44s got=%s exp=%s\n",$l,$g,$e);} }
function cleanup(PDO $p){
  $p->exec("SET FOREIGN_KEY_CHECKS=0");
  $oids=$p->query("SELECT id FROM orders WHERE order_number LIKE 'S5T-%'")->fetchAll(PDO::FETCH_COLUMN);
  if($oids){$in=implode(',',array_map('intval',$oids));
    $p->exec("DELETE FROM transactions WHERE reference_type='order' AND reference_id IN ($in)");
    $p->exec("DELETE FROM commerce_events WHERE order_id IN ($in)");
    $p->exec("DELETE FROM orders WHERE id IN ($in)"); }
  $uids=$p->query("SELECT id FROM users WHERE email LIKE 's5t-%@wtg.test'")->fetchAll(PDO::FETCH_COLUMN);
  if($uids){$in=implode(',',array_map('intval',$uids)); $p->exec("DELETE FROM notifications WHERE user_id IN ($in)"); $p->exec("DELETE FROM users WHERE id IN ($in)"); }
  $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e){ $p->prepare("INSERT INTO users (name,email,phone,role,status,created_at,updated_at) VALUES ('S5',?,?, 'customer','active',NOW(),NOW())")->execute([$e,'9000000000']); return (int)$p->lastInsertId(); }
function mkOrder(PDO $p,$num,$uid,$total){ $p->prepare("INSERT INTO orders (order_number,user_id,status,payment_status,payment_method,subtotal,total,shipping_address,ledger_status,created_at,updated_at) VALUES (?,?, 'pending','unpaid','cod',?,?,'{}','pending',NOW(),NOW())")->execute([$num,$uid,$total,$total]); return (int)$p->lastInsertId(); }
function col(PDO $p,$oid,$c){ $s=$p->prepare("SELECT $c FROM orders WHERE id=?"); $s->execute([$oid]); return $s->fetchColumn(); }
function txCount(PDO $p,$oid){ $s=$p->prepare("SELECT COUNT(*) FROM transactions WHERE reference_type='order' AND reference_id=? AND status='success'"); $s->execute([$oid]); return (int)$s->fetchColumn(); }

cleanup($pdo);
$u = mkUser($pdo,'s5t-c@wtg.test');
$USER = ['id'=>$u,'name'=>'S5','email'=>'s5t-c@wtg.test','phone'=>'9000000000'];

echo "\n== method config ==\n";
ok('P1 default method = online', CommercePayment::defaultMethod($pdo), 'online');
ok('P1 online allowed', CommercePayment::isAllowed($pdo,'online'), true);
ok('P1 cod allowed', CommercePayment::isAllowed($pdo,'cod'), true);
ok('P1 upi NOT allowed', CommercePayment::isAllowed($pdo,'upi'), false);

echo "\n== online init (stub gateway) ==\n";
$o1 = mkOrder($pdo,'S5T-ON',$u,500.00);
$r1 = CommercePayment::initOnline($pdo,$o1,$USER, mgr($pdo,$CFG_OK));
ok('P2 online success', !empty($r1['success']), true);
ok('P2 session returned', $r1['payment_session_id'] === 'sess_'.col($pdo,$o1,'payment_id'), true);
ok('P2 order method=online', col($pdo,$o1,'payment_method'), 'online');
ok('P2 order gateway=stub_ok', col($pdo,$o1,'payment_gateway'), 'stub_ok');

echo "\n== failover (primary down → secondary) ==\n";
$o2 = mkOrder($pdo,'S5T-FO',$u,700.00);
$r2 = CommercePayment::initOnline($pdo,$o2,$USER, mgr($pdo,$CFG_FAIL));
ok('P3 success via failover', !empty($r2['success']), true);
ok('P3 failover flag true', !empty($r2['failover']), true);
ok('P3 handled by stub_ok', $r2['gateway'], 'stub_ok');

echo "\n== webhook = sole paid authority + idempotent ==\n";
$ref1 = col($pdo,$o1,'payment_id');
$event = ['gateway'=>'stub_ok','order_ref'=>$ref1,'amount'=>500.00,'internal_status'=>'paid','gateway_txn_id'=>'TXN-1','event_type'=>'PAYMENT_SUCCESS'];
$w1 = (new PaymentService($pdo))->processWebhookEvent($event);
ok('P4 webhook ok', !empty($w1['success']), true);
ok('P4 order now paid', col($pdo,$o1,'payment_status'), 'paid');
ok('P4 order confirmed', col($pdo,$o1,'status'), 'confirmed');
ok('P4 one success txn', txCount($pdo,$o1), 1);
$w2 = (new PaymentService($pdo))->processWebhookEvent($event); // replay
ok('P4 replay skipped', strpos((string)($w2['message'] ?? ''),'Already processed')!==false, true);
ok('P4 still one txn (idempotent)', txCount($pdo,$o1), 1);

echo "\n== amount mismatch rejected ==\n";
$o3 = mkOrder($pdo,'S5T-AMT',$u,500.00);
CommercePayment::initOnline($pdo,$o3,$USER, mgr($pdo,$CFG_OK));
$ref3 = col($pdo,$o3,'payment_id');
$bad = ['gateway'=>'stub_ok','order_ref'=>$ref3,'amount'=>999.00,'internal_status'=>'paid','gateway_txn_id'=>'TXN-BAD'];
$w3 = (new PaymentService($pdo))->processWebhookEvent($bad);
ok('P5 mismatch rejected', empty($w3['success']), true);
ok('P5 order flagged failed', col($pdo,$o3,'payment_status'), 'failed');

echo "\n== COD path ==\n";
$o4 = mkOrder($pdo,'S5T-COD',$u,300.00);
$c = CommercePayment::confirmCod($pdo,$o4);
ok('P6 cod confirmed ok', !empty($c['success']), true);
ok('P6 method cod', col($pdo,$o4,'payment_method'), 'cod');
ok('P6 status confirmed', col($pdo,$o4,'status'), 'confirmed');
ok('P6 payment unpaid (COD)', col($pdo,$o4,'payment_status'), 'unpaid');

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("S5 RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL>0?1:0);
