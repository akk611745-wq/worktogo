<?php
/**
 * API Endpoint: POST /api/payment/webhook[?gateway=cashfree|razorpay]
 *
 * Receives and processes payment webhooks from ANY gateway via the payment
 * abstraction layer.
 *
 * Security:
 *   - The gateway is taken from the ?gateway query param (default 'cashfree'
 *     for backward compatibility — Cashfree's existing notify_url has no param).
 *     It comes from the URL we registered with the gateway, NOT the body, so it
 *     is trustworthy before the body is verified.
 *   - The matching adapter verifies the signature/HMAC on the RAW body BEFORE
 *     any parse or DB write; a bad signature is rejected 401.
 *   - Idempotent: duplicate webhook calls are safe.
 *   - No auth cookie/session — server-to-server, authenticated by signature.
 *   - PaymentService additionally rejects a webhook whose gateway does not match
 *     the gateway stored on the order (a Razorpay event cannot settle a Cashfree
 *     order, and vice-versa).
 *
 * IMPORTANT: this endpoint must be publicly reachable (no auth middleware).
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// ── Only accept POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['status' => 'error', 'message' => 'Method Not Allowed']));
}

// ── CRITICAL: Read raw body BEFORE any parsing (php://input is consumed once) ──
$rawBody = file_get_contents('php://input');

if (empty($rawBody)) {
    http_response_code(400);
    exit(json_encode(['status' => 'error', 'message' => 'Empty webhook body']));
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../../lib/PaymentService.php';
require_once __DIR__ . '/../../lib/payment/PaymentGatewayManager.php';
require_once __DIR__ . '/../../lib/WebhookVerifier.php';

// ── Step 1: Determine which gateway sent this (from the URL, not the body) ─────
$gatewayName = strtolower(trim((string)($_GET['gateway'] ?? 'cashfree')));

$manager = new PaymentGatewayManager();
$adapter = $manager->adapter($gatewayName);

if ($adapter === null) {
    error_log("[Webhook] Unknown gateway '$gatewayName'. IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    http_response_code(400);
    exit(json_encode(['status' => 'error', 'message' => 'Unknown payment gateway']));
}

// ── Step 2: Extract headers (lower-cased) ─────────────────────────────────────
$headers = [];
if (function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        $headers[strtolower($name)] = $value;
    }
} else {
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
        }
    }
}

// ── Step 3: Verify signature — REJECT immediately if invalid ──────────────────
if (!$adapter->verifyWebhook($rawBody, $headers)) {
    error_log("[Webhook] Signature verification failed (gateway=$gatewayName). IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    http_response_code(401);
    exit(json_encode(['status' => 'error', 'message' => 'Signature verification failed']));
}

// ── Step 4: Parse body ────────────────────────────────────────────────────────
$payload = json_decode($rawBody, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
    http_response_code(400);
    exit(json_encode(['status' => 'error', 'message' => 'Invalid JSON payload']));
}

// ── Step 5: Normalise into a gateway-agnostic event ───────────────────────────
$event = $adapter->parseWebhook($rawBody, $payload);

error_log('[Webhook] Received gateway=' . $gatewayName
    . ' order_ref=' . (string)($event['order_ref'] ?? 'unknown')
    . ' type=' . (string)($event['event_type'] ?? 'unknown'));

// ── Step 6: Acknowledge non-payment events without processing ─────────────────
if (empty($event['is_payment_event'])) {
    http_response_code(200);
    exit(json_encode(['status' => 'ok', 'message' => 'Event type not handled']));
}

// ── Step 7: DB connection ─────────────────────────────────────────────────────
require_once __DIR__ . '/../../core/helpers/Database.php';
$db = Database::getConnection();

if (!isset($db) || !($db instanceof PDO)) {
    error_log('[Webhook] DB connection unavailable');
    http_response_code(500); // 500 ⇒ gateway will retry
    exit(json_encode(['status' => 'error', 'message' => 'Internal server error']));
}

// ── Step 8: Process the normalised event (idempotent, gateway-aware) ──────────
$paymentService = new PaymentService($db, $manager);
$result = $paymentService->processWebhookEvent($event);

if (!$result['success']) {
    error_log('[Webhook] Processing failed (gateway=' . $gatewayName . ') for order_ref='
        . (string)($event['order_ref'] ?? 'unknown') . ': ' . $result['error']);
    // Return 200 for known-bad events to avoid gateway retry storms; investigate via logs.
    http_response_code(200);
    exit(json_encode(['status' => 'error', 'message' => $result['error']]));
}

// ── Step 9: Acknowledge success ───────────────────────────────────────────────
http_response_code(200);
exit(json_encode(['status' => 'ok', 'message' => $result['message']]));
