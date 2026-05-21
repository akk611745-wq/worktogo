<?php
/**
 * WorkToGo — Services & Bookings Module
 *
 * GET    /api/services                → list active services
 * GET    /api/services/{id}           → service detail
 * POST   /api/service/request         → create booking (+ auto-creates job)
 * GET    /api/service/bookings        → list bookings (scoped by role)
 * GET    /api/service/bookings/{id}   → booking detail with linked job
 * PATCH  /api/jobs/{id}/status        → update job status (vendor/admin)
 */

// ── Centralized Boot ──────────────────────────────────────────
// Incorrect path: dirname(dirname(dirname(__DIR__))) . '/core/...' -> body/core/...
// Corrected path: dirname(dirname(dirname(dirname(__DIR__)))) . '/core/...' -> /core/...
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/core/helpers/Database.php';
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/core/helpers/Response.php';
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/core/helpers/JWT.php';
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/heart/middleware/AuthMiddleware.php';

$db = getDB();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

// Handle internal Heart calls
if (defined('HEART_INTERNAL_INC')) {
    $input = json_decode($GLOBALS['HEART_PAYLOAD'] ?? '{}', true);
}

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

function serviceTableHasColumn(PDO $db, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];

    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    $cache[$key] = ((int)$stmt->fetchColumn()) > 0;
    return $cache[$key];
}

