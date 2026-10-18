<?php
// ─────────────────────────────────────────────────────────────────────────────
//  PB15 PROOF — customer saved-address book.
//  Proves: Home/Shop/Site labels; one default (first auto-default; switching);
//  LANDMARK required; pincode checked by PB14 serviceability at save; optional
//  GPS pin; soft-delete promotes a new default; toShipping() carries the full
//  address (incl. landmark + pin) for the rider; + shop/rider/client wiring.
//  Run: php tests/commerce/pb15_addresses_vectors.php   (local 10.11)
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
require_once SYSTEM_ROOT . '/lib/commerce/CommerceAddress.php';
require_once SYSTEM_ROOT . '/lib/commerce/Serviceability.php';
$pdo = Database::getConnection();

$PASS = 0; $FAIL = 0;
function ok($label, $got, $exp = true) {
    global $PASS, $FAIL;
    $g = var_export($got, true); $e = var_export($exp, true);
    if ($g === $e) { $PASS++; printf("  PASS  %-54s %s\n", $label, $g); }
    else { $FAIL++; printf("  FAIL  %-54s got=%s exp=%s\n", $label, $g, $e); }
}
function has(string $h,string $n):bool{ return strpos($h,$n)!==false; }
function get(string $p):string{ return is_file($p)?(string)file_get_contents($p):''; }
function cleanup(PDO $p){
    $uids=$p->query("SELECT id FROM users WHERE email LIKE 'pb15t-%@wtg.test'")->fetchAll(PDO::FETCH_COLUMN);
    if($uids){$in=implode(',',array_map('intval',$uids)); $p->exec("DELETE FROM commerce_user_addresses WHERE user_id IN ($in)"); }
    $cids=$p->query("SELECT id FROM cities WHERE slug LIKE 'pb15t-%'")->fetchAll(PDO::FETCH_COLUMN);
    if($cids){$in=implode(',',array_map('intval',$cids)); $p->exec("DELETE FROM commerce_serviceability WHERE city_id IN ($in)"); $p->exec("DELETE FROM cities WHERE id IN ($in)"); }
    $p->exec("DELETE FROM users WHERE email LIKE 'pb15t-%@wtg.test'");
}
function mkUser(PDO $p,$e){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('PB15',?, 'customer','active',NOW(),NOW())")->execute([$e]); return (int)$p->lastInsertId(); }
function base($o=[]){ return array_merge(['label'=>'home','name'=>'Ravi','phone'=>'9998887776','line1'=>'House 12, Mukhani','landmark'=>'behind SBI','city'=>'PB15City','state'=>'Uttarakhand','pincode'=>'263139'], $o); }

cleanup($pdo);
$pdo->exec("INSERT INTO cities (name,slug,is_active) VALUES ('PB15 City','pb15t-city',1)"); $cityA=(int)$pdo->lastInsertId();
$u=mkUser($pdo,'pb15t-c@wtg.test');

echo "\n== [A] validation: landmark required, pincode required ==\n";
ok('missing landmark rejected', CommerceAddress::save($pdo,$u,base(['landmark'=>'']))['reason'], 'landmark_required');
ok('bad pincode rejected', CommerceAddress::save($pdo,$u,base(['pincode'=>'12']))['reason'], 'bad_pincode');
ok('missing name rejected', CommerceAddress::save($pdo,$u,base(['name'=>'']))['reason'], 'missing_fields');

echo "\n== [B] add → first becomes default; labels ==\n";
$a1 = CommerceAddress::save($pdo,$u,base(['label'=>'home']));
ok('first address saved', $a1['ok'], true);
ok('first address auto-default', $a1['is_default'], true);
$a2 = CommerceAddress::save($pdo,$u,base(['label'=>'shop','landmark'=>'near red gate']));
ok('second address saved', $a2['ok'], true);
ok('second NOT default', $a2['is_default'], false);
$list = CommerceAddress::listForUser($pdo,$u);
ok('two addresses listed', count($list), 2);
ok('default listed first', $list[0]['id'], $a1['id']);
ok('labels stored (home/shop)', in_array($list[0]['label'],['home','shop'],true) && in_array($list[1]['label'],['home','shop'],true), true);

echo "\n== [C] switch default ==\n";
CommerceAddress::setDefault($pdo,(int)$a2['id'],$u);
$list = CommerceAddress::listForUser($pdo,$u);
$def = array_values(array_filter($list, fn($x)=>$x['is_default']));
ok('exactly one default', count($def), 1);
ok('default switched to a2', $def[0]['id'], $a2['id']);

echo "\n== [D] GPS pin + toShipping carries landmark + pin ==\n";
$a3 = CommerceAddress::save($pdo,$u,base(['label'=>'site','landmark'=>'plot 9','lat'=>29.21,'lng'=>79.51]));
$row = CommerceAddress::get($pdo,(int)$a3['id'],$u);
$ship = CommerceAddress::toShipping($row);
ok('toShipping has landmark', $ship['landmark'], 'plot 9');
ok('toShipping carries GPS lat', round((float)$ship['lat'],2), 29.21);
ok('toShipping has required order fields', !empty($ship['name'])&&!empty($ship['phone'])&&!empty($ship['line1'])&&!empty($ship['city'])&&!empty($ship['state'])&&!empty($ship['pincode']), true);

echo "\n== [E] pincode checked by PB14 serviceability at save ==\n";
Serviceability::addArea($pdo,'city',$cityA,null,'263139'); // city serves only 263139
ok('serviceable pincode saves', CommerceAddress::save($pdo,$u,base(['city_id'=>$cityA,'pincode'=>'263139']))['ok'], true);
ok('unserved pincode blocked', CommerceAddress::save($pdo,$u,base(['city_id'=>$cityA,'pincode'=>'110001']))['reason'], 'area_not_served');

echo "\n== [F] delete promotes a new default; ownership ==\n";
CommerceAddress::setDefault($pdo,(int)$a1['id'],$u);
CommerceAddress::remove($pdo,(int)$a1['id'],$u);
$list = CommerceAddress::listForUser($pdo,$u);
ok('deleted address gone', (function()use($list,$a1){ foreach($list as $x) if($x['id']===$a1['id']) return true; return false; })(), false);
ok('a new default was promoted', count(array_filter($list, fn($x)=>$x['is_default'])), 1);
$other=mkUser($pdo,'pb15t-c2@wtg.test');
ok('cannot delete another user\'s address', CommerceAddress::remove($pdo,(int)$a2['id'],$other)['ok'], false);

echo "\n== [G] shop + rider + client wiring ==\n";
$shop = get(SYSTEM_ROOT.'/heart/api/shop/index.php');
$rider= get(SYSTEM_ROOT.'/heart/api/rider/index.php');
$rjs  = get(SYSTEM_ROOT.'/rider/rider.js');
$js   = get(SYSTEM_ROOT.'/app/commerce/shop.js');
$capi = get(SYSTEM_ROOT.'/app/commerce/api.js');
ok('shop: address routes', has($shop,'/api/shop/addresses')&&has($shop,'/default')&&has($shop,'/remove'), true);
ok('rider: landmark surfaced', has($rider,"\$r['landmark']"), true);
ok('rider UI shows landmark', has($rjs,'Landmark'), true);
ok('shop.js: address picker + add form + gps', has($js,'addressChoice')&&has($js,'addressForm')&&has($js,'captureGps'), true);
ok('shop.js: landmark required in form', has($js,'ad-landmark')&&has($js,'required'), true);
ok('api.js: address endpoints', has($capi,'saveAddress')&&has($capi,'setDefaultAddr')&&has($capi,'removeAddress'), true);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("PB15 ADDRESSES RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
