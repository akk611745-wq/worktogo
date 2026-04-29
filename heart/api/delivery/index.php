<?php
/**
 * /api/delivery/* — Delivery endpoints
 * Requires ROLE_DELIVERY or ROLE_ADMIN.
 */

$auth = AuthMiddleware::requireRole(ROLE_DELIVERY, ROLE_ADMIN);

try {
    // ── GET /api/delivery/quote ───────────────────────────────
    if ($method === 'GET' && $uri === '/api/delivery/quote') {
        $lat1 = floatval($_GET['pickup_lat'] ?? 0);
        $lon1 = floatval($_GET['pickup_lng'] ?? 0);
        $lat2 = floatval($_GET['drop_lat'] ?? 0);
        $lon2 = floatval($_GET['drop_lng'] ?? 0);

        if (!$lat1 || !$lon1 || !$lat2 || !$lon2) {
            Response::validation('Missing coordinates');
        }

        require_once dirname(dirname(__DIR__)) . '/core/helpers/DeliveryPricingEngine.php';
        $engine = new DeliveryPricingEngine($db);
        $quote = $engine->calculatePricing($lat1, $lon1, $lat2, $lon2);

        Response::success($quote);
    }

    // ── POST /api/delivery/dispatch ────────────────────────────
    if ($method === 'POST' && $uri === '/api/delivery/dispatch') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $orderId = (int) ($input['order_id'] ?? 0);

        if (!$orderId) Response::validation('Order ID required');

        // Logic to send to external Railway-based system
        $swiftKey = $_ENV['SWIFTDELIVER_SECRET'] ?? getenv('SWIFTDELIVER_SECRET');
        $swiftUrl = rtrim($_ENV['SWIFTDELIVER_URL'] ?? getenv('SWIFTDELIVER_URL'), '/');
        
        $db->prepare("UPDATE orders SET status = 'processing' WHERE id = ?")
           ->execute([$orderId]);

        // Check if delivery entry exists
        $stmt = $db->prepare("SELECT id FROM deliveries WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $delivery = $stmt->fetch();

        if (!$delivery) {
            $db->prepare("INSERT INTO deliveries (order_id, status, created_at) VALUES (?, 'pending', NOW())")
               ->execute([$orderId]);
            $deliveryId = $db->lastInsertId();
        } else {
            $deliveryId = $delivery['id'];
        }

        // Real external system API call
        $ch = curl_init($swiftUrl . '/api/dispatch');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$swiftKey}",
            "Content-Type: application/json"
        ]);
        
        $payload = json_encode([
            "order_id" => $orderId,
            "delivery_id" => $deliveryId
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            Logger::info('Order dispatched to external delivery system', ['order_id' => $orderId, 'delivery_id' => $deliveryId, 'response' => $response]);
            Response::success(['delivery_id' => $deliveryId], 200, 'Order dispatched to delivery network');
        } else {
            Logger::error('Failed to dispatch order to external delivery system', ['order_id' => $orderId, 'delivery_id' => $deliveryId, 'http_code' => $httpCode, 'response' => $response]);
            Response::error('Delivery network unavailable', 503);
        }
    }

    // ── PATCH /api/delivery/{id}/status ────────────────────────
    if ($method === 'PATCH' && preg_match('#^/api/delivery/(\d+)/status$#', $uri, $m)) {
        $deliveryId = (int) $m[1];
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        
        $status = $input['status'] ?? '';
        $allowed = ['picked', 'delivered', 'cancelled'];
        
        if (!in_array($status, $allowed)) Response::validation('Invalid status');

        $db->prepare("UPDATE deliveries SET status = ?, updated_at = NOW() WHERE id = ? AND driver_id = ?")
           ->execute([$status, $deliveryId, $auth['user_id']]);

        // Mirror to order
        $orderStatus = ($status === 'delivered') ? 'delivered' : 'shipped';
        $db->prepare("UPDATE orders SET status = ? WHERE delivery_id = ?")
           ->execute([$orderStatus, $deliveryId]);

        Response::success(null, 200, 'Delivery updated');
    }
} catch (PDOException $e) {
    Response::serverError();
}

Response::notFound('Delivery endpoint');
