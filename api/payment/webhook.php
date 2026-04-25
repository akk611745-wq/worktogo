<?php
/**
 * API Endpoint: POST /api/payment/webhook
 *
 * Receives and processes Cashfree payment webhooks.
 *
 * Security:
 *   - HMAC-SHA256 signature verified BEFORE any DB operation
 *   - Raw body captured BEFORE any parsing (required for correct HMAC)
 *   - Idempotent: duplicate webhook calls are safe
 *   - No auth cookie/session needed — uses Cashfree signature instead
 *
 * IMPORTANT: This endpoint must be publicly reachable (no auth middleware).
 * Add it to your router's public whitelist.
 */

// ── No session, no cookies, no CSRF needed here — webhook is server-to-server ─
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// ── Only accept POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['status' => 'error', 'message' => 'Method Not Allowed']));
}

// ── CRITICAL: Read raw body BEFORE any parsing ────────────────────────────────
// php://input is consumed once; capture immediately
$rawBody = file_get_contents('php://input');

if (empty($rawBody)) {
    http_response_code(400);
    exit(json_encode(['status' => 'error', 'message' => 'Empty webhook body']));
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../../lib/WebhookVerifier.php';
require_once __DIR__ . '/../../lib/PaymentService.php';

// ── Step 1: Extract headers ───────────────────────────────────────────────────
$headers = WebhookVerifier::extractHeaders();

// ── Step 2: Verify signature — REJECT immediately if invalid ─────────────────
$verifier = new WebhookVerifier();
if (!$verifier->verify($rawBody, $headers['signature'], $headers['timestamp'])) {
    error_log('[Webhook] Signature verification failed. IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    http_response_code(401);
    exit(json_encode(['status' => 'error', 'message' => 'Signature verification failed']));
}

// ── Step 3: Parse body ────────────────────────────────────────────────────────
$payload = json_decode($rawBody, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
    http_response_code(400);
    exit(json_encode(['status' => 'error', 'message' => 'Invalid JSON payload']));
}

// ── Step 4: Log webhook receipt (for audit trail) ─────────────────────────────
$cfOrderId = $payload['data']['order']['order_id'] ?? 'unknown';
error_log("[Webhook] Received for cf_order_id=$cfOrderId type=" . ($payload['type'] ?? 'unknown'));

// ── Step 5: Only process payment events ───────────────────────────────────────
$eventType = $payload['type'] ?? '';
$handledEvents = ['PAYMENT_SUCCESS_WEBHOOK', 'PAYMENT_FAILED_WEBHOOK', 'PAYMENT_USER_DROPPED_WEBHOOK'];

if (!in_array($eventType, $handledEvents, true)) {
    // Acknowledge non-payment events without processing
    http_response_code(200);
    exit(json_encode(['status' => 'ok', 'message' => 'Event type not handled: ' . $eventType]));
}

// ── Step 6: DB connection ─────────────────────────────────────────────────────
require_once __DIR__ . '/../../core/helpers/Database.php';
$db = Database::getConnection();

if (!isset($db) || !($db instanceof PDO)) {
    error_log('[Webhook] DB connection unavailable');
    // Return 500 so Cashfree will retry
    http_response_code(500);
    exit(json_encode(['status' => 'error', 'message' => 'Internal server error']));
}

// ── Step 7: Process webhook ───────────────────────────────────────────────────
$paymentService = new PaymentService($db);
$result = $paymentService->processWebhook($payload);

if (!$result['success']) {
    error_log("[Webhook] Processing failed for cf_order_id=$cfOrderId: " . $result['error']);
    // Return 200 anyway for known-bad events to prevent Cashfree retry loops
    // Log internally and investigate manually
    http_response_code(200);
    exit(json_encode(['status' => 'error', 'message' => $result['error']]));
}

// ── Step 8: Acknowledge success ───────────────────────────────────────────────
http_response_code(200);
exit(json_encode(['status' => 'ok', 'message' => $result['message']]));
