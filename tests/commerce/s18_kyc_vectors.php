<?php
// ─────────────────────────────────────────────────────────────────────────────
//  S18 PROOF HARNESS — KYC / onboarding (seller + rider): submit→review→approve,
//  payout target (bank/UPI), required-field gate, phone-OTP gate, seller activation
//  on approve, rider delivery_riders profile on approve, reject→re-edit. Idempotent.
//  Run: php tests/commerce/s18_kyc_vectors.php
// ─────────────────────────────────────────────────────────────────────────────
define('SYSTEM_ROOT', dirname(dirname(__DIR__)));
foreach (file(SYSTEM_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2); $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
    if (getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
}
require_once SYSTEM_ROOT . '/core/helpers/Database.php';
require_once SYSTEM_ROOT . '/lib/commerce/KycService.php';
$pdo = Database::getConnection();

$PASS = 0; $FAIL = 0;
function ok($label, $got, $exp = true) {
    global $PASS, $FAIL;
    if ($got === $exp) { $PASS++; printf("  PASS  %-50s got=%s\n", $label, var_export($got, true)); }
    else { $FAIL++; printf("  FAIL  %-50s got=%s exp=%s\n", $label, var_export($got, true), var_export($exp, true)); }
}
function cleanup(PDO $p) {
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $p->exec("DELETE sk FROM commerce_seller_kyc sk JOIN vendors v ON v.id=sk.vendor_id WHERE v.slug LIKE 's18t-%'");
    $p->exec("DELETE rk FROM commerce_rider_kyc rk JOIN users u ON u.id=rk.rider_user_id WHERE u.email LIKE 's18t-%@wtg.test'");
    $p->exec("DELETE dr FROM delivery_riders dr JOIN users u ON u.id=dr.rider_id WHERE u.email LIKE 's18t-%@wtg.test'");
    $p->exec("DELETE FROM vendors WHERE slug LIKE 's18t-%'");
    $p->exec("DELETE FROM users WHERE email LIKE 's18t-%@wtg.test'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e,$role,$verified=true){ $p->prepare("INSERT INTO users (name,email,phone,role,status,phone_verified_at,created_at,updated_at) VALUES ('S18',?,?,?, 'active',".($verified?"NOW()":"NULL").",NOW(),NOW())")->execute([$e,substr(str_replace(['@','.','-'],'',$e),0,14),$role]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','pending',1,10,NOW(),NOW())")->execute([$uid,'S18 '.$slug,$slug]); return (int)$p->lastInsertId(); }
function vstatus(PDO $p,$id){ return $p->query("SELECT status FROM vendors WHERE id=$id")->fetchColumn(); }

cleanup($pdo);
$cityId = (int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if (!$cityId) { $pdo->exec("INSERT INTO cities (name,slug) VALUES ('S18 City','s18t-city')"); $cityId=(int)$pdo->lastInsertId(); }

echo "\n== SELLER KYC: submit→review→approve + payout + activation ==\n";
$su = mkUser($pdo,'s18t-seller@wtg.test','vendor_shopping',true); $V = mkVendor($pdo,$su,'s18t-v1');
ok('submit with no application → no_application', KycService::sellerSubmit($pdo,$V)['reason'] ?? null, 'no_application');
KycService::sellerUpsert($pdo,$V,['owner_name'=>'Ramesh']);
$inc = KycService::sellerSubmit($pdo,$V);
ok('incomplete submit blocked', $inc['ok'] ?? null, false);
ok('missing lists payout + proofs', in_array('bank_or_upi',$inc['missing'] ?? [],true) && in_array('id_proof_media_id',$inc['missing'] ?? [],true), true);
KycService::sellerUpsert($pdo,$V,['shop_address'=>'12 Mukhani','pincode'=>'263139','upi_id'=>'ramesh@upi','pan'=>'ABCDE1234F','id_proof_media_id'=>101,'address_proof_media_id'=>102,'shop_photo_media_id'=>103,'operating_hours'=>'9-8','service_area'=>'Haldwani']);
$sub = KycService::sellerSubmit($pdo,$V);
ok('complete submit → submitted', $sub['status'] ?? null, 'submitted');
ok('upsert blocked while under review', KycService::sellerUpsert($pdo,$V,['owner_name'=>'X'])['reason'] ?? null, 'under_review');
ok('vendor still pending pre-approval', vstatus($pdo,$V), 'pending');
$rev = KycService::sellerReview($pdo,$V,true,'looks good',1);
ok('review approve ok', $rev['status'] ?? null, 'approved');
ok('vendor ACTIVATED on approve', vstatus($pdo,$V), 'active');
$k = KycService::sellerGet($pdo,$V);
ok('payout target stored (upi)', $k['upi_id'], 'ramesh@upi');

echo "\n== SELLER KYC: reject → re-edit returns to draft ==\n";
$su2 = mkUser($pdo,'s18t-seller2@wtg.test','vendor_shopping',true); $V2 = mkVendor($pdo,$su2,'s18t-v2');
KycService::sellerUpsert($pdo,$V2,['owner_name'=>'Sita','shop_address'=>'A','pincode'=>'263001','upi_id'=>'s@upi','pan'=>'PANXX1234Z','id_proof_media_id'=>201,'address_proof_media_id'=>202]);
KycService::sellerSubmit($pdo,$V2);
ok('reject ok', KycService::sellerReview($pdo,$V2,false,'blurry proof',1)['status'] ?? null, 'rejected');
ok('rejected vendor NOT active', vstatus($pdo,$V2), 'pending');
KycService::sellerUpsert($pdo,$V2,['address_proof_media_id'=>203]);
ok('edit after reject → back to draft', KycService::sellerGet($pdo,$V2)['status'], 'draft');

echo "\n== SELLER KYC: phone-OTP gate ==\n";
$su3 = mkUser($pdo,'s18t-seller3@wtg.test','vendor_shopping',false); $V3 = mkVendor($pdo,$su3,'s18t-v3');
KycService::sellerUpsert($pdo,$V3,['owner_name'=>'N','shop_address'=>'B','pincode'=>'263002','upi_id'=>'n@upi','pan'=>'PANYY1234Z','id_proof_media_id'=>301,'address_proof_media_id'=>302]);
ok('unverified phone → submit missing phone_otp', in_array('phone_otp_verified', KycService::sellerSubmit($pdo,$V3)['missing'] ?? [], true), true);

echo "\n== RIDER KYC: self-register role + submit→review→approve + profile ==\n";
$ru = mkUser($pdo,'s18t-rider@wtg.test','delivery',true);
ok('delivery user created (role allowed)', $pdo->query("SELECT role FROM users WHERE id=$ru")->fetchColumn(), 'delivery');
$rinc = KycService::riderSubmit($pdo,$ru);  // no app
ok('rider submit no-app', $rinc['reason'] ?? null, 'no_application');
KycService::riderUpsert($pdo,$ru,['full_name'=>'Mohan','phone'=>'9990001111','address'=>'Kathgodam','licence_number'=>'UK0712345','vehicle_type'=>'bike','vehicle_number'=>'UK07AB1234','rc_number'=>'RC123','upi_id'=>'mohan@upi','photo_media_id'=>401,'licence_media_id'=>402,'rc_media_id'=>403]);
ok('rider complete submit → submitted', KycService::riderSubmit($pdo,$ru)['status'] ?? null, 'submitted');
ok('delivery_riders absent pre-approval', (int)$pdo->query("SELECT COUNT(*) FROM delivery_riders WHERE rider_id=$ru")->fetchColumn(), 0);
ok('rider review approve ok', KycService::riderReview($pdo,$ru,true,null,1,$cityId)['status'] ?? null, 'approved');
ok('delivery_riders profile CREATED on approve', (int)$pdo->query("SELECT COUNT(*) FROM delivery_riders WHERE rider_id=$ru")->fetchColumn(), 1);
ok('rider payout stored (upi)', KycService::riderGet($pdo,$ru)['upi_id'], 'mohan@upi');
ok('rider vehicle carried to profile', $pdo->query("SELECT vehicle_type FROM delivery_riders WHERE rider_id=$ru")->fetchColumn(), 'bike');

echo "\n== admin review queue ==\n";
$su4 = mkUser($pdo,'s18t-seller4@wtg.test','vendor_shopping',true); $V4 = mkVendor($pdo,$su4,'s18t-v4');
KycService::sellerUpsert($pdo,$V4,['owner_name'=>'Q','shop_address'=>'C','pincode'=>'263003','upi_id'=>'q@upi','pan'=>'PANZZ1234Z','id_proof_media_id'=>501,'address_proof_media_id'=>502]);
KycService::sellerSubmit($pdo,$V4);
$pend = KycService::pending($pdo);
ok('pending queue lists a submitted seller', count(array_filter($pend['sellers'], fn($r)=>(int)$r['vendor_id']===$V4)) >= 1, true);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("S18 RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
