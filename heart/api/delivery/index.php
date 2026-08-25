<?php
// ============================================================================
//  /api/delivery/* — NATIVE delivery API (Commerce Phase 5)
// ----------------------------------------------------------------------------
//  Replaces the old external SwiftDeliver shim (DISCARDED — founder's unfinished
//  early build). This is the delivery engine's OWN customer + control-room HTTP
//  surface, backed by DeliveryEngine (which composes the motherboard's native
//  deliveries / driver_wallets / delivery_riders / rate-card pieces). Rider-
//  facing endpoints live separately under /api/rider/* (own scope).
//
//  Routes:
//    GET  /api/delivery/quote            price a delivery by distance (public)
//    GET  /api/delivery/track/{orderId}  customer tracking snapshot (auth)
//    Admin control-room (ROLE_ADMIN + delivery.* permission):
//    GET/POST/PATCH /api/delivery/admin/rate-cards[/{id}]
//    GET  /api/delivery/admin/deliveries       live deliveries board
//    GET  /api/delivery/admin/riders           riders + availability
//    POST /api/delivery/admin/deliveries/{id}/reassign   nearest-rider re-assign
//
//  Uses front-controller globals $db, $method, $uri and the Response / Validator
//  / Permission / AuthMiddleware helpers. Per-route auth (public quote, customer
//  track, admin control-room).
// ============================================================================

require_once SYSTEM_ROOT . '/core/delivery/DeliveryEngine.php';

$numOrNull = function ($v) { return ($v === '' || $v === null) ? null : (float)$v; };

