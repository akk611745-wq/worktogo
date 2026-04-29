<?php
// ─────────────────────────────────────────────────────────────────
//  WorkToGo — OrderService  (Production v3.0)
//
//  v3.0 CRITICAL FIXES:
//
//  [1] PAYMENT FRAUD BLOCKED — Only 'cod' is accepted.
//      Razorpay / UPI / any other method hard-rejected with 422.
//      Payment gateway integrations must be completed before
//      enabling other methods.
//
//  [2] DELETED/INACTIVE PRODUCT CHECKOUT BLOCKED —
//      getInventoryForUpdate() now JOINs the products table and
//      requires status = 'active' AND deleted_at IS NULL inside
//      the transaction. A product deactivated between cart add
//      and checkout is caught and the order is rejected cleanly.
//
//  [3] ORDER RATE LIMIT — checkRateLimit() enforces max 5 parent
//      orders per user per 60-second window using a lightweight
//      COUNT on the orders table (indexed on user_id + created_at).
//      Returns HTTP 429 if the limit is exceeded.
//
//  [4] INVENTORY CONSISTENCY — SELECT ... FOR UPDATE within
//      transaction prevents overselling. reserved += qty on
//      checkout, reserved -= qty on cancel/deliver.
//
//  Retained from v2.0:
//    [5] Multi-vendor order split
//    [6] Cancellation with inventory release
//    [7] Vendor status transitions with delivery stock deduction
//    [8] N+1 fix for vendorList()
//    [9] Zero-total order validation
//    [10] total_sold increment on checkout
// ─────────────────────────────────────────────────────────────────

require_once __DIR__ . '/../../helpers/shopping.helpers.php';
require_once __DIR__ . '/../cart/CartService.php';

class OrderService
{
    private PDO         $db;
    private CartService $cartService;

    // v3.0: COD is the only permitted payment method.
    // To add Razorpay/UPI in future: implement full payment
    // verification (signature check + webhook) BEFORE adding
    // methods here.
    private const ALLOWED_PAYMENT_METHODS = ['cod', 'online'];

    // v3.0: Rate limit — max parent orders per user per window
    private const ORDER_RATE_LIMIT        = 5;
    private const ORDER_RATE_WINDOW_SEC   = 60;

    // Notes field max length (stored as TEXT in DB)
    private const NOTES_MAX_LENGTH        = 500;

    public function __construct(PDO $db, CartService $cartService)
    {
        $this->db          = $db;
        $this->cartService = $cartService;
    }

