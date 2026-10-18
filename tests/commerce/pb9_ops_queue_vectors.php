<?php
// ─────────────────────────────────────────────────────────────────────────────
//  PB9 PROOF — human-support escalation queue (one queue, system drives human).
//  Proves: ONE queue aggregates vehicle doubt (PB8), no-seller-accepted, rider
//  cash mismatch (PB5), high-value hold, cancellation dispute, payment stuck;
//  each row carries a plain "decide X" instruction + a suggested action + the
//  action buttons; auto-escalate kinds sort to the top; resolving an item (flag
//  / cash) removes it + logs the action. + admin route + page wiring.
//  Run: php tests/commerce/pb9_ops_queue_vectors.php   (local 10.11)
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
require_once SYSTEM_ROOT . '/lib/commerce/CommerceOpsQueue.php';
require_once SYSTEM_ROOT . '/lib/commerce/CommerceEventBus.php';
require_once SYSTEM_ROOT . '/lib/commerce/CommerceVehicle.php';
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
    $oids=$p->query("SELECT id FROM orders WHERE order_number LIKE 'PB9T-%'")->fetchAll(PDO::FETCH_COLUMN);
    if($oids){$in=implode(',',array_map('intval',$oids));
        $p->exec("DELETE FROM commerce_order_flags WHERE order_id IN ($in)");
        $p->exec("DELETE FROM commerce_events WHERE order_id IN ($in)");
        $p->exec("DELETE FROM commerce_vehicle_doubts WHERE order_id IN ($in)");
        $p->exec("DELETE FROM commerce_cash_collections WHERE order_id IN ($in)");
        $p->exec("DELETE FROM commerce_ops_actions WHERE order_id IN ($in)");
        $p->exec("DELETE FROM orders WHERE id IN ($in)"); }
    $p->exec("DELETE FROM users WHERE email LIKE 'pb9t-%@wtg.test'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('PB9',?, 'customer','active',NOW(),NOW())")->execute([$e]); return (int)$p->lastInsertId(); }
function mkOrder(PDO $p,$num,$uid,$city,$total){ $p->prepare("INSERT INTO orders (order_number,user_id,city_id,status,payment_status,payment_method,subtotal,total,shipping_address,delivery_status,ledger_status,created_at,updated_at) VALUES (?,?,?, 'confirmed','unpaid','cod',?,?,'{}','offered','pending',NOW(),NOW())")->execute([$num,$uid,$city,$total,$total]); return (int)$p->lastInsertId(); }
function find($q,$kind,$oid){ foreach($q as $it){ if($it['kind']===$kind && (int)$it['order_id']===$oid) return $it; } return null; }

