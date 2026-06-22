<?php
// ============================================================
//  POST /api/auth/device/fcm
//  Saves the authenticated user's FCM browser push token.
// ============================================================

require_once HEART_ROOT . '/middleware/AuthMiddleware.php';
require_once SYSTEM_ROOT . '/core/lib/PushSubscription.php';

$auth = AuthMiddleware::require();

$input      = json_decode(file_get_contents('php://input'), true) ?? [];
$token      = trim((string)($input['token'] ?? ''));
$deviceType = in_array($input['device_type'] ?? '', ['web', 'android', 'ios'], true) ? $input['device_type'] : 'web';

if ($token === '') {
    Response::validation('token is required');
}

try {
    PushSubscription::save($db, (int)$auth['user_id'], $token, $deviceType);
    Response::success(['saved' => true]);
} catch (PDOException $e) {
    Logger::error('Failed to save FCM token', ['error' => $e->getMessage()]);
    Response::serverError();
}
