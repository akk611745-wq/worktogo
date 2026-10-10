<?php
// ─────────────────────────────────────────────────────────────────────────────
//  S14 PROOF HARNESS — Customer-ID layer (opaque, no PII, seller-isolated history).
//  Proves: a stable opaque ref per user, internal resolveRef round-trip, a seller's
//  own-history (items + aggregates + attribute snapshot), cross-seller ISOLATION,
//  NO PII in the output, unknown ref → empty. Idempotent (markers s14t-*).
//  Run: php tests/commerce/s14_customer_id_vectors.php
// ─────────────────────────────────────────────────────────────────────────────
define('SYSTEM_ROOT', dirname(dirname(__DIR__)));
foreach (file(SYSTEM_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2); $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
    if (getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
}
require_once SYSTEM_ROOT . '/core/helpers/Database.php';
require_once SYSTEM_ROOT . '/lib/commerce/CustomerId.php';
$pdo = Database::getConnection();

$PASS = 0; $FAIL = 0;
function ok($label, $got, $exp) {
    global $PASS, $FAIL;
    if ($got === $exp) { $PASS++; printf("  PASS  %-48s got=%s\n", $label, var_export($got, true)); }
    else { $FAIL++; printf("  FAIL  %-48s got=%s exp=%s\n", $label, var_export($got, true), var_export($exp, true)); }
}
function cleanup(PDO $p) {
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $p->exec("DELETE oi FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.order_number LIKE 's14t-%'");
    $p->exec("DELETE FROM orders WHERE order_number LIKE 's14t-%'");
    $p->exec("DELETE cci FROM commerce_customer_ids cci JOIN users u ON u.id=cci.user_id WHERE u.email LIKE 's14t-%@wtg.test'");
    $p->exec("DELETE FROM vendors WHERE slug LIKE 's14t-%'");
    $p->exec("DELETE FROM users WHERE email LIKE 's14t-%@wtg.test'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$name,$e){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES (?,?, 'customer','active',NOW(),NOW())")->execute([$name,$e]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',1,10,NOW(),NOW())")->execute([$uid,'S14 '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkOrder(PDO $p,$no,$uid,$vendorId){ $p->prepare("INSERT INTO orders (order_number,user_id,vendor_id,status,subtotal,total,shipping_address,created_at,updated_at) VALUES (?,?,?, 'delivered',100,100,'{\"city\":\"test\"}',NOW(),NOW())")->execute([$no,$uid,$vendorId]); return (int)$p->lastInsertId(); }
function mkItem(PDO $p,$orderId,$pid,$vendorId,$name,$qty,$attrs=null){ $p->prepare("INSERT INTO order_items (order_id,product_id,vendor_id,product_name,quantity,unit_price,line_total,attributes,created_at) VALUES (?,?,?,?,?,50,?,?,NOW())")->execute([$orderId,$pid,$vendorId,$name,$qty,50*$qty,$attrs]); }

cleanup($pdo);

$cust  = mkUser($pdo,'Ramesh Secret','s14t-cust@wtg.test');
$other = mkUser($pdo,'Other Person','s14t-other@wtg.test');
$uA = mkUser($pdo,'Seller A','s14t-sa@wtg.test'); $vA = mkVendor($pdo,$uA,'s14t-va');
$uB = mkUser($pdo,'Seller B','s14t-sb@wtg.test'); $vB = mkVendor($pdo,$uB,'s14t-vb');

$pdo->exec("SET FOREIGN_KEY_CHECKS=0");
$o1 = mkOrder($pdo,'s14t-o1',$cust,$vA); mkItem($pdo,$o1,1001,$vA,'Berger Easy Clean 4L',2,'{"shade_code":"2003","base":"white"}'); mkItem($pdo,$o1,1002,$vA,'Putty 5kg',1);
$o2 = mkOrder($pdo,'s14t-o2',$cust,$vA); mkItem($pdo,$o2,1001,$vA,'Berger Easy Clean 4L',2,'{"shade_code":"2003","base":"white"}');
$o3 = mkOrder($pdo,'s14t-o3',$cust,$vB); mkItem($pdo,$o3,1003,$vB,'Indigo Gold 10L',1);
$pdo->exec("SET FOREIGN_KEY_CHECKS=1");

echo "\n== opaque ref: stable + unique + no PII shape ==\n";
$ref = CustomerId::forUser($pdo,$cust);
ok('ref is non-empty',           $ref !== '', true);
ok('ref opaque (starts with C)', ($ref[0] ?? '') === 'C', true);
ok('ref stable on re-call',      CustomerId::forUser($pdo,$cust), $ref);
$refOther = CustomerId::forUser($pdo,$other);
ok('different user → different ref', $refOther !== $ref, true);
ok('resolveRef round-trips (internal)', CustomerId::resolveRef($pdo,$ref), $cust);
ok('unknown ref resolves null',  CustomerId::resolveRef($pdo,'CZZZZZZZ'), null);

echo "\n== seller A history (own orders only) ==\n";
$hA = CustomerId::sellerHistory($pdo,$vA,$ref);
ok('A order_count 2',   $hA['order_count'], 2);
ok('A item_count 3',    $hA['item_count'],  3);
ok('A total_qty 5',     $hA['total_qty'],   5);
$px = null; foreach ($hA['products'] as $pr) { if ($pr['product_name']==='Berger Easy Clean 4L') $px=$pr; }
ok('A: Berger bought 2 times', $px['times'] ?? null, 2);
ok('A: Berger total qty 4',    $px['total_qty'] ?? null, 4);
$hasShade = false; foreach ($hA['items'] as $it) { if (($it['attributes']['shade_code'] ?? null)==='2003') $hasShade=true; }
ok('A: shade_code snapshot present (repeat-match fuel)', $hasShade, true);

echo "\n== cross-seller ISOLATION (A cannot see B, B cannot see A) ==\n";
$namesA = array_map(fn($i)=>$i['product_name'], $hA['items']);
ok('A does NOT see Indigo (B\'s item)', in_array('Indigo Gold 10L',$namesA,true), false);
$hB = CustomerId::sellerHistory($pdo,$vB,$ref);
ok('B item_count 1',    $hB['item_count'], 1);
ok('B total_qty 1',     $hB['total_qty'],  1);
$namesB = array_map(fn($i)=>$i['product_name'], $hB['items']);
ok('B sees only Indigo', $namesB, ['Indigo Gold 10L']);
ok('B does NOT see Berger (A\'s item)', in_array('Berger Easy Clean 4L',$namesB,true), false);

echo "\n== NO PII in seller output ==\n";
$blob = json_encode(CustomerId::sellerHistory($pdo,$vA,$ref));
ok('no customer name leaked',  str_contains($blob,'Ramesh'), false);
ok('no customer email leaked', str_contains($blob,'s14t-cust@'), false);
ok('no user_id key leaked',    str_contains($blob,'"user_id"'), false);

echo "\n== unknown ref → empty history ==\n";
$he = CustomerId::sellerHistory($pdo,$vA,'CZZZZZZZ');
ok('unknown ref order_count 0', $he['order_count'], 0);
ok('unknown ref items empty',   $he['items'], []);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("S14 RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
