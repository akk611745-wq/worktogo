<?php
// ─────────────────────────────────────────────────────────────────────────────
//  PB6 PROOF — commerce lifecycle cron: offer + quote timeouts.
//  Proves: expireStaleOffers reroutes a seller offer that timed out (→ next →
//  company fallback); the NEW quote-timeout seam expireStaleQuotes reroutes a
//  custom-tint quote the seller didn't price in commerce_quote_timeout_sec
//  (default 180 = 3 min), keyed off the 'quote_offered' signal, and is idempotent
//  + leaves a FRESH quote alone; config defaults; + cron file wiring (flock lock,
//  both sweeps, CLI/secret gate, Windows .bat, host crontab line).
//  Run: php tests/commerce/pb6_commerce_cron_vectors.php   (local 10.11)
// ─────────────────────────────────────────────────────────────────────────────
define('SYSTEM_ROOT', dirname(dirname(__DIR__)));
foreach (file(SYSTEM_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2); $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
    if (getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
}
require_once SYSTEM_ROOT . '/core/helpers/Database.php';
require_once SYSTEM_ROOT . '/lib/commerce/RoutingEngine.php';
$pdo = Database::getConnection();

$PASS = 0; $FAIL = 0;
function ok($label, $got, $exp = true) {
    global $PASS, $FAIL;
    $g = var_export($got, true); $e = var_export($exp, true);
    if ($g === $e) { $PASS++; printf("  PASS  %-52s %s\n", $label, $g); }
    else { $FAIL++; printf("  FAIL  %-52s got=%s exp=%s\n", $label, $g, $e); }
}
function has(string $h,string $n):bool{ return strpos($h,$n)!==false; }
function get(string $p):string{ return is_file($p)?(string)file_get_contents($p):''; }
function cleanup(PDO $p) {
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $oids = $p->query("SELECT id FROM orders WHERE order_number LIKE 'PB6T-%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($oids) { $in = implode(',', array_map('intval', $oids));
        $p->exec("DELETE FROM commerce_routing_signals WHERE order_id IN ($in) OR sub_order_id IN ($in)");
        $p->exec("DELETE FROM order_items WHERE order_id IN ($in)");
        $p->exec("DELETE FROM orders WHERE id IN ($in)"); }
    $p->exec("DELETE i FROM inventory i JOIN products pr ON pr.id=i.product_id WHERE pr.slug LIKE 'pb6t-%'");
    $p->exec("DELETE FROM products WHERE slug LIKE 'pb6t-%'");
    $p->exec("DELETE FROM stores   WHERE slug LIKE 'pb6t-%'");
    $p->exec("DELETE FROM vendors  WHERE slug LIKE 'pb6t-%'");
    $p->exec("DELETE FROM users    WHERE email LIKE 'pb6t-%@wtg.test'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('PB6',?, 'vendor_shopping','active',NOW(),NOW())")->execute([$e]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',1,10,NOW(),NOW())")->execute([$uid,'PB6 V '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkStore(PDO $p,$slug,$own,$ownerV,$city,$lat,$lng){ $p->prepare("INSERT INTO stores (ownership,owner_vendor_id,city_id,name,slug,status,lat,lng,service_radius_km,created_at,updated_at) VALUES (?,?,?,?,?,'active',?,?,NULL,NOW(),NOW())")->execute([$own,$ownerV,$city,'PB6 S '.$slug,$slug,$lat,$lng]); return (int)$p->lastInsertId(); }
function mkProd(PDO $p,$vendorId,$storeId,$city,$matchKey,$slug,$qty){ $p->prepare("INSERT INTO products (vendor_id,store_id,city_id,name,slug,sku,match_key,price,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,199.00,'active',NOW(),NOW())")->execute([$vendorId,$storeId,$city,'PB6 P '.$slug,$slug,$slug,$matchKey]); $pid=(int)$p->lastInsertId(); $p->prepare("INSERT INTO inventory (product_id,quantity,reserved,low_stock_alert,track_inventory,allow_backorder,updated_at) VALUES (?,?,0,5,1,0,NOW())")->execute([$pid,$qty]); return $pid; }
function mkOrder(PDO $p,$num,$uid,$parent,$vendor,$store,$city,$ds){ $ds=$ds??'pending'; $p->prepare("INSERT INTO orders (order_number,parent_order_id,user_id,vendor_id,store_id,city_id,status,payment_status,payment_method,subtotal,total,shipping_address,delivery_status,ledger_status,created_at,updated_at) VALUES (?,?,?,?,?,?, 'pending','unpaid','cod',199.00,199.00,'{}',?,'pending',NOW(),NOW())")->execute([$num,$parent,$uid,$vendor,$store,$city,$ds]); return (int)$p->lastInsertId(); }
function mkItem(PDO $p,$oid,$pid,$vendor,$qty){ $p->prepare("INSERT INTO order_items (order_id,product_id,vendor_id,product_name,quantity,unit_price,line_total,created_at) VALUES (?,?,?,?,?,199.00,199.00,NOW())")->execute([$oid,$pid,$vendor,'PB6 item',$qty]); }
function sig(PDO $p,$order,$sub,$store,$vendor,$event,$ageMin){ $p->prepare("INSERT INTO commerce_routing_signals (order_id,sub_order_id,store_id,vendor_id,event,created_at) VALUES (?,?,?,?,?, NOW() - INTERVAL ? MINUTE)")->execute([$order,$sub,$store,$vendor,$event,$ageMin]); }
function storeOf(PDO $p,$oid){ return (int)$p->query("SELECT store_id FROM orders WHERE id=$oid")->fetchColumn(); }
function sigCount(PDO $p,$sub,$event){ return (int)$p->query("SELECT COUNT(*) FROM commerce_routing_signals WHERE sub_order_id=$sub AND event='$event'")->fetchColumn(); }

cleanup($pdo);
$cityId=(int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if(!$cityId){ $pdo->exec("INSERT INTO cities (name,slug) VALUES ('PB6 City','pb6t-city')"); $cityId=(int)$pdo->lastInsertId(); }
$uc=mkUser($pdo,'pb6t-c@wtg.test');
$uco=mkUser($pdo,'pb6t-co@wtg.test'); $vCo=mkVendor($pdo,$uco,'pb6t-co');
$uon=mkUser($pdo,'pb6t-on@wtg.test'); $vON=mkVendor($pdo,$uon,'pb6t-on');
$stCompany=mkStore($pdo,'pb6t-company','company',null,$cityId,29.2105,79.5105);
$stNear   =mkStore($pdo,'pb6t-near','vendor',$vON,$cityId,29.2110,79.5110);
// shared match keys so a reroute has a company target
mkProd($pdo,$vCo,$stCompany,$cityId,'MK-Q','pb6t-q-co',10);
$pQnear=mkProd($pdo,$vON,$stNear,$cityId,'MK-Q','pb6t-q-ne',10);
mkProd($pdo,$vCo,$stCompany,$cityId,'MK-O','pb6t-o-co',10);
$pOnear=mkProd($pdo,$vON,$stNear,$cityId,'MK-O','pb6t-o-ne',10);

echo "\n== [A] config defaults (admin-tunable, nothing hardcoded) ==\n";
$qt=$pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='commerce_quote_timeout_sec'")->fetchColumn();
ok('commerce_quote_timeout_sec seeded = 180', (int)$qt, 180);
ok('RoutingEngine::quoteTimeoutSec() = 180', RoutingEngine::quoteTimeoutSec($pdo), 180);
$le=$pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='commerce_lifecycle_enabled'")->fetchColumn();
ok('commerce_lifecycle_enabled seeded = 1', (string)$le, '1');

echo "\n== [B] expireStaleOffers: timed-out offer → reroute to company ==\n";
$oP=mkOrder($pdo,'PB6T-OF-P',$uc,null,null,null,$cityId,null);
$oS=mkOrder($pdo,'PB6T-OF-S',$uc,$oP,$vON,$stNear,$cityId,'offered');
mkItem($pdo,$oS,$pOnear,$vON,1);
sig($pdo,$oP,$oS,$stNear,$vON,'offered',10); // 10 min old > 120s
ok('stale offer processed >=1', RoutingEngine::expireStaleOffers($pdo) >= 1, true);
ok('timeout signal logged', sigCount($pdo,$oS,'timeout'), 1);
ok('offer rerouted to company', storeOf($pdo,$oS), $stCompany);

echo "\n== [C] expireStaleQuotes: timed-out quote → reroute (PB6 seam) ==\n";
$qP=mkOrder($pdo,'PB6T-QT-P',$uc,null,null,null,$cityId,null);
$qS=mkOrder($pdo,'PB6T-QT-S',$uc,$qP,$vON,$stNear,$cityId,'offered');
mkItem($pdo,$qS,$pQnear,$vON,1);
sig($pdo,$qP,$qS,$stNear,$vON,'quote_offered',10); // 10 min old > 180s
$expired = RoutingEngine::expireStaleQuotes($pdo);
ok('stale quote processed >=1', $expired >= 1, true);
ok('quote_timeout signal logged', sigCount($pdo,$qS,'quote_timeout'), 1);
ok('quote rerouted to company', storeOf($pdo,$qS), $stCompany);

echo "\n== [D] idempotent: a second sweep does not re-process it ==\n";
$before = sigCount($pdo,$qS,'quote_timeout');
RoutingEngine::expireStaleQuotes($pdo);
ok('no duplicate quote_timeout on re-run', sigCount($pdo,$qS,'quote_timeout'), $before);

echo "\n== [E] a FRESH quote is left alone ==\n";
$fP=mkOrder($pdo,'PB6T-FR-P',$uc,null,null,null,$cityId,null);
$fS=mkOrder($pdo,'PB6T-FR-S',$uc,$fP,$vON,$stNear,$cityId,'offered');
mkItem($pdo,$fS,$pQnear,$vON,1);
sig($pdo,$fP,$fS,$stNear,$vON,'quote_offered',0); // just now (< 180s)
RoutingEngine::expireStaleQuotes($pdo);
ok('fresh quote NOT timed out', sigCount($pdo,$fS,'quote_timeout'), 0);
ok('fresh quote still at its seller', storeOf($pdo,$fS), $stNear);

echo "\n== [F] cron wiring (flock lock, both sweeps, gate, bat, crontab) ==\n";
$cron = get(SYSTEM_ROOT.'/cron/commerce_lifecycle.php');
$bat  = get(SYSTEM_ROOT.'/cron/commerce_lifecycle.bat');
$doc  = get(SYSTEM_ROOT.'/system-brain/LAUNCH/server-day-checklist.md');
ok('cron runs both sweeps', has($cron,'expireStaleOffers')&&has($cron,'expireStaleQuotes'), true);
ok('cron is non-overlap locked (flock)', has($cron,'LOCK_EX')&&has($cron,'LOCK_NB'), true);
ok('cron CLI-or-secret gated', has($cron,"php_sapi_name")&&has($cron,'CRON_SECRET'), true);
ok('cron master switch honored', has($cron,'commerce_lifecycle_enabled'), true);
ok('Windows Task Scheduler .bat present', has($bat,'commerce_lifecycle.php')&&has($bat,'schtasks'), true);
ok('host crontab line documented', has($doc,'cron/commerce_lifecycle.php'), true);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("PB6 COMMERCE-CRON RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
