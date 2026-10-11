<?php
// ============================================================================
//  /api/commerce-admin/* — Commerce OPS + ADMIN API  (Commerce S11)
// ----------------------------------------------------------------------------
//  The thin HTTP surface that finally EXPOSES the commerce ops/admin backends
//  built in S7–S9 (which were class-only until now) and ACTIVATES RBAC for them:
//    • OpsConsole        (S7)  — exception queue, holds, cancels, comms
//    • AdminControlRoom  (S8)  — live config, kill-switches, override, money
//    • CommerceSettlement(S9)  — settle / payouts / accounting
//    • CommerceReturns   (S9)  — approve / receive / refund / reject
//  Every route is gated by a COMMERCE PERMISSION via AuthMiddleware::requirePermission
//  (data-driven RBAC — legacy users.role='admin' and super_admin still pass
//  everything, so nothing breaks for today's admin; seats get least privilege).
//  Every privileged action writes the UNIFORM audit trail (CommerceAdminAudit),
//  on top of the backends' own specific trails. This adapter calls the backends'
//  public methods only — it edits no engine and no service.
//
//  Dispatched from heart/index.php on prefix /api/commerce-admin. Uses the
//  front-controller globals $db (PDO), $method, $uri, plus AuthMiddleware /
//  Response / Permission.
// ============================================================================

require_once SYSTEM_ROOT . '/core/helpers/Permission.php';
require_once SYSTEM_ROOT . '/lib/commerce/OpsConsole.php';
require_once SYSTEM_ROOT . '/lib/commerce/AdminControlRoom.php';
require_once SYSTEM_ROOT . '/lib/commerce/CommerceSettlement.php';
require_once SYSTEM_ROOT . '/lib/commerce/CommerceReturns.php';
require_once SYSTEM_ROOT . '/lib/commerce/CommerceAdminAudit.php';
require_once SYSTEM_ROOT . '/lib/commerce/KycService.php';

$B   = json_decode(file_get_contents('php://input'), true);
if (!is_array($B)) $B = [];
$pi  = function (string $k, $d = 0) use ($B) { return isset($B[$k]) ? (int)$B[$k] : $d; };
$ps  = function (string $k, $d = '') use ($B) { return isset($B[$k]) ? trim((string)$B[$k]) : $d; };
$gate = function (string $perm, ?string $st = null, ?int $sid = null) {
    return AuthMiddleware::requirePermission($perm, $st, $sid);
};
$audit = function (?array $auth, ?string $perm, string $action, array $opts = []) use ($db, $method, $uri) {
    $opts += ['method' => $method, 'route' => $uri];
    return CommerceAdminAudit::log($db, $auth, $perm, $action, $opts);
};

