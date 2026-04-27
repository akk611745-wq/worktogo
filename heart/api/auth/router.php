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
    $method === 'POST' && $uri === '/api/auth/register'       => 'register.php',
    $method === 'POST' && $uri === '/api/auth/logout'         => 'logout.php',
    $method === 'GET'  && $uri === '/api/auth/me'             => 'me.php',
    $method === 'POST' && $uri === '/api/auth/refresh'        => 'refresh.php',
    $method === 'POST' && $uri === '/api/auth/otp/send'       => 'send-otp.php',
    $method === 'POST' && $uri === '/api/auth/otp/verify'     => 'verify-otp.php',
    // New routes
    $method === 'POST' && $uri === '/api/auth/email/register' => 'AuthController.php',
    $method === 'POST' && $uri === '/api/auth/email/login'    => 'AuthController.php',
    $method === 'POST' && $uri === '/api/auth/google'         => 'AuthController.php',
    $method === 'GET'  && $uri === '/api/auth/guest'          => 'AuthController.php',
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
