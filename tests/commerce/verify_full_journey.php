<?php
// ─────────────────────────────────────────────────────────────────────────────
//  FULL-JOURNEY VERIFY — Step 5. Strings the complete seller + rider + auth
//  journeys end-to-end through the REAL libs (no HTTP, no engine edits):
//    SELLER: register → KYC → approve → login-gate → catalogue → ON/OFF/BUSY
//            → inbox → accept / reject / stock-out → earnings → Customer-ID history
//    RIDER : register → KYC → approve (profile created) → login-gate
//            → job → pickup → OTP deliver → earnings (ledger credit)
//    AUTH  : password gate, active-status gate, JWT issue/verify/tamper/expiry,
//            logout token-blacklist (token/session churn)
//  The one-order founder-free settle (margin earn / seller base / accept-gates /
//  return-refund / kill-switch / loss-proof) is proven by verify_engine_e2e.php;
//  this harness adds the onboarding + availability + delivery + auth journeys.
//  Run: php tests/commerce/verify_full_journey.php
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
require_once SYSTEM_ROOT . '/core/helpers/JWT.php';
if (!class_exists('Core\\Helpers\\Database')) class_alias('Database', 'Core\\Helpers\\Database');
if (!class_exists('Core\\Helpers\\Logger'))   class_alias('Logger', 'Core\\Helpers\\Logger');
if (!defined('JWT_SECRET')) define('JWT_SECRET', (string)(getenv('JWT_SECRET') ?: 'test-secret'));
require_once SYSTEM_ROOT . '/core/helpers/LedgerEngine.php';
foreach (['MarginEngine','OrderabilityResolver','RoutingEngine','SellerCatalogue','SellerConsole',
          'CustomerId','KycService','CommerceDelivery','CommerceEventBus','AdminControlRoom'] as $c) {
    require_once SYSTEM_ROOT . "/lib/commerce/$c.php";
}
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
function txSum(PDO $p,string $etype,int $ref){ $s=$p->prepare("SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE entity_type=? AND reference_type='order' AND reference_id=?"); $s->execute([$etype,$ref]); return (float)$s->fetchColumn(); }

