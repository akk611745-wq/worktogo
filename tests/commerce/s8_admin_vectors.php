<?php
// ─────────────────────────────────────────────────────────────────────────────
//  S8 PROOF HARNESS — AdminControlRoom: live config + audit, kill-switches (all
//  scopes, enforced via OrderabilityResolver), loss-proof, force-status override,
//  money dashboard. Run: php tests/commerce/s8_admin_vectors.php
// ─────────────────────────────────────────────────────────────────────────────
define('SYSTEM_ROOT', dirname(dirname(__DIR__)));
foreach (file(SYSTEM_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2); $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
    if (getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
}
require_once SYSTEM_ROOT . '/core/helpers/Database.php';
require_once SYSTEM_ROOT . '/lib/commerce/AdminControlRoom.php';
require_once SYSTEM_ROOT . '/lib/commerce/OrderabilityResolver.php';
$pdo = Database::getConnection();

$PASS=0;$FAIL=0;
function ok($l,$g,$e){ global $PASS,$FAIL; $g=is_bool($g)?var_export($g,true):(string)$g; $e=is_bool($e)?var_export($e,true):(string)$e;
  if($g===$e){$PASS++;printf("  PASS  %-46s %s\n",$l,$g);}else{$FAIL++;printf("  FAIL  %-46s got=%s exp=%s\n",$l,$g,$e);} }
function reason(PDO $p,$pid,$city){ return OrderabilityResolver::check($p,$pid,$city)['reason']; }
function cleanup(PDO $p){
  // always restore global switches so a crash never leaves commerce paused
  $p->exec("UPDATE app_settings SET setting_value='1' WHERE setting_key='commerce_enabled'");
  $p->exec("UPDATE app_settings SET setting_value='{\"categories\":[],\"cities\":[],\"stores\":[]}' WHERE setting_key='commerce_paused'");
  $p->exec("UPDATE app_settings SET setting_value='5000' WHERE setting_key='commerce_high_value_threshold'");
  $p->exec("SET FOREIGN_KEY_CHECKS=0");
  $oids=$p->query("SELECT id FROM orders WHERE order_number LIKE 'S8T-%'")->fetchAll(PDO::FETCH_COLUMN);
  if($oids){$in=implode(',',array_map('intval',$oids));
    $p->exec("DELETE FROM wallet_transactions WHERE reference_type='order' AND reference_id IN ($in)");
    $p->exec("DELETE FROM commerce_events WHERE order_id IN ($in)");
    $p->exec("DELETE FROM orders WHERE id IN ($in)"); }
  $p->exec("DELETE FROM commerce_config_audit WHERE reason LIKE 's8t-%' OR target LIKE '%:0'");
  $uids=$p->query("SELECT id FROM users WHERE email LIKE 's8t-%@wtg.test'")->fetchAll(PDO::FETCH_COLUMN);
  if($uids){$in=implode(',',array_map('intval',$uids)); $p->exec("DELETE FROM delivery_riders WHERE rider_id IN ($in)"); $p->exec("DELETE FROM vendor_wallets WHERE vendor_id IN (SELECT id FROM vendors WHERE slug LIKE 's8t-%')"); $p->exec("DELETE FROM driver_wallets WHERE driver_id IN ($in)"); $p->exec("DELETE FROM users WHERE id IN ($in)"); }
  $p->exec("DELETE i FROM inventory i JOIN products pr ON pr.id=i.product_id WHERE pr.slug LIKE 's8t-%'");
  $p->exec("DELETE FROM products WHERE slug LIKE 's8t-%'");
  $p->exec("DELETE FROM stores WHERE slug LIKE 's8t-%'");
  $p->exec("DELETE FROM vendors WHERE slug LIKE 's8t-%'");
  $p->exec("DELETE FROM categories WHERE slug LIKE 's8t-%'");
  $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e,$r='customer'){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('S8',?,?, 'active',NOW(),NOW())")->execute([$e,$r]); return (int)$p->lastInsertId(); }
function mkCat(PDO $p,$slug){ $p->prepare("INSERT INTO categories (name,slug,type,status,created_at,updated_at) VALUES (?,?,'product','active',NOW(),NOW())")->execute(['S8 '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkStore(PDO $p,$slug,$city){ $p->prepare("INSERT INTO stores (ownership,city_id,name,slug,status,created_at,updated_at) VALUES ('company',?,?,?,'active',NOW(),NOW())")->execute([$city,'S8 S '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkProd(PDO $p,$store,$city,$cat,$slug){ $p->prepare("INSERT INTO products (vendor_id,store_id,city_id,category_id,name,slug,sku,price,status,created_at,updated_at) VALUES (1,?,?,?,?,?,?,199,'active',NOW(),NOW())")->execute([$store,$city,$cat,'S8 P '.$slug,$slug,$slug]); $pid=(int)$p->lastInsertId(); $p->prepare("INSERT INTO inventory (product_id,quantity,reserved,low_stock_alert,track_inventory,allow_backorder,updated_at) VALUES (?,10,0,5,1,0,NOW())")->execute([$pid]); return $pid; }
function mkOrder(PDO $p,$num,$uid,$total){ $p->prepare("INSERT INTO orders (order_number,user_id,status,payment_status,payment_method,subtotal,total,shipping_address,ledger_status,created_at,updated_at) VALUES (?,?, 'pending','unpaid','cod',?,?,'{}','pending',NOW(),NOW())")->execute([$num,$uid,$total,$total]); return (int)$p->lastInsertId(); }
function wtx(PDO $p,$entity,$eid,$oid,$amt){ $p->prepare("INSERT INTO wallet_transactions (entity_type,entity_id,order_id,reference_type,reference_id,type,amount,status,created_at) VALUES (?,?,?,'order',?, 'credit',?, 'settled',NOW())")->execute([$entity,$eid,$oid,$oid,$amt]); }
function ocol(PDO $p,$oid,$c){ $s=$p->prepare("SELECT $c FROM orders WHERE id=?"); $s->execute([$oid]); return $s->fetchColumn(); }

cleanup($pdo);
$cityId=(int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if(!$cityId){ $pdo->exec("INSERT INTO cities (name,slug) VALUES ('S8 City','s8t-city')"); $cityId=(int)$pdo->lastInsertId(); }
$admin=mkUser($pdo,'s8t-admin@wtg.test','admin'); $cust=mkUser($pdo,'s8t-c@wtg.test');
$cat=mkCat($pdo,'s8t-cat'); $store=mkStore($pdo,'s8t-store',$cityId); $prod=mkProd($pdo,$store,$cityId,$cat,'s8t-prod');

echo "\n== live config + audit ==\n";
$r=AdminControlRoom::setConfig($pdo,'commerce_high_value_threshold','7000',$admin,'s8t-tune');
ok('A1 setConfig ok', $r['ok'], true);
ok('A1 getConfig reflects live', (string)AdminControlRoom::getConfig($pdo)['commerce_high_value_threshold'], '7000');
ok('A1 non-editable key rejected', AdminControlRoom::setConfig($pdo,'jwt_secret','x',$admin)['ok'] ?? false, false);

echo "\n== kill-switches (enforced in orderability gate) ==\n";
ok('A0 baseline orderable', reason($pdo,$prod,$cityId), 'ok');
AdminControlRoom::pause($pdo,'commerce',null,$admin,'s8t'); ok('A2 global pause → commerce_paused', reason($pdo,$prod,$cityId), 'commerce_paused');
AdminControlRoom::resume($pdo,'commerce',null,$admin); ok('A2 resume → ok', reason($pdo,$prod,$cityId), 'ok');
AdminControlRoom::pause($pdo,'unit',$cat,$admin,'s8t'); ok('A3 unit pause → unit_paused', reason($pdo,$prod,$cityId), 'unit_paused');
AdminControlRoom::resume($pdo,'unit',$cat,$admin); ok('A3 unit resume → ok', reason($pdo,$prod,$cityId), 'ok');
AdminControlRoom::pause($pdo,'zone',$cityId,$admin,'s8t'); ok('A4 zone pause → zone_paused', reason($pdo,$prod,$cityId), 'zone_paused');
AdminControlRoom::resume($pdo,'zone',$cityId,$admin); ok('A4 zone resume → ok', reason($pdo,$prod,$cityId), 'ok');
AdminControlRoom::pause($pdo,'seller',$store,$admin,'s8t'); ok('A5 seller pause → seller_paused', reason($pdo,$prod,$cityId), 'seller_paused');
ok('A5 store status suspended', (string)$pdo->query("SELECT status FROM stores WHERE id=$store")->fetchColumn(), 'suspended');
AdminControlRoom::resume($pdo,'seller',$store,$admin); ok('A5 seller resume → ok', reason($pdo,$prod,$cityId), 'ok');
$rider=mkUser($pdo,'s8t-rider@wtg.test','driver');
AdminControlRoom::pause($pdo,'rider',$rider,$admin,'s8t');
ok('A6 rider pause → is_online 0', (string)$pdo->query("SELECT is_online FROM delivery_riders WHERE rider_id=$rider")->fetchColumn(), '0');
AdminControlRoom::resume($pdo,'rider',$rider,$admin);
ok('A6 rider resume → is_online 1', (string)$pdo->query("SELECT is_online FROM delivery_riders WHERE rider_id=$rider")->fetchColumn(), '1');

echo "\n== loss-proof floor ==\n";
ok('A7 margin 10 < exposure 30 → blocked', AdminControlRoom::lossProofOk($pdo,10,30), false);
ok('A7 margin 40 ≥ exposure 30 → ok', AdminControlRoom::lossProofOk($pdo,40,30), true);
AdminControlRoom::setConfig($pdo,'commerce_loss_proof_enabled','0',$admin);
ok('A7 disabled → always ok', AdminControlRoom::lossProofOk($pdo,10,30), true);
AdminControlRoom::setConfig($pdo,'commerce_loss_proof_enabled','1',$admin);

echo "\n== full manual override (force status) ==\n";
$o=mkOrder($pdo,'S8T-FORCE',$cust,500);
$fr=AdminControlRoom::forceStatus($pdo,$o,'cancelled',$admin,'s8t-ops-decision');
ok('A8 force ok', $fr['ok'], true);
ok('A8 order status changed', ocol($pdo,$o,'status'), 'cancelled');
ok('A8 bad status rejected', AdminControlRoom::forceStatus($pdo,$o,'teleported',$admin,'x')['ok'] ?? false, false);
ok('A8 override event logged', (int)$pdo->query("SELECT COUNT(*) FROM commerce_events WHERE order_id=$o AND event_type='admin_force_status'")->fetchColumn(), 1);

echo "\n== config audit log (who changed what) ==\n";
$aud=AdminControlRoom::configAudit($pdo,100);
$hit=null; foreach($aud as $a){ if($a['setting_key']==='commerce_high_value_threshold' && $a['new_value']==='7000'){ $hit=$a; break; } }
ok('A9 audit row for the threshold change', $hit!==null, true);
ok('A9 audit recorded admin', $hit?(int)$hit['admin_user_id']:0, $admin);

echo "\n== money dashboard (who-we-owe + profit) ==\n";
$base=AdminControlRoom::moneyDashboard($pdo);
$mo=mkOrder($pdo,'S8T-MONEY',$cust,500);
wtx($pdo,'platform',null,$mo,100.00); wtx($pdo,'vendor',1,$mo,200.00); wtx($pdo,'driver',$rider,$mo,50.00);
$pdo->prepare("INSERT INTO driver_wallets (driver_id,cash_in_hand,earnings,collection_limit,updated_at) VALUES (?,300,0,5000,NOW()) ON DUPLICATE KEY UPDATE cash_in_hand=cash_in_hand+300")->execute([$rider]);
$now=AdminControlRoom::moneyDashboard($pdo);
ok('A10 platform profit +100 (24h)', round($now['by_window']['24h']['platform_profit']-$base['by_window']['24h']['platform_profit'],2), '100');
ok('A10 vendor earnings +200 (24h)', round($now['by_window']['24h']['vendor_earnings']-$base['by_window']['24h']['vendor_earnings'],2), '200');
ok('A10 who-we-owe rider cash +300', round($now['who_we_owe']['rider_cash_in_hand']-$base['who_we_owe']['rider_cash_in_hand'],2), '300');

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("S8 RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL>0?1:0);
