<?php
// ─────────────────────────────────────────────────────────────────────────────
//  S9 PROOF HARNESS — settlement payout + own/facilitated accounting + returns/
//  refund (source-aware, COD-refund SOP). Run: php tests/commerce/s9_settlement_returns_vectors.php
// ─────────────────────────────────────────────────────────────────────────────
define('SYSTEM_ROOT', dirname(dirname(__DIR__)));
foreach (file(SYSTEM_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2); $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
    if (getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
}
require_once SYSTEM_ROOT . '/core/helpers/Logger.php';
require_once SYSTEM_ROOT . '/core/helpers/Database.php';
require_once SYSTEM_ROOT . '/lib/commerce/CommerceSettlement.php';
require_once SYSTEM_ROOT . '/lib/commerce/CommerceReturns.php';
require_once SYSTEM_ROOT . '/core/helpers/LedgerEngine.php';
$pdo = Database::getConnection();

$PASS=0;$FAIL=0;
function ok($l,$g,$e){ global $PASS,$FAIL; $g=is_bool($g)?var_export($g,true):(string)$g; $e=is_bool($e)?var_export($e,true):(string)$e;
  if($g===$e){$PASS++;printf("  PASS  %-46s %s\n",$l,$g);}else{$FAIL++;printf("  FAIL  %-46s got=%s exp=%s\n",$l,$g,$e);} }
function cleanup(PDO $p){
  $p->exec("UPDATE app_settings SET setting_value='{\"default\":\"returnable_if_sealed\",\"no_return\":[],\"perishable\":[]}' WHERE setting_key='commerce_return_risk'");
  $p->exec("SET FOREIGN_KEY_CHECKS=0");
  $oids=$p->query("SELECT id FROM orders WHERE order_number LIKE 'S9T-%'")->fetchAll(PDO::FETCH_COLUMN);
  if($oids){$in=implode(',',array_map('intval',$oids));
    $p->exec("DELETE FROM wallet_transactions WHERE reference_type='order' AND reference_id IN ($in)");
    $p->exec("DELETE FROM commerce_returns WHERE order_id IN ($in)");
    $p->exec("DELETE FROM commerce_events WHERE order_id IN ($in)");
    $p->exec("DELETE FROM order_items WHERE order_id IN ($in)");
    $p->exec("DELETE FROM orders WHERE id IN ($in)"); }
  $vids=$p->query("SELECT id FROM vendors WHERE slug LIKE 's9t-%'")->fetchAll(PDO::FETCH_COLUMN);
  if($vids){$in=implode(',',array_map('intval',$vids)); $p->exec("DELETE FROM commerce_payouts WHERE payee_type='vendor' AND payee_id IN ($in)"); $p->exec("DELETE FROM vendor_wallets WHERE vendor_id IN ($in)"); $p->exec("DELETE FROM vendors WHERE id IN ($in)"); }
  $p->exec("DELETE i FROM inventory i JOIN products pr ON pr.id=i.product_id WHERE pr.slug LIKE 's9t-%'");
  $p->exec("DELETE FROM products WHERE slug LIKE 's9t-%'");
  $p->exec("DELETE FROM stores WHERE slug LIKE 's9t-%'");
  $p->exec("DELETE FROM categories WHERE slug LIKE 's9t-%'");
  $p->exec("DELETE FROM users WHERE email LIKE 's9t-%@wtg.test'");
  $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e,$r='customer'){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('S9',?,?, 'active',NOW(),NOW())")->execute([$e,$r]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',1,10,NOW(),NOW())")->execute([$uid,'S9 V '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkStore(PDO $p,$slug,$own,$ownerV){ $p->prepare("INSERT INTO stores (ownership,owner_vendor_id,name,slug,status,created_at,updated_at) VALUES (?,?,?,?,'active',NOW(),NOW())")->execute([$own,$ownerV,'S9 S '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkCat(PDO $p,$slug){ $p->prepare("INSERT INTO categories (name,slug,type,status,created_at,updated_at) VALUES (?,?,'product','active',NOW(),NOW())")->execute(['S9 '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkProd(PDO $p,$v,$s,$cat,$slug){ $p->prepare("INSERT INTO products (vendor_id,store_id,category_id,name,slug,sku,price,status,created_at,updated_at) VALUES (?,?,?,?,?,?,199,'active',NOW(),NOW())")->execute([$v,$s,$cat,'S9 P '.$slug,$slug,$slug]); return (int)$p->lastInsertId(); }
function mkOrder(PDO $p,$num,$uid,$parent,$vendor,$store,$sub,$sellerSub,$pm,$status,$deliveredExpr){ $p->prepare("INSERT INTO orders (order_number,parent_order_id,user_id,vendor_id,store_id,status,payment_status,payment_method,subtotal,seller_subtotal,total,shipping_address,delivery_status,ledger_status,delivered_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,'{}','delivered','pending', $deliveredExpr ,NOW(),NOW())")->execute([$num,$parent,$uid,$vendor,$store,$status,($pm==='online'?'paid':'unpaid'),$pm,$sub,$sellerSub,$sub]); return (int)$p->lastInsertId(); }
function mkItem(PDO $p,$oid,$pid,$vendor,$store,$qty){ $p->prepare("INSERT INTO order_items (order_id,product_id,vendor_id,store_id,product_name,quantity,unit_price,line_total,created_at) VALUES (?,?,?,?,?,?,199,199,NOW())")->execute([$oid,$pid,$vendor,$store,'S9 item',$qty]); return (int)$p->lastInsertId(); }
function vwal(PDO $p,$vid,$pending){ $p->prepare("INSERT INTO vendor_wallets (vendor_id,available_balance,pending_balance,updated_at) VALUES (?,0,?,NOW()) ON DUPLICATE KEY UPDATE pending_balance=VALUES(pending_balance)")->execute([$vid,$pending]); }
function wtxPending(PDO $p,$vid,$oid,$amt,$ageHours){ $p->prepare("INSERT INTO wallet_transactions (entity_type,entity_id,order_id,reference_type,reference_id,type,amount,status,created_at) VALUES ('vendor',?,?,'order',?, 'credit',?, 'pending', NOW() - INTERVAL ? HOUR)")->execute([$vid,$oid,$oid,$amt,$ageHours]); }
function vbal(PDO $p,$vid,$c){ $s=$p->prepare("SELECT $c FROM vendor_wallets WHERE vendor_id=?"); $s->execute([$vid]); return (float)$s->fetchColumn(); }
function ocol(PDO $p,$oid,$c){ $s=$p->prepare("SELECT $c FROM orders WHERE id=?"); $s->execute([$oid]); return $s->fetchColumn(); }

cleanup($pdo);
$cust=mkUser($pdo,'s9t-c@wtg.test'); $ops=mkUser($pdo,'s9t-ops@wtg.test','admin'); $rider=mkUser($pdo,'s9t-rider@wtg.test','delivery');

echo "\n== settlement: return-hold + payout record ==\n";
$svu=mkUser($pdo,'s9t-sv@wtg.test','vendor_shopping'); $sv=mkVendor($pdo,$svu,'s9t-sv');
$oOld=mkOrder($pdo,'S9T-OLD',$cust,null,null,null,100,100,'cod','delivered','NOW() - INTERVAL 3 DAY');
$oNew=mkOrder($pdo,'S9T-NEW',$cust,null,null,null,60,60,'cod','delivered','NOW()');
vwal($pdo,$sv,160); wtxPending($pdo,$sv,$oOld,100,72); wtxPending($pdo,$sv,$oNew,60,1); // 72h old (past 48h hold), 1h new
$se=CommerceSettlement::settleEligible($pdo);
ok('SET1 only old settled (count 1)', $se['settled_count'], 1);
ok('SET1 settled amount = 100', (string)$se['total'], '100');
ok('SET1 available now 100 (old moved)', (string)vbal($pdo,$sv,'available_balance'), '100');
ok('SET1 pending now 60 (new held)', (string)vbal($pdo,$sv,'pending_balance'), '60');
$pay=CommerceSettlement::runPayout($pdo,'bank',$ops);
ok('SET1 payout created for vendor', count(array_filter($pay,fn($x)=>$x['vendor_id']===$sv)), 1);
ok('SET1 available → 0 after payout', (string)vbal($pdo,$sv,'available_balance'), '0');
$pid=null; foreach($pay as $x){ if($x['vendor_id']===$sv) $pid=$x['payout_id']; }
ok('SET1 markPayoutPaid ok', CommerceSettlement::markPayoutPaid($pdo,$pid,'UTR123'), true);

echo "\n== own vs facilitated accounting ==\n";
$ovu=mkUser($pdo,'s9t-ov@wtg.test','vendor_shopping'); $ov=mkVendor($pdo,$ovu,'s9t-ov');
$coStore=mkStore($pdo,'s9t-co','company',null); $veStore=mkStore($pdo,'s9t-ve','vendor',$ov);
$base=CommerceSettlement::accountingSplit($pdo,30);
$aOwnP=mkOrder($pdo,'S9T-ACC-OWN-P',$cust,null,null,null,500,null,'cod','delivered','NOW()');
mkOrder($pdo,'S9T-ACC-OWN-S',$cust,$aOwnP,null,$coStore,500,null,'cod','delivered','NOW()');       // company sub
$aFacP=mkOrder($pdo,'S9T-ACC-FAC-P',$cust,null,null,null,300,null,'cod','delivered','NOW()');
mkOrder($pdo,'S9T-ACC-FAC-S',$cust,$aFacP,$ov,$veStore,300,250,'cod','delivered','NOW()');          // vendor sub margin 50
$now=CommerceSettlement::accountingSplit($pdo,30);
ok('SET2 own gross +500', (string)round($now['own']['gross']-$base['own']['gross'],2), '500');
ok('SET2 own margin +500 (company full)', (string)round($now['own']['margin']-$base['own']['margin'],2), '500');
ok('SET2 facilitated gross +300', (string)round($now['facilitated']['gross']-$base['facilitated']['gross'],2), '300');
ok('SET2 facilitated margin +50 (spread)', (string)round($now['facilitated']['margin']-$base['facilitated']['margin'],2), '50');

echo "\n== returns: window + risk + source ==\n";
$cat=mkCat($pdo,'s9t-cat'); $rvu=mkUser($pdo,'s9t-rv@wtg.test','vendor_shopping'); $rv=mkVendor($pdo,$rvu,'s9t-rv'); $rstore=mkStore($pdo,'s9t-rs','vendor',$rv);
$prod=mkProd($pdo,$rv,$rstore,$cat,'s9t-prod');
// online delivered within window
$onP=mkOrder($pdo,'S9T-RET-ON',$cust,null,null,null,400,350,'online','delivered','NOW()'); $onS=mkOrder($pdo,'S9T-RET-ON-S',$cust,$onP,$rv,$rstore,400,350,'online','delivered','NOW()');
mkItem($pdo,$onS,$prod,$rv,$rstore,1);
$req=CommerceReturns::requestReturn($pdo,$onP,$cust,'wrong item');
ok('SET3 online return ok', $req['ok'], true);
ok('SET3 method = gateway (online)', $req['refund_method'], 'gateway');
ok('SET3 source store captured', (int)$req['source_store_id'], $rstore);
// past window
$oldP=mkOrder($pdo,'S9T-RET-OLD',$cust,null,null,null,200,150,'cod','delivered','NOW() - INTERVAL 72 HOUR'); $oldS=mkOrder($pdo,'S9T-RET-OLD-S',$cust,$oldP,$rv,$rstore,200,150,'cod','delivered','NOW() - INTERVAL 72 HOUR');
mkItem($pdo,$oldS,$prod,$rv,$rstore,1);
ok('SET3 past window rejected', CommerceReturns::requestReturn($pdo,$oldP,$cust,'late')['reason'], 'window_passed');
// no_return category
$pdo->prepare("UPDATE app_settings SET setting_value=? WHERE setting_key='commerce_return_risk'")->execute([json_encode(['default'=>'returnable_if_sealed','no_return'=>[$cat],'perishable'=>[]])]);
$nrP=mkOrder($pdo,'S9T-RET-NR',$cust,null,null,null,150,100,'cod','delivered','NOW()'); $nrS=mkOrder($pdo,'S9T-RET-NR-S',$cust,$nrP,$rv,$rstore,150,100,'cod','delivered','NOW()');
mkItem($pdo,$nrS,$prod,$rv,$rstore,1);
ok('SET3 no_return category rejected', CommerceReturns::requestReturn($pdo,$nrP,$cust,'x')['reason'], 'not_returnable');
$pdo->prepare("UPDATE app_settings SET setting_value='{\"default\":\"returnable_if_sealed\",\"no_return\":[],\"perishable\":[]}' WHERE setting_key='commerce_return_risk'")->execute();

echo "\n== COD refund lifecycle (reverses ledger) ==\n";
$cvu=mkUser($pdo,'s9t-cv@wtg.test','vendor_shopping'); $cv=mkVendor($pdo,$cvu,'s9t-cv'); $cstore=mkStore($pdo,'s9t-cs','vendor',$cv);
$cP=mkOrder($pdo,'S9T-COD-P',$cust,null,null,null,300,240,'cod','delivered','NOW()'); $cS=mkOrder($pdo,'S9T-COD-S',$cust,$cP,$cv,$cstore,300,240,'cod','delivered','NOW()');
\Core\Helpers\LedgerEngine::processCommerceOrderSettlement($cP,$pdo);        // vendor pending += 240 (seller base)
ok('COD pre-refund vendor pending = 240', (string)vbal($pdo,$cv,'pending_balance'), '240');
$rr=CommerceReturns::requestReturn($pdo,$cP,$cust,'damaged');
ok('SET3 COD method = cod_store_credit', $rr['refund_method'], 'cod_store_credit');
CommerceReturns::approve($pdo,$rr['return_id'],$ops);
CommerceReturns::markReceived($pdo,$rr['return_id'],$rider);
$rf=CommerceReturns::refund($pdo,$rr['return_id'],$ops);
ok('SET3 refund ledger reversed', $rf['ledger_reversed'], true);
ok('SET3 COD obligation flagged (SOP)', $rf['cod_obligation'], true);
ok('SET3 vendor pending reversed to 0', (string)vbal($pdo,$cv,'pending_balance'), '0');
ok('SET3 order ledger_status refunded', ocol($pdo,$cP,'ledger_status'), 'refunded');
ok('SET3 return status refunded', (string)$pdo->query("SELECT status FROM commerce_returns WHERE id=".$rr['return_id'])->fetchColumn(), 'refunded');

echo "\n== config ==\n";
ok('SET4 return window', CommerceReturns::windowHours($pdo), 48);
ok('SET4 return hold', CommerceSettlement::returnHoldHours($pdo), 48);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("S9 RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL>0?1:0);
