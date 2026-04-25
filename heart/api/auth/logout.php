<?php
// ============================================================
//  POST /api/auth/logout
//  Invalidates the current access token and clears refresh tokens.
// ============================================================

$auth = AuthMiddleware::require();

// Extract raw token from header for blacklisting
$header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$token  = trim(str_replace('Bearer ', '', $header));

try {
    if ($token) {
        $expiresAt = JWT::expiresAt($token);

        $db->prepare(
            "INSERT INTO token_blacklist (token_hash, expires_at, created_at)
             VALUES (?, FROM_UNIXTIME(?), NOW())
             ON DUPLICATE KEY UPDATE expires_at = FROM_UNIXTIME(?)"
        )->execute([hash('sha256', $token), $expiresAt, $expiresAt]);
    }

    // Revoke all refresh tokens for this user
    $db->prepare("DELETE FROM refresh_tokens WHERE user_id = ?")->execute([(int) $auth['user_id']]);

    Logger::info('User logged out', ['user_id' => $auth['user_id']]);
    Response::success(null, 200, 'Logged out successfully');

} catch (PDOException $e) {
    Logger::error('Logout DB error', ['error' => $e->getMessage()]);
    // Still return success — client should clear token regardless
    Response::success(null, 200, 'Logged out');
}
