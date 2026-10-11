<?php
// ─────────────────────────────────────────────────────────────────────────────
//  F1 PROOF HARNESS — routing source-preference knob (nearest|cheapest|reliable).
//  Same product at 3 sellers (different price/distance/reliability): prove each
//  mode picks the right top source, company-first stays the invariant, and the
//  default 'nearest' reproduces the original order. Idempotent (markers sf1t-*).
//  Run: php tests/commerce/sf1_routing_source_vectors.php
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
function ok($label, $got, $exp) {
    global $PASS, $FAIL;
    if ($got === $exp) { $PASS++; printf("  PASS  %-46s got=%s\n", $label, var_export($got, true)); }
    else { $FAIL++; printf("  FAIL  %-46s got=%s exp=%s\n", $label, var_export($got, true), var_export($exp, true)); }
}
function setcfg(PDO $p, $v) { $p->prepare("INSERT INTO app_settings (setting_key,setting_value,value_type,is_public) VALUES ('commerce_routing_source_preference',?, 'text',0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$v]); }
function cleanup(PDO $p) {
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $p->exec("DELETE crs FROM commerce_routing_signals crs JOIN vendors v ON v.id=crs.vendor_id WHERE v.slug LIKE 'sf1t-%'");
    $p->exec("DELETE i FROM inventory i JOIN products pr ON pr.id=i.product_id WHERE pr.slug LIKE 'sf1t-%'");
    $p->exec("DELETE FROM products WHERE slug LIKE 'sf1t-%'");
    $p->exec("DELETE FROM stores   WHERE slug LIKE 'sf1t-%'");
    $p->exec("DELETE FROM vendors  WHERE slug LIKE 'sf1t-%'");
    $p->exec("DELETE FROM users    WHERE email LIKE 'sf1t-%@wtg.test'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('F1',?, 'vendor_shopping','active',NOW(),NOW())")->execute([$e]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',1,10,NOW(),NOW())")->execute([$uid,'F1 '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkStore(PDO $p,$slug,$own,$ownerV,$city,$lat,$lng,$prio){ $p->prepare("INSERT INTO stores (ownership,owner_vendor_id,city_id,name,slug,status,lat,lng,service_radius_km,priority,created_at,updated_at) VALUES (?,?,?,?,?,'active',?,?,80,?,NOW(),NOW())")->execute([$own,$ownerV,$city,'F1 S '.$slug,$slug,$lat,$lng,$prio]); return (int)$p->lastInsertId(); }
function mkProd(PDO $p,$v,$st,$city,$mk,$slug,$price){ $p->prepare("INSERT INTO products (vendor_id,store_id,city_id,name,slug,sku,match_key,price,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,'active',NOW(),NOW())")->execute([$v,$st,$city,'F1 P '.$slug,$slug,$slug,$mk,$price]); $pid=(int)$p->lastInsertId(); $p->prepare("INSERT INTO inventory (product_id,quantity,reserved,low_stock_alert,track_inventory,allow_backorder,updated_at) VALUES (?,50,0,5,1,0,NOW())")->execute([$pid]); return $pid; }
function sig(PDO $p,$vendor,$event,$n){ for($i=0;$i<$n;$i++) $p->prepare("INSERT INTO commerce_routing_signals (order_id,sub_order_id,store_id,vendor_id,event,created_at) VALUES (0,0,0,?,?,NOW())")->execute([$vendor,$event]); }
function topStore(PDO $p,$city,$lat,$lng){ $r=RoutingEngine::eligibleStores($p,'sf1t-mk',1,$city,$lat,$lng,null); return $r ? (int)$r[0]['store_id'] : 0; }

cleanup($pdo);
$snap = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='commerce_routing_source_preference'")->fetchColumn();
register_shutdown_function(function() use($pdo,$snap){ if($snap!==false&&$snap!==null){ try{ $pdo->prepare("UPDATE app_settings SET setting_value=? WHERE setting_key='commerce_routing_source_preference'")->execute([$snap]); }catch(\Throwable $e){} } });

$cityId=(int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if(!$cityId){ $pdo->exec("INSERT INTO cities (name,slug) VALUES ('F1 City','sf1t-city')"); $cityId=(int)$pdo->lastInsertId(); }
// customer location = (29.20,79.50) == store B's location (B is nearest)
$CLAT=29.20; $CLNG=79.50;
$uA=mkUser($pdo,'sf1t-a@wtg.test'); $VA=mkVendor($pdo,$uA,'sf1t-va'); $SA=mkStore($pdo,'sf1t-sa','vendor',$VA,$cityId,29.25,79.55,5); mkProd($pdo,$VA,$SA,$cityId,'sf1t-mk','sf1t-pa',90);   // far, cheapest, reliable
$uB=mkUser($pdo,'sf1t-b@wtg.test'); $VB=mkVendor($pdo,$uB,'sf1t-vb'); $SB=mkStore($pdo,'sf1t-sb','vendor',$VB,$cityId,29.20,79.50,9); mkProd($pdo,$VB,$SB,$cityId,'sf1t-mk','sf1t-pb',110);  // nearest, dearest, unreliable
$uC=mkUser($pdo,'sf1t-c@wtg.test'); $VC=mkVendor($pdo,$uC,'sf1t-vc'); $SC=mkStore($pdo,'sf1t-sc','vendor',$VC,$cityId,29.22,79.52,1); mkProd($pdo,$VC,$SC,$cityId,'sf1t-mk','sf1t-pc',100);  // mid
sig($pdo,$VA,'accepted',8);                              // A: 8/8 = 1.0 reliability
sig($pdo,$VB,'accepted',1); sig($pdo,$VB,'rejected',7);  // B: 1/8 = 0.125 reliability

echo "\n== default 'nearest' → nearest store (B); behaviour unchanged ==\n";
setcfg($pdo,'nearest');
ok('nearest → B (closest)',  topStore($pdo,$cityId,$CLAT,$CLNG), $SB);

echo "\n== 'cheapest' → lowest-price store (A @90) ==\n";
setcfg($pdo,'cheapest');
ok('cheapest → A (₹90)',     topStore($pdo,$cityId,$CLAT,$CLNG), $SA);

echo "\n== 'reliable' → highest accept-ratio (A) over nearest-but-unreliable B ==\n";
setcfg($pdo,'reliable');
ok('reliable → A (1.0 ratio)', topStore($pdo,$cityId,$CLAT,$CLNG), $SA);

echo "\n== unknown/empty pref → falls back to nearest ==\n";
setcfg($pdo,'wobble');
ok('bad pref → nearest (B)',  topStore($pdo,$cityId,$CLAT,$CLNG), $SB);

echo "\n== company-first invariant holds in every mode ==\n";
$SD=mkStore($pdo,'sf1t-sd','company',null,$cityId,29.40,79.70,0); mkProd($pdo,$VA,$SD,$cityId,'sf1t-mk','sf1t-pd',999); // company: far + dearest
setcfg($pdo,'cheapest');
ok('cheapest but company first (D)', topStore($pdo,$cityId,$CLAT,$CLNG), $SD);
setcfg($pdo,'reliable');
ok('reliable but company first (D)', topStore($pdo,$cityId,$CLAT,$CLNG), $SD);
setcfg($pdo,'nearest');
ok('nearest but company first (D)',  topStore($pdo,$cityId,$CLAT,$CLNG), $SD);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("F1 RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
