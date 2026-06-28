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
$data['email'] = isset($data['email']) && trim((string)$data['email']) !== '' ? trim((string)$data['email']) : null;

// Resolves what to do with an existing account hit during registration —
// either terminates the request with a specific, accurate error message
// (never a generic failure), or returns the user_id/name/phone to reuse.
// Same pattern as the email-reuse fix in AuthController::registerEmail
// (0c12617): trust an OTP-only account's identity (no password to check),
// otherwise require the correct password before reusing someone's account.
function _registerResolveExisting(PDO $db, array $existingUser, string $role, string $password): array
{
    $existingRole = (string)$existingUser['role'];

    if (str_starts_with($existingRole, 'vendor_')) {
        $vStmt = $db->prepare("SELECT status FROM vendors WHERE user_id = ? LIMIT 1");
        $vStmt->execute([$existingUser['id']]);
        $vendorStatus = $vStmt->fetchColumn();
        Response::validation($vendorStatus
            ? "You have already applied as a vendor (status: {$vendorStatus}). Please login instead."
            : 'You have already applied as a vendor. Please login instead.');
    }

    if ($existingRole === ROLE_ADMIN) {
        Response::validation('This account already exists. Please login instead.');
    }

    // existingRole === customer from here on
    if (!str_starts_with($role, 'vendor_')) {
        Response::validation('This account is already registered. Please login instead.');
    }

    if (empty($existingUser['password'])) {
        // OTP-only account has no password to verify against — trust the
        // phone/email match and set the password they just chose so they
        // can also log in with phone/email + password.
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $db->prepare("UPDATE users SET password = ? WHERE id = ?")
           ->execute([$hash, $existingUser['id']]);
    } elseif (!password_verify($password, (string)$existingUser['password'])) {
        Response::validation('This account already exists, but the password is incorrect. Please login instead.');
    }

    if (($existingUser['status'] ?? '') !== 'active') {
        Response::validation('Account is inactive');
    }

    $db->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$role, $existingUser['id']]);

    return [
        'id'    => (int)$existingUser['id'],
        'name'  => $existingUser['name'],
        'phone' => $existingUser['phone'] ?? null,
    ];
}

try {
    $role = $data['role'] ?? ROLE_CUSTOMER;

    // Match an existing account by phone first, then by email — covers
    // "existing customer applying as vendor" whichever identity they typed
    // matches, instead of only catching the phone case and dead-ending on
    // email with no reuse path.
    $check = $db->prepare("SELECT id, name, phone, password, role, status FROM users WHERE phone = ? LIMIT 1");
    $check->execute([$data['phone']]);
    $existingUser = $check->fetch(PDO::FETCH_ASSOC);

    if (!$existingUser && $data['email'] !== null) {
        $emailCheck = $db->prepare("SELECT id, name, phone, password, role, status FROM users WHERE email = ? LIMIT 1");
        $emailCheck->execute([$data['email']]);
        $existingUser = $emailCheck->fetch(PDO::FETCH_ASSOC);
    }

    if ($existingUser) {
        $resolved = _registerResolveExisting($db, $existingUser, $role, $data['password']);
        $userId = $resolved['id'];
        $data['name'] = $resolved['name'];
        // Reflect the phone actually on file (the account may have been
        // matched by email with a different/no phone on record) rather
        // than whatever was typed into this form, since we never write a
        // new phone onto a reused account.
        if ($resolved['phone']) {
            $data['phone'] = $resolved['phone'];
        }
    } else {
        // Brand new identity on both phone and email — create fresh.
        $hash = password_hash($data['password'], PASSWORD_BCRYPT);

        $stmt = $db->prepare(
            "INSERT INTO users (uuid, name, phone, email, password, role, status, created_at, updated_at)
             VALUES (UUID(), :name, :phone, :email, :password, :role, 'active', NOW(), NOW())"
        );

        $stmt->execute([
            ':name'     => $data['name'],
            ':phone'    => $data['phone'],
            ':email'    => $data['email'],
            ':password' => $hash,
            ':role'     => $role,
        ]);

        $userId = (int) $db->lastInsertId();
    }

    // Vendor profile creation — idempotent, so a repeat call (or a
    // customer-to-vendor upgrade that already has a vendor row) never
    // errors, it just leaves the existing application alone.
    if (str_starts_with($role, 'vendor_')) {
        $existingVendor = $db->prepare("SELECT id FROM vendors WHERE user_id = ? LIMIT 1");
        $existingVendor->execute([$userId]);
        if (!$existingVendor->fetchColumn()) {
            $type = ($role === ROLE_VENDOR_SERVICE) ? 'service' : 'shopping';
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $data['business_name']), '-'));
            $typeColumn = ServiceVendorEligibility::vendorTypeColumn($db);

            $db->prepare("INSERT INTO vendors (user_id, business_name, slug, {$typeColumn}, status) VALUES (?, ?, ?, ?, 'pending')")
               ->execute([$userId, $data['business_name'], $slug . '-' . $userId, $type]);
        }
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
            'email' => $data['email'],
            'role'  => $role,
        ],
    ], 'Account created successfully');

} catch (PDOException $e) {
    Logger::error('Registration DB error', ['error' => $e->getMessage()]);
    Response::serverError('Registration failed. Please try again.');
}
