<?php
/**
 * CashfreeAdapter.php  —  WorkToGo Payment Abstraction Layer
 * ----------------------------------------------------------------------------
 * The Cashfree implementation of the PaymentGateway contract.
 *
 * This adapter is a thin, faithful wrapper around the EXISTING, battle-tested
 * Cashfree code — it does not re-implement anything:
 *   • CashfreeClient   — signed cURL client (SSL-verified, 30s timeout, no secret leak)
 *   • WebhookVerifier  — HMAC-SHA256 signature + 5-minute replay guard
 *   • the exact order payload + status mapping the production path already uses
 *
 * The only NEW behaviour is classifying a create-order failure as retryable
 * (network / 5xx / auth-config) vs terminal (payload/validation), so the
 * PaymentGatewayManager can fail over to a secondary gateway on infra outages
 * only — never on a legitimate rejection.
 */

require_once __DIR__ . '/PaymentGateway.php';
require_once __DIR__ . '/../CashfreeClient.php';
require_once __DIR__ . '/../WebhookVerifier.php';
require_once __DIR__ . '/../../config/payment.config.php';

class CashfreeAdapter implements PaymentGateway
{
    private CashfreeClient  $client;
    private WebhookVerifier $verifier;

    /** Cashfree webhook event types that carry a payment result. */
    private const PAYMENT_EVENTS = [
        'PAYMENT_SUCCESS_WEBHOOK',
        'PAYMENT_FAILED_WEBHOOK',
        'PAYMENT_USER_DROPPED_WEBHOOK',
    ];

    public function __construct()
    {
        // Reuse the production client/verifier untouched.
        $this->client   = new CashfreeClient();
        $this->verifier = new WebhookVerifier();
    }

    public function name(): string
    {
        return 'cashfree';
    }

    public function isConfigured(): bool
    {
        // Same guard the production paths use: real, non-placeholder credentials.
        $appId  = (string)(getenv('CASHFREE_APP_ID') ?: (defined('CASHFREE_APP_ID') ? CASHFREE_APP_ID : ''));
        $secret = (string)(getenv('CASHFREE_SECRET_KEY') ?: (defined('CASHFREE_SECRET_KEY') ? CASHFREE_SECRET_KEY : ''));
        if ($appId === '' || $secret === '') {
            return false;
        }
        if (strpos($appId, 'placeholder') !== false || strpos($appId, 'your_') !== false) {
            return false;
        }
        return true;
    }

