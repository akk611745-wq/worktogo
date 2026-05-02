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

class PaymentService
{
    private PDO    $db;
    private CashfreeClient $cashfree;

    public function __construct(PDO $db)
    {
        $this->db       = $db;
        $this->cashfree = new CashfreeClient();
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
     * @return array ['success', 'payment_session_id', 'order_token', 'cashfree_order_id', 'error']
     */
    public function createOnlinePaymentOrder(int $orderId, float $amount, array $user): array
    {
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

        // ── 4. Build Cashfree order payload ───────────────────────────────────
        $cfOrderId = 'WTG_' . $orderId . '_' . time(); // Unique Cashfree order ID

        $payload = [
            'order_id'       => $cfOrderId,
            'order_amount'   => $dbAmount,
            'order_currency' => 'INR',
            'customer_details' => [
                'customer_id'    => (string)$user['id'],
                'customer_name'  => $this->sanitizeString($user['name']),
                'customer_email' => filter_var($user['email'], FILTER_SANITIZE_EMAIL),
                'customer_phone' => preg_replace('/\D/', '', $user['phone']),
            ],
            'order_meta' => [
                'return_url'   => PAYMENT_RETURN_URL . '?order_id=' . $orderId,
                'notify_url'   => PAYMENT_WEBHOOK_URL,
            ],
            'order_tags' => [
                'internal_order_id' => (string)$orderId,
                'platform'          => 'worktogo',
            ],
        ];

        // ── 5. Call Cashfree API ───────────────────────────────────────────────
        $result = $this->cashfree->post('/orders', $payload);

        if (!$result['success']) {
            return $this->fail('Payment gateway error: ' . $result['error']);
        }

        $cfData = $result['data'];

        // ── 6. Store Cashfree order ID in DB for webhook correlation ──────────
        $this->updateOrderPaymentMeta($orderId, [
            'payment_id' => $cfOrderId,
        ]);

        return [
            'success'           => true,
            'payment_session_id' => $cfData['payment_session_id'] ?? null,
            'order_token'       => $cfData['order_token'] ?? null,
            'cashfree_order_id' => $cfOrderId,
            'cashfree_env'      => strtolower(CASHFREE_ENV),
            'error'             => null,
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
     * Processes a verified Cashfree webhook payload.
     * Idempotent: safe to call multiple times with the same event.
     *
     * @param array $payload  Decoded webhook JSON body
     * @return array ['success', 'message', 'error']
     */
    public function processWebhook(array $payload): array
    {
        // ── Extract fields from Cashfree webhook structure ────────────────────
        $cfOrderId     = $payload['data']['order']['order_id']     ?? null;
        $cfOrderStatus = $payload['data']['order']['order_status'] ?? null;
        $cfAmount      = $payload['data']['order']['order_amount'] ?? null;
        $paymentStatus = $payload['data']['payment']['payment_status'] ?? null;
        $txnId         = $payload['data']['payment']['cf_payment_id'] ?? null;

        if (!$cfOrderId) {
            error_log("[PaymentService][Webhook] Missing order_id in payload.");
            return $this->fail("Invalid webhook payload.");
        }

        try {
            $this->db->beginTransaction();

            // ── Find internal order by payment_id ───────────────────────
            $stmt = $this->db->prepare(
                'SELECT id, user_id, total, payment_status, payment_method
                 FROM orders
                 WHERE payment_id = :ref
                    OR payment_id LIKE :ref_prefix
                 LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([
                ':ref'        => $cfOrderId,
                ':ref_prefix' => $cfOrderId . '|txn:%',
            ]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                $this->db->rollBack();
                error_log("[PaymentService][Webhook] No order found for cf_order_id=$cfOrderId");
                return $this->fail("Order not found for reference: $cfOrderId");
            }

            $internalOrderId = (int)$order['id'];

            // ── Idempotency: skip if already processed ────────────────────────────
            if ($order['payment_status'] === 'paid') {
                $this->db->rollBack();
                return ['success' => true, 'message' => 'Already processed. Skipped.', 'error' => null];
            }

            // ── Server-side amount verification ───────────────────────────────────
            $dbAmount = round((float)$order['total'], 2);
            if ($cfAmount !== null && round((float)$cfAmount, 2) !== $dbAmount) {
                error_log("[PaymentService][Webhook] Amount mismatch order $internalOrderId: webhook=$cfAmount db=$dbAmount");
                $this->updateOrderPaymentMeta($internalOrderId, ['payment_status' => 'failed']);
                $this->db->commit();
                return $this->fail("Amount mismatch in webhook. Flagged for review.");
            }

            // ── Map Cashfree status to internal status ─────────────────────────────
            $newStatus   = $this->mapCashfreeStatus($cfOrderStatus, $paymentStatus);
            $orderStatus = ($newStatus === 'paid') ? 'confirmed' : null;

            $meta = [
                'payment_status' => $newStatus,
            ];
            if ($txnId) {
                $meta['payment_id'] = $cfOrderId . '|txn:' . $txnId;
            }
            if ($orderStatus) {
                $meta['status'] = $orderStatus;
            }

            $updated = $this->updateOrderPaymentMeta($internalOrderId, $meta);

            if (!$updated) {
                $this->db->rollBack();
                return $this->fail("DB update failed for order $internalOrderId.");
            }

            // ── Create transaction record once after successful online payment ────────
            // Cashfree can send the same webhook multiple times, so first check whether
            // a successful order transaction already exists for this order ID.
            if ($newStatus === 'paid') {
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
                    ':reference_id'   => $internalOrderId,
                    ':reference_type' => 'order',
                    ':status'         => 'success',
                ]);

                // If no transaction exists, insert exactly one successful Cashfree
                // transaction for this order. The schema stores the payment method in
                // the `gateway` column and requires `user_id`, so both are included.
                if (!$txnCheck->fetch(PDO::FETCH_ASSOC)) {
                    $txnInsert = $this->db->prepare(
                        'INSERT INTO transactions
                            (user_id, reference_id, reference_type, amount, status, gateway, gateway_ref, created_at)
                         VALUES
                            (:user_id, :reference_id, :reference_type, :amount, :status, :gateway, :gateway_ref, NOW())'
                    );
                    $txnInsert->execute([
                        ':user_id'        => (int)$order['user_id'],
                        ':reference_id'   => $internalOrderId,
                        ':reference_type' => 'order',
                        ':amount'         => round((float)$cfAmount, 2),
                        ':status'         => 'success',
                        ':gateway'        => 'cashfree',
                        ':gateway_ref'    => $txnId,
                    ]);
                }
            }

            $this->db->commit();
            
            if ($newStatus === 'failed') {
                $this->db->prepare("INSERT INTO alerts (type, title, message, ref_type) VALUES ('payment_failure', 'Payment Failed', ?, 'none')")
                   ->execute(["Payment failed for Order #$internalOrderId."]);
            }

            return [
                'success' => true,
                'message' => "Order $internalOrderId updated to status: $newStatus",
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
            'status',
        ];

        $setClauses = [];
        $params     = [':id' => $orderId];

        foreach ($meta as $col => $val) {
            if (!in_array($col, $allowed, true)) continue;
            $setClauses[] = "`$col` = :$col";
            $params[":$col"] = $val;
        }

        if (empty($setClauses)) return false;

        $sql  = 'UPDATE orders SET ' . implode(', ', $setClauses) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Utility
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Maps Cashfree order/payment status to internal status.
     */
    private function mapCashfreeStatus(?string $orderStatus, ?string $paymentStatus): string
    {
        $status = strtoupper((string)($paymentStatus ?: $orderStatus));

        return match ($status) {
            'SUCCESS', 'PAID'    => 'paid',
            'FAILED', 'FAILURE'  => 'failed',
            'USER_DROPPED'       => 'failed',
            'CANCELLED'          => 'failed',
            'FLAGGED'            => 'unpaid', // Needs manual review
            default              => 'unpaid',
        };
    }

    private function sanitizeString(string $input): string
    {
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }

    private function fail(string $message): array
    {
        return ['success' => false, 'error' => $message];
    }
}