try {

    // ── GET /api/delivery/quote?store_id=&drop_lat=&drop_lng=&subtotal= ──────
    if ($method === 'GET' && $uri === '/api/delivery/quote') {
        $storeId = isset($_GET['store_id']) ? (int)$_GET['store_id'] : 0;
        $pLat = $numOrNull($_GET['pickup_lat'] ?? null); $pLng = $numOrNull($_GET['pickup_lng'] ?? null);
        $cityId = null;
        if ($storeId) {
            $s = $db->prepare("SELECT lat, lng, city_id FROM stores WHERE id = ? LIMIT 1");
            $s->execute([$storeId]);
            if ($srow = $s->fetch(PDO::FETCH_ASSOC)) {
                $pLat = $pLat ?? ($srow['lat'] !== null ? (float)$srow['lat'] : null);
                $pLng = $pLng ?? ($srow['lng'] !== null ? (float)$srow['lng'] : null);
                $cityId = $srow['city_id'] ? (int)$srow['city_id'] : null;
            }
        }
        $quote = DeliveryEngine::quote(
            $db, $pLat, $pLng,
            $numOrNull($_GET['drop_lat'] ?? null), $numOrNull($_GET['drop_lng'] ?? null),
            $cityId, (float)($_GET['subtotal'] ?? 0)
        );
        Response::success($quote);
        exit;
    }

    // ── GET /api/delivery/track/{orderId} — customer tracking ───────────────
    if ($method === 'GET' && preg_match('#^/api/delivery/track/(\d+)$#', $uri, $m)) {
        AuthMiddleware::requireRole(ROLE_CUSTOMER, ROLE_ADMIN);
        $track = DeliveryEngine::track($db, (int)$m[1]);
        if (!$track) { Response::notFound('Delivery'); exit; }
        Response::success($track);
        exit;
    }

    // ══ ADMIN CONTROL-ROOM ══════════════════════════════════════════════════
    if (strpos($uri, '/api/delivery/admin/') === 0) {
        $auth = AuthMiddleware::requireRole(ROLE_ADMIN);

        // GET rate cards
        if ($method === 'GET' && $uri === '/api/delivery/admin/rate-cards') {
            Permission::enforce($db, $auth, 'delivery.view');
            $rows = $db->query(
                "SELECT r.*, c.name AS city_name FROM delivery_rate_cards r
                   LEFT JOIN cities c ON c.id = r.city_id ORDER BY r.city_id IS NULL, c.name"
            )->fetchAll(PDO::FETCH_ASSOC);
            Response::success(['rate_cards' => $rows]);
            exit;
        }
        // POST create rate card
        if ($method === 'POST' && $uri === '/api/delivery/admin/rate-cards') {
            Permission::enforce($db, $auth, 'delivery.manage');
            $in = json_decode(file_get_contents('php://input'), true) ?? [];
            $db->prepare(
                "INSERT INTO delivery_rate_cards
                    (city_id, name, base_fare, per_km_rate, min_fee, free_above_subtotal,
                     rider_share_pct, platform_margin_pct, max_distance_km, is_active)
                 VALUES (:city,:name,:base,:km,:min,:free,:rider,:plat,:maxd,:active)"
            )->execute([
                ':city' => ($in['city_id'] ?? '') !== '' ? (int)$in['city_id'] : null,
                ':name' => trim((string)($in['name'] ?? 'Standard')) ?: 'Standard',
                ':base' => (float)($in['base_fare'] ?? 20), ':km' => (float)($in['per_km_rate'] ?? 8),
                ':min' => (float)($in['min_fee'] ?? 20),
                ':free' => $numOrNull($in['free_above_subtotal'] ?? null),
                ':rider' => (float)($in['rider_share_pct'] ?? 80), ':plat' => (float)($in['platform_margin_pct'] ?? 20),
                ':maxd' => $numOrNull($in['max_distance_km'] ?? null),
                ':active' => array_key_exists('is_active', $in) ? ($in['is_active'] ? 1 : 0) : 1,
            ]);
            Response::success(['id' => (int)$db->lastInsertId()], 201, 'Rate card created');
            exit;
        }
        // PATCH rate card
        if ($method === 'PATCH' && preg_match('#^/api/delivery/admin/rate-cards/(\d+)$#', $uri, $m)) {
            Permission::enforce($db, $auth, 'delivery.manage');
            $in = json_decode(file_get_contents('php://input'), true) ?? [];
            $set = []; $bind = [];
            foreach (['name'] as $f) if (array_key_exists($f, $in)) { $set[] = "$f = ?"; $bind[] = trim((string)$in[$f]); }
            foreach (['base_fare','per_km_rate','min_fee','rider_share_pct','platform_margin_pct'] as $f)
                if (array_key_exists($f, $in)) { $set[] = "$f = ?"; $bind[] = (float)$in[$f]; }
            foreach (['free_above_subtotal','max_distance_km'] as $f)
                if (array_key_exists($f, $in)) { $set[] = "$f = ?"; $bind[] = $numOrNull($in[$f]); }
            if (array_key_exists('is_active', $in)) { $set[] = 'is_active = ?'; $bind[] = $in['is_active'] ? 1 : 0; }
            if (!$set) { Response::validation('Nothing to update'); exit; }
            $set[] = 'updated_at = NOW()'; $bind[] = (int)$m[1];
            $db->prepare("UPDATE delivery_rate_cards SET " . implode(', ', $set) . " WHERE id = ?")->execute($bind);
            Response::success(['id' => (int)$m[1]], 200, 'Rate card updated');
            exit;
        }
        // GET live deliveries board
        if ($method === 'GET' && $uri === '/api/delivery/admin/deliveries') {
            Permission::enforce($db, $auth, 'delivery.view');
            $where = ['1=1']; $bind = [];
            if (isset($_GET['status']) && $_GET['status'] !== '') { $where[] = 'd.status = ?'; $bind[] = $_GET['status']; }
            if (isset($_GET['city_id']) && $_GET['city_id'] !== '') { $where[] = 'd.city_id = ?'; $bind[] = (int)$_GET['city_id']; }
            if (!empty($_GET['attention'])) { $where[] = "d.flag <> 'none'"; }
            $st = $db->prepare(
                "SELECT d.id, d.order_id, d.status, d.price, d.distance_km, d.city_id, d.driver_id,
                        d.assigned_at, d.picked_at, d.delivered_at, d.created_at,
                        d.flag, d.flagged_at, d.assign_attempts, d.eta_minutes,
                        o.order_number, s.name AS store_name, u.name AS rider_name
                   FROM deliveries d
                   LEFT JOIN orders o ON o.id = d.order_id
                   LEFT JOIN stores s ON s.id = d.store_id
                   LEFT JOIN users  u ON u.id = d.driver_id
                  WHERE " . implode(' AND ', $where) . "
                  ORDER BY (d.flag <> 'none') DESC,
                           FIELD(d.status,'pending','assigned','picked','delivered','cancelled'), d.created_at DESC
                  LIMIT 200"
            );
            $st->execute($bind);
            Response::success(['deliveries' => $st->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }
        // GET riders + availability
        if ($method === 'GET' && $uri === '/api/delivery/admin/riders') {
            Permission::enforce($db, $auth, 'delivery.view');
            $rows = $db->query(
                "SELECT r.rider_id, r.is_online, r.lat, r.lng, r.last_ping_at, r.vehicle_type,
                        r.max_active, r.active_count, u.name, u.phone,
                        w.cash_in_hand, w.collection_limit
                   FROM delivery_riders r
                   JOIN users u ON u.id = r.rider_id
                   LEFT JOIN driver_wallets w ON w.driver_id = r.rider_id
                  ORDER BY r.is_online DESC, r.active_count ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
            Response::success(['riders' => $rows]);
            exit;
        }
        // POST manual reassign (nearest)
        if ($method === 'POST' && preg_match('#^/api/delivery/admin/deliveries/(\d+)/reassign$#', $uri, $m)) {
            Permission::enforce($db, $auth, 'delivery.manage');
            $did = (int)$m[1];
            // free the current rider, set pending, then re-run nearest assignment
            $cur = $db->prepare("SELECT driver_id FROM deliveries WHERE id = ? LIMIT 1");
            $cur->execute([$did]); $curDriver = (int)($cur->fetchColumn() ?: 0);
            $db->prepare("UPDATE deliveries SET status='pending', driver_id=NULL, assigned_at=NULL, accepted_at=NULL WHERE id = ? AND status IN ('pending','assigned')")->execute([$did]);
            if ($curDriver) $db->prepare("UPDATE delivery_riders SET active_count = GREATEST(0, active_count-1) WHERE rider_id = ?")->execute([$curDriver]);
            $rider = DeliveryEngine::assignNearest($db, $did);
            Response::success(['assigned' => (bool)$rider, 'rider_id' => $rider['rider_id'] ?? null], 200, $rider ? 'Reassigned' : 'No rider available — left pending');
            exit;
        }

        Response::notFound('Delivery admin endpoint');
        exit;
    }

} catch (\Throwable $e) {
    error_log('[api/delivery] ' . $e->getMessage());
    Response::error('Delivery service error.', 500);
    exit;
}

Response::notFound('Delivery endpoint');
