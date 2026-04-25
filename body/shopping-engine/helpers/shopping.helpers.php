<?php
// ─────────────────────────────────────────────────────────────
//  WorkToGo — Shopping Engine Helpers  (Production v3.0)
//  Shared utilities: response, auth, pagination, JSON body
// ─────────────────────────────────────────────────────────────

if (!function_exists('se_response')) {
    function se_response(bool $success, $data = null, ?string $error = null, int $httpCode = 200): void
    {
        if (defined('HEART_INTERNAL_INC')) {
            throw new InternalResponseException(json_encode([
                'success' => $success,
                'data'    => $data,
                'error'   => $error,
            ]));
        }

        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode([
            'success' => $success,
            'data'    => $data,
            'error'   => $error,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('se_ok')) {
    function se_ok($data = null): void
    {
        se_response(true, $data);
    }
}

if (!function_exists('se_fail')) {
    function se_fail(string $message, int $code = 400): void
    {
        se_response(false, null, $message, $code);
    }
}

if (!function_exists('se_paginate')) {
    function se_paginate(int $total, int $page, int $limit): array
    {
        return [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int)ceil($total / max($limit, 1)),
        ];
    }
}

if (!function_exists('se_json_body')) {
    function se_json_body(): array
    {
        if (defined('HEART_INTERNAL_INC')) {
            $decoded = json_decode($GLOBALS['HEART_PAYLOAD'] ?? '{}', true);
            return is_array($decoded['data'] ?? null) ? $decoded['data'] : (is_array($decoded) ? $decoded : []);
        }
        $raw = file_get_contents('php://input');
        if (!$raw) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('se_auth_user')) {
    /**
     * Resolves user_id from the JWT in the Authorization header.
     * Uses unified AuthMiddleware.
     */
    function se_auth_user(): ?int
    {
        // Try unified AuthMiddleware
        if (class_exists('AuthMiddleware')) {
            $payload = AuthMiddleware::optional();
            return isset($payload['user_id']) ? (int)$payload['user_id'] : null;
        }
        return null;
    }
}

if (!function_exists('se_require_auth')) {
    /**
     * Requires a valid JWT. Returns the authenticated user_id.
     * Terminates with HTTP 401 if not authenticated.
     */
    function se_require_auth(): int
    {
        $uid = se_auth_user();
        if (!$uid) {
            se_fail('Unauthorized', 401);
        }
        return $uid;
    }
}
