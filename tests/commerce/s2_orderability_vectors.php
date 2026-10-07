<?php
// ─────────────────────────────────────────────────────────────────────────────
//  S2 PROOF HARNESS — OrderabilityResolver (4 layers) + seller ON/OFF/BUSY gate.
//  Run: php tests/commerce/s2_orderability_vectors.php   (local DB; idempotent)
// ─────────────────────────────────────────────────────────────────────────────
define('SYSTEM_ROOT', dirname(dirname(__DIR__)));
foreach (file(SYSTEM_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2); $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
    if (getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
}
require_once SYSTEM_ROOT . '/core/helpers/Database.php';
require_once SYSTEM_ROOT . '/lib/commerce/OrderabilityResolver.php';
$pdo = Database::getConnection();

$PASS = 0; $FAIL = 0;
function ok($label, $got, $exp) {
    global $PASS, $FAIL;
    if ($got === $exp) { $PASS++; printf("  PASS  %-42s got=%s\n", $label, var_export($got, true)); }
    else { $FAIL++; printf("  FAIL  %-42s got=%s exp=%s\n", $label, var_export($got, true), var_export($exp, true)); }
}
function cleanup(PDO $p) {
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $p->exec("DELETE i FROM inventory i JOIN products pr ON pr.id=i.product_id WHERE pr.slug LIKE 's2t-%'");
    $p->exec("DELETE FROM products WHERE slug LIKE 's2t-%'");
    $p->exec("DELETE FROM stores   WHERE slug LIKE 's2t-%'");
    $p->exec("DELETE FROM vendors  WHERE slug LIKE 's2t-%'");
    $p->exec("DELETE FROM users    WHERE email LIKE 's2t-%@wtg.test'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('S2 User',?, 'vendor_shopping','active',NOW(),NOW())")->execute([$e]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug,$online,$busyExpr){ // $busyExpr: NULL | 'NOW() + INTERVAL 1 HOUR' | 'NOW() - INTERVAL 1 HOUR'
    $busy = $busyExpr === null ? 'NULL' : $busyExpr;
    $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,busy_until,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',?, $busy ,10,NOW(),NOW())")->execute([$uid,'S2 Vendor '.$slug,$slug,$online]);
    return (int)$p->lastInsertId();
}
function mkStore(PDO $p,$slug,$ownership,$ownerVendor,$cityId,$status){ $p->prepare("INSERT INTO stores (ownership,owner_vendor_id,city_id,name,slug,status,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())")->execute([$ownership,$ownerVendor,$cityId,'S2 Store '.$slug,$slug,$status]); return (int)$p->lastInsertId(); }
function mkProduct(PDO $p,$vendorId,$storeId,$cityId,$slug,$status){ $p->prepare("INSERT INTO products (vendor_id,store_id,city_id,name,slug,price,status,created_at,updated_at) VALUES (?,?,?,?,?,199.00,?,NOW(),NOW())")->execute([$vendorId,$storeId,$cityId,'S2 Product '.$slug,$slug,$status]); return (int)$p->lastInsertId(); }
function mkInv(PDO $p,$pid,$qty,$reserved,$backorder){ $p->prepare("INSERT INTO inventory (product_id,quantity,reserved,low_stock_alert,track_inventory,allow_backorder,updated_at) VALUES (?,?,?,5,1,?,NOW())")->execute([$pid,$qty,$reserved,$backorder]); }
function chk(PDO $p,$pid,$city){ return OrderabilityResolver::check($p,$pid,$city); }

cleanup($pdo);

// a real city id (FK target); reuse an existing one, else create a minimal row.
$cityId = (int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if (!$cityId) { $pdo->exec("INSERT INTO cities (name,slug) VALUES ('S2 City','s2t-city')"); $cityId = (int)$pdo->lastInsertId(); }

$cu   = mkUser($pdo,'s2t-cv@wtg.test');  $compVendor = mkVendor($pdo,$cu,'s2t-compv',1,null); // vendor row to satisfy company product's NOT NULL vendor_id
$u1   = mkUser($pdo,'s2t-v1@wtg.test');  $vON  = mkVendor($pdo,$u1,'s2t-von',1,null);
$ut   = mkUser($pdo,'s2t-tg@wtg.test');  $vTOG = mkVendor($pdo,$ut,'s2t-vtog',1,null);
$uoff = mkUser($pdo,'s2t-of@wtg.test');  $vOFF = mkVendor($pdo,$uoff,'s2t-voff',0,null);

$stCompany = mkStore($pdo,'s2t-company',   'company', null,  $cityId, 'active');
$stOn      = mkStore($pdo,'s2t-vendor-on', 'vendor',  $vON,  $cityId, 'active');
$stTog     = mkStore($pdo,'s2t-vendor-tog','vendor',  $vTOG, $cityId, 'active');
$stSusp    = mkStore($pdo,'s2t-vendor-sus','vendor',  $vON,  $cityId, 'suspended');
$stOff     = mkStore($pdo,'s2t-vendor-off','vendor',  $vOFF, $cityId, 'active');

$pOk       = mkProduct($pdo,$vON,       $stOn,     $cityId,'s2t-ok',       'active'); mkInv($pdo,$pOk,10,0,0);
$pCompany  = mkProduct($pdo,$compVendor,$stCompany,$cityId,'s2t-company-p','active'); mkInv($pdo,$pCompany,10,0,0);
$pInactive = mkProduct($pdo,$vON,       $stOn,     $cityId,'s2t-inactive', 'inactive'); mkInv($pdo,$pInactive,10,0,0);
$pNoStock  = mkProduct($pdo,$vON,       $stOn,     $cityId,'s2t-nostock',  'active'); mkInv($pdo,$pNoStock,5,5,0);
$pSusp     = mkProduct($pdo,$vON,       $stSusp,   $cityId,'s2t-susp',     'active'); mkInv($pdo,$pSusp,10,0,0);
$pTog      = mkProduct($pdo,$vTOG,      $stTog,    $cityId,'s2t-tog',      'active'); mkInv($pdo,$pTog,10,0,0);
$pOff      = mkProduct($pdo,$vOFF,      $stOff,    $cityId,'s2t-off',      'active'); mkInv($pdo,$pOff,10,0,0);

echo "\n== 4-layer orderability ==\n";
ok('O1 vendor ON / active / stock → ok',        chk($pdo,$pOk,$cityId)['reason'],       'ok');
ok('O9 company store → ok (always on)',          chk($pdo,$pCompany,$cityId)['reason'],   'ok');
ok('O5 inactive product → inactive_product',     chk($pdo,$pInactive,$cityId)['reason'],  'inactive_product');
ok('O6 out of stock → out_of_stock',             chk($pdo,$pNoStock,$cityId)['reason'],   'out_of_stock');
ok('O7 store suspended → store_inactive',        chk($pdo,$pSusp,$cityId)['reason'],      'store_inactive');
ok('O8 wrong city → out_of_area',                chk($pdo,$pOk,999999)['reason'],         'out_of_area');
ok('O-off OFF seller → seller_off',              chk($pdo,$pOff,$cityId)['reason'],       'seller_off');

echo "\n== ON / OFF / BUSY gate (toggle vTOG) ==\n";
$pdo->prepare("UPDATE vendors SET is_online=0, busy_until=NULL WHERE id=?")->execute([$vTOG]);
ok('O2 seller OFF → seller_off',                 chk($pdo,$pTog,$cityId)['reason'],       'seller_off');
$pdo->prepare("UPDATE vendors SET is_online=1, busy_until = NOW() + INTERVAL 1 HOUR WHERE id=?")->execute([$vTOG]);
ok('O3 seller BUSY (future) → seller_busy',      chk($pdo,$pTog,$cityId)['reason'],       'seller_busy');
$pdo->prepare("UPDATE vendors SET is_online=1, busy_until = NOW() - INTERVAL 1 HOUR WHERE id=?")->execute([$vTOG]);
ok('O4 BUSY expired (past) → ok (auto-clear)',   chk($pdo,$pTog,$cityId)['reason'],       'ok');

echo "\n== global kill-switch ==\n";
$pdo->exec("UPDATE app_settings SET setting_value='0' WHERE setting_key='commerce_enabled'");
ok('O10 kill-switch OFF → commerce_paused',      chk($pdo,$pOk,$cityId)['reason'],        'commerce_paused');
$pdo->exec("UPDATE app_settings SET setting_value='1' WHERE setting_key='commerce_enabled'");
ok('O10b kill-switch restored → ok',             chk($pdo,$pOk,$cityId)['reason'],        'ok');

echo "\n== browse predicate excludes OFF seller (sellerAvailableSql) ==\n";
$sql = "SELECT p.id FROM products p JOIN stores s ON s.id=p.store_id LEFT JOIN vendors v ON v.id=s.owner_vendor_id
         WHERE p.slug IN ('s2t-ok','s2t-off') AND " . OrderabilityResolver::sellerAvailableSql('s','v');
$ids = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
ok('browse includes ON product',  in_array((string)$pOk, array_map('strval',$ids), true),  true);
ok('browse excludes OFF product', in_array((string)$pOff, array_map('strval',$ids), true), false);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("S2 RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
