<?php
/**
 * WorkToGo â€” Vendors Module
 *
 * GET /api/vendors          â†’ paginated list of active vendors
 * GET /api/vendors/{id}     â†’ vendor detail
 * GET /api/vendor/jobs      â†’ vendor's own jobs (vendor/admin JWT required)
 * GET /api/vendor/jobs/{id} â†’ single job detail (ownership enforced)
 */

// â”€â”€ Centralized Boot â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Incorrect path: dirname(dirname(dirname(__DIR__))) . '/core/...' -> body/core/...
// Corrected path: dirname(dirname(dirname(dirname(__DIR__)))) . '/core/...' -> /core/...
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/core/helpers/Database.php';
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/core/helpers/Response.php';
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/core/helpers/JWT.php';
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/core/helpers/ServiceVendorEligibility.php';
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/heart/middleware/AuthMiddleware.php';
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/core/lib/PushSubscription.php';
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/core/lib/FcmNotifier.php';

$db = getDB();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
if (str_starts_with($uri, '/heart')) { $uri = substr($uri, 6) ?: '/' ; }

/**
 * Resolve the vendors.id for the authenticated user.
 * Terminates with 403 if no vendor profile exists.
 */
function resolveVendorId(PDO $db, int $userId): int
{
    // FIX: vendors table has no deleted_at column; filter by status instead
    $stmt = $db->prepare(
        "SELECT id FROM vendors WHERE user_id = ? AND status != 'rejected' LIMIT 1"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        Response::forbidden('No vendor profile found for your account');
    }
    return (int)$row['id'];
}

// â”€â”€ POST /api/vendors â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($method === 'POST' && $uri === '/api/vendors') {
    $auth  = AuthMiddleware::requireRole(ROLE_VENDOR_SERVICE, ROLE_ADMIN, ROLE_CUSTOMER);
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $businessName = trim($input['business_name'] ?? '');
    $phone        = isset($input['phone']) ? trim((string)$input['phone']) : null;
    $phone        = $phone !== '' ? $phone : null;
    $type         = trim($input['type'] ?? $input['category'] ?? '');

    if ($businessName === '' || $type === '') {
        Response::error('business_name, phone, and type/category are required', 400);
    }

    if (!in_array($type, ['service', 'shopping'])) {
        Response::error('type must be either service or shopping', 400);
    }

    $userId = (int)$auth['user_id'];
    $stmtPhone = $db->prepare("SELECT phone FROM users WHERE id = ? LIMIT 1");
    $stmtPhone->execute([$userId]);
    $phone = $stmtPhone->fetchColumn() ?: null;

    // Check if vendor already exists
    $stmt = $db->prepare("SELECT id, business_name, type, status, created_at, updated_at FROM vendors WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $existingVendor = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existingVendor) {
        Response::error(
            'Vendor application already exists with status: ' . ($existingVendor['status'] ?? 'unknown'),
            409,
            'VENDOR_ALREADY_EXISTS',
            ['vendor' => $existingVendor]
        );
    }

    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $businessName), '-'));
    
    // Ensure slug uniqueness
    $stmt = $db->prepare("SELECT COUNT(*) FROM vendors WHERE slug = ?");
    $stmt->execute([$slug]);
    if ($stmt->fetchColumn() > 0) {
        $slug .= '-' . time();
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            "INSERT INTO vendors (user_id, business_name, slug, type, status)
             VALUES (?, ?, ?, ?, 'pending')"
        );
        $stmt->execute([$userId, $businessName, $slug, $type]);
        $vendorId = $db->lastInsertId();

        if ($phone !== '' && $phone !== null) {
            $stmt = $db->prepare("UPDATE users SET phone = ? WHERE id = ?");
            $stmt->execute([$phone, $userId]);
        }

        $db->commit();

        FcmNotifier::notifyRole(
            $db,
            ROLE_ADMIN,
            'New Vendor Registration',
            "{$businessName} has applied to become a vendor and is awaiting approval.",
            ['url' => '/admin/vendors.html', 'vendor_id' => (string)$vendorId, 'type' => 'vendor_registered']
        );

        $stmt = $db->prepare("SELECT * FROM vendors WHERE id = ?");
        $stmt->execute([$vendorId]);
        $vendor = $stmt->fetch(PDO::FETCH_ASSOC);

        Response::created(['vendor' => $vendor]);
    } catch (Exception $e) {
        $db->rollBack();
        Response::error('Failed to create vendor profile: ' . $e->getMessage(), 500);
    }
}

