<?php
/**
 * Global Application Configuration
 */

$appEnv = getenv('APP_ENV') ?: 'production';
$jwtSecret = getenv('JWT_SECRET');

if ($appEnv === 'production' && empty($jwtSecret)) {
    throw new Exception('JWT_SECRET environment variable is required in production environment.');
}

// Global Constants for backward compatibility.
// These must be defined before this file returns its config array.
if (!defined('ROLE_ADMIN')) {
    define('ROLE_ADMIN',           'admin');
    define('ROLE_VENDOR_SERVICE',  'vendor_service');
    define('ROLE_VENDOR_SHOPPING', 'vendor_shopping');
    define('ROLE_DELIVERY',        'delivery');
    define('ROLE_CUSTOMER',        'customer');
}

if (!defined('JWT_SECRET')) {
    define('JWT_SECRET', $jwtSecret ?: 'change-me-in-production');
}
if (!defined('JWT_EXPIRY')) {
    define('JWT_EXPIRY', (int)(getenv('JWT_EXPIRY') ?: 3600));
}
if (!defined('JWT_REFRESH_EXP')) {
    define('JWT_REFRESH_EXP', (int)(getenv('JWT_REFRESH_EXP') ?: 86400 * 30));
}

if (!defined('OTP_LENGTH')) {
    define('OTP_LENGTH', 6);
}
if (!defined('OTP_EXPIRY')) {
    define('OTP_EXPIRY', 300); // 5 minutes
}
if (!defined('OTP_RESEND_WAIT')) {
    define('OTP_RESEND_WAIT', 60); // 1 minute
}
if (!defined('OTP_MAX_ATTEMPTS')) {
    define('OTP_MAX_ATTEMPTS', 3);
}

return [
    'env'         => $appEnv,
    'version'     => '1.2.0',
    'timezone'    => getenv('APP_TIMEZONE') ?: 'Asia/Kolkata',
    'admin_key'   => getenv('ADMIN_KEY') ?: '',
    'internal_key'=> getenv('HEART_INTERNAL_KEY') ?: '',
    'log_level'   => strtoupper(getenv('HEART_LOG_LEVEL') ?: 'WARN'),
    'debug'       => getenv('APP_DEBUG') === 'true',
    'auth' => [
        'jwt_secret' => $jwtSecret ?: 'change-me-in-production',
        'jwt_expiry' => (int)(getenv('JWT_EXPIRY') ?: 3600),
        'jwt_refresh_exp' => (int)(getenv('JWT_REFRESH_EXP') ?: 86400 * 30),
    ],
    'roles' => [
        'admin'            => 'admin',
        'vendor_service'   => 'vendor_service',
        'vendor_shopping'  => 'vendor_shopping',
        'delivery'         => 'delivery',
        'customer'         => 'customer',
    ],
];
