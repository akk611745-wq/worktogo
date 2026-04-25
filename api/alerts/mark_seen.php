<?php
/**
 * POST /api/alerts/mark_seen.php
 * -------------------------------------------------------
 * Mark one, many, or ALL alerts as seen for the session user.
 * -------------------------------------------------------
 */

declare(strict_types=1);
header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once $_SERVER['DOCUMENT_ROOT'] . '/core/helpers/Database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/heart/middleware/AuthMiddleware.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/AlertEngine.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

// ── Auth ──────────────────────────────────────────────────────────
try {
    $user = AuthMiddleware::require();
    $userId = (int) $user['user_id'];
    
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $role = $body['role'] ?? $user['role'] ?? 'user';

    $recipientId = $userId;

    // If role is vendor, we need the vendor_id instead of user_id
    if (str_contains($role, 'vendor')) {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT id FROM vendors WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $vendorId = $stmt->fetchColumn();
        if ($vendorId) {
            $recipientId = (int) $vendorId;
            $role = 'vendor'; // Normalize
        }
    }
} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if (!in_array($role, ['user', 'vendor'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid role']);
    exit;
}

// ── Parse IDs ────────────────────────────────────────────────────
$alertIds = array_map('intval', $body['alert_ids'] ?? []);

// ── Update ───────────────────────────────────────────────────────
try {
    $pdo = Database::getConnection();
    $engine  = new AlertEngine($pdo);
    $updated = $engine->markSeen($recipientId, $role, $alertIds);

    $col         = ($role === 'vendor') ? 'vendor_id' : 'user_id';
    $unseenCount = $engine->getUnseenCount($recipientId, $col);

    echo json_encode([
        'success'      => true,
        'updated'      => $updated,
        'unseen_count' => $unseenCount,
    ]);
} catch (Throwable $e) {
    error_log('[MarkSeen] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
