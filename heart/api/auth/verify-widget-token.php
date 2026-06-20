<?php
// ============================================================
//  POST /api/auth/widget/verify-token
//  Server-side verification of MSG91 OTP Widget access-token.
//  Calls MSG91's verifyAccessToken API, then finds-or-creates
//  the user and issues a JWT — same outcome as verify-otp.php.
// ============================================================

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$v = Validator::make($input, [
    'phone'        => 'required|phone',
    'access_token' => 'required|string',
]);

if ($v->fails()) {
    Response::validation($v->firstError(), $v->errors());
}

$data    = $v->validated();
$authKey = getenv('MSG91_AUTH_KEY');

if (!$authKey) {
    Logger::error('MSG91_AUTH_KEY missing for widget verification');
    Response::serverError('OTP service not configured');
}

// ── Server-side verify with MSG91 ────────────────────────────
$verifyPayload = json_encode([
    'authkey'      => $authKey,
    'access-token' => $data['access_token'],
]);

$ch = curl_init('https://control.msg91.com/api/v5/widget/verifyAccessToken');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $verifyPayload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$res  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

Logger::error('MSG91_WIDGET_VERIFY', ['http_code' => $code, 'body' => $res]);

$body = @json_decode($res, true);

if ($code !== 200 || empty($body) || ($body['type'] ?? '') !== 'success') {
    Logger::error('Widget token rejected', ['code' => $code, 'phone' => $data['phone']]);
    Response::error('OTP verification failed. Please try again.', 400, 'WIDGET_VERIFY_FAILED');
}

// ── Find or create user (mirrors verify-otp.php logic) ───────
$phone = $data['phone'];

try {
    $userStmt = $db->prepare(
        "SELECT id, name, phone, email, role, status FROM users WHERE phone = ? AND deleted_at IS NULL LIMIT 1"
    );
    $userStmt->execute([$phone]);
    $user = $userStmt->fetch();

    $isNew = false;

    if (!$user) {
        $name = 'User_' . substr(preg_replace('/\D/', '', $phone), -4);
        $db->prepare(
            "INSERT INTO users (uuid, name, phone, role, status, phone_verified_at, created_at, updated_at)
             VALUES (UUID(), :name, :phone, :role, 'active', NOW(), NOW(), NOW())"
        )->execute([':name' => $name, ':phone' => $phone, ':role' => ROLE_CUSTOMER]);
        $userId = (int) $db->lastInsertId();
        $role   = ROLE_CUSTOMER;
        $isNew  = true;
        Logger::info('User created via widget OTP', ['user_id' => $userId, 'phone' => $phone]);
    } else {
        if ($user['status'] !== 'active') {
            Response::error('Account suspended. Contact support.', 403, 'ACCOUNT_SUSPENDED');
        }
        $userId = (int) $user['id'];
        $role   = $user['role'];
        $db->prepare("UPDATE users SET phone_verified_at = NOW(), last_login_at = NOW() WHERE id = ?")
           ->execute([$userId]);
    }

    $token = JWT::encode([
        'user_id' => $userId,
        'role'    => $role,
        'phone'   => $phone,
    ], JWT_SECRET, time() + (86400 * 30));

    $refreshToken = bin2hex(random_bytes(32));
    $refreshHash  = hash('sha256', $refreshToken);
    $db->prepare("INSERT INTO refresh_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))")
       ->execute([$userId, $refreshHash]);

    Response::success([
        'token'         => $token,
        'refresh_token' => $refreshToken,
        'refreshToken'  => $refreshToken,
        'expires_in'    => 86400 * 30,
        'is_new'        => $isNew,
        'user'          => [
            'id'    => $userId,
            'phone' => $phone,
            'role'  => $role,
        ],
    ], 200, $isNew ? 'Account created' : 'Login successful');

} catch (PDOException $e) {
    Logger::error('Widget verify DB error', ['error' => $e->getMessage()]);
    Response::serverError('Login failed. Please try again.');
}