    /**
     * Create a Cashfree order. Builds the SAME payload the production code builds.
     */
    public function createOrder(array $request): array
    {
        $orderRef = (string)($request['order_ref'] ?? '');
        $amount   = round((float)($request['amount'] ?? 0), 2);
        $customer = (array)($request['customer'] ?? []);

        $payload = [
            'order_id'       => $orderRef,
            'order_amount'   => $amount,
            'order_currency' => (string)($request['currency'] ?? 'INR'),
            'customer_details' => [
                'customer_id'    => (string)($customer['id'] ?? ''),
                'customer_name'  => $this->sanitizeName((string)($customer['name'] ?? 'WorkToGo Customer')),
                'customer_email' => (string)(filter_var((string)($customer['email'] ?? ''), FILTER_SANITIZE_EMAIL) ?: 'support@worktogo.com'),
                'customer_phone' => $this->normalizePhone((string)($customer['phone'] ?? '')),
            ],
            'order_meta' => [
                // Manager appends ?gateway=cashfree so the webhook knows the gateway
                // even before it can trust the body.
                'return_url' => (string)($request['return_url'] ?? ''),
                'notify_url' => (string)($request['notify_url'] ?? ''),
            ],
        ];
        if (!empty($request['tags']) && is_array($request['tags'])) {
            $payload['order_tags'] = array_map('strval', $request['tags']);
        }

        $res      = $this->client->post('/orders', $payload);
        $httpCode = (int)($res['http_code'] ?? 0);
        $data     = $res['data'] ?? null;

        if (!empty($res['success']) && !empty($data['payment_session_id'])) {
            return [
                'success'            => true,
                'gateway'            => $this->name(),
                'gateway_order_id'   => (string)($data['order_id'] ?? $orderRef),
                'payment_session_id' => $data['payment_session_id'] ?? null,
                'order_token'        => $data['order_token'] ?? null,
                'mode'               => $this->mode(),
                'http_code'          => $httpCode,
                'retryable'          => false,
                'error'              => null,
                'raw'                => $data,
            ];
        }

        // Failure. Decide whether a secondary gateway could do better.
        //   • 0    — network/DNS/TLS failure reaching Cashfree      → retryable
        //   • 5xx  — Cashfree server-side outage                    → retryable
        //   • 401/403 — our Cashfree creds are broken/blocked       → retryable (secondary may be fine)
        //   • 4xx (400/422/404/409) — payload/amount/validation     → terminal (same everywhere)
        //   • 2xx w/o session — gateway anomaly                     → terminal (avoid orphan orders)
        $retryable = ($httpCode === 0 || $httpCode >= 500 || $httpCode === 401 || $httpCode === 403);

        return [
            'success'            => false,
            'gateway'            => $this->name(),
            'gateway_order_id'   => null,
            'payment_session_id' => null,
            'order_token'        => null,
            'mode'               => $this->mode(),
            'http_code'          => $httpCode,
            'retryable'          => $retryable,
            'error'              => (string)($res['error'] ?? 'Cashfree order creation failed'),
            'raw'                => $data,
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        $signature = (string)($headers['x-webhook-signature'] ?? '');
        $timestamp = (string)($headers['x-webhook-timestamp'] ?? '');
        return $this->verifier->verify($rawBody, $signature, $timestamp);
    }

    public function parseWebhook(string $rawBody, array $decoded): array
    {
        $eventType = (string)($decoded['type'] ?? '');
        $isPayment = in_array($eventType, self::PAYMENT_EVENTS, true);

        $orderId       = $decoded['data']['order']['order_id']       ?? null;
        $orderStatus   = $decoded['data']['order']['order_status']   ?? null;
        $orderAmount   = $decoded['data']['order']['order_amount']   ?? null;
        $paymentStatus = $decoded['data']['payment']['payment_status'] ?? null;
        $cfPaymentId   = $decoded['data']['payment']['cf_payment_id']  ?? null;

        return [
            'gateway'          => $this->name(),
            'order_ref'        => $orderId !== null ? (string)$orderId : null,
            'gateway_txn_id'   => $cfPaymentId !== null ? (string)$cfPaymentId : null,
            'amount'           => $orderAmount !== null ? round((float)$orderAmount, 2) : null,
            'internal_status'  => $this->mapStatus($orderStatus, $paymentStatus),
            'event_type'       => $eventType !== '' ? $eventType : null,
            'is_payment_event' => $isPayment,
        ];
    }

    // ── Internals (moved verbatim from PaymentService, kept identical) ──────────

    /** Cashfree order/payment status → internal payment_status. */
    private function mapStatus(?string $orderStatus, ?string $paymentStatus): string
    {
        $status = strtoupper((string)($paymentStatus ?: $orderStatus));
        return match ($status) {
            'SUCCESS', 'PAID'   => 'paid',
            'FAILED', 'FAILURE' => 'failed',
            'USER_DROPPED'      => 'failed',
            'CANCELLED'         => 'failed',
            'FLAGGED'           => 'unpaid', // needs manual review
            default             => 'unpaid',
        };
    }

    private function mode(): string
    {
        $env = defined('CASHFREE_ENV') ? (string)CASHFREE_ENV : (string)(getenv('CASHFREE_ENV') ?: 'TEST');
        return strtoupper($env) === 'PRODUCTION' ? 'production' : 'sandbox';
    }

    private function sanitizeName(string $input): string
    {
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8') ?: 'WorkToGo Customer';
    }

    /** Cashfree expects a plain 10-digit Indian mobile; strip symbols/country code. */
    private function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/\D/', '', $raw);
        if (strlen($digits) > 10) {
            $digits = substr($digits, -10);
        }
        return strlen($digits) === 10 ? $digits : '9999999999';
    }
}
