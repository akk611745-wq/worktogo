<?php
// ─────────────────────────────────────────────────────────────────────────────
//  PB3b PROOF — admin config screens (reporting + live config, no new money path).
//  Proves: the live margin preview reflects the slab + growth config; money
//  stats aggregate orders/revenue/margin/delivery/COD-vs-prepaid; per-seller
//  settlement shows owed / paid / margin we earned; config set is allow-listed
//  + audited; + the three admin screens + routes are wired.
//  Run: php tests/commerce/pb3b_admin_config_vectors.php   (local 10.11)
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
require_once SYSTEM_ROOT . '/lib/commerce/CommerceReports.php';
require_once SYSTEM_ROOT . '/lib/commerce/AdminControlRoom.php';
require_once SYSTEM_ROOT . '/lib/commerce/MarginEngine.php';
$pdo = Database::getConnection();

$PASS = 0; $FAIL = 0; $CFG = [];
function ok($label, $got, $exp = true) {
    global $PASS, $FAIL;
    $g = is_float($got) ? number_format($got, 2, '.', '') : var_export($got, true);
    $e = is_float($exp) ? number_format($exp, 2, '.', '') : var_export($exp, true);
    if ($g === $e) { $PASS++; printf("  PASS  %-54s %s\n", $label, $g); }
    else { $FAIL++; printf("  FAIL  %-54s got=%s exp=%s\n", $label, $g, $e); }
}
function has(string $h,string $n):bool{ return strpos($h,$n)!==false; }
function get(string $p):string{ return is_file($p)?(string)file_get_contents($p):''; }
function setCfg(PDO $p,$k,$v){ global $CFG; $s=$p->prepare("SELECT setting_value FROM app_settings WHERE setting_key=?"); $s->execute([$k]); $CFG[$k]=$s->fetchColumn(); $p->prepare("INSERT INTO app_settings (setting_key,setting_value,value_type,label,is_public) VALUES (?,?,'text','pb3b',0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$k,$v]); }
register_shutdown_function(function() use ($pdo){ global $CFG; foreach($CFG as $k=>$v){ if($v===false) $pdo->prepare("DELETE FROM app_settings WHERE setting_key=?")->execute([$k]); else $pdo->prepare("UPDATE app_settings SET setting_value=? WHERE setting_key=?")->execute([$v,$k]); } });
function cleanup(PDO $p){
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $oids=$p->query("SELECT id FROM orders WHERE order_number LIKE 'PB3BT-%'")->fetchAll(PDO::FETCH_COLUMN);
    if($oids){$in=implode(',',array_map('intval',$oids)); $p->exec("DELETE FROM order_items WHERE order_id IN ($in)"); $p->exec("DELETE FROM orders WHERE id IN ($in)"); }
    $vids=$p->query("SELECT id FROM vendors WHERE slug LIKE 'pb3bt-%'")->fetchAll(PDO::FETCH_COLUMN);
    if($vids){$in=implode(',',array_map('intval',$vids)); $p->exec("DELETE FROM vendor_wallets WHERE vendor_id IN ($in)"); $p->exec("DELETE FROM commerce_payouts WHERE payee_type='vendor' AND payee_id IN ($in)"); $p->exec("DELETE FROM stores WHERE owner_vendor_id IN ($in)"); $p->exec("DELETE FROM vendors WHERE id IN ($in)"); }
    $p->exec("DELETE FROM users WHERE email LIKE 'pb3bt-%@wtg.test'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('PB3B',?, 'customer','active',NOW(),NOW())")->execute([$e]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',1,10,NOW(),NOW())")->execute([$uid,'PB3B '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkStore(PDO $p,$slug,$v,$city){ $p->prepare("INSERT INTO stores (ownership,owner_vendor_id,city_id,name,slug,status,created_at,updated_at) VALUES ('vendor',?,?,?,?,'active',NOW(),NOW())")->execute([$v,$city,'PB3B S '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkParent(PDO $p,$num,$uid,$city,$total,$delivery,$payMethod,$payStatus){ $p->prepare("INSERT INTO orders (order_number,user_id,city_id,status,payment_status,payment_method,subtotal,delivery_fee,total,shipping_address,delivery_status,ledger_status,created_at,updated_at) VALUES (?,?,?, 'confirmed',?,?,?,?,?,'{}','offered','pending',NOW(),NOW())")->execute([$num,$uid,$city,$payStatus,$payMethod,$total-$delivery,$delivery,$total]); return (int)$p->lastInsertId(); }
function mkSub(PDO $p,$num,$uid,$parent,$v,$st,$city,$subtotal,$sellerBase){ $p->prepare("INSERT INTO orders (order_number,parent_order_id,user_id,vendor_id,store_id,city_id,status,payment_status,payment_method,subtotal,seller_subtotal,total,shipping_address,delivery_status,ledger_status,created_at,updated_at) VALUES (?,?,?,?,?,?, 'confirmed','unpaid','cod',?,?,?,'{}','accepted','pending',NOW(),NOW())")->execute([$num,$parent,$uid,$v,$st,$city,$subtotal,$sellerBase,$subtotal]); return (int)$p->lastInsertId(); }

cleanup($pdo);
$cityId=(int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if(!$cityId){ $pdo->exec("INSERT INTO cities (name,slug) VALUES ('PB3B City','pb3bt-city')"); $cityId=(int)$pdo->lastInsertId(); }

echo "\n== [A] live margin preview reflects slab + growth knob ==\n";
setCfg($pdo,'commerce_margin_slabs','{"enabled":true,"bands":[{"up_to":null,"flat":100}]}');
setCfg($pdo,'commerce_margin_multiplier','1');
$pv=CommerceReports::marginPreview($pdo,1000);
ok('flat ₹100 slab → margin 100', (float)$pv['margin'], 100.0);
ok('customer price = base + margin', (float)$pv['customer_price'], 1100.0);
ok('source is slab', $pv['source'], 'slab');
setCfg($pdo,'commerce_margin_multiplier','0.5');
ok('growth ×0.5 halves the margin', (float)CommerceReports::marginPreview($pdo,1000)['margin'], 50.0);

echo "\n== [B] config set is allow-listed + audited ==\n";
$r1=AdminControlRoom::setConfig($pdo,'commerce_margin_multiplier','0.5',1,'test');
ok('allow-listed key saves', $r1['ok'], true);
$r2=AdminControlRoom::setConfig($pdo,'not_a_real_key','x',1,'test');
ok('non-allow-listed key rejected', $r2['ok'] ?? false, false);

echo "\n== [C] money stats aggregate orders/revenue/COD-vs-prepaid/margin ==\n";
$uc=mkUser($pdo,'pb3bt-c@wtg.test');
$us=mkUser($pdo,'pb3bt-s@wtg.test'); $V=mkVendor($pdo,$us,'pb3bt-v'); $St=mkStore($pdo,'pb3bt-s',$V,$cityId);
$p1=mkParent($pdo,'PB3BT-COD',$uc,$cityId,1200,200,'cod','unpaid');     mkSub($pdo,'PB3BT-COD-S',$uc,$p1,$V,$St,$cityId,1000,900);
$p2=mkParent($pdo,'PB3BT-ONL',$uc,$cityId,800,100,'online','paid');     mkSub($pdo,'PB3BT-ONL-S',$uc,$p2,$V,$St,$cityId,700,650);
$ms=CommerceReports::moneyStats($pdo,1);
ok('orders counted (>=2 mine)', $ms['orders'] >= 2, true);
ok('revenue includes mine (>=2000)', $ms['revenue'] >= 2000.0, true);
ok('delivery spread includes mine (>=300)', $ms['delivery'] >= 300.0, true);
ok('>=1 COD order', $ms['cod'] >= 1, true);
ok('>=1 prepaid order', $ms['prepaid'] >= 1, true);
ok('margin present in split', isset($ms['split']['facilitated']), true);

echo "\n== [D] per-seller settlement: owed / paid / margin earned ==\n";
$pdo->prepare("INSERT INTO vendor_wallets (vendor_id,available_balance,pending_balance,updated_at) VALUES (?,300,150,NOW())")->execute([$V]);
$pdo->prepare("INSERT INTO commerce_payouts (payee_type,payee_id,amount,method,status,created_at,paid_at) VALUES ('vendor',?,500,'bank','paid',NOW(),NOW())")->execute([$V]);
$rows=CommerceReports::perSellerSettlement($pdo);
$mine=null; foreach($rows as $r){ if($r['vendor_id']===$V){ $mine=$r; break; } }
ok('my seller row present', $mine !== null, true);
ok('owed = available + pending = 450', (float)$mine['owed'], 450.0);
ok('paid = 500', (float)$mine['paid'], 500.0);
// margin earned = (1000-900) + (700-650) = 150 from the two sub-orders
ok('margin earned we keep = 150', (float)$mine['margin_earned'], 150.0);

echo "\n== [E] screens + routes wired ==\n";
$adm=get(SYSTEM_ROOT.'/heart/api/commerce-admin/index.php');
$sh=get(SYSTEM_ROOT.'/admin/shared/shell.js');
$set=get(SYSTEM_ROOT.'/admin/commerce-settings.html'); $br=get(SYSTEM_ROOT.'/admin/commerce-brands.html'); $mo=get(SYSTEM_ROOT.'/admin/commerce-money.html');
ok('routes: margin-preview / money-stats / settlement', has($adm,'/margin-preview')&&has($adm,'/money-stats')&&has($adm,'/settlement'), true);
ok('settings screen edits config + preview', has($set,'/commerce-admin/config')&&has($set,'margin-preview'), true);
ok('brands screen edits both brand lists', has($br,'commerce_paint_brands')&&has($br,'commerce_custom_tint_brands'), true);
ok('money screen reads stats + settlement + settle', has($mo,'money-stats')&&has($mo,'settlement')&&has($mo,'/settle'), true);
ok('nav shows the three config screens', has($sh,'commerce-settings.html')&&has($sh,'commerce-brands.html')&&has($sh,'commerce-money.html'), true);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("PB3b ADMIN-CONFIG RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
