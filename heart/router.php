<?php
/**
 * ================================================================
 *  WorkToGo — HEART SYSTEM v1.2.0
 *  Pipeline Orchestrator :: heart/router.php
 *
 *  This file ONLY moves data. It contains no business logic.
 *
 *  PIPELINE (in order):
 *  1. Parse & validate request
 *  2. Build context (ContextBuilder)
 *  3. Reflex check  (ReflexEngine)     — may return early
 *  4. Brain call    (BrainConnector)   — OPTIONAL, circuit-broken
 *  5. Engine calls  (EngineLoader)     — parallel HTTP
 *  6. Priority      (PriorityResolver) — picks winning result
 *  7. Response      (ResponseBuilder)  — sends JSON back
 *
 *  v1.2.0 fixes applied here:
 *  - Content-Type enforcement (415 on non-JSON)
 *  - Rate limiter: IP + user dual layer
 *  - Debug mode gated behind X-Admin-Key header
 *  - AppRouter prefix matching is now opt-in only
 *  - CORS headers added
 * ================================================================
 */

declare(strict_types=1);

// ── Load all pipeline components ──────────────────────────────
require_once HEART_ROOT . '/pipeline/ContextBuilder.php';
require_once HEART_ROOT . '/pipeline/ReflexEngine.php';
require_once HEART_ROOT . '/pipeline/BrainConnector.php';
require_once HEART_ROOT . '/pipeline/EngineLoader.php';
require_once HEART_ROOT . '/pipeline/PriorityResolver.php';
require_once HEART_ROOT . '/pipeline/ResponseBuilder.php';

// ── Standard response headers ─────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Heart-Version: ' . HEART_VERSION);
header('X-Powered-By: WorkToGo-Heart');

// ── CORS headers — FIX 5: lock to env-configured origin, no wildcard ──
$allowedOrigin = getenv('APP_URL') ?: 'https://yourdomain.com';
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($requestOrigin !== '' && $requestOrigin === $allowedOrigin) {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
} else {
    // Always emit the configured origin (never reflect arbitrary origins)
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Internal-Key, X-Admin-Key, Authorization');
header('Access-Control-Max-Age: 86400');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Only POST is accepted ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed. Use POST.', 'code' => 405]);
    exit;
}

// ── Content-Type enforcement ──────────────────────────────────
$contentType = $_SERVER['HTTP_CONTENT_TYPE'] ?? $_SERVER['CONTENT_TYPE'] ?? '';
if (!str_contains(strtolower($contentType), 'application/json')) {
    http_response_code(415);
    echo json_encode(['ok' => false, 'error' => 'Content-Type must be application/json.', 'code' => 415]);
    exit;
}

// ── Global exception handler ──────────────────────────────────
set_exception_handler(function (Throwable $e) {
    HeartLogger::error('Unhandled exception', [
        'class'   => get_class($e),
        'message' => $e->getMessage(),
        'file'    => HEART_ENV === 'development' ? $e->getFile() : '[hidden]',
        'line'    => HEART_ENV === 'development' ? $e->getLine() : 0,
    ]);
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'Internal server error. Please try again.',
        'code'  => 500,
    ]);
    exit;
});

// ── STEP 0 · Parse raw input ──────────────────────────────────
$rawInput = file_get_contents('php://input');
if (empty($rawInput)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Empty request body.', 'code' => 400]);
    exit;
}

$payload = json_decode($rawInput, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON in request body.', 'code' => 400]);
    exit;
}

