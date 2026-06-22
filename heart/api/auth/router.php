<?php
// ============================================================
//  WorkToGo CORE — Auth Router
//  Dispatches /api/auth/* requests to the correct handler.
//  All handlers share the already-loaded $db, helpers, etc.
// ============================================================

$_authSegment = str_replace('/api/auth', '', $uri);

if ($method === 'POST' && $uri === '/api/auth/login') {
    header('Location: /api/auth/email/login', true, 307);
    exit;
}

$_authRoute = match (true) {
    $method === 'POST'  && $uri === '/api/auth/register'       => 'register.php',
    $method === 'POST'  && $uri === '/api/auth/logout'         => 'logout.php',
    $method === 'GET'   && $uri === '/api/auth/me'             => 'me.php',
    $method === 'GET'   && $uri === '/api/auth/fcm-config'     => 'fcm-config.php',
    $method === 'PATCH' && $uri === '/api/auth/profile'        => 'update-profile.php',
    $method === 'POST'  && $uri === '/api/auth/refresh'        => 'refresh.php',
    $method === 'POST'  && in_array($uri, ['/api/auth/otp/send', '/api/auth/send-otp'], true)     => 'send-otp.php',
    $method === 'POST'  && in_array($uri, ['/api/auth/otp/verify', '/api/auth/verify-otp'], true) => 'verify-otp.php',
    $method === 'POST'  && $uri === '/api/auth/widget/verify-token'                               => 'verify-widget-token.php',
    $method === 'POST'  && $uri === '/api/auth/device/fcm'                                        => 'device-fcm.php',
    $method === 'GET'   && $uri === '/api/auth/notifications'                                     => 'notifications.php',
    $method === 'POST'  && $uri === '/api/auth/notifications/read'                                => 'notifications-read.php',
    // New routes
    $method === 'POST'  && $uri === '/api/auth/email/register' => 'AuthController.php',
    $method === 'POST'  && $uri === '/api/auth/email/login'    => 'AuthController.php',
    $method === 'POST'  && $uri === '/api/auth/google'         => 'AuthController.php',
    $method === 'GET'   && $uri === '/api/auth/guest'          => 'AuthController.php',
    default => null,
};

if ($_authRoute === null) {
    Logger::warning('Unknown auth route', ['uri' => $uri, 'method' => $method]);
    Response::notFound('Auth endpoint');
}

require __DIR__ . '/' . $_authRoute;

// Dispatch AuthController methods if matched
if ($_authRoute === 'AuthController.php') {
    $controller = new AuthController();
    if ($uri === '/api/auth/email/register') $controller->registerEmail();
    if ($uri === '/api/auth/email/login') $controller->loginEmail();
    if ($uri === '/api/auth/google') $controller->loginGoogle();
    if ($uri === '/api/auth/guest') $controller->guestLogin();
    exit;
}
