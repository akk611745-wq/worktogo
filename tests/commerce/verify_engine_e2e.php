<?php
// ─────────────────────────────────────────────────────────────────────────────
//  ENGINE VERIFICATION — end-to-end (PART A). One real order lifecycle through the
//  actual commerce libs (no HTTP, no engine edits): offer → price → place → record
//  seller base → SETTLE (margin earns) → accept-gates-dispatch → Customer-ID
//  isolation → return/refund, plus kill-switch + loss-proof. Read/verify only.
//  Run: php tests/commerce/verify_engine_e2e.php
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
require_once SYSTEM_ROOT . '/core/helpers/LedgerEngine.php';
require_once SYSTEM_ROOT . '/lib/commerce/MarginEngine.php';
require_once SYSTEM_ROOT . '/lib/commerce/OrderabilityResolver.php';
require_once SYSTEM_ROOT . '/lib/commerce/AdminControlRoom.php';
require_once SYSTEM_ROOT . '/lib/commerce/SellerCatalogue.php';
require_once SYSTEM_ROOT . '/lib/commerce/SellerConsole.php';
require_once SYSTEM_ROOT . '/lib/commerce/CustomerId.php';
require_once SYSTEM_ROOT . '/lib/commerce/DeliveryPricing.php';
require_once SYSTEM_ROOT . '/lib/commerce/CommerceReturns.php';
$pdo = Database::getConnection();

