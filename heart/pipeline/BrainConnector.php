<?php
/**
 * ================================================================
 *  WorkToGo — HEART SYSTEM v1.2.0
 *  Pipeline Step 3 :: heart/brain_connector.php
 *
 *  IMPORTANT: Brain is OPTIONAL.
 *  If BRAIN_API_URL is empty in .env, Brain is skipped silently.
 *  Heart pipeline continues normally with safe defaults.
 *
 *  v1.2.0 changes:
 *  - Blocking usleep() REMOVED — was causing worker starvation
 *  - Circuit breaker added (APCu-based)
 *    → After BRAIN_CIRCUIT_THRESHOLD consecutive failures,
 *      Brain is skipped for BRAIN_CIRCUIT_WINDOW seconds
 *    → No retries during open-circuit window
 *  - Auth failures (401/403) open circuit immediately
 *  - Brain response sanitised before passing downstream
 * ================================================================
 */

declare(strict_types=1);

class BrainConnector
{
    private const TIMEOUT_CONNECT_S = 2;
    private const TIMEOUT_READ_S    = 5;   // reduced from 8s

    // Circuit breaker APCu keys
    private const CB_FAIL_KEY  = 'heart_brain_cb_fails';
    private const CB_OPEN_KEY  = 'heart_brain_cb_open';

    /**
     * Call Brain API with context.
     *
     * Returns Brain decision array, OR safe defaults if:
     *   - BRAIN_API_URL is not set
     *   - Circuit is open (too many recent failures)
     *   - Brain is unreachable / errors
     *   - Route says brain = false
     */
    public static function call(array $context, array $route): array
    {
        // Brain not needed for this route
        if (empty($route['brain'])) {
            return self::skipped($context);
        }

        // Brain not configured
        $brainUrl = BRAIN_API_URL;
        if (empty($brainUrl)) {
            HeartLogger::info('Brain not configured — skipping', [
                'request_id' => $context['request_id'],
            ]);
            return self::notConfigured($context);
        }

        // Circuit breaker: skip Brain if circuit is open
        if (self::isCircuitOpen()) {
            HeartLogger::warn('Brain circuit open — skipping to protect pipeline', [
                'request_id' => $context['request_id'],
            ]);
            return self::fallback($context, 'circuit_open');
        }

        // Attempt ONE call (no blocking retry loop)
        try {
            $decision = self::httpCall($brainUrl, $context);
            self::recordSuccess();

            HeartLogger::info('Brain responded', ['request_id' => $context['request_id']]);
            return self::normalise($decision, $context);

        } catch (BrainTimeoutException $e) {
            $reason = 'timeout';
            HeartLogger::warn('Brain timeout', [
                'request_id' => $context['request_id'],
                'error'      => $e->getMessage(),
            ]);

        } catch (BrainAuthException $e) {
            // Auth failure: open circuit immediately (no point retrying)
            self::openCircuit(BRAIN_CIRCUIT_THRESHOLD);
            $reason = 'auth_failure';
            HeartLogger::error('Brain auth failure — circuit opened', [
                'request_id' => $context['request_id'],
                'error'      => $e->getMessage(),
            ]);

        } catch (BrainUnavailableException $e) {
            $reason = 'unavailable';
            HeartLogger::warn('Brain unavailable', [
                'request_id' => $context['request_id'],
                'error'      => $e->getMessage(),
            ]);

        } catch (\Throwable $e) {
            $reason = 'error';
            HeartLogger::error('Brain unexpected error', [
                'request_id' => $context['request_id'],
                'error'      => get_class($e) . ': ' . $e->getMessage(),
            ]);
        }

        self::recordFailure();

        HeartLogger::warn('Brain fallback — using safe defaults', [
            'request_id' => $context['request_id'],
            'reason'     => $reason ?? 'unknown',
        ]);

        return self::fallback($context, $reason ?? 'unknown');
    }

    // ── Circuit Breaker ──────────────────────────────────────

    private static function isCircuitOpen(): bool
    {
        if (!function_exists('apcu_fetch')) {
            return false; // No APCu — always attempt
        }
        return (bool)(apcu_fetch(self::CB_OPEN_KEY) ?: false);
    }

    private static function recordFailure(): void
    {
        if (!function_exists('apcu_add')) {
            return;
        }
        apcu_add(self::CB_FAIL_KEY, 0, BRAIN_CIRCUIT_WINDOW);
        $fails = (int)apcu_inc(self::CB_FAIL_KEY);
        if ($fails >= BRAIN_CIRCUIT_THRESHOLD) {
            self::openCircuit($fails);
        }
    }

    private static function openCircuit(int $fails): void
    {
        if (!function_exists('apcu_store')) {
            return;
        }
        apcu_store(self::CB_OPEN_KEY, true, BRAIN_CIRCUIT_WINDOW);
        HeartLogger::warn('Brain circuit breaker opened', [
            'consecutive_failures' => $fails,
            'window_seconds'       => BRAIN_CIRCUIT_WINDOW,
        ]);
    }

    private static function recordSuccess(): void
    {
        if (!function_exists('apcu_delete')) {
            return;
        }
        apcu_delete(self::CB_FAIL_KEY);
        apcu_delete(self::CB_OPEN_KEY);
    }

    // ── HTTP call ────────────────────────────────────────────

