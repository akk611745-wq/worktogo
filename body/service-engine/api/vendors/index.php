<?php
/**
 * WorkToGo — Vendors Module
 *
 * GET /api/vendors          → paginated list of active vendors
 * GET /api/vendors/{id}     → vendor detail
 * GET /api/vendor/jobs      → vendor's own jobs (vendor/admin JWT required)
 * GET /api/vendor/jobs/{id} → single job detail (ownership enforced)
 */

// ── Centralized Boot ──────────────────────────────────────────
require_once dirname(dirname(dirname(__DIR__))) . '/core/helpers/Database.php';
require_once dirname(dirname(dirname(__DIR__))) . '/core/helpers/Response.php';
require_once dirname(dirname(dirname(__DIR__))) . '/core/helpers/JWT.php';
require_once dirname(dirname(dirname(__DIR__))) . '/heart/middleware/AuthMiddleware.php';

$db = getDB();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

/**
 * Resolve the vendors.id for the authenticated user.
 * Terminates with 403 if no vendor profile exists.
 */
function resolveVendorId(PDO $db, int $userId): int
{
    $stmt = $db->prepare(
        "SELECT id FROM vendors WHERE user_id = ? AND deleted_at IS NULL LIMIT 1"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        Response::forbidden('No vendor profile found for your account');
    }
    return (int)$row['id'];
}

// ── GET /api/vendors ──────────────────────────────────────────────────────────
if ($method === 'GET' && $uri === '/api/vendors') {
    $type   = $_GET['type']   ?? null;
    $search = $_GET['search'] ?? null;
    $page   = max(1, (int)($_GET['page']  ?? 1));
    $limit  = min(50, (int)($_GET['limit'] ?? 20));
    $offset = ($page - 1) * $limit;

    $where = ["v.status = 'active'", "v.deleted_at IS NULL"];
    $bind  = [];

    if ($type) {
        $where[]       = 'v.type = :type';
        $bind[':type'] = $type;
    }
    if ($search) {
        $where[]         = '(v.business_name LIKE :search OR v.description LIKE :search)';
        $bind[':search'] = '%' . $search . '%';
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $where);

    // Count total matching rows
    $countStmt = $db->prepare("SELECT COUNT(*) FROM vendors v $whereSQL");
    $countStmt->execute($bind);
    $total = (int)$countStmt->fetchColumn();

    // Fetch paginated rows
    $sql = "SELECT v.id, v.business_name, v.slug, v.description, v.logo_url,
                   v.type, v.status, v.rating, v.total_reviews, v.commission_rate,
                   v.created_at,
                   u.name AS owner_name,
                   a.city, a.state
            FROM vendors v
            LEFT JOIN users u ON u.id = v.user_id
            LEFT JOIN addresses a ON a.id = v.address_id
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

// ── GET /api/vendors/{id} ─────────────────────────────────────────────────────
if ($method === 'GET' && preg_match('#^/api/vendors/(\d+)$#', $uri, $m)) {
    $stmt = $db->prepare(
        "SELECT v.id, v.business_name, v.slug, v.description, v.logo_url,
                v.type, v.status, v.rating, v.total_reviews, v.commission_rate,
                v.created_at, v.updated_at,
                u.name AS owner_name,
                a.line1, a.line2, a.city, a.state, a.postal_code, a.lat, a.lng
         FROM vendors v
         LEFT JOIN users u ON u.id = v.user_id
         LEFT JOIN addresses a ON a.id = v.address_id
         WHERE v.id = ? AND v.deleted_at IS NULL
         LIMIT 1"
    );
    $stmt->execute([(int)$m[1]]);
    $vendor = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$vendor) Response::notFound('Vendor');

    Response::success(['vendor' => $vendor]);
}

// ── GET /api/vendor/jobs ──────────────────────────────────────────────────────
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

// ── GET /api/vendor/jobs/{id} ─────────────────────────────────────────────────
// FIX: ownership check uses resolved vendors.id, not raw JWT user_id.
if ($method === 'GET' && preg_match('#^/api/vendor/jobs/(\d+)$#', $uri, $m)) {
    $auth  = AuthMiddleware::requireRole(ROLE_VENDOR, ROLE_ADMIN);
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
    if ($auth['role'] === ROLE_VENDOR) {
        $vendorId = resolveVendorId($db, (int)$auth['user_id']);
        if ((int)$job['vendor_id'] !== $vendorId) {
            Response::forbidden('Job not assigned to your account');
        }
    }

    Response::success(['job' => $job]);
}

Response::error('Endpoint not found', 404);
