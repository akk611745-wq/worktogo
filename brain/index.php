<?php

/**
 * BrainCore v3 — Entry Point
 *
 * ─── BOOT ORDER ───────────────────────────────────────────────
 * 1. env.php       → reads .env, calls putenv() for all keys
 * 2. database.php  → defines getDB()
 * 3. logs/setup.php→ verifies /logs is writable
 * 4. autoload.php  → spl_autoload_register
 * 5. CORS headers  → must fire before any controller output
 * 6. routes.php    → dispatches
 *
 * ─── TIMEZONE NOTE ───────────────────────────────────────────
 * No timezone is hardcoded here. TimezoneResolver handles
 * per-request timezone resolution from user profile or ?tz= param.
 * UTC is only used as last resort for server-to-server calls.
 *
 * ─── CORS ────────────────────────────────────────────────────
 * Set ALLOWED_ORIGINS=https://yourapp.com in .env for production.
 * Multiple origins are comma-separated. * = open (dev only).
 */

// ── Error visibility ───────────────────────────────────────────
$appEnv = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'production';
if ($appEnv === 'development') {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(E_ALL);
}

header('Content-Type: application/json');

// ── Boot ───────────────────────────────────────────────────────
if (!defined('SYSTEM_ROOT')) {
    define('SYSTEM_ROOT', dirname(__DIR__));
}
require_once SYSTEM_ROOT . '/config/app.php';
require_once SYSTEM_ROOT . '/core/helpers/Database.php';
require_once SYSTEM_ROOT . '/core/helpers/Logger.php';
require_once __DIR__ . '/config/autoload.php';

$db = getDB();
Logger::init();


// ── CORS ───────────────────────────────────────────────────────
$origin         = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = array_filter(array_map('trim', explode(',', getenv('ALLOWED_ORIGINS') ?: '*')));
$isWildcard     = in_array('*', $allowedOrigins, true);

if ($isWildcard) {
    header('Access-Control-Allow-Origin: *');
} elseif ($origin && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Key, X-Admin-Key, X-Cron-Key');
header('Access-Control-Max-Age: 86400');

// Handle OPTIONS preflight immediately
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/routes.php';
