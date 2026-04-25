<?php
// ============================================================
//  GET /api/auth/me
//  Returns the currently authenticated user's profile.
// ============================================================

$auth = AuthMiddleware::require();

try {
    $stmt = $db->prepare(
        "SELECT id, uuid, name, phone, email, role, status, created_at, last_login_at
         FROM users
         WHERE id = ? AND deleted_at IS NULL
         LIMIT 1"
    );
    $stmt->execute([(int) $auth['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        Response::unauthorized('User account not found');
    }

    Response::success(['user' => $user]);

} catch (PDOException $e) {
    Logger::error('Me endpoint DB error', ['error' => $e->getMessage()]);
    Response::serverError();
}
