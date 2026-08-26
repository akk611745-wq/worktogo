<?php
/**
 * RazorpayAdapter.php  —  WorkToGo Payment Abstraction Layer
 * ============================================================================
 *  ██  STUB / SLOT — PENDING RAZORPAY CREDENTIALS  ██
 * ============================================================================
 * This is the drop-in slot for Razorpay as a secondary (failover) gateway.
 *
 * It is a COMPLETE, SAFE stub — it implements the full PaymentGateway contract
 * and will never mis-fire, because every money path is gated behind
 * isConfigured(). Until real Razorpay keys are present AND Razorpay is enabled
 * in config/payment.gateways.php, this adapter reports "not configured", so:
 *   • the manager NEVER routes or fails over to it, and
 *   • the webhook NEVER accepts a Razorpay event.
 *
 * ── To go live with Razorpay (no system changes needed): ────────────────────
 *   1. Add to .env:  RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET, RAZORPAY_WEBHOOK_SECRET
 *      (optionally RAZORPAY_ENV = production|test).
 *   2. In config/payment.gateways.php set gateways.razorpay.enabled = true and
 *      point an engine's 'secondary' (or 'primary') to 'razorpay'.
 *   3. Register the webhook URL  <APP_URL>/api/payment/webhook?gateway=razorpay
 *      in the Razorpay dashboard.
 *   4. VALIDATE on Razorpay TEST mode first — the create-order + webhook shapes
 *      below follow Razorpay's documented Orders API v1, but have NOT been run
 *      against a live account yet. Confirm one sandbox payment end-to-end.
 *
 * NOTE (frontend): Razorpay Checkout is initialised client-side with
 * {key_id, order_id} — it does NOT use Cashfree's payment_session_id. The
 * create-order result therefore returns razorpay_order_id + key_id in 'raw';
 * wiring the client SDK switch (Cashfree vs Razorpay) is a separate frontend
 * task and is intentionally out of scope for this backend layer.
 * ============================================================================
 */

require_once __DIR__ . '/PaymentGateway.php';

class RazorpayAdapter implements PaymentGateway
{
    /** Razorpay webhook events that carry a payment result → internal status. */
    private const EVENT_MAP = [
        'payment.captured'  => 'paid',
        'order.paid'        => 'paid',
        'payment.failed'    => 'failed',
    ];

    public function name(): string
    {
        return 'razorpay';
    }

    public function isConfigured(): bool
    {
        $keyId  = (string)(getenv('RAZORPAY_KEY_ID') ?: '');
        $secret = (string)(getenv('RAZORPAY_KEY_SECRET') ?: '');
        if ($keyId === '' || $secret === '') {
            return false;
        }
        if (strpos($keyId, 'placeholder') !== false || strpos($keyId, 'your_') !== false) {
            return false;
        }
        return true;
    }

    /**
     * Create a Razorpay order (Orders API v1). Guarded: returns a terminal
     * "not configured" failure until real keys exist — so failover never lands
     * here prematurely and no double-charge is possible.
     */
    public function createOrder(array $request): array
    {
        if (!$this->isConfigured()) {
            // Terminal (retryable=false): a missing secondary is not an outage to
            // retry — it simply means there is no failover target yet.
            return $this->terminalFailure('Razorpay is not configured (stub).');
        }

        // ── Real Orders API call — PENDING SANDBOX VALIDATION ──────────────────
        $keyId    = (string)getenv('RAZORPAY_KEY_ID');
        $secret   = (string)getenv('RAZORPAY_KEY_SECRET');
        $baseUrl  = 'https://api.razorpay.com/v1';
        $orderRef = (string)($request['order_ref'] ?? '');
        // Razorpay amounts are in the smallest currency unit (paise).
        $amountPaise = (int)round(((float)($request['amount'] ?? 0)) * 100);

        $payload = json_encode([
            'amount'          => $amountPaise,
            'currency'        => (string)($request['currency'] ?? 'INR'),
            'receipt'         => $orderRef,               // our reference, echoed back on webhooks
            'payment_capture' => 1,                       // auto-capture on success
            'notes'           => (array)($request['tags'] ?? []),
        ]);

        $ch = curl_init($baseUrl . '/orders');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_USERPWD        => $keyId . ':' . $secret, // HTTP Basic auth
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log("[RazorpayAdapter] cURL error creating order: $curlErr");
            return $this->infraFailure('Network error communicating with Razorpay.', 0);
        }

