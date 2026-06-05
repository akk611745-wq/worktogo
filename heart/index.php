<?php
/**
 * ================================================================
 *  WorkToGo â€” HEART SYSTEM v1.2.0
 *  Entry Point :: heart/index.php
 *
 *  PIPELINE:
 *  Request â†’ [HEART] â†’ Brain â†’ Body â†’ Response
 * ================================================================
 */

declare(strict_types=1);

// â”€â”€ 0. Global Error Handling â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
error_reporting(E_ALL);
ini_set('display_errors', '0');

set_exception_handler(function (\Throwable $e) {
    if (class_exists('Logger')) {
        Logger::error('Uncaught Exception', [
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'trace'   => $e->getTraceAsString(),
        ]);
    }
    
    // Check if we have DB access to store alert/log
    try {
        if (function_exists('getDB')) {
            $db = getDB();
            $db->prepare("INSERT INTO logs (level, message, context, created_at) VALUES ('CRITICAL', ?, ?, NOW())")
               ->execute(['Uncaught Exception: ' . $e->getMessage(), json_encode(['file' => $e->getFile(), 'line' => $e->getLine()])]);
            
            // Alert on system error
            $db->prepare("INSERT INTO alerts (type, title, message, ref_type) VALUES ('system', 'System Error', ?, 'none')")
               ->execute(['A critical system error occurred.']);
        }
    } catch (\Throwable $ignored) {}

    if (class_exists('Response')) {
        Response::serverError('An unexpected system error occurred.');
    } else {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Internal server error', 'error' => ['code' => 'SERVER_ERROR']]);
        exit;
    }
});

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

define('HEART_ROOT',  __DIR__);
define('SYSTEM_ROOT', dirname(__DIR__));
define('HEART_START', microtime(true));

// â”€â”€ 1. Load environment â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$envFile = SYSTEM_ROOT . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val, " \t\n\r\0\x0B\"'");
        if (!empty($key) && getenv($key) === false) {
            putenv("{$key}={$val}");
            $_ENV[$key] = $val;
        }
    }
}

// â”€â”€ 2. Load Centralized Configurations â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$appConfig = require_once __DIR__ . '/bootstrap.php';
$config = [
    'app'      => $appConfig,
    'db'       => require_once SYSTEM_ROOT . '/config/database.php',
    'engines'  => require_once SYSTEM_ROOT . '/config/engines.php',
];

// â”€â”€ 3. Load Shared Core Helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
require_once SYSTEM_ROOT . '/core/helpers/Logger.php';
require_once SYSTEM_ROOT . '/core/helpers/Validator.php';
require_once SYSTEM_ROOT . '/core/helpers/RateLimiter.php';

$db = getDB();
Logger::init();

// Define legacy constants for backward compatibility where needed
define('APP_ENV',         $config['app']['env']);
define('HEART_ENV',       $config['app']['env']);
define('HEART_VERSION',   $config['app']['version']);
define('HEART_ADMIN_KEY', $config['app']['admin_key']);
define('HEART_INTERNAL_KEY', $config['app']['internal_key']);
define('BRAIN_API_URL',   getenv('BRAIN_API_URL') ?: (getenv('APP_URL') . '/brain'));
define('BRAIN_API_KEY',   getenv('BRAIN_API_KEY') ?: '');
define('BRAIN_CLIENT_ID', getenv('BRAIN_CLIENT_ID') ?: 'heart-system');
define('BRAIN_CIRCUIT_THRESHOLD', (int)(getenv('BRAIN_CIRCUIT_THRESHOLD') ?: 5));
define('BRAIN_CIRCUIT_WINDOW',    (int)(getenv('BRAIN_CIRCUIT_WINDOW') ?: 60));
define('ENGINE_URLS',     $config['engines']);
define('DB_CONFIG',       $config['db']);
define('CORE_PATH',       SYSTEM_ROOT . '/core'); // Still needed for some includes

// Global Cache TTLs for ReflexEngine
define('CACHE_TTL', [
    'default' => 60,
    'search'  => 120,
    'list'    => 300,
    'status'  => 15,
    'track'   => 10,
]);

// â”€â”€ 4. Global Headers & Guards â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

header('Content-Type: application/json; charset=utf-8');

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 1_048_576) {   // 1 MB limit
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'Request body too large.', 'code' => 413]);
    exit;
}

// â”€â”€ 4. Standard REST Dispatcher (Non-Pipeline) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$allowedOrigin = $_ENV['APP_URL'] ?? '';
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($requestOrigin !== '' && $requestOrigin === $allowedOrigin) {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
} else {
    // Always emit the configured origin (never reflect arbitrary origins)
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept');
    http_response_code(200);
    exit;
}

$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

