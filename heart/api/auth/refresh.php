<?php
// ============================================================
//  POST /api/auth/refresh
//  Issues a new access token using a valid refresh token.
// ============================================================

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if (empty($input['refresh_token'])) {
    Response::validation('refresh_token is required');
}

$refreshToken = trim($input['refresh_token']);

try {
    // Check if it's a JWT (legacy) or a hex token
    $userId = null;

    if (strpos($refreshToken, '.') !== false) {
        $payload = JWT::decode($refreshToken, JWT_SECRET);
        if (!$payload || ($payload['type'] ?? '') !== 'refresh') {
            Response::error('Invalid or expired refresh token', 401, 'INVALID_REFRESH_TOKEN');
        }
        $userId = (int) $payload['user_id'];

        $stmt = $db->prepare(
            "SELECT id FROM refresh_tokens
             WHERE user_id = ? AND token_hash = ? AND expires_at > NOW()
             LIMIT 1"
        );
        $stmt->execute([$userId, hash('sha256', $refreshToken)]);

        if (!$stmt->fetch()) {
            Response::error('Refresh token has been revoked or expired', 401, 'REFRESH_TOKEN_REVOKED');
        }
    } else {
        // Hex token
        $stmt = $db->prepare(
            "SELECT id, user_id FROM refresh_tokens
             WHERE token_hash = ? AND expires_at > NOW()
             LIMIT 1"
        );
        $stmt->execute([hash('sha256', $refreshToken)]);
        $rt = $stmt->fetch();

        if (!$rt) {
            Response::error('Refresh token has been revoked or expired', 401, 'REFRESH_TOKEN_REVOKED');
        }
        $userId = (int) $rt['user_id'];
    }

    // Load user
    $user = $db->prepare("SELECT id, phone, role, status FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1");
    $user->execute([$userId]);
    $user = $user->fetch();

    if (!$user || $user['status'] !== 'active') {
        Response::error('Account not found or suspended', 401, 'ACCOUNT_UNAVAILABLE');
    }

    // Issue new access token
    $newToken = JWT::encode([
        'user_id' => (int) $user['id'],
        'role'    => $user['role'],
        'phone'   => $user['phone'],
    ], JWT_SECRET, JWT_EXPIRY);

    Logger::info('Token refreshed', ['user_id' => $user['id']]);

    Response::success([
        'token'      => $newToken,
        'expires_in' => JWT_EXPIRY,
    ], 200, 'Token refreshed');

} catch (PDOException $e) {
    Logger::error('Refresh DB error', ['error' => $e->getMessage()]);
    Response::serverError('Token refresh failed');
}
