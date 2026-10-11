<?php
// ============================================================================
//  /api/commerce-rider/* — rider ONBOARDING / KYC  (Commerce S18)
// ----------------------------------------------------------------------------
//  A rider self-registers via /api/auth/register (role=delivery, now allowed),
//  then completes KYC here (name, phone[OTP], address, licence, vehicle+RC,
//  bank/UPI payout target, photo) and submits for review. On admin approval the
//  rider's delivery_riders profile (S6) is created. Scoped to the authed delivery
//  user. Thin glue over KycService; no engine edit.
//    GET  /kyc            the rider's own application
//    POST /kyc            upsert (draft)
//    POST /kyc/submit     submit for review
//  Dispatched from heart/index.php on /api/commerce-rider. Uses $db/$method/$uri.
// ============================================================================

require_once SYSTEM_ROOT . '/lib/commerce/KycService.php';

$auth = AuthMiddleware::requireRole(ROLE_DELIVERY, ROLE_ADMIN);
$uid  = (int)($auth['user_id'] ?? 0);
$B = json_decode(file_get_contents('php://input'), true);
if (!is_array($B)) $B = [];

try {
    if ($method === 'GET' && $uri === '/api/commerce-rider/kyc') {
        Response::success(['kyc' => KycService::riderGet($db, $uid)]); exit;
    }
    if ($method === 'POST' && $uri === '/api/commerce-rider/kyc') {
        $res = KycService::riderUpsert($db, $uid, $B);
        if (empty($res['ok'])) { Response::error('Cannot edit: ' . ($res['reason'] ?? 'error'), 422); exit; }
        Response::success($res); exit;
    }
    if ($method === 'POST' && $uri === '/api/commerce-rider/kyc/submit') {
        $res = KycService::riderSubmit($db, $uid);
        if (empty($res['ok'])) { Response::error('Cannot submit (' . ($res['reason'] ?? 'error') . '): ' . implode(', ', $res['missing'] ?? []), 422); exit; }
        Response::success($res); exit;
    }
} catch (\Throwable $e) {
    error_log('[api/commerce-rider] ' . $e->getMessage());
    Response::error('Rider action failed.', 500);
    exit;
}

Response::notFound('Commerce-rider endpoint');
