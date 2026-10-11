<?php
// ─────────────────────────────────────────────────────────────────────────────
//  P3 PROOF HARNESS — READY PAINT, END-TO-END (first money path).
//  One ready paint order flows through the FROZEN core + Paint P1/P2, founder-
//  free, no engine edit:
//    external seller register → KYC → admin approve (vendor active) → online
//    → paint catalogue offer (paint attributes + category) priced by the
//      PAINT-CATEGORY slab (S12 by_category × P1 category) → verify-gate approve
//    → orderability OK (paint unit ON, ready) [custom-tint sibling stays blocked]
//    → customer places order (seller_base → seller_subtotal) → accept-gates
//    → delivery assigned → pickup → OTP deliver
//    → SETTLE: platform earns the margin spread, seller gets base, rider gets
//      payout — founder puts in nothing (loss-proof ≥ 0)
//    → coverage calculator reads the product (P2) → returnable (sealed) return OK
//  Run: php tests/commerce/p3_ready_paint_e2e.php   (local 10.11; idempotent)
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
foreach (['MarginEngine','OrderabilityResolver','UnitConfig','PaintUnit','SellerCatalogue','SellerConsole',
          'KycService','CommerceDelivery','CustomerId','CommerceReturns','CoverageCalculator'] as $c) {
    require_once SYSTEM_ROOT . "/lib/commerce/$c.php";
}
$pdo = Database::getConnection();