function servicePublicSetting(PDO $db, string $key, mixed $fallback): mixed
{
    try {
        $stmt = $db->prepare("SELECT setting_value, value_type FROM app_settings WHERE setting_key = ? AND is_public = 1 LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return $fallback;
        return match ($row['value_type'] ?? 'text') {
            'number' => (float)$row['setting_value'],
            'boolean' => (bool)$row['setting_value'],
            'json' => json_decode((string)$row['setting_value'], true) ?: $fallback,
            default => (string)$row['setting_value'],
        };
    } catch (Throwable) {
        return $fallback;
    }
}

function servicePilotConfig(PDO $db): array
{
    $fallback = [
        'city' => 'Haldwani',
        'hero_title' => 'Book trusted local services in Haldwani',
        'hero_subtitle' => 'Browse first. Login is needed only when you send a booking request or track it.',
        'trust_badges' => ['Local providers', 'Pay after service', 'Manual confirmation'],
        'support_label' => 'Need help?',
        'support_phone' => '+91 95285 44548',
        'whatsapp_url' => 'https://wa.me/919528544548?text=Hi%20WorkToGo%2C%20I%20need%20help%20with%20a%20service%20booking.',
        'featured_services_label' => 'Services near you',
        'fallback_title' => 'Need another service?',
        'fallback_text' => 'Tell us on WhatsApp. We are manually coordinating pilot requests in Haldwani.',
        'manual_fallback_label' => 'Manual assistance',
    ];
    $stored = servicePublicSetting($db, 'pilot_public_config', []);
    return array_replace($fallback, is_array($stored) ? $stored : []);
}

function normalizeServiceJobStatus(string $status): string
{
    $status = strtolower(trim($status));
    $map = [
        'open' => 'pending',
        'pending' => 'pending',
        'assigned' => 'confirmed',
        'accepted' => 'confirmed',
        'confirmed' => 'confirmed',
        'started' => 'in_progress',
        'ongoing' => 'in_progress',
        'in_progress' => 'in_progress',
        'completed' => 'completed',
        'delivered' => 'completed',
        'rejected' => 'cancelled',
        'cancelled' => 'cancelled',
    ];
    return $map[$status] ?? $status;
}

function canonicalJobStatusForBooking(string $status): string
{
    return match (normalizeServiceJobStatus($status)) {
        'pending' => 'open',
        'confirmed' => 'assigned',
        'in_progress' => 'in_progress',
        'completed' => 'completed',
        'cancelled' => 'cancelled',
        default => 'open',
    };
}

function canonicalBookingMode(array $input, array $service): string
{
    $mode = strtolower(trim((string)($input['booking_mode'] ?? $input['lifecycle_type'] ?? '')));
    if (in_array($mode, ['inspection', 'premium_inspection'], true)) return 'inspection';
    if (in_array($mode, ['direct_vendor', 'vendor_direct'], true) && !empty($service['vendor_id'])) return 'direct_vendor';
    return 'free_lead';
}

function serviceLifecycleNote(array $input, string $bookingMode, array $service): string
{
    $notes = trim((string)($input['notes'] ?? ''));
    $lines = [
        'Lifecycle mode: ' . $bookingMode,
        'Category slug: ' . trim((string)($input['category_slug'] ?? '')),
        'Category label: ' . trim((string)($input['category_label'] ?? '')),
        'Customer name: ' . trim((string)($input['customer_name'] ?? '')),
        'Customer mobile: ' . trim((string)($input['customer_mobile'] ?? '')),
        'Customer locality: ' . trim((string)($input['customer_locality'] ?? '')),
        'Customer address: ' . trim((string)($input['customer_address'] ?? '')),
        'Vendor route: ' . ($bookingMode === 'direct_vendor' ? ('direct:' . (int)($service['vendor_id'] ?? 0)) : 'admin_queue'),
        $notes,
    ];
    return trim(implode("\n", array_values(array_filter($lines, fn($line) => trim((string)$line) !== ''))));
}

function servicePaymentStatusForMode(string $bookingMode, string $paymentMethod): string
{
    return 'unpaid';
}

function serviceJobPriorityForMode(string $bookingMode, array $input): string
{
    if ($bookingMode === 'inspection') return 'high';
    if (!empty($input['demand_priority'])) return 'demand';
    return 'normal';
}

function serviceModeFromNotes(?string $notes): string
{
    $notes = (string)$notes;
    if (str_contains($notes, 'Lifecycle mode: inspection')) return 'inspection';
    if (str_contains($notes, 'Lifecycle mode: direct_vendor')) return 'direct_vendor';
    return 'free_lead';
}

function serviceBookingColumnSql(PDO $db, string $column, string $expr): string
{
    return serviceTableHasColumn($db, 'bookings', $column) ? $expr : "NULL AS {$column}";
}

// ── GET /api/services ──────────────────────────────────────────────────────────
if ($method === 'GET' && $uri === '/api/services') {
    header('Cache-Control: no-store, max-age=0');
    $category = $_GET['category'] ?? null;

    $orderParts = [];
    if (serviceTableHasColumn($db, 'services', 'is_featured')) $orderParts[] = 's.is_featured DESC';
    if (serviceTableHasColumn($db, 'services', 'rating')) $orderParts[] = 's.rating DESC';
    $orderParts[] = 's.name ASC';

    $categorySelect = serviceTableHasColumn($db, 'categories', 'icon') ? ', c.icon AS category_icon' : ", NULL AS category_icon";
    $categorySelect .= serviceTableHasColumn($db, 'categories', 'image_url') ? ', c.image_url AS category_image' : ", NULL AS category_image";

    $sql  = "SELECT s.*, v.business_name AS vendor_name, c.name AS category_name, c.slug AS category_slug {$categorySelect}
             FROM services s
             LEFT JOIN vendors v ON v.id = s.vendor_id
             LEFT JOIN categories c ON c.id = s.category_id
             WHERE s.status = 'active' AND s.deleted_at IS NULL";
    $bind = [];

    if ($category) {
        $sql .= " AND c.slug = :cat";
        $bind[':cat'] = $category;
    }

    $sql .= " ORDER BY " . implode(', ', $orderParts);

    $stmt = $db->prepare($sql);
    $stmt->execute($bind);
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $categories = [];
    foreach ($services as $service) {
        $slug = $service['category_slug'] ?? '';
        if ($slug && !isset($categories[$slug])) {
            $categories[$slug] = [
                'slug' => $slug,
                'name' => $service['category_name'] ?? $slug,
                'icon' => $service['category_icon'] ?: '🔧',
                'image' => $service['category_image'] ?? null,
            ];
        }
    }

    Response::success([
        'services' => $services,
        'categories' => array_values($categories),
        'pilot_config' => servicePilotConfig($db),
        'total' => count($services)
    ]);
}

// ── GET /api/service/categories ───────────────────────────────────────────────
if ($method === 'GET' && $uri === '/api/service/categories') {
    header('Cache-Control: no-store, max-age=0');
    $iconSelect = serviceTableHasColumn($db, 'categories', 'icon') ? 'icon' : "NULL AS icon";
    $imageSelect = serviceTableHasColumn($db, 'categories', 'image_url') ? 'image_url' : "NULL AS image_url";
    $sortSelect = serviceTableHasColumn($db, 'categories', 'sort_order') ? 'sort_order' : "0 AS sort_order";
    $stmt = $db->query("SELECT id, name, slug, status, {$iconSelect}, {$imageSelect}, {$sortSelect} FROM categories WHERE type IN ('service','services') OR type IS NULL ORDER BY sort_order ASC, name ASC");
    Response::success(['categories' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ── POST /api/services ─────────────────────────────────────────────────────────
if ($method === 'POST' && $uri === '/api/services') {
    $auth  = AuthMiddleware::requireRole(ROLE_VENDOR_SERVICE);
    $input = defined('HEART_INTERNAL_INC') 
        ? json_decode($GLOBALS['HEART_PAYLOAD'] ?? '{}', true) 
        : (json_decode(file_get_contents('php://input'), true) ?? []);

    $vendorId = resolveVendorId($db, (int)$auth['user_id']);

    $name            = trim($input['name'] ?? '');
    $basePrice       = isset($input['base_price']) ? (float)$input['base_price'] : 0;
    $categoryId      = isset($input['category_id']) ? (int)$input['category_id'] : 0;
    $durationMinutes = isset($input['duration_minutes']) ? (int)$input['duration_minutes'] : 0;
    $description     = trim($input['description'] ?? '');

    if (!$name || !$basePrice || !$categoryId || !$durationMinutes) {
        Response::validation('name, base_price, category_id, and duration_minutes are required');
    }

    $stmt = $db->prepare(
        "INSERT INTO services (vendor_id, category_id, name, description, base_price, duration_minutes, status, created_at, updated_at)
         VALUES (:vid, :cid, :name, :desc, :price, :duration, 'active', NOW(), NOW())"
    );
    
    $stmt->execute([
        ':vid'      => $vendorId,
        ':cid'      => $categoryId,
        ':name'     => $name,
        ':desc'     => $description ?: null,
        ':price'    => $basePrice,
        ':duration' => $durationMinutes
    ]);

    $serviceId = (int)$db->lastInsertId();

    $stmt = $db->prepare("SELECT * FROM services WHERE id = ?");
    $stmt->execute([$serviceId]);
    $service = $stmt->fetch(PDO::FETCH_ASSOC);

    Response::success(['service' => $service], 201);
}

// ── GET /api/services/{id} ────────────────────────────────────────────────────
if ($method === 'GET' && preg_match('#^/api/services/(\d+)$#', $uri, $m)) {
    $stmt = $db->prepare(
        "SELECT s.*, v.business_name AS vendor_name, v.logo_url AS vendor_logo,
                c.name AS category_name
         FROM services s
         LEFT JOIN vendors v ON v.id = s.vendor_id
         LEFT JOIN categories c ON c.id = s.category_id
         WHERE s.id = ? AND s.status = 'active' AND s.deleted_at IS NULL
         LIMIT 1"
    );
    $stmt->execute([(int)$m[1]]);
    $service = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$service) Response::notFound('Service');

    Response::success(['service' => $service]);
}

// ── PUT /api/services/{id} ────────────────────────────────────────────────────
if ($method === 'PUT' && preg_match('#^/api/services/(\d+)$#', $uri, $m)) {
    $auth  = AuthMiddleware::requireRole(ROLE_VENDOR_SERVICE);
    $input = defined('HEART_INTERNAL_INC') 
        ? json_decode($GLOBALS['HEART_PAYLOAD'] ?? '{}', true) 
        : (json_decode(file_get_contents('php://input'), true) ?? []);

    $vendorId = resolveVendorId($db, (int)$auth['user_id']);
    $serviceId = (int)$m[1];

    $stmt = $db->prepare("SELECT id FROM services WHERE id = ? AND vendor_id = ? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$serviceId, $vendorId]);
    if (!$stmt->fetch()) {
        Response::notFound('Service not found or you do not have permission to edit it');
    }

    $updates = [];
    $bind = [':id' => $serviceId];

    if (isset($input['name'])) {
        $updates[] = 'name = :name';
        $bind[':name'] = trim($input['name']);
    }
    if (isset($input['base_price'])) {
        $updates[] = 'base_price = :price';
        $bind[':price'] = (float)$input['base_price'];
    }
    if (isset($input['category_id'])) {
        $updates[] = 'category_id = :cid';
        $bind[':cid'] = (int)$input['category_id'];
    }
    if (isset($input['duration_minutes'])) {
        $updates[] = 'duration_minutes = :duration';
        $bind[':duration'] = (int)$input['duration_minutes'];
    }
    if (isset($input['description'])) {
        $updates[] = 'description = :desc';
        $bind[':desc'] = trim($input['description']) ?: null;
    }

    if (empty($updates)) {
        Response::validation('No valid fields provided for update');
    }

    $updates[] = 'updated_at = NOW()';

    $db->prepare("UPDATE services SET " . implode(', ', $updates) . " WHERE id = :id")->execute($bind);

    $stmt = $db->prepare("SELECT * FROM services WHERE id = ?");
    $stmt->execute([$serviceId]);
    $service = $stmt->fetch(PDO::FETCH_ASSOC);

    Response::success(['service' => $service]);
}

// ── DELETE /api/services/{id} ─────────────────────────────────────────────────
if ($method === 'DELETE' && preg_match('#^/api/services/(\d+)$#', $uri, $m)) {
    $auth  = AuthMiddleware::requireRole(ROLE_VENDOR_SERVICE);
    $vendorId = resolveVendorId($db, (int)$auth['user_id']);
    $serviceId = (int)$m[1];

    $stmt = $db->prepare("SELECT id FROM services WHERE id = ? AND vendor_id = ? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$serviceId, $vendorId]);
    if (!$stmt->fetch()) {
        Response::notFound('Service not found or you do not have permission to delete it');
    }

    $db->prepare("UPDATE services SET deleted_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$serviceId]);

    Response::success(['message' => 'Service deleted successfully']);
}

// ── POST /api/service/request (create booking + auto-create job) ──────────────
if ($method === 'POST' && $uri === '/api/service/request') {
    $auth  = AuthMiddleware::require();
    if (defined('HEART_INTERNAL_INC')) {
        $decoded = json_decode($GLOBALS['HEART_PAYLOAD'] ?? '{}', true) ?: [];
        $input = is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;
    } else {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
    }

    $serviceId   = (int)($input['service_id']   ?? 0);
    $scheduledAt = trim($input['scheduled_at']   ?? '');
    $notes       = trim($input['notes']          ?? '');
    $addressId   = isset($input['address_id']) ? (int)$input['address_id'] : null;

    // Required field validation
    if (!$serviceId || !$scheduledAt) {
        Response::validation('service_id and scheduled_at are required');
    }

    // Validate scheduled_at is a parseable future datetime
    $scheduledTs = strtotime($scheduledAt);
    if (!$scheduledTs || $scheduledTs <= time()) {
        Response::validation('scheduled_at must be a valid future datetime (e.g. 2026-05-01 14:00:00)');
    }
    $scheduledAt = date('Y-m-d H:i:s', $scheduledTs);

    // Validate address belongs to the authenticated user (if provided)
    if ($addressId) {
        $addrStmt = $db->prepare(
            "SELECT id FROM addresses WHERE id = ? AND user_id = ? LIMIT 1"
        );
        $addrStmt->execute([$addressId, (int)$auth['user_id']]);
        if (!$addrStmt->fetch()) {
            Response::validation('Invalid address_id or address does not belong to your account');
        }
    }

    // Fetch the active service
    $svcStmt = $db->prepare(
        "SELECT * FROM services WHERE id = ? AND status = 'active' LIMIT 1"
    );
    $svcStmt->execute([$serviceId]);
    $service = $svcStmt->fetch(PDO::FETCH_ASSOC);
    if (!$service) Response::notFound('Service');

    $bookingMode = canonicalBookingMode($input, $service);
    $paymentMethod = strtolower(trim($input['payment_method'] ?? 'cod'));
    $paymentMethod = ($bookingMode === 'inspection' && $paymentMethod === 'online') ? 'online' : 'cod';
    $paymentStatus = servicePaymentStatusForMode($bookingMode, $paymentMethod);
    $canonicalNotes = serviceLifecycleNote($input, $bookingMode, $service);
    $bookingTotal = $bookingMode === 'inspection'
        ? (float)($input['expected_payment_amount'] ?? servicePublicSetting($db, 'inspection_price', $service['inspection_price'] ?? 299))
        : (float)$service['base_price'];
    $jobPriority = serviceJobPriorityForMode($bookingMode, $input);

    // Generate collision-resistant unique reference numbers
    $bookingNum = 'WTG-BKG-' . strtoupper(bin2hex(random_bytes(4)));
    $jobNum     = 'WTG-JOB-' . strtoupper(bin2hex(random_bytes(4)));

    try {
        $db->beginTransaction();

        // Create booking
        $bStmt = $db->prepare(
            "INSERT INTO bookings
                (booking_number, user_id, vendor_id, service_id, status, payment_status, payment_method,
                 booking_mode, scheduled_at, duration_minutes, total, address_id, notes,
                 customer_name, customer_mobile, customer_locality, customer_address, vendor_route,
                 created_at)
             VALUES
                (:bnum, :uid, :vid, :sid, 'pending', :pstatus, :pmethod,
                 :booking_mode, :sched, :dur, :price, :addr, :notes,
                 :customer_name, :customer_mobile, :customer_locality, :customer_address, :vendor_route,
                 NOW())"
        );
        $bStmt->execute([
            ':bnum'  => $bookingNum,
            ':uid'   => (int)$auth['user_id'],
            ':vid'   => (int)$service['vendor_id'],
            ':sid'   => $serviceId,
            ':pstatus' => $paymentStatus,
            ':pmethod' => $paymentMethod,
            ':booking_mode' => $bookingMode,
            ':sched' => $scheduledAt,
            ':dur'   => (int)($service['duration_minutes'] ?? 60),
            ':price' => $bookingTotal,
            ':addr'  => $addressId,
            ':notes' => $canonicalNotes ?: null,
            ':customer_name' => trim((string)($input['customer_name'] ?? '')) ?: null,
            ':customer_mobile' => trim((string)($input['customer_mobile'] ?? '')) ?: null,
            ':customer_locality' => trim((string)($input['customer_locality'] ?? '')) ?: null,
            ':customer_address' => trim((string)($input['customer_address'] ?? '')) ?: null,
            ':vendor_route' => $bookingMode === 'direct_vendor' ? 'direct_vendor' : 'admin_queue',
        ]);

        $bookingId = (int)$db->lastInsertId();

        // Online Payment logic
        $paymentData = null;
        if ($paymentMethod === 'online') {
            require_once SYSTEM_ROOT . '/core/helpers/Payment.php';
            try {
                $paymentData = Payment::createOrder('cashfree', $bookingTotal, $bookingNum);
                $db->prepare("UPDATE bookings SET payment_id = ?, payment_status = 'unpaid' WHERE id = ?")
                   ->execute([$paymentData['payment_id'] ?? null, $bookingId]);
                $paymentStatus = 'unpaid';
            } catch (Throwable $paymentError) {
                $paymentData = ['success' => false, 'message' => 'Payment session could not be created. Booking lifecycle is still saved.'];
                $db->prepare("UPDATE bookings SET payment_status = 'failed' WHERE id = ?")->execute([$bookingId]);
                $paymentStatus = 'failed';
            }
        }

        // Auto-create linked job so job_number constraint is always satisfied
        $jStmt = $db->prepare(
            "INSERT INTO jobs
                (job_number, booking_id, vendor_id, user_id, title, description,
                  status, priority, created_at, updated_at)
              VALUES
                 (:jnum, :bid, :vid, :uid, :title, :desc,
                  'open', :priority, NOW(), NOW())"
        );
        $jStmt->execute([
            ':jnum'  => $jobNum,
            ':bid'   => $bookingId,
            ':vid'   => (int)$service['vendor_id'],
            ':uid'   => (int)$auth['user_id'],
            ':title' => 'Job: ' . $service['name'],
            ':desc'  => $canonicalNotes ?: null,
            ':priority' => $jobPriority,
        ]);

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        Response::error('Booking could not be created. Please try again.', 500);
    }

    Response::success([
        'message'        => 'Booking created. A vendor will confirm shortly.',
        'booking_id'     => $bookingId,
        'booking_number' => $bookingNum,
        'job_number'     => $jobNum,
        'service'        => $service['name'],
        'booking_mode'   => $bookingMode,
        'lifecycle_type' => $bookingMode,
        'scheduled_at'   => $scheduledAt,
        'total'          => $bookingTotal,
        'status'         => 'pending',
        'payment_status' => $paymentStatus,
        'vendor_route'   => $bookingMode === 'direct_vendor' ? 'direct_vendor' : 'admin_queue',
        'payment_data'   => $paymentData,
    ], 201);
}

// ── GET /api/service/bookings ─────────────────────────────────────────────────
if ($method === 'GET' && $uri === '/api/service/bookings') {
    header('Cache-Control: no-store, max-age=0');
    $auth   = AuthMiddleware::require();
    $status = $_GET['status'] ?? null;

    // Scope by role
    if ($auth['role'] === ROLE_ADMIN) {
        $where = [];
        $bind  = [];
    } elseif ($auth['role'] === ROLE_VENDOR_SERVICE) {
        $vendorId = resolveVendorId($db, (int)$auth['user_id']);
        $where    = ['b.vendor_id = :vid'];
        $bind     = [':vid' => $vendorId];
    } else {
        // Regular user — own bookings only
        $where = ['b.user_id = :uid'];
        $bind  = [':uid' => (int)$auth['user_id']];
    }

    if ($status) {
        $where[]         = '(b.status = :status OR j.status = :job_status)';
        $bind[':status'] = normalizeServiceJobStatus($status);
        $bind[':job_status'] = canonicalJobStatusForBooking($status);
    }

    if (!empty($_GET['vendor_id']) && $auth['role'] === ROLE_ADMIN) {
        $where[] = 'b.vendor_id = :filter_vid';
        $bind[':filter_vid'] = (int)$_GET['vendor_id'];
    }
    if (!empty($_GET['phone'])) {
        $where[] = 'u.phone LIKE :phone';
        $bind[':phone'] = '%' . trim($_GET['phone']) . '%';
    }
    if (!empty($_GET['q'])) {
        $where[] = '(b.booking_number LIKE :q OR s.name LIKE :q OR v.business_name LIKE :q OR u.name LIKE :q OR u.phone LIKE :q)';
        $bind[':q'] = '%' . trim($_GET['q']) . '%';
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $bookingModeSelect = serviceBookingColumnSql($db, 'booking_mode', 'b.booking_mode');
    $customerNameSelect = serviceBookingColumnSql($db, 'customer_name', 'b.customer_name');
    $customerMobileSelect = serviceBookingColumnSql($db, 'customer_mobile', 'b.customer_mobile');
    $customerLocalitySelect = serviceBookingColumnSql($db, 'customer_locality', 'b.customer_locality');
    $customerAddressSelect = serviceBookingColumnSql($db, 'customer_address', 'b.customer_address');
    $vendorRouteSelect = serviceBookingColumnSql($db, 'vendor_route', 'b.vendor_route');

    $stmt = $db->prepare(
        "SELECT b.*, s.name AS service_name, v.business_name AS vendor_name,
                 {$customerNameSelect}, {$customerMobileSelect}, {$customerLocalitySelect}, {$customerAddressSelect},
                 {$bookingModeSelect}, {$vendorRouteSelect},
                 u.name AS user_name, u.phone AS customer_phone,
                 j.id AS job_id, j.job_number, j.status AS job_status
         FROM bookings b
         LEFT JOIN services s ON s.id = b.service_id
         LEFT JOIN vendors v ON v.id = b.vendor_id
         LEFT JOIN users u ON u.id = b.user_id
         LEFT JOIN jobs j ON j.booking_id = b.id
         $whereSQL
         ORDER BY b.created_at DESC
         LIMIT 50"
    );
    $stmt->execute($bind);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($bookings as &$booking) {
        $booking['status'] = normalizeServiceJobStatus((string)($booking['status'] ?? 'pending'));
        $booking['job_status'] = normalizeServiceJobStatus((string)($booking['job_status'] ?? $booking['status']));
        $booking['amount'] = $booking['total'] ?? $booking['amount'] ?? null;
        $booking['payment_method'] = $booking['payment_method'] ?: 'cod';
        $booking['booking_mode'] = $booking['booking_mode'] ?: serviceModeFromNotes($booking['notes'] ?? null);
        $booking['vendor_route'] = $booking['vendor_route'] ?: ($booking['booking_mode'] === 'direct_vendor' ? 'direct_vendor' : 'admin_queue');
        $booking['customer_name'] = $booking['customer_name'] ?: ($booking['user_name'] ?? null);
        $booking['support_hint'] = 'WorkToGo support can help with this booking ID.';
    }
    unset($booking);

    Response::success(['bookings' => $bookings, 'total' => count($bookings)]);
}

// ── GET /api/service/bookings/{id} ────────────────────────────────────────────
// FIX: IDOR — enforce that only the owning user, the assigned vendor, or an admin
//      can view a specific booking.
if ($method === 'GET' && preg_match('#^/api/service/bookings/(\d+)$#', $uri, $m)) {
    $auth = AuthMiddleware::require();
    $id   = (int)$m[1];

    $stmt = $db->prepare(
        "SELECT b.*, s.name AS service_name, v.business_name AS vendor_name,
                 " . serviceBookingColumnSql($db, 'booking_mode', 'b.booking_mode') . ",
                 " . serviceBookingColumnSql($db, 'customer_name', 'b.customer_name') . ",
                 " . serviceBookingColumnSql($db, 'customer_mobile', 'b.customer_mobile') . ",
                 " . serviceBookingColumnSql($db, 'customer_locality', 'b.customer_locality') . ",
                 " . serviceBookingColumnSql($db, 'customer_address', 'b.customer_address') . ",
                 " . serviceBookingColumnSql($db, 'vendor_route', 'b.vendor_route') . ",
                 j.id AS job_id, j.job_number, j.status AS job_status
         FROM bookings b
         LEFT JOIN services s ON s.id = b.service_id
         LEFT JOIN vendors v ON v.id = b.vendor_id
         LEFT JOIN jobs j ON j.booking_id = b.id
         WHERE b.id = ?
         LIMIT 1"
    );
    $stmt->execute([$id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) Response::notFound('Booking');

    // Ownership enforcement
    if ($auth['role'] === ROLE_ADMIN) {
        // Admin may view any booking — no restriction
    } elseif ($auth['role'] === ROLE_VENDOR_SERVICE) {
        $vendorId = resolveVendorId($db, (int)$auth['user_id']);
        if ((int)$booking['vendor_id'] !== $vendorId) {
            Response::forbidden('Access denied to this booking');
        }
    } else {
        if ((int)$booking['user_id'] !== (int)$auth['user_id']) {
            Response::forbidden('Access denied to this booking');
        }
    }

    $booking['status'] = normalizeServiceJobStatus((string)($booking['status'] ?? 'pending'));
    $booking['job_status'] = normalizeServiceJobStatus((string)($booking['job_status'] ?? $booking['status']));
    $booking['amount'] = $booking['total'] ?? $booking['amount'] ?? null;
    $booking['payment_method'] = $booking['payment_method'] ?: 'cod';
    $booking['booking_mode'] = $booking['booking_mode'] ?: serviceModeFromNotes($booking['notes'] ?? null);
    $booking['vendor_route'] = $booking['vendor_route'] ?: ($booking['booking_mode'] === 'direct_vendor' ? 'direct_vendor' : 'admin_queue');
    $booking['support_hint'] = 'WorkToGo support can help with this booking ID.';

    // Attach linked job
    $jobStmt = $db->prepare("SELECT * FROM jobs WHERE booking_id = ? LIMIT 1");
    $jobStmt->execute([$id]);
    $job = $jobStmt->fetch(PDO::FETCH_ASSOC);

    Response::success(['booking' => $booking, 'job' => $job ?: null]);
}

// ── PATCH /api/jobs/{id}/status ───────────────────────────────────────────────
// FIX: Vendor ownership validated via vendors table (not raw user_id comparison).
if ($method === 'PATCH' && preg_match('#^/api/jobs/(\d+)/status$#', $uri, $m)) {
    $auth      = AuthMiddleware::requireRole(ROLE_VENDOR_SERVICE, ROLE_ADMIN);
    $input     = defined('HEART_INTERNAL_INC')
        ? (json_decode($GLOBALS['HEART_PAYLOAD'] ?? '{}', true)['data'] ?? [])
        : (json_decode(file_get_contents('php://input'), true) ?? []);
    $jobId     = (int)$m[1];
    $newStatus = canonicalJobStatusForBooking((string)($input['status'] ?? ''));

    $allowed = ['open', 'assigned', 'in_progress', 'completed', 'cancelled'];
    if (!in_array($newStatus, $allowed, true)) {
        Response::validation('status must be one of: ' . implode(', ', $allowed));
    }

    $jobStmt = $db->prepare("SELECT * FROM jobs WHERE id = ? LIMIT 1");
    $jobStmt->execute([$jobId]);
    $jobRow = $jobStmt->fetch(PDO::FETCH_ASSOC);
    if (!$jobRow) Response::notFound('Job');

    // FIX: vendor ownership — compare against vendors.id, not users.id
    if ($auth['role'] === ROLE_VENDOR_SERVICE) {
        $vendorId = resolveVendorId($db, (int)$auth['user_id']);
        if ((int)$jobRow['vendor_id'] !== $vendorId) {
            Response::forbidden('You do not have permission to update this job');
        }
    }

    $updates = ['status = :status', 'updated_at = NOW()'];
    $bind    = [':status' => $newStatus, ':id' => $jobId];

    if ($newStatus === 'in_progress' && empty($jobRow['started_at'])) {
        $updates[] = 'started_at = NOW()';
    }
    if ($newStatus === 'completed' && empty($jobRow['completed_at'])) {
        $updates[] = 'completed_at = NOW()';
    }

    $db->prepare("UPDATE jobs SET " . implode(', ', $updates) . " WHERE id = :id")
       ->execute($bind);

    // Mirror status onto the linked booking
    $bookingStatus = match ($newStatus) {
        'open'        => 'pending',
        'assigned'    => 'confirmed',
        'in_progress' => 'in_progress',
        'completed'   => 'completed',
        'cancelled'   => 'cancelled',
        default       => null,
    };
    if ($bookingStatus && $jobRow['booking_id']) {
        $db->prepare("UPDATE bookings SET status = ?, updated_at = NOW() WHERE id = ?")
           ->execute([$bookingStatus, (int)$jobRow['booking_id']]);
    }

    Response::success([
        'message' => "Job status updated to '{$newStatus}'",
        'job_id'  => $jobId,
        'status'  => $bookingStatus ?: normalizeServiceJobStatus($newStatus),
    ]);
}

Response::error('Endpoint not found', 404);