// â”€â”€ GET /api/vendors â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($method === 'GET' && $uri === '/api/vendors') {
    $type   = $_GET['type']   ?? null;
    $search = $_GET['search'] ?? null;
    $page   = max(1, (int)($_GET['page']  ?? 1));
    $limit  = min(50, (int)($_GET['limit'] ?? 20));
    $offset = ($page - 1) * $limit;

    // Production schema uses vendors.type; no deleted_at, no description, no logo_url, no address_id
    $where = ["v.status = 'active'"];
    $bind  = [];
    $typeColumn = ServiceVendorEligibility::vendorTypeColumn($db);

    if ($type) {
        $where[]              = "v.{$typeColumn} = :type";
        $bind[':type']        = $type;
    }
    if ($search) {
        $where[]              = 'v.business_name LIKE :search';
        $bind[':search']      = '%' . $search . '%';
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $where);

    // Count total matching rows
    $countStmt = $db->prepare("SELECT COUNT(*) FROM vendors v $whereSQL");
    $countStmt->execute($bind);
    $total = (int)$countStmt->fetchColumn();

    // FIX: Only select columns that actually exist in the vendors table
    $serviceLocalitiesSelect = ServiceVendorEligibility::tableHasColumn($db, 'vendors', 'service_localities') ? 'v.service_localities' : 'NULL AS service_localities';
    $serviceAreaNotesSelect = ServiceVendorEligibility::tableHasColumn($db, 'vendors', 'service_area_notes') ? 'v.service_area_notes' : 'NULL AS service_area_notes';
    $sql = "SELECT v.id, v.business_name, v.slug, v.{$typeColumn} AS type, v.{$typeColumn} AS vendor_type,
                   v.status, v.rating, v.commission_rate, v.is_online,
                   v.lat, v.lng, {$serviceLocalitiesSelect}, {$serviceAreaNotesSelect}, v.created_at,
                   v.category_id, c.name AS category_name,
                   u.name AS owner_name, u.phone AS owner_phone, u.email AS owner_email
            FROM vendors v
            LEFT JOIN users u ON u.id = v.user_id
            LEFT JOIN categories c ON c.id = v.category_id
            $whereSQL
            ORDER BY v.rating DESC, v.created_at DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($sql);
    foreach ($bind as $k => $val) {
        $stmt->bindValue($k, $val);
    }
    $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    Response::success([
        'vendors'    => $vendors,
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int)ceil($total / max($limit, 1)),
        ],
    ]);
}

// â”€â”€ GET /api/vendors/{id} â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($method === 'GET' && preg_match('#^/api/vendors/(\d+)$#', $uri, $m)) {
    // FIX: Only select columns that actually exist in the vendors table
    $stmt = $db->prepare(
        "SELECT v.id, v.business_name, v.slug, v." . ServiceVendorEligibility::vendorTypeColumn($db) . " AS type, v." . ServiceVendorEligibility::vendorTypeColumn($db) . " AS vendor_type,
                v.status, v.rating, v.commission_rate, v.is_online,
                v.lat, v.lng, " . (ServiceVendorEligibility::tableHasColumn($db, 'vendors', 'service_localities') ? 'v.service_localities' : 'NULL AS service_localities') . ",
                " . (ServiceVendorEligibility::tableHasColumn($db, 'vendors', 'service_area_notes') ? 'v.service_area_notes' : 'NULL AS service_area_notes') . ",
                v.created_at, v.updated_at,
                u.name AS owner_name, u.phone AS owner_phone, u.email AS owner_email
         FROM vendors v
         LEFT JOIN users u ON u.id = v.user_id
         WHERE v.id = ?
         LIMIT 1"
    );
    $stmt->execute([(int)$m[1]]);
    $vendor = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$vendor) Response::notFound('Vendor');

    Response::success(['vendor' => $vendor]);
}

