<?php
// ─────────────────────────────────────────────────────────────────────────────
//  P1 PROOF HARNESS — Paint Unit foundation: attribute convention (PaintUnit) +
//  7 paint categories (migration 018) + unit_config layer-4 hook (UnitConfig
//  wired into OrderabilityResolver). Proves: paint rules have a home, custom
//  tint is OFF by config, a disabled unit blocks, and NON-paint products are
//  untouched (layer-4 default-pass → no regression). Config/convention only.
//  Run: php tests/commerce/p1_paint_unit_vectors.php   (local 10.11; idempotent)
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
require_once SYSTEM_ROOT . '/lib/commerce/UnitConfig.php';
require_once SYSTEM_ROOT . '/lib/commerce/PaintUnit.php';
$pdo = Database::getConnection();

$PASS = 0; $FAIL = 0;
function ok($label, $got, $exp) {
    global $PASS, $FAIL;
    if ($got === $exp) { $PASS++; printf("  PASS  %-50s got=%s\n", $label, var_export($got, true)); }
    else { $FAIL++; printf("  FAIL  %-50s got=%s exp=%s\n", $label, var_export($got, true), var_export($exp, true)); }
}
function setcfg(PDO $p,$k,$v){ $p->prepare("INSERT INTO app_settings (setting_key,setting_value,value_type,is_public) VALUES (?,?,'text',0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$k,$v]); UnitConfig::resetCache(); }
function cleanup(PDO $p){
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $p->exec("DELETE i FROM inventory i JOIN products pr ON pr.id=i.product_id WHERE pr.slug LIKE 'p1t-%'");
    $p->exec("DELETE FROM products WHERE slug LIKE 'p1t-%'");
    $p->exec("DELETE FROM stores   WHERE slug LIKE 'p1t-%'");
    $p->exec("DELETE FROM vendors  WHERE slug LIKE 'p1t-%'");
    $p->exec("DELETE FROM users    WHERE email LIKE 'p1t-%@wtg.test'");
    $p->exec("DELETE FROM categories WHERE slug LIKE 'p1t-%'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('P1',?, 'vendor_shopping','active',NOW(),NOW())")->execute([$e]); return (int)$p->lastInsertId(); }
function mkVendor(PDO $p,$uid,$slug){ $p->prepare("INSERT INTO vendors (user_id,business_name,slug,`type`,status,is_online,commission_rate,created_at,updated_at) VALUES (?,?,?,'shopping','active',1,10,NOW(),NOW())")->execute([$uid,'P1 V '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkStore(PDO $p,$slug,$v,$city){ $p->prepare("INSERT INTO stores (ownership,owner_vendor_id,city_id,name,slug,status,created_at,updated_at) VALUES ('vendor',?,?,?,?,'active',NOW(),NOW())")->execute([$v,$city,'P1 S '.$slug,$slug]); return (int)$p->lastInsertId(); }
function mkProduct(PDO $p,$vendorId,$storeId,$cityId,$catId,$slug,$attrs){ $p->prepare("INSERT INTO products (vendor_id,store_id,city_id,category_id,name,slug,price,status,attributes,created_at,updated_at) VALUES (?,?,?,?,?,?,199.00,'active',?,NOW(),NOW())")->execute([$vendorId,$storeId,$cityId,$catId,'P1 P '.$slug,$slug,$attrs]); return (int)$p->lastInsertId(); }
function mkInv(PDO $p,$pid,$qty){ $p->prepare("INSERT INTO inventory (product_id,quantity,reserved,low_stock_alert,track_inventory,allow_backorder,updated_at) VALUES (?,?,0,5,1,0,NOW())")->execute([$pid,$qty]); }

cleanup($pdo);
$cfgSnap = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='commerce_unit_config'")->fetchColumn();
register_shutdown_function(function() use($pdo,$cfgSnap){ try{ if($cfgSnap!==false&&$cfgSnap!==null){ $pdo->prepare("UPDATE app_settings SET setting_value=? WHERE setting_key='commerce_unit_config'")->execute([$cfgSnap]); } }catch(\Throwable $e){} });

$PAINT_FULL = '{"units":{"paint":{"enabled":true,"custom_tint_enabled":false,"label":"Paint"}},"paint_category_slugs":["paint-interior-emulsion","paint-exterior-emulsion","paint-enamel","paint-distemper","paint-primer","paint-putty","paint-waterproofing"]}';

echo "\n== [A] migration: 7 paint categories seeded ==\n";
$slugs = ['paint-interior-emulsion','paint-exterior-emulsion','paint-enamel','paint-distemper','paint-primer','paint-putty','paint-waterproofing'];
$cnt = (int)$pdo->query("SELECT COUNT(*) FROM categories WHERE slug IN ('".implode("','",$slugs)."') AND type='product' AND status='active'")->fetchColumn();
ok('7 paint categories present (type=product, active)', $cnt, 7);
$catInterior = (int)$pdo->query("SELECT id FROM categories WHERE slug='paint-interior-emulsion' LIMIT 1")->fetchColumn();
ok('interior-emulsion category resolvable', $catInterior > 0, true);

echo "\n== [B] PaintUnit convention ==\n";
ok('7 families defined', count(PaintUnit::FAMILIES), 7);
ok('isCustomTint: tintable+yes → true', PaintUnit::isCustomTint(['shade_mode'=>'tintable','custom_tintable'=>'yes']), true);
ok('isCustomTint: tintable+no → false', PaintUnit::isCustomTint(['shade_mode'=>'tintable','custom_tintable'=>'no']), false);
ok('isCustomTint: ready → false',       PaintUnit::isCustomTint(['shade_mode'=>'ready']), false);
ok('isPaintFamily(interior-emulsion)',  PaintUnit::isPaintFamily('interior-emulsion'), true);
ok('isPaintFamily(groceries) → false',  PaintUnit::isPaintFamily('groceries'), false);
$readyOk = ['brand'=>'Berger','product_name'=>'Easy Clean 4L','category'=>'interior-emulsion','pack_size'=>'4','pack_unit'=>'L','finish'=>'sheen','shade_mode'=>'ready','returnable'=>'yes'];
ok('validate ready (complete) → no missing', PaintUnit::validate($readyOk), []);
$readyBad = $readyOk; unset($readyBad['finish']);
ok('validate ready (no finish) → [finish]',  PaintUnit::validate($readyBad), ['finish']);
$tintOk = ['brand'=>'Indigo','product_name'=>'Interior Gold','category'=>'interior-emulsion','pack_size'=>'4','pack_unit'=>'L','shade_mode'=>'tintable','custom_tintable'=>'yes','base_type'=>'white','returnable'=>'no'];
ok('validate tintable (complete) → no missing', PaintUnit::validate($tintOk), []);

echo "\n== [C] UnitConfig resolution ==\n";
setcfg($pdo,'commerce_unit_config',$PAINT_FULL);
ok('unitForCategory(paint cat) → paint', UnitConfig::unitForCategory($pdo,$catInterior), 'paint');
ok('unitForCategory(null) → null',       UnitConfig::unitForCategory($pdo,null), null);
ok('unitEnabled(paint) → true',          UnitConfig::unitEnabled($pdo,'paint'), true);
ok('custom_tint_enabled default → false',UnitConfig::unitFlag($pdo,'paint','custom_tint_enabled',false), false);

echo "\n== [D] OrderabilityResolver layer-4 (end-to-end) ==\n";
$cityId=(int)($pdo->query("SELECT id FROM cities ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if(!$cityId){ $pdo->exec("INSERT INTO cities (name,slug) VALUES ('P1 City','p1t-city')"); $cityId=(int)$pdo->lastInsertId(); }
$u=mkUser($pdo,'p1t-v@wtg.test'); $V=mkVendor($pdo,$u,'p1t-v'); $St=mkStore($pdo,'p1t-s',$V,$cityId);
// temp NON-paint category (control)
$pdo->prepare("INSERT INTO categories (name,slug,type,status) VALUES ('P1 Other','p1t-other-cat','product','active')")->execute();
$catOther=(int)$pdo->lastInsertId();

$readyAttrs = json_encode($readyOk);
$tintAttrs  = json_encode($tintOk);
$pReady = mkProduct($pdo,$V,$St,$cityId,$catInterior,'p1t-ready',$readyAttrs); mkInv($pdo,$pReady,10);
$pTint  = mkProduct($pdo,$V,$St,$cityId,$catInterior,'p1t-tint', $tintAttrs);  mkInv($pdo,$pTint,10);
$pOther = mkProduct($pdo,$V,$St,$cityId,$catOther,  'p1t-other',$readyAttrs); mkInv($pdo,$pOther,10);

setcfg($pdo,'commerce_unit_config',$PAINT_FULL); // custom tint OFF
ok('paint READY product → orderable', chk_reason($pdo,$pReady,$cityId), 'ok');
ok('paint CUSTOM-TINT (tint off) → custom_tint_off', chk_reason($pdo,$pTint,$cityId), 'custom_tint_off');
ok('  …blocked at layer 4', OrderabilityResolver::check($pdo,$pTint,$cityId)['layer'], 4);
ok('NON-paint product → orderable (layer-4 pass)', chk_reason($pdo,$pOther,$cityId), 'ok');

// flip custom tint ON
setcfg($pdo,'commerce_unit_config','{"units":{"paint":{"enabled":true,"custom_tint_enabled":true}},"paint_category_slugs":["paint-interior-emulsion","paint-exterior-emulsion","paint-enamel","paint-distemper","paint-primer","paint-putty","paint-waterproofing"]}');
ok('custom tint ON → custom-tint now orderable', chk_reason($pdo,$pTint,$cityId), 'ok');

// disable the whole paint unit
setcfg($pdo,'commerce_unit_config','{"units":{"paint":{"enabled":false}},"paint_category_slugs":["paint-interior-emulsion"]}');
ok('paint unit OFF → ready blocked unit_unavailable', chk_reason($pdo,$pReady,$cityId), 'unit_unavailable');
ok('NON-paint still orderable (unit off only hits paint)', chk_reason($pdo,$pOther,$cityId), 'ok');

echo "\n== [E] friendly seller-hidden messages ==\n";
ok('msg(custom_tint_off) mentions ready shades', str_contains(OrderabilityResolver::message('custom_tint_off'),'ready shades'), true);
ok('msg(unit_unavailable) is generic',           str_contains(OrderabilityResolver::message('unit_unavailable'),'available'), true);

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("P1 RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);

function chk_reason(PDO $p,$pid,$city){ return OrderabilityResolver::check($p,$pid,$city)['reason']; }
