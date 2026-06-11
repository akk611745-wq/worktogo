<?php
// ============================================================
//  PATCH /api/auth/profile
//  Updates the authenticated user's display name and address.
//  Requires valid JWT. Only name and address are user-editable.
// ============================================================

require_once HEART_ROOT . '/middleware/AuthMiddleware.php';
$auth = AuthMiddleware::require();

$input = json_decode(file_get_contents('php://input'), true) ?? [];

// ── Validate ──────────────────────────────────────────────────
$name    = trim((string)($input['name']    ?? ''));
$address = trim((string)($input['address'] ?? ''));

if ($name !== '' && mb_strlen($name) > 100) {
    Response::validation('Name must be 100 characters or fewer.', []);
}
if ($address !== '' && mb_strlen($address) > 500) {
    Response::validation('Address must be 500 characters or fewer.', []);
}
if ($name === '' && $address === '') {
    Response::validation('Provide at least name or address to update.', []);
}

// ── Build SET clause from only the fields that were sent ──────
$sets   = [];
$params = [':id' => (int)$auth['user_id']];

if ($name !== '') {
    $sets[]          = 'name = :name';
    $params[':name'] = $name;
}

// address column added by migration 2026_06_10_002; skip if absent
if ($address !== '') {
    try {
        $colCheck = $db->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'users'
               AND COLUMN_NAME = 'address'"
        );
        if ((int)$colCheck->fetchColumn() > 0) {
            $sets[]             = 'address = :address';
            $params[':address'] = $address;
        }
    } catch (\Throwable $ignored) {}
}

if (empty($sets)) {
    Response::validation('No updatable fields found.', []);
}

try {
    $sql  = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id AND deleted_at IS NULL';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    // Return the fresh row so the client can update its cache
    $row = $db->prepare(
        'SELECT id, name, phone, email, role,
                COALESCE(address, \'\') AS address
         FROM users WHERE id = :id LIMIT 1'
    );
    $row->execute([':id' => (int)$auth['user_id']]);
    $user = $row->fetch(PDO::FETCH_ASSOC);

    Response::success(['user' => $user], 200, 'Profile updated.');

} catch (\PDOException $e) {
    Logger::error('Profile update DB error', ['error' => $e->getMessage()]);
    Response::serverError('Could not update profile. Please try again.');
}