// â”€â”€ GET /api/vendor/jobs â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// FIX: vendor_id resolved from vendors table via JWT user_id (not used as raw vendor_id).
if ($method === 'GET' && $uri === '/api/vendor/jobs') {
    $auth   = AuthMiddleware::requireRole(ROLE_VENDOR_SERVICE, ROLE_ADMIN);
    $status = $_GET['status'] ?? null;

    // Admin may override vendor_id via query param; vendors always use their own
    if ($auth['role'] === ROLE_ADMIN && isset($_GET['vendor_id'])) {
        $vendorId = (int)$_GET['vendor_id'];
    } else {
        $vendorId = resolveVendorId($db, (int)$auth['user_id']);
    }

    $where = ['j.vendor_id = :vid'];
    $bind  = [':vid' => $vendorId];

    if ($status) {
        $where[]         = 'j.status = :status';
        $bind[':status'] = $status;
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $where);

    $stmt = $db->prepare(
        "SELECT j.*, b.booking_number, b.scheduled_at, b.notes AS booking_notes,
                b.address AS customer_address, b.locality AS customer_locality,
                s.name AS service_name, u.name AS customer_name, u.phone AS customer_phone
         FROM jobs j
         LEFT JOIN bookings b ON b.id = j.booking_id
         LEFT JOIN services s ON s.id = b.service_id
         LEFT JOIN users u ON u.id = j.user_id
         $whereSQL
         ORDER BY j.created_at DESC"
    );
    $stmt->execute($bind);
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    Response::success(['jobs' => $jobs, 'total' => count($jobs)]);
}

// â”€â”€ GET /api/vendor/jobs/{id} â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// FIX: ownership check uses resolved vendors.id, not raw JWT user_id.
if ($method === 'GET' && preg_match('#^/api/vendor/jobs/(\d+)$#', $uri, $m)) {
    $auth  = AuthMiddleware::requireRole(ROLE_VENDOR_SERVICE, ROLE_ADMIN);
    $jobId = (int)$m[1];

    $stmt = $db->prepare(
        "SELECT j.*, b.booking_number, b.scheduled_at, b.notes AS booking_notes,
                s.name AS service_name, s.base_price,
                u.name AS customer_name, u.phone AS customer_phone,
                v.business_name AS vendor_name
         FROM jobs j
         LEFT JOIN bookings b ON b.id = j.booking_id
         LEFT JOIN services s ON s.id = b.service_id
         LEFT JOIN users u ON u.id = j.user_id
         LEFT JOIN vendors v ON v.id = j.vendor_id
         WHERE j.id = ?
         LIMIT 1"
    );
    $stmt->execute([$jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$job) Response::notFound('Job');

    // Enforce vendor ownership via vendors table
    if ($auth['role'] === ROLE_VENDOR_SERVICE) {
        $vendorId = resolveVendorId($db, (int)$auth['user_id']);
        if ((int)$job['vendor_id'] !== $vendorId) {
            Response::forbidden('Job not assigned to your account');
        }
    }

    Response::success(['job' => $job]);
}

// ── GET /api/vendor/profile ──────────────────────────────────────────────────────
if ($method === 'GET' && $uri === '/api/vendor/profile') {
    $auth   = AuthMiddleware::requireRole(ROLE_VENDOR_SERVICE, ROLE_VENDOR_SHOPPING);
    $userId = (int)$auth['user_id'];

    $typeCol = ServiceVendorEligibility::vendorTypeColumn($db);
    $descSel = ServiceVendorEligibility::tableHasColumn($db, 'vendors', 'description')          ? 'v.description'          : 'NULL AS description';
    $logoSel = ServiceVendorEligibility::tableHasColumn($db, 'vendors', 'logo_url')              ? 'v.logo_url'             : 'NULL AS logo_url';
    $catSel  = ServiceVendorEligibility::tableHasColumn($db, 'vendors', 'category_id')           ? 'v.category_id'         : 'NULL AS category_id';
    $expSel  = ServiceVendorEligibility::tableHasColumn($db, 'vendors', 'experience_years')      ? 'v.experience_years'    : 'NULL AS experience_years';
    $verSel  = ServiceVendorEligibility::tableHasColumn($db, 'vendors', 'is_verified')            ? 'v.is_verified'         : 'NULL AS is_verified';
    $localitiesSel = ServiceVendorEligibility::tableHasColumn($db, 'vendors', 'service_localities') ? 'v.service_localities' : 'NULL AS service_localities';

    $stmt = $db->prepare(
        "SELECT v.id, v.business_name, v.{$typeCol} AS type, v.status, v.rating,
                {$descSel}, {$logoSel}, {$catSel}, {$expSel}, {$verSel}, {$localitiesSel},
                v.created_at, v.updated_at,
                u.name, u.phone, u.email, u.role
         FROM vendors v
         LEFT JOIN users u ON u.id = v.user_id
         WHERE v.user_id = ? AND v.status != 'rejected'
         LIMIT 1"
    );
    $stmt->execute([$userId]);
    $vendor = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$vendor) Response::forbidden('No vendor profile found for your account');

    Response::success($vendor);
}

