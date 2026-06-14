<?php
// ============================================================
//  TEMPORARY DIAGNOSTIC — DELETE AFTER USE
//  GET /heart/api/auth/_diag_msg91_test?key=<ADMIN_KEY>&phone=<10digits>
//  Fires the exact same MSG91 request as _sendMsg91() and
//  returns the raw response so we can see what MSG91 replies.
// ============================================================

// Load env manually (bootstrap not available here)
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

// ── Phone param ───────────────────────────────────────────────
$rawPhone = trim($_GET['phone'] ?? '');
if ($rawPhone === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required param: phone (10-digit number)']);
    exit;
}

// ── Clean phone — identical logic to _sendMsg91() ─────────────
$cleanPhone = preg_replace('/[^0-9]/', '', $rawPhone);
$cleanPhone = preg_replace('/^91/', '', $cleanPhone);
$mobiles    = '91' . $cleanPhone;

// ── MSG91 credentials ─────────────────────────────────────────
$authKey    = getenv('MSG91_AUTH_KEY')    ?: '';
$templateId = getenv('MSG91_TEMPLATE_ID') ?: '';

if ($authKey === '' || $templateId === '') {
    echo json_encode([
        'error'              => 'MSG91 credentials missing from env',
        'MSG91_AUTH_KEY'     => ['exists' => $authKey !== '',    'length' => strlen($authKey)],
        'MSG91_TEMPLATE_ID'  => ['exists' => $templateId !== '', 'length' => strlen($templateId)],
    ]);
    exit;
}

// ── Build payload — identical to _sendMsg91() ─────────────────
$payload = json_encode([
    'template_id'      => $templateId,
    'short_url'        => '0',
    'realTimeResponse' => '1',
    'recipients'       => [['mobiles' => $mobiles, 'otp' => '123456']],
]);

// ── cURL POST ─────────────────────────────────────────────────
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => 'https://api.msg91.com/api/v5/flow/',
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'authkey: ' . $authKey,
        'content-type: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$body      = curl_exec($ch);
$httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// ── Response ──────────────────────────────────────────────────
echo json_encode([
    'phone_sent_as'       => $mobiles,
    'http_code'           => $httpCode,
    'msg91_response_body' => $body,
    'curl_error'          => $curlError !== '' ? $curlError : null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
