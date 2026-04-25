<?php
/**
 * GET /api/alerts/fetch.php
 * -------------------------------------------------------
 * The polling endpoint. Called by alerts.js on a timer.
 * -------------------------------------------------------
 */

declare(strict_types=1);
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache');
header('X-Content-Type-Options: nosniff');

require_once $_SERVER['DOCUMENT_ROOT'] . '/core/helpers/Database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/heart/middleware/AuthMiddleware.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/AlertEngine.php';

// ── Auth: resolve recipient from core system ─────────
try {
    $user = AuthMiddleware::require();
    $userId = (int) $user['user_id'];
    $role = $_GET['role'] ?? $user['role'] ?? 'user';
    
    $recipientId = $userId;

    // If role is vendor, we need the vendor_id instead of user_id
    if (str_contains($role, 'vendor')) {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT id FROM vendors WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $vendorId = $stmt->fetchColumn();
        if ($vendorId) {
            $recipientId = (int) $vendorId;
            $role = 'vendor'; // Normalize role for AlertEngine
        }
    }
} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// ── Parse inputs ────────────────────────────────────────────────
$lastTs     = isset($_GET['last_ts']) ? (string) $_GET['last_ts'] : null;
$unreadOnly = ($_GET['unread'] ?? '0') === '1';

// Basic sanity: if last_ts looks malformed, treat as cold start
if ($lastTs !== null && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $lastTs)) {
    $lastTs = null;
}

// ── Fetch ────────────────────────────────────────────────────────
try {
    $pdo = Database::getConnection();
    $engine = new AlertEngine($pdo);
    $result = $engine->fetchAlerts($recipientId, $role, $lastTs, $unreadOnly);

    echo json_encode([
        'success'      => true,
        'has_new'      => $result['has_new'],
        'unseen_count' => $result['unseen_count'],
        'server_ts'    => $result['server_ts'],
        'alerts'       => $result['alerts'],
    ]);
} catch (Throwable $e) {
    error_log('[AlertFetch] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
