<?php
/**
 * POST /api/alerts/create.php
 * -------------------------------------------------------
 * INTERNAL endpoint — called by your Heart Pipeline when
 * events occur (order placed, payment captured, etc.).
 * -------------------------------------------------------
 */

declare(strict_types=1);
header('Content-Type: application/json');

require_once $_SERVER['DOCUMENT_ROOT'] . '/core/helpers/Database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/AlertEngine.php';

// ── Internal secret guard ────────────────────────────────────────
if (($_SERVER['HTTP_X_INTERNAL_KEY'] ?? '') !== getenv('ALERT_INTERNAL_SECRET')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ── Parse body ───────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    exit;
}

// ── Create ───────────────────────────────────────────────────────
try {
    $pdo = Database::getConnection();
    $engine   = new AlertEngine($pdo);
    $alertId  = $engine->createAlert($body);

    if ($alertId === false) {
        echo json_encode(['success' => false, 'deduped' => true, 'error' => 'Duplicate or invalid']);
        exit;
    }

    echo json_encode(['success' => true, 'alert_id' => $alertId]);
} catch (Throwable $e) {
    error_log('[AlertCreate] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