try {

    // ── GET /me — the caller's seat window (roles + effective permissions) ───
    if ($method === 'GET' && $uri === '/api/commerce-admin/me') {
        $auth = AuthMiddleware::require();
        $uid  = (int)($auth['user_id'] ?? 0);
        Response::success([
            'user_id'     => $uid,
            'role'        => $auth['role'] ?? null,
            'seats'       => Permission::roleSlugsForUser($db, $uid),
            'permissions' => Permission::listForUser($db, $uid, $auth['role'] ?? null),
        ]);
        exit;
    }

    // ─────────────────────────── OPS CONSOLE (S7) ───────────────────────────
    if ($method === 'GET' && $uri === '/api/commerce-admin/ops/queue') {
        $gate('commerce.ops.view');
        Response::success(['tickets' => OpsConsole::exceptionQueue($db)]); exit;
    }
    if ($method === 'GET' && $uri === '/api/commerce-admin/ops/live') {
        $gate('commerce.ops.view');
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        Response::success(['orders' => OpsConsole::liveOrders($db, $limit)]); exit;
    }
    if ($method === 'GET' && $uri === '/api/commerce-admin/ops/summary') {
        $gate('commerce.ops.view');
        Response::success(OpsConsole::ownerSummary($db)); exit;
    }
    if ($method === 'GET' && preg_match('#^/api/commerce-admin/ops/thread/(\d+)$#', $uri, $m)) {
        $gate('commerce.ops.view');
        Response::success(['thread' => OpsConsole::thread($db, (int)$m[1])]); exit;
    }
    if ($method === 'POST' && $uri === '/api/commerce-admin/ops/claim') {
        $auth = $gate('commerce.ops.manage'); $uid = (int)$auth['user_id'];
        $oid = $pi('order_id'); $flag = $ps('flag');
        $ok = OpsConsole::claim($db, $oid, $flag, $uid);
        $audit($auth, 'commerce.ops.manage', 'ops.claim', ['target_type' => 'order', 'target_id' => $oid, 'summary' => ['flag' => $flag], 'outcome' => $ok ? 'ok' : 'error']);
        Response::success(['claimed' => $ok]); exit;
    }
    if ($method === 'POST' && $uri === '/api/commerce-admin/ops/escalate') {
        $auth = $gate('commerce.ops.manage'); $uid = (int)$auth['user_id'];
        $oid = $pi('order_id'); $flag = $ps('flag'); $note = $ps('note');
        $ok = OpsConsole::escalate($db, $oid, $flag, $uid, $note !== '' ? $note : null);
        $audit($auth, 'commerce.ops.manage', 'ops.escalate', ['target_type' => 'order', 'target_id' => $oid, 'summary' => ['flag' => $flag, 'note' => $note], 'outcome' => $ok ? 'ok' : 'error']);
        Response::success(['escalated' => $ok]); exit;
    }
    if ($method === 'POST' && $uri === '/api/commerce-admin/ops/resolve') {
        $auth = $gate('commerce.ops.manage'); $uid = (int)$auth['user_id'];
        $oid = $pi('order_id'); $flag = $ps('flag'); $note = $ps('note');
        $ok = OpsConsole::resolve($db, $oid, $flag, $uid, $note !== '' ? $note : null);
        $audit($auth, 'commerce.ops.manage', 'ops.resolve', ['target_type' => 'order', 'target_id' => $oid, 'summary' => ['flag' => $flag, 'note' => $note], 'outcome' => $ok ? 'ok' : 'error']);
        Response::success(['resolved' => $ok]); exit;
    }
    if ($method === 'POST' && $uri === '/api/commerce-admin/ops/hold/approve') {
        $auth = $gate('commerce.ops.manage'); $uid = (int)$auth['user_id'];
        $oid = $pi('order_id');
        $ok = OpsConsole::approveHold($db, $oid, $uid);
        $audit($auth, 'commerce.ops.manage', 'ops.hold.approve', ['target_type' => 'order', 'target_id' => $oid, 'outcome' => $ok ? 'ok' : 'error']);
        Response::success(['approved' => $ok]); exit;
    }
    if ($method === 'POST' && $uri === '/api/commerce-admin/ops/hold/reject') {
        $auth = $gate('commerce.ops.manage'); $uid = (int)$auth['user_id'];
        $oid = $pi('order_id'); $reason = $ps('reason');
        $res = OpsConsole::rejectHold($db, $oid, $uid, $reason);
        $audit($auth, 'commerce.ops.manage', 'ops.hold.reject', ['target_type' => 'order', 'target_id' => $oid, 'summary' => ['reason' => $reason], 'outcome' => !empty($res['ok']) ? 'ok' : 'error']);
        Response::success($res); exit;
    }
    if ($method === 'POST' && $uri === '/api/commerce-admin/ops/message') {
        $auth = $gate('commerce.ops.manage'); $uid = (int)$auth['user_id'];
        $oid = $pi('order_id'); $fromType = $ps('from_type', 'ops'); $toType = $ps('to_type'); $bodyTxt = $ps('body');
        $id = OpsConsole::postMessage($db, $oid, $fromType, $uid, $bodyTxt, $toType !== '' ? $toType : null);
        $audit($auth, 'commerce.ops.manage', 'ops.message', ['target_type' => 'order', 'target_id' => $oid, 'summary' => ['to' => $toType], 'outcome' => 'ok']);
        Response::success(['message_id' => $id]); exit;
    }
    if ($method === 'POST' && $uri === '/api/commerce-admin/ops/reassign-rider') {
        $auth = $gate('commerce.delivery.manage'); $uid = (int)$auth['user_id'];
        $oid = $pi('order_id'); $rid = isset($B['rider_id']) ? (int)$B['rider_id'] : null;
        $res = OpsConsole::reassignRider($db, $oid, $rid);
        $audit($auth, 'commerce.delivery.manage', 'ops.reassign_rider', ['target_type' => 'order', 'target_id' => $oid, 'summary' => ['rider_id' => $rid], 'outcome' => !empty($res['ok']) ? 'ok' : 'error']);
        Response::success($res); exit;
    }
    if ($method === 'POST' && $uri === '/api/commerce-admin/ops/cancel') {
        $auth = $gate('commerce.order.override'); $uid = (int)$auth['user_id'];
        $oid = $pi('order_id'); $reason = $ps('reason');
        $res = OpsConsole::cancelOrder($db, $oid, $uid, $reason);
        $audit($auth, 'commerce.order.override', 'ops.cancel', ['target_type' => 'order', 'target_id' => $oid, 'summary' => ['reason' => $reason], 'outcome' => !empty($res['ok']) ? 'ok' : 'error']);
        Response::success($res); exit;
    }

    // ───────────────────────── ADMIN CONTROL ROOM (S8) ──────────────────────
    if ($method === 'GET' && $uri === '/api/commerce-admin/config') {
        $gate('commerce.config.view');
        Response::success(['config' => AdminControlRoom::getConfig($db), 'editable_keys' => AdminControlRoom::editableKeys()]); exit;
    }
    if ($method === 'POST' && $uri === '/api/commerce-admin/config') {
        $auth = $gate('commerce.config.manage'); $uid = (int)$auth['user_id'];
        $key = $ps('key'); $value = $B['value'] ?? null; $reason = $ps('reason');
        $res = AdminControlRoom::setConfig($db, $key, $value, $uid, $reason !== '' ? $reason : null);
        $audit($auth, 'commerce.config.manage', 'config.set', ['target_type' => 'setting', 'target_id' => $key, 'summary' => ['reason' => $reason], 'outcome' => !empty($res['ok']) ? 'ok' : 'error']);
        if (empty($res['ok'])) { Response::error($res['error'] ?? 'not_editable', 422); exit; }
        Response::success($res); exit;
    }
    if ($method === 'GET' && $uri === '/api/commerce-admin/config/audit') {
        $gate('commerce.config.view');
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        Response::success(['config_audit' => AdminControlRoom::configAudit($db, $limit), 'admin_audit' => CommerceAdminAudit::recent($db, $limit)]); exit;
    }
    if ($method === 'POST' && ($uri === '/api/commerce-admin/pause' || $uri === '/api/commerce-admin/resume')) {
        $resume = ($uri === '/api/commerce-admin/resume');
        $scope = $ps('scope'); $id = isset($B['id']) ? (int)$B['id'] : null; $reason = $ps('reason');
        // Scope → permission: seller-ops owns sellers; a unit operator is category-scoped;
        // everything else (commerce / zone / rider) is a global kill-switch (admin-grade).
        if ($scope === 'seller')      { $auth = $gate('commerce.seller.manage'); $perm = 'commerce.seller.manage'; }
        elseif ($scope === 'unit')    { $auth = $gate('commerce.killswitch', 'category', $id); $perm = 'commerce.killswitch'; }
        else                          { $auth = $gate('commerce.killswitch'); $perm = 'commerce.killswitch'; }
        $uid = (int)$auth['user_id'];
        $res = $resume ? AdminControlRoom::resume($db, $scope, $id, $uid, $reason !== '' ? $reason : null)
                       : AdminControlRoom::pause($db, $scope, $id, $uid, $reason !== '' ? $reason : null);
        $audit($auth, $perm, $resume ? 'killswitch.resume' : 'killswitch.pause', ['target_type' => $scope, 'target_id' => $id, 'summary' => ['reason' => $reason], 'outcome' => !empty($res['ok']) ? 'ok' : 'error']);
        if (empty($res['ok'])) { Response::error($res['error'] ?? 'bad_scope', 422); exit; }
        Response::success($res); exit;
    }
    if ($method === 'POST' && $uri === '/api/commerce-admin/order/force-status') {
        $auth = $gate('commerce.order.override'); $uid = (int)$auth['user_id'];
        $oid = $pi('order_id'); $status = $ps('status'); $reason = $ps('reason');
        $res = AdminControlRoom::forceStatus($db, $oid, $status, $uid, $reason);
        $audit($auth, 'commerce.order.override', 'order.force_status', ['target_type' => 'order', 'target_id' => $oid, 'summary' => ['status' => $status, 'reason' => $reason], 'outcome' => !empty($res['ok']) ? 'ok' : 'error']);
        if (empty($res['ok'])) { Response::error($res['error'] ?? 'bad_status', 422); exit; }
        Response::success($res); exit;
    }
    if ($method === 'GET' && $uri === '/api/commerce-admin/money') {
        $gate('commerce.finance.view');
        Response::success(AdminControlRoom::moneyDashboard($db)); exit;
    }

    // ─────────────────────── SETTLEMENT / PAYOUTS (S9) ──────────────────────
    if ($method === 'POST' && $uri === '/api/commerce-admin/settle') {
        $auth = $gate('commerce.payout.manage');
        $res = CommerceSettlement::settleEligible($db);
        $audit($auth, 'commerce.payout.manage', 'settle.run', ['summary' => $res, 'outcome' => 'ok']);
        Response::success($res); exit;
    }
    if ($method === 'POST' && $uri === '/api/commerce-admin/payout/run') {
        $auth = $gate('commerce.payout.manage'); $uid = (int)$auth['user_id'];
        $pm = $ps('method', 'bank');
        $res = CommerceSettlement::runPayout($db, $pm, $uid);
        $audit($auth, 'commerce.payout.manage', 'payout.run', ['summary' => ['method' => $pm], 'outcome' => 'ok']);
        Response::success($res); exit;
    }
    if ($method === 'POST' && $uri === '/api/commerce-admin/payout/mark-paid') {
        $auth = $gate('commerce.payout.manage');
        $pid = $pi('payout_id'); $ref = $ps('reference');
        $ok = CommerceSettlement::markPayoutPaid($db, $pid, $ref !== '' ? $ref : null);
        $audit($auth, 'commerce.payout.manage', 'payout.mark_paid', ['target_type' => 'payout', 'target_id' => $pid, 'summary' => ['reference' => $ref], 'outcome' => $ok ? 'ok' : 'error']);
        Response::success(['paid' => $ok]); exit;
    }
    if ($method === 'GET' && $uri === '/api/commerce-admin/accounting') {
        $gate('commerce.finance.view');
        $days = isset($_GET['days']) ? (int)$_GET['days'] : 30;
        Response::success(CommerceSettlement::accountingSplit($db, $days)); exit;
    }

    // ────────────────────────────── RETURNS (S9) ────────────────────────────
    if ($method === 'POST' && preg_match('#^/api/commerce-admin/returns/(approve|received|refund|reject)$#', $uri, $m)) {
        $auth = $gate('commerce.returns.manage'); $uid = (int)$auth['user_id'];
        $rid = $pi('return_id'); $act = $m[1];
        if ($act === 'approve')       { $out = ['done' => CommerceReturns::approve($db, $rid, $uid)]; }
        elseif ($act === 'received')  { $out = ['done' => CommerceReturns::markReceived($db, $rid, $uid)]; }
        elseif ($act === 'refund')    { $out = CommerceReturns::refund($db, $rid, $uid); }
        else                          { $out = ['done' => CommerceReturns::reject($db, $rid, $uid, $ps('reason'))]; }
        $ok = isset($out['ok']) ? !empty($out['ok']) : !empty($out['done']);
        $audit($auth, 'commerce.returns.manage', 'returns.' . $act, ['target_type' => 'return', 'target_id' => $rid, 'outcome' => $ok ? 'ok' : 'error']);
        Response::success($out); exit;
    }

    // ────────────────────────── KYC REVIEW (S18) ───────────────────────────
    if ($method === 'GET' && $uri === '/api/commerce-admin/kyc/pending') {
        $gate('commerce.kyc.review');
        Response::success(KycService::pending($db)); exit;
    }
    if ($method === 'POST' && preg_match('#^/api/commerce-admin/kyc/seller/(\d+)/review$#', $uri, $m)) {
        $auth = $gate('commerce.kyc.review'); $uid = (int)$auth['user_id'];
        $approve = !empty($B['approve']); $note = $ps('note');
        $res = KycService::sellerReview($db, (int)$m[1], $approve, $note !== '' ? $note : null, $uid);
        $audit($auth, 'commerce.kyc.review', 'kyc.seller.' . ($approve ? 'approve' : 'reject'), ['target_type' => 'vendor', 'target_id' => (int)$m[1], 'summary' => ['note' => $note], 'outcome' => !empty($res['ok']) ? 'ok' : 'error']);
        if (empty($res['ok'])) { Response::error($res['reason'] ?? 'error', 422); exit; }
        Response::success($res); exit;
    }
    if ($method === 'POST' && preg_match('#^/api/commerce-admin/kyc/rider/(\d+)/review$#', $uri, $m)) {
        $auth = $gate('commerce.kyc.review'); $uid = (int)$auth['user_id'];
        $approve = !empty($B['approve']); $note = $ps('note'); $cityId = isset($B['city_id']) ? (int)$B['city_id'] : null;
        $res = KycService::riderReview($db, (int)$m[1], $approve, $note !== '' ? $note : null, $uid, $cityId);
        $audit($auth, 'commerce.kyc.review', 'kyc.rider.' . ($approve ? 'approve' : 'reject'), ['target_type' => 'rider_user', 'target_id' => (int)$m[1], 'summary' => ['note' => $note], 'outcome' => !empty($res['ok']) ? 'ok' : 'error']);
        if (empty($res['ok'])) { Response::error($res['reason'] ?? 'error', 422); exit; }
        Response::success($res); exit;
    }

} catch (\Throwable $e) {
    error_log('[api/commerce-admin] ' . $e->getMessage());
    Response::error('Commerce admin action failed.', 500);
    exit;
}

Response::notFound('Commerce-admin endpoint');
