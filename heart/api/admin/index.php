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

        $stmt = $db->prepare("
            SELECT SUM(amount) FROM wallet_transactions
            WHERE entity_type = 'platform' AND type = 'credit' AND created_at >= :today AND status = 'settled'
        ");
        $stmt->execute([':today' => $todayStart]);
        // FIX: fetchColumn() returns false (no rows) or NULL (SUM of empty set); both → 0.0
        $platform_earnings = $stmt->fetchColumn();

        $stmt = $db->prepare("SELECT SUM(pending_balance) FROM vendor_wallets");
        $stmt->execute();
        $pending_vendor_payout = $stmt->fetchColumn();

        $finance['total_revenue_today']     = (float)($finance['total_revenue_today'] ?? 0);
        $finance['platform_earnings_today'] = (float)($platform_earnings ?: 0);
        $finance['pending_vendor_payout']   = (float)($pending_vendor_payout ?: 0);
        $finance['total_refunds_today']     = (float)($finance['total_refunds_today'] ?? 0);

        // --- DRIVER STATUS ---
        // FIX: When no active drivers exist the JOIN returns zero rows, so fetch()
        //      returns false — not an array row of NULLs.  Guard with ?: [] so the
        //      subsequent array-key accesses never run on a boolean.
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
        $stmt = $db->prepare("
            SELECT v.id, v.business_name, SUM(o.total) as total_sales
            FROM vendors v
            JOIN orders o ON v.id = o.vendor_id
            WHERE o.status IN ('delivered')
            GROUP BY v.id
            ORDER BY total_sales DESC
            LIMIT 5
        ");
        $stmt->execute();
        // FIX: fetchAll() already returns [] on no rows — safe.
        $top_vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
            'orders'       => $orders,
            'finance'      => $finance,
            'driver_status' => $driver_stats,
            'system_health' => $system_health,
            'insights'     => [
                'top_vendors'           => $top_vendors,
                'most_cancelled_items'  => $most_cancelled_items,
                'cash_flow'             => $cash_flow,
            ],
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
        $page   = max(1, (int) ($_GET['page']  ?? 1));
        $limit  = min(100, (int) ($_GET['limit'] ?? 20));
        $offset = ($page - 1) * $limit;

        $where = ['1=1'];
        $bind  = [];

        if (!empty($_GET['status'])) {
            $where[] = 'status = :status';
            $bind[':status'] = $_GET['status'];
        }

        $whereSQL = 'WHERE ' . implode(' AND ', $where);
        
        $countStmt = $db->prepare("SELECT COUNT(*) FROM vendors {$whereSQL}");
        $countStmt->execute($bind);
        $total = (int) $countStmt->fetchColumn();
        
        $stmt = $db->prepare("SELECT * FROM vendors {$whereSQL} ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach ($bind as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();

        Response::success([
            'vendors' => $stmt->fetchAll(),
            'total'   => $total,
            'page'    => $page,
            'limit'   => $limit
        ]);
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
        $page   = max(1, (int) ($_GET['page']  ?? 1));
        $limit  = min(100, (int) ($_GET['limit'] ?? 20));
        $offset = ($page - 1) * $limit;

        $where = ['deleted_at IS NULL'];
        $bind  = [];

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
            "SELECT id, uuid, name, phone, email, role, status, created_at, last_login_at
             FROM users {$whereSQL}
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
        
        Logger::info('Admin approved vendor', ['admin_id' => $auth['user_id'], 'vendor_id' => $vendorId]);
        Response::success(null, 200, 'Vendor approved');
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
        $db->prepare("UPDATE users SET status = 'suspended', updated_at = NOW() WHERE id = ?")
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

    // ── POST /api/admin/orders/{id}/cancel ────────────────────────
    if ($method === 'POST' && preg_match('#^/api/admin/orders/(\d+)/cancel$#', $uri, $m)) {
        $orderId = (int) $m[1];
        $db->prepare("UPDATE orders SET status = 'cancelled', updated_at = NOW() WHERE id = ?")
           ->execute([$orderId]);
        Logger::info('Admin cancelled order', ['admin_id' => $auth['user_id'], 'order_id' => $orderId]);
        Response::success(null, 200, 'Order cancelled');
    }

} catch (PDOException $e) {
    Logger::error('Admin endpoint DB error', ['error' => $e->getMessage(), 'uri' => $uri]);
    Response::serverError();
}

Response::notFound('Admin endpoint');