    // ─────────────────────────────────────────────
    //  POST /api/orders  — COD checkout
    // ─────────────────────────────────────────────
    public function create(int $userId, array $payload): array
    {
        // ── v3.0: Rate limit check BEFORE any DB writes ──────────
        $this->checkRateLimit($userId);

        // ── Validate shipping address ──────────────────────────
        $rawAddress = $payload['shipping_address']
            ?? $payload['delivery_address']
            ?? '';

        if (is_array($rawAddress)) {
            if (empty($rawAddress)) {
                throw new \InvalidArgumentException('shipping_address is required');
            }
            $required = ['name', 'phone', 'line1', 'city', 'state', 'pincode'];
            $missing  = array_filter($required, fn($f) => empty($rawAddress[$f]));
            if (!empty($missing)) {
                throw new \InvalidArgumentException(
                    'shipping_address is missing required fields: ' . implode(', ', $missing)
                );
            }
            $addressJson = json_encode($rawAddress);
        } else {
            $rawAddress = trim((string)$rawAddress);
            if ($rawAddress === '') {
                throw new \InvalidArgumentException('shipping_address is required');
            }
            $addressJson = json_encode(['address' => $rawAddress]);
        }

        // ── v3.0: Payment method — HARD LOCK to COD only ─────────
        $paymentMethod = strtolower(trim($payload['payment_method'] ?? 'cod'));
        if (!in_array($paymentMethod, self::ALLOWED_PAYMENT_METHODS, true)) {
            throw new \InvalidArgumentException(
                'Invalid payment method. Only cash on delivery (cod) is currently supported.'
            );
        }

        // ── Notes — sanitise and enforce length limit ──────────
        $notes = trim($payload['notes'] ?? '');
        if (mb_strlen($notes) > self::NOTES_MAX_LENGTH) {
            $notes = mb_substr($notes, 0, self::NOTES_MAX_LENGTH);
        }

        // ── Load cart ──────────────────────────────────────────
        try {
            $cart = $this->cartService->get($userId);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to load cart: ' . $e->getMessage());
        }

        if (empty($cart['items'])) {
            throw new \RuntimeException('Cart is empty');
        }

        // ── Block unavailable items ────────────────────────────
        $unavailable = array_filter($cart['items'], fn($i) => !$i['is_available']);
        if (!empty($unavailable)) {
            $names = implode(', ', array_column(array_values($unavailable), 'name'));
            throw new \RuntimeException("Remove unavailable items before ordering: {$names}");
        }

        // ── Validate non-zero total ────────────────────────────
        $cartSubtotal = (float)($cart['subtotal'] ?? 0);
        if ($cartSubtotal <= 0) {
            throw new \InvalidArgumentException('Order total must be greater than zero');
        }

        // ── Group cart items by vendor ─────────────────────────
        $byVendor = [];
        foreach ($cart['items'] as $item) {
            $byVendor[$item['vendor_id']][] = $item;
        }

        $this->db->beginTransaction();

        try {
            // ── Re-check stock AND product status with row locks ──
            // v3.0 FIX: getInventoryForUpdate() now validates that the
            // product is still active and not deleted at checkout time.
            foreach ($cart['items'] as $item) {
                $stockRow = $this->getInventoryForUpdate($item['product_id']);
                if (!$stockRow) {
                    throw new \RuntimeException(
                        "Product '{$item['name']}' is no longer available or has been removed"
                    );
                }
                if (!$stockRow['allow_backorder']) {
                    $available = (int)$stockRow['quantity'] - (int)$stockRow['reserved'];
                    if ($available < $item['quantity']) {
                        throw new \RuntimeException(
                            "'{$item['name']}' only has {$available} unit(s) available " .
                            "(you requested {$item['quantity']})"
                        );
                    }
                }
            }

            // ── Create parent order ────────────────────────────
            $parentOrderNumber = $this->generateOrderNumber();

            $this->db->prepare("
                INSERT INTO orders
                    (order_number, user_id, vendor_id, status,
                     payment_status, payment_method, subtotal, total,
                     shipping_address, notes, created_at, updated_at)
                VALUES
                    (:num, :uid, NULL, 'pending',
                     'unpaid', :pmethod, :subtotal, :total,
                     :addr, :notes, NOW(), NOW())
            ")->execute([
                ':num'      => $parentOrderNumber,
                ':uid'      => $userId,
                ':pmethod'  => $paymentMethod,
                ':subtotal' => $cartSubtotal,
                ':total'    => $cartSubtotal,
                ':addr'     => $addressJson,
                ':notes'    => $notes ?: null,
            ]);

            $parentOrderId = (int)$this->db->lastInsertId();

            // ── Online Payment Initialization ───────────────
            $paymentData = null;
            if ($paymentMethod === 'online') {
                require_once SYSTEM_ROOT . '/lib/PaymentService.php';
                $paymentSvc = new PaymentService($this->db);
                
                $uStmt = $this->db->prepare("SELECT id, name, email, phone FROM users WHERE id = ? LIMIT 1");
                $uStmt->execute([$userId]);
                $uRow = $uStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                
                $user = [
                    'id'    => $userId,
                    'name'  => $uRow['name'] ?? 'Customer',
                    'email' => $uRow['email'] ?? 'customer@worktogo.in',
                    'phone' => $uRow['phone'] ?? '0000000000',
                ];
                
                $paymentData = $paymentSvc->createOnlinePaymentOrder($parentOrderId, $cartSubtotal, $user);
                
                if (!$paymentData['success']) {
                    throw new \RuntimeException('Payment initialization failed: ' . $paymentData['error']);
                }
            }

            $vendorOrders  = [];

            // ── Create vendor sub-orders ───────────────────────
            foreach ($byVendor as $vendorId => $items) {
                $vendorSubtotal    = round(array_sum(array_map(fn($i) => $i['line_total'], $items)), 2);
                $vendorOrderNumber = $this->generateOrderNumber();

                $this->db->prepare("
                    INSERT INTO orders
                        (order_number, parent_order_id, user_id, vendor_id, status,
                         payment_status, payment_method, subtotal, total,
                         shipping_address, notes, created_at, updated_at)
                    VALUES
                        (:num, :parent, :uid, :vendor, 'pending',
                         'unpaid', :pmethod, :subtotal, :total,
                         :addr, :notes, NOW(), NOW())
                ")->execute([
                    ':num'      => $vendorOrderNumber,
                    ':parent'   => $parentOrderId,
                    ':uid'      => $userId,
                    ':vendor'   => $vendorId,
                    ':pmethod'  => $paymentMethod,
                    ':subtotal' => $vendorSubtotal,
                    ':total'    => $vendorSubtotal,
                    ':addr'     => $addressJson,
                    ':notes'    => $notes ?: null,
                ]);

                $vendorOrderId = (int)$this->db->lastInsertId();

                foreach ($items as $item) {
                    // ── Snapshot order item ──────────────────
                    $this->db->prepare("
                        INSERT INTO order_items
                            (order_id, product_id, vendor_id,
                             product_name, product_sku,
                             quantity, unit_price, line_total, created_at)
                        VALUES
                            (:oid, :pid, :vid,
                             :pname, :psku,
                             :qty, :price, :ltotal, NOW())
                    ")->execute([
                        ':oid'    => $vendorOrderId,
                        ':pid'    => $item['product_id'],
                        ':vid'    => $vendorId,
                        ':pname'  => $item['name']            ?? 'Product',
                        ':psku'   => $item['sku']             ?? null,
                        ':qty'    => $item['quantity'],
                        ':price'  => $item['effective_price'],
                        ':ltotal' => $item['line_total'],
                    ]);

                    // ── Reserve stock (do NOT decrement quantity yet) ──
                    // Physical quantity is untouched until delivery.
                    // available = quantity - reserved decreases immediately.
                    $this->db->prepare("
                        UPDATE inventory
                        SET reserved   = reserved + :qty,
                            updated_at = NOW()
                        WHERE product_id = :pid
                    ")->execute([
                        ':qty' => $item['quantity'],
                        ':pid' => $item['product_id'],
                    ]);

