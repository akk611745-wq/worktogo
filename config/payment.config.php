<?php
/**
 * Cashfree Payment Gateway Configuration
 * WorkToGo Payment Module — v1.0
 * 
 * ⚠️  DO NOT hardcode credentials here.
 *     Load from environment or a .env file outside webroot.
 */

// ─── Load .env values (if using a .env loader like vlucas/phpdotenv) ──────────
// If you're on shared hosting without Composer, use the manual loader below.

if (!function_exists('getenv_safe')) {
    function getenv_safe(string $key, string $default = ''): string {
        $val = getenv($key);
        return ($val !== false && $val !== '') ? $val : $default;
    }
}

// ─── Manual .env loader ───────────────────────────────────────────────────────
$envFile = __DIR__ . '/.env'; 
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key]  = $value;
            putenv("$key=$value");
        }
    }
}

// ─── Payment Config Constants ─────────────────────────────────────────────────
define('CASHFREE_APP_ID',     getenv_safe('CASHFREE_APP_ID'));
define('CASHFREE_SECRET_KEY', getenv_safe('CASHFREE_SECRET_KEY'));
define('CASHFREE_ENV',        getenv_safe('CASHFREE_ENV', 'TEST')); // TEST | PRODUCTION

// Derived: Cashfree API base URL
define('CASHFREE_API_BASE', strtoupper(CASHFREE_ENV) === 'PRODUCTION'
    ? 'https://api.cashfree.com/pg'
    : 'https://sandbox.cashfree.com/pg'
);

define('CASHFREE_API_VERSION', '2023-08-01');

// ─── App Base URL (used for return/notify URLs) ───────────────────────────────
define('APP_BASE_URL', rtrim(getenv_safe('APP_BASE_URL', 'https://yourdomain.com'), '/'));

// Payment return URL after Cashfree hosted page
define('PAYMENT_RETURN_URL', APP_BASE_URL . '/payment/return');

// Webhook URL (must be publicly accessible)
define('PAYMENT_WEBHOOK_URL', APP_BASE_URL . '/api/payment/webhook');

// ─── Validation ───────────────────────────────────────────────────────────────
if (empty(CASHFREE_APP_ID) || empty(CASHFREE_SECRET_KEY)) {
    // Only throw in non-CLI contexts where payment is actually being used
    if (php_sapi_name() !== 'cli') {
        error_log('[PaymentConfig] CRITICAL: CASHFREE_APP_ID or CASHFREE_SECRET_KEY not set.');
        // Don't expose config errors to end users — die silently and log
        http_response_code(500);
        die(json_encode(['status' => 'error', 'message' => 'Payment gateway not configured.']));
    }
}