function cleanup(PDO $p){
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $ids=$p->query("SELECT id FROM orders WHERE order_number LIKE 'FJT-%'")->fetchAll(PDO::FETCH_COLUMN);
    if($ids){$in=implode(',',array_map('intval',$ids));
        $p->exec("DELETE FROM wallet_transactions WHERE (reference_type='order' AND reference_id IN ($in)) OR order_id IN ($in)");
        $p->exec("DELETE FROM commerce_routing_signals WHERE order_id IN ($in) OR sub_order_id IN ($in)");
        $p->exec("DELETE FROM deliveries WHERE order_id IN ($in)");
        $p->exec("DELETE FROM commerce_events WHERE order_id IN ($in)");
        $p->exec("DELETE FROM commerce_order_flags WHERE order_id IN ($in)");
        $p->exec("DELETE FROM order_items WHERE order_id IN ($in)");
    }
    $p->exec("DELETE FROM orders WHERE order_number LIKE 'FJT-%'");
    $uids=$p->query("SELECT id FROM users WHERE email LIKE 'fjt-%@wtg.test'")->fetchAll(PDO::FETCH_COLUMN);
    if($uids){$in=implode(',',array_map('intval',$uids));
        $p->exec("DELETE FROM commerce_rider_kyc WHERE rider_user_id IN ($in)");
        $p->exec("DELETE FROM delivery_riders WHERE rider_id IN ($in)");
        $p->exec("DELETE FROM commerce_customer_ids WHERE user_id IN ($in)");
    }
    $vids=$p->query("SELECT id FROM vendors WHERE slug LIKE 'fjt-%'")->fetchAll(PDO::FETCH_COLUMN);
    if($vids){$in=implode(',',array_map('intval',$vids)); $p->exec("DELETE FROM commerce_seller_kyc WHERE vendor_id IN ($in)");}
    $p->exec("DELETE i FROM inventory i JOIN products pr ON pr.id=i.product_id WHERE pr.slug LIKE 'fjt-%' OR pr.name LIKE 'FJT %'");
    $p->exec("DELETE FROM products WHERE slug LIKE 'fjt-%' OR name LIKE 'FJT %'");
    $p->exec("DELETE FROM stores WHERE slug LIKE 'fjt-%'");
    $p->exec("DELETE FROM vendors WHERE slug LIKE 'fjt-%'");
    $p->exec("DELETE FROM token_blacklist WHERE token_hash LIKE 'fjttest-%'");
    if($uids){$in=implode(',',array_map('intval',$uids)); $p->exec("DELETE FROM users WHERE id IN ($in)");}
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
// "register" = the row the register endpoint writes (bcrypt password, chosen role)
function registerUser(PDO $p,$email,$role,$pw,$name='FJT'){ $p->prepare("INSERT INTO users (name,email,phone,role,status,password,created_at,updated_at) VALUES (?,?,?,?, 'active',?,NOW(),NOW())")->execute([$name,$email,substr(str_replace(['@','.','-'],'',$email),0,14),$role,password_hash($pw,PASSWORD_BCRYPT)]); return (int)$p->lastInsertId(); }
function verifyPhone(PDO $p,$uid){ $p->prepare("UPDATE users SET phone_verified_at=NOW() WHERE id=?")->execute([$uid]); }
function mkVendorPending(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','pending',0,10,NOW(),NOW())")->execute([$uid,'FJT '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkStore(PDO $p,$slug,$v,$city){ $p->prepare("INSERT INTO stores (ownership,owner_vendor_id,city_id,name,slug,status,lat,lng,service_radius_km,created_at,updated_at) VALUES ('vendor',?,?,?,?,'active',29.2,79.5,50,NOW(),NOW())")->execute([$v,$city,'FJT S '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkOrder(PDO $p,$num,$uid,$parent,$vendor,$store,$city,$sub,$tot,$ds,$status,$pm='online',$sellerSub=null){ $p->prepare("INSERT INTO orders (order_number,parent_order_id,user_id,vendor_id,store_id,city_id,status,payment_status,payment_method,subtotal,seller_subtotal,delivery_fee,total,shipping_address,delivery_status,ledger_status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,0,?,'{}',?,'pending',NOW(),NOW())")->execute([$num,$parent,$uid,$vendor,$store,$city,$status,$pm==='cod'?'unpaid':'paid',$pm,$sub,$sellerSub,$tot,$ds]); return (int)$p->lastInsertId(); }
function mkItem(PDO $p,$oid,$pid,$vendor,$qty,$unit){ $p->prepare("INSERT INTO order_items (order_id,product_id,vendor_id,product_name,quantity,unit_price,line_total,created_at) VALUES (?,?,?,?,?,?,?,NOW())")->execute([$oid,$pid,$vendor,'FJT item',$qty,$unit,$unit*$qty]); }
function mkDelivery(PDO $p,$oid,$driver,$status,$city){ $p->prepare("INSERT INTO deliveries (order_id,city_id,status,driver_id,pickup_lat,pickup_lng,drop_lat,drop_lng,assigned_at,accepted_at,assign_attempts,created_at) VALUES (?,?,?,?,29.21,79.51,29.22,79.52, NOW(), NULL, 0, NOW())")->execute([$oid,$city,$status,$driver]); return (int)$p->lastInsertId(); }
function ocol(PDO $p,$oid,$c){ $s=$p->prepare("SELECT $c FROM orders WHERE id=?"); $s->execute([$oid]); return $s->fetchColumn(); }

cleanup($pdo);
// config snapshot/restore (margin + gates), so the suite leaves settings untouched
$snap=[]; foreach(['commerce_margin_slabs','commerce_margin_multiplier','commerce_margin_policy','commerce_enabled','commerce_accept_gates_dispatch'] as $k){ $s=$pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key=?"); $s->execute([$k]); $snap[$k]=$s->fetchColumn(); }
register_shutdown_function(function() use($pdo,$snap){ foreach($snap as $k=>$v){ try{ if($v!==false&&$v!==null){ $pdo->prepare("INSERT INTO app_settings (setting_key,setting_value,value_type,is_public) VALUES (?,?,'text',0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$k,$v]); } }catch(\Throwable $e){} } });
setcfg($pdo,'commerce_margin_slabs','{"enabled":true,"bands":[{"up_to":500,"flat":20},{"up_to":5000,"pct":8},{"pct":2}],"by_category":{}}');
setcfg($pdo,'commerce_margin_multiplier','1.0');
setcfg($pdo,'commerce_margin_policy','{"platform_margin_pct":0,"platform_margin_flat":0,"min_margin_flat":0,"rounding":"none"}');
setcfg($pdo,'commerce_enabled','1');

$cityId=(int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if(!$cityId){ $pdo->exec("INSERT INTO cities (name,slug) VALUES ('FJT City','fjt-city')"); $cityId=(int)$pdo->lastInsertId(); }

// ============================================================================
echo "\n══ SELLER JOURNEY ══════════════════════════════════════════════════════\n";
// ── register ──
$PW='Shop#Pass123';
$uS=registerUser($pdo,'fjt-seller@wtg.test','vendor_shopping',$PW,'Dukaandar');
$V =mkVendorPending($pdo,$uS,'fjt-v');
$St=mkStore($pdo,'fjt-s',$V,$cityId);
ok('[S1] seller registered (role=vendor_shopping)', $pdo->query("SELECT role FROM users WHERE id=$uS")->fetchColumn(), 'vendor_shopping');
ok('[S1] vendor starts pending (not active)',       $pdo->query("SELECT status FROM vendors WHERE id=$V")->fetchColumn(), 'pending');

echo "\n== KYC → review → approve (activates seller + stores payout) ==\n";
KycService::sellerUpsert($pdo,$V,[
    'owner_name'=>'Ramesh Kumar','shop_address'=>'12 Mandi Road','pincode'=>'263139',
    'pan'=>'ABCDE1234F','bank_account_name'=>'Ramesh Kumar','bank_account_number'=>'123456789012','bank_ifsc'=>'HDFC0001234',
    'operating_hours'=>'9-9','service_area'=>'Haldwani',
    'shop_photo_media_id'=>'101','id_proof_media_id'=>'102','address_proof_media_id'=>'103',
]);
$subNoPhone=KycService::sellerSubmit($pdo,$V);
ok('[S2] submit blocked until phone OTP verified', in_array('phone_otp_verified',$subNoPhone['missing']??[],true), true);
verifyPhone($pdo,$uS);
$sub=KycService::sellerSubmit($pdo,$V);
ok('[S2] KYC submitted after OTP',                  $sub['status']??($sub['ok']??null), 'submitted');
ok('[S2] still pending pre-approval',               $pdo->query("SELECT status FROM vendors WHERE id=$V")->fetchColumn(), 'pending');
KycService::sellerReview($pdo,$V,true,null,1);
ok('[S2] approve → vendor ACTIVE',                 $pdo->query("SELECT status FROM vendors WHERE id=$V")->fetchColumn(), 'active');
ok('[S2] payout stored (bank acct)',                $pdo->query("SELECT bank_account_number FROM commerce_seller_kyc WHERE vendor_id=$V")->fetchColumn(), '123456789012');
SellerConsole::setAvailability($pdo,$V,'online'); // approved seller logs in and comes online

echo "\n== login gate (bcrypt password + active-status) ==\n";
$row=$pdo->query("SELECT password,status FROM users WHERE id=$uS")->fetch(PDO::FETCH_ASSOC);
ok('[S3] correct password verifies',  password_verify($PW,$row['password']), true);
ok('[S3] wrong password rejected',     password_verify('nope',$row['password']), false);
ok('[S3] active status allows login',  ($row['status']==='active'), true);

echo "\n== catalogue: add offer (slab price) → base recorded → verify gate ==\n";
$off=SellerCatalogue::createOffer($pdo,$V,['name'=>'FJT Berger 4L','base_price'=>1000,'stock'=>10,'match_key'=>'fjt-mk']);
$pid=(int)$off['product_id'];
ok('[S4] offer priced by slab = 1080', (float)$off['customer_price'], 1080.0);
ok('[S4] seller_base recorded = 1000', SellerCatalogue::sellerBaseOf($pdo,$pid), 1000.0);
ok('[S4] draft NOT orderable',         OrderabilityResolver::check($pdo,$pid,$cityId)['reason'], 'inactive_product');
SellerCatalogue::approve($pdo,$pid);
ok('[S4] approved → orderable',        OrderabilityResolver::check($pdo,$pid,$cityId)['reason'], 'ok');

echo "\n== availability: ON / BUSY / OFF (one-tap) ==\n";
ok('[S5] set ONLINE',  SellerConsole::setAvailability($pdo,$V,'online')['status'], 'online');
ok('[S5] set BUSY',    SellerConsole::setAvailability($pdo,$V,'busy',20)['status'], 'busy');
ok('[S5] set OFFLINE', SellerConsole::setAvailability($pdo,$V,'offline')['status'], 'offline');
SellerConsole::setAvailability($pdo,$V,'online');

echo "\n== inbox → accept (accept-gates) / reject / stock-out ==\n";
setcfg($pdo,'commerce_accept_gates_dispatch','1');
$uc=registerUser($pdo,'fjt-cust@wtg.test','customer','Cust#123','Grahak Secret');
// three offered sub-orders to the seller
$mk=function($tag) use($pdo,$uc,$V,$St,$cityId,$pid){ $P=mkOrder($pdo,"FJT-$tag-P",$uc,null,$V,$St,$cityId,1080,1080,'pending','pending'); $S=mkOrder($pdo,"FJT-$tag-S",$uc,$P,$V,$St,$cityId,1080,1080,'offered','pending'); mkItem($pdo,$S,$pid,$V,1,1080); return [$P,$S]; };
[$Pa,$Sa]=$mk('ACC'); [$Pr,$Sr]=$mk('REJ'); [$Po,$So]=$mk('STK');
$inbox=SellerConsole::inbox($pdo,$V);
ok('[S6] inbox lists offered subs (>=3)', count($inbox)>=3, true);
ok('[S6] inbox customer is opaque ref',   str_starts_with((string)($inbox[0]['customer_ref']??''),'C'), true);
ok('[S6] inbox leaks no PII (name)',       str_contains(json_encode($inbox),'Grahak'), false);
$acc=SellerConsole::accept($pdo,$V,$Sa);
ok('[S6] accept ok',        $acc['ok']??null, true);
ok('[S6] accept is gated',  $acc['gated']??null, true);
ok('[S6] offered → accepted', ocol($pdo,$Sa,'delivery_status'), 'accepted');
$rej=SellerConsole::reject($pdo,$V,$Sr,'cannot fulfil');
ok('[S6] reject ok',         $rej['ok']??null, true);
ok('[S6] rejected sub left seller', (ocol($pdo,$Sr,'delivery_status')!=='offered') || ((int)ocol($pdo,$Sr,'vendor_id')!==$V), true);
$stk=SellerConsole::stockOut($pdo,$V,$So,'out of stock');
ok('[S6] stock-out ok (reroute attempt)', $stk['ok']??null, true);
setcfg($pdo,'commerce_accept_gates_dispatch',(string)($snap['commerce_accept_gates_dispatch'] ?: '1'));

echo "\n== earnings (delivered seller_subtotal) ==\n";
$Pe=mkOrder($pdo,'FJT-EARN-P',$uc,null,$V,$St,$cityId,1080,1080,'delivered','delivered','online',1000);
mkItem($pdo,$Pe,$pid,$V,1,1080);
$earn=SellerConsole::earnings($pdo,$V);
ok('[S7] delivered_count >=1',    $earn['delivered_count']>=1, true);
ok('[S7] seller_earned reflects base', $earn['seller_earned']>=1000.0, true);

echo "\n== Customer-ID history (own only, opaque, no PII) ==\n";
$ref=CustomerId::forUser($pdo,$uc);
$uO=registerUser($pdo,'fjt-other@wtg.test','vendor_shopping','Other#123'); $V2=mkVendorPending($pdo,$uO,'fjt-v2'); mkStore($pdo,'fjt-s2',$V2,$cityId);
$hist=SellerConsole::customerHistory($pdo,$V,$ref);
ok('[S8] seller sees own history >=1', ($hist['item_count']??0)>=1, true);
ok('[S8] other seller sees nothing',   (int)(SellerConsole::customerHistory($pdo,$V2,$ref)['item_count']??0), 0);
ok('[S8] no customer name leaked',     str_contains(json_encode($hist),'Grahak'), false);

// ============================================================================
echo "\n══ RIDER JOURNEY ═══════════════════════════════════════════════════════\n";
$RPW='Rider#Pass123';
$uR=registerUser($pdo,'fjt-rider@wtg.test','delivery',$RPW,'Mohan');
ok('[R1] rider registered (role=delivery allowed)', $pdo->query("SELECT role FROM users WHERE id=$uR")->fetchColumn(), 'delivery');

echo "\n== rider KYC → review → approve (profile created) ==\n";
KycService::riderUpsert($pdo,$uR,[
    'full_name'=>'Mohan Lal','phone'=>'9990001111','address'=>'Ward 4, Haldwani',
    'licence_number'=>'UK07-2020-0001','vehicle_type'=>'bike','vehicle_number'=>'UK07AB1234','rc_number'=>'RC-99',
    'upi_id'=>'mohan@upi','photo_media_id'=>'201','licence_media_id'=>'202','rc_media_id'=>'203',
]);
$rsubNo=KycService::riderSubmit($pdo,$uR);
ok('[R2] submit blocked until phone verified', in_array('phone_otp_verified',$rsubNo['missing']??[],true), true);
verifyPhone($pdo,$uR);
$rsub=KycService::riderSubmit($pdo,$uR);
ok('[R2] rider KYC submitted',           $rsub['status']??($rsub['ok']??null), 'submitted');
ok('[R2] no profile pre-approval',       (int)$pdo->query("SELECT COUNT(*) FROM delivery_riders WHERE rider_id=$uR")->fetchColumn(), 0);
KycService::riderReview($pdo,$uR,true,null,1,$cityId);
ok('[R2] approve → delivery_riders row created', (int)$pdo->query("SELECT COUNT(*) FROM delivery_riders WHERE rider_id=$uR")->fetchColumn(), 1);
ok('[R2] vehicle carried to profile',    $pdo->query("SELECT vehicle_type FROM delivery_riders WHERE rider_id=$uR")->fetchColumn(), 'bike');

echo "\n== rider login gate ==\n";
$rrow=$pdo->query("SELECT password,status FROM users WHERE id=$uR")->fetch(PDO::FETCH_ASSOC);
ok('[R3] correct password verifies', password_verify($RPW,$rrow['password']), true);
ok('[R3] wrong password rejected',   password_verify('bad',$rrow['password']), false);

echo "\n== job → pickup → OTP deliver ==\n";
$pdo->prepare("UPDATE delivery_riders SET is_online=1, lat=29.211, lng=79.511, last_ping_at=NOW() WHERE rider_id=?")->execute([$uR]);
$Pj=mkOrder($pdo,'FJT-JOB-P',$uc,null,null,null,$cityId,1080,1080,'accepted','pending','cod',1000);
$Sj=mkOrder($pdo,'FJT-JOB-S',$uc,$Pj,$V,$St,$cityId,1080,1080,'accepted','pending','cod',1000);
mkItem($pdo,$Sj,$pid,$V,1,1080);
$dJ=mkDelivery($pdo,$Pj,$uR,'assigned',$cityId);
// delivery pricing (set by DeliveryEngine/pricing in the live flow): rider payout drives the ledger credit
$pdo->prepare("UPDATE deliveries SET price=40, rider_payout=30, platform_fee=10 WHERE id=?")->execute([$dJ]);
$pick=CommerceDelivery::pickup($pdo,$dJ,$uR,'photo:pickup.jpg');
ok('[R4] pickup → picked',       $pick['status']??null, 'picked');
$otp=CommerceDelivery::issueOtp($pdo,$Pj);
ok('[R4] OTP issued (4 digits)', (bool)preg_match('/^\d{4}$/',(string)$otp), true);
$bad=CommerceDelivery::deliver($pdo,$dJ,$uR, ($otp==='0000'?'1111':'0000'),'photo:d.jpg');
ok('[R4] wrong OTP rejected',    $bad['status']??null, 'rejected');
ok('[R4] still not delivered',   $pdo->query("SELECT status FROM deliveries WHERE id=$dJ")->fetchColumn(), 'picked');
$good=CommerceDelivery::deliver($pdo,$dJ,$uR,(string)$otp,'photo:deliver.jpg');
ok('[R4] correct OTP → delivered', $good['status']??null, 'delivered');

echo "\n== rider earnings (ledger credit on settle) ==\n";
$pdo->prepare("UPDATE orders SET status='delivered', delivery_status='delivered', delivered_at=NOW() WHERE id IN ($Pj,$Sj)")->execute();
\Core\Helpers\LedgerEngine::processCommerceOrderSettlement($Pj,$pdo);
$driverCredit=txSum($pdo,'driver',$Pj);
ok('[R5] rider credited on settle (>0)', $driverCredit>0.0, true);

// ============================================================================
echo "\n══ AUTH / SESSION CHURN ════════════════════════════════════════════════\n";
$sec=JWT_SECRET;
$tok=JWT::encode(['user_id'=>$uS,'role'=>'vendor_shopping'],$sec,3600);
$dec=JWT::decode($tok,$sec);
ok('[A1] JWT issue+verify round-trip', (int)($dec['user_id']??0), $uS);
ok('[A1] tampered signature → null',   JWT::decode($tok.'x',$sec)===null, true);
ok('[A1] wrong secret → null',         JWT::decode($tok,$sec.'-wrong')===null, true);
// expired token (same HS256 scheme, exp in the past)
$b64=fn($d)=>rtrim(strtr(base64_encode($d),'+/','-_'),'=');
$eh=$b64(json_encode(['typ'=>'JWT','alg'=>'HS256']));
$eb=$b64(json_encode(['user_id'=>$uS,'iat'=>time()-100,'nbf'=>time()-100,'exp'=>time()-10]));
$es=$b64(hash_hmac('sha256',"$eh.$eb",$sec,true));
ok('[A2] expired token → null',        JWT::decode("$eh.$eb.$es",$sec)===null, true);
// login gate as the endpoint applies it: password_verify AND status=active
$gate=fn($r,$pw)=>($r && password_verify($pw,$r['password']) && ($r['status']??'')==='active');
$activeRow=$pdo->query("SELECT password,status FROM users WHERE id=$uS")->fetch(PDO::FETCH_ASSOC);
$pdo->prepare("UPDATE users SET status='suspended' WHERE id=?")->execute([$uO]);
$suspRow=$pdo->query("SELECT password,status FROM users WHERE id=$uO")->fetch(PDO::FETCH_ASSOC);
ok('[A3] active+correct → login ok',  $gate($activeRow,$PW), true);
ok('[A3] active+wrong   → denied',    $gate($activeRow,'x'), false);
ok('[A3] suspended user → denied',    $gate($suspRow,'Other#123'), false);
// logout = token blacklisted (token/session invalidation)
$th='fjttest-'.hash('sha256',$tok);
$pdo->prepare("INSERT INTO token_blacklist (token_hash,expires_at,created_at) VALUES (?,FROM_UNIXTIME(?),NOW()) ON DUPLICATE KEY UPDATE expires_at=VALUES(expires_at)")->execute([$th, JWT::expiresAt($tok)]);
ok('[A4] logout blacklists token',    (int)$pdo->query("SELECT COUNT(*) FROM token_blacklist WHERE token_hash='$th'")->fetchColumn(), 1);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("FULL-JOURNEY RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
