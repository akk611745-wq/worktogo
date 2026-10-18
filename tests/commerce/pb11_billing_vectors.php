<?php
// ─────────────────────────────────────────────────────────────────────────────
//  PB11 PROOF — billing: GST-ready customer invoice + seller report.
//  Proves: the invoice is built from the order (lines reconcile to the total),
//  carries our GSTIN + business header, and shows a GST line ONLY when a rate is
//  configured (tax extracted from the inclusive total — the customer pays the
//  same); the seller report sums sales / units / margin split + lists stock
//  movement + payouts, and renders to CSV; + shop/seller route + client wiring.
//  Run: php tests/commerce/pb11_billing_vectors.php   (local 10.11)
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
function setCfg(PDO $p,$k,$v){ global $CFG; $s=$p->prepare("SELECT setting_value FROM app_settings WHERE setting_key=?"); $s->execute([$k]); $CFG[$k]=$s->fetchColumn(); $p->prepare("INSERT INTO app_settings (setting_key,setting_value,value_type,label,is_public) VALUES (?,?,'text','pb11',0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$k,$v]); }
register_shutdown_function(function() use ($pdo){ global $CFG; foreach($CFG as $k=>$v){ if($v===false) $pdo->prepare("DELETE FROM app_settings WHERE setting_key=?")->execute([$k]); else $pdo->prepare("UPDATE app_settings SET setting_value=? WHERE setting_key=?")->execute([$v,$k]); } });
function cleanup(PDO $p){
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $oids=$p->query("SELECT id FROM orders WHERE order_number LIKE 'PB11T-%'")->fetchAll(PDO::FETCH_COLUMN);
    if($oids){$in=implode(',',array_map('intval',$oids)); $p->exec("DELETE FROM order_items WHERE order_id IN ($in)"); $p->exec("DELETE FROM orders WHERE id IN ($in)"); }
    $vids=$p->query("SELECT id FROM vendors WHERE slug LIKE 'pb11t-%'")->fetchAll(PDO::FETCH_COLUMN);
    if($vids){$in=implode(',',array_map('intval',$vids)); $p->exec("DELETE FROM commerce_payouts WHERE payee_type='vendor' AND payee_id IN ($in)"); $p->exec("DELETE FROM commerce_offer_changes WHERE vendor_id IN ($in)"); $p->exec("DELETE FROM stores WHERE owner_vendor_id IN ($in)"); $p->exec("DELETE FROM vendors WHERE id IN ($in)"); }
    $p->exec("DELETE FROM users WHERE email LIKE 'pb11t-%@wtg.test'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('PB11',?, 'customer','active',NOW(),NOW())")->execute([$e]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',1,10,NOW(),NOW())")->execute([$uid,'PB11 '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkStore(PDO $p,$slug,$v,$city){ $p->prepare("INSERT INTO stores (ownership,owner_vendor_id,city_id,name,slug,status,created_at,updated_at) VALUES ('vendor',?,?,?,?,'active',NOW(),NOW())")->execute([$v,$city,'PB11 S '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkParent(PDO $p,$num,$uid,$city){ $p->prepare("INSERT INTO orders (order_number,user_id,city_id,status,payment_status,payment_method,subtotal,total,shipping_address,delivery_status,ledger_status,created_at,updated_at) VALUES (?,?,?, 'delivered','unpaid','cod',0,0,'{}','delivered','pending',NOW(),NOW())")->execute([$num,$uid,$city]); return (int)$p->lastInsertId(); }
function mkSub(PDO $p,$num,$uid,$parent,$v,$st,$city,$subtotal,$sellerBase){ $p->prepare("INSERT INTO orders (order_number,parent_order_id,user_id,vendor_id,store_id,city_id,status,payment_status,payment_method,subtotal,seller_subtotal,total,shipping_address,delivery_status,ledger_status,created_at,updated_at) VALUES (?,?,?,?,?,?, 'delivered','unpaid','cod',?,?,?,'{}','delivered','pending',NOW(),NOW())")->execute([$num,$parent,$uid,$v,$st,$city,$subtotal,$sellerBase,$subtotal]); return (int)$p->lastInsertId(); }

cleanup($pdo);
$cityId=(int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if(!$cityId){ $pdo->exec("INSERT INTO cities (name,slug) VALUES ('PB11 City','pb11t-city')"); $cityId=(int)$pdo->lastInsertId(); }

echo "\n== [A] customer invoice built from the order (no tax configured) ==\n";
setCfg($pdo,'commerce_gstin','09ABCDE1234F1Z5');
setCfg($pdo,'commerce_tax_rate_pct','0');
setCfg($pdo,'commerce_invoice_business','{"name":"WorkToGo","address":"Haldwani","email":"","phone":""}');
$order = [
  'id'=>1, 'order_number'=>'PB11T-INV1', 'created_at'=>'2026-10-18T10:00:00', 'total'=>1180, 'delivery_fee'=>180,
  'payment_method'=>'cod', 'payment_status'=>'unpaid',
  'shipping_address'=>['name'=>'Ravi','phone'=>'9998887776','line1'=>'1 Mall Rd','landmark'=>'near SBI','city'=>'Haldwani','pincode'=>'263139'],
  'vendor_orders'=>[['items'=>[['name'=>'Berger Silk 4L','quantity'=>2,'unit_price'=>500,'line_total'=>1000]]]],
];
$inv = CommerceReports::customerInvoice($pdo,$order);
ok('invoice number = order number', $inv['invoice_no'], 'PB11T-INV1');
ok('our GSTIN on the invoice', $inv['gstin'], '09ABCDE1234F1Z5');
ok('one line item', count($inv['lines']), 1);
ok('items total 1000', (float)$inv['items_total'], 1000.0);
ok('delivery 180', (float)$inv['delivery_fee'], 180.0);
ok('grand total = order total 1180', (float)$inv['grand_total'], 1180.0);
ok('no tax line when rate 0', $inv['tax'], null);
ok('bill-to carries the landmark', $inv['bill_to']['landmark'], 'near SBI');

echo "\n== [B] GST line appears when a rate is set (tax-inclusive, same total) ==\n";
setCfg($pdo,'commerce_tax_rate_pct','18');
$inv2 = CommerceReports::customerInvoice($pdo,$order);
ok('tax rate shown 18', (float)$inv2['tax_rate'], 18.0);
ok('grand total UNCHANGED 1180', (float)$inv2['grand_total'], 1180.0);
ok('taxable value = 1180/1.18 = 1000', (float)$inv2['taxable_value'], 1000.0);
ok('tax extracted = 180', (float)$inv2['tax'], 180.0);

echo "\n== [C] seller report: sales, units, margin split, stock, payouts + CSV ==\n";
$uc=mkUser($pdo,'pb11t-c@wtg.test');
$us=mkUser($pdo,'pb11t-s@wtg.test'); $V=mkVendor($pdo,$us,'pb11t-v'); $St=mkStore($pdo,'pb11t-s',$V,$cityId);
$par=mkParent($pdo,'PB11T-PAR',$uc,$cityId);
$s1=mkSub($pdo,'PB11T-S1',$uc,$par,$V,$St,$cityId,1000,900); $pdo->prepare("INSERT INTO order_items (order_id,product_id,vendor_id,product_name,quantity,unit_price,line_total,created_at) VALUES (?,?,?,?,3,?,?,NOW())")->execute([$s1,1,$V,'x',333,1000]);
$s2=mkSub($pdo,'PB11T-S2',$uc,$par,$V,$St,$cityId,500,460);   $pdo->prepare("INSERT INTO order_items (order_id,product_id,vendor_id,product_name,quantity,unit_price,line_total,created_at) VALUES (?,?,?,?,2,?,?,NOW())")->execute([$s2,1,$V,'x',250,500]);
$pdo->prepare("INSERT INTO commerce_offer_changes (vendor_id,product_id,field,old_value,new_value,changed_at) VALUES (?,?, 'stock','10','4',NOW())")->execute([$V,1]);
$pdo->prepare("INSERT INTO commerce_payouts (payee_type,payee_id,amount,method,status,created_at,paid_at) VALUES ('vendor',?,1200,'bank','paid',NOW(),NOW())")->execute([$V]);
$rep = CommerceReports::sellerReport($pdo,$V,30);
ok('2 orders', $rep['sales']['orders'], 2);
ok('units sold 5', $rep['sales']['units'], 5);
ok('gross 1500', (float)$rep['sales']['gross'], 1500.0);
ok('to-seller 1360', (float)$rep['sales']['to_seller'], 1360.0);
ok('platform margin 140', (float)$rep['sales']['platform_margin'], 140.0);
ok('stock movement listed', count($rep['stock_movement']) >= 1, true);
ok('payouts listed', count($rep['payouts']) >= 1, true);
$csv = CommerceReports::sellerReportCsv($rep);
ok('CSV has a sales summary header', has($csv,'orders,units,gross'), true);
ok('CSV has the payouts section', has($csv,'Payouts'), true);

echo "\n== [D] route + client wiring ==\n";
$shop=get(SYSTEM_ROOT.'/heart/api/shop/index.php');
$sell=get(SYSTEM_ROOT.'/heart/api/commerce-seller/index.php');
$sj=get(SYSTEM_ROOT.'/app/commerce/shop.js'); $capi=get(SYSTEM_ROOT.'/app/commerce/api.js'); $sel=get(SYSTEM_ROOT.'/seller/seller.js');
$acr=get(SYSTEM_ROOT.'/lib/commerce/AdminControlRoom.php');
ok('shop invoice route', has($shop,'/invoice')&&has($shop,'customerInvoice'), true);
ok('seller report route + CSV', has($sell,'/report')&&has($sell,'text/csv'), true);
ok('billing config keys are admin-editable', has($acr,'commerce_gstin')&&has($acr,'commerce_tax_rate_pct'), true);
ok('customer app: invoice view + button', has($sj,'showInvoice')&&has($capi,'invoice:'), true);
ok('seller app: download report', has($sel,'downloadReport'), true);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("PB11 BILLING RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