if (empty($payload['intent'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing required field: intent.', 'code' => 400]);
    exit;
}

// ── STEP 1 · Rate limit guard (IP + user) ────────────────────
$userId = is_string($payload['user_id'] ?? null) ? trim($payload['user_id']) : null;
HeartRateLimiter::check($userId);

// ── STEP 2 · Build context ────────────────────────────────────
$context = ContextBuilder::build($payload);

HeartLogger::info('Pipeline started', [
    'request_id' => $context['request_id'],
    'intent'     => $context['intent'],
    'user_id'    => $context['user']['id'] ?? 'anonymous',
    'app'        => $context['channel']['app'],
]);

// ── STEP 3 · Resolve route ────────────────────────────────────
$route = AppRouter::resolve($context['intent'], $context['channel']['app']);

if ($route === null) {
    http_response_code(422);
    echo json_encode([
        'ok'    => false,
        'error' => 'Unknown intent: ' . htmlspecialchars($context['intent'], ENT_QUOTES, 'UTF-8'),
        'code'  => 422,
    ]);
    exit;
}

// ── STEP 4 · Reflex check (fast cache — may return early) ─────
$reflexResult = ReflexEngine::check($context);

if ($reflexResult !== null && ($reflexResult['_cache_hit'] ?? false) === true) {
    HeartLogger::info('Reflex fast-return', ['request_id' => $context['request_id']]);
    ResponseBuilder::fromCache($reflexResult, $context)->send();
    exit;
}

// ── STEP 5 · Brain call (OPTIONAL — circuit-broken) ──────────
$brainResult = BrainConnector::call($context, $route);

// ── STEP 6 · Engine dispatch (parallel HTTP) ─────────────────
$engineResults = EngineLoader::dispatch($route['engines'], $context, $brainResult);

// ── STEP 7 · Priority resolution ─────────────────────────────
$resolved = PriorityResolver::resolve($reflexResult, $brainResult, $engineResults);

// ── STEP 8 · Build and send response ─────────────────────────
$debugMode = HeartDebug::isEnabled();
$response  = ResponseBuilder::build($context, $resolved, $brainResult, $engineResults, $debugMode);

// Store result in reflex cache for future requests
ReflexEngine::store($context, $route, $response);

HeartLogger::info('Pipeline complete', [
    'request_id'  => $context['request_id'],
    'winner'      => $resolved['winner_source'],
    'engines'     => array_keys($engineResults),
    'brain_used'  => $brainResult['source'] === 'brain',
    'duration_ms' => round((microtime(true) - HEART_START) * 1000, 2),
]);

$response->send();


// ════════════════════════════════════════════════════════════
//  APP ROUTER — maps intent + app_type → engine list
//  v1.2.0: prefix matching removed (was ambiguous & unsafe)
//  Add new apps/engines without touching pipeline code.
// ════════════════════════════════════════════════════════════
class AppRouter
{
    /**
     * Route registry.
     * Key: 'intent' OR 'app_type:intent' for app-specific overrides.
     *
     * engines   → which engines to call (matched to ENGINE_URLS in config)
     * brain     → call Brain for this intent? (false = skip always)
     * cache_ttl → seconds to cache response in Reflex (0 = no cache)
     */
    private static array $routes = [

        // ── SHOPPING APP ──────────────────────────────────────
        'shopping:search_products'     => ['engines' => ['shopping'],             'brain' => true,  'cache_ttl' => 120],
        'shopping:list_products'       => ['engines' => ['shopping'],             'brain' => false, 'cache_ttl' => 300],
        'shopping:list_orders'         => ['engines' => ['shopping'],             'brain' => false, 'cache_ttl' => 0],
        'shopping:view_product'        => ['engines' => ['shopping'],             'brain' => true,  'cache_ttl' => 180],
        'shopping:add_to_cart'         => ['engines' => ['shopping'],             'brain' => false, 'cache_ttl' => 0],
        'shopping:checkout'            => ['engines' => ['shopping', 'delivery'], 'brain' => true,  'cache_ttl' => 0],
        'shopping:create_order'        => ['engines' => ['shopping'],             'brain' => false, 'cache_ttl' => 0],
        'shopping:order_status'        => ['engines' => ['shopping', 'delivery'], 'brain' => false, 'cache_ttl' => 30],
        'shopping:get_recommendations' => ['engines' => ['shopping'],             'brain' => true,  'cache_ttl' => 90],
        'shopping:vendor_orders'       => ['engines' => ['shopping'],             'brain' => false, 'cache_ttl' => 0],
        'shopping:vendor_update_order' => ['engines' => ['shopping'],             'brain' => false, 'cache_ttl' => 0],

        // ── SERVICE APP ───────────────────────────────────────
        'service:book_service'         => ['engines' => ['service'],              'brain' => true,  'cache_ttl' => 0],
        'service:create_booking'       => ['engines' => ['service'],              'brain' => true,  'cache_ttl' => 0],
        'service:list_bookings'        => ['engines' => ['service'],              'brain' => false, 'cache_ttl' => 0],
        'service:list_services'        => ['engines' => ['service'],              'brain' => false, 'cache_ttl' => 300],
        'service:service_status'       => ['engines' => ['service'],              'brain' => false, 'cache_ttl' => 15],
        'service:get_recommendations'  => ['engines' => ['service'],              'brain' => true,  'cache_ttl' => 90],

        // ── VENDOR APP ────────────────────────────────────────
        'vendor:get_summary'           => ['engines' => ['shopping', 'service'],  'brain' => false, 'cache_ttl' => 0],
        'vendor:list_products'         => ['engines' => ['shopping'],             'brain' => false, 'cache_ttl' => 0],
        'vendor:create_product'        => ['engines' => ['shopping'],             'brain' => false, 'cache_ttl' => 0],
        'vendor:list_orders'           => ['engines' => ['shopping'],             'brain' => false, 'cache_ttl' => 0],
        'vendor:list_jobs'             => ['engines' => ['service'],              'brain' => false, 'cache_ttl' => 0],

        // ── DELIVERY APP ──────────────────────────────────────
        'delivery:track_delivery'      => ['engines' => ['delivery'],             'brain' => false, 'cache_ttl' => 10],
        'delivery:schedule_delivery'   => ['engines' => ['delivery'],             'brain' => true,  'cache_ttl' => 0],

        // ── VIDEO APP ─────────────────────────────────────────
        'video:stream_video'           => ['engines' => ['video'],                'brain' => false, 'cache_ttl' => 600],
        'video:search_video'           => ['engines' => ['video'],                'brain' => true,  'cache_ttl' => 180],

        // ── CROSS-APP (generic — any app_type) ───────────────
        'search_products'              => ['engines' => ['shopping'],             'brain' => true,  'cache_ttl' => 120],
        'add_to_cart'                  => ['engines' => ['shopping'],             'brain' => false, 'cache_ttl' => 0],
        'checkout'                     => ['engines' => ['shopping', 'delivery'], 'brain' => true,  'cache_ttl' => 0],
        'order_status'                 => ['engines' => ['shopping', 'delivery'], 'brain' => false, 'cache_ttl' => 30],
        'book_service'                 => ['engines' => ['service'],              'brain' => true,  'cache_ttl' => 0],
        'list_services'                => ['engines' => ['service'],              'brain' => false, 'cache_ttl' => 300],
        'track_delivery'               => ['engines' => ['delivery'],             'brain' => false, 'cache_ttl' => 10],
        'ask_brain'                    => ['engines' => [],                       'brain' => true,  'cache_ttl' => 60],
        'get_recommendations'          => ['engines' => ['shopping', 'service'],  'brain' => true,  'cache_ttl' => 90],
        'service_status'               => ['engines' => ['service'],              'brain' => false, 'cache_ttl' => 15],
        'schedule_delivery'            => ['engines' => ['delivery'],             'brain' => true,  'cache_ttl' => 0],
        'stream_video'                 => ['engines' => ['video'],                'brain' => false, 'cache_ttl' => 600],
        'search_video'                 => ['engines' => ['video'],                'brain' => true,  'cache_ttl' => 180],
    ];

    /**
     * Resolve intent → route config.
     * Tries app-specific route first, then generic.
     * NOTE: prefix matching removed in v1.2.0 (was ambiguous).
     */
    public static function resolve(string $intent, string $appType = 'unknown'): ?array
    {
        // 1. App-specific exact match: 'shopping:search_products'
        $appKey = $appType . ':' . $intent;
        if (isset(self::$routes[$appKey])) {
            return self::$routes[$appKey];
        }

        // 2. Generic exact match
        if (isset(self::$routes[$intent])) {
            return self::$routes[$intent];
        }

        return null;
    }

    public static function all(): array
    {
        return self::$routes;
    }
}


// ════════════════════════════════════════════════════════════
//  RATE LIMITER — dual layer: IP + user-level
//  APCu token bucket, degrades gracefully if APCu missing.
// ════════════════════════════════════════════════════════════
class HeartRateLimiter
{
    // IP-level limits
    private const IP_MAX    = 200;
    private const IP_WINDOW = 60;

    // User-level limits (authenticated users only)
    private const USER_MAX    = 300;
    private const USER_WINDOW = 60;

    public static function check(?string $userId): void
    {
        if (!function_exists('apcu_add')) {
            return; // APCu unavailable — degrade gracefully
        }

        $ip = self::clientIp();

        // ── IP-level check (atomic increment) ─────────────────
        // apcu_add() only sets the key if it does not exist yet,
        // preserving the TTL window. apcu_inc() is atomic — safe
        // under concurrent PHP-FPM workers (fixes race condition).
        $ipKey = 'heart_rl_ip:' . md5($ip);
        apcu_add($ipKey, 0, self::IP_WINDOW);
        $ipCount = (int) apcu_inc($ipKey);
        if ($ipCount > self::IP_MAX) {
            header('Retry-After: ' . self::IP_WINDOW);
            http_response_code(429);
            echo json_encode([
                'ok'    => false,
                'error' => 'Too many requests from this IP. Please wait ' . self::IP_WINDOW . ' seconds.',
                'code'  => 429,
            ]);
            exit;
        }

        // ── User-level check (only if user identified) ────────
        if (!empty($userId)) {
            $userKey = 'heart_rl_usr:' . md5($userId);
            apcu_add($userKey, 0, self::USER_WINDOW);
            $userCount = (int) apcu_inc($userKey);
            if ($userCount > self::USER_MAX) {
                header('Retry-After: ' . self::USER_WINDOW);
                http_response_code(429);
                echo json_encode([
                    'ok'    => false,
                    'error' => 'Rate limit reached for this user. Please wait ' . self::USER_WINDOW . ' seconds.',
                    'code'  => 429,
                ]);
                exit;
            }
        }
    }

    private static function clientIp(): string
    {
        // Only trust X-Forwarded-For if configured to do so
        $trustProxy = (bool)(getenv('TRUST_PROXY') ?: false);
        if ($trustProxy && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}


// ════════════════════════════════════════════════════════════
//  DEBUG MODE GUARD
//  v1.2.0: debug only enabled with valid X-Admin-Key header
// ════════════════════════════════════════════════════════════
class HeartDebug
{
    public static function isEnabled(): bool
    {
        if (HEART_ENV === 'development') {
            return true;
        }
        $key = $_SERVER['HTTP_X_ADMIN_KEY'] ?? '';
        return !empty(HEART_ADMIN_KEY) && hash_equals(HEART_ADMIN_KEY, $key);
    }
}


/**
 * HEART LOGGER — Redirects to Centralized Logger
 */
class HeartLogger
{
    public static function debug(string $msg, array $ctx = []): void { Logger::debug($msg, $ctx); }
    public static function info(string $msg,  array $ctx = []): void { Logger::info($msg, $ctx); }
    public static function warn(string $msg,  array $ctx = []): void { Logger::warning($msg, $ctx); }
    public static function error(string $msg, array $ctx = []): void { Logger::error($msg, $ctx); }
}

// ═══════════════════════════════════════════════
// VIDEO_ENGINE ROUTES — DO NOT UNCOMMENT YET
// Activate per /body/video-engine/README.md
// ═══════════════════════════════════════════════
// GET  /api/video/feed     -> VideoController::getFeed
// POST /api/vendor/video   -> VideoController::uploadVideo
// POST /api/video/{id}/like -> VideoController::likeVideo
// ═══════════════════════════════════════════════