// ── PATCH /api/vendor/profile ────────────────────────────────────────────────────
if ($method === 'PATCH' && $uri === '/api/vendor/profile') {
    $auth   = AuthMiddleware::requireRole(ROLE_VENDOR_SERVICE, ROLE_VENDOR_SHOPPING);
    $userId = (int)$auth['user_id'];
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];

    $name        = trim((string)($input['name'] ?? ''));
    $phone       = trim((string)($input['phone'] ?? ''));
    $description = array_key_exists('description', $input) ? trim((string)$input['description']) : null;
    $categoryId  = array_key_exists('category_id', $input) ? (int)$input['category_id'] : null;
    $logoUrl     = array_key_exists('logo_url', $input)    ? trim((string)$input['logo_url'])    : null;
    $experienceYears = null;
    if (array_key_exists('experience_years', $input) && $input['experience_years'] !== '' && $input['experience_years'] !== null) {
        $experienceYears = max(1, min(50, (int)$input['experience_years']));
    }
    $localities  = null;
    if (array_key_exists('service_localities', $input)) {
        $raw        = $input['service_localities'];
        $localities = is_array($raw) ? json_encode($raw, JSON_UNESCAPED_UNICODE) : trim((string)$raw);
    }

    if ($name === '') Response::validation('Name is required');

    $db->prepare("UPDATE users SET name = ?, phone = ?, updated_at = NOW() WHERE id = ?")
       ->execute([$name, $phone ?: null, $userId]);

    $vendorSets   = ['business_name = ?', 'updated_at = NOW()'];
    $vendorParams = [$name];

    if ($description !== null && ServiceVendorEligibility::tableHasColumn($db, 'vendors', 'description')) {
        $vendorSets[]   = 'description = ?';
        $vendorParams[] = $description;
    }
    if ($categoryId !== null && $categoryId > 0 && ServiceVendorEligibility::tableHasColumn($db, 'vendors', 'category_id')) {
        $vendorSets[]   = 'category_id = ?';
        $vendorParams[] = $categoryId;
    }
    if ($logoUrl !== null && ServiceVendorEligibility::tableHasColumn($db, 'vendors', 'logo_url')) {
        $vendorSets[]   = 'logo_url = ?';
        $vendorParams[] = $logoUrl ?: null;
    }
    if ($experienceYears !== null && ServiceVendorEligibility::tableHasColumn($db, 'vendors', 'experience_years')) {
        $vendorSets[]   = 'experience_years = ?';
        $vendorParams[] = $experienceYears;
    }
    if ($localities !== null && ServiceVendorEligibility::tableHasColumn($db, 'vendors', 'service_localities')) {
        $vendorSets[]   = 'service_localities = ?';
        $vendorParams[] = $localities ?: null;
    }
    $vendorParams[] = $userId;

    $db->prepare("UPDATE vendors SET " . implode(', ', $vendorSets) . " WHERE user_id = ?")
       ->execute($vendorParams);

    // Keep vendor_categories in sync with the vendor's selected category.
    // DELETE + INSERT IGNORE replaces the single-category selection atomically.
    if ($categoryId !== null && $categoryId > 0
        && ServiceVendorEligibility::tableHasColumn($db, 'vendor_categories', 'vendor_id')
    ) {
        $vendorId = resolveVendorId($db, $userId);
        $db->prepare("DELETE FROM vendor_categories WHERE vendor_id = ?")->execute([$vendorId]);
        $db->prepare("INSERT IGNORE INTO vendor_categories (vendor_id, category_id) VALUES (?, ?)")
           ->execute([$vendorId, $categoryId]);
    }

    Response::success(['updated' => true], 200, 'Profile updated');
}

// ── POST /api/vendor/profile/photo ──────────────────────────────────────────────
if ($method === 'POST' && $uri === '/api/vendor/profile/photo') {
    $auth     = AuthMiddleware::requireRole(ROLE_VENDOR_SERVICE, ROLE_VENDOR_SHOPPING);
    $vendorId = resolveVendorId($db, (int)$auth['user_id']);

    if (empty($_FILES['photo']) || $_FILES['photo']['error'] === UPLOAD_ERR_NO_FILE) {
        Response::error('No file uploaded. Send file as multipart field "photo".', 400);
    }

    require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/core/helpers/UploadHelper.php';

    try {
        $url = UploadHelper::save($_FILES['photo'], 'vendors');
    } catch (Exception $e) {
        Response::error($e->getMessage(), 400);
    }

    $db->prepare("UPDATE vendors SET logo_url = ?, updated_at = NOW() WHERE id = ?")
       ->execute([$url, $vendorId]);

    Response::success(['url' => $url], 200, 'Photo updated');
}

Response::error('Endpoint not found', 404);
