<?php
// ============================================================
//  GET /api/auth/notifications
//  Last 10 in-app notifications for the current authenticated user.
//  Generic (any role) — used by the vendor panel bell icon.
// ============================================================

require_once HEART_ROOT . '/middleware/AuthMiddleware.php';
$auth = AuthMiddleware::require();

try {
    $stmt = $db->prepare(
        "SELECT id, title, body, type, is_read, created_at
         FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10"
    );
    $stmt->execute([(int)$auth['user_id']]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $unreadStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $unreadStmt->execute([(int)$auth['user_id']]);

    Response::success(['notifications' => $notifications, 'unread_count' => (int)$unreadStmt->fetchColumn()]);
} catch (PDOException $e) {
    Logger::error('Failed to fetch notifications', ['error' => $e->getMessage()]);
    Response::serverError();
}
