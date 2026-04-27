<?php

class AuthController {

    private PDO $db;

    public function __construct() {
        $this->db = getDB();
    }

    public function registerEmail() {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            Response::error('Invalid JSON payload', 400);
        }

        $name = trim($input['name'] ?? '');
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';
        $phone = trim($input['phone'] ?? '');

        if (!$name || !$email || !$password) {
            Response::error('Name, email, and password are required', 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Invalid email format', 400);
        }

        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            Response::error('Email already registered', 409);
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        
        $stmt = $this->db->prepare("
            INSERT INTO users (uuid, name, email, password, phone, auth_type, role, created_at)
            VALUES (UUID(), ?, ?, ?, ?, 'email', 'customer', NOW())
        ");
        $stmt->execute([$name, $email, $hash, $phone ?: null]);
        $userId = $this->db->lastInsertId();

        $user = [
            'id' => $userId,
            'name' => $name,
            'email' => $email,
            'auth_type' => 'email',
            'role' => 'customer'
        ];

        $token = JWT::encode([
            'user_id' => $userId,
            'role' => 'customer',
            'iat' => time(),
            'exp' => time() + (86400 * 30) // 30 days
        ], JWT_SECRET);

        Response::json([
            'success' => true,
            'token' => $token,
            'user' => $user
        ]);
    }

    public function loginEmail() {
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
            SELECT id, name, email, phone, password, role, auth_type
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

        $user = [
            'id' => $userRow['id'],
            'name' => $userRow['name'],
            'email' => $userRow['email'],
            'role' => $userRow['role']
        ];

        $token = JWT::encode([
            'user_id' => $userRow['id'],
            'role' => $userRow['role'],
            'iat' => time(),
            'exp' => time() + (86400 * 30) // 30 days
        ], JWT_SECRET);

        Response::json([
            'success' => true,
            'token' => $token,
            'user' => $user,
            'admin' => ($userRow['role'] === 'admin' ? $user : null)
        ]);
    }

    public function loginGoogle() {
        $input = json_decode(file_get_contents('php://input'), true);
        $googleToken = $input['google_token'] ?? '';

        if (!$googleToken) {
            Response::error('Google token is required', 400);
        }

        // Verify token via external HTTP call
        $url = "https://oauth2.googleapis.com/tokeninfo?id_token=" . urlencode($googleToken);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $responseJson = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $googleData = json_decode($responseJson, true);

        if ($httpCode !== 200 || isset($googleData['error'])) {
            Response::error('Invalid Google token', 401);
        }

        $email = $googleData['email'] ?? '';
        $googleId = $googleData['sub'] ?? '';
        $name = $googleData['name'] ?? 'Google User';

        if (!$email || !$googleId) {
            Response::error('Invalid Google token data', 401);
        }

        // Find or create user
        $stmt = $this->db->prepare("SELECT id, name, email, google_id, role, auth_type FROM users WHERE google_id = ? OR email = ?");
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
                'auth_type' => $existingUser['auth_type']
            ];
        } else {
            // INSERT
            $role = 'customer';
            $stmt = $this->db->prepare("
                INSERT INTO users (uuid, name, email, google_id, auth_type, role, created_at)
                VALUES (UUID(), ?, ?, ?, 'google', ?, NOW())
            ");
            $stmt->execute([$name, $email, $googleId, $role]);
            $userId = $this->db->lastInsertId();

            $userResponse = [
                'id' => $userId,
                'name' => $name,
                'email' => $email,
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

    public function guestLogin() {
        $guestNumber = rand(100000, 999999);
        $name = 'Guest_' . $guestNumber;
        
        $stmt = $this->db->prepare("
            INSERT INTO users (uuid, name, auth_type, role, is_guest, guest_expires_at, created_at)
            VALUES (UUID(), ?, 'guest', 'customer', 1, DATE_ADD(NOW(), INTERVAL 24 HOUR), NOW())
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
