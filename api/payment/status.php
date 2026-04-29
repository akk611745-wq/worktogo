<?php
/**
 * API Endpoint: GET /api/payment/status.php?order_id=123
 *
 * Checks the current payment status of an order for frontend polling.
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../core/helpers/Database.php';
require_once __DIR__ . '/../../heart/middleware/AuthMiddleware.php';

$currentUser = AuthMiddleware::require();

if (!isset($currentUser) || empty($currentUser['user_id'])) {
    http_response_code(401);
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized']));
}

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

if ($orderId <= 0) {
    http_response_code(400);
    die(json_encode(['status' => 'error', 'message' => 'Invalid order_id']));
}

$db = Database::getConnection();

if (!$db) {
    http_response_code(500);
    die(json_encode(['status' => 'error', 'message' => 'Database connection failed']));
}

$stmt = $db->prepare('SELECT payment_status FROM orders WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $orderId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    die(json_encode(['status' => 'error', 'message' => 'Order not found']));
}

// Map internal 'unpaid' to requirement's 'pending'
$status = $row['payment_status'];
if ($status === 'unpaid') {
    $status = 'pending';
}

echo json_encode([
    'payment_status' => $status
]);
