<?php
/**
 * ================================================================
 *  WorkToGo — HEART SYSTEM v1.2.0
 *  Pipeline Step 4 :: heart/engine_loader.php
 *
 *  Dispatches HTTP calls to one or more Engines in parallel.
 *  Engines are configured in .env only — never hardcoded here.
 *
 *  v1.2.0 changes:
 *  - curl_multi_select timeout reduced 0.5s → 0.05s (10× faster)
 *  - Internal error details removed from response (log only)
 *  - Engine response size capped at 2 MB before decode
 *  - Connect timeout reduced to 1s (was 2s)
 * ================================================================
 */

declare(strict_types=1);

class EngineLoader
{
    // Per-engine read timeouts (seconds)
    private static array $timeouts = [
        'shopping' => 5,
        'service'  => 5,
        'delivery' => 4,
        'video'    => 6,
        'default'  => 5,
    ];

    private const CONNECT_TIMEOUT_S  = 1;
    private const MAX_RESPONSE_BYTES = 2_097_152; // 2 MB per engine

    /**
     * Dispatch to one or more engines, collect results.
     *
     * @param  string[] $engines      Engine names from route config
     * @param  array    $context      Full request context
     * @param  array    $brainResult  Brain decision (may be empty)
     * @return array                  Keyed by engine name → response array
     */
    public static function dispatch(
        array $engines,
        array $context,
        array $brainResult
    ): array {
        if (empty($engines)) {
            return [];
        }

        $engineUrls = ENGINE_URLS;
        $active     = [];

        foreach ($engines as $name) {
            $url = $engineUrls[$name] ?? '';
            if (empty($url)) {
                HeartLogger::warn("Engine [{$name}] not configured — skipping", [
                    'request_id' => $context['request_id'],
                    'hint'       => 'Set ENGINE_' . strtoupper($name) . '_URL in .env',
                ]);
            } else {
                $active[$name] = $url;
            }
        }

        if (empty($active)) {
            return [];
        }

        return count($active) === 1
            ? [array_key_first($active) => self::callSingle(
                array_key_first($active),
                array_values($active)[0],
                $context, $brainResult
              )]
            : self::callParallel($active, $context, $brainResult);
    }

    /**
     * Internal engine call via include/require
     */
    private static function callInternal(
        string $name,
        string $path,
        array  $context,
        array  $brainResult
    ): array {
        if (!defined('HEART_INTERNAL_INC')) define('HEART_INTERNAL_INC', true);

        // Body engines often expect $method and $uri to be defined in their entry point
        // or they read from $_SERVER.
        $_SERVER['REQUEST_METHOD'] = 'POST';
        
        $payload = [
            'request_id'     => $context['request_id'],
            'intent'         => $context['intent'],
            'query'          => $context['query'],
            'filters'        => $context['filters'],
            'data'           => $context['data'],
            'user'           => $context['user'],
            'location'       => $context['location'],
            'time'           => $context['time'],
            'pagination'     => $context['pagination'],
            'brain_hints'    => $brainResult['engine_hints'][$name] ?? [],
            'brain_decision' => $brainResult['decision'],
        ];

        $GLOBALS['HEART_PAYLOAD'] = json_encode($payload);
        $_SERVER['HTTP_X_INTERNAL_KEY'] = HEART_INTERNAL_KEY;
        
        // Pass the Authorization header if present in the main request
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $fullPath = SYSTEM_ROOT . '/' . ltrim($path, '/');
        
        // Ensure the path points to the index.php if it's a directory
        if (is_dir($fullPath)) {
            $fullPath = rtrim($fullPath, '/') . '/index.php';
        }

        try {
            ob_start();
            require $fullPath;
            $raw = ob_get_clean();
            return self::parseResponse($name, $raw, 200, 0, $context);
        } catch (InternalResponseException $e) {
            ob_end_clean();
            $resp = json_decode($e->getMessage(), true) ?: [];
            return self::parseResponse($name, json_encode($resp['data'] ?? $resp), 200, 0, $context);
        } catch (\Throwable $e) {
            ob_end_clean();
            return self::parseResponse($name, false, 500, -1, $context);
        }
    }

    // ── Single cURL call ─────────────────────────────────────
    private static function callSingle(
        string $name,
        string $url,
        array  $context,
        array  $brainResult
    ): array {
        if (!str_starts_with($url, 'http')) {
            return self::callInternal($name, $url, $context, $brainResult);
        }
        $ch       = self::buildHandle($name, $url, $context, $brainResult);
        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno    = curl_errno($ch);
        curl_close($ch);

        return self::parseResponse($name, $raw, $httpCode, $errno, $context);
    }

