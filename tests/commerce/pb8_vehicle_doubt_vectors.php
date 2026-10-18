<?php
// ─────────────────────────────────────────────────────────────────────────────
//  PB8 PROOF — vehicle doubt → seller suggestion → admin override.
//  Proves: the weight→vehicle auto pick is reused; a doubt is raised when weight
//  is unknown / near a threshold / multi-item / high-value (admin thresholds),
//  NOT dispatched blind; a needs-ops flag + event fire; the seller suggests a
//  vehicle; admin (or seller) resolves → the final vehicle is written to the
//  delivery + the flag clears; a clear order does NOT raise a doubt; + wiring.
//  Run: php tests/commerce/pb8_vehicle_doubt_vectors.php   (local 10.11)
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
require_once SYSTEM_ROOT . '/lib/commerce/CommerceVehicle.php';
require_once SYSTEM_ROOT . '/lib/commerce/CommerceEventBus.php';
$pdo = Database::getConnection();

$PASS = 0; $FAIL = 0; $CFG = [];
function ok($label, $got, $exp = true) {
    global $PASS, $FAIL;
    $g = var_export($got, true); $e = var_export($exp, true);
    if ($g === $e) { $PASS++; printf("  PASS  %-54s %s\n", $label, $g); }
    else { $FAIL++; printf("  FAIL  %-54s got=%s exp=%s\n", $label, $g, $e); }
}
function has(string $h,string $n):bool{ return strpos($h,$n)!==false; }
function get(string $p):string{ return is_file($p)?(string)file_get_contents($p):''; }
function setCfg(PDO $p,$k,$v){ global $CFG; $s=$p->prepare("SELECT setting_value FROM app_settings WHERE setting_key=?"); $s->execute([$k]); $CFG[$k]=$s->fetchColumn(); $p->prepare("INSERT INTO app_settings (setting_key,setting_value,value_type,label,is_public) VALUES (?,?,'number','pb8',0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$k,$v]); }
register_shutdown_function(function() use ($pdo){ global $CFG; foreach($CFG as $k=>$v){ if($v===false) $pdo->prepare("DELETE FROM app_settings WHERE setting_key=?")->execute([$k]); else $pdo->prepare("UPDATE app_settings SET setting_value=? WHERE setting_key=?")->execute([$v,$k]); } });
function cleanup(PDO $p){
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $oids=$p->query("SELECT id FROM orders WHERE order_number LIKE 'PB8T-%'")->fetchAll(PDO::FETCH_COLUMN);
    if($oids){$in=implode(',',array_map('intval',$oids));
        $p->exec("DELETE FROM commerce_vehicle_doubts WHERE order_id IN ($in)");
        $p->exec("DELETE FROM commerce_events WHERE order_id IN ($in)");
        $p->exec("DELETE FROM commerce_order_flags WHERE order_id IN ($in)");
        $p->exec("DELETE FROM order_items WHERE order_id IN ($in)");
        $p->exec("DELETE FROM deliveries WHERE order_id IN ($in)");
        $p->exec("DELETE FROM orders WHERE id IN ($in)"); }
    $p->exec("DELETE i FROM inventory i JOIN products pr ON pr.id=i.product_id WHERE pr.slug LIKE 'pb8t-%'");
    $p->exec("DELETE FROM products WHERE slug LIKE 'pb8t-%'");
    $p->exec("DELETE FROM vendors WHERE slug LIKE 'pb8t-%'");
    $p->exec("DELETE FROM users   WHERE email LIKE 'pb8t-%@wtg.test'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('PB8',?, 'vendor_shopping','active',NOW(),NOW())")->execute([$e]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',1,10,NOW(),NOW())")->execute([$uid,'PB8 '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkOrder(PDO $p,$num,$uid,$parent,$v,$city,$total){ $p->prepare("INSERT INTO orders (order_number,parent_order_id,user_id,vendor_id,city_id,status,payment_status,payment_method,subtotal,total,shipping_address,delivery_status,ledger_status,created_at,updated_at) VALUES (?,?,?,?,?, 'confirmed','unpaid','cod',?,?,'{}','accepted','pending',NOW(),NOW())")->execute([$num,$parent,$uid,$v,$city,$total,$total]); return (int)$p->lastInsertId(); }
function mkDelivery(PDO $p,$oid,$weight){ $p->prepare("INSERT INTO deliveries (order_id,status,price,total_weight_kg,required_vehicle,created_at,updated_at) VALUES (?, 'pending',60,?,NULL,NOW(),NOW())")->execute([$oid,$weight]); return (int)$p->lastInsertId(); }
function mkItems(PDO $p,$oid,$v,$n){ global $PRODID; for($i=0;$i<$n;$i++) $p->prepare("INSERT INTO order_items (order_id,product_id,vendor_id,product_name,quantity,unit_price,line_total,created_at) VALUES (?,?,?,?,1,100,100,NOW())")->execute([$oid,$PRODID,$v,'PB8 item '.$i]); }
function flag(PDO $p,$oid){ return (string)$p->query("SELECT flag FROM deliveries WHERE order_id=$oid ORDER BY id DESC LIMIT 1")->fetchColumn(); }

cleanup($pdo);
$cityId=(int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if(!$cityId){ $pdo->exec("INSERT INTO cities (name,slug) VALUES ('PB8 City','pb8t-city')"); $cityId=(int)$pdo->lastInsertId(); }
$su=mkUser($pdo,'pb8t-s@wtg.test'); $V=mkVendor($pdo,$su,'pb8t-v');
// one real product (FK target for order_items); item COUNT is what decide() uses
$pdo->prepare("INSERT INTO products (vendor_id,city_id,name,slug,sku,price,status,created_at,updated_at) VALUES (?,?, 'PB8 Prod','pb8t-prod','pb8t-prod',100,'active',NOW(),NOW())")->execute([$V,$cityId]);
$PRODID=(int)$pdo->lastInsertId();
// deterministic thresholds for the test
setCfg($pdo,'commerce_vehicle_thresholds','[{"max_kg":20,"vehicle":"bike"},{"max_kg":80,"vehicle":"e_rickshaw"},{"max_kg":null,"vehicle":"loader"}]');
setCfg($pdo,'commerce_vehicle_doubt_near_pct','15');
setCfg($pdo,'commerce_vehicle_doubt_min_items','3');
setCfg($pdo,'commerce_high_value_threshold','15000');

echo "\n== [A] decide() reuses the weight→vehicle rule + flags doubt ==\n";
$oClear=mkOrder($pdo,'PB8T-CLEAR',$su,null,$V,$cityId,500); mkDelivery($pdo,$oClear,8.0); mkItems($pdo,$oClear,$V,1);
$dC=CommerceVehicle::decide($pdo,$oClear);
ok('8kg → bike', $dC['vehicle'], 'bike');
ok('8kg single item low value → confident', $dC['confident'], true);
$oNear=mkOrder($pdo,'PB8T-NEAR',$su,null,$V,$cityId,500); mkDelivery($pdo,$oNear,19.0); mkItems($pdo,$oNear,$V,1);
$dN=CommerceVehicle::decide($pdo,$oNear);
ok('19kg near the 20kg cut-off → NOT confident', $dN['confident'], false);
$oUnknown=mkOrder($pdo,'PB8T-UNK',$su,null,$V,$cityId,500); mkDelivery($pdo,$oUnknown,0.0); mkItems($pdo,$oUnknown,$V,1);
ok('weight unknown → NOT confident', CommerceVehicle::decide($pdo,$oUnknown)['confident'], false);
$oMulti=mkOrder($pdo,'PB8T-MULTI',$su,null,$V,$cityId,500); mkDelivery($pdo,$oMulti,8.0); mkItems($pdo,$oMulti,$V,4);
ok('4 items → NOT confident', CommerceVehicle::decide($pdo,$oMulti)['confident'], false);
$oHV=mkOrder($pdo,'PB8T-HV',$su,null,$V,$cityId,20000); mkDelivery($pdo,$oHV,8.0); mkItems($pdo,$oHV,$V,1);
ok('high-value → NOT confident', CommerceVehicle::decide($pdo,$oHV)['confident'], false);
ok('options come from thresholds', CommerceVehicle::options($pdo), ['bike','e_rickshaw','loader']);

echo "\n== [B] evaluateOnDispatch: clear dispatches, unclear opens a doubt ==\n";
ok('clear order → no doubt', CommerceVehicle::evaluateOnDispatch($pdo,$oClear)['vehicle_doubt'], false);
ok('clear order → no doubt row', CommerceVehicle::doubtFor($pdo,$oClear), null);
$ev=CommerceVehicle::evaluateOnDispatch($pdo,$oNear);
ok('near order → doubt raised', $ev['vehicle_doubt'], true);
ok('doubt row pending', CommerceVehicle::doubtFor($pdo,$oNear)['status'], 'pending');
ok('auto vehicle recorded (not dispatched blind)', CommerceVehicle::doubtFor($pdo,$oNear)['auto_vehicle'], 'bike');
ok('needs-ops flag raised (event→flag)', in_array('vehicle_doubt', CommerceEventBus::activeFlags($pdo,$oNear), true), true);
ok('idempotent: re-eval keeps ONE row', (function()use($pdo,$oNear){ CommerceVehicle::evaluateOnDispatch($pdo,$oNear); return (int)$pdo->query("SELECT COUNT(*) FROM commerce_vehicle_doubts WHERE order_id=$oNear")->fetchColumn(); })(), 1);

echo "\n== [C] seller suggests → admin overrides → resolved + dispatched ==\n";
ok('seller suggests a valid vehicle', CommerceVehicle::sellerSuggest($pdo,$oNear,$V,'e_rickshaw')['ok'], true);
ok('bad vehicle rejected', CommerceVehicle::sellerSuggest($pdo,$oNear,$V,'rocket')['reason'], 'bad_vehicle');
ok('suggestion recorded', CommerceVehicle::doubtFor($pdo,$oNear)['suggested_vehicle'], 'e_rickshaw');
$rv=CommerceVehicle::resolve($pdo,$oNear,'loader','admin',77); // admin OVERRIDES the seller
ok('admin override resolves', $rv['ok'], true);
ok('doubt now resolved', CommerceVehicle::doubtFor($pdo,$oNear)['status'], 'resolved');
ok('final vehicle written to delivery', (string)$pdo->query("SELECT required_vehicle FROM deliveries WHERE order_id=$oNear ORDER BY id DESC LIMIT 1")->fetchColumn(), 'loader');
ok('vehicle_doubt flag cleared', in_array('vehicle_doubt', CommerceEventBus::activeFlags($pdo,$oNear), true), false);

echo "\n== [D] queue lists pending doubts only ==\n";
$q = CommerceVehicle::queue($pdo);
ok('resolved order not in queue', (function()use($q,$oNear){ foreach($q as $r) if((int)$r['order_id']===$oNear) return true; return false; })(), false);
ok('an un-resolved doubt is in queue', (function()use($pdo,$oMulti){ CommerceVehicle::evaluateOnDispatch($pdo,$oMulti); foreach(CommerceVehicle::queue($pdo) as $r) if((int)$r['order_id']===$oMulti) return true; return false; })(), true);

echo "\n== [E] wiring ==\n";
$sell=get(SYSTEM_ROOT.'/heart/api/commerce-seller/index.php');
$adm=get(SYSTEM_ROOT.'/heart/api/commerce-admin/index.php');
$sj=get(SYSTEM_ROOT.'/seller/seller.js');
$bus=get(SYSTEM_ROOT.'/lib/commerce/CommerceEventBus.php');
ok('seller accept evaluates the vehicle', has($sell,'CommerceVehicle::evaluateOnDispatch'), true);
ok('seller suggest route', has($sell,'/vehicle')&&has($sell,'sellerSuggest'), true);
ok('admin doubts + resolve routes', has($adm,'/vehicle-doubts')&&has($adm,'CommerceVehicle::resolve'), true);
ok('seller UI prompts for a vehicle', has($sj,'vehiclePrompt')&&has($sj,'suggestVehicle'), true);
ok('vehicle_doubt feeds the ops flag rules', has($bus,"'vehicle_doubt'   => 'vehicle_doubt'"), true);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("PB8 VEHICLE-DOUBT RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
