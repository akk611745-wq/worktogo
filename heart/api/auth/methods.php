<?php
// ============================================================
//  GET|POST /api/auth/methods  — Smart Auth: available login methods
// ------------------------------------------------------------
//  Public, config-aware discovery for the login screen: which methods the
//  platform can offer RIGHT NOW (password / phone-OTP / WhatsApp-OTP / Google)
//  based on what is actually configured — WhatsApp/Google are only listed when
//  connected. So the frontend offers the right options and the user takes the
//  fastest path, never stuck on one method.
//
//  SECURITY: secure by default. With app_settings.auth_channel_routing
//  (reveal_account_methods) OFF, this NEVER confirms whether a given identifier
//  has an account or a password — it is not an enumeration oracle. Rate-limited
//  per IP regardless. No auth required (it's the pre-login screen).
// ============================================================

require_once SYSTEM_ROOT . '/lib/auth/bootstrap.php';

// Per-IP ceiling (bounds any enumeration if reveal is later enabled). Env-tunable.
$authMethodsIpMax    = (int) (getenv('AUTH_METHODS_IP_MAX')    ?: 60);
$authMethodsIpWindow = (int) (getenv('AUTH_METHODS_IP_WINDOW') ?: 600);
if ($authMethodsIpMax > 0 && !RateLimiter::check('auth_methods_ip', $authMethodsIpMax, $authMethodsIpWindow)) {
    Response::tooManyRequests('Too many requests. Please try again shortly.');
}

$cfg = @include SYSTEM_ROOT . '/config/auth.channels.php';
$cfg = is_array($cfg) ? $cfg : [];

$manager  = new OtpChannelManager($db);
$resolver = new LoginMethodResolver($manager, $cfg);

// Optional identifier (phone/email) — refines the offered set / preferred method.
$identifier = '';
if ($method === 'POST') {
    $body       = json_decode(file_get_contents('php://input'), true) ?: [];
    $identifier = is_string($body['identifier'] ?? null) ? trim($body['identifier']) : '';
} elseif (isset($_GET['identifier'])) {
    $identifier = trim((string) $_GET['identifier']);
}

$out = [
    'channels' => $resolver->channels(),                                   // { sms:bool, whatsapp:bool }
    'google'   => in_array('google', $resolver->platformMethods(), true),  // Google configured?
    'reveal'   => $resolver->revealAccountMethods(),
];

if ($identifier !== '') {
    $r = $resolver->methodsForIdentifier($db, $identifier);
    $out['identifier_type'] = $r['identifier_type'];
    $out['methods']         = $r['methods'];
    $out['preferred']       = $r['preferred'];
    if ($r['account'] !== null) {
        $out['account'] = $r['account']; // present only when reveal is enabled
    }
} else {
    $out['methods'] = $resolver->platformMethods();
}

Response::success($out, 200, 'Available auth methods');
