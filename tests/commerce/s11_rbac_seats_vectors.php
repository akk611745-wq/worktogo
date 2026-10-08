<?php
// ─────────────────────────────────────────────────────────────────────────────
//  S11 PROOF HARNESS — RBAC activation + commerce seats + uniform audit.
//  Proves: each seat (ops/finance/seller-ops/delivery-ops/unit-operator) resolves
//  to its least-privilege permission set via Permission::userHas (the gate the
//  /api/commerce-admin HTTP layer uses), legacy-admin + super_admin bypass, an
//  unassigned user is denied (fail-closed), category-scoped grants enforce scope,
//  and CommerceAdminAudit writes a scrubbed uniform trail. Idempotent (s11t-*).
//  Run: php tests/commerce/s11_rbac_seats_vectors.php   (local DB)
// ─────────────────────────────────────────────────────────────────────────────
define('SYSTEM_ROOT', dirname(dirname(__DIR__)));
foreach (file(SYSTEM_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2); $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
    if (getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
}
require_once SYSTEM_ROOT . '/core/helpers/Database.php';
require_once SYSTEM_ROOT . '/core/helpers/Permission.php';
require_once SYSTEM_ROOT . '/lib/commerce/CommerceAdminAudit.php';
$pdo = Database::getConnection();

$PASS = 0; $FAIL = 0;
function ok($label, $got, $exp) {
    global $PASS, $FAIL;
    if ($got === $exp) { $PASS++; printf("  PASS  %-52s got=%s\n", $label, var_export($got, true)); }
    else { $FAIL++; printf("  FAIL  %-52s got=%s exp=%s\n", $label, var_export($got, true), var_export($exp, true)); }
}
function cleanup(PDO $p) {
    $p->exec("SET FOREIGN_KEY_CHECKS=0");
    $p->exec("DELETE ur FROM user_roles ur JOIN users u ON u.id=ur.user_id WHERE u.email LIKE 's11t-%@wtg.test'");
    $p->exec("DELETE FROM commerce_admin_audit WHERE action LIKE 's11t-%'");
    $p->exec("DELETE FROM users WHERE email LIKE 's11t-%@wtg.test'");
    $p->exec("DELETE FROM categories WHERE slug LIKE 's11t-%'");
    $p->exec("SET FOREIGN_KEY_CHECKS=1");
}
function mkUser(PDO $p,$e,$role){ $p->prepare("INSERT INTO users (name,email,role,status,created_at,updated_at) VALUES ('S11',?,?, 'active',NOW(),NOW())")->execute([$e,$role]); return (int)$p->lastInsertId(); }
function roleId(PDO $p,$slug){ $s=$p->prepare("SELECT id FROM roles WHERE slug=? LIMIT 1"); $s->execute([$slug]); return (int)$s->fetchColumn(); }
function assign(PDO $p,$uid,$slug,$st='global',$sid=0){ $rid=roleId($p,$slug); $p->prepare("INSERT IGNORE INTO user_roles (user_id,role_id,scope_type,scope_id) VALUES (?,?,?,?)")->execute([$uid,$rid,$st,$sid]); }
// enforcement helper mirroring AuthMiddleware::requirePermission's gate
function can(PDO $p,$uid,$role,$perm,$st=null,$sid=null){ return Permission::userHas($p,$uid,$role,$perm,$st,$sid); }

cleanup($pdo);

// sanity: the seats + commerce perms exist (migration 011 applied)
$seats = ['commerce_ops','commerce_finance','commerce_seller_ops','commerce_delivery_ops','commerce_unit_operator'];
foreach ($seats as $sl) { if (!roleId($pdo,$sl)) { fwrite(STDERR, "MISSING seat role $sl — run migrate.php first\n"); exit(2); } }

$catX = (function(PDO $p){ $p->prepare("INSERT INTO categories (name,slug,type,is_active,created_at,updated_at) VALUES ('S11 Unit','s11t-unit','product',1,NOW(),NOW())")->execute(); return (int)$p->lastInsertId(); })($pdo);
$catY = (function(PDO $p){ $p->prepare("INSERT INTO categories (name,slug,type,is_active,created_at,updated_at) VALUES ('S11 Unit2','s11t-unit2','product',1,NOW(),NOW())")->execute(); return (int)$p->lastInsertId(); })($pdo);

$R = 'vendor_shopping'; // a non-admin legacy role → RBAC path is exercised (no bypass)
$uOps  = mkUser($pdo,'s11t-ops@wtg.test',$R);     assign($pdo,$uOps,'commerce_ops');
$uFin  = mkUser($pdo,'s11t-fin@wtg.test',$R);     assign($pdo,$uFin,'commerce_finance');
$uSel  = mkUser($pdo,'s11t-sel@wtg.test',$R);     assign($pdo,$uSel,'commerce_seller_ops');
$uDel  = mkUser($pdo,'s11t-del@wtg.test',$R);     assign($pdo,$uDel,'commerce_delivery_ops');
$uUnit = mkUser($pdo,'s11t-unit@wtg.test',$R);    assign($pdo,$uUnit,'commerce_unit_operator','category',$catX);
$uNone = mkUser($pdo,'s11t-none@wtg.test',$R);    // no seat
$uSup  = mkUser($pdo,'s11t-sup@wtg.test',$R);     assign($pdo,$uSup,'super_admin');
$uAdm  = mkUser($pdo,'s11t-adm@wtg.test','admin'); // legacy all-access

echo "\n== seat: commerce_ops ==\n";
ok('ops  → ops.view',        can($pdo,$uOps,$R,'commerce.ops.view'),      true);
ok('ops  → ops.manage',      can($pdo,$uOps,$R,'commerce.ops.manage'),    true);
ok('ops  → returns.manage',  can($pdo,$uOps,$R,'commerce.returns.manage'),true);
ok('ops  → order.override',  can($pdo,$uOps,$R,'commerce.order.override'),true);
ok('ops  ✗ payout.manage',   can($pdo,$uOps,$R,'commerce.payout.manage'), false);
ok('ops  ✗ killswitch',      can($pdo,$uOps,$R,'commerce.killswitch'),    false);

echo "\n== seat: commerce_finance ==\n";
ok('fin  → finance.view',    can($pdo,$uFin,$R,'commerce.finance.view'),  true);
ok('fin  → payout.manage',   can($pdo,$uFin,$R,'commerce.payout.manage'), true);
ok('fin  → returns.manage',  can($pdo,$uFin,$R,'commerce.returns.manage'),true);
ok('fin  ✗ ops.manage',      can($pdo,$uFin,$R,'commerce.ops.manage'),    false);
ok('fin  ✗ order.override',  can($pdo,$uFin,$R,'commerce.order.override'),false);

echo "\n== seat: commerce_seller_ops ==\n";
ok('sell → ops.view',        can($pdo,$uSel,$R,'commerce.ops.view'),      true);
ok('sell → seller.manage',   can($pdo,$uSel,$R,'commerce.seller.manage'), true);
ok('sell ✗ killswitch',      can($pdo,$uSel,$R,'commerce.killswitch'),    false);
ok('sell ✗ payout.manage',   can($pdo,$uSel,$R,'commerce.payout.manage'), false);

echo "\n== seat: commerce_delivery_ops ==\n";
ok('del  → delivery.manage', can($pdo,$uDel,$R,'commerce.delivery.manage'),true);
ok('del  → ops.view',        can($pdo,$uDel,$R,'commerce.ops.view'),       true);
ok('del  ✗ ops.manage',      can($pdo,$uDel,$R,'commerce.ops.manage'),     false);
ok('del  ✗ config.view',     can($pdo,$uDel,$R,'commerce.config.view'),    false);

echo "\n== seat: commerce_unit_operator (category-scoped) ==\n";
ok('unit → killswitch @ own unit',     can($pdo,$uUnit,$R,'commerce.killswitch','category',$catX), true);
ok('unit ✗ killswitch @ other unit',   can($pdo,$uUnit,$R,'commerce.killswitch','category',$catY), false);
ok('unit → killswitch anywhere (view)',can($pdo,$uUnit,$R,'commerce.killswitch'),                  true);
ok('unit → config.view',               can($pdo,$uUnit,$R,'commerce.config.view'),                 true);
ok('unit ✗ ops.manage',                can($pdo,$uUnit,$R,'commerce.ops.manage'),                  false);

echo "\n== bypass + fail-closed ==\n";
ok('legacy admin → anything',  can($pdo,$uAdm,'admin','commerce.payout.manage'), true);
ok('super_admin role → anything', can($pdo,$uSup,$R,'commerce.order.override'),  true);
ok('unassigned ✗ ops.view (fail-closed)', can($pdo,$uNone,$R,'commerce.ops.view'), false);

echo "\n== seat catalog: finance grants exactly its 4 keys ==\n";
$finKeys = $pdo->query("SELECT p.perm_key FROM roles r JOIN role_permissions rp ON rp.role_id=r.id JOIN permissions p ON p.id=rp.permission_id WHERE r.slug='commerce_finance' AND p.perm_key LIKE 'commerce.%' ORDER BY p.perm_key")->fetchAll(PDO::FETCH_COLUMN);
sort($finKeys);
ok('finance perm set',  $finKeys, ['commerce.config.view','commerce.finance.view','commerce.payout.manage','commerce.returns.manage']);

echo "\n== uniform audit trail (scrubbed) ==\n";
$auditId = CommerceAdminAudit::log($pdo, ['user_id'=>$uOps,'role'=>$R], 'commerce.ops.manage', 's11t-claim',
    ['method'=>'POST','route'=>'/api/commerce-admin/ops/claim','target_type'=>'order','target_id'=>'777','summary'=>['flag'=>'needs_ops','password'=>'hunter2','otp'=>'123456']]);
ok('audit row written',  $auditId > 0, true);
$row = $pdo->query("SELECT actor_user_id, action, target_type, target_id, request_summary, outcome FROM commerce_admin_audit WHERE id=".(int)$auditId)->fetch(PDO::FETCH_ASSOC) ?: [];
ok('audit actor',        (int)($row['actor_user_id'] ?? 0), $uOps);
ok('audit action',       $row['action'] ?? null, 's11t-claim');
ok('audit target',       ($row['target_type'] ?? '').'#'.($row['target_id'] ?? ''), 'order#777');
$sum = json_decode($row['request_summary'] ?? '{}', true) ?: [];
ok('audit keeps flag',       $sum['flag'] ?? null, 'needs_ops');
ok('audit redacts password', $sum['password'] ?? null, '[redacted]');
ok('audit redacts otp',      $sum['otp'] ?? null, '[redacted]');

cleanup($pdo);
echo "\n────────────────────────────────────\n";
printf("S11 RESULT: %d PASS, %d FAIL\n", $PASS, $FAIL);
exit($FAIL > 0 ? 1 : 0);
