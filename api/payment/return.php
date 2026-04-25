<?php
/**
 * Payment Return Handler
 * URL: /payment/return?order_id={id}
 *
 * Cashfree redirects the user here after completing payment on their hosted page.
 *
 * ⚠️  IMPORTANT:
 *     DO NOT trust return URL params for confirming payment.
 *     Payment status is authoritatively updated only via webhook.
 *     This page just shows a user-friendly status screen.
 *
 *     The actual payment_status in DB is set by webhook.php, not here.
 */

require_once __DIR__ . '/../../lib/PaymentService.php';
require_once __DIR__ . '/../../core/helpers/Database.php';

// ── Only allow GET ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// ── DB connection ─────────────────────────────────────────────────────────────
$db = Database::getConnection();

if (!isset($db) || !($db instanceof PDO)) {
    http_response_code(500);
    exit('Internal server error');
}

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

if ($orderId <= 0) {
    // Redirect to orders list if no valid order_id
    header('Location: /orders');
    exit;
}

// ── Fetch order status (webhook may have already updated it) ──────────────────
$stmt = $db->prepare(
    'SELECT id, payment_status, payment_method, total
     FROM orders WHERE id = :id LIMIT 1'
);
$stmt->execute([':id' => $orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header('Location: /orders');
    exit;
}

$paymentStatus = $order['payment_status'] ?? 'pending';

// ── Respond to client ─────────────────────────────────────────────────────────
// For API-first response (if your frontend is SPA/Flutter):
header('Content-Type: application/json');
echo json_encode([
    'status'         => 'ok',
    'order_id'       => $orderId,
    'payment_status' => $paymentStatus,
    'message'        => match($paymentStatus) {
        'paid'    => 'Payment successful! Your order is confirmed.',
        'failed'  => 'Payment failed. Please try again or choose a different method.',
        default   => 'Payment is being processed. You will be notified shortly.',
    },
]);

// ── For server-side rendered HTML, replace above with: ────────────────────────
// include BASE_PATH . '/views/payment/return.php';
