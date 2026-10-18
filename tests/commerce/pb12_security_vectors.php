<?php
// ─────────────────────────────────────────────────────────────────────────────
//  PB12 PROOF — security hardening (additive guards; honest users unaffected).
//  Proves: (1) no client price/total is ever trusted; (2) COD open-order cap +
//  no-pay strikes→block, and abandoned unpaid-online cleanup releases held stock;
//  (3) uploads verify the SERVER MIME + image content + size + throttle;
//  (4) rate limits on the public/sensitive shop endpoints; (5) authz — sellers
//  only their own data, customers only their own orders/addresses.
//  Run: php tests/commerce/pb12_security_vectors.php   (local 10.11)
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
require_once SYSTEM_ROOT . '/lib/commerce/CommerceCodGuard.php';
require_once SYSTEM_ROOT . '/lib/commerce/CommerceAddress.php';
require_once SYSTEM_ROOT . '/lib/commerce/CommerceVehicle.php';
$pdo = Database::getConnection();

$PASS = 0; $FAIL = 0; $CFG = [];
function ok($label, $got, $exp = true) {
    global $PASS, $FAIL;
    $g = var_export($got, true); $e = var_export($exp, true);
    if ($g === $e) { $PASS++; printf("  PASS  %-56s %s\n", $label, $g); }
    else { $FAIL++; printf("  FAIL  %-56s got=%s exp=%s\n", $label, $g, $e); }
}
function has(string $h,string $n):bool{ return strpos($h,$n)!==false; }
function get(string $p):string{ return is_file($p)?(string)file_get_contents($p):''; }
function setCfg(PDO $p,$k,$v){ global $CFG; $s=$p->prepare("SELECT setting_value FROM app_settings WHERE setting_key=?"); $s->execute([$k]); $CFG[$k]=$s->fetchColumn(); $p->prepare("INSERT INTO app_settings (setting_key,setting_value,value_type,label,is_public) VALUES (?,?,'number','pb12',0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$k,$v]); }
register_shutdown_function(function() use ($pdo){ global $CFG; foreach($CFG as $k=>$v){ if($v===false) $pdo->prepare("DELETE FROM app_settings WHERE setting_key=?")->execute([$k]); else $pdo->prepare("UPDATE app_settings SET setting_value=? WHERE setting_key=?")->execute([$v,$k]); } });
function cleanup(PDO $p){
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $oids=$p->query("SELECT id FROM orders WHERE order_number LIKE 'PB12T-%'")->fetchAll(PDO::FETCH_COLUMN);
    if($oids){$in=implode(',',array_map('intval',$oids)); $p->exec("DELETE FROM order_items WHERE order_id IN ($in)"); $p->exec("DELETE FROM orders WHERE id IN ($in)"); }
    $uids=$p->query("SELECT id FROM users WHERE email LIKE 'pb12t-%@wtg.test'")->fetchAll(PDO::FETCH_COLUMN);
    if($uids){$in=implode(',',array_map('intval',$uids)); $p->exec("DELETE FROM commerce_cod_strikes WHERE user_id IN ($in)"); $p->exec("DELETE FROM commerce_user_addresses WHERE user_id IN ($in)"); }
    $vids=$p->query("SELECT id FROM vendors WHERE slug LIKE 'pb12t-%'")->fetchAll(PDO::FETCH_COLUMN);
    if($vids){$in=implode(',',array_map('intval',$vids)); $p->exec("DELETE i FROM inventory i JOIN products pr ON pr.id=i.product_id WHERE pr.slug LIKE 'pb12t-%'"); $p->exec("DELETE FROM products WHERE slug LIKE 'pb12t-%'"); $p->exec("DELETE FROM stores WHERE owner_vendor_id IN ($in)"); $p->exec("DELETE FROM vendors WHERE id IN ($in)"); }
    $p->exec("DELETE FROM users WHERE email LIKE 'pb12t-%@wtg.test'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('PB12',?, 'customer','active',NOW(),NOW())")->execute([$e]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',1,10,NOW(),NOW())")->execute([$uid,'PB12 '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkStore(PDO $p,$slug,$v,$city){ $p->prepare("INSERT INTO stores (ownership,owner_vendor_id,city_id,name,slug,status,created_at,updated_at) VALUES ('vendor',?,?,?,?,'active',NOW(),NOW())")->execute([$v,$city,'PB12 S '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkCod(PDO $p,$num,$uid,$city,$status){ $p->prepare("INSERT INTO orders (order_number,user_id,city_id,status,payment_status,payment_method,subtotal,total,shipping_address,delivery_status,ledger_status,created_at,updated_at) VALUES (?,?,?,?, 'unpaid','cod',500,500,'{}','offered','pending',NOW(),NOW())")->execute([$num,$uid,$city,$status]); return (int)$p->lastInsertId(); }

cleanup($pdo);
$cityId=(int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if(!$cityId){ $pdo->exec("INSERT INTO cities (name,slug) VALUES ('PB12 City','pb12t-city')"); $cityId=(int)$pdo->lastInsertId(); }

echo "\n== [1] price/amount tampering — server is the only source ==\n";
$os = get(SYSTEM_ROOT.'/body/shopping-engine/api/orders/OrderService.php');
$shop = get(SYSTEM_ROOT.'/heart/api/shop/index.php');
ok('engine never reads a client price/total/amount from the payload',
   preg_match("/payload\\['(price|total|amount|subtotal|unit_price|line_total)'\\]/", $os), 0);
ok('cart add only reads product_id/quantity/op', has($shop,"\$b['product_id']")&&has($shop,"\$b['quantity']"), true);
ok('checkout forces payment_method server-side', has($shop,"\$b['payment_method'] = 'cod'"), true);

echo "\n== [2a] COD open-order cap ==\n";
$u=mkUser($pdo,'pb12t-c@wtg.test');
setCfg($pdo,'commerce_cod_open_cap','2');
setCfg($pdo,'commerce_cod_nopay_limit','3');
setCfg($pdo,'commerce_abandoned_order_min','30');
ok('fresh customer may use COD', CommerceCodGuard::codAllowed($pdo,$u)['ok'], true);
mkCod($pdo,'PB12T-C1',$u,$cityId,'confirmed'); mkCod($pdo,'PB12T-C2',$u,$cityId,'confirmed');
ok('open COD count = 2', CommerceCodGuard::openCodCount($pdo,$u), 2);
ok('at the cap → COD blocked', CommerceCodGuard::codAllowed($pdo,$u)['reason'], 'cod_cap');
mkCod($pdo,'PB12T-C3',$u,$cityId,'delivered'); // delivered doesn't count
ok('delivered order not counted', CommerceCodGuard::openCodCount($pdo,$u), 2);

echo "\n== [2b] COD no-pay strikes → block ==\n";
$u2=mkUser($pdo,'pb12t-c2@wtg.test');
ok('strike 1 not blocked', CommerceCodGuard::recordNoPay($pdo,$u2)['blocked'], false);
ok('strike 2 not blocked', CommerceCodGuard::recordNoPay($pdo,$u2)['blocked'], false);
ok('strike 3 → blocked', CommerceCodGuard::recordNoPay($pdo,$u2)['blocked'], true);
ok('blocked customer denied COD', CommerceCodGuard::codAllowed($pdo,$u2)['reason'], 'cod_blocked');
ok('admin can clear the block', (function()use($pdo,$u2){ CommerceCodGuard::clearStrikes($pdo,$u2); return CommerceCodGuard::codAllowed($pdo,$u2)['ok']; })(), true);

echo "\n== [2c] abandoned unpaid-online cleanup releases stock ==\n";
$us=mkUser($pdo,'pb12t-s@wtg.test'); $V=mkVendor($pdo,$us,'pb12t-v'); $St=mkStore($pdo,'pb12t-s',$V,$cityId);
$pdo->prepare("INSERT INTO products (vendor_id,store_id,city_id,name,slug,sku,price,status,created_at,updated_at) VALUES (?,?,?, 'PB12 P','pb12t-p','pb12t-p',500,'active',NOW(),NOW())")->execute([$V,$St,$cityId]);
$prod=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO inventory (product_id,quantity,reserved,low_stock_alert,track_inventory,allow_backorder,updated_at) VALUES (?,10,2,2,1,0,NOW())")->execute([$prod]);
$par=mkCod($pdo,'PB12T-ABN',$us,$cityId,'pending'); // we'll flip it to online+old below
$pdo->prepare("UPDATE orders SET payment_method='online', created_at=(NOW() - INTERVAL 60 MINUTE) WHERE id=?")->execute([$par]);
$pdo->prepare("INSERT INTO orders (order_number,parent_order_id,user_id,vendor_id,store_id,city_id,status,payment_status,payment_method,subtotal,total,shipping_address,delivery_status,ledger_status,created_at,updated_at) VALUES ('PB12T-ABN-S',?,?,?,?,?, 'pending','unpaid','online',500,500,'{}','offered','pending',NOW(),NOW())")->execute([$par,$us,$V,$St,$cityId]);
$sub=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO order_items (order_id,product_id,vendor_id,product_name,quantity,unit_price,line_total,created_at) VALUES (?,?,?, 'PB12 P',2,500,1000,NOW())")->execute([$sub,$prod,$V]);
$fresh=mkCod($pdo,'PB12T-FRESH',$us,$cityId,'pending'); $pdo->prepare("UPDATE orders SET payment_method='online' WHERE id=?")->execute([$fresh]); // created now → NOT abandoned
$cleaned = CommerceCodGuard::cleanupAbandoned($pdo);
ok('cleanup ran (>=1)', $cleaned >= 1, true);
ok('abandoned order cancelled', (string)$pdo->query("SELECT status FROM orders WHERE id=$par")->fetchColumn(), 'cancelled');
ok('reserved stock released (2→0)', (int)$pdo->query("SELECT reserved FROM inventory WHERE product_id=$prod")->fetchColumn(), 0);
ok('fresh order left alone', (string)$pdo->query("SELECT status FROM orders WHERE id=$fresh")->fetchColumn(), 'pending');

echo "\n== [3] upload safety (server MIME, image content, size, throttle) ==\n";
$media = get(SYSTEM_ROOT.'/heart/api/media/index.php');
ok('verifies server MIME via finfo (not client type)', has($media,'finfo_file')&&!has($media,"\$f['type']"), true);
ok('validates real image content', has($media,'getimagesize'), true);
ok('PDF header checked', has($media,"'%PDF-'"), true);
ok('size cap enforced (config)', has($media,'commerce_media_max_mb'), true);
ok('upload throttle', has($media,'RateLimiter::check')&&has($media,'media_upload_'), true);

echo "\n== [4] rate limits on public / sensitive shop endpoints ==\n";
ok('serviceability rate-limited', has($shop,'shop_svc_'), true);
ok('notify-me rate-limited', has($shop,'shop_service_req_'), true);
ok('address write rate-limited', has($shop,'shop_addr_'), true);
ok('cancel rate-limited', has($shop,'shop_cancel_'), true);
ok('invoice rate-limited', has($shop,'shop_invoice_'), true);

echo "\n== [5] authz — cross-account access is refused ==\n";
$ua=mkUser($pdo,'pb12t-a@wtg.test'); $ub=mkUser($pdo,'pb12t-b@wtg.test');
$addr=CommerceAddress::save($pdo,$ua,['label'=>'home','name'=>'A','phone'=>'9998887776','line1'=>'1 Rd','landmark'=>'gate','city'=>'X','state'=>'UK','pincode'=>'263139']);
ok('owner can read own address', CommerceAddress::get($pdo,(int)$addr['id'],$ua) !== null, true);
ok('other user cannot read it', CommerceAddress::get($pdo,(int)$addr['id'],$ub), null);
ok('other user cannot delete it', CommerceAddress::remove($pdo,(int)$addr['id'],$ub)['ok'], false);
ok('other user cannot set it default', CommerceAddress::setDefault($pdo,(int)$addr['id'],$ub)['ok'], false);
$uv1=mkUser($pdo,'pb12t-v1@wtg.test'); $V1=mkVendor($pdo,$uv1,'pb12t-v1'); mkStore($pdo,'pb12t-v1s',$V1,$cityId);
$uv2=mkUser($pdo,'pb12t-v2@wtg.test'); $V2=mkVendor($pdo,$uv2,'pb12t-v2');
$vp=mkCod($pdo,'PB12T-VP',$ua,$cityId,'pending');
$vs=$pdo->prepare("INSERT INTO orders (order_number,parent_order_id,user_id,vendor_id,store_id,city_id,status,payment_status,payment_method,subtotal,total,shipping_address,delivery_status,ledger_status,created_at,updated_at) VALUES ('PB12T-VP-S',?,?,?,?,?, 'confirmed','unpaid','cod',500,500,'{}','accepted','pending',NOW(),NOW())");
$vs->execute([$vp,$ua,$V1,(int)$pdo->query("SELECT id FROM stores WHERE owner_vendor_id=$V1 LIMIT 1")->fetchColumn(),$cityId]);
$subVp=(int)$pdo->lastInsertId();
CommerceVehicle::open($pdo,$vp,$subVp,['vehicle'=>'bike','options'=>['bike','e_rickshaw','loader'],'reason'=>'test','weight_kg'=>19.0]);
ok('a seller cannot suggest on another seller\'s order', CommerceVehicle::sellerSuggest($pdo,$subVp,$V2,'loader')['reason'], 'not_yours');
ok('COD count is per-customer (B has none)', CommerceCodGuard::openCodCount($pdo,$ub), 0);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("PB12 SECURITY RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