    /**
     * Internal include/require call to Brain
     */
    private static function internalCall(string $path, array $context): array
    {
        if (!defined('HEART_INTERNAL_INC')) define('HEART_INTERNAL_INC', true);
        
        $brainPath = SYSTEM_ROOT . '/' . ltrim($path, '/');
        
        // Mock the environment for Brain
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/decision'; // Standard Brain endpoint
        
        // Brain expects client_id in the payload
        $payload = [
            'client_id'  => BRAIN_CLIENT_ID,
            'request_id' => $context['request_id'],
            'intent'     => $context['intent'],
            'query'      => $context['query'],
            'filters'    => $context['filters'],
            'user'       => $context['user'],
            'location'   => $context['location'],
            'time'       => $context['time'],
            'data'       => $context['data'],
            'session'    => $context['session'],
            'channel'    => $context['channel'],
        ];

        $GLOBALS['HEART_PAYLOAD'] = json_encode($payload);
        $_SERVER['HTTP_X_API_KEY'] = BRAIN_API_KEY;

        // Pass the Authorization header if present
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        
        try {
            ob_start();
            require $brainPath . '/index.php';
            $output = ob_get_clean();
            return json_decode($output, true) ?: [];
        } catch (InternalResponseException $e) {
            ob_end_clean();
            $resp = json_decode($e->getMessage(), true) ?: [];
            return $resp['data'] ?? $resp; // Extract data from Response::success wrapper
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }

    private static function httpCall(string $url, array $context): array
    {
        // Check if $url is a local path
        if (!str_starts_with($url, 'http')) {
            return self::internalCall($url, $context);
        }

        // ... existing HTTP call logic ...
        $body = json_encode([
            'client_id'  => BRAIN_CLIENT_ID,   // registered Heart client
            'request_id' => $context['request_id'],
            'intent'     => $context['intent'],
            'query'      => $context['query'],
            'filters'    => $context['filters'],
            'user'       => $context['user'],
            'location'   => $context['location'],
            'time'       => $context['time'],
            'data'       => $context['data'],
            'session'    => $context['session'],
            'channel'    => $context['channel'],
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_CONNECT_S,
            CURLOPT_TIMEOUT        => self::TIMEOUT_READ_S,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Heart-Version: ' . HEART_VERSION,
                'X-Request-ID: '    . $context['request_id'],
                'X-Internal-Key: '  . HEART_INTERNAL_KEY,  // kept for tracing
                'X-Api-Key: '       . BRAIN_API_KEY,        // FIX 4: Brain auth header
            ],
        ]);

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($errno === CURLE_OPERATION_TIMEOUTED) {
            throw new BrainTimeoutException("Brain timed out after " . self::TIMEOUT_READ_S . "s");
        }
        if ($errno !== 0) {
            throw new BrainUnavailableException("cURL error {$errno}: {$error}");
        }
        if ($httpCode === 401 || $httpCode === 403) {
            throw new BrainAuthException("Brain auth failed: HTTP {$httpCode}");
        }
        if ($httpCode >= 500) {
            throw new BrainUnavailableException("Brain returned HTTP {$httpCode}");
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new BrainUnavailableException("Brain returned unexpected HTTP {$httpCode}");
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Brain returned non-JSON response");
        }

        return $decoded;
    }

    // ── Response shapes ──────────────────────────────────────

    private static function normalise(array $raw, array $context): array
    {
        return [
            'source'       => 'brain',
            'intent'       => $context['intent'],
            'decision'     => is_array($raw['decision'] ?? null) ? $raw['decision'] : null,
            'score'        => is_numeric($raw['score'] ?? null) ? (float)$raw['score'] : null,
            'confidence'   => min(1.0, max(0.0, (float)($raw['confidence'] ?? 1.0))),
            'reasoning'    => is_string($raw['reasoning'] ?? null) ? $raw['reasoning'] : null,
            'engine_hints' => is_array($raw['engine_hints'] ?? null) ? $raw['engine_hints'] : [],
            'metadata'     => is_array($raw['metadata'] ?? null) ? $raw['metadata'] : [],
            'fallback'     => false,
        ];
    }

    private static function fallback(array $context, string $reason): array
    {
        return [
            'source'       => 'fallback',
            'intent'       => $context['intent'],
            'decision'     => self::safeDefaults($context['intent']),
            'score'        => null,
            'confidence'   => 0.0,
            'reasoning'    => 'Brain temporarily unavailable — safe defaults applied.',
            'engine_hints' => [],
            'metadata'     => ['fallback_reason' => $reason],
            'fallback'     => true,
        ];
    }

    private static function notConfigured(array $context): array
    {
        return [
            'source'       => 'not_configured',
            'intent'       => $context['intent'],
            'decision'     => self::safeDefaults($context['intent']),
            'score'        => null,
            'confidence'   => 0.0,
            'reasoning'    => 'Brain not connected. Set BRAIN_API_URL in .env to enable.',
            'engine_hints' => [],
            'metadata'     => [],
            'fallback'     => false,
        ];
    }

    private static function skipped(array $context): array
    {
        return [
            'source'       => 'skipped',
            'intent'       => $context['intent'],
            'decision'     => null,
            'score'        => null,
            'confidence'   => null,
            'reasoning'    => 'Brain not required for this intent.',
            'engine_hints' => [],
            'metadata'     => [],
            'fallback'     => false,
        ];
    }

    private static function safeDefaults(string $intent): array
    {
        return match(true) {
            str_contains($intent, 'search')   => ['sort' => 'popular', 'limit' => 20],
            str_contains($intent, 'checkout') => ['allow' => true, 'flags' => []],
            str_contains($intent, 'book')     => ['allow' => true, 'slots' => []],
            str_contains($intent, 'track')    => ['refresh_interval' => 30],
            str_contains($intent, 'list')     => ['sort' => 'default', 'limit' => 20],
            default                           => ['status' => 'ok'],
        };
    }
}

class BrainTimeoutException     extends \RuntimeException {}
class BrainUnavailableException extends \RuntimeException {}
class BrainAuthException        extends \RuntimeException {}