// Normalize URI: strip /heart prefix and ensure leading slash
if (str_starts_with($uri, '/heart')) {
    $uri = substr($uri, 6);
}
// Ensure leading slash after prefix stripping
if ($uri !== '/' && !str_starts_with($uri, '/')) {
    $uri = '/' . $uri;
}
// Remove trailing slash (except for root)
$uri = $uri === '/' ? '/' : rtrim($uri, '/');

// System / DevOps API
if ($uri === '/api/system/deploy') {
    require_once SYSTEM_ROOT . '/deploy-hook.php';
    exit;
}
if ($uri === '/api/system/migrate') {
    require_once SYSTEM_ROOT . '/migrate.php';
    exit;
}

// Health Check
if ($uri === '/api/health') {
    require_once HEART_ROOT . '/health.php';
    exit;
}

// Payment API
if (str_starts_with($uri, '/api/payment')) {
    $path = str_replace('/api/payment', '', $uri);
    $file = SYSTEM_ROOT . '/api/payment' . $path . '.php';
    if (file_exists($file)) {
        require_once $file;
        exit;
    }
}

// Auth API
if (str_starts_with($uri, '/api/auth')) {
    require_once HEART_ROOT . '/api/auth/router.php';
    exit;
}

// Admin API
if (str_starts_with($uri, '/api/admin')) {
    require_once HEART_ROOT . '/api/admin/index.php';
    exit;
}

// Delivery API
if (str_starts_with($uri, '/api/delivery')) {
    require_once HEART_ROOT . '/api/delivery/index.php';
    exit;
}

// Shopping Orders API
if (str_starts_with($uri, '/api/orders') || $uri === '/api/order/create' || str_starts_with($uri, '/api/vendor/orders')) {
    require_once SYSTEM_ROOT . '/body/shopping-engine/api/orders/index.php';
    exit;
}

// Shopping Products API
if (str_starts_with($uri, '/api/products') || str_starts_with($uri, '/api/product/') || $uri === '/api/vendor/products' || str_starts_with($uri, '/api/vendor/product/')) {
    require_once SYSTEM_ROOT . '/body/shopping-engine/api/products/index.php';
    exit;
}

// Shopping Cart API
if (str_starts_with($uri, '/api/cart')) {
    require_once SYSTEM_ROOT . '/body/shopping-engine/api/cart/index.php';
    exit;
}

// Services & Bookings API
if (str_starts_with($uri, '/api/services') || str_starts_with($uri, '/api/service/') || str_starts_with($uri, '/api/jobs/')) {
    require_once SYSTEM_ROOT . '/body/service-engine/api/services/index.php';
    exit;
}

// Service Vendors & Jobs API
if (str_starts_with($uri, '/api/vendors') || str_starts_with($uri, '/api/vendor/jobs')) {
    require_once SYSTEM_ROOT . '/body/service-engine/api/vendors/index.php';
    exit;
}

// Refund API (User & Admin)
if (str_starts_with($uri, '/api/user/refund') || str_starts_with($uri, '/api/admin/refund')) {
    require_once SYSTEM_ROOT . '/core/controllers/RefundController.php';
    
    // User refund routes
    if ($method === 'POST' && $uri === '/api/user/refund/request') {
        RefundController::requestRefund();
        exit;
    }
    
    if ($method === 'GET' && $uri === '/api/user/refunds') {
        RefundController::getMyRefunds();
        exit;
    }
    
    // Admin refund routes
    if ($method === 'POST' && $uri === '/api/admin/refund/approve') {
        RefundController::adminApproveRefund();
        exit;
    }
    
    if ($method === 'POST' && $uri === '/api/admin/refund/reject') {
        RefundController::adminRejectRefund();
        exit;
    }
    
    if ($method === 'GET' && $uri === '/api/admin/refunds') {
        RefundController::adminListRefunds();
        exit;
    }
    
    Response::notFound('Refund endpoint');
}

// Vendor Analytics API
if (str_starts_with($uri, '/api/vendor/analytics') || str_starts_with($uri, '/api/vendor/service-analytics')) {
    if (str_starts_with($uri, '/api/vendor/analytics')) {
        require_once SYSTEM_ROOT . '/body/shopping-engine/api/VendorAnalyticsController.php';
        $ctrl = new ShoppingVendorAnalyticsController();
        if ($method === 'GET' && $uri === '/api/vendor/analytics/stats') { $ctrl->getDashboardStats(); exit; }
        if ($method === 'GET' && $uri === '/api/vendor/analytics/revenue') { $ctrl->getRevenue(); exit; }
        if ($method === 'GET' && $uri === '/api/vendor/analytics/funnel') { $ctrl->getFunnel(); exit; }
        if ($method === 'GET' && $uri === '/api/vendor/analytics/top-products') { $ctrl->getTopProducts(); exit; }
    } else {
        require_once SYSTEM_ROOT . '/body/service-engine/api/VendorAnalyticsController.php';
        $ctrl = new ServiceVendorAnalyticsController();
        if ($method === 'GET' && $uri === '/api/vendor/service-analytics/stats') { $ctrl->getBookingStats(); exit; }
        if ($method === 'GET' && $uri === '/api/vendor/service-analytics/bookings') { $ctrl->getBookingChart(); exit; }
    }
}

