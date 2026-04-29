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

// ── GET /api/services ──────────────────────────────────────────────────────────
if ($method === 'GET' && $uri === '/api/services') {
    $category = $_GET['category'] ?? null;

    $sql  = "SELECT s.*, v.business_name AS vendor_name
             FROM services s
             LEFT JOIN vendors v ON v.id = s.vendor_id
             WHERE s.status = 'active' AND s.deleted_at IS NULL";
    $bind = [];

    if ($category) {
        $sql .= " AND s.category_id = (SELECT id FROM categories WHERE slug = :cat LIMIT 1)";
        $bind[':cat'] = $category;
    }

    $sql .= " ORDER BY s.is_featured DESC, s.rating DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($bind);
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);

    Response::success(['services' => $services, 'total' => count($services)]);
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
    $input = defined('HEART_INTERNAL_INC') 
        ? json_decode($GLOBALS['HEART_PAYLOAD'] ?? '{}', true) 
        : (json_decode(file_get_contents('php://input'), true) ?? []);

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

    $paymentMethod = strtolower(trim($input['payment_method'] ?? 'cod'));

    // Generate collision-resistant unique reference numbers
    $bookingNum = 'WTG-BKG-' . strtoupper(bin2hex(random_bytes(4)));
    $jobNum     = 'WTG-JOB-' . strtoupper(bin2hex(random_bytes(4)));

    try {
        $db->beginTransaction();

        // Create booking
        $bStmt = $db->prepare(
            "INSERT INTO bookings
                (booking_number, user_id, vendor_id, service_id, status, payment_status, payment_method,
                 scheduled_at, duration_minutes, total, address_id, notes,
                 created_at)
             VALUES
                (:bnum, :uid, :vid, :sid, 'pending', 'unpaid', :pmethod,
                 :sched, :dur, :price, :addr, :notes,
                 NOW())"
        );
        $bStmt->execute([
            ':bnum'  => $bookingNum,
            ':uid'   => (int)$auth['user_id'],
            ':vid'   => (int)$service['vendor_id'],
            ':sid'   => $serviceId,
            ':pmethod' => $paymentMethod,
            ':sched' => $scheduledAt,
            ':dur'   => (int)($service['duration_minutes'] ?? 60),
            ':price' => (float)$service['base_price'],
            ':addr'  => $addressId,
            ':notes' => $notes ?: null,
        ]);

        $bookingId = (int)$db->lastInsertId();

        // Online Payment logic
        $paymentData = null;
        if ($paymentMethod === 'online') {
            require_once SYSTEM_ROOT . '/core/helpers/Payment.php';
            $paymentData = Payment::createOrder('cashfree', (float)$service['base_price'], $bookingNum);
            $db->prepare("UPDATE bookings SET payment_id = ? WHERE id = ?")->execute([$paymentData['payment_id'], $bookingId]);
        }

        // Auto-create linked job so job_number constraint is always satisfied
        $jStmt = $db->prepare(
            "INSERT INTO jobs
                (job_number, booking_id, vendor_id, user_id, title, description,
                 status, priority, created_at, updated_at)
             VALUES
                (:jnum, :bid, :vid, :uid, :title, :desc,
                 'open', 'normal', NOW(), NOW())"
        );
        $jStmt->execute([
            ':jnum'  => $jobNum,
            ':bid'   => $bookingId,
            ':vid'   => (int)$service['vendor_id'],
            ':uid'   => (int)$auth['user_id'],
            ':title' => 'Job: ' . $service['name'],
            ':desc'  => $notes ?: null,
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
        'scheduled_at'   => $scheduledAt,
        'total'          => (float)$service['base_price'],
        'status'         => 'pending',
        'payment_data'   => $paymentData,
    ], 201);
}

// ── GET /api/service/bookings ─────────────────────────────────────────────────
if ($method === 'GET' && $uri === '/api/service/bookings') {
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
        $where[]         = 'b.status = :status';
        $bind[':status'] = $status;
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $db->prepare(
        "SELECT b.*, s.name AS service_name, v.business_name AS vendor_name
         FROM bookings b
         LEFT JOIN services s ON s.id = b.service_id
         LEFT JOIN vendors v ON v.id = b.vendor_id
         $whereSQL
         ORDER BY b.created_at DESC
         LIMIT 50"
    );
    $stmt->execute($bind);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    Response::success(['bookings' => $bookings, 'total' => count($bookings)]);
}

// ── GET /api/service/bookings/{id} ────────────────────────────────────────────
// FIX: IDOR — enforce that only the owning user, the assigned vendor, or an admin
//      can view a specific booking.
if ($method === 'GET' && preg_match('#^/api/service/bookings/(\d+)$#', $uri, $m)) {
    $auth = AuthMiddleware::require();
    $id   = (int)$m[1];

    $stmt = $db->prepare(
        "SELECT b.*, s.name AS service_name, v.business_name AS vendor_name
         FROM bookings b
         LEFT JOIN services s ON s.id = b.service_id
         LEFT JOIN vendors v ON v.id = b.vendor_id
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
    $input     = json_decode(file_get_contents('php://input'), true) ?? [];
    $jobId     = (int)$m[1];
    $newStatus = trim($input['status'] ?? '');

    $allowed = ['assigned', 'in_progress', 'completed', 'cancelled'];
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
    ]);
}

Response::error('Endpoint not found', 404);
