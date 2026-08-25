<?php
// ============================================================================
//  /api/rider/* — Rider-facing API (Commerce Phase 5, own scope)
// ----------------------------------------------------------------------------
//  The delivery rider's own surface — separate from the customer storefront and
//  the admin control-room. A rider is a users.role='delivery' account; their
//  operational state (online, location, active deliveries) lives in
//  delivery_riders. All routes require a rider token (ROLE_DELIVERY).
//
//    GET  /api/rider/me                       profile + availability + wallet
//    POST /api/rider/online     {online}      go online / offline
//    POST /api/rider/location   {lat,lng}     live location ping
//    GET  /api/rider/deliveries               my active + newly assigned jobs
//    POST /api/rider/deliveries/{id}/accept   acknowledge an assignment
//    POST /api/rider/deliveries/{id}/reject   decline → auto-reassign to next
//    POST /api/rider/deliveries/{id}/pickup   picked up from the store
//    POST /api/rider/deliveries/{id}/deliver  delivered to the customer
// ============================================================================

require_once SYSTEM_ROOT . '/core/delivery/DeliveryEngine.php';

require_once SYSTEM_ROOT . '/core/helpers/RateLimiter.php';

$auth   = AuthMiddleware::requireRole(ROLE_DELIVERY);
$riderId = (int)$auth['user_id'];
$body   = function () { return json_decode(file_get_contents('php://input'), true) ?? []; };
// M5 (audit §4): per-rider spam guard.
$riderLimit = function (string $action, int $max, int $windowSec) {
    if (!RateLimiter::check($action, $max, $windowSec)) {
        Response::error('Too many requests. Please slow down.', 429);
        exit;
    }
};

try {
    DeliveryEngine::ensureRider($db, $riderId);

    // GET /api/rider/me
    if ($method === 'GET' && $uri === '/api/rider/me') {
        $st = $db->prepare(
            "SELECT r.is_online, r.lat, r.lng, r.vehicle_type, r.max_active, r.active_count, r.last_ping_at,
                    u.name, u.phone, w.cash_in_hand, w.earnings, w.collection_limit
               FROM delivery_riders r JOIN users u ON u.id = r.rider_id
               LEFT JOIN driver_wallets w ON w.driver_id = r.rider_id
              WHERE r.rider_id = ? LIMIT 1"
        );
        $st->execute([$riderId]);
        Response::success(['rider' => $st->fetch(PDO::FETCH_ASSOC) ?: []]);
        exit;
    }

    // POST /api/rider/online
    if ($method === 'POST' && $uri === '/api/rider/online') {
        $riderLimit('rider_write_' . $riderId, 90, 60);
        $b = $body();
        DeliveryEngine::riderSetOnline($db, $riderId, !empty($b['online']));
        Response::success(['online' => !empty($b['online'])], 200, !empty($b['online']) ? 'You are online' : 'You are offline');
        exit;
    }

    // POST /api/rider/location
    if ($method === 'POST' && $uri === '/api/rider/location') {
        $riderLimit('rider_ping_' . $riderId, 120, 60);
        $b = $body();
        if (!isset($b['lat'], $b['lng'])) { Response::validation('lat and lng required'); exit; }
        $lat = (float)$b['lat']; $lng = (float)$b['lng'];
        // L4 (audit §4): validate coordinate ranges before storing.
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) { Response::validation('Invalid coordinates'); exit; }
        DeliveryEngine::riderPing($db, $riderId, $lat, $lng);
        Response::success(['ok' => true]);
        exit;
    }

    // GET /api/rider/deliveries — active jobs for this rider
    if ($method === 'GET' && $uri === '/api/rider/deliveries') {
        $st = $db->prepare(
            "SELECT d.id, d.order_id, d.status, d.price, d.rider_payout, d.distance_km,
                    d.pickup_lat, d.pickup_lng, d.drop_lat, d.drop_lng, d.assigned_at, d.picked_at,
                    o.order_number, o.shipping_address, s.name AS store_name, s.locality AS store_locality
               FROM deliveries d
               LEFT JOIN orders o ON o.id = d.order_id
               LEFT JOIN stores s ON s.id = d.store_id
              WHERE d.driver_id = ? AND d.status IN ('assigned','picked')
              ORDER BY FIELD(d.status,'assigned','picked'), d.assigned_at ASC"
        );
        $st->execute([$riderId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $addr = $r['shipping_address'] ? json_decode($r['shipping_address'], true) : null;
            $r['drop_address'] = is_array($addr) ? trim(($addr['line1'] ?? $addr['address'] ?? '') . ' ' . ($addr['city'] ?? '')) : null;
            $r['customer_name'] = is_array($addr) ? ($addr['name'] ?? null) : null;
            $r['customer_phone'] = is_array($addr) ? ($addr['phone'] ?? null) : null;
            unset($r['shipping_address']);
        }
        Response::success(['deliveries' => $rows]);
        exit;
    }

    // POST /api/rider/deliveries/{id}/{action}
    if ($method === 'POST' && preg_match('#^/api/rider/deliveries/(\d+)/(accept|reject|pickup|deliver)$#', $uri, $m)) {
        $riderLimit('rider_write_' . $riderId, 90, 60);
        $did = (int)$m[1]; $action = $m[2];
        try {
            $res = match ($action) {
                'accept'  => DeliveryEngine::riderAccept($db, $did, $riderId),
                'reject'  => DeliveryEngine::riderReject($db, $did, $riderId),
                'pickup'  => DeliveryEngine::riderPickup($db, $did, $riderId),
                'deliver' => DeliveryEngine::riderDeliver($db, $did, $riderId),
            };
            Response::success($res, 200, 'Updated');
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 400);
        }
        exit;
    }

} catch (\Throwable $e) {
    error_log('[api/rider] ' . $e->getMessage());
    Response::error('Rider service error.', 500);
    exit;
}

Response::notFound('Rider endpoint');
