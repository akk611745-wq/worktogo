<?php
// ============================================================
//  POST /api/auth/otp/verify
//  Verifies OTP and returns JWT. Creates account if new user.
// ============================================================

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$v = Validator::make($input, [
    'phone' => 'required|phone',
    'otp'   => 'required|string|min:6|max:6',
    'name'  => 'nullable|string|min:2|max:100',
]);

if ($v->fails()) {
    Response::validation($v->firstError(), $v->errors());
}

$data = $v->validated();

try {
    // Fetch latest unused OTP for this phone
    $stmt = $db->prepare(
        "SELECT id, otp_hash, expires_at, attempts
         FROM otps
         WHERE phone = ? AND used = 0
         ORDER BY created_at DESC
         LIMIT 1"
    );
    $stmt->execute([$data['phone']]);
    $row = $stmt->fetch();

    if (!$row) {
        Response::error('No active OTP found. Please request a new one.', 400, 'OTP_NOT_FOUND');
    }

    // Check expiry
    if (strtotime($row['expires_at']) < time()) {
        $db->prepare("UPDATE otps SET used = 1 WHERE id = ?")->execute([$row['id']]);
        Response::error('OTP has expired. Please request a new one.', 400, 'OTP_EXPIRED');
    }

    // Check attempts
    if ((int) $row['attempts'] >= OTP_MAX_ATTEMPTS) {
        $db->prepare("UPDATE otps SET used = 1 WHERE id = ?")->execute([$row['id']]);
        Response::error('Too many failed attempts. Please request a new OTP.', 400, 'OTP_MAX_ATTEMPTS');
    }

    // Verify OTP value
    if (!password_verify($data['otp'], $row['otp_hash'])) {
        // Increment attempts
        $db->prepare("UPDATE otps SET attempts = attempts + 1 WHERE id = ?")->execute([$row['id']]);
        Response::error('Incorrect OTP. Please try again.', 400, 'OTP_INVALID');
    }

    // Mark as used
    $db->prepare("UPDATE otps SET used = 1, used_at = NOW() WHERE id = ?")->execute([$row['id']]);

    // Find or create user
    $userStmt = $db->prepare(
        "SELECT id, name, phone, email, role, status FROM users WHERE phone = ? AND deleted_at IS NULL LIMIT 1"
    );
    $userStmt->execute([$data['phone']]);
    $user = $userStmt->fetch();

    $isNew = false;

    if (!$user) {
        // Auto-create account on first OTP verify
        $name = $data['name'] ?: ('User_' . substr(str_replace('+', '', $data['phone']), -4));

        $db->prepare(
            "INSERT INTO users (uuid, name, phone, role, status, phone_verified_at, created_at, updated_at)
             VALUES (UUID(), :name, :phone, :role, 'active', NOW(), NOW(), NOW())"
        )->execute([':name' => $name, ':phone' => $data['phone'], ':role' => ROLE_CUSTOMER]);

        $userId = (int) $db->lastInsertId();
        $role   = ROLE_CUSTOMER;
        $isNew  = true;

        Logger::info('User created via OTP', ['user_id' => $userId, 'phone' => $data['phone']]);
    } else {
        if ($user['status'] !== 'active') {
            Response::error('Account suspended. Contact support.', 403, 'ACCOUNT_SUSPENDED');
        }

        $userId = (int) $user['id'];
        $role   = $user['role'];

        // Mark phone as verified
        $db->prepare("UPDATE users SET phone_verified_at = NOW(), last_login_at = NOW() WHERE id = ?")
           ->execute([$userId]);
    }

    // Issue JWT
    $token = JWT::encode([
        'user_id' => $userId,
        'role'    => $role,
        'phone'   => $data['phone'],
    ], JWT_SECRET, JWT_EXPIRY);

    $refreshToken = bin2hex(random_bytes(32));
    $refreshHash = hash('sha256', $refreshToken);
    $stmt = $db->prepare("INSERT INTO refresh_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))");
    $stmt->execute([$userId, $refreshHash]);

    Response::success([
        'token'        => $token,
        'refreshToken' => $refreshToken,
        'expires_in'   => JWT_EXPIRY,
        'is_new'       => $isNew,
        'user'         => [
            'id'    => $userId,
            'phone' => $data['phone'],
            'role'  => $role,
        ],
    ], 200, $isNew ? 'Account created' : 'OTP verified');

} catch (PDOException $e) {
    Logger::error('OTP verify DB error', ['error' => $e->getMessage()]);
    Response::serverError('Verification failed. Please try again.');
}
