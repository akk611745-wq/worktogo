<?php
/**
 * WebhookVerifier.php
 * Cashfree Webhook Signature Verification (HMAC-SHA256)
 *
 * Cashfree sends a `x-webhook-signature` header.
 * We recompute using: HMAC-SHA256(timestamp + raw_body, secret_key)
 * and compare with constant-time comparison to prevent timing attacks.
 *
 * Reference: https://docs.cashfree.com/docs/webhook-verification
 */

require_once __DIR__ . '/../config/payment.config.php';

class WebhookVerifier
{
    private string $secretKey;

    public function __construct()
    {
        $this->secretKey = CASHFREE_SECRET_KEY;
    }

    /**
     * Verifies the Cashfree webhook request.
     *
     * @param string $rawBody         Raw request body (do NOT decode before verifying)
     * @param string $signatureHeader Value of 'x-webhook-signature' header
     * @param string $timestampHeader Value of 'x-webhook-timestamp' header
     * @return bool
     */
    public function verify(string $rawBody, string $signatureHeader, string $timestampHeader): bool
    {
        if (empty($signatureHeader) || empty($timestampHeader) || empty($rawBody)) {
            error_log('[WebhookVerifier] Missing signature, timestamp, or body.');
            return false;
        }

        // ── Replay attack protection: reject webhooks older than 5 minutes ────
        $webhookTime = (int)$timestampHeader;
        $currentTime = time();
        if (abs($currentTime - $webhookTime) > 300) {
            error_log("[WebhookVerifier] Webhook timestamp too old: $webhookTime vs now $currentTime");
            return false;
        }

        // ── Recompute HMAC ────────────────────────────────────────────────────
        // Cashfree signs: timestamp + rawBody
        $dataToSign       = $timestampHeader . $rawBody;
        $computedSignature = base64_encode(
            hash_hmac('sha256', $dataToSign, $this->secretKey, true)
        );

        // ── Constant-time comparison ──────────────────────────────────────────
        if (!hash_equals($computedSignature, $signatureHeader)) {
            error_log('[WebhookVerifier] Signature mismatch. Possible tampering.');
            return false;
        }

        return true;
    }

    /**
     * Extracts and returns all required headers from the current request.
     *
     * @return array ['signature' => string, 'timestamp' => string]
     */
    public static function extractHeaders(): array
    {
        $headers = [];

        // Normalize header fetching (works on Apache + Nginx)
        if (function_exists('getallheaders')) {
            $allHeaders = getallheaders();
            foreach ($allHeaders as $name => $value) {
                $headers[strtolower($name)] = $value;
            }
        } else {
            foreach ($_SERVER as $key => $value) {
                if (strpos($key, 'HTTP_') === 0) {
                    $headerName = strtolower(str_replace('_', '-', substr($key, 5)));
                    $headers[$headerName] = $value;
                }
            }
        }

        return [
            'signature' => $headers['x-webhook-signature'] ?? '',
            'timestamp' => $headers['x-webhook-timestamp'] ?? '',
        ];
    }
}