cleanup($pdo);
$cityId=(int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if(!$cityId){ $pdo->exec("INSERT INTO cities (name,slug) VALUES ('PB9 City','pb9t-city')"); $cityId=(int)$pdo->lastInsertId(); }
$u=mkUser($pdo,'pb9t-c@wtg.test');

// build the six feed sources
$oNoSeller=mkOrder($pdo,'PB9T-NOSELL',$u,$cityId,500);  CommerceEventBus::raiseFlag($pdo,$oNoSeller,'needs_ops','no store accepted');
$oHV=mkOrder($pdo,'PB9T-HV',$u,$cityId,20000);          CommerceEventBus::raiseFlag($pdo,$oHV,'high_value_hold','total 20000');
$oPay=mkOrder($pdo,'PB9T-PAY',$u,$cityId,500);          CommerceEventBus::raiseFlag($pdo,$oPay,'payment_issue','gateway timeout');
$oDisp=mkOrder($pdo,'PB9T-DISP',$u,$cityId,500);        CommerceEventBus::raiseFlag($pdo,$oDisp,'cancellation_dispute','customer contests fee');
$oVeh=mkOrder($pdo,'PB9T-VEH',$u,$cityId,500);          CommerceVehicle::open($pdo,$oVeh,$oVeh,['vehicle'=>'bike','options'=>['bike','e_rickshaw','loader'],'reason'=>'19kg near 20kg cut-off','weight_kg'=>19.0]);
$oCash=mkOrder($pdo,'PB9T-CASH',$u,$cityId,3400);
$pdo->prepare("INSERT INTO commerce_cash_collections (order_id,delivery_id,rider_id,expected,collected,mismatch,created_at) VALUES (?,?,?,?,?,1,NOW())")->execute([$oCash,0,0,3400,3000]);

echo "\n== [A] one queue aggregates every feed, each a clear instruction ==\n";
$q = CommerceOpsQueue::build($pdo);
$nosell = find($q,'no_seller',$oNoSeller);
ok('no-seller item present', $nosell !== null, true);
ok('no-seller tells the human what to decide', $nosell && has($nosell['decide'],'No store accepted'), true);
ok('no-seller has actions', $nosell && count($nosell['actions']) >= 1, true);
$hv = find($q,'high_value',$oHV);
ok('high-value item present', $hv !== null, true);
ok('high-value has Approve/Reject actions', $hv ? $hv['actions'][0]['action'] : null, 'hold_approve');
ok('payment-stuck item present', find($q,'payment_stuck',$oPay) !== null, true);
ok('cancellation-dispute item present', find($q,'cancellation_dispute',$oDisp) !== null, true);
$veh = find($q,'vehicle_doubt',$oVeh);
ok('vehicle-doubt item present', $veh !== null, true);
ok('vehicle item offers the choices', $veh ? $veh['actions'][0]['choices'] : null, ['bike','e_rickshaw','loader']);
$cash = find($q,'cash_mismatch',$oCash);
ok('cash-mismatch item present', $cash !== null, true);
ok('cash item states collected vs expected', $cash && has($cash['detail'],'3,000') && has($cash['detail'],'3,400'), true);

echo "\n== [B] auto-escalate kinds sort to the top ==\n";
ok('needs_ops is escalated', $nosell['priority'], 'escalated');
ok('high_value is escalated', $hv['priority'], 'escalated');
ok('vehicle doubt is normal (not auto)', $veh['priority'], 'normal');
ok('first item is escalated', $q[0]['priority'], 'escalated');

echo "\n== [C] resolving removes items + logs the action ==\n";
ok('resolve high-value hold', \OpsConsole::approveHold($pdo,$oHV,99), true);
ok('resolved flag gone from queue', find(CommerceOpsQueue::build($pdo),'high_value',$oHV), null);
$rc = CommerceOpsQueue::resolveCash($pdo,(int)$pdo->query("SELECT id FROM commerce_cash_collections WHERE order_id=$oCash")->fetchColumn(),99,'rider short-paid, adjusted');
ok('cash resolve ok', $rc['ok'], true);
ok('resolved cash gone from queue', find(CommerceOpsQueue::build($pdo),'cash_mismatch',$oCash), null);
ok('cash resolution logged', (int)$pdo->query("SELECT COUNT(*) FROM commerce_ops_actions WHERE order_id=$oCash AND action='resolve'")->fetchColumn(), 1);
ok('double-resolve rejected', CommerceOpsQueue::resolveCash($pdo,(int)$pdo->query("SELECT id FROM commerce_cash_collections WHERE order_id=$oCash")->fetchColumn(),99,'x')['ok'], false);
ok('vehicle resolve clears its queue item', (function()use($pdo,$oVeh){ CommerceVehicle::resolve($pdo,$oVeh,'e_rickshaw','admin',99); return find(CommerceOpsQueue::build($pdo),'vehicle_doubt',$oVeh); })(), null);

echo "\n== [D] admin + page wiring ==\n";
$adm=get(SYSTEM_ROOT.'/heart/api/commerce-admin/index.php');
$pg=get(SYSTEM_ROOT.'/admin/commerce-ops.html');
$sh=get(SYSTEM_ROOT.'/admin/shared/shell.js');
ok('admin escalations + cash-resolve routes', has($adm,'/ops/escalations')&&has($adm,'/ops/cash/'), true);
ok('ops page renders decide + actions', has($pg,'ops/escalations')&&has($pg,'it.decide')&&has($pg,'function act'), true);
ok('ops page wires every action route', has($pg,'ops/resolve')&&has($pg,'hold/approve')&&has($pg,'vehicle-doubts')&&has($pg,'ops/cash/'), true);
ok('nav shows Ops Queue', has($sh,'commerce-ops.html'), true);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("PB9 OPS-QUEUE RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