        $data = json_decode((string)$response, true);

        if ($httpCode >= 200 && $httpCode < 300 && !empty($data['id'])) {
            return [
                'success'            => true,
                'gateway'            => $this->name(),
                'gateway_order_id'   => (string)$data['id'],   // razorpay order_id — correlates webhook
                'payment_session_id' => null,                  // Razorpay uses key_id+order_id, not a session id
                'order_token'        => null,
                'mode'               => strtolower((string)(getenv('RAZORPAY_ENV') ?: 'production')) === 'test' ? 'sandbox' : 'production',
                'http_code'          => $httpCode,
                'retryable'          => false,
                'error'              => null,
                'raw'                => ['razorpay_order' => $data, 'key_id' => $keyId],
            ];
        }

        $errMsg    = $data['error']['description'] ?? 'Razorpay order creation failed';
        $retryable = ($httpCode === 0 || $httpCode >= 500);
        error_log("[RazorpayAdapter] order creation failed [$httpCode]: $errMsg");
        return [
            'success'            => false,
            'gateway'            => $this->name(),
            'gateway_order_id'   => null,
            'payment_session_id' => null,
            'order_token'        => null,
            'mode'               => 'production',
            'http_code'          => $httpCode,
            'retryable'          => $retryable,
            'error'              => (string)$errMsg,
            'raw'                => $data,
        ];
    }

    /**
     * Verify a Razorpay webhook: HMAC-SHA256(rawBody, webhook_secret), hex,
     * compared constant-time against the x-razorpay-signature header.
     */
    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        $secret = (string)(getenv('RAZORPAY_WEBHOOK_SECRET') ?: '');
        if ($secret === '' || $rawBody === '') {
            error_log('[RazorpayAdapter] Missing webhook secret or body.');
            return false;
        }
        $signature = (string)($headers['x-razorpay-signature'] ?? '');
        if ($signature === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $signature);
    }

    public function parseWebhook(string $rawBody, array $decoded): array
    {
        $event    = (string)($decoded['event'] ?? '');
        $entity   = $decoded['payload']['payment']['entity'] ?? [];
        $orderEnt = $decoded['payload']['order']['entity'] ?? [];

        // Prefer the receipt (our order_ref) if present; else the razorpay order_id.
        $orderRef = $entity['receipt']
            ?? $orderEnt['receipt']
            ?? ($entity['order_id'] ?? ($orderEnt['id'] ?? null));

        $amountPaise = $entity['amount'] ?? $orderEnt['amount'] ?? null;

        return [
            'gateway'          => $this->name(),
            'order_ref'        => $orderRef !== null ? (string)$orderRef : null,
            'gateway_txn_id'   => isset($entity['id']) ? (string)$entity['id'] : null,
            'amount'           => $amountPaise !== null ? round(((float)$amountPaise) / 100, 2) : null,
            'internal_status'  => self::EVENT_MAP[$event] ?? 'unpaid',
            'event_type'       => $event !== '' ? $event : null,
            'is_payment_event' => array_key_exists($event, self::EVENT_MAP),
        ];
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function terminalFailure(string $msg): array
    {
        return $this->failure($msg, false, 0);
    }

    private function infraFailure(string $msg, int $httpCode): array
    {
        return $this->failure($msg, true, $httpCode);
    }

    private function failure(string $msg, bool $retryable, int $httpCode): array
    {
        return [
            'success'            => false,
            'gateway'            => $this->name(),
            'gateway_order_id'   => null,
            'payment_session_id' => null,
            'order_token'        => null,
            'mode'               => 'production',
            'http_code'          => $httpCode,
            'retryable'          => $retryable,
            'error'              => $msg,
            'raw'                => null,
        ];
    }
}
