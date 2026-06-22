<?php
// ============================================================
//  POST /api/auth/notifications/read
//  Marks all of the current user's notifications as read.
//  Generic (any role) — used by the vendor panel bell icon.
// ============================================================

require_once HEART_ROOT . '/middleware/AuthMiddleware.php';
$auth = AuthMiddleware::require();

try {
    $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")
       ->execute([(int)$auth['user_id']]);
    Response::success(['marked_read' => true]);
} catch (PDOException $e) {
    Logger::error('Failed to mark notifications read', ['error' => $e->getMessage()]);
    Response::serverError();
}
