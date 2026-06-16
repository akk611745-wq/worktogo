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

$auth = AuthMiddleware::requireRole(ROLE_ADMIN);

try {

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

        $stmt = $db->prepare(
            "SELECT v.id, v.user_id, v.business_name, v.slug, v.type, v.type AS vendor_type,
                    v.status, v.commission_rate, v.rating, v.is_online, v.lat, v.lng,
                    v.created_at, v.updated_at, v.category_id,
                    u.name AS owner_name, u.phone AS owner_phone, u.email AS owner_email
                    {$serviceLocalitiesSelect}{$serviceAreaNotesSelect}
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
        foreach (['icon','image_url','sort_order'] as $col) {
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
                 WHERE v.category_id = ?
                    OR v.id IN (SELECT DISTINCT vendor_id FROM services WHERE category_id = ? AND vendor_id IS NOT NULL)
                 ORDER BY v.status = 'active' DESC, v.business_name ASC
                 LIMIT 50"
            );
            $stmt->execute([$categoryId, $categoryId]);
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

    // ── PATCH /api/admin/categories/{id} ───────────────────────
    if ($method === 'PATCH' && preg_match('#^/api/admin/categories/(\d+)$#', $uri, $m)) {
        $categoryId = (int)$m[1];
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $updates = [];
        $bind = [':id' => $categoryId];
        foreach (['name','slug','status','icon','image_url','sort_order','commission_rate','inspection_price'] as $col) {
            if (!array_key_exists($col, $input)) continue;
            $chk = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = ?");
            $chk->execute([$col]);
            if ((int)$chk->fetchColumn() === 0) continue;
            $updates[] = "{$col} = :{$col}";
            $bind[":{$col}"] = match($col) {
                'sort_order'                  => (int)$input[$col],
                'commission_rate',
                'inspection_price'            => (float)$input[$col],
                default                       => trim((string)$input[$col]),
            };
        }
        if (!$updates) Response::validation('No supported category fields to update');
        $db->prepare("UPDATE categories SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = :id")->execute($bind);
        Logger::info('Admin updated category', ['admin_id' => $auth['user_id'], 'category_id' => $categoryId]);
        Response::success(['id' => $categoryId], 200, 'Category updated');
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

    // ── POST /api/admin/vendors/{id}/approve ───────────────────
    if ($method === 'POST' && preg_match('#^/api/admin/vendors/(\d+)/approve$#', $uri, $m)) {
        $vendorId = (int) $m[1];
        $db->prepare("UPDATE vendors SET status = 'active', updated_at = NOW() WHERE id = ?")
           ->execute([$vendorId]);
        
        // Also update the user's role to match vendor type
        $vendorRow = $db->prepare("SELECT type, user_id FROM vendors WHERE id = ? LIMIT 1");
        $vendorRow->execute([$vendorId]);
        $vData = $vendorRow->fetch(PDO::FETCH_ASSOC);
        if ($vData) {
            $newRole = ($vData['type'] === 'service') ? 'vendor_service' : 'vendor_shopping';
            $db->prepare("UPDATE users SET role = ?, updated_at = NOW() WHERE id = ?")
               ->execute([$newRole, $vData['user_id']]);
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
        $db->prepare("UPDATE vendors SET category_id = ?, updated_at = NOW() WHERE id = ?")
           ->execute([$categoryId, $vendorId]);
        Logger::info('Admin updated vendor category', ['admin_id' => $auth['user_id'], 'vendor_id' => $vendorId, 'category_id' => $categoryId]);
        Response::success(null, 200, 'Vendor category updated');
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
        Logger::info('Admin assigned vendor to booking', ['admin_id' => $auth['user_id'], 'booking_id' => $bookingId, 'vendor_id' => $vendorId]);
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
        $optSel .= isset($bc['payment_method'])    ? ', b.payment_method AS payment_route' : (isset($bc['payment_route']) ? ', b.payment_route' : ", 'cod' AS payment_route");
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
            $vrStmt = $db->query("SELECT v.id, v.category_id{$locSel} FROM vendors v WHERE v.status = 'active'");
            $vendorRows = $vrStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $ignored) {}

        $bookings = array_map(function (array $b) use ($vendorRows): array {
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
                $categoryMatch  = $bookingCategoryId > 0 && $vendorCatId === $bookingCategoryId;
                $areaMatch      = false;
                if ($bookingLocality !== '' && $vendorLocRaw !== '') {
                    $areaMatch = stripos($vendorLocRaw, $bookingLocality) !== false
                              || stripos($bookingLocality, strtolower(trim($vendorLocRaw))) !== false;
                }
                $assignable = $categoryMatch && ($bookingLocality === '' || $areaMatch);
                if ($categoryMatch && $areaMatch) {
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
                    ],
                    'service_localities' => $vendorLocRaw,
                ];
            }
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
        Logger::info('Admin assigned vendor to booking', ['admin_id' => $auth['user_id'], 'booking_id' => $bookingId, 'vendor_id' => $vendorId]);
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
                break;
            case 'unassign_vendor':
                $sets[] = 'vendor_id = NULL';
                if (isset($bc['assignment_state'])) { $sets[] = 'assignment_state = :as'; $bind[':as'] = 'unassigned_searching'; }
                if (isset($bc['lifecycle_state']))  { $sets[] = 'lifecycle_state = :ls';  $bind[':ls'] = 'searching_worker'; }
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
        try {
            $db->prepare('UPDATE jobs SET status = :st, updated_at = NOW() WHERE id = :id')
               ->execute([':st' => $status, ':id' => $jobId]);
            $bRow = $db->prepare('SELECT booking_id FROM jobs WHERE id = :id LIMIT 1');
            $bRow->execute([':id' => $jobId]);
            $bId = $bRow->fetchColumn();
            if ($bId) {
                $db->prepare('UPDATE bookings SET status = :st, updated_at = NOW() WHERE id = :id')
                   ->execute([':st' => $status, ':id' => (int) $bId]);
            }
        } catch (PDOException $jobEx) {
            $db->prepare('UPDATE bookings SET status = :st, updated_at = NOW() WHERE id = :id')
               ->execute([':st' => $status, ':id' => $jobId]);
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