// Vendor Availability API
if (str_starts_with($uri, '/api/vendor/availability') || str_starts_with($uri, '/api/booking/check-slot')) {
    require_once SYSTEM_ROOT . '/body/service-engine/api/SlotController.php';
    $slotCtrl = new SlotController();
    
    if ($method === 'POST' && $uri === '/api/vendor/availability') {
        $slotCtrl->setupAvailability();
        exit;
    }
    
    if ($method === 'GET' && preg_match('#^/api/vendor/availability/(\d+)$#', $uri, $matches)) {
        $slotCtrl->getVendorAvailability((int)$matches[1]);
        exit;
    }
    
    if ($method === 'POST' && $uri === '/api/booking/check-slot') {
        $slotCtrl->checkSlotAvailable();
        exit;
    }
}

// Feed API
if ($uri === '/api/feed' && $method === 'GET') {
    require_once HEART_ROOT . '/../brain/BrainCore.php';
    
    // Optional JWT
    $user_id = null;
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if (str_starts_with($authHeader, 'Bearer ')) {
        $token = substr($authHeader, 7);
        try {
            $decoded = JWT::decode($token, getenv('JWT_SECRET'));
            $user_id = (int)$decoded['user_id'];
        } catch (\Throwable $e) {}
    }

    $lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
    $lng = isset($_GET['lng']) ? (float)$_GET['lng'] : null;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;

    $brain = new BrainCore();
    $feed = $brain->buildUserFeed($user_id, $lat, $lng, $limit);
    
    Response::json([
        'success' => true,
        'data' => $feed
    ]);
    exit;
}

// Search API
if ($uri === '/api/search' && $method === 'GET') {
    require_once HEART_ROOT . '/api/search/index.php';
    exit;
}

// Public Settings API
if ($uri === '/api/settings' && $method === 'GET') {
    $fallbackPilotConfig = [
        'city' => 'Haldwani',
        'hero_title' => 'Book trusted local services in Haldwani',
        'hero_subtitle' => 'Browse local services first. Login only when you request or track a booking.',
        'trust_badges' => ['Local providers', 'Pay after service', 'Manual confirmation'],
        'support_label' => 'Need help?',
        'support_phone' => '+91 95285 44548',
        'whatsapp_url' => 'https://wa.me/919528544548?text=Hi%20WorkToGo%2C%20I%20need%20help%20with%20a%20service%20booking.',
        'featured_services_label' => 'Services near you',
        'fallback_title' => 'Need another service?',
        'fallback_text' => 'Tell us on WhatsApp. We are manually coordinating pilot requests in Haldwani.',
        'manual_fallback_label' => 'Manual assistance',
    ];
    $flat = ['pilot_public_config' => $fallbackPilotConfig];
    try {
        $stmt = $db->query("SELECT setting_key, setting_value, value_type FROM app_settings WHERE is_public = 1");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $val = $row['setting_value'];
            if ($row['value_type'] === 'number') $val = (float)$val;
            if ($row['value_type'] === 'boolean') $val = (bool)$val;
            if ($row['value_type'] === 'json') $val = json_decode((string)$val, true) ?: $val;
            $flat[$row['setting_key']] = $val;
        }
    } catch (Throwable $ignored) {}
    Response::success($flat);
    exit;
}

// Story & Content API
if (str_starts_with($uri, '/api/vendor/story') || str_starts_with($uri, '/api/feed/stories') || str_starts_with($uri, '/api/story/')) {
    require_once SYSTEM_ROOT . '/body/content-engine/api/StoryController.php';
    $storyCtrl = new StoryController();

    if ($method === 'POST' && $uri === '/api/vendor/story') {
        $storyCtrl->uploadStory();
        exit;
    }

    if ($method === 'GET' && $uri === '/api/feed/stories') {
        $storyCtrl->getFeedStories();
        exit;
    }

    if ($method === 'POST' && preg_match('#^/api/story/(\d+)/view$#', $uri, $matches)) {
        $storyCtrl->viewStory((int)$matches[1]);
        exit;
    }
}

// â”€â”€ 5. Heart Pipeline (POST only) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($method !== 'POST') {
    Response::error('Method not allowed. Use POST for pipeline or valid GET for API.', 405);
}

require_once HEART_ROOT . '/router.php';

