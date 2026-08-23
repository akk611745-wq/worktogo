<?php
// ============================================================
//  /api/admin/* — Admin endpoints
//  All routes require ROLE_ADMIN JWT.
//  GET  /api/admin/users          — list all users
//  GET  /api/admin/users/{id}     — user detail
//  PATCH /api/admin/users/{id}    — update user status/role
//  GET  /api/admin/stats          — platform summary stats
//  DELETE /api/admin/logs         — purge old log files
// ============================================================

require_once SYSTEM_ROOT . '/core/lib/PushSubscription.php';
require_once SYSTEM_ROOT . '/core/lib/FcmNotifier.php';
require_once SYSTEM_ROOT . '/core/helpers/ServiceVendorEligibility.php';
require_once SYSTEM_ROOT . '/core/helpers/CityResolver.php';

/**
 * Build the optional (name/category_id-excluded) column/value/bind arrays for a
 * packages INSERT/UPDATE from admin input. Shared by create + edit so the
 * package schema is fully data-driven (no hardcoded names/prices). Features are
 * normalised to a JSON array (accepts an array or newline text). Empty numerics
 * become NULL so a package can be flat-price OR per-sqft.
 * @return array{0:array,1:array,2:array} [cols, valuePlaceholders, binds]
 */
function adminBuildPackageFields(array $input, bool $isCreate): array {
    $cols = []; $vals = []; $bind = [];
    $add = function (string $col, $value) use (&$cols, &$vals, &$bind) {
        $cols[] = $col; $vals[] = ':' . $col; $bind[':' . $col] = $value;
    };
    if (array_key_exists('description', $input)) {
        $add('description', trim((string)$input['description']) !== '' ? trim((string)$input['description']) : null);
    }
    if (array_key_exists('features', $input)) {
        $feat = $input['features'];
        if (is_string($feat)) { $feat = preg_split('/\r\n|\r|\n/', $feat) ?: []; }
        if (!is_array($feat)) { $feat = []; }
        $feat = array_values(array_filter(array_map(fn($f) => trim((string)$f), $feat), fn($f) => $f !== ''));
        $add('features', $feat ? json_encode($feat, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null);
    }
    foreach (['rate_per_sqft', 'base_price', 'inspection_fee'] as $numCol) {
        if (array_key_exists($numCol, $input)) {
            $v = $input[$numCol];
            $add($numCol, ($v === '' || $v === null) ? null : (float)$v);
        }
    }
    if (array_key_exists('min_sqft', $input)) {
        $v = $input['min_sqft'];
        $add('min_sqft', ($v === '' || $v === null) ? null : (int)$v);
    }
    if (array_key_exists('city_id', $input)) {
        $v = $input['city_id'];
        $add('city_id', ($v === '' || $v === null || (int)$v <= 0) ? null : (int)$v);
    }
    if (array_key_exists('is_active', $input)) {
        $add('is_active', $input['is_active'] ? 1 : 0);
    } elseif ($isCreate) {
        $add('is_active', 1);
    }
    if (array_key_exists('sort_order', $input)) {
        $add('sort_order', (int)$input['sort_order']);
    }
    return [$cols, $vals, $bind];
}

$auth = AuthMiddleware::requireRole(ROLE_ADMIN);

// ── Commerce control-room routes (Phase 3) ──────────────────────────────────
// Dedicated commerce admin API in its own file so a separate commerce team owns
// it. It matches /api/admin/commerce/* (store types, stores, products, inventory),
// each route RBAC-gated by a commerce permission; non-matching requests fall
// through to the admin routes below. See heart/api/admin/commerce.php.
require_once __DIR__ . '/commerce.php';

// Blocker B2 fix — keep jobs.vendor_id in sync with bookings.vendor_id on admin (un)assign.
// Admin assign historically wrote only bookings.vendor_id, leaving jobs.vendor_id NULL, so the
// vendor's PATCH /api/jobs/{id}/status ownership check (jobs.vendor_id === vendor) returned 403
// for Free/Inspection bookings. This mirrors exactly how Direct Vendor creates its job
// (vendor_id set, status='open') so the vendor can accept/start/complete. Every booking has one
// linked job (jobs.booking_id = bookings.id); pass null on unassign to release the job too.
$syncBookingJobVendor = function (int $bookingId, ?int $vendorId) use ($db): void {
    $db->prepare("UPDATE jobs SET vendor_id = :vid, status = 'open', updated_at = NOW() WHERE booking_id = :bid")
       ->execute([':vid' => $vendorId, ':bid' => $bookingId]);
};

try {

    // ── GET /api/admin/notifications ──────────────────────────
    if ($method === 'GET' && $uri === '/api/admin/notifications') {
        $stmt = $db->prepare(
            "SELECT id, title, body, type, is_read, created_at
             FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10"
        );
        $stmt->execute([(int)$auth['user_id']]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $unreadStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $unreadStmt->execute([(int)$auth['user_id']]);

        Response::success(['notifications' => $notifications, 'unread_count' => (int)$unreadStmt->fetchColumn()]);
    }

    // ── POST /api/admin/notifications/read ────────────────────
    if ($method === 'POST' && $uri === '/api/admin/notifications/read') {
        $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")
           ->execute([(int)$auth['user_id']]);
        Response::success(['marked_read' => true]);
    }

    // ── GET /api/admin/dashboard ──────────────────────────────
    if ($method === 'GET' && $uri === '/api/admin/dashboard') {
        $todayStart = date('Y-m-d 00:00:00');

        // --- ORDERS ---
        // FIX: fetch() returns false when no rows match; guard with ?: [] so array
        //      access never runs on a boolean false value.
        $stmt = $db->prepare("
            SELECT
                COUNT(*) as total_orders_today,
                SUM(CASE WHEN status IN ('delivered') THEN 1 ELSE 0 END) as completed_orders_today,
                SUM(CASE WHEN status IN ('cancelled', 'failed') THEN 1 ELSE 0 END) as failed_orders_today
            FROM orders
            WHERE created_at >= :today
        ");
        $stmt->execute([':today' => $todayStart]);
        $orders = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $stmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE status IN ('pending', 'confirmed', 'processing', 'shipped')");
        $stmt->execute();
        // FIX: fetchColumn() returns false on empty result; cast safely via (int)
        $pending_orders = $stmt->fetchColumn();

        $orders['pending_orders']            = (int)($pending_orders ?: 0);
        $orders['total_orders_today']        = (int)($orders['total_orders_today'] ?? 0);
        $orders['completed_orders_today']    = (int)($orders['completed_orders_today'] ?? 0);
        $orders['failed_orders_today']       = (int)($orders['failed_orders_today'] ?? 0);

        // --- SERVICE STATS ---
        // Guard: bookings table is absent on delivery-only databases;
        // mirrors the same try/catch pattern used for driver_wallets above.
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM bookings WHERE DATE(created_at) = CURDATE()");
            $stmt->execute();
            $svc_total_today = $stmt->fetchColumn();

            $stmt = $db->prepare("SELECT COUNT(*) FROM bookings WHERE status = 'completed' AND DATE(created_at) = CURDATE()");
            $stmt->execute();
            $svc_completed_today = $stmt->fetchColumn();

            $stmt = $db->prepare("SELECT COUNT(*) FROM bookings WHERE vendor_id IS NULL AND status NOT IN ('completed', 'cancelled')");
            $stmt->execute();
            $svc_pending_assignment = $stmt->fetchColumn();

            $stmt = $db->prepare("SELECT COUNT(*) FROM bookings WHERE status = 'in_progress'");
            $stmt->execute();
            $svc_in_progress = $stmt->fetchColumn();

            $stmt = $db->prepare("SELECT SUM(total) FROM bookings WHERE payment_status = 'paid'");
            $stmt->execute();
            $svc_revenue = $stmt->fetchColumn();
        } catch (PDOException $e) {
            $svc_total_today        = 0;
            $svc_completed_today    = 0;
            $svc_pending_assignment = 0;
            $svc_in_progress        = 0;
            $svc_revenue            = 0;
        }

        // --- MISSING FIELDS: vendor rejections, reviews pending, inspections, follow-up ---
        try {
            $stmt = $db->query("SELECT COUNT(*) FROM jobs WHERE status='rejected' AND DATE(updated_at) = CURDATE()");
            $vendor_rejections = (int)$stmt->fetchColumn();
        } catch(Exception $e) { $vendor_rejections = 0; }

        try {
            $stmt = $db->query("SELECT COUNT(*) FROM bookings WHERE status='completed' AND (review_status IS NULL OR review_status='')");
            $reviews_pending = (int)$stmt->fetchColumn();
        } catch(Exception $e) { $reviews_pending = 0; }

        try {
            $stmt = $db->query("SELECT COUNT(*) FROM bookings WHERE booking_mode IN ('inspection','paid_inspection') AND DATE(created_at) = CURDATE()");
            $inspections_today = (int)$stmt->fetchColumn();
        } catch(Exception $e) { $inspections_today = 0; }

        try {
            $stmt = $db->query("SELECT COUNT(*) FROM bookings WHERE vendor_id IS NULL AND status NOT IN ('completed','cancelled') AND created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
            $followup_urgent = (int)$stmt->fetchColumn();
        } catch(Exception $e) { $followup_urgent = 0; }

        $service_stats = [
            'total_service_bookings_today' => (int)($svc_total_today ?: 0),
            'completed_today'              => (int)($svc_completed_today ?: 0),
            'pending_assignment'           => (int)($svc_pending_assignment ?: 0),
            'in_progress'                  => (int)($svc_in_progress ?: 0),
            'total_revenue_services'       => (float)($svc_revenue ?: 0),
            'vendor_rejections_today'      => $vendor_rejections,
            'reviews_pending'              => $reviews_pending,
            'inspections_today'            => $inspections_today,
            'followup_urgent'              => $followup_urgent,
        ];

        // --- FINANCE ---
        // FIX: same fetch() false-guard; SUM on empty set returns NULL → coerce to 0.0
        $stmt = $db->prepare("
            SELECT
                SUM(CASE WHEN status != 'cancelled' THEN total ELSE 0 END) as total_revenue_today,
                SUM(CASE WHEN status = 'refunded' THEN total ELSE 0 END) as total_refunds_today
            FROM orders
            WHERE created_at >= :today
        ");
        $stmt->execute([':today' => $todayStart]);
        $finance = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        try {
            $stmt = $db->prepare("
                SELECT SUM(amount) FROM wallet_transactions
                WHERE entity_type = 'platform' AND type = 'credit' AND created_at >= :today AND status = 'settled'
            ");
            $stmt->execute([':today' => $todayStart]);
            $platform_earnings = $stmt->fetchColumn();
        } catch (PDOException $e) {
            $platform_earnings = 0;
        }

        try {
            $stmt = $db->prepare("SELECT SUM(pending_balance) FROM vendor_wallets");
            $stmt->execute();
            $pending_vendor_payout = $stmt->fetchColumn();
        } catch (PDOException $e) {
            $pending_vendor_payout = 0;
        }

        $finance['total_revenue_today']     = (float)($finance['total_revenue_today'] ?? 0);
        $finance['platform_earnings_today'] = (float)($platform_earnings ?: 0);
        $finance['pending_vendor_payout']   = (float)($pending_vendor_payout ?: 0);
        $finance['total_refunds_today']     = (float)($finance['total_refunds_today'] ?? 0);

        // --- DRIVER STATUS ---
        // Guard: driver_wallets table is absent on service-only pilot databases.
        // Absence causes a PDOException that would otherwise propagate to the outer
        // catch and return 500 for the entire dashboard request.
        try {
            $stmt = $db->prepare("
                SELECT
                    COUNT(u.id) as total_drivers_active,
                    SUM(CASE WHEN dw.cash_in_hand >= dw.collection_limit THEN 1 ELSE 0 END) as drivers_blocked,
                    SUM(dw.cash_in_hand) as total_cash_in_hand
                FROM users u
                JOIN driver_wallets dw ON u.id = dw.driver_id
                WHERE u.role = 'delivery' AND u.status = 'active'
            ");
            $stmt->execute();
            $driver_stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $driver_stats = [];
        }

        $driver_stats['total_drivers_active'] = (int)($driver_stats['total_drivers_active'] ?? 0);
        $driver_stats['drivers_blocked']      = (int)($driver_stats['drivers_blocked'] ?? 0);
        $driver_stats['total_cash_in_hand']   = (float)($driver_stats['total_cash_in_hand'] ?? 0);

        // --- SYSTEM HEALTH ---
        // Stuck orders (no update for > 60 mins and not in a final state)
        $stmt = $db->prepare("
            SELECT id, order_number, status, updated_at
            FROM orders
            WHERE status NOT IN ('delivered', 'cancelled', 'refunded')
              AND updated_at < DATE_SUB(NOW(), INTERVAL 60 MINUTE)
        ");
        $stmt->execute();
        // FIX: fetchAll() returns [] on no rows — already safe; keep as-is.
        $stuck_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // FIX: wrap optional-table queries in try/catch so a missing table
        //      (fresh install / partial migration) returns 0 instead of a 500.
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM transactions WHERE status = 'failed' AND created_at >= :today");
            $stmt->execute([':today' => $todayStart]);
            $failed_payments_today = $stmt->fetchColumn();
        } catch (PDOException $e) {
            $failed_payments_today = 0;
        }

        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM wallet_transactions WHERE status = 'pending' AND type = 'credit' AND entity_type IN ('vendor', 'driver') AND description LIKE '%refund%'");
            $stmt->execute();
            $refund_pending = $stmt->fetchColumn();
        } catch (PDOException $e) {
            $refund_pending = 0;
        }

        // Alternative refund pending count based on orders table ledger_status or payment_status
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE payment_status = 'refunded' AND ledger_status = 'pending'");
            $stmt->execute();
            $refund_pending_orders = $stmt->fetchColumn();
        } catch (PDOException $e) {
            $refund_pending_orders = 0;
        }

        $system_health = [
            'stuck_orders_count'    => count($stuck_orders),
            'stuck_orders_list'     => $stuck_orders, // for UI click
            'failed_payments_today' => (int)($failed_payments_today ?: 0),
            'refund_pending'        => (int)($refund_pending ?: 0) + (int)($refund_pending_orders ?: 0),
        ];

        // --- SMART INSIGHTS ---
        // Primary: service bookings (service-only mode); fallback: shopping orders
        $top_vendors = [];
        try {
            $bCols = array_flip($db->query(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings'
                 AND COLUMN_NAME IN ('total','amount','vendor_id','status')"
            )->fetchAll(PDO::FETCH_COLUMN));
            if (isset($bCols['vendor_id'], $bCols['status'])) {
                $revExpr = isset($bCols['total']) ? 'SUM(b.total)' : (isset($bCols['amount']) ? 'SUM(b.amount)' : '0');
                $stmt = $db->prepare("
                    SELECT v.id, v.business_name,
                           COUNT(*) AS completed_jobs,
                           COALESCE({$revExpr}, 0) AS total_sales
                    FROM bookings b
                    JOIN vendors v ON v.id = b.vendor_id
                    WHERE b.status IN ('completed','done','delivered') AND b.vendor_id IS NOT NULL
                    GROUP BY v.id, v.business_name
                    ORDER BY completed_jobs DESC, total_sales DESC
                    LIMIT 5
                ");
                $stmt->execute();
                $top_vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $ignored) {}
        if (empty($top_vendors)) {
            try {
                $stmt = $db->prepare("
                    SELECT v.id, v.business_name, SUM(o.total) AS total_sales, 0 AS completed_jobs
                    FROM vendors v
                    JOIN orders o ON v.id = o.vendor_id
                    WHERE o.status IN ('delivered')
                    GROUP BY v.id, v.business_name
                    ORDER BY total_sales DESC
                    LIMIT 5
                ");
                $stmt->execute();
                $top_vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $ignored) {}
        }

        try {
            $stmt = $db->prepare("
                SELECT oi.product_id, oi.product_name, COUNT(*) as cancel_count
                FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                WHERE o.status = 'cancelled'
                GROUP BY oi.product_id, oi.product_name
                ORDER BY cancel_count DESC
                LIMIT 5
            ");
            $stmt->execute();
            $most_cancelled_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $most_cancelled_items = [];
        }

        // --- AREA DEMAND ---
        $area_demand = [];
        try {
            $stmt = $db->prepare(
                "SELECT
                    customer_locality AS area,
                    COUNT(*) AS total_bookings,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                    COUNT(DISTINCT vendor_id) AS active_vendors
                 FROM bookings
                 WHERE customer_locality IS NOT NULL AND customer_locality != ''
                 GROUP BY customer_locality
                 ORDER BY total_bookings DESC
                 LIMIT 10"
            );
            $stmt->execute();
            $area_demand = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $ignored) {}

        $stmt = $db->prepare("SELECT SUM(total) FROM orders WHERE payment_method = 'cod' AND status = 'delivered'");
        $stmt->execute();
        // FIX: fetchColumn() returns false or NULL on empty set → coerce to 0.0
        $expected_cod = $stmt->fetchColumn();

        $cash_flow = [
            'expected_cash_from_cod' => (float)($expected_cod ?: 0),
            'actual_cash_collected'  => $driver_stats['total_cash_in_hand'], // already safe float above
        ];

        $cash_flow['deviation'] = $cash_flow['expected_cash_from_cod'] - $cash_flow['actual_cash_collected'];

        // --- ALERTS SYSTEM ---
        $alerts = [];
        if ($system_health['stuck_orders_count'] > 0) {
            $alerts[] = ['type' => 'warning', 'message' => "{$system_health['stuck_orders_count']} orders are stuck for over 60 mins."];
        }
        if ($driver_stats['drivers_blocked'] > 0) {
            $alerts[] = ['type' => 'danger', 'message' => "{$driver_stats['drivers_blocked']} drivers are blocked due to cash limits."];
        }
        if ($finance['total_refunds_today'] > 1000) {
            $alerts[] = ['type' => 'warning', 'message' => "Abnormal refund spike detected: {$finance['total_refunds_today']} refunded today."];
        }
        if (abs($cash_flow['deviation']) > 1000) {
            $alerts[] = ['type' => 'critical', 'message' => "Cash collection mismatch! Deviation of {$cash_flow['deviation']} detected."];
        }

        Response::success([
            'orders'        => $orders,
            'service_stats' => $service_stats,
            'finance'       => $finance,
            'driver_status' => $driver_stats,
            'system_health' => $system_health,
            'insights'     => [
                'top_vendors'           => $top_vendors,
                'most_cancelled_items'  => $most_cancelled_items,
                'cash_flow'             => $cash_flow,
            ],
            'area_demand' => $area_demand,
            'alerts' => $alerts,
        ]);
    }

    // ── GET /api/admin/stats ──────────────────────────────────
    if ($method === 'GET' && $uri === '/api/admin/stats') {
        $stats = [];

        $tables = ['users', 'vendors'];
        foreach ($tables as $table) {
            try {
                $stmt = $db->prepare("SELECT COUNT(*) FROM {$table}");
                $stmt->execute();
                $stats[$table] = (int) $stmt->fetchColumn();
            } catch (PDOException) {
                $stats[$table] = null;
            }
        }

        // Optional engine tables
        foreach (['services', 'bookings', 'products', 'orders'] as $table) {
            try {
                $stmt = $db->prepare("SELECT COUNT(*) FROM {$table}");
                $stmt->execute();
                $stats[$table] = (int) $stmt->fetchColumn();
            } catch (PDOException) {
                // Table doesn't exist yet — skip
            }
        }

        Response::success(['stats' => $stats]);
    }

    // ── GET /api/admin/vendors ────────────────────────────────
    if ($method === 'GET' && $uri === '/api/admin/vendors') {
        $type   = $_GET['type']   ?? null;
        $search = substr(trim($_GET['search'] ?? $_GET['q'] ?? ''), 0, 100);
        $page   = max(1, (int) ($_GET['page']  ?? 1));
        $limit  = min(100, (int) ($_GET['limit'] ?? 20));
        $offset = ($page - 1) * $limit;

        $where = ['1=1'];
        $bind  = [];

        if ($search !== '') {
            $where[] = '(v.business_name LIKE :search OR u.name LIKE :search OR u.email LIKE :search OR u.phone LIKE :search)';
            $bind[':search'] = '%' . $search . '%';
        }

        if (!empty($_GET['status'])) {
            $where[] = 'v.status = :status';
            $bind[':status'] = $_GET['status'];
        }

        if ($type) {
            $where[] = 'v.type = :type';
            $bind[':type'] = $type;
        }

        $whereSQL = 'WHERE ' . implode(' AND ', $where);
        
        $countStmt = $db->prepare("SELECT COUNT(*) FROM vendors v LEFT JOIN users u ON u.id = v.user_id {$whereSQL}");
        $countStmt->execute($bind);
        $total = (int) $countStmt->fetchColumn();

        $pendingStmt = $db->prepare("SELECT COUNT(*) FROM vendors WHERE status = 'pending'");
        $pendingStmt->execute();
        $pendingCount = (int) $pendingStmt->fetchColumn();
        
        $hasServiceLocalities = (int)$db->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND COLUMN_NAME = 'service_localities'"
        )->fetchColumn() > 0;
        $hasServiceAreaNotes = (int)$db->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND COLUMN_NAME = 'service_area_notes'"
        )->fetchColumn() > 0;
        $serviceLocalitiesSelect = $hasServiceLocalities ? ', v.service_localities' : ', NULL AS service_localities';
        $serviceAreaNotesSelect  = $hasServiceAreaNotes  ? ', v.service_area_notes'  : ', NULL AS service_area_notes';

        $hasIsVerified = (int)$db->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND COLUMN_NAME = 'is_verified'"
        )->fetchColumn() > 0;
        $hasExperienceYears = (int)$db->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND COLUMN_NAME = 'experience_years'"
        )->fetchColumn() > 0;
        $isVerifiedSelect      = $hasIsVerified      ? ', v.is_verified'      : ', NULL AS is_verified';
        $experienceYearsSelect = $hasExperienceYears ? ', v.experience_years' : ', NULL AS experience_years';

        $stmt = $db->prepare(
            "SELECT v.id, v.user_id, v.business_name, v.slug, v.type, v.type AS vendor_type,
                    v.status, v.commission_rate, v.rating, v.is_online, v.lat, v.lng,
                    v.created_at, v.updated_at, v.category_id,
                    u.name AS owner_name, u.phone AS owner_phone, u.email AS owner_email
                    {$serviceLocalitiesSelect}{$serviceAreaNotesSelect}{$isVerifiedSelect}{$experienceYearsSelect}
             FROM vendors v
             LEFT JOIN users u ON u.id = v.user_id
             {$whereSQL}
             ORDER BY v.created_at DESC
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach ($bind as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();

        Response::success([
            'vendors'      => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total'        => $total,
            'pendingCount' => $pendingCount,
            'page'         => $page,
            'limit'        => $limit,
            'pagination'   => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int) ceil($total / max($limit, 1)),
            ],
        ]);
    }

    // ── GET /api/admin/services ───────────────────────────────
    if ($method === 'GET' && $uri === '/api/admin/services') {
        $page   = max(1, (int) ($_GET['page']  ?? 1));
        $limit  = min(100, (int) ($_GET['limit'] ?? 20));
        $offset = ($page - 1) * $limit;

        $where = ['1=1'];
        $bind  = [];

        if (!empty($_GET['search'])) {
            $where[] = "(s.name LIKE :search OR COALESCE(s.description,'') LIKE :search)";
            $bind[':search'] = '%' . substr(trim($_GET['search']), 0, 100) . '%';
        }
        if (!empty($_GET['status'])) {
            $rawSt   = strtolower(trim($_GET['status']));
            $mappedSt = ($rawSt === 'enabled') ? 'active' : (($rawSt === 'disabled') ? 'inactive' : $rawSt);
            $where[] = 's.status = :status';
            $bind[':status'] = $mappedSt;
        }

        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        $countStmt = $db->prepare("SELECT COUNT(*) FROM services s {$whereSQL}");
        $countStmt->execute($bind);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT s.*, c.name AS category_name, c.slug AS category_slug,
                    v.business_name AS vendor_name,
                    (SELECT COUNT(*) FROM bookings b WHERE b.service_id = s.id) AS bookingCount
             FROM services s
             LEFT JOIN categories c ON c.id = s.category_id
             LEFT JOIN vendors v ON v.id = s.vendor_id
             {$whereSQL}
             ORDER BY s.created_at DESC
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach ($bind as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();

        Response::success([
            'services' => $stmt->fetchAll(),
            'total'    => $total,
            'page'     => $page,
            'limit'    => $limit
        ]);
    }

    // ── PATCH /api/admin/services/{id}/status ──────────────────
    if ($method === 'PATCH' && preg_match('#^/api/admin/services/(\d+)/status$#', $uri, $m)) {
        $serviceId = (int)$m[1];
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $rawStatus = strtolower(trim((string)($input['status'] ?? '')));
        $status = match ($rawStatus) {
            'enabled', 'active' => 'active',
            'disabled', 'inactive' => 'inactive',
            default => null,
        };
        if (!$status) Response::validation('Invalid service status');
        $db->prepare("UPDATE services SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$status, $serviceId]);
        Logger::info('Admin updated service status', ['admin_id' => $auth['user_id'], 'service_id' => $serviceId, 'status' => $status]);
        Response::success(['id' => $serviceId, 'status' => $status], 200, 'Service status updated');
    }

    // ── GET /api/admin/categories ──────────────────────────────
    if ($method === 'GET' && $uri === '/api/admin/categories') {
        $type = $_GET['type'] ?? 'service';
        $cols = "id, name, slug, type, status, parent_id, created_at, updated_at";
        foreach (['icon','image_url','sort_order','commission_rate'] as $col) {
            $chk = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = ?");
            $chk->execute([$col]);
            $cols .= ((int)$chk->fetchColumn() > 0) ? ", {$col}" : ", NULL AS {$col}";
        }
        $stmt = $db->prepare("SELECT {$cols} FROM categories WHERE type = ? OR type IS NULL ORDER BY COALESCE(sort_order,0) ASC, name ASC");
        $stmt->execute([$type]);
        Response::success(['categories' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // ── POST /api/admin/categories ────────────────────────────
    if ($method === 'POST' && $uri === '/api/admin/categories') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $name  = trim($input['name'] ?? '');
        if (!$name) Response::validation('Category name is required');
        $slug = trim($input['slug'] ?? '');
        if (!$slug) {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
            $slug = trim($slug, '-');
        }
        $type   = ($input['type'] ?? 'service') === 'product' ? 'product' : 'service';
        $status = ($input['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $dup = $db->prepare("SELECT id FROM categories WHERE slug = ? LIMIT 1");
        $dup->execute([$slug]);
        if ($dup->fetchColumn()) $slug = $slug . '-' . substr((string)time(), -4);
        $db->prepare("INSERT INTO categories (name, slug, type, status, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())")
           ->execute([$name, $slug, $type, $status]);
        $newId = (int)$db->lastInsertId();
        Logger::info('Admin created category', ['admin_id' => $auth['user_id'], 'category_id' => $newId, 'name' => $name]);
        Response::success(['id' => $newId, 'name' => $name, 'slug' => $slug, 'status' => $status], 201, 'Category created');
    }

    // ── GET /api/admin/categories/{id}/detail ─────────────────────
    if ($method === 'GET' && preg_match('#^/api/admin/categories/(\d+)/detail$#', $uri, $m)) {
        $categoryId = (int)$m[1];

        $cols = "id, name, slug, type, status, parent_id, created_at";
        foreach (['icon','image_url','sort_order','commission_rate','inspection_price'] as $col) {
            $chk = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = ?");
            $chk->execute([$col]);
            $cols .= ((int)$chk->fetchColumn() > 0) ? ", {$col}" : ", NULL AS {$col}";
        }

        $stmt = $db->prepare("SELECT {$cols} FROM categories WHERE id = ? LIMIT 1");
        $stmt->execute([$categoryId]);
        $category = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$category) Response::notFound('Category');

        $totalBookings = 0;
        $completedJobs = 0;
        try {
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM bookings b
                 JOIN services s ON s.id = b.service_id
                 WHERE s.category_id = ?"
            );
            $stmt->execute([$categoryId]);
            $totalBookings = (int)$stmt->fetchColumn();

            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM bookings b
                 JOIN services s ON s.id = b.service_id
                 WHERE s.category_id = ? AND b.status IN ('completed','done','delivered')"
            );
            $stmt->execute([$categoryId]);
            $completedJobs = (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            // bookings or services table absent on this database
        }

        // --- REVENUE TOTAL for this category ---
        $revenueTotal = 0.0;
        try {
            $stmt = $db->prepare(
                "SELECT COALESCE(SUM(b.total), 0)
                 FROM bookings b
                 JOIN services s ON s.id = b.service_id
                 WHERE s.category_id = ? AND b.status IN ('completed','done','delivered')"
            );
            $stmt->execute([$categoryId]);
            $revenueTotal = (float)$stmt->fetchColumn();
        } catch (PDOException $ignored) {}

        // --- TOP 3 VENDORS in this category by job count ---
        $topVendorsInCat = [];
        try {
            $stmt = $db->prepare(
                "SELECT v.id, v.business_name, COUNT(*) AS jobs,
                        COALESCE(v.rating, 0) AS rating
                 FROM bookings b
                 JOIN services s ON s.id = b.service_id
                 JOIN vendors v ON v.id = b.vendor_id
                 WHERE s.category_id = ? AND b.vendor_id IS NOT NULL
                 GROUP BY v.id, v.business_name, v.rating
                 ORDER BY jobs DESC
                 LIMIT 3"
            );
            $stmt->execute([$categoryId]);
            $topVendorsInCat = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $ignored) {}

        // --- PENDING (unassigned) bookings for this category ---
        $pendingBookings = 0;
        try {
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM bookings b
                 JOIN services s ON s.id = b.service_id
                 WHERE s.category_id = ?
                   AND b.vendor_id IS NULL
                   AND b.status NOT IN ('completed','done','delivered','cancelled','rejected')"
            );
            $stmt->execute([$categoryId]);
            $pendingBookings = (int)$stmt->fetchColumn();
        } catch (PDOException $ignored) {}

        $vendorList = [];
        try {
            $hasIsOnline = (int)$db->query(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND COLUMN_NAME = 'is_online'"
            )->fetchColumn() > 0;
            $onlineCol = $hasIsOnline ? ', v.is_online' : ', NULL AS is_online';

            $stmt = $db->prepare(
                "SELECT v.id, v.business_name, v.status{$onlineCol},
                        u.phone AS owner_phone, u.name AS owner_name
                 FROM vendors v
                 LEFT JOIN users u ON u.id = v.user_id
                 WHERE v.id IN (SELECT vendor_id FROM vendor_categories WHERE category_id = ?)
                 ORDER BY v.status = 'active' DESC, v.business_name ASC
                 LIMIT 50"
            );
            $stmt->execute([$categoryId]);
            $vendorList = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // vendors unavailable
        }

        $activeVendors = count(array_filter($vendorList, fn($v) => $v['status'] === 'active'));

        Response::success([
            'category' => $category,
            'stats'    => [
                'total_bookings'  => $totalBookings,
                'completed_jobs'  => $completedJobs,
                'active_vendors'  => $activeVendors,
                'revenue_total'   => $revenueTotal,
                'pending_bookings'=> $pendingBookings,
            ],
            'top_vendors_in_cat' => $topVendorsInCat,
            'vendors' => $vendorList,
        ]);
    }

    // ── GET /api/admin/categories/{id}/stats ─────────────────
    if ($method === 'GET' && preg_match('#^/api/admin/categories/(\d+)/stats$#', $uri, $m)) {
        $categoryId = (int)$m[1];

        // Category core fields + optional pricing/media columns
        $cols = "id, name, slug, type, status";
        foreach (['inspection_price','commission_pct','commission_rate','image_url','icon'] as $col) {
            $chk = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = ?");
            $chk->execute([$col]);
            $cols .= ((int)$chk->fetchColumn() > 0) ? ", {$col}" : ", NULL AS {$col}";
        }
        $stmt = $db->prepare("SELECT {$cols} FROM categories WHERE id = ? LIMIT 1");
        $stmt->execute([$categoryId]);
        $category = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$category) Response::notFound('Category');

        // Stats counters
        $stats = ['total_bookings' => 0, 'active_vendors' => 0, 'pending_bookings' => 0, 'completed_bookings' => 0];
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM bookings b JOIN services s ON s.id = b.service_id WHERE s.category_id = ?");
            $stmt->execute([$categoryId]);
            $stats['total_bookings'] = (int)$stmt->fetchColumn();

            $stmt = $db->prepare("SELECT COUNT(*) FROM bookings b JOIN services s ON s.id = b.service_id WHERE s.category_id = ? AND b.status IN ('completed','done','delivered')");
            $stmt->execute([$categoryId]);
            $stats['completed_bookings'] = (int)$stmt->fetchColumn();

            $stmt = $db->prepare("SELECT COUNT(*) FROM bookings b JOIN services s ON s.id = b.service_id WHERE s.category_id = ? AND b.vendor_id IS NULL AND b.status NOT IN ('completed','done','delivered','cancelled','rejected')");
            $stmt->execute([$categoryId]);
            $stats['pending_bookings'] = (int)$stmt->fetchColumn();
        } catch (PDOException $ignored) {}

        // Vendors with per-category job counts and acceptance rate
        $vendors = [];
        try {
            $hasRating = (int)$db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND COLUMN_NAME = 'rating'")->fetchColumn() > 0;
            $ratingCol = $hasRating ? 'COALESCE(v.rating, 0)' : '0';

            $stmt = $db->prepare(
                "SELECT v.id, v.business_name, v.status,
                        {$ratingCol} AS rating,
                        COUNT(DISTINCT b.id) AS total_jobs,
                        SUM(CASE WHEN b.status IN ('completed','done','delivered') THEN 1 ELSE 0 END) AS completed_jobs
                 FROM vendors v
                 LEFT JOIN bookings b ON b.vendor_id = v.id
                     AND b.service_id IN (SELECT id FROM services WHERE category_id = ?)
                 WHERE v.id IN (SELECT vendor_id FROM vendor_categories WHERE category_id = ?)
                 GROUP BY v.id, v.business_name, v.status, {$ratingCol}
                 ORDER BY v.status = 'active' DESC, completed_jobs DESC
                 LIMIT 50"
            );
            $stmt->execute([$categoryId, $categoryId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stats['active_vendors'] = count(array_filter($rows, fn($r) => $r['status'] === 'active'));

            foreach ($rows as $r) {
                $total     = (int)($r['total_jobs']     ?? 0);
                $completed = (int)($r['completed_jobs'] ?? 0);
                $vendors[] = [
                    'id'              => (int)$r['id'],
                    'business_name'   => $r['business_name'],
                    'is_active'       => $r['status'] === 'active',
                    'rating'          => ($r['rating'] > 0) ? (float)$r['rating'] : null,
                    'total_jobs'      => $total,
                    'completed_jobs'  => $completed,
                    'acceptance_rate' => $total > 0 ? round($completed / $total * 100) : null,
                ];
            }
        } catch (PDOException $ignored) {}

        // Top locations from bookings.customer_locality
        $topLocations = [];
        try {
            $hasLocality = (int)$db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'customer_locality'")->fetchColumn() > 0;
            if ($hasLocality) {
                $stmt = $db->prepare(
                    "SELECT COALESCE(b.customer_locality, 'Unknown') AS locality, COUNT(*) AS count
                     FROM bookings b JOIN services s ON s.id = b.service_id
                     WHERE s.category_id = ? AND b.customer_locality IS NOT NULL AND b.customer_locality != ''
                     GROUP BY locality ORDER BY count DESC LIMIT 8"
                );
                $stmt->execute([$categoryId]);
                $topLocations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $ignored) {}

        // Booking-type breakdown: inspection / free_lead / direct_vendor
        $bookingModes = ['inspection' => 0, 'free_lead' => 0, 'direct_vendor' => 0];
        try {
            $hasMode = (int)$db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'booking_mode'")->fetchColumn() > 0;
            if ($hasMode) {
                $stmt = $db->prepare(
                    "SELECT COALESCE(LOWER(b.booking_mode), 'free_lead') AS mode, COUNT(*) AS cnt
                     FROM bookings b JOIN services s ON s.id = b.service_id
                     WHERE s.category_id = ?
                     GROUP BY mode"
                );
                $stmt->execute([$categoryId]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $mode = trim((string)$row['mode']) ?: 'free_lead';
                    $bookingModes[$mode] = (int)$row['cnt'];
                }
            }
        } catch (PDOException $ignored) {}

        Response::success([
            'category'      => $category,
            'stats'         => $stats,
            'vendors'       => $vendors,
            'top_locations' => $topLocations,
            'booking_modes' => $bookingModes,
        ]);
    }

    // ── GET /api/admin/categories/{id}/vendor/{vendor_id}/stats ──
    if ($method === 'GET' && preg_match('#^/api/admin/categories/(\d+)/vendor/(\d+)/stats$#', $uri, $m)) {
        $categoryId = (int)$m[1];
        $vendorId   = (int)$m[2];

        $vendorStats = ['total' => 0, 'accepted' => 0, 'rejected' => 0, 'completed' => 0, 'pending' => 0];
        try {
            $hasVendorResp = (int)$db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'vendor_response_status'")->fetchColumn() > 0;
            $respExpr = $hasVendorResp ? 'b.vendor_response_status' : "''";

            $stmt = $db->prepare(
                "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN {$respExpr} = 'vendor_accepted' THEN 1 ELSE 0 END) AS accepted,
                    SUM(CASE WHEN {$respExpr} = 'vendor_rejected' OR b.status = 'cancelled' THEN 1 ELSE 0 END) AS rejected,
                    SUM(CASE WHEN b.status IN ('completed','done','delivered') THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN b.status NOT IN ('completed','done','delivered','cancelled','rejected') THEN 1 ELSE 0 END) AS pending
                 FROM bookings b
                 JOIN services s ON s.id = b.service_id
                 WHERE s.category_id = ? AND b.vendor_id = ?"
            );
            $stmt->execute([$categoryId, $vendorId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $vendorStats = [
                'total'     => (int)($row['total']     ?? 0),
                'accepted'  => (int)($row['accepted']  ?? 0),
                'rejected'  => (int)($row['rejected']  ?? 0),
                'completed' => (int)($row['completed'] ?? 0),
                'pending'   => (int)($row['pending']   ?? 0),
            ];
        } catch (PDOException $ignored) {}

        Response::success([
            'category_id' => $categoryId,
            'vendor_id'   => $vendorId,
            'stats'       => $vendorStats,
        ]);
    }

    // ── POST /api/admin/categories/{id}/banner ──────────────────
    if ($method === 'POST' && preg_match('#^/api/admin/categories/(\d+)/banner$#', $uri, $m)) {
        $categoryId = (int)$m[1];

        if (empty($_FILES['banner']) || $_FILES['banner']['error'] === UPLOAD_ERR_NO_FILE) {
            Response::error('No file uploaded. Send file as multipart field "banner".', 400);
        }

        require_once SYSTEM_ROOT . '/core/helpers/UploadHelper.php';

        try {
            $url = UploadHelper::save($_FILES['banner'], 'categories');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }

        $hasImageUrl = (int)$db->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'image_url'"
        )->fetchColumn() > 0;
        if ($hasImageUrl) {
            $db->prepare("UPDATE categories SET image_url = ?, updated_at = NOW() WHERE id = ?")
               ->execute([$url, $categoryId]);
        }

        Logger::info('Admin updated category banner', ['admin_id' => $auth['user_id'], 'category_id' => $categoryId]);
        Response::success(['url' => $url], 200, 'Banner updated');
    }

    // ── PATCH /api/admin/categories/{id} ───────────────────────
    if ($method === 'PATCH' && preg_match('#^/api/admin/categories/(\d+)$#', $uri, $m)) {
        $categoryId = (int)$m[1];
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $updates = [];
        $bind = [':id' => $categoryId];
        foreach (['name','slug','status','icon','image_url','sort_order','commission_rate','commission_pct','inspection_price'] as $col) {
            if (!array_key_exists($col, $input)) continue;
            $chk = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = ?");
            $chk->execute([$col]);
            if ((int)$chk->fetchColumn() === 0) continue;
            $updates[] = "{$col} = :{$col}";
            $bind[":{$col}"] = match($col) {
                'sort_order'                  => (int)$input[$col],
                'commission_rate', 'commission_pct',
                'inspection_price'            => (float)$input[$col],
                default                       => trim((string)$input[$col]),
            };
        }
        if (!$updates) Response::validation('No supported category fields to update');
        $db->prepare("UPDATE categories SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = :id")->execute($bind);
        Logger::info('Admin updated category', ['admin_id' => $auth['user_id'], 'category_id' => $categoryId]);
        Response::success(['id' => $categoryId], 200, 'Category updated');
    }

    // ══ Packages — admin-controlled, per category (data-driven, zero hardcoding) ══

    // ── GET /api/admin/categories/{id}/packages ──────────────
    if ($method === 'GET' && preg_match('#^/api/admin/categories/(\d+)/packages$#', $uri, $m)) {
        $categoryId = (int)$m[1];
        try {
            $stmt = $db->prepare(
                "SELECT p.*, m.public_url AS cover_url
                 FROM packages p
                 LEFT JOIN media m ON m.id = p.cover_media_id AND m.deleted_at IS NULL
                 WHERE p.category_id = ?
                 ORDER BY p.sort_order ASC, p.id ASC"
            );
            $stmt->execute([$categoryId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['features']  = $r['features'] ? (json_decode($r['features'], true) ?: []) : [];
                $r['is_active'] = (int)$r['is_active'];
            }
            unset($r);
            Response::success(['packages' => $rows]);
        } catch (PDOException $e) {
            // packages table not present yet (migration not applied) — degrade gracefully
            Response::success(['packages' => [], 'note' => 'packages table not available']);
        }
    }

    // ── POST /api/admin/categories/{id}/packages ─────────────
    if ($method === 'POST' && preg_match('#^/api/admin/categories/(\d+)/packages$#', $uri, $m)) {
        $categoryId = (int)$m[1];
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $name  = trim((string)($input['name'] ?? ''));
        if ($name === '') Response::validation('Package name is required');
        [$cols, $vals, $bind] = adminBuildPackageFields($input, true);
        array_unshift($cols, 'category_id', 'name');
        array_unshift($vals, ':category_id', ':name');
        $bind[':category_id'] = $categoryId;
        $bind[':name']        = $name;
        try {
            $sql = "INSERT INTO packages (" . implode(', ', $cols) . ", created_at, updated_at) VALUES ("
                 . implode(', ', $vals) . ", NOW(), NOW())";
            $db->prepare($sql)->execute($bind);
            $pkgId = (int)$db->lastInsertId();
            Logger::info('Admin created package', ['admin_id' => $auth['user_id'], 'category_id' => $categoryId, 'package_id' => $pkgId]);
            Response::success(['id' => $pkgId], 201, 'Package created');
        } catch (PDOException $e) {
            Logger::error('Package create failed', ['error' => $e->getMessage()]);
            Response::error('Could not create package. Ensure the packages migration is applied.', 500);
        }
    }

    // ── PATCH /api/admin/packages/{id} ───────────────────────
    if ($method === 'PATCH' && preg_match('#^/api/admin/packages/(\d+)$#', $uri, $m)) {
        $pkgId = (int)$m[1];
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        [$cols, $vals, $bind] = adminBuildPackageFields($input, false);
        if (array_key_exists('name', $input)) {
            $nm = trim((string)$input['name']);
            if ($nm === '') Response::validation('Package name cannot be empty');
            $cols[] = 'name'; $vals[] = ':name'; $bind[':name'] = $nm;
        }
        if (!$cols) Response::validation('No package fields to update');
        $set = [];
        foreach ($cols as $i => $c) { $set[] = "{$c} = {$vals[$i]}"; }
        $bind[':id'] = $pkgId;
        try {
            $db->prepare("UPDATE packages SET " . implode(', ', $set) . ", updated_at = NOW() WHERE id = :id")->execute($bind);
            Logger::info('Admin updated package', ['admin_id' => $auth['user_id'], 'package_id' => $pkgId]);
            Response::success(['id' => $pkgId], 200, 'Package updated');
        } catch (PDOException $e) {
            Logger::error('Package update failed', ['error' => $e->getMessage()]);
            Response::error('Could not update package.', 500);
        }
    }

    // ── POST /api/admin/packages/{id}/cover ──────────────────
    if ($method === 'POST' && preg_match('#^/api/admin/packages/(\d+)/cover$#', $uri, $m)) {
        $pkgId = (int)$m[1];
        if (empty($_FILES['cover']) || $_FILES['cover']['error'] === UPLOAD_ERR_NO_FILE) {
            Response::error('No file uploaded. Send file as multipart field "cover".', 400);
        }
        require_once SYSTEM_ROOT . '/core/helpers/UploadHelper.php';
        require_once SYSTEM_ROOT . '/core/helpers/Media.php';

        $pkgStmt = $db->prepare("SELECT category_id, city_id, cover_media_id FROM packages WHERE id = ? LIMIT 1");
        $pkgStmt->execute([$pkgId]);
        $pkg = $pkgStmt->fetch(PDO::FETCH_ASSOC);
        if (!$pkg) Response::notFound('Package');

        try {
            $url = UploadHelper::save($_FILES['cover'], 'packages');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }

        // Record in the unified media table (admin upload -> approved), then point
        // the package at it via cover_media_id for a clean customer-side read.
        $mediaId = Media::add($db, [
            'owner_type'          => 'package',
            'owner_id'            => $pkgId,
            'media_type'          => 'image',
            'purpose'             => 'cover_photo',
            'storage_provider'    => 'local',
            'storage_key'         => $url,
            'public_url'          => $url,
            'city_id'             => !empty($pkg['city_id']) ? (int)$pkg['city_id'] : null,
            'status'              => Media::STATUS_APPROVED,
            'uploaded_by_user_id' => (int)$auth['user_id'],
        ]);
        if ($mediaId > 0) {
            if (!empty($pkg['cover_media_id'])) { Media::softDelete($db, (int)$pkg['cover_media_id']); }
            $db->prepare("UPDATE packages SET cover_media_id = ?, updated_at = NOW() WHERE id = ?")->execute([$mediaId, $pkgId]);
        }
        Logger::info('Admin uploaded package cover', ['admin_id' => $auth['user_id'], 'package_id' => $pkgId, 'media_id' => $mediaId]);
        Response::success(['url' => $url, 'media_id' => $mediaId], 200, 'Cover updated');
    }

    // ── DELETE /api/admin/packages/{id} ──────────────────────
    if ($method === 'DELETE' && preg_match('#^/api/admin/packages/(\d+)$#', $uri, $m)) {
        $pkgId = (int)$m[1];
        try {
            $c = $db->prepare("SELECT cover_media_id FROM packages WHERE id = ? LIMIT 1");
            $c->execute([$pkgId]);
            $coverId = (int)($c->fetchColumn() ?: 0);
            if ($coverId > 0) {
                require_once SYSTEM_ROOT . '/core/helpers/Media.php';
                Media::softDelete($db, $coverId);
            }
            $db->prepare("DELETE FROM packages WHERE id = ?")->execute([$pkgId]);
            Logger::info('Admin deleted package', ['admin_id' => $auth['user_id'], 'package_id' => $pkgId]);
            Response::success(['id' => $pkgId], 200, 'Package deleted');
        } catch (PDOException $e) {
            Logger::error('Package delete failed', ['error' => $e->getMessage()]);
            Response::error('Could not delete package.', 500);
        }
    }

    // ══ Expert Visit pricing — global default + per-category/per-city overrides ══
    // Precedence (resolver): category+city > category > city > global default.

    // ── GET /api/admin/expert-visit-pricing ──────────────────
    if ($method === 'GET' && $uri === '/api/admin/expert-visit-pricing') {
        require_once SYSTEM_ROOT . '/core/helpers/ExpertVisitPricing.php';
        $global    = ExpertVisitPricing::globalDefault($db);
        $overrides = [];
        $cities    = [];
        try {
            $stmt = $db->query(
                "SELECT e.id, e.category_id, e.city_id, e.amount, e.is_free, e.is_active,
                        c.name AS category_name, ci.name AS city_name
                 FROM expert_visit_pricing e
                 LEFT JOIN categories c  ON c.id  = e.category_id
                 LEFT JOIN cities     ci ON ci.id = e.city_id
                 ORDER BY (e.category_id IS NOT NULL) DESC, (e.city_id IS NOT NULL) DESC, e.id DESC"
            );
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $overrides[] = [
                    'id'            => (int)$r['id'],
                    'category_id'   => $r['category_id'] !== null ? (int)$r['category_id'] : null,
                    'category_name' => $r['category_name'],
                    'city_id'       => $r['city_id'] !== null ? (int)$r['city_id'] : null,
                    'city_name'     => $r['city_name'],
                    'amount'        => $r['amount'] !== null ? (float)$r['amount'] : null,
                    'is_free'       => (int)$r['is_free'],
                    'is_active'     => (int)$r['is_active'],
                ];
            }
        } catch (PDOException $e) { /* table not migrated yet */ }
        try {
            $cs = $db->query("SELECT id, name FROM cities WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");
            $cities = $cs->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { /* cities not present */ }
        Response::success(['global' => $global, 'overrides' => $overrides, 'cities' => $cities]);
    }

    // ── PATCH /api/admin/expert-visit-pricing/global ─────────
    if ($method === 'PATCH' && $uri === '/api/admin/expert-visit-pricing/global') {
        $input  = json_decode(file_get_contents('php://input'), true) ?? [];
        $isFree = array_key_exists('is_free', $input) ? ($input['is_free'] ? '1' : '0') : null;
        $amount = array_key_exists('amount', $input) && $input['amount'] !== '' && $input['amount'] !== null
            ? (string)max(0, (float)$input['amount']) : null;
        $upsert = $db->prepare(
            "INSERT INTO app_settings (setting_key, setting_value, value_type, is_public, updated_at)
             VALUES (:k, :v, :t, 1, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
        );
        if ($amount !== null) $upsert->execute([':k' => 'expert_visit_fee',  ':v' => $amount, ':t' => 'number']);
        if ($isFree !== null) $upsert->execute([':k' => 'expert_visit_free', ':v' => $isFree, ':t' => 'boolean']);
        Logger::info('Admin updated expert-visit global default', ['admin_id' => $auth['user_id'], 'amount' => $amount, 'is_free' => $isFree]);
        require_once SYSTEM_ROOT . '/core/helpers/ExpertVisitPricing.php';
        Response::success(['global' => ExpertVisitPricing::globalDefault($db)], 200, 'Global default updated');
    }

    // ── POST /api/admin/expert-visit-pricing ─────────────────
    if ($method === 'POST' && $uri === '/api/admin/expert-visit-pricing') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $catId  = (isset($input['category_id']) && (int)$input['category_id'] > 0) ? (int)$input['category_id'] : null;
        $cityId = (isset($input['city_id']) && (int)$input['city_id'] > 0) ? (int)$input['city_id'] : null;
        $isFree = !empty($input['is_free']) ? 1 : 0;
        $amount = (!$isFree && isset($input['amount']) && $input['amount'] !== '' && $input['amount'] !== null)
            ? max(0, (float)$input['amount']) : null;
        if ($catId === null && $cityId === null) {
            Response::validation('An override must scope by category and/or city (the global default is set separately).');
        }
        if (!$isFree && $amount === null) {
            Response::validation('Set an amount, or mark this override as Free.');
        }
        try {
            // Dedup: one override per (category_id, city_id) scope — update if it exists.
            $find = $db->prepare(
                "SELECT id FROM expert_visit_pricing
                  WHERE (category_id <=> :cat) AND (city_id <=> :city) LIMIT 1"
            );
            $find->execute([':cat' => $catId, ':city' => $cityId]);
            $existingId = (int)($find->fetchColumn() ?: 0);
            if ($existingId > 0) {
                $db->prepare("UPDATE expert_visit_pricing SET amount = :a, is_free = :f, is_active = 1, updated_at = NOW() WHERE id = :id")
                   ->execute([':a' => $amount, ':f' => $isFree, ':id' => $existingId]);
                $newId = $existingId;
            } else {
                $db->prepare("INSERT INTO expert_visit_pricing (category_id, city_id, amount, is_free, is_active, created_at, updated_at) VALUES (:cat, :city, :a, :f, 1, NOW(), NOW())")
                   ->execute([':cat' => $catId, ':city' => $cityId, ':a' => $amount, ':f' => $isFree]);
                $newId = (int)$db->lastInsertId();
            }
            Logger::info('Admin saved expert-visit override', ['admin_id' => $auth['user_id'], 'id' => $newId, 'category_id' => $catId, 'city_id' => $cityId, 'is_free' => $isFree]);
            Response::success(['id' => $newId], 201, 'Override saved');
        } catch (PDOException $e) {
            Logger::error('Expert-visit override save failed', ['error' => $e->getMessage()]);
            Response::error('Could not save override. Ensure the expert_visit_pricing migration is applied.', 500);
        }
    }

    // ── PATCH /api/admin/expert-visit-pricing/{id} ───────────
    if ($method === 'PATCH' && preg_match('#^/api/admin/expert-visit-pricing/(\d+)$#', $uri, $m)) {
        $id    = (int)$m[1];
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $sets = []; $bind = [':id' => $id];
        if (array_key_exists('is_free', $input))   { $sets[] = 'is_free = :f';   $bind[':f'] = $input['is_free'] ? 1 : 0; }
        if (array_key_exists('is_active', $input)) { $sets[] = 'is_active = :ac'; $bind[':ac'] = $input['is_active'] ? 1 : 0; }
        if (array_key_exists('amount', $input)) {
            $sets[] = 'amount = :a';
            $bind[':a'] = ($input['amount'] === '' || $input['amount'] === null) ? null : max(0, (float)$input['amount']);
        }
        if (!$sets) Response::validation('No expert-visit override fields to update');
        try {
            $db->prepare("UPDATE expert_visit_pricing SET " . implode(', ', $sets) . ", updated_at = NOW() WHERE id = :id")->execute($bind);
            Logger::info('Admin updated expert-visit override', ['admin_id' => $auth['user_id'], 'id' => $id]);
            Response::success(['id' => $id], 200, 'Override updated');
        } catch (PDOException $e) {
            Response::error('Could not update override.', 500);
        }
    }

    // ── DELETE /api/admin/expert-visit-pricing/{id} ──────────
    if ($method === 'DELETE' && preg_match('#^/api/admin/expert-visit-pricing/(\d+)$#', $uri, $m)) {
        $id = (int)$m[1];
        try {
            $db->prepare("DELETE FROM expert_visit_pricing WHERE id = ?")->execute([$id]);
            Logger::info('Admin deleted expert-visit override', ['admin_id' => $auth['user_id'], 'id' => $id]);
            Response::success(['id' => $id], 200, 'Override removed');
        } catch (PDOException $e) {
            Response::error('Could not remove override.', 500);
        }
    }

    // ══ Media approvals — vendor work-photo moderation + auto-approve toggle ══

    // ── GET /api/admin/media/pending ─────────────────────────
    if ($method === 'GET' && $uri === '/api/admin/media/pending') {
        require_once SYSTEM_ROOT . '/core/helpers/Media.php';
        // auto-approve mode (app_settings; default off = manual review)
        $auto = false;
        try {
            $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'media_auto_approve' LIMIT 1");
            $s->execute();
            $auto = in_array(strtolower(trim((string)($s->fetchColumn() ?: '0'))), ['1', 'true', 'yes', 'on'], true);
        } catch (PDOException $e) { /* setting absent -> off */ }

        $items = [];
        $rows  = Media::pendingQueue($db, 'vendor', 200);
        if ($rows) {
            // attach the owning vendor's name for context
            $vids = array_values(array_unique(array_map(fn($r) => (int)$r['owner_id'], $rows)));
            $names = [];
            if ($vids) {
                $ph = implode(',', array_fill(0, count($vids), '?'));
                try {
                    $vs = $db->prepare("SELECT id, business_name FROM vendors WHERE id IN ($ph)");
                    $vs->execute($vids);
                    foreach ($vs->fetchAll(PDO::FETCH_ASSOC) as $v) { $names[(int)$v['id']] = $v['business_name']; }
                } catch (PDOException $e) { /* ignore */ }
            }
            foreach ($rows as $r) {
                $items[] = [
                    'id'             => (int)$r['id'],
                    'vendor_id'      => (int)$r['owner_id'],
                    'vendor_name'    => $names[(int)$r['owner_id']] ?? ('Vendor #' . (int)$r['owner_id']),
                    'url'            => $r['public_url'] ?? $r['storage_key'],
                    'caption'        => $r['caption'] ?? null,
                    'location_label' => $r['location_label'] ?? null,
                    'created_at'     => $r['created_at'] ?? null,
                ];
            }
        }
        Response::success(['auto_approve' => $auto, 'items' => $items]);
    }

    // ── PATCH /api/admin/media/auto-approve  {enabled:bool} ──
    if ($method === 'PATCH' && $uri === '/api/admin/media/auto-approve') {
        $input   = json_decode(file_get_contents('php://input'), true) ?? [];
        $enabled = !empty($input['enabled']) ? '1' : '0';
        $db->prepare(
            "INSERT INTO app_settings (setting_key, setting_value, value_type, label, is_public, updated_at)
             VALUES ('media_auto_approve', :v, 'boolean', 'Auto-approve vendor media (1=auto, 0=manual)', 0, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
        )->execute([':v' => $enabled]);
        Logger::info('Admin set media auto-approve', ['admin_id' => $auth['user_id'], 'enabled' => $enabled]);
        Response::success(['auto_approve' => $enabled === '1'], 200, 'Approval mode updated');
    }

    // ── PATCH /api/admin/media/{id}/status  {status:'approved'|'rejected'} ──
    if ($method === 'PATCH' && preg_match('#^/api/admin/media/(\d+)/status$#', $uri, $m)) {
        require_once SYSTEM_ROOT . '/core/helpers/Media.php';
        $mediaId = (int)$m[1];
        $input   = json_decode(file_get_contents('php://input'), true) ?? [];
        $status  = strtolower(trim((string)($input['status'] ?? '')));
        $notes   = isset($input['notes']) ? trim((string)$input['notes']) : null;
        if (!in_array($status, [Media::STATUS_APPROVED, Media::STATUS_REJECTED], true)) {
            Response::validation("status must be 'approved' or 'rejected'");
        }
        $ok = Media::setStatus($db, $mediaId, $status, (int)$auth['user_id'], $notes);
        if (!$ok) Response::error('Could not update media status (not found or already handled).', 400);
        Logger::info('Admin moderated media', ['admin_id' => $auth['user_id'], 'media_id' => $mediaId, 'status' => $status]);
        Response::success(['id' => $mediaId, 'status' => $status], 200, 'Media ' . $status);
    }

    // ══ Roles & permissions (data-driven RBAC) ═══════════════════════════════
    //  Foundation for future role-scoped "windows" (Category Head, Ops Manager,
    //  Expert, Finance, Moderator…). The founder composes ROLES from the fixed
    //  permission catalog here — no code change to add a role. Every write is an
    //  ADDITIVE gate: Permission::enforce('roles.manage') — for today's admin this
    //  passes trivially (legacy admin bypass), so current access is unchanged; it
    //  becomes the real gate once non-admin panel roles exist. See
    //  core/helpers/Permission.php + migrations/2026_08_13_001_rbac_foundation.sql.
    require_once SYSTEM_ROOT . '/core/helpers/Permission.php';

    // ── GET /api/admin/roles — list roles (+ permission / user counts) ────────
    if ($method === 'GET' && $uri === '/api/admin/roles') {
        Permission::enforce($db, $auth, 'roles.view');
        $rows = $db->query(
            "SELECT r.id, r.name, r.slug, r.description, r.is_system, r.is_active, r.created_at,
                    (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permission_count,
                    (SELECT COUNT(DISTINCT ur.user_id) FROM user_roles ur WHERE ur.role_id = r.id) AS user_count
               FROM roles r
              ORDER BY r.is_system DESC, r.name ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id']; $r['is_system'] = (int)$r['is_system'];
            $r['is_active'] = (int)$r['is_active'];
            $r['permission_count'] = (int)$r['permission_count'];
            $r['user_count'] = (int)$r['user_count'];
        }
        Response::success(['roles' => $rows]);
    }

    // ── GET /api/admin/permissions — the capability catalog (grouped) ─────────
    if ($method === 'GET' && $uri === '/api/admin/permissions') {
        Permission::enforce($db, $auth, 'roles.view');
        $rows = $db->query(
            "SELECT id, perm_key, label, engine, group_label, is_active
               FROM permissions WHERE is_active = 1
              ORDER BY engine, group_label, perm_key"
        )->fetchAll(PDO::FETCH_ASSOC);
        $grouped = [];
        foreach ($rows as $p) {
            $key = $p['engine'] . ' · ' . $p['group_label'];
            $grouped[$key][] = ['id' => (int)$p['id'], 'perm_key' => $p['perm_key'], 'label' => $p['label'], 'engine' => $p['engine']];
        }
        Response::success(['permissions' => $rows, 'grouped' => $grouped]);
    }

    // ── GET /api/admin/roles/{id} — role detail + its permission keys ─────────
    if ($method === 'GET' && preg_match('#^/api/admin/roles/(\d+)$#', $uri, $m)) {
        Permission::enforce($db, $auth, 'roles.view');
        $roleId = (int)$m[1];
        $rs = $db->prepare("SELECT id, name, slug, description, is_system, is_active, created_at FROM roles WHERE id = ? LIMIT 1");
        $rs->execute([$roleId]);
        $role = $rs->fetch(PDO::FETCH_ASSOC);
        if (!$role) Response::notFound('Role');
        $ps = $db->prepare(
            "SELECT p.perm_key FROM role_permissions rp
               JOIN permissions p ON p.id = rp.permission_id
              WHERE rp.role_id = ? ORDER BY p.perm_key"
        );
        $ps->execute([$roleId]);
        $role['id'] = (int)$role['id']; $role['is_system'] = (int)$role['is_system']; $role['is_active'] = (int)$role['is_active'];
        $role['permissions'] = $ps->fetchAll(PDO::FETCH_COLUMN) ?: [];
        Response::success(['role' => $role]);
    }

    // ── POST /api/admin/roles — create a role (data-driven, no code change) ───
    if ($method === 'POST' && $uri === '/api/admin/roles') {
        Permission::enforce($db, $auth, 'roles.manage');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $v = Validator::make($input, [
            'name'        => 'required|string|max:100',
            'slug'        => 'string|max:64',
            'description' => 'string|max:255',
        ]);
        if ($v->fails()) Response::validation($v->firstError(), $v->errors());

        $name = trim((string)$input['name']);
        $slug = trim((string)($input['slug'] ?? ''));
        if ($slug === '') $slug = $name;
        // normalise: lowercase, non-alphanumeric -> underscore, collapse + trim
        $slug = trim(preg_replace('/_+/', '_', preg_replace('/[^a-z0-9]+/', '_', strtolower($slug))), '_');
        if ($slug === '') Response::validation('Could not derive a valid slug from the name');

        $exists = $db->prepare("SELECT id FROM roles WHERE slug = ? LIMIT 1");
        $exists->execute([$slug]);
        if ($exists->fetchColumn()) Response::error('A role with that slug already exists.', 409);

        $db->prepare("INSERT INTO roles (name, slug, description, is_system, is_active) VALUES (?, ?, ?, 0, 1)")
           ->execute([$name, $slug, trim((string)($input['description'] ?? '')) ?: null]);
        $newId = (int)$db->lastInsertId();
        Logger::info('Admin created role', ['admin_id' => $auth['user_id'], 'role_id' => $newId, 'slug' => $slug]);
        Response::success(['id' => $newId, 'name' => $name, 'slug' => $slug], 201, 'Role created');
    }

    // ── PATCH /api/admin/roles/{id} — edit name/description/active ────────────
    if ($method === 'PATCH' && preg_match('#^/api/admin/roles/(\d+)$#', $uri, $m)) {
        Permission::enforce($db, $auth, 'roles.manage');
        $roleId = (int)$m[1];
        $input  = json_decode(file_get_contents('php://input'), true) ?? [];
        $rs = $db->prepare("SELECT id, is_system FROM roles WHERE id = ? LIMIT 1");
        $rs->execute([$roleId]);
        $role = $rs->fetch(PDO::FETCH_ASSOC);
        if (!$role) Response::notFound('Role');

        $set = []; $bind = [];
        if (array_key_exists('name', $input) && trim((string)$input['name']) !== '') {
            $set[] = 'name = ?'; $bind[] = trim((string)$input['name']);
        }
        if (array_key_exists('description', $input)) {
            $set[] = 'description = ?'; $bind[] = trim((string)$input['description']) ?: null;
        }
        if (array_key_exists('is_active', $input)) {
            // A system role (super_admin) can never be deactivated — that would lock out admin.
            if ((int)$role['is_system'] === 1 && !$input['is_active']) {
                Response::error('A system role cannot be deactivated.', 400);
            }
            $set[] = 'is_active = ?'; $bind[] = $input['is_active'] ? 1 : 0;
        }
        if (!$set) Response::validation('Nothing to update');
        $set[] = 'updated_at = NOW()';
        $bind[] = $roleId;
        $db->prepare("UPDATE roles SET " . implode(', ', $set) . " WHERE id = ?")->execute($bind);
        Logger::info('Admin updated role', ['admin_id' => $auth['user_id'], 'role_id' => $roleId]);
        Response::success(['id' => $roleId], 200, 'Role updated');
    }

    // ── PUT /api/admin/roles/{id}/permissions — set the role's permission set ─
    //     Body: { permissions: ["media.approve","bookings.assign", ...] }  (keys or ids)
    if ($method === 'PUT' && preg_match('#^/api/admin/roles/(\d+)/permissions$#', $uri, $m)) {
        Permission::enforce($db, $auth, 'roles.manage');
        $roleId = (int)$m[1];
        $input  = json_decode(file_get_contents('php://input'), true) ?? [];
        $rs = $db->prepare("SELECT id, is_system FROM roles WHERE id = ? LIMIT 1");
        $rs->execute([$roleId]);
        $role = $rs->fetch(PDO::FETCH_ASSOC);
        if (!$role) Response::notFound('Role');
        // super_admin keeps ALL permissions — its set is locked (belt-and-braces).
        if ((int)$role['is_system'] === 1) Response::error('A system role\'s permissions are locked.', 400);

        $wanted = $input['permissions'] ?? null;
        if (!is_array($wanted)) Response::validation('permissions must be an array of permission keys or ids');

        // Resolve keys/ids -> existing permission ids.
        $ids = [];
        if ($wanted) {
            $keys = []; $numIds = [];
            foreach ($wanted as $w) {
                if (is_int($w) || (is_string($w) && ctype_digit($w))) { $numIds[] = (int)$w; }
                else { $keys[] = (string)$w; }
            }
            if ($keys) {
                $ph = implode(',', array_fill(0, count($keys), '?'));
                $q = $db->prepare("SELECT id FROM permissions WHERE perm_key IN ($ph)");
                $q->execute($keys);
                $ids = array_merge($ids, $q->fetchAll(PDO::FETCH_COLUMN));
            }
            if ($numIds) {
                $ph = implode(',', array_fill(0, count($numIds), '?'));
                $q = $db->prepare("SELECT id FROM permissions WHERE id IN ($ph)");
                $q->execute($numIds);
                $ids = array_merge($ids, $q->fetchAll(PDO::FETCH_COLUMN));
            }
            $ids = array_values(array_unique(array_map('intval', $ids)));
        }

        // Replace-all in a transaction (idempotent, atomic).
        $db->beginTransaction();
        try {
            $db->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$roleId]);
            if ($ids) {
                $ins = $db->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
                foreach ($ids as $pid) { $ins->execute([$roleId, $pid]); }
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        Logger::info('Admin set role permissions', ['admin_id' => $auth['user_id'], 'role_id' => $roleId, 'count' => count($ids)]);
        Response::success(['id' => $roleId, 'permission_count' => count($ids)], 200, 'Permissions updated');
    }

    // ── GET /api/admin/users/{id}/roles — a user's roles + effective perms ────
    if ($method === 'GET' && preg_match('#^/api/admin/users/(\d+)/roles$#', $uri, $m)) {
        Permission::enforce($db, $auth, 'roles.view');
        $userId = (int)$m[1];
        $us = $db->prepare("SELECT id, name, role FROM users WHERE id = ? LIMIT 1");
        $us->execute([$userId]);
        $user = $us->fetch(PDO::FETCH_ASSOC);
        if (!$user) Response::notFound('User');
        $rs = $db->prepare(
            "SELECT ur.role_id, r.slug, r.name, ur.scope_type, ur.scope_id, ur.created_at
               FROM user_roles ur JOIN roles r ON r.id = ur.role_id
              WHERE ur.user_id = ? ORDER BY r.name"
        );
        $rs->execute([$userId]);
        $held = $rs->fetchAll(PDO::FETCH_ASSOC);
        foreach ($held as &$h) { $h['role_id'] = (int)$h['role_id']; $h['scope_id'] = (int)$h['scope_id']; }
        Response::success([
            'user_id'     => $userId,
            'legacy_role' => $user['role'],
            'roles'       => $held,
            'permissions' => Permission::listForUser($db, $userId, $user['role']),
        ]);
    }

    // ── POST /api/admin/users/{id}/roles — assign a role to a user ────────────
    //     Body: { role_id | role_slug, scope_type?='global', scope_id?=0 }
    if ($method === 'POST' && preg_match('#^/api/admin/users/(\d+)/roles$#', $uri, $m)) {
        Permission::enforce($db, $auth, 'roles.manage');
        $userId = (int)$m[1];
        $input  = json_decode(file_get_contents('php://input'), true) ?? [];

        $us = $db->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
        $us->execute([$userId]);
        if (!$us->fetchColumn()) Response::notFound('User');

        // Resolve role by id or slug.
        $role = null;
        if (!empty($input['role_id'])) {
            $q = $db->prepare("SELECT id, slug FROM roles WHERE id = ? LIMIT 1");
            $q->execute([(int)$input['role_id']]);
            $role = $q->fetch(PDO::FETCH_ASSOC);
        } elseif (!empty($input['role_slug'])) {
            $q = $db->prepare("SELECT id, slug FROM roles WHERE slug = ? LIMIT 1");
            $q->execute([trim((string)$input['role_slug'])]);
            $role = $q->fetch(PDO::FETCH_ASSOC);
        }
        if (!$role) Response::validation('A valid role_id or role_slug is required');

        $scopeType = strtolower(trim((string)($input['scope_type'] ?? 'global')));
        if (!in_array($scopeType, ['global', 'category', 'city'], true)) {
            Response::validation("scope_type must be 'global', 'category' or 'city'");
        }
        $scopeId = ($scopeType === 'global') ? 0 : (int)($input['scope_id'] ?? 0);
        if ($scopeType !== 'global' && $scopeId <= 0) {
            Response::validation('scope_id is required when scope_type is category or city');
        }

        $db->prepare(
            "INSERT INTO user_roles (user_id, role_id, scope_type, scope_id, assigned_by_user_id)
             VALUES (:uid, :rid, :st, :sid, :by)
             ON DUPLICATE KEY UPDATE assigned_by_user_id = VALUES(assigned_by_user_id)"
        )->execute([
            ':uid' => $userId, ':rid' => (int)$role['id'], ':st' => $scopeType,
            ':sid' => $scopeId, ':by' => (int)$auth['user_id'],
        ]);
        Logger::info('Admin assigned role', ['admin_id' => $auth['user_id'], 'user_id' => $userId, 'role' => $role['slug'], 'scope_type' => $scopeType, 'scope_id' => $scopeId]);
        Response::success(['user_id' => $userId, 'role_id' => (int)$role['id'], 'scope_type' => $scopeType, 'scope_id' => $scopeId], 200, 'Role assigned');
    }

    // ── DELETE /api/admin/users/{id}/roles/{roleId} — unassign a role ─────────
    //     Optional ?scope_type=&scope_id= to remove one scoped grant; omit to remove all.
    if ($method === 'DELETE' && preg_match('#^/api/admin/users/(\d+)/roles/(\d+)$#', $uri, $m)) {
        Permission::enforce($db, $auth, 'roles.manage');
        $userId = (int)$m[1]; $roleId = (int)$m[2];
        $sql  = "DELETE FROM user_roles WHERE user_id = ? AND role_id = ?";
        $bind = [$userId, $roleId];
        if (isset($_GET['scope_type'])) {
            $sql .= " AND scope_type = ? AND scope_id = ?";
            $bind[] = strtolower(trim((string)$_GET['scope_type']));
            $bind[] = (int)($_GET['scope_id'] ?? 0);
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($bind);
        Logger::info('Admin unassigned role', ['admin_id' => $auth['user_id'], 'user_id' => $userId, 'role_id' => $roleId, 'removed' => $stmt->rowCount()]);
        Response::success(['user_id' => $userId, 'role_id' => $roleId, 'removed' => $stmt->rowCount()], 200, 'Role unassigned');
    }

    // ── GET /api/admin/orders ─────────────────────────────────
    if ($method === 'GET' && $uri === '/api/admin/orders') {
        $page   = max(1, (int) ($_GET['page']  ?? 1));
        $limit  = min(100, (int) ($_GET['limit'] ?? 20));
        $offset = ($page - 1) * $limit;

        $countStmt = $db->prepare("SELECT COUNT(*) FROM orders");
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();
        
        $stmt = $db->prepare("SELECT * FROM orders ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        Response::success([
            'orders' => $stmt->fetchAll(),
            'total'  => $total,
            'page'   => $page,
            'limit'  => $limit
        ]);
    }

    // ── GET /api/admin/settings ──────────────────────────────
    if ($method === 'GET' && $uri === '/api/admin/settings') {
        require_once CORE_PATH . '/controllers/SettingsController.php';
        $ctrl = new SettingsController($db);
        $ctrl->getAllSettings();
        exit;
    }

    // ── PUT /api/admin/settings/{key} ────────────────────────
    if ($method === 'PUT' && preg_match('#^/api/admin/settings/([^/]+)$#', $uri, $m)) {
        require_once CORE_PATH . '/controllers/SettingsController.php';
        $ctrl = new SettingsController($db);
        $ctrl->updateSetting($m[1]);
        exit;
    }

    // ── GET /api/admin/users ──────────────────────────────────
    if ($method === 'GET' && $uri === '/api/admin/users') {
        $role   = $_GET['role']   ?? null;
        $status = $_GET['status'] ?? null;
        $search = substr(trim($_GET['search'] ?? $_GET['q'] ?? ''), 0, 100);
        $page   = max(1, (int) ($_GET['page']  ?? 1));
        $limit  = min(100, (int) ($_GET['limit'] ?? 20));
        $offset = ($page - 1) * $limit;

        $where = ['deleted_at IS NULL'];
        $bind  = [];

        if ($search !== '') {
            $where[]         = '(name LIKE :search OR phone LIKE :search OR email LIKE :search)';
            $bind[':search'] = '%' . $search . '%';
        }
        if ($role) {
            $where[]    = 'role = :role';
            $bind[':role'] = $role;
        }
        if ($status) {
            $where[]      = 'status = :status';
            $bind[':status'] = $status;
        }

        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        $countStmt = $db->prepare("SELECT COUNT(*) FROM users {$whereSQL}");
        $countStmt->execute($bind);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT id, uuid, name, phone, email, role, status, created_at, last_login_at,
                    (SELECT COUNT(*) FROM bookings WHERE user_id = u.id) AS order_count
             FROM users u {$whereSQL}
             ORDER BY created_at DESC
             LIMIT :limit OFFSET :offset"
        );
        foreach ($bind as $k => $v2) {
            $stmt->bindValue($k, $v2);
        }
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        Response::success([
            'users'      => $stmt->fetchAll(),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int) ceil($total / max($limit, 1)),
            ],
        ]);
    }

    // ── GET /api/admin/users/{id} ─────────────────────────────
    if ($method === 'GET' && preg_match('#^/api/admin/users/(\d+)$#', $uri, $m)) {
        $stmt = $db->prepare(
            "SELECT id, uuid, name, phone, email, role, status, created_at, last_login_at
             FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute([(int) $m[1]]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::notFound('User');
        }

        Response::success(['user' => $user]);
    }

    // ── PATCH /api/admin/users/{id} ───────────────────────────
    if ($method === 'PATCH' && preg_match('#^/api/admin/users/(\d+)$#', $uri, $m)) {
        $userId = (int) $m[1];
        $input  = json_decode(file_get_contents('php://input'), true) ?? [];

        $v = Validator::make($input, [
            'status' => 'nullable|in:active,suspended,banned',
            'role'   => 'nullable|in:customer,vendor_service,vendor_shopping,admin',
        ]);

        if ($v->fails()) {
            Response::validation($v->firstError(), $v->errors());
        }

        $updates = [];
        $bind    = [];

        if (isset($input['status'])) {
            $updates[]  = 'status = :status';
            $bind[':status'] = $input['status'];
        }
        if (isset($input['role'])) {
            $updates[]  = 'role = :role';
            $bind[':role'] = $input['role'];
        }

        if (empty($updates)) {
            Response::validation('No valid fields to update');
        }

        $bind[':id'] = $userId;
        $db->prepare("UPDATE users SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = :id")
           ->execute($bind);

        Logger::info('Admin updated user', ['admin_id' => $auth['user_id'], 'target_user' => $userId, 'changes' => $input]);

        Response::success(null, 200, 'User updated');
    }

    // ── POST /api/admin/users/{id}/role ────────────────────────
    // Promotes a user to vendor_service/vendor_shopping and creates a
    // pending vendors row if one doesn't already exist — unlike the
    // generic PATCH above, which only flips users.role and leaves the
    // account with no vendor profile to actually use the vendor panel.
    if ($method === 'POST' && preg_match('#^/api/admin/users/(\d+)/role$#', $uri, $m)) {
        $userId = (int) $m[1];
        $input  = json_decode(file_get_contents('php://input'), true) ?? [];

        $v = Validator::make($input, [
            'role' => 'required|in:customer,vendor_service,vendor_shopping,admin',
        ]);
        if ($v->fails()) {
            Response::validation($v->firstError(), $v->errors());
        }
        $role = $v->validated()['role'];

        $userRow = $db->prepare("SELECT id, name FROM users WHERE id = ? LIMIT 1");
        $userRow->execute([$userId]);
        $user = $userRow->fetch(PDO::FETCH_ASSOC);
        if (!$user) Response::notFound('User');

        $db->prepare("UPDATE users SET role = ?, updated_at = NOW() WHERE id = ?")
           ->execute([$role, $userId]);

        if (str_starts_with($role, 'vendor_')) {
            $existingVendor = $db->prepare("SELECT id FROM vendors WHERE user_id = ? LIMIT 1");
            $existingVendor->execute([$userId]);
            if (!$existingVendor->fetchColumn()) {
                $type = ($role === ROLE_VENDOR_SERVICE) ? 'service' : 'shopping';
                $businessName = $user['name'] ?: ('Vendor #' . $userId);
                $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $businessName), '-')) . '-' . $userId;
                $typeColumn = ServiceVendorEligibility::vendorTypeColumn($db);

                // V2 city dimension: vendor inherits the owning user's city, default Haldwani.
                $vendorCityId = CityResolver::resolveFromUser($db, (int)$userId);
                $vCityCol = ''; $vCityVal = ''; $vCityArg = [];
                if ($vendorCityId > 0 && CityResolver::hasCityColumn($db, 'vendors')) {
                    $vCityCol = ', city_id'; $vCityVal = ', ?'; $vCityArg = [$vendorCityId];
                }
                $db->prepare("INSERT INTO vendors (user_id, business_name, slug, {$typeColumn}, status{$vCityCol}) VALUES (?, ?, ?, ?, 'pending'{$vCityVal})")
                   ->execute(array_merge([$userId, $businessName, $slug, $type], $vCityArg));
            }
        }

        Logger::info('Admin changed user role', ['admin_id' => $auth['user_id'], 'target_user' => $userId, 'role' => $role]);

        Response::success(['user_id' => $userId, 'role' => $role], 200, 'Role updated');
    }

    // ── POST /api/admin/vendors/{id}/approve ───────────────────
    if ($method === 'POST' && preg_match('#^/api/admin/vendors/(\d+)/approve$#', $uri, $m)) {
        $vendorId = (int) $m[1];
        $db->prepare("UPDATE vendors SET status = 'active', updated_at = NOW() WHERE id = ?")
           ->execute([$vendorId]);
        
        // Also update the user's role to match vendor type
        $typeCol = ServiceVendorEligibility::vendorTypeColumn($db);
        $vendorRow = $db->prepare("SELECT {$typeCol} AS type, user_id FROM vendors WHERE id = ? LIMIT 1");
        $vendorRow->execute([$vendorId]);
        $vData = $vendorRow->fetch(PDO::FETCH_ASSOC);
        if ($vData) {
            $newRole = ($vData['type'] === 'service') ? 'vendor_service' : 'vendor_shopping';
            $db->prepare("UPDATE users SET role = ?, updated_at = NOW() WHERE id = ?")
               ->execute([$newRole, $vData['user_id']]);

            FcmNotifier::notifyUser(
                $db,
                (int)$vData['user_id'],
                'Vendor Account Approved',
                'Your vendor account has been approved. Logout and login again to access your dashboard.',
                ['url' => '/vendor/index.php', 'type' => 'vendor_approved']
            );
        }

        Logger::info('Admin approved vendor', ['admin_id' => $auth['user_id'], 'vendor_id' => $vendorId]);
        Response::success([
            'require_relogin' => true,
            'message' => 'Please logout and login again to access vendor panel'
        ], 200, 'Vendor approved');
    }

    // ── POST /api/admin/vendors/{id}/reject ────────────────────
    if ($method === 'POST' && preg_match('#^/api/admin/vendors/(\d+)/reject$#', $uri, $m)) {
        $vendorId = (int) $m[1];
        $input    = json_decode(file_get_contents('php://input'), true) ?? [];
        $db->prepare("UPDATE vendors SET status = 'rejected', updated_at = NOW() WHERE id = ?")
           ->execute([$vendorId]);
        Logger::info('Admin rejected vendor', ['admin_id' => $auth['user_id'], 'vendor_id' => $vendorId, 'reason' => $input['reason'] ?? '']);
        Response::success(null, 200, 'Vendor rejected');
    }

    // ── PATCH /api/admin/vendors/{id}/status ───────────────────
    if ($method === 'PATCH' && preg_match('#^/api/admin/vendors/(\d+)/status$#', $uri, $m)) {
        $vendorId = (int) $m[1];
        $input    = json_decode(file_get_contents('php://input'), true) ?? [];
        $status   = $input['status'] ?? '';
        // Map frontend 'enabled'/'disabled' to DB values 'active'/'inactive'
        $dbStatus = match ($status) {
            'enabled'  => 'active',
            'disabled' => 'inactive',
            'active'   => 'active',
            'inactive' => 'inactive',
            default    => null,
        };
        if (!$dbStatus) {
            Response::validation('Invalid status value');
        }
        $db->prepare("UPDATE vendors SET status = ?, updated_at = NOW() WHERE id = ?")
           ->execute([$dbStatus, $vendorId]);
        Logger::info('Admin updated vendor status', ['admin_id' => $auth['user_id'], 'vendor_id' => $vendorId, 'status' => $dbStatus]);
        Response::success(null, 200, 'Vendor status updated');
    }

    // ── PATCH /api/admin/vendors/{id}/category ─────────────────
    if ($method === 'PATCH' && preg_match('#^/api/admin/vendors/(\d+)/category$#', $uri, $m)) {
        $vendorId   = (int) $m[1];
        $input      = json_decode(file_get_contents('php://input'), true) ?? [];
        $categoryId = isset($input['category_id']) ? (int) $input['category_id'] : null;
        // Keep vendors.category_id updated as a backward-compat "primary category"
        // hint (now dormant — no longer the source of truth), then write the
        // canonical vendor_categories junction so the resolver / customer app scope
        // this vendor to exactly this category. Replace semantics (DELETE + INSERT)
        // mirror the vendor-profile path; see OPEN QUESTIONS re: a multi-select UI.
        $db->prepare("UPDATE vendors SET category_id = ?, updated_at = NOW() WHERE id = ?")
           ->execute([$categoryId, $vendorId]);
        if (ServiceVendorEligibility::tableHasColumn($db, 'vendor_categories', 'vendor_id')) {
            $db->prepare("DELETE FROM vendor_categories WHERE vendor_id = ?")->execute([$vendorId]);
            if ($categoryId !== null && $categoryId > 0) {
                $db->prepare("INSERT IGNORE INTO vendor_categories (vendor_id, category_id) VALUES (?, ?)")
                   ->execute([$vendorId, $categoryId]);
            }
        }
        Logger::info('Admin updated vendor category', ['admin_id' => $auth['user_id'], 'vendor_id' => $vendorId, 'category_id' => $categoryId]);
        Response::success(null, 200, 'Vendor category updated');
    }

    // ── PATCH /api/admin/vendors/{id}/verify ────────────────────
    if ($method === 'PATCH' && preg_match('#^/api/admin/vendors/(\d+)/verify$#', $uri, $m)) {
        $vendorId   = (int) $m[1];
        $input      = json_decode(file_get_contents('php://input'), true) ?? [];
        $isVerified = !empty($input['is_verified']) ? 1 : 0;
        $db->prepare("UPDATE vendors SET is_verified = ?, updated_at = NOW() WHERE id = ?")
           ->execute([$isVerified, $vendorId]);
        Logger::info('Admin updated vendor verified flag', ['admin_id' => $auth['user_id'], 'vendor_id' => $vendorId, 'is_verified' => $isVerified]);
        Response::success(null, 200, 'Vendor verification status updated');
    }

    // ── GET /api/admin/vendors/{id} ────────────────────────────
    if ($method === 'GET' && preg_match('#^/api/admin/vendors/(\d+)$#', $uri, $m)) {
        $vendorId = (int) $m[1];
        $stmt = $db->prepare(
            "SELECT v.*, u.name AS owner_name, u.email AS owner_email, u.phone AS owner_phone
             FROM vendors v
             LEFT JOIN users u ON u.id = v.user_id
             WHERE v.id = ? LIMIT 1"
        );
        $stmt->execute([$vendorId]);
        $vendor = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$vendor) {
            Response::notFound('Vendor');
        }

        $perf = ['total_assigned' => 0, 'completed' => 0, 'active' => 0, 'completion_rate' => 0];
        try {
            $pStmt = $db->prepare(
                "SELECT
                    COUNT(*) AS total_assigned,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN status IN ('pending','assigned','in_progress') THEN 1 ELSE 0 END) AS active
                 FROM bookings WHERE vendor_id = ?"
            );
            $pStmt->execute([$vendorId]);
            $row = $pStmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $total = (int) $row['total_assigned'];
                $done  = (int) $row['completed'];
                $perf  = [
                    'total_assigned'  => $total,
                    'completed'       => $done,
                    'active'          => (int) $row['active'],
                    'completion_rate' => $total > 0 ? round($done / $total * 100) : 0,
                ];
            }
        } catch (PDOException $ignored) {}

        Response::success(['vendor' => $vendor, 'perf' => $perf]);
    }

    // ══ Vendor accountability — bypass / non-completion auto-flag (V2-VISION §5) ══
    //  READ-ONLY detection computed from existing jobs + auto_assignments; the
    //  system flags, the admin acts. Thresholds are app_settings-driven (zero
    //  hardcoding). Gated by RBAC (today's admin bypasses).
    require_once SYSTEM_ROOT . '/core/helpers/VendorAccountability.php';

    // ── GET /api/admin/vendors/flagged — the "flagged vendors" list (worst-first)
    if ($method === 'GET' && $uri === '/api/admin/vendors/flagged') {
        Permission::enforce($db, $auth, 'vendors.view');
        $limit = min(200, max(1, (int)($_GET['limit'] ?? 100)));
        Response::success(VendorAccountability::flaggedList($db, $limit));
    }

    // ── GET /api/admin/vendors/{id}/accountability — one vendor's flag detail ──
    if ($method === 'GET' && preg_match('#^/api/admin/vendors/(\d+)/accountability$#', $uri, $m)) {
        Permission::enforce($db, $auth, 'vendors.view');
        Response::success(VendorAccountability::forVendor($db, (int)$m[1]));
    }

    // ── PATCH /api/admin/vendors/{id}/accountability — admin ACTS on a flag ────
    //  Sets the admin-decision layer (manual flag, review ack, work throttle,
    //  note). work_throttle is a FUTURE hook — recorded, NOT yet enforced by
    //  assignment. Schema-defensive: only writes columns that exist.
    if ($method === 'PATCH' && preg_match('#^/api/admin/vendors/(\d+)/accountability$#', $uri, $m)) {
        Permission::enforce($db, $auth, 'vendors.manage');
        $vendorId = (int)$m[1];
        $input    = json_decode(file_get_contents('php://input'), true) ?? [];

        $vchk = $db->prepare("SELECT id FROM vendors WHERE id = ? LIMIT 1");
        $vchk->execute([$vendorId]);
        if (!$vchk->fetchColumn()) Response::notFound('Vendor');

        $set = []; $bind = [];
        if (array_key_exists('manual_flag', $input) && VendorAccountability::hasColumn($db, 'vendors', 'manual_flag')) {
            $set[] = 'manual_flag = ?'; $bind[] = $input['manual_flag'] ? 1 : 0;
        }
        if (array_key_exists('flag_notes', $input) && VendorAccountability::hasColumn($db, 'vendors', 'flag_notes')) {
            $set[] = 'flag_notes = ?'; $bind[] = (trim((string)$input['flag_notes']) !== '') ? mb_substr(trim((string)$input['flag_notes']), 0, 255) : null;
        }
        if (array_key_exists('work_throttle', $input) && VendorAccountability::hasColumn($db, 'vendors', 'work_throttle')) {
            $wt = strtolower(trim((string)$input['work_throttle']));
            if (!in_array($wt, ['normal', 'reduced', 'paused', ''], true)) Response::validation("work_throttle must be normal|reduced|paused");
            $set[] = 'work_throttle = ?'; $bind[] = ($wt === '' ? null : $wt);
        }
        if (array_key_exists('reviewed', $input) && VendorAccountability::hasColumn($db, 'vendors', 'flag_reviewed_at')) {
            $set[] = 'flag_reviewed_at = ' . ($input['reviewed'] ? 'NOW()' : 'NULL');
        }
        if (!$set) Response::validation('No accountability fields to update (or columns not migrated).');

        $db->prepare("UPDATE vendors SET " . implode(', ', $set) . " WHERE id = ?")
           ->execute(array_merge($bind, [$vendorId]));
        Logger::info('Admin updated vendor accountability', ['admin_id' => $auth['user_id'], 'vendor_id' => $vendorId, 'fields' => array_keys($input)]);
        Response::success(VendorAccountability::forVendor($db, $vendorId), 200, 'Accountability updated');
    }

    // ── GET /api/admin/accountability/config — current tunable thresholds ──────
    if ($method === 'GET' && $uri === '/api/admin/accountability/config') {
        Permission::enforce($db, $auth, 'vendors.view');
        Response::success(['thresholds' => VendorAccountability::thresholds($db), 'defaults' => VendorAccountability::DEFAULTS]);
    }

    // ── PUT /api/admin/accountability/config — tune thresholds (app_settings) ──
    if ($method === 'PUT' && $uri === '/api/admin/accountability/config') {
        Permission::enforce($db, $auth, 'settings.manage');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        // sane clamps so a typo can't disable the whole rule
        $ranges = [
            'window_days' => [1, 365], 'stall_hours' => [1, 2160], 'min_assigned' => [1, 1000],
            'noncompletion_count' => [1, 1000], 'min_completion_rate' => [0, 100],
            'min_offered' => [1, 1000], 'max_reject_rate' => [0, 100],
        ];
        $up = $db->prepare(
            "INSERT INTO app_settings (setting_key, setting_value, value_type, is_public, updated_at)
             VALUES (:k, :v, 'number', 0, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
        );
        $applied = [];
        foreach ($ranges as $short => $range) {
            if (!array_key_exists($short, $input) || !is_numeric($input[$short])) continue;
            $val = max($range[0], min($range[1], (int)$input[$short]));
            $up->execute([':k' => 'vendor_flag_' . $short, ':v' => (string)$val]);
            $applied[$short] = $val;
        }
        if (!$applied) Response::validation('No valid threshold fields provided.');
        Logger::info('Admin tuned accountability thresholds', ['admin_id' => $auth['user_id'], 'applied' => $applied]);
        Response::success(['thresholds' => VendorAccountability::thresholds($db), 'applied' => $applied], 200, 'Thresholds updated');
    }

    // ══ Cities — multi-city management (add / enable a city = data, not a deploy) ══
    //  The cities table + city_id foundation exist (migration 001); this exposes
    //  the CRUD so launching a new city is one click. Gated by RBAC settings.*.
    //  Schema-defensive: if the cities table isn't migrated yet, returns 503.
    //  No DELETE on purpose — cities are FK-referenced; disable via is_active=0.

    // ── GET /api/admin/cities — all cities (incl. inactive, for management) ────
    if ($method === 'GET' && $uri === '/api/admin/cities') {
        Permission::enforce($db, $auth, 'settings.view');
        try {
            $rows = $db->query(
                "SELECT c.id, c.name, c.slug, c.state, c.country, c.center_lat, c.center_lng, c.is_active, c.sort_order,
                        (SELECT COUNT(*) FROM vendors v  WHERE v.city_id = c.id) AS vendor_count,
                        (SELECT COUNT(*) FROM bookings b WHERE b.city_id = c.id) AS booking_count
                   FROM cities c ORDER BY c.sort_order ASC, c.name ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id']; $r['is_active'] = (int)$r['is_active']; $r['sort_order'] = (int)$r['sort_order'];
                $r['vendor_count'] = (int)$r['vendor_count']; $r['booking_count'] = (int)$r['booking_count'];
            }
            Response::success(['cities' => $rows]);
        } catch (PDOException $e) {
            Response::error('Cities table is not available yet — run the city migration on this environment.', 503);
        }
    }

    // ── POST /api/admin/cities — add a city ───────────────────────────────────
    if ($method === 'POST' && $uri === '/api/admin/cities') {
        Permission::enforce($db, $auth, 'settings.manage');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $v = Validator::make($input, ['name' => 'required|string|max:100', 'slug' => 'string|max:120', 'state' => 'string|max:100']);
        if ($v->fails()) Response::validation($v->firstError(), $v->errors());

        $name = trim((string)$input['name']);
        $slug = trim((string)($input['slug'] ?? ''));
        if ($slug === '') $slug = $name;
        $slug = trim(preg_replace('/-+/', '-', preg_replace('/[^a-z0-9]+/', '-', strtolower($slug))), '-');
        if ($slug === '') Response::validation('Could not derive a valid slug from the name');

        try {
            $exists = $db->prepare("SELECT id FROM cities WHERE slug = ? LIMIT 1");
            $exists->execute([$slug]);
            if ($exists->fetchColumn()) Response::error('A city with that slug already exists.', 409);

            $lat = (isset($input['center_lat']) && $input['center_lat'] !== '') ? (float)$input['center_lat'] : null;
            $lng = (isset($input['center_lng']) && $input['center_lng'] !== '') ? (float)$input['center_lng'] : null;
            $db->prepare(
                "INSERT INTO cities (name, slug, state, country, center_lat, center_lng, is_active, sort_order)
                 VALUES (:name, :slug, :state, :country, :lat, :lng, :active, :sort)"
            )->execute([
                ':name' => $name, ':slug' => $slug,
                ':state' => trim((string)($input['state'] ?? '')) ?: null,
                ':country' => trim((string)($input['country'] ?? 'India')) ?: 'India',
                ':lat' => $lat, ':lng' => $lng,
                ':active' => array_key_exists('is_active', $input) ? ($input['is_active'] ? 1 : 0) : 1,
                ':sort' => (int)($input['sort_order'] ?? 0),
            ]);
            $newId = (int)$db->lastInsertId();
            Logger::info('Admin added city', ['admin_id' => $auth['user_id'], 'city_id' => $newId, 'slug' => $slug]);
            Response::success(['id' => $newId, 'name' => $name, 'slug' => $slug], 201, 'City added');
        } catch (PDOException $e) {
            Response::error('Cities table is not available yet — run the city migration on this environment.', 503);
        }
    }

    // ── PATCH /api/admin/cities/{id} — edit / enable / disable ────────────────
    if ($method === 'PATCH' && preg_match('#^/api/admin/cities/(\d+)$#', $uri, $m)) {
        Permission::enforce($db, $auth, 'settings.manage');
        $cityId = (int)$m[1];
        $input  = json_decode(file_get_contents('php://input'), true) ?? [];
        try {
            $chk = $db->prepare("SELECT id FROM cities WHERE id = ? LIMIT 1");
            $chk->execute([$cityId]);
            if (!$chk->fetchColumn()) Response::notFound('City');

            $set = []; $bind = [];
            if (array_key_exists('name', $input) && trim((string)$input['name']) !== '') { $set[] = 'name = ?'; $bind[] = trim((string)$input['name']); }
            if (array_key_exists('state', $input)) { $set[] = 'state = ?'; $bind[] = trim((string)$input['state']) ?: null; }
            if (array_key_exists('center_lat', $input)) { $set[] = 'center_lat = ?'; $bind[] = ($input['center_lat'] === '' || $input['center_lat'] === null) ? null : (float)$input['center_lat']; }
            if (array_key_exists('center_lng', $input)) { $set[] = 'center_lng = ?'; $bind[] = ($input['center_lng'] === '' || $input['center_lng'] === null) ? null : (float)$input['center_lng']; }
            if (array_key_exists('is_active', $input)) { $set[] = 'is_active = ?'; $bind[] = $input['is_active'] ? 1 : 0; }
            if (array_key_exists('sort_order', $input)) { $set[] = 'sort_order = ?'; $bind[] = (int)$input['sort_order']; }
            if (!$set) Response::validation('Nothing to update');
            $set[] = 'updated_at = NOW()';
            $bind[] = $cityId;
            $db->prepare("UPDATE cities SET " . implode(', ', $set) . " WHERE id = ?")->execute($bind);
            Logger::info('Admin updated city', ['admin_id' => $auth['user_id'], 'city_id' => $cityId]);
            Response::success(['id' => $cityId], 200, 'City updated');
        } catch (PDOException $e) {
            Response::error('Cities table is not available yet — run the city migration on this environment.', 503);
        }
    }

    // ── POST /api/admin/deliveries/assign ──────────────────────
    if ($method === 'POST' && $uri === '/api/admin/deliveries/assign') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $v = Validator::make($input, [
            'order_id'  => 'required|integer',
            'driver_id' => 'required|integer',
        ]);

        if ($v->fails()) Response::validation($v->firstError());

        require_once CORE_PATH . '/helpers/LedgerEngine.php';
        if (\Core\Helpers\LedgerEngine::isDriverBlocked($input['driver_id'])) {
            Response::json(['status' => 'error', 'message' => 'Driver is blocked due to exceeding cash collection limit.'], 403);
        }

        $db->prepare("INSERT INTO deliveries (order_id, driver_id, status, assigned_at) VALUES (?, ?, 'assigned', NOW())")
           ->execute([$input['order_id'], $input['driver_id']]);
        
        $deliveryId = $db->lastInsertId();
        $db->prepare("UPDATE orders SET delivery_id = ?, status = 'processing' WHERE id = ?")
           ->execute([$deliveryId, $input['order_id']]);

        Response::success(['delivery_id' => $deliveryId], 201, 'Delivery assigned');
    }

    // ── GET /api/admin/logs/activity ──────────────────────────
    if ($method === 'GET' && $uri === '/api/admin/logs/activity') {
        $limit = min(50, (int) ($_GET['limit'] ?? 10));
        
        // Return dummy activity for now if table doesn't exist, or fetch from system logs
        // Assuming we have a 'logs' table or similar. If not, we can return recent events.
        $stmt = $db->prepare("SELECT * FROM logs ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$limit]);
        Response::success(['logs' => $stmt->fetchAll()]);
    }

    // ── DELETE /api/admin/logs ────────────────────────────────
    if ($method === 'DELETE' && $uri === '/api/admin/logs') {
        $days   = max(1, (int) ($_GET['older_than_days'] ?? 30));
        $logDir = dirname(dirname(dirname(__DIR__))) . '/logs';
        $cutoff = strtotime("-{$days} days");

        $deleted = 0;

        foreach (glob($logDir . '/*.log') as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                $deleted++;
            }
        }

        Logger::info('Admin purged logs', ['admin_id' => $auth['user_id'], 'files_deleted' => $deleted]);
        Response::success(['files_deleted' => $deleted], 200, "Deleted {$deleted} log file(s)");
    }

    // ── POST /api/admin/users/{id}/block ──────────────────────────
    if ($method === 'POST' && preg_match('#^/api/admin/users/(\d+)/block$#', $uri, $m)) {
        $userId = (int) $m[1];
        $db->prepare("UPDATE users SET status = 'blocked', updated_at = NOW() WHERE id = ?")
           ->execute([$userId]);
        Logger::info('Admin blocked user', ['admin_id' => $auth['user_id'], 'target_user' => $userId]);
        Response::success(null, 200, 'User blocked');
    }

    // ── POST /api/admin/users/{id}/unblock ────────────────────────
    if ($method === 'POST' && preg_match('#^/api/admin/users/(\d+)/unblock$#', $uri, $m)) {
        $userId = (int) $m[1];
        $db->prepare("UPDATE users SET status = 'active', updated_at = NOW() WHERE id = ?")
           ->execute([$userId]);
        Logger::info('Admin unblocked user', ['admin_id' => $auth['user_id'], 'target_user' => $userId]);
        Response::success(null, 200, 'User unblocked');
    }

    // ── DELETE /api/admin/products/{id} ───────────────────────────
    if ($method === 'DELETE' && preg_match('#^/api/admin/products/(\d+)$#', $uri, $m)) {
        $productId = (int) $m[1];
        $db->prepare("UPDATE products SET status = 'archived', deleted_at = NOW() WHERE id = ?")
           ->execute([$productId]);
        Logger::info('Admin deleted product', ['admin_id' => $auth['user_id'], 'product_id' => $productId]);
        Response::success(null, 200, 'Product deleted');
    }

    // ── PUT /api/admin/orders/{id}/status ─────────────────────────
    if (in_array($method, ['PUT', 'PATCH']) && preg_match('#^/api/admin/orders/(\d+)/status$#', $uri, $m)) {
        $orderId = (int) $m[1];
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $status = $input['status'] ?? null;
        if (!$status) {
            Response::validation('Status is required');
        }
        $db->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?")
           ->execute([$status, $orderId]);
        Logger::info('Admin updated order status', ['admin_id' => $auth['user_id'], 'order_id' => $orderId, 'new_status' => $status]);
        Response::success(null, 200, 'Order status updated');
    }

    // ── PATCH /service/bookings/{id}/assign ───────────────────────
    if ($method === 'PATCH' && preg_match('#^/service/bookings/(\d+)/assign$#', $uri, $m)) {
        $bookingId = (int) $m[1];
        $input     = json_decode(file_get_contents('php://input'), true) ?? [];
        $vendorId  = (int) ($input['vendor_id'] ?? 0);
        if (!$vendorId) {
            Response::error('vendor_id required', 400);
        }
        $db->prepare("UPDATE bookings SET vendor_id = ? WHERE id = ?")
           ->execute([$vendorId, $bookingId]);
        $syncBookingJobVendor($bookingId, $vendorId);
        Logger::info('Admin assigned vendor to booking', ['admin_id' => $auth['user_id'], 'booking_id' => $bookingId, 'vendor_id' => $vendorId]);

        $vendorOwnerStmt = $db->prepare("SELECT user_id FROM vendors WHERE id = ? LIMIT 1");
        $vendorOwnerStmt->execute([$vendorId]);
        $vendorUserId = $vendorOwnerStmt->fetchColumn();
        if ($vendorUserId) {
            FcmNotifier::notifyUser(
                $db,
                (int)$vendorUserId,
                'New Booking Assigned',
                "You have been assigned booking #{$bookingId}.",
                ['url' => '/vendor/bookings.php', 'booking_id' => (string)$bookingId, 'type' => 'booking_assigned']
            );
        }

        Response::success(null, 200, 'Vendor assigned');
    }

    // ── POST /api/admin/orders/{id}/cancel ────────────────────────
    if ($method === 'POST' && preg_match('#^/api/admin/orders/(\d+)/cancel$#', $uri, $m)) {
        $orderId = (int) $m[1];
        $db->prepare("UPDATE orders SET status = 'cancelled', updated_at = NOW() WHERE id = ?")
           ->execute([$orderId]);
        Logger::info('Admin cancelled order', ['admin_id' => $auth['user_id'], 'order_id' => $orderId]);
        Response::success(null, 200, 'Order cancelled');
    }

    // ══════════════════════════════════════════════════════════
    // GET /api/admin/orders/{id} — Single order full detail
    // ══════════════════════════════════════════════════════════
    if ($method === 'GET' && preg_match('#^/api/admin/orders/(\d+)$#', $uri, $m)) {
        $orderId = (int) $m[1];

        $stmt = $db->prepare("
            SELECT o.*,
                   u.name  AS customer_name,
                   u.phone AS customer_phone,
                   u.email AS customer_email
            FROM orders o
            LEFT JOIN users u ON u.id = o.customer_id
            WHERE o.id = ?
        ");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) Response::notFound('Order');

        // Delivery info
        $dStmt = $db->prepare("
            SELECT d.*, u.name AS rider_name, u.phone AS rider_phone
            FROM deliveries d
            LEFT JOIN users u ON u.id = d.rider_id
            WHERE d.order_id = ?
        ");
        $dStmt->execute([$orderId]);
        $order['delivery'] = $dStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        // Payment info
        $pStmt = $db->prepare("SELECT * FROM payments WHERE order_id = ? LIMIT 1");
        $pStmt->execute([$orderId]);
        $order['payment'] = $pStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        Response::success($order);
    }

    // ══════════════════════════════════════════════════════════
    // GET /api/admin/deliveries — All deliveries with status
    // ══════════════════════════════════════════════════════════
    if ($method === 'GET' && $uri === '/api/admin/deliveries') {
        $allowed_delivery_statuses = ['pending','assigned','picked_up','in_transit','delivered','failed','cancelled'];
        $raw_status = $_GET['status'] ?? null;
        $status = ($raw_status !== null && in_array($raw_status, $allowed_delivery_statuses, true))
            ? $raw_status : null;
        $page   = max(1, (int) ($_GET['page'] ?? 1));
        $limit  = 20;
        $offset = ($page - 1) * $limit;

        $where  = $status ? "WHERE d.status = " . $db->quote($status) : "";

        $rows = $db->query("
            SELECT d.*,
                   o.order_number, o.total, o.payment_method,
                   c.name  AS customer_name,
                   c.phone AS customer_phone,
                   r.name  AS rider_name,
                   r.phone AS rider_phone
            FROM deliveries d
            LEFT JOIN orders  o ON o.id = d.order_id
            LEFT JOIN users   c ON c.id = o.customer_id
            LEFT JOIN users   r ON r.id = d.rider_id
            {$where}
            ORDER BY d.created_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ")->fetchAll(PDO::FETCH_ASSOC);

        $total = $db->query("SELECT COUNT(*) FROM deliveries d {$where}")->fetchColumn();

        Response::success([
            'deliveries' => $rows,
            'pagination' => ['page' => $page, 'limit' => $limit, 'total' => (int) $total],
        ]);
    }

    // ══════════════════════════════════════════════════════════
    // GET /api/admin/products — Product listing with stock
    // ══════════════════════════════════════════════════════════
    if ($method === 'GET' && $uri === '/api/admin/products') {
        $page   = max(1, (int) ($_GET['page'] ?? 1));
        $limit  = 20;
        $offset = ($page - 1) * $limit;
        $search = substr(trim($_GET['search'] ?? ''), 0, 100);

        $where = $search
            ? "WHERE p.name LIKE " . $db->quote('%' . $search . '%')
            : "";

        $rows = $db->query("
            SELECT p.*,
                   m.shop_name AS merchant_name
            FROM products p
            LEFT JOIN merchants m ON m.id = p.merchant_id
            {$where}
            ORDER BY p.created_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ")->fetchAll(PDO::FETCH_ASSOC);

        $total = $db->query("SELECT COUNT(*) FROM products p {$where}")->fetchColumn();

        Response::success([
            'products'   => $rows,
            'pagination' => ['page' => $page, 'limit' => $limit, 'total' => (int) $total],
        ]);
    }

    // ══════════════════════════════════════════════════════════
    // GET /api/admin/payments — Payment records
    // ══════════════════════════════════════════════════════════
    if ($method === 'GET' && $uri === '/api/admin/payments') {
        $page   = max(1, (int) ($_GET['page'] ?? 1));
        $limit  = 20;
        $offset = ($page - 1) * $limit;
        $allowed_payment_statuses = ['pending','paid','failed','refunded'];
        $raw_status = $_GET['status'] ?? null;
        $status = ($raw_status !== null && in_array($raw_status, $allowed_payment_statuses, true))
            ? $raw_status : null;

        $where = $status
            ? "WHERE p.status = " . $db->quote($status)
            : "";

        $rows = $db->query("
            SELECT p.*,
                   o.order_number,
                   u.name  AS customer_name,
                   u.phone AS customer_phone
            FROM payments p
            LEFT JOIN orders o ON o.id = p.order_id
            LEFT JOIN users  u ON u.id = o.customer_id
            {$where}
            ORDER BY p.created_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ")->fetchAll(PDO::FETCH_ASSOC);

        $total = $db->query("SELECT COUNT(*) FROM payments p {$where}")->fetchColumn();

        $sum = $db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'paid'")->fetchColumn();

        Response::success([
            'payments'       => $rows,
            'total_collected'=> (float) ($sum ?? 0),
            'pagination'     => ['page' => $page, 'limit' => $limit, 'total' => (int) $total],
        ]);
    }

    // ══ Payment Room — READ-ONLY revenue control room (computed, moves no money) ══
    //  Everything here only SELECTs via core/helpers/PaymentReport.php (no
    //  LedgerEngine, no webhook, no writes). Gated by RBAC 'payments.view' —
    //  today's admin bypasses (legacy admin all-access), Finance role sees it later.

    // ── GET /api/admin/payments/room?period=&city_id= ─────────────────────────
    if ($method === 'GET' && $uri === '/api/admin/payments/room') {
        Permission::enforce($db, $auth, 'payments.view');
        require_once SYSTEM_ROOT . '/core/helpers/PaymentReport.php';
        $period = (string)($_GET['period'] ?? '30d');
        $cityId = isset($_GET['city_id']) && $_GET['city_id'] !== '' ? (int)$_GET['city_id'] : null;
        Response::success(PaymentReport::room($db, $period, $cityId));
    }

    // ── GET /api/admin/payments/vendor/{id}?period= — vendor revenue history ───
    if ($method === 'GET' && preg_match('#^/api/admin/payments/vendor/(\d+)$#', $uri, $m)) {
        Permission::enforce($db, $auth, 'payments.view');
        require_once SYSTEM_ROOT . '/core/helpers/PaymentReport.php';
        $period = (string)($_GET['period'] ?? 'all');
        Response::success(PaymentReport::vendorRevenue($db, (int)$m[1], $period));
    }

    // ── POST /api/admin/payments/settle  { vendor_id? } ───────────────────────
    //  REAL-MONEY WRITE — advances vendor earnings pending_balance -> available_
    //  balance ("ready to pay out"). It does NOT transfer money to any bank; that
    //  payout-gateway step is separate. Admin-triggered only, gated by RBAC
    //  'payments.manage' (today's admin bypasses). Safe/idempotent/transactional
    //  via LedgerEngine::runSettlement (SELECT ... FOR UPDATE, pending-only). Omit
    //  vendor_id to settle all vendors; pass one to settle a single vendor.
    if ($method === 'POST' && $uri === '/api/admin/payments/settle') {
        Permission::enforce($db, $auth, 'payments.manage');
        require_once SYSTEM_ROOT . '/core/helpers/PaymentReport.php';
        require_once CORE_PATH . '/helpers/LedgerEngine.php';

        if (!PaymentReport::hasTable($db, 'wallet_transactions') || !PaymentReport::hasTable($db, 'vendor_wallets')) {
            Response::error('Wallet tables are not available on this environment.', 503);
        }

        $input    = json_decode(file_get_contents('php://input'), true) ?? [];
        $vendorId = (isset($input['vendor_id']) && $input['vendor_id'] !== '' && $input['vendor_id'] !== null)
            ? (int)$input['vendor_id'] : null;
        if ($vendorId !== null && $vendorId <= 0) Response::validation('vendor_id must be a positive integer');
        if ($vendorId !== null) {
            $vchk = $db->prepare("SELECT id FROM vendors WHERE id = ? LIMIT 1");
            $vchk->execute([$vendorId]);
            if (!$vchk->fetchColumn()) Response::notFound('Vendor');
        }

        // Before/after wallet snapshot (scoped to the vendor when given) so the
        // admin sees exactly how much moved.
        $sumsSql = "SELECT COALESCE(SUM(pending_balance),0) AS pending, COALESCE(SUM(available_balance),0) AS available
                    FROM vendor_wallets" . ($vendorId !== null ? " WHERE vendor_id = ?" : "");
        $snapArgs = $vendorId !== null ? [$vendorId] : [];
        $bStmt = $db->prepare($sumsSql); $bStmt->execute($snapArgs);
        $before = $bStmt->fetch(PDO::FETCH_ASSOC) ?: ['pending' => 0, 'available' => 0];

        $result = \Core\Helpers\LedgerEngine::runSettlement($vendorId, $db);
        if (($result['status'] ?? '') !== 'success') {
            Response::error('Settlement failed: ' . ($result['message'] ?? 'unknown error'), 500);
        }

        $aStmt = $db->prepare($sumsSql); $aStmt->execute($snapArgs);
        $after = $aStmt->fetch(PDO::FETCH_ASSOC) ?: ['pending' => 0, 'available' => 0];

        // Attach vendor names to the per-vendor breakdown.
        $vendors = $result['vendors'] ?? [];
        if ($vendors) {
            $ids = array_map(fn($v) => (int)$v['vendor_id'], $vendors);
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $names = [];
            try {
                $ns = $db->prepare("SELECT id, business_name FROM vendors WHERE id IN ($ph)");
                $ns->execute($ids);
                foreach ($ns->fetchAll(PDO::FETCH_ASSOC) as $r) { $names[(int)$r['id']] = $r['business_name']; }
            } catch (PDOException $e) { /* names are optional context */ }
            foreach ($vendors as &$v) { $v['vendor_name'] = $names[(int)$v['vendor_id']] ?? ('Vendor #' . (int)$v['vendor_id']); }
            unset($v);
        }

        Logger::info('Admin ran vendor settlement', [
            'admin_id'      => $auth['user_id'],
            'scope'         => $vendorId !== null ? ('vendor:' . $vendorId) : 'all',
            'settled_count' => $result['settled_count'] ?? 0,
            'total_amount'  => $result['total_amount'] ?? 0,
        ]);

        Response::success([
            'scope'         => $vendorId !== null ? $vendorId : 'all',
            'settled_count' => (int)($result['settled_count'] ?? 0),
            'total_amount'  => (float)($result['total_amount'] ?? 0),
            'vendors'       => $vendors,
            'before'        => ['pending' => (float)$before['pending'], 'available' => (float)$before['available']],
            'after'         => ['pending' => (float)$after['pending'], 'available' => (float)$after['available']],
        ], 200, 'Settlement complete');
    }

    // ══════════════════════════════════════════════════════════
    // GET /api/admin/riders — All riders with availability
    // ══════════════════════════════════════════════════════════
    if ($method === 'GET' && $uri === '/api/admin/riders') {
        $rows = $db->query("
            SELECT r.*,
                   u.name, u.phone, u.email,
                   COUNT(d.id)  AS total_deliveries,
                   SUM(d.status = 'delivered') AS completed_deliveries
            FROM riders r
            LEFT JOIN users     u ON u.id = r.user_id
            LEFT JOIN deliveries d ON d.rider_id = r.user_id
            GROUP BY r.id
            ORDER BY r.is_available DESC, u.name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        Response::success(['riders' => $rows, 'total' => count($rows)]);
    }

    // ══════════════════════════════════════════════════════════
    // GET /api/admin/merchants — All merchants with status
    // ══════════════════════════════════════════════════════════
    if ($method === 'GET' && $uri === '/api/admin/merchants') {
        $page   = max(1, (int) ($_GET['page'] ?? 1));
        $limit  = 20;
        $offset = ($page - 1) * $limit;

        $rows = $db->query("
            SELECT m.*,
                   u.name, u.phone, u.email,
                   COUNT(p.id)   AS product_count,
                   COUNT(o.id)   AS order_count
            FROM merchants m
            LEFT JOIN users    u ON u.id = m.user_id
            LEFT JOIN products p ON p.merchant_id = m.id
            LEFT JOIN orders   o ON o.merchant_id = m.id
            GROUP BY m.id
            ORDER BY m.created_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ")->fetchAll(PDO::FETCH_ASSOC);

        $total = $db->query("SELECT COUNT(*) FROM merchants")->fetchColumn();

        Response::success([
            'merchants'  => $rows,
            'pagination' => ['page' => $page, 'limit' => $limit, 'total' => (int) $total],
        ]);
    }

    // ══════════════════════════════════════════════════════════
    // GET /api/admin/system-config — Read all config keys
    // POST /api/admin/system-config — Write a config key
    // ══════════════════════════════════════════════════════════
    if ($uri === '/api/admin/system-config') {

        if ($method === 'GET') {
            $rows = $db->query("
                SELECT id, `key`, `value`, updated_by, updated_at
                FROM system_config
                ORDER BY `key` ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            Response::success(['config' => $rows, 'total' => count($rows)]);
        }

        if ($method === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $key   = trim($input['key']   ?? '');
            $value = trim($input['value'] ?? '');

            if (!$key) Response::validation('Config key is required');

            // Upsert — insert or update
            $db->prepare("
                INSERT INTO system_config (`key`, `value`, updated_by, updated_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    `value`      = VALUES(`value`),
                    updated_by   = VALUES(updated_by),
                    updated_at   = NOW()
            ")->execute([$key, $value, $auth['user_id']]);

            Logger::info('System config updated', [
                'key'        => $key,
                'updated_by' => $auth['user_id'],
            ]);

            Response::success(['key' => $key, 'value' => $value], 200, 'Config updated');
        }
    }

    // ── GET /api/admin/service/bookings ───────────────────────────
    if ($method === 'GET' && $uri === '/api/admin/service/bookings') {
        $page   = max(1, (int) ($_GET['page']  ?? 1));
        $limit  = min(100, (int) ($_GET['limit'] ?? 50));
        $offset = ($page - 1) * $limit;
        $q      = substr(trim($_GET['q'] ?? $_GET['search'] ?? ''), 0, 100);
        $status = $_GET['status'] ?? null;

        // Discover all optional columns in bookings in one query
        $colStmt = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings'");
        $bc = array_flip($colStmt->fetchAll(PDO::FETCH_COLUMN));

        $optSel  = '';
        $optSel .= isset($bc['lifecycle_state'])   ? ', b.lifecycle_state'   : ', b.status AS lifecycle_state';
        $optSel .= isset($bc['assignment_state'])  ? ', b.assignment_state'  : ", IF(b.vendor_id IS NOT NULL, 'worker_assigned', 'unassigned_searching') AS assignment_state";
        $optSel .= isset($bc['payment_route'])
            ? ', b.payment_route'
            : (isset($bc['booking_mode'])
                ? ", CASE WHEN b.booking_mode LIKE '%inspect%' OR b.booking_mode LIKE 'paid%' THEN 'paid_inspection' WHEN b.booking_mode = 'direct_vendor' OR b.booking_mode LIKE 'direct%' THEN 'direct_vendor' ELSE 'free_request' END AS payment_route"
                : (isset($bc['payment_method'])
                    ? ", CASE WHEN b.payment_method = 'online' THEN 'paid_inspection' ELSE 'free_request' END AS payment_route"
                    : ", 'free_request' AS payment_route"));
        $optSel .= isset($bc['booking_mode'])      ? ', b.booking_mode'      : ", 'free_lead' AS booking_mode";
        $optSel .= isset($bc['priority'])          ? ', b.priority'          : ", 'normal_priority' AS priority";
        $optSel .= isset($bc['request_id'])        ? ', b.request_id'        : ", CONCAT('REQ-', LPAD(b.id, 6, '0')) AS request_id";
        $optSel .= isset($bc['booking_number'])    ? ', b.booking_number'    : ', NULL AS booking_number';
        $optSel .= isset($bc['job_id'])            ? ', b.job_id'            : ', b.id AS job_id';
        $optSel .= isset($bc['city'])              ? ', b.city'              : ', NULL AS city';
        $optSel .= isset($bc['locality'])          ? ', b.locality'          : (isset($bc['customer_locality']) ? ', b.customer_locality AS locality' : ', NULL AS locality');
        $optSel .= isset($bc['address'])           ? ', b.address AS full_address' : (isset($bc['customer_address']) ? ', b.customer_address AS full_address' : ', NULL AS full_address');
        $optSel .= isset($bc['issue_description']) ? ', b.issue_description AS issue_text' : (isset($bc['issue_summary']) ? ', b.issue_summary AS issue_text' : ', NULL AS issue_text');
        $optSel .= isset($bc['total'])             ? ', b.total AS amount'   : (isset($bc['amount']) ? ', b.amount' : ', NULL AS amount');
        // vendor_response_status exists on `bookings` (migration 2026_05_28_001) and is
        // written by the vendor accept/reject flow (body/service-engine/.../jobs/{id}/status)
        // for every booking_mode. It was never selected here, so the admin modal's
        // "Vendor" box always fell back to its hardcoded 'not_requested' default
        // regardless of true state. See VENDOR-ASSIGN-VISIBILITY task.
        $optSel .= isset($bc['vendor_response_status']) ? ', b.vendor_response_status' : ", 'not_requested' AS vendor_response_status";
        // V2 structured package selection (nullable — only set for company package requests)
        $optSel .= isset($bc['package_id'])      ? ', b.package_id'      : ', NULL AS package_id';
        $optSel .= isset($bc['package_name'])    ? ', b.package_name'    : ', NULL AS package_name';
        $optSel .= isset($bc['area_sqft'])       ? ', b.area_sqft'       : ', NULL AS area_sqft';
        $optSel .= isset($bc['estimate_amount']) ? ', b.estimate_amount' : ', NULL AS estimate_amount';
        // V2 Expert Visit (nullable — only set for expert-visit bookings)
        $optSel .= isset($bc['visit_type'])         ? ', b.visit_type'         : ', NULL AS visit_type';
        $optSel .= isset($bc['expert_visit_fee'])   ? ', b.expert_visit_fee'   : ', NULL AS expert_visit_fee';
        $optSel .= isset($bc['expert_visit_free'])  ? ', b.expert_visit_free'  : ', NULL AS expert_visit_free';
        $optSel .= isset($bc['final_quote_amount']) ? ', b.final_quote_amount' : ', NULL AS final_quote_amount';

        $svcJoin = isset($bc['service_id'])
            ? 'LEFT JOIN services s ON s.id = b.service_id'
            : 'LEFT JOIN services s ON 1=0';
        $catOn = 'c.id = COALESCE(s.category_id' . (isset($bc['category_id']) ? ', b.category_id' : '') . ')';

        $where = ['1=1'];
        $bind  = [];
        if ($status) {
            $where[]         = 'b.status = :bstatus';
            $bind[':bstatus'] = $status;
        }
        if ($q) {
            $parts = ['u.name LIKE :q', 'u.phone LIKE :q'];
            if (isset($bc['request_id']))     $parts[] = 'b.request_id LIKE :q';
            if (isset($bc['booking_number'])) $parts[] = 'b.booking_number LIKE :q';
            if (isset($bc['city']))           $parts[] = 'b.city LIKE :q';
            $where[]    = '(' . implode(' OR ', $parts) . ')';
            $bind[':q'] = '%' . $q . '%';
        }
        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        $cntStmt = $db->prepare("SELECT COUNT(*) FROM bookings b LEFT JOIN users u ON u.id = b.user_id {$whereSQL}");
        $cntStmt->execute($bind);
        $total = (int) $cntStmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT b.id, b.user_id, b.vendor_id, b.status, b.payment_status, b.created_at, b.updated_at
                    {$optSel},
                    u.name  AS customer_name, u.phone AS customer_phone,
                    v.business_name AS vendor_name,
                    s.name AS service_name, s.category_id AS svc_category_id,
                    c.name AS category_name, c.slug AS category_slug
             FROM bookings b
             LEFT JOIN users      u ON u.id = b.user_id
             LEFT JOIN vendors    v ON v.id = b.vendor_id
             {$svcJoin}
             LEFT JOIN categories c ON {$catOn}
             {$whereSQL}
             ORDER BY b.created_at DESC
             LIMIT :blimit OFFSET :boffset"
        );
        $stmt->bindValue(':blimit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':boffset', $offset, PDO::PARAM_INT);
        foreach ($bind as $k => $bv) $stmt->bindValue($k, $bv);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch vendor eligibility data (category_id + service_localities) for the map below
        $vendorRows = [];
        try {
            $hasLocCol = (int)$db->query(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND COLUMN_NAME = 'service_localities'"
            )->fetchColumn() > 0;
            $locSel = $hasLocCol ? ', v.service_localities' : ", '' AS service_localities";
            $hasThrottleCol = ServiceVendorEligibility::tableHasColumn($db, 'vendors', 'work_throttle');
            $thrSel = $hasThrottleCol ? ', v.work_throttle' : ", NULL AS work_throttle";
            $vrStmt = $db->query("SELECT v.id, v.category_id{$locSel}{$thrSel} FROM vendors v WHERE v.status = 'active'");
            $vendorRows = $vrStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $ignored) {}

        // Canonical vendor->categories from the vendor_categories junction, so
        // assignment eligibility agrees with the customer resolver. Falls back to
        // the single vendors.category_id only for legacy vendors with no rows.
        $vendorCatMap = [];
        try {
            if (ServiceVendorEligibility::tableHasColumn($db, 'vendor_categories', 'vendor_id')) {
                $vcStmt = $db->query("SELECT vendor_id, category_id FROM vendor_categories");
                foreach ($vcStmt->fetchAll(PDO::FETCH_ASSOC) as $vc) {
                    $vendorCatMap[(int)$vc['vendor_id']][(int)$vc['category_id']] = true;
                }
            }
        } catch (PDOException $ignored) {}

        $bookings = array_map(function (array $b) use ($vendorRows, $vendorCatMap): array {
            $reqId    = $b['request_id'] ?? ('REQ-' . str_pad((string) $b['id'], 6, '0', STR_PAD_LEFT));
            $catLabel = $b['category_name'] ?? $b['category_slug'] ?? $b['service_name'] ?? 'Service';
            $assigned = $b['assignment_state'] ?? ($b['vendor_id'] ? 'worker_assigned' : 'unassigned_searching');
            $b['admin_request_view'] = [
                'request_summary'  => ['request_id' => $reqId, 'booking_number' => $b['booking_number'] ?? null],
                'customer_summary' => ['name' => $b['customer_name'] ?? '', 'mobile' => $b['customer_phone'] ?? ''],
                'location'         => ['city' => $b['city'] ?? '', 'locality' => $b['locality'] ?? '', 'full_address' => $b['full_address'] ?? ''],
                'issue_summary'    => ['label' => $catLabel, 'category' => $b['category_slug'] ?? '', 'summary' => $b['issue_text'] ?? '', 'issues' => []],
                'payment_type'     => $b['payment_route']   ?? 'cod',
                'priority'         => $b['priority']        ?? 'normal_priority',
                'lifecycle_state'  => $b['lifecycle_state'] ?? $b['status'] ?? 'pending',
                'assignment_state' => $assigned,
                'booking_mode'     => $b['booking_mode']    ?? 'free_lead',
                'package'          => (!empty($b['package_id']) || !empty($b['package_name'])) ? [
                    'id'              => !empty($b['package_id']) ? (int)$b['package_id'] : null,
                    'name'            => $b['package_name'] ?? null,
                    'area_sqft'       => isset($b['area_sqft']) && $b['area_sqft'] !== null ? (float)$b['area_sqft'] : null,
                    'estimate_amount' => isset($b['estimate_amount']) && $b['estimate_amount'] !== null ? (float)$b['estimate_amount'] : null,
                ] : null,
                'expert_visit'     => (($b['visit_type'] ?? '') === 'expert_visit') ? [
                    'is_free'            => !empty($b['expert_visit_free']),
                    'fee'                => isset($b['expert_visit_fee']) && $b['expert_visit_fee'] !== null ? (float)$b['expert_visit_fee'] : 0.0,
                    'payment_status'     => $b['payment_status'] ?? 'unpaid',
                    'final_quote_amount' => isset($b['final_quote_amount']) && $b['final_quote_amount'] !== null ? (float)$b['final_quote_amount'] : null,
                ] : null,
                'filter_keys'      => [
                    'city'             => $b['city']          ?? '',
                    'category'         => $b['category_slug'] ?? '',
                    'payment_route'    => $b['payment_route'] ?? 'cod',
                    'priority'         => $b['priority']      ?? 'normal_priority',
                    'assignment_state' => $assigned,
                ],
                'timeline_snapshot' => [
                    'latest_event' => 'request_received',
                    'latest_actor' => 'system',
                    'latest_state' => $b['lifecycle_state'] ?? $b['status'] ?? 'pending',
                    'created_at'   => $b['created_at'] ?? null,
                ],
            ];
            // Build real vendor eligibility: category + locality match per vendor
            $bookingCategoryId = (int)($b['svc_category_id'] ?? $b['category_id'] ?? 0);
            $bookingLocality   = strtolower(trim($b['locality'] ?? $b['customer_locality'] ?? ''));
            $eligibleVendors   = [];
            $eligibleCount     = 0;
            foreach ($vendorRows as $vr) {
                $vid            = (int)$vr['id'];
                $vendorCatId    = (int)($vr['category_id'] ?? 0);
                $vendorLocRaw   = $vr['service_localities'] ?? '';
                // Canonical: match against the vendor's junction category set; fall
                // back to the single category_id for legacy vendors with no rows.
                $vendorCats     = $vendorCatMap[$vid] ?? ($vendorCatId > 0 ? [$vendorCatId => true] : []);
                $categoryMatch  = $bookingCategoryId > 0 && isset($vendorCats[$bookingCategoryId]);
                $areaMatch      = false;
                if ($bookingLocality !== '' && $vendorLocRaw !== '') {
                    $areaMatch = stripos($vendorLocRaw, $bookingLocality) !== false
                              || stripos($bookingLocality, strtolower(trim($vendorLocRaw))) !== false;
                }
                // Accountability throttle: 'paused' vendors are NOT offered new
                // work (not assignable); 'reduced' stay assignable but sort last.
                $throttle  = strtolower(trim((string)($vr['work_throttle'] ?? ''))) ?: 'normal';
                $isPaused  = ($throttle === 'paused');
                $assignable = $categoryMatch && ($bookingLocality === '' || $areaMatch) && !$isPaused;
                if ($isPaused) {
                    $reason = 'Paused (throttled)';
                } elseif ($categoryMatch && $areaMatch) {
                    $reason = 'Perfect match';
                } elseif (!$categoryMatch) {
                    $reason = 'Wrong category';
                } else {
                    $reason = 'Different area';
                }
                if ($assignable) $eligibleCount++;
                $eligibleVendors[] = [
                    'vendor_id'          => $vid,
                    'eligibility'        => [
                        'assignable'      => $assignable,
                        'category_match'  => $categoryMatch,
                        'area_match'      => $areaMatch,
                        'reason'          => $reason,
                        'work_throttle'   => $throttle,
                        'deprioritized'   => ($throttle === 'reduced'),
                    ],
                    'service_localities' => $vendorLocRaw,
                ];
            }
            // Throttle-aware order: assignable first, then de-prioritised ('reduced')
            // below normal, paused (not assignable) last.
            usort($eligibleVendors, function ($a, $b) {
                $ae = $a['eligibility']; $be = $b['eligibility'];
                if ($ae['assignable'] !== $be['assignable']) return $ae['assignable'] ? -1 : 1;
                $ad = !empty($ae['deprioritized']); $bd = !empty($be['deprioritized']);
                if ($ad !== $bd) return $ad ? 1 : -1;
                return 0;
            });
            $b['vendor_eligibility'] = ['vendors' => $eligibleVendors, 'eligible_count' => $eligibleCount, 'booking_locality' => $bookingLocality];
            return $b;
        }, $rows);

        Response::success([
            'bookings'    => $bookings,
            'total'       => $total,
            'total_pages' => (int) ceil($total / max($limit, 1)),
            'page'        => $page,
            'limit'       => $limit,
        ]);
    }

    // ── PATCH /api/admin/service/bookings/{id}/assign ─────────────
    if ($method === 'PATCH' && preg_match('#^/api/admin/service/bookings/(\d+)/assign$#', $uri, $m)) {
        $bookingId = (int) $m[1];
        $input    = json_decode(file_get_contents('php://input'), true) ?? [];
        $vendorId = (int) ($input['vendor_id'] ?? 0);
        if (!$vendorId) Response::validation('vendor_id is required');

        $colStmt = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME IN ('assignment_state','lifecycle_state')");
        $bc = array_flip($colStmt->fetchAll(PDO::FETCH_COLUMN));

        $sets = ['vendor_id = :vid', 'updated_at = NOW()'];
        $bind = [':vid' => $vendorId, ':id' => $bookingId];
        if (isset($bc['assignment_state'])) { $sets[] = 'assignment_state = :as'; $bind[':as'] = 'worker_assigned'; }
        if (isset($bc['lifecycle_state']))  { $sets[] = 'lifecycle_state = :ls';  $bind[':ls'] = 'worker_assigned'; }

        $db->prepare('UPDATE bookings SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($bind);
        $syncBookingJobVendor($bookingId, $vendorId);
        Logger::info('Admin assigned vendor to booking', ['admin_id' => $auth['user_id'], 'booking_id' => $bookingId, 'vendor_id' => $vendorId]);

        $vendorOwnerStmt = $db->prepare("SELECT user_id FROM vendors WHERE id = ? LIMIT 1");
        $vendorOwnerStmt->execute([$vendorId]);
        $vendorUserId = $vendorOwnerStmt->fetchColumn();
        if ($vendorUserId) {
            FcmNotifier::notifyUser(
                $db,
                (int)$vendorUserId,
                'New Booking Assigned',
                "You have been assigned booking #{$bookingId}.",
                ['url' => '/vendor/bookings.php', 'booking_id' => (string)$bookingId, 'type' => 'booking_assigned']
            );
        }

        Response::success(['id' => $bookingId, 'vendor_id' => $vendorId], 200, 'Vendor assigned');
    }

    // ── PATCH /api/admin/service/bookings/{id}/ops ────────────────
    if ($method === 'PATCH' && preg_match('#^/api/admin/service/bookings/(\d+)/ops$#', $uri, $m)) {
        $bookingId = (int) $m[1];
        $input  = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = trim($input['action'] ?? '');
        if (!$action) Response::validation('action is required');

        $colStmt = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME IN ('assignment_state','lifecycle_state','booking_mode')");
        $bc = array_flip($colStmt->fetchAll(PDO::FETCH_COLUMN));

        $sets = ['updated_at = NOW()'];
        $bind = [':id' => $bookingId];
        $doJobVendorSync = false;   // B2: sync jobs.vendor_id only for vendor-changing actions
        $jobVendorTarget = null;

        switch ($action) {
            case 'mark_worker_contacted':
                if (isset($bc['assignment_state'])) { $sets[] = 'assignment_state = :as'; $bind[':as'] = 'worker_contacted'; }
                break;
            case 'mark_worker_confirmed':
                if (isset($bc['assignment_state'])) { $sets[] = 'assignment_state = :as'; $bind[':as'] = 'worker_confirmed'; }
                if (isset($bc['lifecycle_state']))  { $sets[] = 'lifecycle_state = :ls';  $bind[':ls'] = 'worker_assigned'; }
                break;
            case 'assign_vendor':
            case 'reassign_vendor':
                $vid = (int) ($input['vendor_id'] ?? 0);
                if (!$vid) Response::validation('vendor_id required for ' . $action);
                $sets[] = 'vendor_id = :vid'; $bind[':vid'] = $vid;
                if (isset($bc['assignment_state'])) { $sets[] = 'assignment_state = :as'; $bind[':as'] = 'worker_assigned'; }
                if (isset($bc['lifecycle_state']))  { $sets[] = 'lifecycle_state = :ls';  $bind[':ls'] = 'worker_assigned'; }
                $doJobVendorSync = true; $jobVendorTarget = $vid;
                break;
            case 'unassign_vendor':
                $sets[] = 'vendor_id = NULL';
                if (isset($bc['assignment_state'])) { $sets[] = 'assignment_state = :as'; $bind[':as'] = 'unassigned_searching'; }
                if (isset($bc['lifecycle_state']))  { $sets[] = 'lifecycle_state = :ls';  $bind[':ls'] = 'searching_worker'; }
                $doJobVendorSync = true; $jobVendorTarget = null;
                break;
            case 'inspection_queued':
                if (isset($bc['lifecycle_state'])) { $sets[] = 'lifecycle_state = :ls'; $bind[':ls'] = 'inspection_queued'; }
                if (isset($bc['booking_mode']))    { $sets[] = 'booking_mode = :bm';    $bind[':bm'] = 'paid_inspection'; }
                break;
            case 'coordinator_assigned':
                if (isset($bc['lifecycle_state']))  { $sets[] = 'lifecycle_state = :ls';  $bind[':ls'] = 'coordinator_assigned'; }
                if (isset($bc['assignment_state'])) { $sets[] = 'assignment_state = :as'; $bind[':as'] = 'inspection_assigned'; }
                break;
            case 'inspection_scheduled':
                if (isset($bc['lifecycle_state'])) { $sets[] = 'lifecycle_state = :ls'; $bind[':ls'] = 'inspection_scheduled'; }
                break;
            case 'inspection_completed':
                if (isset($bc['lifecycle_state'])) { $sets[] = 'lifecycle_state = :ls'; $bind[':ls'] = 'inspection_completed'; }
                break;
            case 'mark_escalated':
                if (isset($bc['lifecycle_state'])) { $sets[] = 'lifecycle_state = :ls'; $bind[':ls'] = 'escalated'; }
                $sets[] = 'status = :st'; $bind[':st'] = 'escalated';
                break;
            default:
                Response::validation('Unknown action: ' . $action);
        }

        $db->prepare('UPDATE bookings SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($bind);
        if ($doJobVendorSync) {
            $syncBookingJobVendor($bookingId, $jobVendorTarget);
        }
        Logger::info('Admin ops action on booking', ['admin_id' => $auth['user_id'], 'booking_id' => $bookingId, 'action' => $action]);
        Response::success(['id' => $bookingId, 'action' => $action], 200, 'Action applied');
    }

    // ── PATCH /api/admin/jobs/{id}/status ──────────────────────────
    if ($method === 'PATCH' && preg_match('#^/api/admin/jobs/(\d+)/status$#', $uri, $m)) {
        $jobId  = (int) $m[1];
        $input  = json_decode(file_get_contents('php://input'), true) ?? [];
        $status = strtolower(trim($input['status'] ?? ''));
        if (!in_array($status, ['completed', 'cancelled', 'escalated'], true)) {
            Response::validation('status must be: completed, cancelled, or escalated');
        }
        // Try jobs table first; if absent, treat job_id as booking id
        $completedBookingId = null;
        try {
            $db->prepare('UPDATE jobs SET status = :st, updated_at = NOW() WHERE id = :id')
               ->execute([':st' => $status, ':id' => $jobId]);
            $bRow = $db->prepare('SELECT booking_id FROM jobs WHERE id = :id LIMIT 1');
            $bRow->execute([':id' => $jobId]);
            $bId = $bRow->fetchColumn();
            if ($bId) {
                $db->prepare('UPDATE bookings SET status = :st, updated_at = NOW() WHERE id = :id')
                   ->execute([':st' => $status, ':id' => (int) $bId]);
                if ($status === 'completed') {
                    $completedBookingId = (int) $bId;
                }
            }
        } catch (PDOException $jobEx) {
            $db->prepare('UPDATE bookings SET status = :st, updated_at = NOW() WHERE id = :id')
               ->execute([':st' => $status, ':id' => $jobId]);
            if ($status === 'completed') {
                $completedBookingId = $jobId;
            }
        }
        if ($completedBookingId !== null) {
            \Core\Helpers\LedgerEngine::processBookingCompletion($completedBookingId, $db);
        }
        Logger::info('Admin force-set job status', ['admin_id' => $auth['user_id'], 'job_id' => $jobId, 'status' => $status]);
        Response::success(['id' => $jobId, 'status' => $status], 200, 'Status updated');
    }

    // ── PATCH /api/admin/services/{id}/price ──────────────────────
    if ($method === 'PATCH' && preg_match('#^/api/admin/services/(\d+)/price$#', $uri, $m)) {
        $serviceId = (int) $m[1];
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        if (!array_key_exists('base_price', $input)) Response::validation('base_price is required');
        $price = (float) $input['base_price'];
        if ($price < 0) Response::validation('base_price must be >= 0');
        $db->prepare('UPDATE services SET base_price = :p, updated_at = NOW() WHERE id = :id')
           ->execute([':p' => $price, ':id' => $serviceId]);
        Logger::info('Admin updated service price', ['admin_id' => $auth['user_id'], 'service_id' => $serviceId, 'base_price' => $price]);
        Response::success(['id' => $serviceId, 'base_price' => $price], 200, 'Price updated');
    }

} catch (PDOException $e) {
    Logger::error('Admin endpoint DB error', ['error' => $e->getMessage(), 'uri' => $uri]);
    Response::serverError();
}

Response::notFound('Admin endpoint');
