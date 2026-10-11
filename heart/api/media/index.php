<?php
// ============================================================================
//  /api/media/* — minimal media upload  (S18 / Step 5: KYC docs)
// ----------------------------------------------------------------------------
//  The KYC forms (seller + rider) need to upload documents (shop photo, ID/
//  address proof, licence, RC, rider photo). This is a small, additive upload
//  endpoint that saves a file locally and records a `media` row, returning the
//  media_id the KYC payload stores. Any authenticated user may upload their own
//  KYC docs. No engine edit.
//    POST /api/media/upload   (multipart: field `file`, optional `purpose`)
//  Dispatched from heart/index.php on /api/media. Uses $db/$method/$uri.
// ============================================================================

$auth = AuthMiddleware::require();
$uid  = (int)($auth['user_id'] ?? 0);

if ($method === 'POST' && $uri === '/api/media/upload') {
    if (empty($_FILES['file']) || !is_array($_FILES['file']) || ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        Response::error('No file uploaded (field "file").', 422); exit;
    }
    $f = $_FILES['file'];
    $size = (int)($f['size'] ?? 0);
    if ($size <= 0 || $size > 5 * 1024 * 1024) { Response::error('File must be 1 byte–5 MB.', 422); exit; }

    $mime = strtolower((string)($f['type'] ?? ''));
    $allowed = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf',
    ];
    if (!isset($allowed[$mime])) { Response::error('Only JPG, PNG, WEBP or PDF allowed.', 422); exit; }
    $ext = $allowed[$mime];
    $mediaType = $mime === 'application/pdf' ? 'doc' : 'image';

    // Save under uploads/kyc/<year-month>/ (created on demand).
    $root = defined('SYSTEM_ROOT') ? SYSTEM_ROOT : dirname(__DIR__, 2);
    $sub  = 'uploads/kyc/' . date('Y-m');
    $absDir = $root . '/' . $sub;
    if (!is_dir($absDir)) { @mkdir($absDir, 0775, true); }
    try { $name = bin2hex(random_bytes(10)) . '.' . $ext; } catch (\Throwable $e) { $name = uniqid('kyc', true) . '.' . $ext; }
    $relPath = $sub . '/' . $name;
    $absPath = $root . '/' . $relPath;
    if (!@move_uploaded_file($f['tmp_name'], $absPath)) { Response::error('Could not store the file.', 500); exit; }

    $ownerType = (($auth['role'] ?? '') === ROLE_VENDOR_SHOPPING) ? 'vendor' : 'user';
    $purpose = isset($_POST['purpose']) ? substr(preg_replace('/[^a-z_]/', '', strtolower((string)$_POST['purpose'])), 0, 32) : 'kyc';
    if ($purpose === '') $purpose = 'kyc';
    $publicUrl = '/' . $relPath;

    try {
        $db->prepare(
            "INSERT INTO media (owner_type, owner_id, media_type, purpose, storage_provider, storage_key, public_url, mime_type, file_size_bytes, created_at)
             VALUES (?,?,?,?, 'local', ?, ?, ?, ?, NOW())"
        )->execute([$ownerType, $uid, $mediaType, $purpose, $relPath, $publicUrl, $mime, $size]);
        $mediaId = (int)$db->lastInsertId();
    } catch (\Throwable $e) {
        error_log('[api/media/upload] ' . $e->getMessage());
        @unlink($absPath);
        Response::error('Could not record the upload.', 500); exit;
    }

    Response::success(['media_id' => $mediaId, 'public_url' => $publicUrl, 'media_type' => $mediaType], 201, 'Uploaded');
    exit;
}

Response::notFound('Media endpoint');
