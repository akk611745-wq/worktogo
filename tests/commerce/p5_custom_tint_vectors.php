<?php
// ─────────────────────────────────────────────────────────────────────────────
//  P5 PROOF HARNESS — custom-tint STRUCTURE (data-empty + OFF). Proves the spine
//  is correct while custom tint stays dark until Prisma:
//    • OFF by config (ready paint runs; custom-tint order intent is refused)
//    • brand-code guard: a code matches only WITHIN its brand (empty fandeck →
//      every code rejected; with a seeded shade → found; cross-brand → rejected)
//    • non-returnable (attribute-driven) — CommerceReturns refuses a custom tint
//    • new-vs-repaint rule; repeat→same-seller routing (tint order log)
//    • consent + advance gate; ops confirm-call flag
//    • structural wiring (adapter + UI → /api/tint)
//  Run: php tests/commerce/p5_custom_tint_vectors.php   (local 10.11; idempotent)
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
require_once SYSTEM_ROOT . '/lib/commerce/CustomTint.php';
require_once SYSTEM_ROOT . '/lib/commerce/UnitConfig.php';
require_once SYSTEM_ROOT . '/lib/commerce/CommerceReturns.php';
$pdo = Database::getConnection();

$PASS = 0; $FAIL = 0;
function ok($label, $got, $exp = true) {
    global $PASS, $FAIL;
    $g = is_float($got) ? number_format($got, 2, '.', '') : $got;
    $e = is_float($exp) ? number_format($exp, 2, '.', '') : $exp;
    if ($g === $e) { $PASS++; printf("  PASS  %-54s got=%s\n", $label, var_export($g, true)); }
    else { $FAIL++; printf("  FAIL  %-54s got=%s exp=%s\n", $label, var_export($g, true), var_export($e, true)); }
}
function setcfg(PDO $p,$k,$v){ $p->prepare("INSERT INTO app_settings (setting_key,setting_value,value_type,is_public) VALUES (?,?,'text',0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$k,$v]); UnitConfig::resetCache(); }
function has(string $h,string $n):bool{ return strpos($h,$n)!==false; }
function get(string $p):string{ return is_file($p)?(string)file_get_contents($p):''; }
function cleanup(PDO $p){
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $p->exec("DELETE FROM commerce_paint_shades WHERE brand LIKE 'P5T-%' OR source='P5T-seed'");
    $p->exec("DELETE FROM commerce_tint_order_log WHERE brand LIKE 'P5T-%'");
    $ids=$p->query("SELECT id FROM orders WHERE order_number LIKE 'P5T-%'")->fetchAll(PDO::FETCH_COLUMN);
    if($ids){$in=implode(',',array_map('intval',$ids)); $p->exec("DELETE FROM commerce_returns WHERE order_id IN ($in)"); $p->exec("DELETE FROM order_items WHERE order_id IN ($in)");}
    $p->exec("DELETE FROM orders WHERE order_number LIKE 'P5T-%'");
    $p->exec("DELETE i FROM inventory i JOIN products pr ON pr.id=i.product_id WHERE pr.name LIKE 'P5 %'");
    $p->exec("DELETE FROM products WHERE name LIKE 'P5 %'");
    $p->exec("DELETE FROM stores   WHERE slug LIKE 'p5t-%'");
    $p->exec("DELETE FROM vendors  WHERE slug LIKE 'p5t-%'");
    $p->exec("DELETE FROM users    WHERE email LIKE 'p5t-%@wtg.test'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('P5',?, 'customer','active',NOW(),NOW())")->execute([$e]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',1,10,NOW(),NOW())")->execute([$uid,'P5 '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkStore(PDO $p,$slug,$v,$city){ $p->prepare("INSERT INTO stores (ownership,owner_vendor_id,city_id,name,slug,status,created_at,updated_at) VALUES ('vendor',?,?,?,?,'active',NOW(),NOW())")->execute([$v,$city,'P5 S '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkProduct(PDO $p,$v,$st,$city,$slug,$attrs){ $p->prepare("INSERT INTO products (vendor_id,store_id,city_id,name,slug,price,status,attributes,created_at,updated_at) VALUES (?,?,?,?,?,1200,'active',?,NOW(),NOW())")->execute([$v,$st,$city,'P5 '.$slug,$slug,$attrs]); return (int)$p->lastInsertId(); }
function mkParent(PDO $p,$num,$uid,$v,$st,$city){ $p->prepare("INSERT INTO orders (order_number,user_id,vendor_id,store_id,city_id,status,payment_status,payment_method,subtotal,delivery_fee,total,shipping_address,delivery_status,ledger_status,delivered_at,created_at,updated_at) VALUES (?,?,?,?,?, 'delivered','unpaid','cod',1200,0,1200,'{}','delivered','pending',NOW(),NOW(),NOW())")->execute([$num,$uid,$v,$st,$city]); return (int)$p->lastInsertId(); }
function mkItem(PDO $p,$oid,$pid,$v){ $p->prepare("INSERT INTO order_items (order_id,product_id,vendor_id,product_name,quantity,unit_price,line_total,created_at) VALUES (?,?,?,?,1,1200,1200,NOW())")->execute([$oid,$pid,$v,'P5 item']); }

cleanup($pdo);
$cfgSnap = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='commerce_unit_config'")->fetchColumn();
$ccSnap  = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='commerce_confirm_call'")->fetchColumn();
$brSnap  = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='commerce_custom_tint_brands'")->fetchColumn();
register_shutdown_function(function() use($pdo,$cfgSnap,$ccSnap,$brSnap){ try{
    if($cfgSnap!==false&&$cfgSnap!==null) $pdo->prepare("UPDATE app_settings SET setting_value=? WHERE setting_key='commerce_unit_config'")->execute([$cfgSnap]);
    if($ccSnap!==false&&$ccSnap!==null)   $pdo->prepare("UPDATE app_settings SET setting_value=? WHERE setting_key='commerce_confirm_call'")->execute([$ccSnap]);
    if($brSnap!==false&&$brSnap!==null)    $pdo->prepare("UPDATE app_settings SET setting_value=? WHERE setting_key='commerce_custom_tint_brands'")->execute([$brSnap]);
}catch(\Throwable $e){} });
// paint unit ON; per-brand custom-tint go-live is driven by commerce_custom_tint_brands
$PAINT_UNIT = '{"units":{"paint":{"enabled":true}},"paint_category_slugs":["paint-interior-emulsion","paint-exterior-emulsion","paint-enamel","paint-distemper","paint-primer","paint-putty","paint-waterproofing"]}';
setcfg($pdo,'commerce_unit_config',$PAINT_UNIT);
function brands(PDO $p, array $arr){ $p->prepare("INSERT INTO app_settings (setting_key,setting_value,value_type,is_public) VALUES ('commerce_custom_tint_brands',?, 'text',0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([json_encode(array_values($arr))]); UnitConfig::resetCache(); }

$custAttrs  = ['brand'=>'P5T-Berger','product_name'=>'Tintable Base','shade_mode'=>'tintable','custom_tintable'=>'yes','base_type'=>'white','returnable'=>'no'];
$readyAttrs = ['brand'=>'P5T-Berger','product_name'=>'Easy Clean','shade_mode'=>'ready','finish'=>'sheen','returnable'=>'yes'];

echo "\n== [A] P5 tables exist ==\n";
ok('commerce_paint_shades exists',    (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='commerce_paint_shades'")->fetchColumn(), 1);
ok('commerce_tint_order_log exists',  (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='commerce_tint_order_log'")->fetchColumn(), 1);

echo "\n== [B] custom tint PER-BRAND: no brand enabled → refused ==\n";
brands($pdo,[]);
ok('CustomTint::enabled() false (no brands live)', CustomTint::enabled($pdo), false);
ok('order intent refused (brand_not_enabled)',     in_array('brand_not_enabled', CustomTint::validateOrderIntent($pdo,$custAttrs,['brand'=>'P5T-Berger','shade_code'=>'2003','consent'=>true,'advance_started'=>true]), true), true);

echo "\n== [C] brand-code guard (data-empty → with a seeded shade) ==\n";
ok('empty fandeck: Berger 2003 → not found', CustomTint::brandHasCode($pdo,'P5T-Berger','2003'), false);
$pdo->prepare("INSERT INTO commerce_paint_shades (brand,product_line,shade_code,shade_name,base_type,finish,source) VALUES ('P5T-Berger','Easy Clean','2003','P5 Sweet Jasmine','white','matt','P5T-seed')")->execute();
ok('seeded: Berger 2003 → found',            CustomTint::brandHasCode($pdo,'P5T-Berger','2003'), true);
ok('resolveShade base_type = white',         CustomTint::resolveShade($pdo,'P5T-Berger','2003')['base_type'], 'white');
ok('cross-brand: Nerolac 2003 → rejected',   CustomTint::brandHasCode($pdo,'P5T-Nerolac','2003'), false);
ok('unknown code: Berger 9999 → rejected',   CustomTint::brandHasCode($pdo,'P5T-Berger','9999'), false);

echo "\n== [D] non-returnable (attribute-driven) ==\n";
ok('isNonReturnable(custom) true',  CustomTint::isNonReturnable($custAttrs), true);
ok('isNonReturnable(ready) false',  CustomTint::isNonReturnable($readyAttrs), false);

echo "\n== [E] new-vs-repaint rule ==\n";
ok('repaint → same_seller + exact', (CustomTint::modeRule('repaint')['same_seller'] && CustomTint::modeRule('repaint')['exact_only']), true);
ok('new → adjustment allowed, any seller', (CustomTint::modeRule('new')['adjustment_allowed'] && !CustomTint::modeRule('new')['same_seller']), true);

echo "\n== [F] repeat → same-seller routing (tint order log) ==\n";
$cityId=(int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if(!$cityId){ $pdo->exec("INSERT INTO cities (name,slug) VALUES ('P5 City','p5t-city')"); $cityId=(int)$pdo->lastInsertId(); }
$uv=mkUser($pdo,'p5t-v@wtg.test'); $VX=mkVendor($pdo,$uv,'p5t-vx'); $StX=mkStore($pdo,'p5t-sx',$VX,$cityId);
$uA=mkUser($pdo,'p5t-a@wtg.test'); $uB=mkUser($pdo,'p5t-b@wtg.test');
CustomTint::recordMade($pdo,$uA,null,'P5T-Berger','2003','white',$VX,null);
ok('preferredSeller(A,Berger,2003) = VX', CustomTint::preferredSeller($pdo,$uA,'P5T-Berger','2003'), $VX);
ok('preferredSeller(B,…) = null (first time)', CustomTint::preferredSeller($pdo,$uB,'P5T-Berger','2003'), null);
ok('preferredSeller(A,Berger,9999) = null',    CustomTint::preferredSeller($pdo,$uA,'P5T-Berger','9999'), null);
ok('routeFirst repaint → VX (pin same seller)', CustomTint::routeFirstSeller($pdo,$uA,'P5T-Berger','2003','repaint'), $VX);
ok('routeFirst new → null (any seller ok)',     CustomTint::routeFirstSeller($pdo,$uA,'P5T-Berger','2003','new'), null);

echo "\n== [G] consent + advance gate (brand ENABLED) ==\n";
brands($pdo,['P5T-Berger']);
ok('brand enabled now', CustomTint::brandEnabled($pdo,'P5T-Berger'), true);
$blk=CustomTint::validateOrderIntent($pdo,$custAttrs,['brand'=>'P5T-Berger','shade_code'=>'2003','consent'=>false,'advance_started'=>false]);
ok('no consent → consent_required',   in_array('consent_required',$blk,true), true);
ok('no advance → advance_required',    in_array('advance_required',$blk,true), true);
$blk2=CustomTint::validateOrderIntent($pdo,$custAttrs,['brand'=>'P5T-Berger','shade_code'=>'2003','consent'=>true,'advance_started'=>true]);
ok('consent + advance + valid code → OK (no blockers)', empty($blk2), true);
$blk3=CustomTint::validateOrderIntent($pdo,$custAttrs,['brand'=>'P5T-Berger','shade_code'=>'9999','consent'=>true,'advance_started'=>true]);
ok('bad code → shade_code_not_in_brand', in_array('shade_code_not_in_brand',$blk3,true), true);
brands($pdo,[]);

echo "\n== [H] ops confirm-call flag ==\n";
setcfg($pdo,'commerce_confirm_call','{"enabled":false,"min_value":5000,"custom_always":true}');
ok('disabled → never', CustomTint::needsConfirmCall($pdo,6000,true), false);
setcfg($pdo,'commerce_confirm_call','{"enabled":true,"min_value":5000,"custom_always":true}');
ok('enabled + custom → yes',      CustomTint::needsConfirmCall($pdo,100,true), true);
ok('enabled + ready below min → no', CustomTint::needsConfirmCall($pdo,100,false), false);
ok('enabled + ready ≥ min → yes',    CustomTint::needsConfirmCall($pdo,6000,false), true);

echo "\n== [I] CommerceReturns refuses custom-tint, allows ready ==\n";
$pCust = mkProduct($pdo,$VX,$StX,$cityId,'p5-cust',json_encode($custAttrs));
$pdo->prepare("INSERT INTO inventory (product_id,quantity,reserved,low_stock_alert,track_inventory,allow_backorder,updated_at) VALUES (?,5,0,5,1,0,NOW())")->execute([$pCust]);
$pReady= mkProduct($pdo,$VX,$StX,$cityId,'p5-ready',json_encode($readyAttrs));
$pdo->prepare("INSERT INTO inventory (product_id,quantity,reserved,low_stock_alert,track_inventory,allow_backorder,updated_at) VALUES (?,5,0,5,1,0,NOW())")->execute([$pReady]);
$oCust = mkParent($pdo,'P5T-CUST',$uA,$VX,$StX,$cityId); mkItem($pdo,$oCust,$pCust,$VX);
$oReady= mkParent($pdo,'P5T-READY',$uA,$VX,$StX,$cityId); mkItem($pdo,$oReady,$pReady,$VX);
$rc = CommerceReturns::requestReturn($pdo,$oCust,$uA,'changed my mind');
ok('custom-tint return refused', ($rc['ok'] ?? true), false);
ok('refusal risk_class = no_return', $rc['risk_class'] ?? null, 'no_return');
$rr = CommerceReturns::requestReturn($pdo,$oReady,$uA,'sealed, unopened');
ok('ready paint return allowed', ($rr['ok'] ?? false), true);

echo "\n== [J] orderability gate is PER-BRAND ==\n";
require_once SYSTEM_ROOT . '/lib/commerce/OrderabilityResolver.php';
$catId=(int)$pdo->query("SELECT id FROM categories WHERE slug='paint-interior-emulsion' LIMIT 1")->fetchColumn();
$pdo->prepare("UPDATE products SET category_id=? WHERE id=?")->execute([$catId,$pCust]);
brands($pdo,[]);
ok('no brand → custom-tint product blocked (custom_tint_off)', OrderabilityResolver::check($pdo,$pCust,$cityId)['reason'], 'custom_tint_off');
brands($pdo,['P5T-Berger']);
ok('brand ON → same custom-tint product orderable', OrderabilityResolver::check($pdo,$pCust,$cityId)['reason'], 'ok');
brands($pdo,[]);
ok('brand OFF again → blocked', OrderabilityResolver::check($pdo,$pCust,$cityId)['reason'], 'custom_tint_off');

echo "\n== [K] structural wiring ==\n";
$adapter=get(SYSTEM_ROOT.'/heart/api/tint/index.php'); $heart=get(SYSTEM_ROOT.'/heart/index.php'); $ui=get(SYSTEM_ROOT.'/paint-tint/index.html');
ok('tint adapter exists', $adapter!=='', true);
ok('adapter: /api/tint/config + check-code + validate', has($adapter,'/api/tint/config')&&has($adapter,'/api/tint/check-code')&&has($adapter,'/api/tint/validate'), true);
ok('heart dispatches /api/tint', has($heart,'/api/tint'), true);
ok('UI exists + calls check-code + consent', $ui!==''&&has($ui,'/api/tint/check-code')&&has($ui,'custom shade'), true);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("P5 CUSTOM-TINT RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