$PASS = 0; $FAIL = 0;
function ok($label, $got, $exp = true) {
    global $PASS, $FAIL;
    $g = is_float($got) ? number_format($got, 2, '.', '') : $got;
    $e = is_float($exp) ? number_format($exp, 2, '.', '') : $exp;
    if ($g === $e) { $PASS++; printf("  PASS  %-54s got=%s\n", $label, var_export($g, true)); }
    else { $FAIL++; printf("  FAIL  %-54s got=%s exp=%s\n", $label, var_export($g, true), var_export($e, true)); }
}
function setcfg(PDO $p,$k,$v){ $p->prepare("INSERT INTO app_settings (setting_key,setting_value,value_type,is_public) VALUES (?,?,'text',0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$k,$v]); }
function txPlatform(PDO $p,$ref){ $s=$p->prepare("SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE entity_type='platform' AND reference_type='order' AND reference_id=?"); $s->execute([$ref]); return (float)$s->fetchColumn(); }
function txSum(PDO $p,$etype,$ref){ $s=$p->prepare("SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE entity_type=? AND reference_type='order' AND reference_id=?"); $s->execute([$etype,$ref]); return (float)$s->fetchColumn(); }

function cleanup(PDO $p){
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $ids=$p->query("SELECT id FROM orders WHERE order_number LIKE 'P3T-%'")->fetchAll(PDO::FETCH_COLUMN);
    if($ids){$in=implode(',',array_map('intval',$ids));
        $p->exec("DELETE FROM wallet_transactions WHERE (reference_type='order' AND reference_id IN ($in)) OR order_id IN ($in)");
        $p->exec("DELETE FROM commerce_routing_signals WHERE order_id IN ($in) OR sub_order_id IN ($in)");
        $p->exec("DELETE FROM deliveries WHERE order_id IN ($in)");
        $p->exec("DELETE FROM commerce_events WHERE order_id IN ($in)");
        $p->exec("DELETE FROM commerce_order_flags WHERE order_id IN ($in)");
        $p->exec("DELETE FROM commerce_returns WHERE order_id IN ($in)");
        $p->exec("DELETE FROM order_items WHERE order_id IN ($in)");
    }
    $p->exec("DELETE FROM orders WHERE order_number LIKE 'P3T-%'");
    $uids=$p->query("SELECT id FROM users WHERE email LIKE 'p3t-%@wtg.test'")->fetchAll(PDO::FETCH_COLUMN);
    if($uids){$in=implode(',',array_map('intval',$uids));
        $p->exec("DELETE FROM commerce_rider_kyc WHERE rider_user_id IN ($in)");
        $p->exec("DELETE FROM delivery_riders WHERE rider_id IN ($in)");
        $p->exec("DELETE FROM commerce_customer_ids WHERE user_id IN ($in)");
    }
    $vids=$p->query("SELECT id FROM vendors WHERE slug LIKE 'p3t-%'")->fetchAll(PDO::FETCH_COLUMN);
    if($vids){$in=implode(',',array_map('intval',$vids)); $p->exec("DELETE FROM commerce_seller_kyc WHERE vendor_id IN ($in)");}
    $p->exec("DELETE i FROM inventory i JOIN products pr ON pr.id=i.product_id WHERE pr.name LIKE 'P3 %' OR pr.slug LIKE 's%-p3-%'");
    $p->exec("DELETE FROM products WHERE name LIKE 'P3 %'");
    $p->exec("DELETE FROM stores   WHERE slug LIKE 'p3t-%'");
    $p->exec("DELETE FROM vendors  WHERE slug LIKE 'p3t-%'");
    if($uids){$in=implode(',',array_map('intval',$uids)); $p->exec("DELETE FROM users WHERE id IN ($in)");}
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function registerUser(PDO $p,$email,$role,$name='P3'){ $p->prepare("INSERT INTO users (name,email,phone,role,status,password,phone_verified_at,created_at,updated_at) VALUES (?,?,?,?, 'active', ?, NOW(), NOW(), NOW())")->execute([$name,$email,substr(str_replace(['@','.','-'],'',$email),0,14),$role,password_hash('P3#pass123',PASSWORD_BCRYPT)]); return (int)$p->lastInsertId(); }
function mkVendorPending(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','pending',0,10,NOW(),NOW())")->execute([$uid,'P3 '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkStore(PDO $p,$slug,$v,$city){ $p->prepare("INSERT INTO stores (ownership,owner_vendor_id,city_id,name,slug,status,lat,lng,service_radius_km,created_at,updated_at) VALUES ('vendor',?,?,?,?,'active',29.2,79.5,50,NOW(),NOW())")->execute([$v,$city,'P3 S '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkOrder(PDO $p,$num,$uid,$parent,$vendor,$store,$city,$sub,$tot,$ds,$status,$pm='cod',$sellerSub=null){ $p->prepare("INSERT INTO orders (order_number,parent_order_id,user_id,vendor_id,store_id,city_id,status,payment_status,payment_method,subtotal,seller_subtotal,delivery_fee,total,shipping_address,delivery_status,ledger_status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,0,?,'{}',?,'pending',NOW(),NOW())")->execute([$num,$parent,$uid,$vendor,$store,$city,$status,$pm==='cod'?'unpaid':'paid',$pm,$sub,$sellerSub,$tot,$ds]); return (int)$p->lastInsertId(); }
function mkItem(PDO $p,$oid,$pid,$vendor,$qty,$unit){ $p->prepare("INSERT INTO order_items (order_id,product_id,vendor_id,product_name,quantity,unit_price,line_total,created_at) VALUES (?,?,?,?,?,?,?,NOW())")->execute([$oid,$pid,$vendor,'P3 paint item',$qty,$unit,$unit*$qty]); }
function mkDelivery(PDO $p,$oid,$driver,$city){ $p->prepare("INSERT INTO deliveries (order_id,city_id,status,driver_id,pickup_lat,pickup_lng,drop_lat,drop_lng,price,rider_payout,platform_fee,assigned_at,accepted_at,assign_attempts,created_at) VALUES (?,?, 'assigned',?,29.21,79.51,29.22,79.52,40,30,10,NOW(),NULL,0,NOW())")->execute([$oid,$city,$driver]); return (int)$p->lastInsertId(); }
function ocol(PDO $p,$oid,$c){ $s=$p->prepare("SELECT $c FROM orders WHERE id=?"); $s->execute([$oid]); return $s->fetchColumn(); }

cleanup($pdo);
// config snapshot/restore
$snap=[]; foreach(['commerce_margin_slabs','commerce_margin_multiplier','commerce_margin_policy','commerce_enabled','commerce_accept_gates_dispatch'] as $k){ $s=$pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key=?"); $s->execute([$k]); $snap[$k]=$s->fetchColumn(); }
register_shutdown_function(function() use($pdo,$snap){ foreach($snap as $k=>$v){ try{ if($v!==false&&$v!==null){ $pdo->prepare("INSERT INTO app_settings (setting_key,setting_value,value_type,is_public) VALUES (?,?,'text',0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$k,$v]); } }catch(\Throwable $e){} } });

$catId = (int)$pdo->query("SELECT id FROM categories WHERE slug='paint-interior-emulsion' LIMIT 1")->fetchColumn();
// PAINT-CATEGORY slab: base ≤2000 → 5% (thin paint margin); else 3%. Proves S12 by_category × P1 category.
setcfg($pdo,'commerce_margin_slabs', '{"enabled":true,"bands":[{"up_to":500,"flat":20},{"up_to":5000,"pct":8},{"pct":2}],"by_category":{"'.$catId.'":{"bands":[{"up_to":2000,"pct":5},{"pct":3}]}}}');
setcfg($pdo,'commerce_margin_multiplier','1.0');
setcfg($pdo,'commerce_margin_policy','{"platform_margin_pct":0,"platform_margin_flat":0,"min_margin_flat":0,"rounding":"none"}');
setcfg($pdo,'commerce_enabled','1');
setcfg($pdo,'commerce_accept_gates_dispatch','1');

$cityId=(int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if(!$cityId){ $pdo->exec("INSERT INTO cities (name,slug) VALUES ('P3 City','p3t-city')"); $cityId=(int)$pdo->lastInsertId(); }

echo "\n== [1] external seller: register → KYC → approve → online ==\n";
$uS=registerUser($pdo,'p3t-seller@wtg.test','vendor_shopping','Paint Dukaan'); $V=mkVendorPending($pdo,$uS,'p3t-v'); $St=mkStore($pdo,'p3t-s',$V,$cityId);
ok('[1] vendor starts pending', $pdo->query("SELECT status FROM vendors WHERE id=$V")->fetchColumn(), 'pending');
KycService::sellerUpsert($pdo,$V,['owner_name'=>'Suresh','shop_address'=>'Paint Bazaar','pincode'=>'263139','pan'=>'ABCDE1234F','upi_id'=>'suresh@upi','shop_photo_media_id'=>'301','id_proof_media_id'=>'302','address_proof_media_id'=>'303']);
KycService::sellerSubmit($pdo,$V); // phone already verified on registerUser
KycService::sellerReview($pdo,$V,true,null,1);
ok('[1] approve → vendor ACTIVE', $pdo->query("SELECT status FROM vendors WHERE id=$V")->fetchColumn(), 'active');
SellerConsole::setAvailability($pdo,$V,'online');
ok('[1] seller ONLINE', SellerConsole::availability($pdo,$V)['status'], 'online');

echo "\n== [2] paint catalogue offer priced by the PAINT-CATEGORY slab ==\n";
$attrs = ['brand'=>'Berger','product_line'=>'Easy Clean','tier'=>'mid','product_name'=>'Easy Clean 4L','category'=>'interior-emulsion','surface'=>'interior','finish'=>'sheen','pack_size'=>'4','pack_unit'=>'L','coverage_value'=>'125','coverage_unit'=>'sqft_per_l','coverage_basis_coats'=>'2','recommended_coats'=>'2','warranty_years'=>'6','shade_mode'=>'ready','returnable'=>'yes'];
$off = SellerCatalogue::createOffer($pdo,$V,['name'=>'P3 Berger Easy Clean 4L','base_price'=>1000,'stock'=>10,'category_id'=>$catId,'match_key'=>'p3-berger-ec-4l','attributes'=>$attrs]);
$pid=(int)$off['product_id'];
ok('[2] paint-category slab margin = 50 (5% of 1000)', (float)$off['margin'], 50.0);
ok('[2] customer price = 1050 (base + paint slab)',     (float)$off['customer_price'], 1050.0);
ok('[2] seller_base recorded = 1000',                   SellerCatalogue::sellerBaseOf($pdo,$pid), 1000.0);
ok('[2] lands as draft (verify gate)',                  $off['status'], 'draft');
ok('[2] draft NOT orderable',                           OrderabilityResolver::check($pdo,$pid,$cityId)['reason'], 'inactive_product');
SellerCatalogue::approve($pdo,$pid);
UnitConfig::resetCache();
ok('[2] approved → orderable (paint unit ON, ready)',   OrderabilityResolver::check($pdo,$pid,$cityId)['reason'], 'ok');

echo "\n== [3] paint-unit gate still guards custom-tint (P1) ==\n";
$offT = SellerCatalogue::createOffer($pdo,$V,['name'=>'P3 Tintable Base 4L','base_price'=>1200,'stock'=>5,'category_id'=>$catId,'match_key'=>'p3-tint-4l','attributes'=>['brand'=>'Indigo','product_name'=>'Interior Gold','category'=>'interior-emulsion','pack_size'=>'4','pack_unit'=>'L','shade_mode'=>'tintable','custom_tintable'=>'yes','base_type'=>'white','returnable'=>'no']]);
SellerCatalogue::approve($pdo,(int)$offT['product_id']);
ok('[3] custom-tint blocked (custom_tint_off)', OrderabilityResolver::check($pdo,(int)$offT['product_id'],$cityId)['reason'], 'custom_tint_off');

echo "\n== [4] customer order → seller_base → accept-gates ==\n";
$uc=registerUser($pdo,'p3t-cust@wtg.test','customer','Grahak Secret');
$P=mkOrder($pdo,'P3T-P',$uc,null,$V,$St,$cityId,1050,1090,'pending','pending','cod',null); // total = 1050 + 40 delivery
$S=mkOrder($pdo,'P3T-S',$uc,$P,$V,$St,$cityId,1050,1050,'offered','pending','cod',null);
mkItem($pdo,$S,$pid,$V,1,1050);
SellerCatalogue::persistSellerBase($pdo,$P);
ok('[4] order_items.seller_base_price = 1000', (float)$pdo->query("SELECT seller_base_price FROM order_items WHERE order_id=$S")->fetchColumn(), 1000.0);
ok('[4] sub.seller_subtotal = 1000',           (float)ocol($pdo,$S,'seller_subtotal'), 1000.0);
$acc=SellerConsole::accept($pdo,$V,$S);
ok('[4] seller accept ok + gated',             (($acc['ok']??false) && ($acc['gated']??false)), true);
ok('[4] offered → accepted',                   ocol($pdo,$S,'delivery_status'), 'accepted');

echo "\n== [5] delivery → pickup → OTP deliver ==\n";
$uR=registerUser($pdo,'p3t-rider@wtg.test','delivery','Rider'); $pdo->prepare("INSERT INTO delivery_riders (rider_id,city_id,is_online,lat,lng,last_ping_at,vehicle_type,max_active,active_count,created_at,updated_at) VALUES (?,?,1,29.211,79.511,NOW(),'bike',3,0,NOW(),NOW())")->execute([$uR,$cityId]);
$dJ=mkDelivery($pdo,$P,$uR,$cityId);
ok('[5] pickup → picked', (CommerceDelivery::pickup($pdo,$dJ,$uR,'photo:pickup.jpg')['status']??null), 'picked');
$otp=CommerceDelivery::issueOtp($pdo,$P);
ok('[5] OTP issued (4 digits)', (bool)preg_match('/^\d{4}$/',(string)$otp), true);
ok('[5] correct OTP → delivered', (CommerceDelivery::deliver($pdo,$dJ,$uR,(string)$otp,'photo:deliver.jpg')['status']??null), 'delivered');

echo "\n== [6] SETTLE founder-free (platform margin spread, seller base, rider payout) ==\n";
$pdo->prepare("UPDATE orders SET status='delivered', delivery_status='delivered', delivered_at=NOW() WHERE id IN ($P,$S)")->execute();
\Core\Helpers\LedgerEngine::processCommerceOrderSettlement($P,$pdo);
$plat=txPlatform($pdo,$P); $drv=txSum($pdo,'driver',$P);
$itemSpread = (float)ocol($pdo,$S,'subtotal') - (float)ocol($pdo,$S,'seller_subtotal');
ok('[6] item margin spread = 50 (1050 − 1000)', $itemSpread, 50.0);
ok('[6] platform earns spread + delivery fee = 60', $plat, 60.0); // 50 items + 10 delivery platform_fee
ok('[6] rider credited payout (30)',            $drv, 30.0);
ok('[6] founder-free: platform margin ≥ 0 (loss-proof)', $plat >= 0.0, true);

echo "\n== [7] coverage calculator reads the paint product (P2) ==\n";
$est = CoverageCalculator::estimateForProduct($pdo,$pid,['net_area_sqft'=>400]);
ok('[7] estimate uses product coverage → 3.52L', (float)$est['estimated_litres'], 3.52);
ok('[7] carries Estimated label',                $est['label'], 'Estimated requirement');

echo "\n== [8] ready paint is returnable (sealed), custom-tint would not be ==\n";
$R=mkOrder($pdo,'P3T-RET-P',$uc,null,$V,$St,$cityId,1050,1050,'delivered','delivered','cod',1000);
mkItem($pdo,$R,$pid,$V,1,1050);
$pdo->prepare("UPDATE orders SET delivered_at=NOW() WHERE id=?")->execute([$R]);
$req=CommerceReturns::requestReturn($pdo,$R,$uc,'sealed, unopened');
ok('[8] ready paint return allowed', ($req['ok']??false), true);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("P3 READY-PAINT E2E RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
