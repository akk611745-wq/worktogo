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
    // PB12: per-user upload throttle (count/spam guard).
    require_once SYSTEM_ROOT . '/core/helpers/RateLimiter.php';
    if (!RateLimiter::check('media_upload_' . $uid, 40, 3600)) { Response::error('Too many uploads. Please try again later.', 429); exit; }

    if (empty($_FILES['file']) || !is_array($_FILES['file']) || ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        Response::error('No file uploaded (field "file").', 422); exit;
    }
    $f = $_FILES['file'];
    $size = (int)($f['size'] ?? 0);
    // Max size is admin-config (commerce_media_max_mb, default 5).
    $maxMb = 5;
    try { $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key='commerce_media_max_mb' LIMIT 1"); $s->execute(); $v = $s->fetchColumn(); if ($v !== false && is_numeric($v) && (float)$v > 0) $maxMb = (float)$v; } catch (\Throwable $e) {}
    if ($size <= 0 || $size > $maxMb * 1024 * 1024) { Response::error('File must be 1 byte–' . rtrim(rtrim((string)$maxMb, '0'), '.') . ' MB.', 422); exit; }

    $allowed = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf',
    ];
    // PB12: trust the SERVER-detected MIME from the file bytes, never the browser-supplied type.
    $mime = '';
    if (function_exists('finfo_open')) { $fi = finfo_open(FILEINFO_MIME_TYPE); if ($fi) { $mime = strtolower((string)finfo_file($fi, $f['tmp_name'])); finfo_close($fi); } }
    if ($mime === '' && function_exists('mime_content_type')) { $mime = strtolower((string)@mime_content_type($f['tmp_name'])); }
    if (!isset($allowed[$mime])) { Response::error('Only JPG, PNG, WEBP or PDF allowed.', 422); exit; }
    $ext = $allowed[$mime];
    $mediaType = $mime === 'application/pdf' ? 'doc' : 'image';
    // Content sanity: images must actually decode; PDFs must start with %PDF-.
    if ($mediaType === 'image') {
        $info = @getimagesize($f['tmp_name']);
        if ($info === false) { Response::error('That image could not be read. Please upload a real photo.', 422); exit; }
    } else {
        $head = @file_get_contents($f['tmp_name'], false, null, 0, 5);
        if ($head !== '%PDF-') { Response::error('That PDF could not be read.', 422); exit; }
    }

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