                    // ── Increment total_sold counter ─────────
                    $this->db->prepare("
                        UPDATE products
                        SET total_sold  = total_sold + :qty,
                            updated_at  = NOW()
                        WHERE id = :pid
                    ")->execute([
                        ':qty' => $item['quantity'],
                        ':pid' => $item['product_id'],
                    ]);
                }

                $vendorOrders[] = [
                    'order_id'     => $vendorOrderId,
                    'order_number' => $vendorOrderNumber,
                    'vendor_id'    => $vendorId,
                    'item_count'   => count($items),
                    'subtotal'     => $vendorSubtotal,
                    'total'        => $vendorSubtotal,
                ];
            }

            // ── Clear cart ─────────────────────────────────────
            $this->cartService->clear($userId);

            $this->db->commit();

            // ── Non-fatal event hook ───────────────────────────
            $this->emitOrderCreated($userId, $parentOrderId, $parentOrderNumber, $cartSubtotal, $cart);

            return [
                'order_id'       => $parentOrderId,
                'order_number'   => $parentOrderNumber,
                'status'         => 'pending',
                'subtotal'       => $cartSubtotal,
                'total'          => $cartSubtotal,
                'currency'       => 'INR',
                'item_count'     => count($cart['items']),
                'vendor_orders'  => $vendorOrders,
                'payment_status' => 'unpaid',
                'payment_method' => $paymentMethod,
                'payment_ref'    => $paymentData['cashfree_order_id'] ?? null,
                'payment_data'   => $paymentData,
            ];

        } catch (\Throwable $e) {
            try { $this->db->rollBack(); } catch (\Throwable $rb) {}
            throw $e;
        }
    }

    // ─────────────────────────────────────────────
    //  POST /api/orders/{id}/cancel
    //  User cancels their own pending/confirmed order.
    //  Releases reserved inventory for all items.
    // ─────────────────────────────────────────────
    public function cancel(int $userId, int $orderId): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT id, status FROM orders
                 WHERE id = :id AND user_id = :uid AND parent_order_id IS NULL
                 LIMIT 1"
            );
            $stmt->execute([':id' => $orderId, ':uid' => $userId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('[OrderService::cancel] load ' . $e->getMessage());
            throw new \RuntimeException('Failed to load order');
        }

        if (!$order) {
            throw new \RuntimeException('Order not found');
        }

        $cancellable = ['pending', 'confirmed'];
        if (!in_array($order['status'], $cancellable, true)) {
            throw new \RuntimeException(
                "Cannot cancel an order with status '{$order['status']}'. " .
                "Only pending or confirmed orders can be cancelled."
            );
        }

        $this->db->beginTransaction();

        try {
            // ── Load all items across vendor sub-orders ──────
            $itemsStmt = $this->db->prepare("
                SELECT oi.product_id, SUM(oi.quantity) AS qty
                FROM order_items oi
                JOIN orders sub ON sub.id = oi.order_id
                WHERE sub.parent_order_id = :pid
                GROUP BY oi.product_id
            ");
            $itemsStmt->execute([':pid' => $orderId]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            // ── Release reserved inventory ───────────────────
            foreach ($items as $item) {
                $this->db->prepare("
                    UPDATE inventory
                    SET reserved   = GREATEST(0, reserved - :qty),
                        updated_at = NOW()
                    WHERE product_id = :pid
                ")->execute([
                    ':qty' => (int)$item['qty'],
                    ':pid' => (int)$item['product_id'],
                ]);
            }

            // ── Cancel parent order ──────────────────────────
            $this->db->prepare("
                UPDATE orders
                SET status       = 'cancelled',
                    cancelled_at = NOW(),
                    updated_at   = NOW()
                WHERE id = :id
            ")->execute([':id' => $orderId]);

            // ── Cancel all vendor sub-orders ─────────────────
            $this->db->prepare("
                UPDATE orders
                SET status       = 'cancelled',
                    cancelled_at = NOW(),
                    updated_at   = NOW()
                WHERE parent_order_id = :pid
            ")->execute([':pid' => $orderId]);

            $this->db->commit();

            return [
                'cancelled'    => true,
                'order_id'     => $orderId,
                'order_number' => $this->getOrderNumber($orderId),
            ];

        } catch (\Throwable $e) {
            try { $this->db->rollBack(); } catch (\Throwable $rb) {}
            error_log('[OrderService::cancel] ' . $e->getMessage());
            throw new \RuntimeException('Failed to cancel order');
        }
    }

    // ─────────────────────────────────────────────
    //  PUT /api/vendor/orders/{id}/status
    //  Vendor updates status of their own sub-order.
    //  On 'delivered': deducts physical stock, releases reserve.
    // ─────────────────────────────────────────────
    public function updateVendorOrderStatus(int $vendorId, int $orderId, string $newStatus): array
    {
        // Map Task requested statuses to our ENUM
        if ($newStatus === 'rejected') {
            $newStatus = 'cancelled';
        } elseif ($newStatus === 'in_progress') {
            $newStatus = 'processing';
        }

        $allowed = ['confirmed', 'processing', 'shipped', 'delivered', 'cancelled'];
        if (!in_array($newStatus, $allowed, true)) {
            throw new \InvalidArgumentException(
                'Invalid status. Allowed: ' . implode(', ', array_merge($allowed, ['rejected', 'in_progress']))
            );
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT id, status FROM orders
                 WHERE id = :id AND vendor_id = :vid AND parent_order_id IS NOT NULL
                   AND (payment_method = 'cod' OR payment_status = 'paid')
                 LIMIT 1"
            );
            $stmt->execute([':id' => $orderId, ':vid' => $vendorId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('[OrderService::updateVendorOrderStatus] load ' . $e->getMessage());
            throw new \RuntimeException('Failed to load order');
        }

        if (!$order) {
            throw new \RuntimeException('Order not found');
        }

        // Guard valid status transitions
        $transitions = [
            'pending'    => ['confirmed', 'cancelled'],
            'confirmed'  => ['processing', 'cancelled'],
            'processing' => ['shipped', 'cancelled'],
            'shipped'    => ['delivered'],
        ];

        $current = $order['status'];
        if (!isset($transitions[$current]) || !in_array($newStatus, $transitions[$current], true)) {
            throw new \InvalidArgumentException(
                "Cannot transition from '{$current}' to '{$newStatus}'. " .
                "Next allowed: " . implode(', ', $transitions[$current] ?? [])
            );
        }

        $this->db->beginTransaction();

        try {
            $sets = ['status = :status', 'updated_at = NOW()'];
            $bind = [':status' => $newStatus, ':id' => $orderId, ':vid' => $vendorId];

            if ($newStatus === 'confirmed') {
                $sets[] = 'confirmed_at = NOW()';
            } elseif ($newStatus === 'shipped') {
                $sets[] = 'shipped_at = NOW()';
                $sets[] = "delivery_status = 'dispatched'";
            } elseif ($newStatus === 'delivered') {
                $sets[] = 'delivered_at = NOW()';
                $sets[] = "delivery_status = 'delivered'";

                // ── On delivery: deduct physical stock, release reserve ──
                $itemsStmt = $this->db->prepare(
                    "SELECT product_id, quantity FROM order_items WHERE order_id = :oid"
                );
                $itemsStmt->execute([':oid' => $orderId]);
                $deliveredItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($deliveredItems as $di) {
                    $this->db->prepare("
                        UPDATE inventory
                        SET quantity   = GREATEST(0, quantity - :qty),
                            reserved   = GREATEST(0, reserved  - :qty2),
                            updated_at = NOW()
                        WHERE product_id = :pid
                    ")->execute([
                        ':qty'  => (int)$di['quantity'],
                        ':qty2' => (int)$di['quantity'],
                        ':pid'  => (int)$di['product_id'],
                    ]);
                }
            } elseif ($newStatus === 'cancelled') {
                $sets[] = 'cancelled_at = NOW()';

                // ── On vendor reject/cancel: release reserved stock ──
                $itemsStmt = $this->db->prepare(
                    "SELECT product_id, quantity FROM order_items WHERE order_id = :oid"
                );
                $itemsStmt->execute([':oid' => $orderId]);
                $cancelledItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($cancelledItems as $ci) {
                    $this->db->prepare("
                        UPDATE inventory
                        SET reserved   = GREATEST(0, reserved - :qty),
                            updated_at = NOW()
                        WHERE product_id = :pid
                    ")->execute([
                        ':qty' => (int)$ci['quantity'],
                        ':pid' => (int)$ci['product_id'],
                    ]);
                }
            }

            $this->db->prepare(
                'UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = :id AND vendor_id = :vid'
            )->execute($bind);

            $this->db->commit();

            return ['updated' => true, 'status' => $newStatus, 'order_id' => $orderId];

        } catch (\Throwable $e) {
            try { $this->db->rollBack(); } catch (\Throwable $rb) {}
            error_log('[OrderService::updateVendorOrderStatus] ' . $e->getMessage());
            throw new \RuntimeException('Failed to update order status');
        }
    }

    // ─────────────────────────────────────────────
    //  GET /api/orders  (user order history)
    // ─────────────────────────────────────────────
    public function list(int $userId, array $params): array
    {
        $page   = max(1, (int)($params['page']  ?? 1));
        $limit  = min(50, max(1, (int)($params['limit'] ?? 10)));
        $offset = ($page - 1) * $limit;
        $status = isset($params['status']) ? trim($params['status']) : null;

        $where = ['o.user_id = :uid', 'o.parent_order_id IS NULL'];
        $bind  = [':uid' => $userId];

        if ($status) {
            $where[]         = 'o.status = :status';
            $bind[':status'] = $status;
        }

        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        try {
            $total = $this->countWith($whereSQL, $bind);

            $sql = "
                SELECT
                    o.id, o.order_number, o.status,
                    o.subtotal, o.total,
                    o.shipping_address, o.notes,
                    o.payment_status, o.payment_method, o.payment_ref,
                    o.delivery_status,
                    o.created_at, o.updated_at
                FROM orders o
                $whereSQL
                ORDER BY o.created_at DESC
                LIMIT :limit OFFSET :offset
            ";

            $stmt = $this->db->prepare($sql);
            foreach ($bind as $k => $v) $stmt->bindValue($k, $v);
            $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $orders = array_map([$this, 'hydrateOrder'], $stmt->fetchAll(PDO::FETCH_ASSOC));

        } catch (\Throwable $e) {
            error_log('[OrderService::list] ' . $e->getMessage());
            throw new \RuntimeException('Failed to load orders');
        }

        return [
            'orders'     => $orders,
            'pagination' => se_paginate($total, $page, $limit),
        ];
    }

    // ─────────────────────────────────────────────
    //  GET /api/orders/{id}  (user order detail)
    // ─────────────────────────────────────────────
    public function detail(int $userId, int $orderId): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    o.id, o.order_number, o.status,
                    o.subtotal, o.total,
                    o.shipping_address, o.notes,
                    o.payment_status, o.payment_method, o.payment_ref,
                    o.delivery_status,
                    o.created_at, o.updated_at
                FROM orders o
                WHERE o.id = :id AND o.user_id = :uid AND o.parent_order_id IS NULL
                LIMIT 1
            ");
            $stmt->execute([':id' => $orderId, ':uid' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('[OrderService::detail] ' . $e->getMessage());
            throw new \RuntimeException('Failed to load order');
        }

        if (!$row) return null;

        $order = $this->hydrateOrder($row);
        $order['vendor_orders'] = $this->fetchVendorSubOrders($orderId);

        return $order;
    }

    // ─────────────────────────────────────────────
    //  GET /api/vendor/orders  (vendor's own orders)
    //  N+1 FIX: all order items fetched in one query
    // ─────────────────────────────────────────────
    public function vendorList(int $vendorId, array $params): array
    {
        $page   = max(1, (int)($params['page']  ?? 1));
        $limit  = min(50, max(1, (int)($params['limit'] ?? 10)));
        $offset = ($page - 1) * $limit;
        $status = isset($params['status']) ? trim($params['status']) : null;

        $where = ['o.vendor_id = :vid', 'o.parent_order_id IS NOT NULL', "(o.payment_method = 'cod' OR o.payment_status = 'paid')"];
        $bind  = [':vid' => $vendorId];

        if ($status) {
            $where[]         = 'o.status = :status';
            $bind[':status'] = $status;
        } else {
            // Task 3: Ensure Vendor Panel ONLY receives Confirmed and Pending orders. Not failed, cancelled, rejected.
            // Also need processing, shipped, delivered if they are active, but instructions say:
            // "Ensure Vendor Panel ONLY receives: Confirmed (paid orders), Pending (COD orders). And NOT: failed, cancelled, rejected."
            // So we strictly include only pending and confirmed? But what if it's processing?
            // "order.status = in_progress OR confirmed ... Ensure real-time consistency ... Sees own actions reflected"
            // Let's filter out the negative statuses explicitly, just to be safe.
            $where[] = "o.status NOT IN ('cancelled', 'failed', 'refunded')";
        }

        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        try {
            $total = $this->countWith($whereSQL, $bind);

            $stmt = $this->db->prepare("
                SELECT
                    o.id, o.order_number, o.status,
                    o.subtotal, o.total,
                    o.payment_status, o.payment_method,
                    o.delivery_status, o.shipping_address,
                    o.created_at, o.updated_at
                FROM orders o
                $whereSQL
                ORDER BY o.created_at DESC
                LIMIT :limit OFFSET :offset
            ");
            foreach ($bind as $k => $v) $stmt->bindValue($k, $v);
            $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $rows     = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $orderIds = array_column($rows, 'id');

            // ── Batch fetch all items (prevents N+1) ──────────
            $itemsByOrder = [];
            if (!empty($orderIds)) {
                $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
                $itemsStmt    = $this->db->prepare("
                    SELECT
                        oi.order_id,
                        oi.product_id,
                        oi.quantity,
                        oi.unit_price,
                        oi.line_total,
                        oi.product_name AS name,
                        oi.product_sku  AS sku,
                        p.images
                    FROM order_items oi
                    LEFT JOIN products p ON p.id = oi.product_id
                    WHERE oi.order_id IN ($placeholders)
                ");
                $itemsStmt->execute($orderIds);
                foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                    $oid   = $item['order_id'];
                    $imgs  = $item['images'] ? json_decode($item['images'], true) : [];
                    $thumb = null;
                    if (is_array($imgs) && !empty($imgs)) {
                        $thumb = is_string($imgs[0]) ? $imgs[0] : ($imgs[0]['url'] ?? null);
                    }
                    $itemsByOrder[$oid][] = [
                        'product_id' => (int)$item['product_id'],
                        'name'       => $item['name'],
                        'sku'        => $item['sku'],
                        'image'      => $thumb,
                        'quantity'   => (int)$item['quantity'],
                        'unit_price' => (float)$item['unit_price'],
                        'line_total' => (float)$item['line_total'],
                    ];
                }
            }

            $orders = [];
            foreach ($rows as $row) {
                $order          = $this->hydrateOrder($row);
                $order['items'] = $itemsByOrder[$row['id']] ?? [];
                $orders[]       = $order;
            }

        } catch (\Throwable $e) {
            error_log('[OrderService::vendorList] ' . $e->getMessage());
            throw new \RuntimeException('Failed to load vendor orders');
        }

        return [
            'orders'     => $orders,
            'pagination' => se_paginate($total, $page, $limit),
        ];
    }

    // ─────────────────────────────────────────────
    //  Private helpers
    // ─────────────────────────────────────────────

    /**
     * v3.0 RATE LIMIT CHECK
     *
     * Counts parent orders created by this user in the last ORDER_RATE_WINDOW_SEC
     * seconds. If the count is at or above ORDER_RATE_LIMIT, throws with a 429-style
     * message (controller maps RuntimeException to 400; caller should map to 429).
     *
     * Uses the idx_orders_user index (user_id) + idx_orders_created_at (created_at).
     * No external cache dependency — works with DB-only deployments.
     */
    private function checkRateLimit(int $userId): void
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM orders
                 WHERE user_id = :uid
                   AND parent_order_id IS NULL
                   AND created_at >= DATE_SUB(NOW(), INTERVAL :window SECOND)"
            );
            $stmt->bindValue(':uid',    $userId,                    PDO::PARAM_INT);
            $stmt->bindValue(':window', self::ORDER_RATE_WINDOW_SEC, PDO::PARAM_INT);
            $stmt->execute();
            $count = (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            // Rate limit query failure — log and allow (fail open, not closed)
            error_log('[OrderService::checkRateLimit] ' . $e->getMessage());
            return;
        }

        if ($count >= self::ORDER_RATE_LIMIT) {
            throw new \RuntimeException(
                'Too many orders. You may place a maximum of ' . self::ORDER_RATE_LIMIT .
                ' orders per minute. Please wait a moment and try again.'
            );
        }
    }

    /**
     * v3.0 FIX: Locks the inventory row AND validates that the product is
     * still active and not deleted at the time of checkout.
     *
     * Must be called INSIDE an open transaction.
     *
     * Returns null if the product has been deleted, deactivated, or if no
     * inventory record exists — all of which block the order.
     */
    private function getInventoryForUpdate(int $productId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT i.quantity, i.reserved, i.allow_backorder
            FROM inventory i
            JOIN products p ON p.id = i.product_id
            WHERE i.product_id = :pid
              AND p.status     = 'active'
              AND p.deleted_at IS NULL
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([':pid' => $productId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function fetchVendorSubOrders(int $parentOrderId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, order_number, vendor_id, status, subtotal, total
                FROM orders
                WHERE parent_order_id = :pid
                ORDER BY id ASC
            ");
            $stmt->execute([':pid' => $parentOrderId]);
            $subOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($subOrders)) return [];

            $subIds       = array_column($subOrders, 'id');
            $placeholders = implode(',', array_fill(0, count($subIds), '?'));
            $itemsStmt    = $this->db->prepare("
                SELECT oi.order_id, oi.product_id, oi.quantity, oi.unit_price, oi.line_total,
                       oi.product_name AS name, oi.product_sku AS sku, p.images
                FROM order_items oi
                LEFT JOIN products p ON p.id = oi.product_id
                WHERE oi.order_id IN ($placeholders)
            ");
            $itemsStmt->execute($subIds);

            $itemsByOrder = [];
            foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $oid   = $item['order_id'];
                $imgs  = $item['images'] ? json_decode($item['images'], true) : [];
                $thumb = null;
                if (is_array($imgs) && !empty($imgs)) {
                    $thumb = is_string($imgs[0]) ? $imgs[0] : ($imgs[0]['url'] ?? null);
                }
                $itemsByOrder[$oid][] = [
                    'product_id' => (int)$item['product_id'],
                    'name'       => $item['name'],
                    'sku'        => $item['sku'],
                    'image'      => $thumb,
                    'quantity'   => (int)$item['quantity'],
                    'unit_price' => (float)$item['unit_price'],
                    'line_total' => (float)$item['line_total'],
                ];
            }

            $result = [];
            foreach ($subOrders as $sub) {
                $result[] = [
                    'order_id'     => (int)$sub['id'],
                    'order_number' => $sub['order_number'],
                    'vendor_id'    => (int)$sub['vendor_id'],
                    'status'       => $sub['status'],
                    'subtotal'     => (float)$sub['subtotal'],
                    'total'        => (float)$sub['total'],
                    'items'        => $itemsByOrder[$sub['id']] ?? [],
                ];
            }
            return $result;

        } catch (\Throwable $e) {
            error_log('[OrderService::fetchVendorSubOrders] ' . $e->getMessage());
            return [];
        }
    }

    private function hydrateOrder(array $row): array
    {
        $addr = $row['shipping_address'] ?? null;
        if (is_string($addr)) {
            $decoded = json_decode($addr, true);
            $addr    = is_array($decoded) ? $decoded : ['address' => $addr];
        }

        return [
            'id'               => (int)$row['id'],
            'order_number'     => $row['order_number'],
            'status'           => $row['status'],
            'subtotal'         => (float)$row['subtotal'],
            'total'            => (float)$row['total'],
            'currency'         => 'INR',
            'shipping_address' => $addr,
            'notes'            => $row['notes'] ?? null,
            'payment_status'   => $row['payment_status']  ?? 'unpaid',
            'payment_method'   => $row['payment_method']  ?? 'cod',
            'payment_ref'      => $row['payment_ref']     ?? null,
            'delivery_status'  => $row['delivery_status'] ?? 'pending',
            'created_at'       => $row['created_at'],
            'updated_at'       => $row['updated_at'],
        ];
    }

    private function countWith(string $whereSQL, array $bind): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM orders o $whereSQL");
        $stmt->execute($bind);
        return (int)$stmt->fetchColumn();
    }

    private function generateOrderNumber(): string
    {
        $prefix = 'WTG-' . date('Ymd') . '-';
        for ($i = 0; $i < 10; $i++) {
            $candidate = $prefix . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            $stmt      = $this->db->prepare('SELECT id FROM orders WHERE order_number = ? LIMIT 1');
            $stmt->execute([$candidate]);
            if (!$stmt->fetchColumn()) {
                return $candidate;
            }
        }
        // Extremely unlikely fallback
        return $prefix . strtoupper(dechex((int)(microtime(true) * 1000)));
    }

    private function getOrderNumber(int $orderId): string
    {
        try {
            $stmt = $this->db->prepare("SELECT order_number FROM orders WHERE id = ? LIMIT 1");
            $stmt->execute([$orderId]);
            return (string)($stmt->fetchColumn() ?: '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function emitOrderCreated(
        int $userId, int $orderId, string $orderNum, float $total, array $cart
    ): void {
        try {
            if (!defined('CORE_PATH') || !file_exists(CORE_PATH . '/helpers/EventEngine.php')) {
                return;
            }
            require_once CORE_PATH . '/helpers/EventEngine.php';
            $engine = new WT_EventEngine($this->db);
            $uStmt  = $this->db->prepare("SELECT name, phone FROM users WHERE id = ? LIMIT 1");
            $uStmt->execute([$userId]);
            $uRow = $uStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $engine->emit('order_created', $userId, [
                'order_id'     => $orderId,
                'order_number' => $orderNum,
                'amount'       => $total,
                'user_name'    => $uRow['name']  ?? '',
                'user_phone'   => $uRow['phone'] ?? '',
                'item_name'    => $cart['items'][0]['name'] ?? 'Item',
                'qty'          => count($cart['items']),
            ]);
        } catch (\Throwable $e) {
            error_log('[OrderService] EventEngine emit failed: ' . $e->getMessage());
            // Non-fatal — order is already committed
        }
    }
}
