<?php
// ============================================================
//  TEMPORARY DIAGNOSTIC — DELETE AFTER USE
//  GET /heart/api/auth/_diag_otp?key=<ADMIN_KEY>
//  Exposes OTP/MSG91 env status + recent OTP rows.
//  NO secret values are printed.
// ============================================================

// Must match authkey route guard before bootstrap loads
// Bootstrap is NOT loaded here — load it manually.
define('HEART_ROOT',  __DIR__ . '/../../..');
define('SYSTEM_ROOT', __DIR__ . '/../../..');

// ── Load env (same logic as heart/index.php) ─────────────────
$_envFile = realpath(__DIR__ . '/../../../.env');
if ($_envFile && file_exists($_envFile)) {
    foreach (file($_envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
        $_line = trim($_line);
        if ($_line === '' || str_starts_with($_line, '#') || !str_contains($_line, '=')) continue;
        [$_k, $_v] = explode('=', $_line, 2);
        $_k = trim($_k);
        $_v = trim($_v, " \t\n\r\0\x0B\"'");
        if (!empty($_k) && getenv($_k) === false) {
            putenv("{$_k}={$_v}");
            $_ENV[$_k] = $_v;
        }
    }
}

header('Content-Type: application/json; charset=utf-8');

// ── Auth guard ────────────────────────────────────────────────
$adminKey = getenv('ADMIN_KEY') ?: '';
$provided = $_GET['key'] ?? '';

if ($adminKey === '' || $provided === '' || !hash_equals($adminKey, $provided)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// ── Env check ────────────────────────────────────────────────
$authKey    = getenv('MSG91_AUTH_KEY')    ?: '';
$templateId = getenv('MSG91_TEMPLATE_ID') ?: '';
$provider   = getenv('SMS_PROVIDER')      ?: '';
$senderId   = getenv('MSG91_SENDER_ID')   ?: '';
$logLevel   = getenv('LOG_LEVEL')         ?: '';     // what Logger::init() actually reads
$heartLog   = getenv('HEART_LOG_LEVEL')   ?: '';     // what app.php reads (different key!)
$appEnv     = getenv('APP_ENV')           ?: '';

$envLoaded = [
    'MSG91_AUTH_KEY'    => [
        'exists' => $authKey !== '',
        'length' => strlen($authKey),
    ],
    'MSG91_TEMPLATE_ID' => [
        'exists'     => $templateId !== '',
        'length'     => strlen($templateId),
        'first_6'    => $templateId !== '' ? substr($templateId, 0, 6) : null,
    ],
    'SMS_PROVIDER'      => $provider !== '' ? $provider : '(not set)',
    'MSG91_SENDER_ID'   => $senderId !== '' ? $senderId : '(not set)',
    'APP_ENV'           => $appEnv !== '' ? $appEnv : '(not set)',
    'LOG_LEVEL'         => $logLevel !== '' ? $logLevel : '(not set — Logger defaults to ERROR, info/warn logs are DROPPED)',
    'HEART_LOG_LEVEL'   => $heartLog !== '' ? $heartLog : '(not set)',
    'LOG_LEVEL_warning' => 'Logger::init() reads LOG_LEVEL, not HEART_LOG_LEVEL — verify these match',
];

// ── DB connection ─────────────────────────────────────────────
$latestOtps = null;
$dbError     = null;
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('DB_HOST') ?: 'localhost',
        getenv('DB_PORT') ?: '3306',
        getenv('DB_NAME') ?: ''
    );
    $pdo = new PDO($dsn, getenv('DB_USER') ?: '', getenv('DB_PASS') ?: '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,
    ]);
    $stmt = $pdo->query(
        "SELECT id, phone, created_at, expires_at, used FROM otps ORDER BY created_at DESC LIMIT 5"
    );
    $latestOtps = $stmt->fetchAll();
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

// ── MSG91 payload preview (no actual call — inspect only) ─────
$samplePhone   = '916398527924';
$cleanPhone    = preg_replace('/[^0-9]/', '', $samplePhone);
$cleanPhone    = preg_replace('/^91/', '', $cleanPhone);
$payloadPreview = [
    'template_id'      => $templateId !== '' ? substr($templateId, 0, 6) . '...' : '(MISSING)',
    'short_url'        => '0',
    'realTimeResponse' => '1',
    'recipients'       => [['mobiles' => '91' . $cleanPhone, 'otp' => '______']],
];

// ── Response ──────────────────────────────────────────────────
echo json_encode([
    'diag'           => 'OTP / MSG91 env diagnostic',
    'server_time'    => date('Y-m-d H:i:s T'),
    'env_loaded'     => $envLoaded,
    'payload_shape'  => $payloadPreview,
    'latest_otps'    => $latestOtps ?? ['error' => $dbError],
    'note'           => 'Delete this file after diagnosis. Never expose in production.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
