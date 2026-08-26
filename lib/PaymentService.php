<?php
/**
 * PaymentService.php
 * Core payment business logic for WorkToGo
 * 
 * Handles:
 *   - Creating Cashfree payment orders
 *   - COD order confirmation
 *   - Webhook processing (idempotent)
 *   - DB updates for payment status
 */

require_once __DIR__ . '/../config/payment.config.php';
require_once __DIR__ . '/CashfreeClient.php';
require_once __DIR__ . '/payment/PaymentGatewayManager.php';

class PaymentService
{
    private PDO $db;
    private PaymentGatewayManager $gateways;

    public function __construct(PDO $db, ?PaymentGatewayManager $gateways = null)
    {
        $this->db = $db;
        // Route all gateway I/O through the abstraction layer. Adding/swapping a
        // gateway (Cashfree, Razorpay, …) or enabling failover is config-only;
        // this orchestrator never names a concrete gateway.
        $this->gateways = $gateways ?? new PaymentGatewayManager($db);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // STEP 1 + 2 — Create Cashfree Payment Order
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Creates a Cashfree payment order for an existing system order.
     *
     * @param int    $orderId    Internal order ID
     * @param float  $amount     Order amount in INR (must match DB — server-side only)
     * @param array  $user       ['id', 'name', 'email', 'phone']
     * @param string $engine     Payment engine context ('commerce'|'service'|'video')
     *                           — selects the primary/secondary gateway routing.
     * @return array ['success', 'gateway', 'payment_session_id', 'order_token',
     *                'cashfree_order_id', 'cashfree_env', 'failover', 'error']
     */
    public function createOnlinePaymentOrder(int $orderId, float $amount, array $user, string $engine = 'commerce'): array
    {
        // ── Gateway availability guard — 503 if this engine has NO usable gateway
        //    (primary or secondary). Replaces the old Cashfree-only F15 guard;
        //    engine-scoped so one engine's outage never blocks another. ─────────
        $route = $this->gateways->routeFor($engine);
        $haveGateway = $this->gateways->isUsable($route['primary'])
            || ($route['secondary'] !== null && $this->gateways->isUsable($route['secondary']));
        if (!$haveGateway) {
            http_response_code(503);
            return ['success' => false, 'error' => 'Payment gateway not configured. Contact support.'];
        }

        // ── 1. Validate order exists and is in correct state ─────────────────
        $order = $this->fetchOrder($orderId);
        if (!$order) {
            return $this->fail("Order #$orderId not found.");
        }

        if ($order['payment_status'] === 'paid') {
            return $this->fail("Order #$orderId is already paid.");
        }

        // ── 2. Server-side amount verification (NEVER trust frontend amount) ──
        $dbAmount = round((float)$order['total'], 2);
        if (round($amount, 2) !== $dbAmount) {
            error_log("[PaymentService] Amount mismatch on order $orderId: sent=$amount db=$dbAmount");
            return $this->fail("Amount mismatch. Payment rejected.");
        }

        // ── 3. Set payment method to 'online' ─────────────────────────────────
        $this->updateOrderPaymentMeta($orderId, [
            'payment_method' => 'online',
            'payment_status' => 'unpaid',
        ]);

        // ── 4. Build a gateway-NEUTRAL order reference + normalised request ───
        //    The reference (WTG_{id}_{ts}) is ours and collision-safe against
        //    booking refs (WTG_B{id}_{ts}); it becomes the webhook correlation key.
        $orderRef = 'WTG_' . $orderId . '_' . time();

        $request = [
            'order_ref'  => $orderRef,
            'amount'     => $dbAmount,
            'currency'   => 'INR',
            'customer'   => [
                'id'    => (string)$user['id'],
                'name'  => (string)($user['name']  ?? 'Customer'),
                'email' => (string)($user['email'] ?? ''),
                'phone' => (string)($user['phone'] ?? ''),
            ],
            'return_url' => PAYMENT_RETURN_URL . '?order_id=' . $orderId,
            'notify_url' => PAYMENT_WEBHOOK_URL,
            'tags'       => [
                'internal_order_id' => (string)$orderId,
                'reference_type'    => 'order',
                'platform'          => 'worktogo',
            ],
        ];

        // ── 5. Route through the abstraction layer (primary → secondary failover)
        $result = $this->gateways->createOrderWithFailover($engine, $request);

        if (empty($result['success'])) {
            return $this->fail('Payment gateway error: ' . ($result['error'] ?? 'unknown'));
        }

        $gateway = (string)($result['gateway'] ?? $route['primary']);

        // ── 6. Persist the correlation reference + WHICH gateway handled it ───
        //    payment_gateway lets the webhook verify/settle with the correct
        //    gateway. Written defensively (skipped if the column is absent).
        $this->updateOrderPaymentMeta($orderId, [
            'payment_id'      => $orderRef,
            'payment_gateway' => $gateway,
        ]);

        return [
            'success'            => true,
            'gateway'            => $gateway,
            'payment_gateway'    => $gateway,
            'failover'           => (bool)($result['failover'] ?? false),
            'gateway_order_id'   => $result['gateway_order_id'] ?? $orderRef,
            'payment_session_id' => $result['payment_session_id'] ?? null,
            'order_token'        => $result['order_token'] ?? null,
            'mode'               => $result['mode'] ?? null,
            // ── Back-compat keys for existing Cashfree-era consumers ──────────
            'cashfree_order_id'  => ($gateway === 'cashfree') ? $orderRef : null,
            'cashfree_env'       => ($gateway === 'cashfree')
                ? ($result['mode'] ?? strtolower((string)(defined('CASHFREE_ENV') ? CASHFREE_ENV : 'test')))
                : null,
            'error'              => null,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // STEP 4 — COD Order Confirmation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Confirms an order as Cash on Delivery.
     * Auto-confirms without external gateway call.
     *
     * @param int $orderId
     * @return array ['success', 'error']
     */
    public function confirmCODOrder(int $orderId): array
    {
        $order = $this->fetchOrder($orderId);
        if (!$order) {
            return $this->fail("Order #$orderId not found.");
        }

        if ($order['payment_status'] === 'paid') {
            return $this->fail("Order #$orderId is already marked paid.");
        }

        $updated = $this->updateOrderPaymentMeta($orderId, [
            'payment_method' => 'cod',
            'payment_status' => 'unpaid',   // COD is unpaid until delivery
            'status'         => 'confirmed', // But order is confirmed immediately
        ]);

        if (!$updated) {
            return $this->fail("Failed to confirm COD order.");
        }

        return ['success' => true, 'error' => null];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // STEP 3 — Process Webhook (Idempotent)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Back-compat entry point: process a raw Cashfree webhook payload.
     * Kept so any legacy caller keeps working; new callers should verify + parse
     * with the correct gateway adapter and call processWebhookEvent().
     *
     * @param array $payload  Decoded Cashfree webhook JSON body
     * @return array ['success', 'message', 'error']
     */
    public function processWebhook(array $payload): array
    {
        require_once __DIR__ . '/payment/CashfreeAdapter.php';
        $adapter = new CashfreeAdapter();
        // Signature verification is the endpoint's responsibility (needs the raw
        // body); here we only normalise an already-trusted Cashfree payload.
        $event = $adapter->parseWebhook('', $payload);
        return $this->processWebhookEvent($event);
    }

    /**
     * Processes a VERIFIED, NORMALISED webhook event from ANY gateway.
     * Idempotent and gateway-agnostic. The calling endpoint must have already
     * verified the signature with the correct gateway adapter.
     *
     * @param array $event Normalised event (see PaymentGateway::parseWebhook):
     *                     gateway, order_ref, gateway_txn_id, amount,
     *                     internal_status ('paid'|'failed'|'unpaid'), event_type.
     * @return array ['success', 'message', 'error']
     */
    public function processWebhookEvent(array $event): array
    {
        $orderRef     = $event['order_ref'] ?? null;
        $eventGateway = strtolower((string)($event['gateway'] ?? ''));
        $cfAmount     = $event['amount'] ?? null;
        $txnId        = $event['gateway_txn_id'] ?? null;
        $newStatus    = (string)($event['internal_status'] ?? 'unpaid');
        if (!in_array($newStatus, ['paid', 'failed', 'unpaid'], true)) {
            $newStatus = 'unpaid';
        }

        if (!$orderRef) {
            error_log("[PaymentService][Webhook] Missing order_ref in event.");
            return $this->fail("Invalid webhook event.");
        }

        try {
            $this->db->beginTransaction();

            // ── Find internal payment target by exact order reference ─────────────
            $target = $this->fetchPaymentTargetForUpdate((string)$orderRef);

            if (!$target) {
                $this->db->rollBack();
                error_log("[PaymentService][Webhook] No order found for order_ref=$orderRef");
                return $this->fail("Order not found for reference: $orderRef");
            }

            $internalOrderId = (int)$target['id'];
            $referenceType   = (string)$target['reference_type'];
            $storedGateway   = strtolower((string)($target['payment_gateway'] ?? ''));

            // ── Defense: a webhook from gateway X must never settle an order that
            //    was created on gateway Y. Legacy rows (no stored gateway) are
            //    accepted and backfilled below. ─────────────────────────────────
            if ($storedGateway !== '' && $eventGateway !== '' && $storedGateway !== $eventGateway) {
                $this->db->rollBack();
                error_log("[PaymentService][Webhook] Gateway mismatch for $orderRef: stored=$storedGateway event=$eventGateway");
                return $this->fail("Gateway mismatch for reference: $orderRef");
            }
            $effectiveGateway = $eventGateway ?: ($storedGateway ?: 'cashfree');

            // ── Idempotency: skip if already processed ────────────────────────────
            if ($target['payment_status'] === 'paid') {
                $this->ensureSuccessfulTransaction($target, $cfAmount, $txnId, $effectiveGateway);
                if ($referenceType === 'booking') {
                    $this->syncPaidBookingPropagation((int)$target['id']);
                }
                $this->db->rollBack();
                return ['success' => true, 'message' => 'Already processed. Skipped.', 'error' => null];
            }

            // ── Server-side amount verification ───────────────────────────────────
            $dbAmount = round((float)$target['total'], 2);
            if ($cfAmount !== null && round((float)$cfAmount, 2) !== $dbAmount) {
                error_log("[PaymentService][Webhook] Amount mismatch order $internalOrderId: webhook=$cfAmount db=$dbAmount");
                $this->updatePaymentTargetMeta($referenceType, $internalOrderId, ['payment_status' => 'failed']);
                $this->db->commit();
                return $this->fail("Amount mismatch in webhook. Flagged for review.");
            }

            $orderStatus = ($newStatus === 'paid') ? 'confirmed' : null;

            $meta = [
                'payment_status' => $newStatus,
            ];
            if ($txnId) {
                $meta['payment_id'] = $orderRef . '|txn:' . $txnId;
            }
            if ($orderStatus) {
                $meta['status'] = $orderStatus;
            }
            // Backfill the gateway on rows created before the column existed
            // (e.g. the inline service-engine path). Written defensively.
            if ($storedGateway === '') {
                $meta['payment_gateway'] = $effectiveGateway;
            }

            $updated = $this->updatePaymentTargetMeta($referenceType, $internalOrderId, $meta);

            if (!$updated) {
                $this->db->rollBack();
                return $this->fail("DB update failed for order $internalOrderId.");
            }

            if ($newStatus === 'paid') {
                $this->ensureSuccessfulTransaction($target, $cfAmount, $txnId, $effectiveGateway);
                if ($referenceType === 'booking') {
                    $this->syncPaidBookingPropagation($internalOrderId);
                }
            }

            $this->db->commit();

            // ── F12: Auto-dispatch to SwiftDeliver after successful payment ────────
            if ($newStatus === 'paid' && $referenceType === 'order') {
                $swiftUrl = rtrim((string)(getenv('SWIFTDELIVER_URL') ?: ''), '/');
                $swiftSecret = (string)(getenv('SWIFTDELIVER_SECRET') ?: '');

                // Skip if URL not configured or looks like a placeholder
                if (
                    empty($swiftUrl) ||
                    strpos($swiftUrl, 'placeholder') !== false ||
                    strpos($swiftUrl, 'your-railway') !== false ||
                    strpos($swiftUrl, 'your_') !== false
                ) {
                    error_log("[PaymentService][Dispatch] SwiftDeliver not configured, skipping dispatch for order $internalOrderId.");
                } else {
                    // Fetch delivery_id for this order
                    $deliveryStmt = $this->db->prepare(
                        "SELECT id FROM deliveries WHERE order_id = :order_id AND status != 'delivered' LIMIT 1"
                    );
                    $deliveryStmt->execute([':order_id' => $internalOrderId]);
                    $deliveryRow = $deliveryStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$deliveryRow) {
                        error_log("[PaymentService][Dispatch] No delivery row found for order $internalOrderId, skipping dispatch.");
                    } else {
                        $deliveryId = (int)$deliveryRow['id'];
                        $dispatchPayload = json_encode([
                            'order_id'    => $internalOrderId,
                            'delivery_id' => $deliveryId,
                        ]);

                        $ch = curl_init($swiftUrl . '/api/dispatch');
                        curl_setopt_array($ch, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POST           => true,
                            CURLOPT_POSTFIELDS     => $dispatchPayload,
                            CURLOPT_TIMEOUT        => 5,
                            CURLOPT_HTTPHEADER     => [
                                'Content-Type: application/json',
                                'X-SwiftDeliver-Secret: ' . $swiftSecret,
                            ],
                        ]);
                        $dispatchResponse = curl_exec($ch);
                        $dispatchCode     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);

                        if ($dispatchCode >= 200 && $dispatchCode < 300) {
                            error_log("[PaymentService][Dispatch] Order $internalOrderId dispatched to SwiftDeliver (delivery_id=$deliveryId).");
                        } else {
                            // Log but do NOT fail — order is already paid
                            error_log("[PaymentService][Dispatch] SwiftDeliver dispatch failed for order $internalOrderId. HTTP=$dispatchCode response=$dispatchResponse");
                        }
                    }
                }
            }
            // ── End F12 ───────────────────────────────────────────────────────────

            if ($newStatus === 'failed') {
                $this->db->prepare("INSERT INTO alerts (type, title, message, ref_type) VALUES ('payment_failure', 'Payment Failed', ?, 'none')")
                   ->execute(["Payment failed for " . ucfirst($referenceType) . " #$internalOrderId."]);
            }

            return [
                'success' => true,
                'message' => ucfirst($referenceType) . " $internalOrderId updated to status: $newStatus",
                'error'   => null,
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            error_log("[PaymentService][Webhook] Exception: " . $e->getMessage());
            return $this->fail("Database error during webhook processing.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DB Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function fetchOrder(int $orderId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, total, payment_status, payment_method
             FROM orders WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $orderId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function fetchOrderByReference(string $reference): ?array
    {
        // payment_id is stored as 'WTG_{id}_{ts}' so LIKE match on prefix
        $stmt = $this->db->prepare(
            'SELECT id, total, payment_status, payment_method
             FROM orders
             WHERE payment_id = :ref
                OR payment_id LIKE :ref_prefix
             LIMIT 1'
        );
        $stmt->execute([
            ':ref'        => $reference,
            ':ref_prefix' => $reference . '|txn:%',
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function fetchPaymentTargetForUpdate(string $reference): ?array
    {
        // Select payment_gateway defensively — the column may not exist on a DB
        // where the abstraction migration hasn't run (existing Cashfree-only setup).
        // The expression is derived from a schema check, never from user input.
        $orderGw   = $this->tableHasColumn('orders', 'payment_gateway')   ? 'payment_gateway' : 'NULL AS payment_gateway';
        $bookingGw = $this->tableHasColumn('bookings', 'payment_gateway') ? 'payment_gateway' : 'NULL AS payment_gateway';

        $orderStmt = $this->db->prepare(
            "SELECT id, user_id, total, payment_status, payment_method, $orderGw, :reference_type AS reference_type
             FROM orders
             WHERE payment_id = :ref
                OR payment_id LIKE :ref_prefix
             LIMIT 1 FOR UPDATE"
        );
        $orderStmt->execute([
            ':reference_type' => 'order',
            ':ref' => $reference,
            ':ref_prefix' => $reference . '|txn:%',
        ]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
        if ($order) return $order;

        $bookingStmt = $this->db->prepare(
            "SELECT id, user_id, total, payment_status, payment_method, $bookingGw, :reference_type AS reference_type
             FROM bookings
             WHERE payment_id = :ref
                OR payment_id LIKE :ref_prefix
             LIMIT 1 FOR UPDATE"
        );
        $bookingStmt->execute([
            ':reference_type' => 'booking',
            ':ref' => $reference,
            ':ref_prefix' => $reference . '|txn:%',
        ]);
        return $bookingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Updates payment-related columns on the orders table.
     * Only updates columns that exist in $meta.
     */
    private function updateOrderPaymentMeta(int $orderId, array $meta): bool
    {
        $allowed = [
            'payment_status',
            'payment_method',
            'payment_id',
            'payment_gateway',
            'status',
        ];

        $setClauses = [];
        $params     = [':id' => $orderId];

        foreach ($meta as $col => $val) {
            if (!in_array($col, $allowed, true)) continue;
            // Schema-defensive: skip the new column if the DB hasn't migrated yet.
            if ($col === 'payment_gateway' && !$this->tableHasColumn('orders', 'payment_gateway')) continue;
            $setClauses[] = "`$col` = :$col";
            $params[":$col"] = $val;
        }

        if (empty($setClauses)) return false;

        $sql  = 'UPDATE orders SET ' . implode(', ', $setClauses) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    private function updatePaymentTargetMeta(string $referenceType, int $referenceId, array $meta): bool
    {
        if ($referenceType === 'order') {
            return $this->updateOrderPaymentMeta($referenceId, $meta);
        }

        $allowed = [
            'payment_status',
            'payment_method',
            'payment_id',
            'payment_gateway',
            'status',
        ];

        $setClauses = [];
        $params = [':id' => $referenceId];

        foreach ($meta as $col => $val) {
            if (!in_array($col, $allowed, true)) continue;
            // Schema-defensive: skip the new column if the DB hasn't migrated yet.
            if ($col === 'payment_gateway' && !$this->tableHasColumn('bookings', 'payment_gateway')) continue;
            $setClauses[] = "`$col` = :$col";
            $params[":$col"] = $val;
        }

        if (empty($setClauses)) return false;

        $sql = 'UPDATE bookings SET ' . implode(', ', $setClauses) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    private function ensureSuccessfulTransaction(array $target, mixed $cfAmount, ?string $txnId, string $gateway = 'cashfree'): void
    {
        $txnCheck = $this->db->prepare(
            'SELECT id
             FROM transactions
             WHERE reference_id = :reference_id
               AND reference_type = :reference_type
               AND status = :status
             LIMIT 1
             FOR UPDATE'
        );
        $txnCheck->execute([
            ':reference_id'   => (int)$target['id'],
            ':reference_type' => (string)$target['reference_type'],
            ':status'         => 'success',
        ]);

        if ($txnCheck->fetch(PDO::FETCH_ASSOC)) {
            return;
        }

        $amount = $cfAmount !== null ? round((float)$cfAmount, 2) : round((float)$target['total'], 2);
        $txnInsert = $this->db->prepare(
            'INSERT INTO transactions
                (user_id, reference_id, reference_type, amount, status, gateway, gateway_ref, created_at)
             VALUES
                (:user_id, :reference_id, :reference_type, :amount, :status, :gateway, :gateway_ref, NOW())'
        );
        $txnInsert->execute([
            ':user_id'        => (int)$target['user_id'],
            ':reference_id'   => (int)$target['id'],
            ':reference_type' => (string)$target['reference_type'],
            ':amount'         => $amount,
            ':status'         => 'success',
            ':gateway'        => $gateway !== '' ? $gateway : 'cashfree',
            ':gateway_ref'    => $txnId,
        ]);
    }

    private function syncPaidBookingPropagation(int $bookingId): void
    {
        $bookingStmt = $this->db->prepare('SELECT id, vendor_id, status, notes FROM bookings WHERE id = :id LIMIT 1 FOR UPDATE');
        $bookingStmt->execute([':id' => $bookingId]);
        $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) {
            return;
        }

        $sets = ["status = CASE WHEN status IN ('cancelled','completed','in_progress') THEN status ELSE 'pending' END"];
        $bind = [':id' => $bookingId];
        if ($this->tableHasColumn('bookings', 'lifecycle_state')) {
            $sets[] = "lifecycle_state = 'inspection_paid'";
        }
        if ($this->tableHasColumn('bookings', 'assignment_state')) {
            $sets[] = "assignment_state = CASE WHEN vendor_id IS NULL THEN 'unassigned' ELSE 'assigned' END";
        }
        if ($this->tableHasColumn('bookings', 'vendor_response_status')) {
            $sets[] = "vendor_response_status = CASE WHEN vendor_id IS NULL THEN 'not_requested' ELSE 'awaiting_vendor_response' END";
        }
        if ($this->tableHasColumn('bookings', 'updated_at')) {
            $sets[] = 'updated_at = NOW()';
        }
        $this->db->prepare('UPDATE bookings SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($bind);

        $jobStmt = $this->db->prepare('SELECT id, status FROM jobs WHERE booking_id = :booking_id LIMIT 1 FOR UPDATE');
        $jobStmt->execute([':booking_id' => $bookingId]);
        $job = $jobStmt->fetch(PDO::FETCH_ASSOC);

        if ($job && in_array((string)$job['status'], ['open', 'pending'], true) && !empty($booking['vendor_id'])) {
            $this->db->prepare("UPDATE jobs SET status = 'assigned', vendor_id = :vendor_id, assignment_lock_time = NULL, updated_at = NOW() WHERE id = :id")
                ->execute([':vendor_id' => (int)$booking['vendor_id'], ':id' => (int)$job['id']]);
        }
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) return $cache[$key];
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        $cache[$key] = ((int)$stmt->fetchColumn()) > 0;
        return $cache[$key];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Utility
    // ─────────────────────────────────────────────────────────────────────────
    //
    // NOTE: gateway status mapping now lives in each gateway adapter
    // (e.g. CashfreeAdapter::mapStatus) — the single source of truth — so it is
    // no longer duplicated here. This closes the drift risk flagged in the audit.

    private function sanitizeString(string $input): string
    {
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }

    private function fail(string $message): array
    {
        return ['success' => false, 'error' => $message];
    }
}
