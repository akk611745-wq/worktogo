<?php
// ============================================================
//  POST /api/auth/register
//  Creates a new customer account.
// ============================================================

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if (!RateLimiter::check('register', 3, 300)) {
    Response::tooManyRequests('Too many registration attempts. Please try again later.');
}

$v = Validator::make($input, [
    'name'     => 'required|string|min:2|max:100',
    'phone'    => 'required|phone',
    'email'    => 'nullable|email|max:191',
    'password' => 'required|min:8|max:128',
    'role'     => 'nullable|in:customer,vendor_service,vendor_shopping',
    'business_name' => 'required_if:role,vendor_service,vendor_shopping|string|max:200',
]);

if ($v->fails()) {
    Response::validation($v->firstError(), $v->errors());
}

$data = $v->validated();

try {
    // Check phone uniqueness
    $check = $db->prepare("SELECT id FROM users WHERE phone = ? LIMIT 1");
    $check->execute([$data['phone']]);

    if ($check->fetchColumn()) {
        Response::validation('This phone number is already registered');
    }

    // Check email uniqueness (if provided)
    if (!empty($data['email'])) {
        $emailCheck = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $emailCheck->execute([$data['email']]);
        if ($emailCheck->fetchColumn()) {
            Response::validation('This email address is already registered');
        }
    }

    // Hash password
    $hash = password_hash($data['password'], PASSWORD_BCRYPT);
    $role = $data['role'] ?? ROLE_CUSTOMER;

    // Insert user
    $stmt = $db->prepare(
        "INSERT INTO users (uuid, name, phone, email, password, role, status, created_at, updated_at)
         VALUES (UUID(), :name, :phone, :email, :password, :role, 'active', NOW(), NOW())"
    );

    $stmt->execute([
        ':name'     => $data['name'],
        ':phone'    => $data['phone'],
        ':email'    => $data['email'] ?: null,
        ':password' => $hash,
        ':role'     => $role,
    ]);

    $userId = (int) $db->lastInsertId();

    // Vendor profile creation
    if (str_starts_with($role, 'vendor_')) {
        $type = ($role === ROLE_VENDOR_SERVICE) ? 'service' : 'shopping';
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $data['business_name']), '-'));
        
        $db->prepare("INSERT INTO vendors (user_id, business_name, slug, vendor_type, status) VALUES (?, ?, ?, ?, 'pending')")
           ->execute([$userId, $data['business_name'], $slug . '-' . $userId, $type]);
    }

    // Issue JWT
    $token = JWT::encode([
        'user_id' => $userId,
        'role'    => $role,
        'phone'   => $data['phone'],
    ], JWT_SECRET, JWT_EXPIRY);

    RateLimiter::clear('register');

    Logger::info('User registered', ['user_id' => $userId, 'phone' => $data['phone']]);

    Response::created([
        'token'      => $token,
        'expires_in' => JWT_EXPIRY,
        'user'       => [
            'id'    => $userId,
            'name'  => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?: null,
            'role'  => $role,
        ],
    ], 'Account created successfully');

} catch (PDOException $e) {
    Logger::error('Registration DB error', ['error' => $e->getMessage()]);
    Response::serverError('Registration failed. Please try again.');
}
