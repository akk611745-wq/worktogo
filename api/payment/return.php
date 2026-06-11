<?php
/**
 * Payment Return Handler
 * URL: /api/payment/return?order_id={id}
 *      /api/payment/return?booking_id={id}
 *
 * Cashfree redirects the user here after completing payment.
 *
 * ⚠️  DO NOT trust return URL params for confirming payment.
 *     Payment status is set authoritatively by webhook.php only.
 *     This page shows a status screen and lets the SPA poll to confirm.
 */

require_once __DIR__ . '/../../lib/PaymentService.php';
require_once __DIR__ . '/../../core/helpers/Database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$db = Database::getConnection();

if (!isset($db) || !($db instanceof PDO)) {
    http_response_code(500);
    exit('Internal server error');
}

// ── Resolve ID from query string ──────────────────────────────
// Accept ?order_id= (orders + bookings fallback) or ?booking_id= (bookings direct)
$rawOrderId   = isset($_GET['order_id'])   ? (int)$_GET['order_id']   : 0;
$rawBookingId = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;

if ($rawOrderId <= 0 && $rawBookingId <= 0) {
    header('Location: /orders');
    exit;
}

// ── Lookup: orders table first, then bookings ─────────────────
$record        = null;
$referenceType = 'order';
$referenceId   = 0;

if ($rawOrderId > 0) {
    $stmt = $db->prepare(
        'SELECT id, payment_status, payment_method, total
         FROM orders WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $rawOrderId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($record) {
        $referenceType = 'order';
        $referenceId   = $rawOrderId;
    } else {
        // Not in orders — try bookings (inspection payments land here)
        $stmt = $db->prepare(
            'SELECT id, payment_status, payment_method, total
             FROM bookings WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $rawOrderId]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($record) {
            $referenceType = 'booking';
            $referenceId   = $rawOrderId;
        }
    }
}

// ?booking_id= direct lookup (no orders check needed)
if (!$record && $rawBookingId > 0) {
    $stmt = $db->prepare(
        'SELECT id, payment_status, payment_method, total
         FROM bookings WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $rawBookingId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($record) {
        $referenceType = 'booking';
        $referenceId   = $rawBookingId;
    }
}

if (!$record) {
    header('Location: /orders');
    exit;
}

// ── Respond ───────────────────────────────────────────────────
$paymentStatus = $record['payment_status'] ?? 'pending';

header('Content-Type: application/json');
echo json_encode([
    'status'         => 'ok',
    'reference_type' => $referenceType,
    'order_id'       => $referenceType === 'order'   ? $referenceId : null,
    'booking_id'     => $referenceType === 'booking' ? $referenceId : null,
    'payment_status' => $paymentStatus,
    'message'        => match($paymentStatus) {
        'paid'    => 'Payment successful! Your booking is confirmed.',
        'failed'  => 'Payment failed. Please try again or choose a different method.',
        default   => 'Payment is being processed. You will be notified shortly.',
    },
]);
