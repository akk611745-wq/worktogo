<?php
// ─────────────────────────────────────────────────────────────────────────────
//  PB5 PROOF — Rider screen: server-computed money line + cash collection.
//  Proves: CommercePayment::collectAtDoor (PREPAID→0, COD→amount, ADVANCE→balance)
//  is computed SERVER-side from the delivery handoff contract (is_prepaid /
//  cod_amount that CommerceDelivery::enrichHandoff wrote onto `deliveries`) +
//  the advance balance; recordCashCollected logs a row and flags a mismatch vs
//  expected; the rider payload carries the collect line + vehicle/weight load flag
//  and NEVER the seller base price or our margin; + rider UI/adapter wiring.
//  Run: php tests/commerce/pb5_rider_collect_vectors.php   (local 10.11)
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
require_once SYSTEM_ROOT . '/lib/commerce/CommercePayment.php';
$pdo = Database::getConnection();

$PASS = 0; $FAIL = 0;
function ok($label, $got, $exp = true) {
    global $PASS, $FAIL;
    $g = is_float($got) ? number_format($got, 2, '.', '') : $got;
    $e = is_float($exp) ? number_format($exp, 2, '.', '') : $exp;
    if ($g === $e) { $PASS++; printf("  PASS  %-54s got=%s\n", $label, var_export($g, true)); }
    else { $FAIL++; printf("  FAIL  %-54s got=%s exp=%s\n", $label, var_export($g, true), var_export($e, true)); }
}
function has(string $h,string $n):bool{ return strpos($h,$n)!==false; }
function get(string $p):string{ return is_file($p)?(string)file_get_contents($p):''; }
function cleanup(PDO $p){
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $oids=$p->query("SELECT id FROM orders WHERE order_number LIKE 'PB5T-%'")->fetchAll(PDO::FETCH_COLUMN);
    if($oids){$in=implode(',',array_map('intval',$oids));
        $p->exec("DELETE FROM commerce_order_payments WHERE order_id IN ($in)");
        $p->exec("DELETE FROM commerce_cash_collections WHERE order_id IN ($in)");
        $p->exec("DELETE FROM deliveries WHERE order_id IN ($in)");
        $p->exec("DELETE FROM orders WHERE id IN ($in)"); }
    $p->exec("DELETE FROM users WHERE email LIKE 'pb5t-%@wtg.test'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e,$role='customer'){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('PB5',?,?, 'active',NOW(),NOW())")->execute([$e,$role]); return (int)$p->lastInsertId(); }
