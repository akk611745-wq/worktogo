<?php
// ─────────────────────────────────────────────────────────────────────────────
//  PB16 PROOF — pre-delivery cancellation + cost-bearer rule.
//  Proves: stage detection (placed/accepted/picked); customer cancel is FREE
//  before accept, admin-fee after accept, blocked (→ return flow) after pickup;
//  prepaid auto-refund (minus fee) with who-bears-cost recorded; COD no refund;
//  custom-tint advance non-refundable once making started; seller-cancel-after-
//  accept reroutes + drops the seller score + logs a penalty; + adapter wiring.
//  Run: php tests/commerce/pb16_cancellation_vectors.php   (local 10.11)
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
require_once SYSTEM_ROOT . '/lib/commerce/CommerceCancellation.php';
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
function setCfg(PDO $p,$k,$v){ global $CFG; $s=$p->prepare("SELECT setting_value FROM app_settings WHERE setting_key=?"); $s->execute([$k]); $CFG[$k]=$s->fetchColumn(); $p->prepare("INSERT INTO app_settings (setting_key,setting_value,value_type,label,is_public) VALUES (?,?,'number','pb16',0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$k,$v]); }
register_shutdown_function(function() use ($pdo){ global $CFG; foreach($CFG as $k=>$v){ if($v===false) $pdo->prepare("DELETE FROM app_settings WHERE setting_key=?")->execute([$k]); else $pdo->prepare("UPDATE app_settings SET setting_value=? WHERE setting_key=?")->execute([$v,$k]); } });
function cleanup(PDO $p){
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $oids=$p->query("SELECT id FROM orders WHERE order_number LIKE 'PB16T-%'")->fetchAll(PDO::FETCH_COLUMN);
    if($oids){$in=implode(',',array_map('intval',$oids));
        $p->exec("DELETE FROM commerce_routing_signals WHERE order_id IN ($in) OR sub_order_id IN ($in)");
        $p->exec("DELETE FROM commerce_cancellations WHERE order_id IN ($in)");
        $p->exec("DELETE FROM commerce_order_payments WHERE order_id IN ($in)");
        $p->exec("DELETE FROM order_items WHERE order_id IN ($in)");
        $p->exec("DELETE FROM deliveries WHERE order_id IN ($in)");
        $p->exec("DELETE FROM orders WHERE id IN ($in)"); }
    $p->exec("DELETE i FROM inventory i JOIN products pr ON pr.id=i.product_id WHERE pr.slug LIKE 'pb16t-%'");
    $p->exec("DELETE FROM products WHERE slug LIKE 'pb16t-%'");
    $p->exec("DELETE FROM stores   WHERE slug LIKE 'pb16t-%'");
    $p->exec("DELETE FROM vendors  WHERE slug LIKE 'pb16t-%'");
    $p->exec("DELETE FROM users    WHERE email LIKE 'pb16t-%@wtg.test'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('PB16',?, 'vendor_shopping','active',NOW(),NOW())")->execute([$e]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',1,10,NOW(),NOW())")->execute([$uid,'PB16 '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkStore(PDO $p,$slug,$own,$v,$city){ $p->prepare("INSERT INTO stores (ownership,owner_vendor_id,city_id,name,slug,status,lat,lng,service_radius_km,created_at,updated_at) VALUES (?,?,?,?,?,'active',29.2,79.5,NULL,NOW(),NOW())")->execute([$own,$v,$city,'PB16 S '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkProd(PDO $p,$v,$st,$city,$mk,$slug){ $p->prepare("INSERT INTO products (vendor_id,store_id,city_id,name,slug,sku,match_key,price,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,199.00,'active',NOW(),NOW())")->execute([$v,$st,$city,'PB16 P '.$slug,$slug,$slug,$mk]); $pid=(int)$p->lastInsertId(); $p->prepare("INSERT INTO inventory (product_id,quantity,reserved,low_stock_alert,track_inventory,allow_backorder,updated_at) VALUES (?,10,0,2,1,0,NOW())")->execute([$pid]); return $pid; }
function mkOrder(PDO $p,$num,$uid,$parent,$v,$st,$city,$ds,$total,$payStatus){ $p->prepare("INSERT INTO orders (order_number,parent_order_id,user_id,vendor_id,store_id,city_id,status,payment_status,payment_method,subtotal,total,shipping_address,delivery_status,ledger_status,created_at,updated_at) VALUES (?,?,?,?,?,?, 'confirmed',?, ?, ?, ?, '{}',?,'pending',NOW(),NOW())")->execute([$num,$parent,$uid,$v,$st,$city,$payStatus,$payStatus==='paid'?'online':'cod',$total,$total,$ds]); return (int)$p->lastInsertId(); }
function sig(PDO $p,$o,$sub,$st,$v,$ev){ $p->prepare("INSERT INTO commerce_routing_signals (order_id,sub_order_id,store_id,vendor_id,event,created_at) VALUES (?,?,?,?,?,NOW())")->execute([$o,$sub,$st,$v,$ev]); }

cleanup($pdo);
$cityId=(int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if(!$cityId){ $pdo->exec("INSERT INTO cities (name,slug) VALUES ('PB16 City','pb16t-city')"); $cityId=(int)$pdo->lastInsertId(); }
$uc=mkUser($pdo,'pb16t-c@wtg.test');
setCfg($pdo,'commerce_cancel_fee_after_accept','50');
setCfg($pdo,'commerce_cancel_fee_pct','0');
setCfg($pdo,'commerce_cancel_seller_penalty','1');

echo "\n== [A] stage detection ==\n";
$o1=mkOrder($pdo,'PB16T-PLACED',$uc,null,null,null,$cityId,'offered',1000,'unpaid');
ok('no accept → placed', CommerceCancellation::stage($pdo,$o1), 'placed');
$o2=mkOrder($pdo,'PB16T-ACC',$uc,null,null,null,$cityId,'accepted',1000,'unpaid');
sig($pdo,$o2,$o2,null,null,'accepted');
ok('accept signal → accepted', CommerceCancellation::stage($pdo,$o2), 'accepted');
$o3=mkOrder($pdo,'PB16T-PICK',$uc,null,null,null,$cityId,'accepted',1000,'unpaid');
sig($pdo,$o3,$o3,null,null,'accepted');
$pdo->prepare("INSERT INTO deliveries (order_id,status,price,created_at,updated_at) VALUES (?, 'picked',60,NOW(),NOW())")->execute([$o3]);
ok('delivery picked → picked', CommerceCancellation::stage($pdo,$o3), 'picked');

echo "\n== [B] quote: free / fee / return-flow ==\n";
$qP=CommerceCancellation::quote($pdo,$o1);
ok('placed cancellable + free', $qP['cancellable'] && (float)$qP['fee']===0.0, true);
ok('placed cost_bearer none', $qP['cost_bearer'], 'none');
$qA=CommerceCancellation::quote($pdo,$o2);
ok('accepted fee = 50 (flat)', (float)$qA['fee'], 50.0);
ok('accepted cost_bearer customer', $qA['cost_bearer'], 'customer');
$qK=CommerceCancellation::quote($pdo,$o3);
ok('picked NOT cancellable', $qK['cancellable'], false);
ok('picked requires return flow', $qK['requires_return'], true);

echo "\n== [C] settle: prepaid refund minus fee; COD no refund; who bears cost ==\n";
// prepaid, placed → full refund, bearer none
$oPre=mkOrder($pdo,'PB16T-PRE',$uc,null,null,null,$cityId,'offered',1000,'paid');
$sPre=CommerceCancellation::settle($pdo,$oPre,'customer',CommerceCancellation::quote($pdo,$oPre),'changed mind');
ok('prepaid placed → full refund 1000', (float)$sPre['refund_amount'], 1000.0);
ok('prepaid placed → bearer none', $sPre['cost_bearer'], 'none');
// prepaid, accepted → refund = 1000 - 50 fee, bearer customer
$oPreA=mkOrder($pdo,'PB16T-PREA',$uc,null,null,null,$cityId,'accepted',1000,'paid');
sig($pdo,$oPreA,$oPreA,null,null,'accepted');
$sPreA=CommerceCancellation::settle($pdo,$oPreA,'customer',CommerceCancellation::quote($pdo,$oPreA),'late');
ok('prepaid accepted → refund 950', (float)$sPreA['refund_amount'], 950.0);
ok('prepaid accepted → fee 50 borne by customer', $sPreA['cost_bearer'], 'customer');
// COD → no refund
$oCod=mkOrder($pdo,'PB16T-COD',$uc,null,null,null,$cityId,'offered',1000,'unpaid');
$sCod=CommerceCancellation::settle($pdo,$oCod,'customer',CommerceCancellation::quote($pdo,$oCod),'cod');
ok('COD → no refund', (float)$sCod['refund_amount'], 0.0);
ok('cancellation rows logged', (int)$pdo->query("SELECT COUNT(*) FROM commerce_cancellations WHERE order_id IN ($oPre,$oPreA,$oCod)")->fetchColumn(), 3);

echo "\n== [D] custom-tint advance non-refundable once making started ==\n";
$oAdv=mkOrder($pdo,'PB16T-ADV',$uc,null,null,null,$cityId,'accepted',5000,'partially_paid');
sig($pdo,$oAdv,$oAdv,null,null,'accepted');
$pdo->prepare("INSERT INTO commerce_order_payments (order_id,kind,expected_amount,captured_amount,balance_amount,status,created_at,updated_at) VALUES (?, 'advance',2500,2500,2500,'captured',NOW(),NOW())")->execute([$oAdv]);
ok('advance refundable before making', CommerceCancellation::advanceRefundable($pdo,$oAdv), true);
sig($pdo,$oAdv,$oAdv,null,null,'making_started');
ok('advance NOT refundable after making', CommerceCancellation::advanceRefundable($pdo,$oAdv), false);
$sAdv=CommerceCancellation::settle($pdo,$oAdv,'customer',CommerceCancellation::quote($pdo,$oAdv),'tint started');
ok('advance retained in refund (0 of the 2500 captured)', (float)$sAdv['refund_amount'], 0.0);

echo "\n== [E] seller cancel after accept → reroute + score penalty ==\n";
$uco=mkUser($pdo,'pb16t-co@wtg.test'); $vCo=mkVendor($pdo,$uco,'pb16t-co');
$uon=mkUser($pdo,'pb16t-on@wtg.test'); $vON=mkVendor($pdo,$uon,'pb16t-on');
$stCo=mkStore($pdo,'pb16t-company','company',null,$cityId);
$stNe=mkStore($pdo,'pb16t-near','vendor',$vON,$cityId);
mkProd($pdo,$vCo,$stCo,$cityId,'MK-PB16','pb16t-q-co');
$pNe=mkProd($pdo,$vON,$stNe,$cityId,'MK-PB16','pb16t-q-ne');
$pP=mkOrder($pdo,'PB16T-SC-P',$uc,null,null,null,$cityId,'offered',199,'unpaid');
$pS=mkOrder($pdo,'PB16T-SC-S',$uc,$pP,$vON,$stNe,$cityId,'accepted',199,'unpaid');
$pdo->prepare("INSERT INTO order_items (order_id,product_id,vendor_id,product_name,quantity,unit_price,line_total,created_at) VALUES (?,?,?,?,1,199,199,NOW())")->execute([$pS,$pNe,$vON,'PB16 item']);
$score0=CommerceCancellation::sellerScore($pdo,$vON);
sig($pdo,$pP,$pS,$stNe,$vON,'accepted'); // the seller had accepted
$sc=CommerceCancellation::sellerCancel($pdo,$pS,$vON,'shop shut');
ok('seller cancel ok', $sc['ok'], true);
ok('rerouted to another seller', $sc['reroute']['rerouted'] ?? false, true);
ok('sub moved off the cancelling seller', (int)$pdo->query("SELECT store_id FROM orders WHERE id=$pS")->fetchColumn(), $stCo);
ok('penalty signal logged', (int)$pdo->query("SELECT COUNT(*) FROM commerce_routing_signals WHERE sub_order_id=$pS AND event='seller_cancelled'")->fetchColumn(), 1);
ok('seller penalty row logged', (int)$pdo->query("SELECT COUNT(*) FROM commerce_cancellations WHERE actor='seller' AND sub_order_id=$pS")->fetchColumn(), 1);
$score1=CommerceCancellation::sellerScore($pdo,$vON);
ok('seller score dropped after cancel', $score1 < $score0, true);

echo "\n== [F] adapter + client wiring ==\n";
$shop=get(SYSTEM_ROOT.'/heart/api/shop/index.php');
$sell=get(SYSTEM_ROOT.'/heart/api/commerce-seller/index.php');
$js=get(SYSTEM_ROOT.'/app/commerce/shop.js');
ok('shop cancel uses the stage rule + settle', has($shop,'CommerceCancellation::quote')&&has($shop,'CommerceCancellation::settle'), true);
ok('shop blocks picked → return flow', has($shop,'requires_return')&&has($shop,'start a return'), true);
ok('seller cancel route present', has($sell,'/cancel')&&has($sell,'CommerceCancellation::sellerCancel'), true);
ok('client surfaces refund/fee', has($js,'refunded to source')||has($js,'cancellation'), true);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("PB16 CANCELLATION RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
