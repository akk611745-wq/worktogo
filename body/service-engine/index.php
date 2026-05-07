<?php
/**
 * WorkToGo — Service Engine Unified Entry Point
 *
 * Mirrors the shopping-engine dispatch pattern.
 * Handles internal Heart intent-based calls only.
 *
 * Supported intents:
 *   service:list_services         → list active services (optionally filtered by category)
 *   service:view_service          → view a single service by ID
 *   service:create_booking        → create a booking + auto-linked job
 *   service:vendor_bookings       → list bookings scoped by role (vendor/admin/user)
 *   service:vendor_update_booking → update job status and mirror onto booking
 */

require_once dirname(dirname(__DIR__)) . '/core/helpers/Database.php';
require_once dirname(dirname(__DIR__)) . '/core/helpers/Response.php';
require_once dirname(dirname(__DIR__)) . '/core/helpers/JWT.php';
require_once dirname(dirname(__DIR__)) . '/heart/middleware/AuthMiddleware.php';

$db = getDB();

// ── Internal Heart Dispatch ────────────────────────────────────────────────────
if (defined('HEART_INTERNAL_INC')) {
    $payload = json_decode($GLOBALS['HEART_PAYLOAD'] ?? '{}', true);
    $intent  = $payload['intent'] ?? '';
    $data    = $payload['data']   ?? [];

    switch ($intent) {

        // ── List active services (optional ?category= filter) ──────────────────
        case 'service:list_services':
            $_SERVER['REQUEST_URI']    = '/api/services';
            $_SERVER['REQUEST_METHOD'] = 'GET';
            require_once __DIR__ . '/api/services/index.php';
            break;

        // ── View a single service by ID ────────────────────────────────────────
        case 'service:view_service':
            $_SERVER['REQUEST_URI']    = '/api/services/' . (int)($data['id'] ?? 0);
            $_SERVER['REQUEST_METHOD'] = 'GET';
            require_once __DIR__ . '/api/services/index.php';
            break;

        // ── Create a booking (+ auto-linked job) ───────────────────────────────
        case 'service:create_booking':
            $_SERVER['REQUEST_URI']    = '/api/service/request';
            $_SERVER['REQUEST_METHOD'] = 'POST';
            require_once __DIR__ . '/api/services/index.php';
            break;

        // ── List bookings scoped by role ───────────────────────────────────────
        case 'service:vendor_bookings':
            $_SERVER['REQUEST_URI']    = '/api/service/bookings';
            $_SERVER['REQUEST_METHOD'] = 'GET';
            require_once __DIR__ . '/api/services/index.php';
            break;

        // ── Update job status (mirrors onto booking) ───────────────────────────
        case 'service:vendor_update_booking':
            $_SERVER['REQUEST_URI']    = '/api/jobs/' . (int)($data['id'] ?? 0) . '/status';
            $_SERVER['REQUEST_METHOD'] = 'PATCH';
            require_once __DIR__ . '/api/services/index.php';
            break;

        default:
            Response::success([
                'items'   => [],
                'total'   => 0,
                'message' => 'Service engine active.',
            ]);
    }
    exit;
}

// ── Fallback: direct HTTP access forbidden ─────────────────────────────────────
http_response_code(403);
echo json_encode(['error' => 'Direct access forbidden']);
exit;
