<?php

class AuthController {

    private PDO $db;

    public function __construct() {
        $this->db = getDB();
    }

    public function registerEmail() {
        if (!RateLimiter::check('register_email', 5, 300)) {
            Response::error('Too many registration attempts. Please try again later.', 429);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            Response::error('Invalid JSON payload', 400);
        }

        $name = trim($input['name'] ?? '');
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';
        $phone = isset($input['phone']) ? trim((string)$input['phone']) : null;
        $phone = $phone !== '' ? $phone : null;

        if (!$name || !$email || !$password) {
            Response::error('Name, email, and password are required', 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Invalid email format', 400);
        }

        $stmt = $this->db->prepare("SELECT id, name, email, password, google_id, role, status FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingUser) {
            // Email already has an account — reuse it (e.g. an existing customer applying as
            // a vendor) instead of failing.
            if (!empty($existingUser['google_id'])) {
                // Google-only account has no password to verify against — trust the email
                // match and set the password they just chose so they can also log in with it.
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $this->db->prepare("UPDATE users SET password = ? WHERE id = ?")
                    ->execute([$hash, $existingUser['id']]);
            } elseif (!password_verify((string)$password, (string)($existingUser['password'] ?? ''))) {
                // Email account — require the correct password to avoid letting someone
                // "register" their way into another person's account.
                Response::error('Email already registered. Please login instead.', 409);
            }
            if (($existingUser['status'] ?? '') !== 'active') {
                Response::error('Account is inactive', 403);
            }
            $userId = $existingUser['id'];
            $name = $existingUser['name'];
            $role = $existingUser['role'];
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);

            $stmt = $this->db->prepare("
                INSERT INTO users (uuid, name, email, password, phone, auth_type, role, status, created_at, updated_at)
                VALUES (UUID(), ?, ?, ?, ?, 'email', 'customer', 'active', NOW(), NOW())
            ");
            $stmt->execute([$name, $email, $hash, $phone]);
            $userId = $this->db->lastInsertId();
            $role = 'customer';
        }

        $user = [
            'id' => $userId,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'auth_type' => 'email',
            'role' => $role
        ];

        $token = JWT::encode([
            'user_id' => $userId,
            'role' => $role,
            'iat' => time(),
            'exp' => time() + (86400 * 30) // 30 days
        ], JWT_SECRET);

        $refreshToken = bin2hex(random_bytes(32));
        $refreshHash = hash('sha256', $refreshToken);
        $stmt = $this->db->prepare("INSERT INTO refresh_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))");
        $stmt->execute([$userId, $refreshHash]);

        Response::json([
            'success' => true,
            'token' => $token,
            'refresh_token' => $refreshToken,
            'refreshToken' => $refreshToken,
            'user' => $user
        ]);
    }

    public function loginEmail() {
        if (!RateLimiter::check('login_email', 5, 300)) {
            Response::error('Too many login attempts. Please try again later.', 429);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            Response::error('Invalid JSON payload', 400);
        }

        $email = trim($input['email'] ?? $input['phone'] ?? '');
        $password = $input['password'] ?? '';

        if (!$email || !$password) {
            Response::error('Email/Phone and password are required', 400);
        }

        $normalizedPhone = preg_replace('/\D+/', '', $email);

        $stmt = $this->db->prepare("
            SELECT id, name, email, phone, password, role, auth_type, status
            FROM users
            WHERE email = ?
               OR phone = ?
               OR REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', ''), '(', '') = ?
            LIMIT 1
        ");
        $stmt->execute([$email, $email, $normalizedPhone]);
        $userRow = $stmt->fetch(PDO::FETCH_ASSOC);

        $storedHash = (string)($userRow['password'] ?? '');

        if (!$userRow || $storedHash === '' || !password_verify((string)$password, $storedHash)) {
            Response::error('Invalid email or password', 401);
        }

        if (($userRow['status'] ?? '') !== 'active') {
            Response::error('Account is inactive', 403);
        }

        $user = [
            'id' => $userRow['id'],
            'name' => $userRow['name'],
            'email' => $userRow['email'],
            'phone' => $userRow['phone'],
            'role' => $userRow['role']
        ];

        $token = JWT::encode([
            'user_id' => $userRow['id'],
            'role' => $userRow['role'],
            'iat' => time(),
            'exp' => time() + (86400 * 30) // 30 days
        ], JWT_SECRET);

        $stmt = $this->db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
        $stmt->execute([$userRow['id']]);

        $refreshToken = bin2hex(random_bytes(32));
        $refreshHash = hash('sha256', $refreshToken);
        $stmt = $this->db->prepare("INSERT INTO refresh_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))");
        $stmt->execute([$userRow['id'], $refreshHash]);

        Response::json([
            'success' => true,
            'token' => $token,
            'refresh_token' => $refreshToken,
            'refreshToken' => $refreshToken,
            'role' => $userRow['role'],
            'user' => $user,
            'admin' => ($userRow['role'] === 'admin' ? $user : null)
        ]);
    }

    public function loginGoogle() {
        if (!RateLimiter::check('google_login', 10, 300)) {
            Response::error('Too many Google login attempts. Please try again later.', 429);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $googleToken = trim((string)($input['google_token'] ?? ''));

        if (!$googleToken) {
            Response::error('Google token is required', 400);
        }

        $allowedClientIds = $this->getAllowedGoogleClientIds();
        if (empty($allowedClientIds)) {
            Response::error('Google login is not configured', 500);
        }

        // Verify token via external HTTP call
        $url = "https://oauth2.googleapis.com/tokeninfo?id_token=" . urlencode($googleToken);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        $responseJson = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $googleData = json_decode($responseJson, true);

        if ($httpCode !== 200 || isset($googleData['error'])) {
            Response::error('Invalid Google token', 401);
        }

        $audience = trim((string)($googleData['aud'] ?? ''));
        if ($audience === '' || !in_array($audience, $allowedClientIds, true)) {
            Response::error('Invalid Google client', 401);
        }

        $issuer = (string)($googleData['iss'] ?? '');
        if (!in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true)) {
            Response::error('Invalid Google token issuer', 401);
        }

        if (isset($googleData['exp']) && (int)$googleData['exp'] < time()) {
            Response::error('Expired Google token', 401);
        }

        $emailVerified = $googleData['email_verified'] ?? false;
        if ($emailVerified !== true && $emailVerified !== 'true') {
            Response::error('Google email is not verified', 401);
        }

        $email = $googleData['email'] ?? '';
        $googleId = $googleData['sub'] ?? '';
        $name = $googleData['name'] ?? 'Google User';

        if (!$email || !$googleId) {
            Response::error('Invalid Google token data', 401);
        }

        // Find or create user
        $stmt = $this->db->prepare("SELECT id, name, email, phone, google_id, role, auth_type FROM users WHERE google_id = ? OR email = ?");
        $stmt->execute([$googleId, $email]);
        $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingUser) {
            // Update google_id if missing
            if (empty($existingUser['google_id'])) {
                $upd = $this->db->prepare("UPDATE users SET google_id = ? WHERE id = ?");
                $upd->execute([$googleId, $existingUser['id']]);
            }
            $userId = $existingUser['id'];
            $role = $existingUser['role'];
            $userResponse = [
                'id' => $userId,
                'name' => $existingUser['name'],
                'email' => $existingUser['email'],
                'phone' => $existingUser['phone'] ?? null,
                'auth_type' => $existingUser['auth_type']
            ];
        } else {
            // INSERT
            // phone is intentionally NULL — Google signup never collects one,
            // and users.phone has a UNIQUE index where MySQL allows unlimited
            // NULLs (unlike '', which would collide on the second Google-only
            // signup). Requires users.phone to allow NULL — see migration.
            $role = 'customer';
            $stmt = $this->db->prepare("
                INSERT INTO users (uuid, name, email, phone, google_id, auth_type, role, created_at)
                VALUES (UUID(), ?, ?, NULL, ?, 'google', ?, NOW())
            ");
            $stmt->execute([$name, $email, $googleId, $role]);
            $userId = $this->db->lastInsertId();

            $userResponse = [
                'id' => $userId,
                'name' => $name,
                'email' => $email,
                'phone' => null,
                'auth_type' => 'google'
            ];
        }

        $token = JWT::encode([
            'user_id' => $userId,
            'role' => $role,
            'iat' => time(),
            'exp' => time() + (86400 * 30) // 30 days
        ], JWT_SECRET);

        Response::json([
            'success' => true,
            'token' => $token,
            'user' => $userResponse
        ]);
    }

    private function getAllowedGoogleClientIds(): array {
        $rawClientIds = getenv('GOOGLE_CLIENT_IDS') ?: getenv('GOOGLE_CLIENT_ID') ?: '';
        $clientIds = array_map('trim', explode(',', $rawClientIds));

        return array_values(array_filter($clientIds, static function ($clientId) {
            return $clientId !== '';
        }));
    }

    public function guestLogin() {
        if (!RateLimiter::check('guest_login', 10, 300)) {
            Response::error('Too many guest login attempts. Please try again later.', 429);
        }

        $guestNumber = rand(100000, 999999);
        $name = 'Guest_' . $guestNumber;
        
        $stmt = $this->db->prepare("
            INSERT INTO users (uuid, name, phone, email, auth_type, role, is_guest, guest_expires_at, created_at)
            VALUES (UUID(), ?, NULL, NULL, 'guest', 'customer', 1, DATE_ADD(NOW(), INTERVAL 24 HOUR), NOW())
        ");
        $stmt->execute([$name]);
        $userId = $this->db->lastInsertId();

        $expiresAt = time() + (86400); // 24 hours

        $token = JWT::encode([
            'user_id' => $userId,
            'role' => 'customer',
            'is_guest' => true,
            'iat' => time(),
            'exp' => $expiresAt
        ], JWT_SECRET);

        Response::json([
            'success' => true,
            'token' => $token,
            'is_guest' => true,
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
            'message' => 'Guest session — sign up to unlock full features'
        ]);
    }
}