$PASS = 0; $FAIL = 0;
function ok($label, $got, $exp = true) {
    global $PASS, $FAIL;
    $g = is_float($got) ? number_format($got, 2, '.', '') : $got;
    $e = is_float($exp) ? number_format($exp, 2, '.', '') : $exp;
    if ($g === $e) { $PASS++; printf("  PASS  %-52s got=%s\n", $label, var_export($g, true)); }
    else { $FAIL++; printf("  FAIL  %-52s got=%s exp=%s\n", $label, var_export($g, true), var_export($e, true)); }
}
function setcfg(PDO $p,$k,$v){ $p->prepare("INSERT INTO app_settings (setting_key,setting_value,value_type,is_public) VALUES (?,?,'text',0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$k,$v]); }
function cleanup(PDO $p){
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $ids=$p->query("SELECT id FROM orders WHERE order_number LIKE 'E2E-%'")->fetchAll(PDO::FETCH_COLUMN);
    if($ids){$in=implode(',',array_map('intval',$ids)); $p->exec("DELETE FROM wallet_transactions WHERE (reference_type='order' AND reference_id IN ($in)) OR order_id IN ($in)"); $p->exec("DELETE FROM commerce_routing_signals WHERE order_id IN ($in) OR sub_order_id IN ($in)"); $p->exec("DELETE FROM order_items WHERE order_id IN ($in)"); $p->exec("DELETE FROM commerce_returns WHERE order_id IN ($in)");}
    $p->exec("DELETE FROM orders WHERE order_number LIKE 'E2E-%'");
    $p->exec("DELETE i FROM inventory i JOIN products pr ON pr.id=i.product_id WHERE pr.slug LIKE 'e2et-%' OR pr.name LIKE 'E2E %'");
    $p->exec("DELETE FROM products WHERE slug LIKE 'e2et-%' OR name LIKE 'E2E %'");
    $p->exec("DELETE FROM stores WHERE slug LIKE 'e2et-%'");
    $p->exec("DELETE cci FROM commerce_customer_ids cci JOIN users u ON u.id=cci.user_id WHERE u.email LIKE 'e2et-%@wtg.test'");
    $p->exec("DELETE FROM vendors WHERE slug LIKE 'e2et-%'");
    $p->exec("DELETE FROM users WHERE email LIKE 'e2et-%@wtg.test'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e,$name='E2E'){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES (?,?, 'vendor_shopping','active',NOW(),NOW())")->execute([$name,$e]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',1,10,NOW(),NOW())")->execute([$uid,'E2E '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkStore(PDO $p,$slug,$v,$city){ $p->prepare("INSERT INTO stores (ownership,owner_vendor_id,city_id,name,slug,status,lat,lng,service_radius_km,created_at,updated_at) VALUES ('vendor',?,?,?,?,'active',29.2,79.5,50,NOW(),NOW())")->execute([$v,$city,'E2E S '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkOrder(PDO $p,$num,$uid,$parent,$vendor,$store,$city,$sub,$tot,$ds,$status,$pm='online',$sellerSub=null){ $p->prepare("INSERT INTO orders (order_number,parent_order_id,user_id,vendor_id,store_id,city_id,status,payment_status,payment_method,subtotal,seller_subtotal,delivery_fee,total,shipping_address,delivery_status,ledger_status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,0,?,'{}',?,'pending',NOW(),NOW())")->execute([$num,$parent,$uid,$vendor,$store,$city,$status,$pm==='cod'?'unpaid':'paid',$pm,$sub,$sellerSub,$tot,$ds]); return (int)$p->lastInsertId(); }
function mkItem(PDO $p,$oid,$pid,$vendor,$qty,$unit){ $p->prepare("INSERT INTO order_items (order_id,product_id,vendor_id,product_name,quantity,unit_price,line_total,created_at) VALUES (?,?,?,?,?,?,?,NOW())")->execute([$oid,$pid,$vendor,'E2E item',$qty,$unit,$unit*$qty]); }
function txPlatform(PDO $p,$parent){ $s=$p->prepare("SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE entity_type='platform' AND reference_type='order' AND reference_id=?"); $s->execute([$parent]); return (float)$s->fetchColumn(); }

cleanup($pdo);
$snap=[]; foreach(['commerce_margin_slabs','commerce_margin_multiplier','commerce_margin_policy','commerce_enabled'] as $k){ $s=$pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key=?"); $s->execute([$k]); $snap[$k]=$s->fetchColumn(); }
register_shutdown_function(function() use($pdo,$snap){ foreach($snap as $k=>$v){ if($v!==false&&$v!==null){ try{ $pdo->prepare("UPDATE app_settings SET setting_value=? WHERE setting_key=?")->execute([$v,$k]); }catch(\Throwable $e){} } } });
setcfg($pdo,'commerce_margin_slabs','{"enabled":true,"bands":[{"up_to":500,"flat":20},{"up_to":5000,"pct":8},{"pct":2}],"by_category":{}}');
setcfg($pdo,'commerce_margin_multiplier','1.0');
setcfg($pdo,'commerce_margin_policy','{"platform_margin_pct":0,"platform_margin_flat":0,"min_margin_flat":0,"rounding":"none"}');
setcfg($pdo,'commerce_enabled','1');

$cityId=(int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if(!$cityId){ $pdo->exec("INSERT INTO cities (name,slug) VALUES ('E2E City','e2et-city')"); $cityId=(int)$pdo->lastInsertId(); }
$uS=mkUser($pdo,'e2et-seller@wtg.test'); $V=mkVendor($pdo,$uS,'e2et-v'); $St=mkStore($pdo,'e2et-s',$V,$cityId);
$uO=mkUser($pdo,'e2et-other@wtg.test'); $V2=mkVendor($pdo,$uO,'e2et-v2'); mkStore($pdo,'e2et-s2',$V2,$cityId);
$uc=mkUser($pdo,'e2et-cust@wtg.test','Ramesh Secret');

echo "\n== [1] catalogue: offer priced by slab + base recorded, verification gate ==\n";
$off=SellerCatalogue::createOffer($pdo,$V,['name'=>'E2E Berger 4L','base_price'=>1000,'stock'=>10,'match_key'=>'e2et-mk']);
$pid=(int)$off['product_id'];
ok('offer customer price 1080', (float)$off['customer_price'], 1080.0);
ok('seller_base recorded 1000', SellerCatalogue::sellerBaseOf($pdo,$pid), 1000.0);
ok('draft NOT orderable',       OrderabilityResolver::check($pdo,$pid,$cityId)['reason'], 'inactive_product');
SellerCatalogue::approve($pdo,$pid);
ok('approved → orderable',      OrderabilityResolver::check($pdo,$pid,$cityId)['reason'], 'ok');

echo "\n== [2] kill-switch + loss-proof floor ==\n";
setcfg($pdo,'commerce_enabled','0');
ok('kill-switch → commerce_paused', OrderabilityResolver::check($pdo,$pid,$cityId)['reason'], 'commerce_paused');
setcfg($pdo,'commerce_enabled','1');
ok('kill-switch restored → ok',     OrderabilityResolver::check($pdo,$pid,$cityId)['reason'], 'ok');
ok('loss-proof: 160 vs 30 → ok',    MarginEngine::lossProofOk(160,30,0), true);
ok('loss-proof: 20 vs 30 → reject', MarginEngine::lossProofOk(20,30,0), false);

echo "\n== [3] place order → record seller base → SETTLE (margin earns to ledger) ==\n";
$P=mkOrder($pdo,'E2E-SETTLE-P',$uc,null,$V,$St,$cityId,2160,2160,'accepted','delivered','online',null);
$S=mkOrder($pdo,'E2E-SETTLE-S',$uc,$P,$V,$St,$cityId,2160,2160,'accepted','delivered','online',null);
mkItem($pdo,$S,$pid,$V,2,1080);
$psb=SellerCatalogue::persistSellerBase($pdo,$P);
ok('seller_base_price on line = 1000', (float)$pdo->query("SELECT seller_base_price FROM order_items WHERE order_id=$S")->fetchColumn(), 1000.0);
ok('seller_subtotal on sub = 2000',    (float)$pdo->query("SELECT seller_subtotal FROM orders WHERE id=$S")->fetchColumn(), 2000.0);
\Core\Helpers\LedgerEngine::processCommerceOrderSettlement($P,$pdo);
ok('platform margin credited = 160 (spread earns)', txPlatform($pdo,$P), 160.0);

echo "\n== [4] accept-gates-dispatch (A) ==\n";
$aSnap=$pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='commerce_accept_gates_dispatch'")->fetchColumn();
setcfg($pdo,'commerce_accept_gates_dispatch','1');
$P2=mkOrder($pdo,'E2E-ACC-P',$uc,null,$V,$St,$cityId,1080,1080,'pending','pending','online',null);
$S2=mkOrder($pdo,'E2E-ACC-S',$uc,$P2,$V,$St,$cityId,1080,1080,'offered','pending','online',null);
mkItem($pdo,$S2,$pid,$V,1,1080);
$acc=SellerConsole::accept($pdo,$V,$S2);
ok('gated accept ok',          $acc['ok'] ?? null, true);
ok('gated flag true',          $acc['gated'] ?? null, true);
ok('offered → accepted',       $pdo->query("SELECT delivery_status FROM orders WHERE id=$S2")->fetchColumn(), 'accepted');
if($aSnap!==false&&$aSnap!==null) setcfg($pdo,'commerce_accept_gates_dispatch',(string)$aSnap);

echo "\n== [5] Customer-ID: opaque, isolated per seller, no PII ==\n";
$ref=CustomerId::forUser($pdo,$uc);
ok('opaque ref (starts C)',    ($ref[0] ?? '') === 'C', true);
$hist=SellerConsole::customerHistory($pdo,$V,$ref);
ok('seller sees own history ≥1', $hist['item_count'] >= 1, true);
ok('other seller sees nothing',  SellerConsole::customerHistory($pdo,$V2,$ref)['item_count'], 0);
ok('no PII (customer name) leaked', str_contains(json_encode($hist),'Ramesh'), false);

echo "\n== [6] return / refund ==\n";
$R=mkOrder($pdo,'E2E-RET-P',$uc,null,$V,$St,$cityId,1080,1080,'delivered','delivered','online',1000);
mkItem($pdo,$R,$pid,$V,1,1080);
$pdo->prepare("UPDATE orders SET delivered_at=NOW() WHERE id=?")->execute([$R]);
$req=CommerceReturns::requestReturn($pdo,$R,$uc,'damaged on arrival');
ok('return requested',         $req['ok'] ?? null, true);
$rid=(int)($req['return_id'] ?? 0);
ok('ops approve',              CommerceReturns::approve($pdo,$rid,1), true);
ok('rider received',           CommerceReturns::markReceived($pdo,$rid,1), true);
$ref2=CommerceReturns::refund($pdo,$rid,1);
ok('refund ok',                $ref2['ok'] ?? null, true);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("E2E VERIFY RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
