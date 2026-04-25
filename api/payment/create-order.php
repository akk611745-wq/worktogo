<?php
/**
 * API Endpoint: POST /api/payment/create-order
 *
 * Accepts an order_id + payment_method (online | cod)
 * For online: returns payment_session_id + cashfree_order_id for frontend SDK
 * For COD:    auto-confirms the order
 *
 * ─── Request Body (JSON) ────────────────────────────────────────────────────
 * {
 *   "order_id":       123,
 *   "payment_method": "online" | "cod"
 * }
 *
 * ─── Authentication ──────────────────────────────────────────────────────────
 * Plug in your existing auth middleware/session check at the marked location.
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// ── Only allow POST ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['status' => 'error', 'message' => 'Method Not Allowed']));
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../../lib/PaymentService.php';
require_once __DIR__ . '/../../heart/middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../core/helpers/Database.php';

// ── Authentication ──────────────────────────────────────────────────────────
$currentUser = AuthMiddleware::require();

if (!isset($currentUser) || empty($currentUser['id'])) {
    http_response_code(401);
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized']));
}

// ── Parse JSON body ───────────────────────────────────────────────────────────
$rawBody = file_get_contents('php://input');
$input   = json_decode($rawBody, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    die(json_encode(['status' => 'error', 'message' => 'Invalid JSON body']));
}

// ── Input validation ──────────────────────────────────────────────────────────
$orderId       = isset($input['order_id'])       ? (int)$input['order_id']            : 0;
$paymentMethod = isset($input['payment_method']) ? strtolower(trim($input['payment_method'])) : '';

if ($orderId <= 0) {
    http_response_code(422);
    die(json_encode(['status' => 'error', 'message' => 'Invalid order_id']));
}

if (!in_array($paymentMethod, ['online', 'cod'], true)) {
    http_response_code(422);
    die(json_encode(['status' => 'error', 'message' => 'payment_method must be "online" or "cod"']));
}

// ── DB connection ─────────────────────────────────────────────────────────────
$db = Database::getConnection();

if (!isset($db) || !($db instanceof PDO)) {
    http_response_code(500);
    error_log('[create-order] DB connection not available');
    die(json_encode(['status' => 'error', 'message' => 'Internal server error']));
}

// ── Process ───────────────────────────────────────────────────────────────────
$paymentService = new PaymentService($db);

if ($paymentMethod === 'cod') {
    $result = $paymentService->confirmCODOrder($orderId);

    if (!$result['success']) {
        http_response_code(400);
        die(json_encode(['status' => 'error', 'message' => $result['error']]));
    }

    http_response_code(200);
    die(json_encode([
        'status'         => 'success',
        'payment_method' => 'cod',
        'order_id'       => $orderId,
        'message'        => 'Order confirmed for Cash on Delivery.',
    ]));
}

// ── Online payment ────────────────────────────────────────────────────────────

// Amount is fetched server-side from DB — NOT from frontend
// We only use $input to get order_id and payment_method

// Fetch the order's verified amount from DB for passing to service
$stmt = $db->prepare('SELECT total FROM orders WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $orderId]);
$orderRow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$orderRow) {
    http_response_code(404);
    die(json_encode(['status' => 'error', 'message' => 'Order not found']));
}

$amount = (float)$orderRow['total'];

$result = $paymentService->createOnlinePaymentOrder(
    $orderId,
    $amount,
    [
        'id'    => $currentUser['id'],
        'name'  => $currentUser['name']  ?? 'Customer',
        'email' => $currentUser['email'] ?? '',
        'phone' => $currentUser['phone'] ?? '9999999999',
    ]
);

if (!$result['success']) {
    http_response_code(400);
    die(json_encode(['status' => 'error', 'message' => $result['error']]));
}

http_response_code(200);
echo json_encode([
    'status'             => 'success',
    'payment_method'     => 'online',
    'order_id'           => $orderId,
    'cashfree_order_id'  => $result['cashfree_order_id'],
    'payment_session_id' => $result['payment_session_id'],
    'order_token'        => $result['order_token'],
    'cashfree_env'       => $result['cashfree_env'],
]);
