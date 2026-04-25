<?php
// ============================================================
//  WorkToGo CORE — Response Helper
//  Single source of truth for all JSON responses.
//  Every method exits after sending — no double-response risk.
// ============================================================

class Response
{
    // ── Success ──────────────────────────────────────────────
    public static function success(mixed $data = null, int $code = 200, string $message = 'OK'): never
    {
        self::send($code, [
            'success' => true,
            'message' => $message,
            'data'    => $data,
            'error'   => null,
        ]);
    }

    // ── JSON (Raw) ───────────────────────────────────────────
    public static function json(array $body, int $code = 200): never
    {
        self::send($code, $body);
    }

    // ── Created ──────────────────────────────────────────────
    public static function created(mixed $data = null, string $message = 'Created'): never
    {
        self::success($data, 201, $message);
    }

    // ── Generic Error ────────────────────────────────────────
    public static function error(string $message, int $code = 400, string $errorCode = 'ERROR', mixed $details = null): never
    {
        self::send($code, [
            'success' => false,
            'message' => $message,
            'data'    => null,
            'error'   => [
                'code'    => $errorCode,
                'message' => $message,
                'details' => $details,
            ],
        ]);
    }

    // ── 401 Unauthorized ─────────────────────────────────────
    public static function unauthorized(string $message = 'Authentication required'): never
    {
        self::error($message, 401, 'UNAUTHORIZED');
    }

    // ── 403 Forbidden ────────────────────────────────────────
    public static function forbidden(string $message = 'Access denied'): never
    {
        self::error($message, 403, 'FORBIDDEN');
    }

    // ── 404 Not Found ────────────────────────────────────────
    public static function notFound(string $resource = 'Resource'): never
    {
        self::error("{$resource} not found", 404, 'NOT_FOUND');
    }

    // ── 422 Validation ───────────────────────────────────────
    public static function validation(string $message, mixed $fields = null): never
    {
        self::error($message, 422, 'VALIDATION_ERROR', $fields);
    }

    // ── 429 Rate Limited ─────────────────────────────────────
    public static function tooManyRequests(string $message = 'Too many requests. Please slow down.'): never
    {
        self::error($message, 429, 'RATE_LIMITED');
    }

    // ── 500 Server Error ─────────────────────────────────────
    public static function serverError(string $message = 'Internal server error'): never
    {
        self::error($message, 500, 'SERVER_ERROR');
    }

    // ── 503 Service Unavailable ──────────────────────────────
    public static function unavailable(string $message = 'Service temporarily unavailable'): never
    {
        self::error($message, 503, 'SERVICE_UNAVAILABLE');
    }

    // ── Raw Send ─────────────────────────────────────────────
    private static function send(int $code, array $body): never
    {
        if (defined('HEART_INTERNAL_INC') && HEART_INTERNAL_INC === true) {
            throw new InternalResponseException(json_encode($body));
        }

        // Headers already sent guard
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Request-ID: ' . self::requestId());
        }
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ── Request ID (for tracing) ─────────────────────────────
    private static function requestId(): string
    {
        static $id = null;
        if ($id === null) {
            $id = substr(bin2hex(random_bytes(8)), 0, 16);
        }
        return $id;
    }
}

/**
 * Exception used to intercept internal calls without exiting
 */
class InternalResponseException extends Exception {}
