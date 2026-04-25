<?php
// ============================================================
//  POST /api/auth/otp/send
//  Generates and sends a 6-digit OTP to a phone number.
//  Stores hashed OTP in DB. Supports MSG91 / Fast2SMS / Twilio.
// ============================================================

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$v = Validator::make($input, [
    'phone' => 'required|phone',
]);

if ($v->fails()) {
    Response::validation($v->firstError(), $v->errors());
}

$phone = $v->validated()['phone'];

try {
    // Rate limit — max OTP_MAX_ATTEMPTS per OTP_RESEND_WAIT seconds
    $recent = $db->prepare(
        "SELECT COUNT(*) FROM otps
         WHERE phone = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND) AND used = 0"
    );
    $recent->execute([$phone, OTP_RESEND_WAIT]);

    if ((int) $recent->fetchColumn() >= OTP_MAX_ATTEMPTS) {
        Response::tooManyRequests('Too many OTP requests. Please wait ' . OTP_RESEND_WAIT . ' seconds.');
    }

    // Expire any existing unused OTPs for this phone
    $db->prepare("UPDATE otps SET used = 1 WHERE phone = ? AND used = 0")->execute([$phone]);

    // Generate OTP
    $otp     = str_pad((string) random_int(0, (int) str_repeat('9', OTP_LENGTH)), OTP_LENGTH, '0', STR_PAD_LEFT);
    $hash    = password_hash($otp, PASSWORD_BCRYPT, ['cost' => 10]);
    $expires = date('Y-m-d H:i:s', time() + OTP_EXPIRY);

    // Store
    $db->prepare(
        "INSERT INTO otps (phone, otp_hash, expires_at, used, created_at)
         VALUES (?, ?, ?, 0, NOW())"
    )->execute([$phone, $hash, $expires]);

    // Send via configured provider
    $sent = _sendOtp($phone, $otp);

    if (!$sent) {
        Logger::error('OTP send failed', ['phone' => $phone]);
        Response::serverError('Failed to send OTP. Please try again.');
    }

    Logger::info('OTP sent', ['phone' => $phone]);

    // Never return the OTP in production
    $responseData = ['phone' => $phone, 'expires_in' => OTP_EXPIRY];

    if (getenv('APP_ENV') === 'local' || getenv('APP_DEBUG') === 'true') {
        $responseData['otp_debug'] = $otp; // Only in local dev
    }

    Response::success($responseData, 200, 'OTP sent successfully');

} catch (PDOException $e) {
    Logger::error('OTP send DB error', ['error' => $e->getMessage()]);
    Response::serverError('OTP service unavailable');
}

// ── SMS Dispatch ─────────────────────────────────────────────
function _sendOtp(string $phone, string $otp): bool
{
    $provider = strtolower(getenv('SMS_PROVIDER') ?: 'msg91');

    return match ($provider) {
        'msg91'    => _sendMsg91($phone, $otp),
        'fast2sms' => _sendFast2Sms($phone, $otp),
        'twilio'   => _sendTwilio($phone, $otp),
        'log'      => _logOtp($phone, $otp),   // For local dev
        default    => _logOtp($phone, $otp),
    };
}

function _sendMsg91(string $phone, string $otp): bool
{
    $key        = getenv('MSG91_AUTH_KEY');
    $templateId = getenv('MSG91_TEMPLATE_ID');

    if (!$key || !$templateId) {
        return _logOtp($phone, $otp);
    }

    $payload = json_encode([
        'template_id' => $templateId,
        'short_url'   => '0',
        'realTimeResponse' => '1',
        'recipients'  => [['mobiles' => '91' . ltrim($phone, '+'), 'otp' => $otp]],
    ]);

    return _httpPost('https://api.msg91.com/api/v5/flow/', $payload, [
        'authkey: ' . $key,
        'content-type: application/json',
    ]);
}

function _sendFast2Sms(string $phone, string $otp): bool
{
    $key = getenv('FAST2SMS_KEY');
    if (!$key) {
        return _logOtp($phone, $otp);
    }

    $url = 'https://www.fast2sms.com/dev/bulkV2?authorization=' . urlencode($key)
         . '&variables_values=' . urlencode($otp)
         . '&route=otp&numbers=' . urlencode(ltrim($phone, '+'));

    return _httpGet($url);
}

function _sendTwilio(string $phone, string $otp): bool
{
    $sid   = getenv('TWILIO_SID');
    $token = getenv('TWILIO_TOKEN');
    $from  = getenv('TWILIO_FROM');

    if (!$sid || !$token || !$from) {
        return _logOtp($phone, $otp);
    }

    $payload = http_build_query([
        'To'   => $phone,
        'From' => $from,
        'Body' => "Your WorkToGo OTP is: {$otp}. Valid for " . (OTP_EXPIRY / 60) . " minutes.",
    ]);

    return _httpPost(
        "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json",
        $payload,
        ['Content-Type: application/x-www-form-urlencoded'],
        $sid . ':' . $token
    );
}

function _logOtp(string $phone, string $otp): bool
{
    Logger::info('OTP (dev/log mode)', ['phone' => $phone, 'otp' => $otp]);
    return true;
}

function _httpPost(string $url, string $payload, array $headers = [], string $auth = ''): bool
{
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ];

    if ($auth) {
        $opts[CURLOPT_USERPWD] = $auth;
    }

    $ch  = curl_init();
    curl_setopt_array($ch, $opts);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $code >= 200 && $code < 300;
}

function _httpGet(string $url): bool
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}
