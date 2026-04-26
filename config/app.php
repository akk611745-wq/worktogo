<?php
/**
 * Global Application Configuration
 */

return [
    'env'         => getenv('APP_ENV') ?: 'production',
    'version'     => '1.2.0',
    'timezone'    => getenv('APP_TIMEZONE') ?: 'Asia/Kolkata',
    'admin_key'   => getenv('ADMIN_KEY') ?: '',
    'internal_key'=> getenv('HEART_INTERNAL_KEY') ?: '',
    'log_level'   => strtoupper(getenv('HEART_LOG_LEVEL') ?: 'WARN'),
    'debug'       => getenv('APP_DEBUG') === 'true',
    'auth' => [
        'jwt_secret' => getenv('JWT_SECRET') ?: 'change-me-in-production',
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

// Global Constants for backward compatibility
define('ROLE_ADMIN',           'admin');
define('ROLE_VENDOR_SERVICE',  'vendor_service');
define('ROLE_VENDOR_SHOPPING', 'vendor_shopping');
define('ROLE_DELIVERY',        'delivery');
define('ROLE_CUSTOMER',        'customer');
define('JWT_SECRET',    getenv('JWT_SECRET') ?: 'change-me-in-production');
define('JWT_EXPIRY',    (int)(getenv('JWT_EXPIRY') ?: 3600));
define('JWT_REFRESH_EXP', (int)(getenv('JWT_REFRESH_EXP') ?: 86400 * 30));

// OTP Constants
define('OTP_LENGTH', 6);
define('OTP_EXPIRY', 300); // 5 minutes
define('OTP_RESEND_WAIT', 60); // 1 minute
define('OTP_MAX_ATTEMPTS', 3);

