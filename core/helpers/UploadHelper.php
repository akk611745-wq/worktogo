<?php
// ============================================================
//  WorkToGo CORE — Upload Helper
//  Secure file upload validation and storage.
//  FIX 4: MIME validation, extension whitelist, PHP-in-image check.
// ============================================================

class UploadHelper
{
    /** Allowed real MIME types (verified via finfo, not HTTP header) */
    private static array $allowedMime = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /** Allowed file extensions (lowercase) */
    private static array $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];

    /** Max file size: 5 MB */
    private static int $maxSize = 5242880;

    // ── Validate uploaded file ────────────────────────────────
    /**
     * Throws Exception on any validation failure.
     * Call this before move_uploaded_file().
     *
     * @param array $file  Single entry from $_FILES (e.g. $_FILES['photo'])
     * @throws Exception
     */
    public static function validate(array $file): void
    {
        // 1. PHP upload error check
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new Exception('File upload error code: ' . ($file['error'] ?? 'unknown'));
        }

        // 2. Size check
        if ($file['size'] > self::$maxSize) {
            throw new Exception('File too large. Maximum allowed size is 5 MB.');
        }

        // 3. Extension check (client-supplied name — secondary guard only)
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, self::$allowedExt, true)) {
            throw new Exception('Invalid file extension. Allowed: jpg, jpeg, png, webp.');
        }

        // 4. Real MIME check via finfo (reads magic bytes — not HTTP header)
        if (!function_exists('finfo_open')) {
            throw new Exception('Server configuration error: fileinfo extension missing.');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        if (!in_array($mime, self::$allowedMime, true)) {
            throw new Exception('Invalid file content type. Only JPEG, PNG, and WebP images are allowed.');
        }

        // 5. PHP code injection check (ImageMagick / polyglot attack prevention)
        //    Read first 512 bytes — enough to catch <?php and <?= markers
        $handle = fopen($file['tmp_name'], 'rb');
        if ($handle === false) {
            throw new Exception('Cannot read uploaded file.');
        }
        $header = fread($handle, 512);
        fclose($handle);

        if (
            $header !== false && (
                str_contains($header, '<?php') ||
                str_contains($header, '<?=')   ||
                str_contains($header, '<script')
            )
        ) {
            throw new Exception('Malicious content detected in uploaded file.');
        }

        // 6. Confirm it is a valid image (GD sanity check)
        $imageInfo = @getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            throw new Exception('Uploaded file is not a valid image.');
        }
    }

    // ── Save validated file ───────────────────────────────────
    /**
     * Validates then moves the file to the uploads directory.
     * Returns the public URL path (e.g. /uploads/products/abc123.jpg).
     *
     * @param array  $file    Single entry from $_FILES
     * @param string $folder  Sub-folder inside uploads/ (e.g. 'products', 'avatars')
     * @return string         Public URL path
     * @throws Exception
     */
    public static function save(array $file, string $folder = 'general'): string
    {
        self::validate($file);

        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $name = bin2hex(random_bytes(16)) . '.' . $ext;

        // Store outside webroot when possible; fall back to uploads/ inside public_html
        $uploadBase = rtrim(getenv('UPLOAD_PATH') ?: '../uploads', '/');
        $dir  = $uploadBase . '/' . preg_replace('/[^a-z0-9_\-]/i', '', $folder);
        $path = $dir . '/' . $name;

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new Exception('Could not create upload directory.');
            }
        }

        if (!move_uploaded_file($file['tmp_name'], $path)) {
            throw new Exception('Failed to save uploaded file.');
        }

        // Return public URL path
        return '/uploads/' . preg_replace('/[^a-z0-9_\-]/i', '', $folder) . '/' . $name;
    }
}
