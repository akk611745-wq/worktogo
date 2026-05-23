<?php
/**
 * API Endpoint: GET /api/payment/status.php?order_id=123 or ?booking_id=123
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
$bookingId = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;

if ($orderId <= 0 && $bookingId <= 0) {
    http_response_code(400);
    die(json_encode(['status' => 'error', 'message' => 'Invalid order_id or booking_id']));
}

$db = Database::getConnection();

if (!$db) {
    http_response_code(500);
    die(json_encode(['status' => 'error', 'message' => 'Database connection failed']));
}

$referenceType = $bookingId > 0 ? 'booking' : 'order';
$referenceId = $bookingId > 0 ? $bookingId : $orderId;
$table = $referenceType === 'booking' ? 'bookings' : 'orders';

$stmt = $db->prepare("SELECT payment_status, user_id FROM {$table} WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $referenceId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    die(json_encode(['status' => 'error', 'message' => ucfirst($referenceType) . ' not found']));
}

if ((string)$row['user_id'] !== (string)$currentUser['user_id']) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Forbidden: You do not own this order']));
}

// Map internal 'unpaid' to requirement's 'pending'
$status = $row['payment_status'];
if ($status === 'unpaid') {
    $status = 'pending';
}

echo json_encode([
    'reference_type' => $referenceType,
    'reference_id' => $referenceId,
    'payment_status' => $status
]);
