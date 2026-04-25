<?php
// ============================================================
//  WorkToGo CORE — Auth Middleware
//  Validates JWT from Authorization header.
//  Supports role-based access control (RBAC).
// ============================================================

class AuthMiddleware
{
    // ── Require valid JWT (any authenticated user) ────────────
    // Returns decoded payload array on success.
    // Calls Response::unauthorized() and exits on failure.
    public static function require(): array
    {
        $payload = self::resolveToken();

        if ($payload === null) {
            Logger::warning('Unauthorized access attempt', ['uri' => $_SERVER['REQUEST_URI'] ?? '']);
            Response::unauthorized('Valid authentication token required');
        }

        return $payload;
    }

    // ── Require JWT + specific role(s) ───────────────────────
    // Usage: AuthMiddleware::requireRole(ROLE_ADMIN)
    //        AuthMiddleware::requireRole(ROLE_VENDOR_SERVICE, ROLE_ADMIN)
    public static function requireRole(string ...$roles): array
    {
        $payload = self::require();

        // Admin bypass
        if (($payload['role'] ?? '') === ROLE_ADMIN) {
            return $payload;
        }

        if (!in_array($payload['role'] ?? '', $roles, true)) {
            Logger::warning('Forbidden: insufficient role', [
                'user_id'       => $payload['user_id'] ?? null,
                'required_role' => implode('|', $roles),
                'actual_role'   => $payload['role'] ?? 'none',
            ]);
            Response::forbidden('You do not have permission to access this resource');
        }

        return $payload;
    }

    // ── Optional auth (returns payload or null) ───────────────
    // Use for endpoints that work with or without authentication
    public static function optional(): ?array
    {
        return self::resolveToken();
    }

    // ── Extract and validate token ───────────────────────────
    private static function resolveToken(): ?array
    {
        $token = self::extractToken();

        if ($token === null) {
            return null;
        }

        // Decode and verify signature + expiry
        $payload = JWT::decode($token, JWT_SECRET);

        if ($payload === null) {
            return null;
        }

        // Require minimum required fields
        if (empty($payload['user_id']) || empty($payload['role'])) {
            return null;
        }

        // FIX 3: JWT blacklist enforcement — check if token was revoked on logout
        try {
            $db = getDB();
            $stmt = $db->prepare(
                "SELECT id FROM token_blacklist WHERE token_hash = ? LIMIT 1"
            );
            $stmt->execute([hash('sha256', $token)]);
            if ($stmt->fetch()) {
                http_response_code(401);
                echo json_encode(['error' => 'Token has been revoked. Please log in again.']);
                exit;
            }
        } catch (Exception $e) {
            // If blacklist check fails (e.g. DB down), log and deny — fail secure
            Logger::error('Blacklist check failed', ['error' => $e->getMessage()]);
            http_response_code(503);
            echo json_encode(['error' => 'Authentication service temporarily unavailable.']);
            exit;
        }

        return $payload;
    }

    // ── Extract Bearer token from Authorization header ────────
    private static function extractToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
                ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                ?? '';

        if ($header === '') {
            // Some hosts strip the header — check Apache fallback
            if (function_exists('apache_request_headers')) {
                $headers = apache_request_headers();
                $header  = $headers['Authorization'] ?? $headers['authorization'] ?? '';
            }
        }

        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));
        return $token !== '' ? $token : null;
    }
}
