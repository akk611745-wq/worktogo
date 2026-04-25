<?php
/**
 * CashfreeClient.php
 * Low-level HTTP wrapper for Cashfree PG API v2023-08-01
 * 
 * Responsibilities:
 *   - Sign every request with App ID + Secret Key headers
 *   - Send cURL requests
 *   - Return decoded response arrays
 *   - Log errors without leaking secrets
 */

require_once __DIR__ . '/../config/payment.config.php';

class CashfreeClient
{
    private string $appId;
    private string $secretKey;
    private string $apiBase;
    private string $apiVersion;

    public function __construct()
    {
        $this->appId      = CASHFREE_APP_ID;
        $this->secretKey  = CASHFREE_SECRET_KEY;
        $this->apiBase    = CASHFREE_API_BASE;
        $this->apiVersion = CASHFREE_API_VERSION;
    }

    /**
     * POST request to Cashfree API
     *
     * @param string $endpoint  e.g. '/orders'
     * @param array  $payload   Associative array (will be JSON-encoded)
     * @return array ['success' => bool, 'data' => array|null, 'error' => string|null, 'http_code' => int]
     */
    public function post(string $endpoint, array $payload): array
    {
        return $this->request('POST', $endpoint, $payload);
    }

    /**
     * GET request to Cashfree API
     *
     * @param string $endpoint  e.g. '/orders/{order_id}'
     * @return array
     */
    public function get(string $endpoint): array
    {
        return $this->request('GET', $endpoint);
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    private function request(string $method, string $endpoint, array $payload = []): array
    {
        $url = $this->apiBase . $endpoint;

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'x-api-version: ' . $this->apiVersion,
            'x-client-id: '   . $this->appId,
            'x-client-secret: ' . $this->secretKey,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log("[CashfreeClient] cURL error on $method $endpoint: $curlErr");
            return [
                'success'   => false,
                'data'      => null,
                'error'     => 'Network error communicating with payment gateway.',
                'http_code' => 0,
            ];
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success'   => true,
                'data'      => $decoded,
                'error'     => null,
                'http_code' => $httpCode,
            ];
        }

        // Log detailed error server-side only
        $errMsg = $decoded['message'] ?? $decoded['error'] ?? 'Unknown Cashfree error';
        error_log("[CashfreeClient] API error [$httpCode] on $method $endpoint: $errMsg");

        return [
            'success'   => false,
            'data'      => $decoded,
            'error'     => $errMsg,
            'http_code' => $httpCode,
        ];
    }
}