function mkOrder(PDO $p,$num,$uid,$city,$payStatus,$payMethod,$total){
    $p->prepare(
      "INSERT INTO orders (order_number,user_id,city_id,status,payment_status,payment_method,subtotal,delivery_fee,total,shipping_address,delivery_status,ledger_status,created_at,updated_at)
       VALUES (?,?,?, 'confirmed',?,?,?,0,?, '{\"name\":\"Cust\",\"phone\":\"9000000000\",\"line1\":\"1 Mall Rd\",\"city\":\"Haldwani\"}','pending','pending',NOW(),NOW())"
    )->execute([$num,$uid,$city,$payStatus,$payMethod,$total,$total]);
    return (int)$p->lastInsertId();
}
// A delivery carrying the handoff contract enrichHandoff writes (is_prepaid / cod_amount / weight / vehicle).
function mkDelivery(PDO $p,$oid,$rider,$status,$isPrepaid,$cod,$weight,$vehicle){
    $p->prepare("INSERT INTO deliveries (order_id,driver_id,status,price,is_prepaid,cod_amount,total_weight_kg,required_vehicle,created_at,updated_at)
                 VALUES (?,?,?,60,?,?,?,?,NOW(),NOW())")
      ->execute([$oid,$rider,$status,$isPrepaid,$cod,$weight,$vehicle]);
    return (int)$p->lastInsertId();
}

cleanup($pdo);
$cityId=(int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if(!$cityId){ $pdo->exec("INSERT INTO cities (name,slug) VALUES ('PB5 City','pb5t-city')"); $cityId=(int)$pdo->lastInsertId(); }
$cust=mkUser($pdo,'pb5t-c@wtg.test');
$rider=mkUser($pdo,'pb5t-r@wtg.test','delivery');

echo "\n== [A] collectAtDoor computed SERVER-side from the handoff contract ==\n";
$oPre=mkOrder($pdo,'PB5T-PRE',$cust,$cityId,'paid','online',2000);
mkDelivery($pdo,$oPre,$rider,'assigned',1,0.0,18.0,'bike');
$cPre=CommercePayment::collectAtDoor($pdo,$oPre);
ok('prepaid → type prepaid', $cPre['type'], 'prepaid');
ok('prepaid → collect 0',    (float)$cPre['amount'], 0.0);

$oCod=mkOrder($pdo,'PB5T-COD',$cust,$cityId,'unpaid','cod',3400);
$didCod=mkDelivery($pdo,$oCod,$rider,'picked',0,3400.0,52.0,'e_rickshaw');
$cCod=CommercePayment::collectAtDoor($pdo,$oCod);
ok('cod → type cod',   $cCod['type'], 'cod');
ok('cod → collect 3400 (from handoff cod_amount)',(float)$cCod['amount'], 3400.0);

$oAdv=mkOrder($pdo,'PB5T-ADV',$cust,$cityId,'partially_paid','online',5000);
mkDelivery($pdo,$oAdv,$rider,'assigned',1,0.0,120.0,'loader'); // online→is_prepaid=1, but advance wins
$pdo->prepare("INSERT INTO commerce_order_payments (order_id,kind,expected_amount,captured_amount,balance_amount,status,created_at,updated_at) VALUES (?, 'advance',2500,2500,2500,'captured',NOW(),NOW())")->execute([$oAdv]);
$cAdv=CommercePayment::collectAtDoor($pdo,$oAdv);
ok('advance → type advance (beats is_prepaid)',  $cAdv['type'], 'advance');
ok('advance → collect balance 2500',(float)$cAdv['amount'], 2500.0);

echo "\n== [B] recordCashCollected: match vs mismatch flag + audit row ==\n";
$match=CommercePayment::recordCashCollected($pdo,$oCod,$didCod,$rider,3400);
ok('exact cash → no mismatch', $match['mismatch'], false);
ok('expected surfaced = 3400',  (float)$match['expected'], 3400.0);
$miss=CommercePayment::recordCashCollected($pdo,$oCod,$didCod,$rider,3000);
ok('short cash → mismatch flagged', $miss['mismatch'], true);
$flagged=(int)$pdo->query("SELECT COUNT(*) FROM commerce_cash_collections WHERE order_id=$oCod AND mismatch=1")->fetchColumn();
ok('mismatch row persisted for admin', $flagged, 1);
$rows=(int)$pdo->query("SELECT COUNT(*) FROM commerce_cash_collections WHERE order_id=$oCod")->fetchColumn();
ok('both collections logged', $rows, 2);

echo "\n== [C] rider payload: collect line + load flag, NEVER seller base / margin ==\n";
$api = get(SYSTEM_ROOT.'/heart/api/rider/index.php');
ok('payload exposes collect fields', has($api,'collect_type')&&has($api,'collect_amount')&&has($api,'collect_label'), true);
ok('label text is server-authored',  has($api,'Collect nothing')&&has($api,'cash')&&has($api,'balance'), true);
ok('load flag: weight + vehicle from delivery', has($api,'d.total_weight_kg')&&has($api,'d.required_vehicle'), true);
ok('collected route present',         has($api,"/collected")&&has($api,'recordCashCollected'), true);
// no seller-cost data field in the SELECT / payload (CSS/comments aside)
$leak = fn(string $h) => has($h,'seller_base')||has($h,'seller_subtotal')||has($h,'our_margin')||has($h,'margin_pct')||has($h,'margin_amount')||has($h,'cost_price');
ok('NO seller base / margin field exposed', $leak($api), false);

echo "\n== [D] rider UI wiring ==\n";
$js = get(SYSTEM_ROOT.'/rider/rider.js'); $html = get(SYSTEM_ROOT.'/rider/index.html');
ok('UI: big money line from server', has($js,'collect_label')&&has($js,'moneyLine'), true);
ok('UI: collected input + handler',  has($js,'cc-${j.id}')&&has($js,'Rider.collected'), true);
ok('UI: weight + vehicle flag',      has($js,'total_weight_kg')&&has($js,'required_vehicle'), true);
ok('UI: amount NOT computed in JS (no cost field)', $leak($js), false);
ok('CSS: collect money-line style',  has($html,'.collect')&&has($html,'.collect.due'), true);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("PB5 RIDER-COLLECT RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
