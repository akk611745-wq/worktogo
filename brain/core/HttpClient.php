<?php

/**
 * BrainCore - HttpClient
 *
 * Simple, safe HTTP POST utility for outbound integration calls.
 *
 * ─── DESIGN PRINCIPLES ───────────────────────────────────────
 * 1. Never throws exceptions — always returns a result array
 * 2. Respects timeout from IntegrationConfig
 * 3. Sends JSON body, accepts JSON response
 * 4. Supports optional Bearer token auth
 * 5. Caller always gets status + response + error message
 *
 * ─── WHY NOT GUZZLE / CURL LIBRARY? ─────────────────────────
 * BrainCore runs on standard shared hosting (Hostinger).
 * No Composer. No external packages.
 * PHP's built-in curl extension is always available.
 * ────────────────────────────────────────────────────────────
 */

class HttpClient
{
    /**
     * POST JSON to a URL.
     *
     * @param  string      $url      Full URL to POST to
     * @param  array       $body     Data to send as JSON
     * @param  string|null $token    Optional Bearer token
     * @param  int         $timeout  Seconds before giving up
     * @return array {
     *   success:     bool
     *   http_status: int|null
     *   response:    array|null   (decoded JSON response body)
     *   error:       string|null  (error message if failed)
     * }
     */
    public static function post(
        string  $url,
        array   $body,
        ?string $token   = null,
        int     $timeout = 5
    ): array {
        // ── Validate URL ───────────────────────────────────────
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return self::fail("Invalid URL: {$url}");
        }

        // ── Build headers ──────────────────────────────────────
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: BrainCore/1.0',
        ];

        if ($token !== null) {
            $headers[] = "Authorization: Bearer {$token}";
        }

        // ── cURL setup ─────────────────────────────────────────
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 3),
            // SSL: verify in production, allow self-signed in dev
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        // ── Execute ────────────────────────────────────────────
        $raw        = curl_exec($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        // ── Handle cURL failure ────────────────────────────────
        if ($raw === false) {
            return self::fail(
                "cURL error calling {$url}: {$curlError}",
                $httpStatus
            );
        }

        // ── Decode response ────────────────────────────────────
        $decoded = json_decode($raw, true);

        // ── Check HTTP status ──────────────────────────────────
        $success = ($httpStatus >= 200 && $httpStatus < 300);

        return [
            'success'     => $success,
            'http_status' => $httpStatus,
            'response'    => $decoded,
            'error'       => $success ? null : "HTTP {$httpStatus} from {$url}",
        ];
    }

    // ──────────────────────────────────────────────────────────
    // PRIVATE
    // ──────────────────────────────────────────────────────────

    private static function fail(string $message, ?int $httpStatus = null): array
    {
        return [
            'success'     => false,
            'http_status' => $httpStatus,
            'response'    => null,
            'error'       => $message,
        ];
    }
}
