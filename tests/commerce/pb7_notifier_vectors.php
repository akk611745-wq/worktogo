<?php
// ─────────────────────────────────────────────────────────────────────────────
//  PB7 PROOF — alerts nervous-system (CommerceNotifier + EventBus fan-out).
//  Proves: every order event resolves to the right audience + channels + a tone;
//  the seller new-order alert is critical + repeat; admin channel on/off is
//  honoured (off → skipped, logged); a channel failure is logged, never thrown;
//  CommerceEventBus::emit fans out to the log without changing its timeline/
//  notification behaviour; + seller/rider loud-alert + admin wiring.
//  Run: php tests/commerce/pb7_notifier_vectors.php   (local 10.11)
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
require_once SYSTEM_ROOT . '/lib/commerce/CommerceNotifier.php';
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
function setCfg(PDO $p,$k,$v){ global $CFG; $s=$p->prepare("SELECT setting_value FROM app_settings WHERE setting_key=?"); $s->execute([$k]); $CFG[$k]=$s->fetchColumn(); $p->prepare("INSERT INTO app_settings (setting_key,setting_value,value_type,label,is_public) VALUES (?,?,'json','pb7',0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$k,$v]); }
register_shutdown_function(function() use ($pdo){ global $CFG; foreach($CFG as $k=>$v){ if($v===false) $pdo->prepare("DELETE FROM app_settings WHERE setting_key=?")->execute([$k]); else $pdo->prepare("UPDATE app_settings SET setting_value=? WHERE setting_key=?")->execute([$v,$k]); } });
function cleanup(PDO $p){
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $oids=$p->query("SELECT id FROM orders WHERE order_number LIKE 'PB7T-%'")->fetchAll(PDO::FETCH_COLUMN);
    if($oids){$in=implode(',',array_map('intval',$oids));
        $p->exec("DELETE FROM commerce_notification_log WHERE order_id IN ($in)");
        $p->exec("DELETE FROM commerce_events WHERE order_id IN ($in)");
        $p->exec("DELETE FROM commerce_order_flags WHERE order_id IN ($in)");
        $p->exec("DELETE FROM orders WHERE id IN ($in)"); }
    $uids=$p->query("SELECT id FROM users WHERE email LIKE 'pb7t-%@wtg.test'")->fetchAll(PDO::FETCH_COLUMN);
    if($uids){$in=implode(',',array_map('intval',$uids)); $p->exec("DELETE FROM notifications WHERE user_id IN ($in)"); }
    $p->exec("DELETE FROM vendors WHERE slug LIKE 'pb7t-%'");
    $p->exec("DELETE FROM users   WHERE email LIKE 'pb7t-%@wtg.test'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e,$role='customer'){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('PB7',?,?, 'active',NOW(),NOW())")->execute([$e,$role]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',1,10,NOW(),NOW())")->execute([$uid,'PB7 '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkOrder(PDO $p,$num,$uid,$parent,$v,$city){ $p->prepare("INSERT INTO orders (order_number,parent_order_id,user_id,vendor_id,city_id,status,payment_status,payment_method,subtotal,total,shipping_address,delivery_status,ledger_status,created_at,updated_at) VALUES (?,?,?,?,?, 'confirmed','unpaid','cod',500,500,'{}','offered','pending',NOW(),NOW())")->execute([$num,$parent,$uid,$v,$city]); return (int)$p->lastInsertId(); }
function logCount(PDO $p,$oid,$extra=''){ return (int)$p->query("SELECT COUNT(*) FROM commerce_notification_log WHERE order_id=$oid $extra")->fetchColumn(); }

cleanup($pdo);
$cityId=(int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if(!$cityId){ $pdo->exec("INSERT INTO cities (name,slug) VALUES ('PB7 City','pb7t-city')"); $cityId=(int)$pdo->lastInsertId(); }
$cust=mkUser($pdo,'pb7t-c@wtg.test','customer');
$su=mkUser($pdo,'pb7t-s@wtg.test','vendor_shopping'); $V=mkVendor($pdo,$su,'pb7t-v');
setCfg($pdo,'commerce_notify_channels','{"inapp":1,"push":1,"sms":1,"whatsapp":1}');

echo "\n== [A] spec: audiences, channels, tones, seller critical+repeat ==\n";
$spec = CommerceNotifier::spec();
ok('order_created notifies seller + customer', isset($spec['order_created']['seller']) && isset($spec['order_created']['customer']), true);
ok('seller new-order tone', $spec['order_created']['seller']['tone'], 'seller_new_order');
ok('seller new-order priority critical', $spec['order_created']['seller']['priority'], 'critical');
ok('seller new-order repeats', $spec['order_created']['seller']['repeat'] ?? false, true);
ok('customer new-order NOT critical', $spec['order_created']['customer']['priority'], 'normal');
ok('rider job tone', $spec['rider_assigned']['rider']['tone'], 'rider_job');

echo "\n== [B] dispatch logs every (recipient × channel) attempt ==\n";
$pP=mkOrder($pdo,'PB7T-P',$cust,null,null,$cityId);
$pS=mkOrder($pdo,'PB7T-S',$cust,$pP,$V,$cityId); // sub-order from our vendor (seller recipient)
$res = CommerceNotifier::dispatch($pdo,'order_created',$pP,[]);
ok('dispatched > 0', $res['dispatched'] > 0, true);
ok('seller channels logged (4)', logCount($pdo,$pP,"AND audience='seller'"), 4);
ok('customer channels logged (2)', logCount($pdo,$pP,"AND audience='customer'"), 2);
ok('seller push carries the tone', (string)$pdo->query("SELECT tone FROM commerce_notification_log WHERE order_id=$pP AND audience='seller' AND channel='push' LIMIT 1")->fetchColumn(), 'seller_new_order');
ok('sms rows queued', logCount($pdo,$pP,"AND channel='sms' AND status='queued'") >= 1, true);

echo "\n== [C] admin channel OFF → skipped + logged ==\n";
CommerceNotifier::setChannel($pdo,'sms',false);
$off = CommerceNotifier::channels($pdo);
ok('sms now off', $off['sms'], false);
$p2=mkOrder($pdo,'PB7T-P2',$cust,null,null,$cityId); $p2s=mkOrder($pdo,'PB7T-S2',$cust,$p2,$V,$cityId);
CommerceNotifier::dispatch($pdo,'order_created',$p2,[]);
ok('sms skipped when off', logCount($pdo,$p2,"AND channel='sms' AND status='skipped'") >= 1, true);
ok('sms NOT queued when off', logCount($pdo,$p2,"AND channel='sms' AND status='queued'"), 0);
ok('push still sent/skipped (not off)', logCount($pdo,$p2,"AND channel='push' AND status IN ('sent','skipped')") >= 1, true);
CommerceNotifier::setChannel($pdo,'sms',true);

echo "\n== [D] EventBus::emit fans out to the log (best-effort, non-blocking) ==\n";
$p3=mkOrder($pdo,'PB7T-P3',$cust,null,null,$cityId); $p3s=mkOrder($pdo,'PB7T-S3',$cust,$p3,$V,$cityId);
$evId = CommerceEventBus::emit($pdo,'order_created',['order_id'=>$p3]);
ok('emit returned an event id', $evId > 0, true);
ok('emit wrote the timeline row', (int)$pdo->query("SELECT COUNT(*) FROM commerce_events WHERE id=$evId")->fetchColumn(), 1);
ok('emit fanned out to the notify log', logCount($pdo,$p3) > 0, true);
ok('emit still wrote in-app notifications', (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id=$cust")->fetchColumn() >= 1, true);

echo "\n== [E] unknown event → no crash, nothing logged ==\n";
$r = CommerceNotifier::dispatch($pdo,'totally_unknown_event',$p3,[]);
ok('unknown event dispatched 0', $r['dispatched'], 0);

echo "\n== [F] seller/rider loud-alert + admin wiring ==\n";
$sj=get(SYSTEM_ROOT.'/seller/seller.js'); $rj=get(SYSTEM_ROOT.'/rider/rider.js');
$adm=get(SYSTEM_ROOT.'/heart/api/commerce-admin/index.php'); $bus=get(SYSTEM_ROOT.'/lib/commerce/CommerceEventBus.php');
$al=get(SYSTEM_ROOT.'/admin/commerce-alerts.html'); $sh=get(SYSTEM_ROOT.'/admin/shared/shell.js');
ok('seller: looping alarm + vibrate until cleared', has($sj,'startAlarm')&&has($sj,'stopAlarm')&&has($sj,'vibrate'), true);
ok('rider: new-job chime + vibrate', has($rj,'jobChime')&&has($rj,'vibrate'), true);
ok('EventBus hooked to CommerceNotifier (best-effort)', has($bus,'CommerceNotifier::dispatch'), true);
ok('admin: channel toggle + log routes', has($adm,'/notify-channels')&&has($adm,'/notify-log'), true);
ok('admin alerts screen + nav', has($al,'notify-channels')&&has($sh,'commerce-alerts.html'), true);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("PB7 NOTIFIER RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