    // ── Parallel cURL multi ──────────────────────────────────
    private static function callParallel(
        array $engineUrls,
        array $context,
        array $brainResult
    ): array {
        $results = [];
        $httpEngines = [];

        foreach ($engineUrls as $name => $url) {
            if (!str_starts_with($url, 'http')) {
                $results[$name] = self::callInternal($name, $url, $context, $brainResult);
            } else {
                $httpEngines[$name] = $url;
            }
        }

        if (empty($httpEngines)) {
            return $results;
        }

        $multi   = curl_multi_init();
        $handles = [];

        foreach ($httpEngines as $name => $url) {
            $ch = self::buildHandle($name, $url, $context, $brainResult);
            curl_multi_add_handle($multi, $ch);
            $handles[$name] = $ch;
        }

        // v1.2.0: select timeout 0.05s (was 0.5s) — 10× faster response collection
        $active = 0;
        do {
            $status = curl_multi_exec($multi, $active);
            if ($active) {
                curl_multi_select($multi, 0.05);
            }
        } while ($active > 0 && $status === CURLM_OK);

        foreach ($handles as $name => $ch) {
            $raw      = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno    = curl_errno($ch);

            $results[$name] = self::parseResponse($name, $raw, $httpCode, $errno, $context);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }

        curl_multi_close($multi);
        return $results;
    }

    // ── Build a cURL handle ──────────────────────────────────
    private static function buildHandle(
        string $name,
        string $url,
        array  $context,
        array  $brainResult
    ): \CurlHandle {
        $timeout = self::$timeouts[$name] ?? self::$timeouts['default'];

        $body = json_encode([
            'request_id'     => $context['request_id'],
            'intent'         => $context['intent'],
            'query'          => $context['query'],
            'filters'        => $context['filters'],
            'data'           => $context['data'],
            'user'           => $context['user'],
            'location'       => $context['location'],
            'time'           => $context['time'],
            'pagination'     => $context['pagination'],
            'brain_hints'    => $brainResult['engine_hints'][$name] ?? [],
            'brain_decision' => $brainResult['decision'],
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST            => true,
            CURLOPT_POSTFIELDS      => $body,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_CONNECTTIMEOUT  => self::CONNECT_TIMEOUT_S,
            CURLOPT_TIMEOUT         => $timeout,
            CURLOPT_MAXFILESIZE     => self::MAX_RESPONSE_BYTES,
            CURLOPT_HTTPHEADER      => [
                'Content-Type: application/json',
                'X-Heart-Version: ' . HEART_VERSION,
                'X-Request-ID: '    . $context['request_id'],
                'X-Internal-Key: '  . HEART_INTERNAL_KEY,
                'X-Engine-Name: '   . $name,
            ],
        ]);

        return $ch;
    }

    // ── Parse engine response ────────────────────────────────
    private static function parseResponse(
        string       $name,
        string|false $raw,
        int          $httpCode,
        int          $errno,
        array        $context
    ): array {
        $base = [
            'engine' => $name,
            'ok'     => false,
            'data'   => null,
            'error'  => null,   // generic only — internal details go to log
        ];

        if ($errno !== 0 || $raw === false) {
            // v1.2.0: internal cURL error code goes to log, NOT to response
            HeartLogger::warn("Engine [{$name}] cURL failed", [
                'request_id' => $context['request_id'],
                'errno'      => $errno,
            ]);
            $base['error'] = 'Engine temporarily unavailable.';
            return $base;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            HeartLogger::warn("Engine [{$name}] HTTP error", [
                'request_id' => $context['request_id'],
                'http'       => $httpCode,
            ]);
            $base['error'] = 'Engine returned an error. Please try again.';
            return $base;
        }

        // Guard response size
        if (strlen($raw) > self::MAX_RESPONSE_BYTES) {
            HeartLogger::warn("Engine [{$name}] response too large", [
                'request_id' => $context['request_id'],
                'bytes'      => strlen($raw),
            ]);
            $base['error'] = 'Engine response too large.';
            return $base;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            HeartLogger::warn("Engine [{$name}] non-JSON response", [
                'request_id' => $context['request_id'],
            ]);
            $base['error'] = 'Engine returned invalid data.';
            return $base;
        }

        HeartLogger::info("Engine [{$name}] responded ok", [
            'request_id' => $context['request_id'],
        ]);

        return array_merge($base, ['ok' => true, 'data' => $decoded]);
    }
}
