<?php
// ============================================================================
//  /api/commerce-seller/* — external SELLER (shop) API  (Commerce S15)
// ----------------------------------------------------------------------------
//  The backend an external shop uses to sell on WorkToGo — the core foundation of
//  the seller-based model (no own stock). Authenticated as a `vendor_shopping`
//  user; every action is scoped to THAT user's vendor_id (resolved here). Thin glue
//  over SellerConsole; it edits no engine and no service.
//    GET  /me                         profile + availability
//    GET  /availability               current ON/OFF/BUSY
//    POST /availability               {status: online|offline|busy, busy_minutes?}
//    GET  /orders                     offered-orders inbox (customer = opaque ref)
//    POST /orders/{id}/accept         accept → (A) dispatch rider now (S6)
//    POST /orders/{id}/reject         {reason} → reroute to next seller (S3)
//    POST /orders/{id}/stock-out      {reason} → reroute + stockout signal
//    GET  /earnings                   this seller's earnings summary
//    GET  /customers/{ref}/history    this seller's own history for a Customer ID
//  Dispatched from heart/index.php on /api/commerce-seller. Uses $db/$method/$uri.
// ============================================================================

require_once SYSTEM_ROOT . '/lib/commerce/SellerConsole.php';
require_once SYSTEM_ROOT . '/lib/commerce/SellerCatalogue.php';
require_once SYSTEM_ROOT . '/lib/commerce/KycService.php';

$auth = AuthMiddleware::requireRole(ROLE_VENDOR_SHOPPING, ROLE_ADMIN);
$sellerVendorId = SellerConsole::resolveVendorId($db, (int)($auth['user_id'] ?? 0));
if ($sellerVendorId === null) { Response::forbidden('No seller account for this user.'); exit; }

$B = json_decode(file_get_contents('php://input'), true);
if (!is_array($B)) $B = [];

try {

    if ($method === 'GET' && $uri === '/api/commerce-seller/me') {
        Response::success(['vendor_id' => $sellerVendorId] + SellerConsole::availability($db, $sellerVendorId)); exit;
    }
    if ($method === 'GET' && $uri === '/api/commerce-seller/availability') {
        Response::success(SellerConsole::availability($db, $sellerVendorId)); exit;
    }
    if ($method === 'POST' && $uri === '/api/commerce-seller/availability') {
        $res = SellerConsole::setAvailability($db, $sellerVendorId, (string)($B['status'] ?? ''), (int)($B['busy_minutes'] ?? 30));
        if (empty($res['ok'])) { Response::error('Unknown status (use online|offline|busy).', 422); exit; }
        Response::success($res); exit;
    }
    if ($method === 'GET' && $uri === '/api/commerce-seller/orders') {
        Response::success(['orders' => SellerConsole::inbox($db, $sellerVendorId)]); exit;
    }
    if ($method === 'POST' && preg_match('#^/api/commerce-seller/orders/(\d+)/accept$#', $uri, $m)) {
        $res = SellerConsole::accept($db, $sellerVendorId, (int)$m[1]);
        if (empty($res['ok'])) { Response::error('Could not accept: ' . ($res['reason'] ?? 'error'), 422); exit; }
        Response::success($res); exit;
    }
    if ($method === 'POST' && preg_match('#^/api/commerce-seller/orders/(\d+)/reject$#', $uri, $m)) {
        $res = SellerConsole::reject($db, $sellerVendorId, (int)$m[1], (string)($B['reason'] ?? ''));
        if (empty($res['ok'])) { Response::error('Could not reject: ' . ($res['reason'] ?? 'error'), 422); exit; }
        Response::success($res); exit;
    }
    if ($method === 'POST' && preg_match('#^/api/commerce-seller/orders/(\d+)/stock-out$#', $uri, $m)) {
        $res = SellerConsole::stockOut($db, $sellerVendorId, (int)$m[1], (string)($B['reason'] ?? 'out of stock'));
        if (empty($res['ok'])) { Response::error('Could not flag stock-out: ' . ($res['reason'] ?? 'error'), 422); exit; }
        Response::success($res); exit;
    }
    // ── KYC (S18): the seller's own onboarding application ──────────────────────
    if ($method === 'GET' && $uri === '/api/commerce-seller/kyc') {
        Response::success(['kyc' => KycService::sellerGet($db, $sellerVendorId)]); exit;
    }
    if ($method === 'POST' && $uri === '/api/commerce-seller/kyc') {
        $res = KycService::sellerUpsert($db, $sellerVendorId, $B);
        if (empty($res['ok'])) { Response::error('Cannot edit: ' . ($res['reason'] ?? 'error'), 422); exit; }
        Response::success($res); exit;
    }
    if ($method === 'POST' && $uri === '/api/commerce-seller/kyc/submit') {
        $res = KycService::sellerSubmit($db, $sellerVendorId);
        if (empty($res['ok'])) { Response::error('Cannot submit (' . ($res['reason'] ?? 'error') . '): ' . implode(', ', $res['missing'] ?? []), 422); exit; }
        Response::success($res); exit;
    }

    // ── catalogue (S16): list / add offer / edit offer / restock ────────────────
    if ($method === 'GET' && $uri === '/api/commerce-seller/catalogue') {
        Response::success(['offers' => SellerCatalogue::listMine($db, $sellerVendorId)]); exit;
    }
    if ($method === 'POST' && $uri === '/api/commerce-seller/catalogue') {
        $res = SellerCatalogue::createOffer($db, $sellerVendorId, $B);
        if (empty($res['ok'])) { Response::error('Could not add offer: ' . ($res['reason'] ?? 'error'), 422); exit; }
        Response::success($res, 201, 'Offer submitted for verification'); exit;
    }
    if ($method === 'POST' && preg_match('#^/api/commerce-seller/catalogue/(\d+)$#', $uri, $m)) {
        $res = SellerCatalogue::updateOffer($db, $sellerVendorId, (int)$m[1], $B);
        if (empty($res['ok'])) { Response::error('Could not update: ' . ($res['reason'] ?? 'error'), 422); exit; }
        Response::success($res); exit;
    }
    if ($method === 'POST' && preg_match('#^/api/commerce-seller/catalogue/(\d+)/stock$#', $uri, $m)) {
        $res = SellerCatalogue::setStock($db, $sellerVendorId, (int)$m[1], (int)($B['stock'] ?? 0));
        if (empty($res['ok'])) { Response::error('Could not set stock: ' . ($res['reason'] ?? 'error'), 422); exit; }
        Response::success($res); exit;
    }

    if ($method === 'GET' && $uri === '/api/commerce-seller/earnings') {
        Response::success(SellerConsole::earnings($db, $sellerVendorId)); exit;
    }
    if ($method === 'GET' && preg_match('#^/api/commerce-seller/customers/([A-Za-z0-9]+)/history$#', $uri, $m)) {
        Response::success(SellerConsole::customerHistory($db, $sellerVendorId, $m[1])); exit;
    }

} catch (\Throwable $e) {
    error_log('[api/commerce-seller] ' . $e->getMessage());
    Response::error('Seller action failed.', 500);
    exit;
}

Response::notFound('Commerce-seller endpoint');
