<?php
// ============================================================
//  POST /api/auth/login
//  Authenticates user by phone + password. Returns JWT.
// ============================================================

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if (!RateLimiter::check('login', 5, 300)) {
    Response::tooManyRequests('Too many failed login attempts. Please try again later.');
}

$v = Validator::make($input, [
    'phone'    => 'required|phone',
    'password' => 'required|min:1',
]);

if ($v->fails()) {
    Response::validation($v->firstError(), $v->errors());
}

$data = $v->validated();

try {
    $stmt = $db->prepare(
        "SELECT id, uuid, name, phone, email, password, role, status
         FROM users
         WHERE phone = ? AND deleted_at IS NULL
         LIMIT 1"
    );
    $stmt->execute([$data['phone']]);
    $user = $stmt->fetch();

    // Deliberate vague message — never reveal whether phone exists
    if (!$user || !password_verify($data['password'], $user['password'])) {
        Logger::warning('Failed login attempt', ['phone' => $data['phone']]);
        Response::error('Invalid phone number or password', 401, 'INVALID_CREDENTIALS');
    }

    if ($user['status'] !== 'active') {
        Response::error('Your account is suspended. Contact support.', 403, 'ACCOUNT_SUSPENDED');
    }

    // Issue access token
    $token = JWT::encode([
        'user_id' => (int) $user['id'],
        'role'    => $user['role'],
        'phone'   => $user['phone'],
    ], JWT_SECRET, JWT_EXPIRY);

    // Issue refresh token (longer-lived)
    $refreshToken = JWT::encode([
        'user_id' => (int) $user['id'],
        'type'    => 'refresh',
    ], JWT_SECRET, JWT_REFRESH_EXP);

    RateLimiter::clear('login');

    // Store refresh token
    $db->prepare(
        "INSERT INTO refresh_tokens (user_id, token_hash, expires_at, created_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())"
    )->execute([(int) $user['id'], hash('sha256', $refreshToken), JWT_REFRESH_EXP]);

    // Update last login
    $db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([(int) $user['id']]);

    Logger::info('User logged in', ['user_id' => $user['id'], 'role' => $user['role']]);

    Response::success([
        'token'         => $token,
        'refresh_token' => $refreshToken,
        'expires_in'    => JWT_EXPIRY,
        'user'          => [
            'id'    => (int) $user['id'],
            'name'  => $user['name'],
            'phone' => $user['phone'],
            'email' => $user['email'],
            'role'  => $user['role'],
        ],
    ], 200, 'Login successful');

} catch (PDOException $e) {
    Logger::error('Login DB error', ['error' => $e->getMessage()]);
    Response::serverError('Login failed. Please try again.');
}
