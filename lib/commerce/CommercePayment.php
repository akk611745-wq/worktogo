<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — CommercePayment  (S5)
//  commerce-system-blueprint §P. A THIN commerce-scoped seam over the existing
//  gateway-agnostic PaymentService / PaymentGatewayManager (which already give
//  multi-gateway routing + primary→secondary FAILOVER + an idempotent webhook).
//  This adds only: the configurable enabled-methods list (online primary, COD
//  fallback) and small helpers the /api/shop adapter calls. It names NO concrete
//  gateway — plugging a 2nd provider (Razorpay/PhonePe) is config-only.
//
//  Online path reuses PaymentService::createOnlinePaymentOrder(..., 'commerce');
//  the webhook (api/payment/webhook.php → processWebhookEvent) remains the sole
//  authority that marks an order paid. OrderService stays untouched.
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/../PaymentService.php';

class CommercePayment
{
    /** Enabled methods in priority order (app_settings.commerce_payment_methods). */
    public static function enabledMethods(PDO $db): array
    {
        $default = ['online', 'cod'];
        try {
            $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key='commerce_payment_methods' LIMIT 1");
            $s->execute();
            $v = $s->fetchColumn();
            if ($v !== false && $v !== '') {
                $list = array_values(array_filter(array_map('trim', explode(',', strtolower((string)$v)))));
                if ($list) return $list;
            }
        } catch (\Throwable $e) { /* defaults */ }
        return $default;
    }

    public static function isAllowed(PDO $db, string $method): bool
    {
        return in_array(strtolower(trim($method)), self::enabledMethods($db), true);
    }

    public static function defaultMethod(PDO $db): string
    {
        $m = self::enabledMethods($db);
        return $m[0] ?? 'cod';
    }

    /**
     * Start an online payment for a commerce order through the gateway-agnostic
     * layer (failover built in). Returns PaymentService's result (payment_session_id,
     * gateway, failover, …). $gateways is injectable for tests; production passes
     * null so the real manager (Cashfree primary) is used.
     */
    public static function initOnline(PDO $db, int $orderId, array $user, ?PaymentGatewayManager $gateways = null): array
    {
        $ps = new PaymentService($db, $gateways);
        $t = $db->prepare("SELECT total FROM orders WHERE id=? LIMIT 1");
        $t->execute([$orderId]);
        $total = (float)($t->fetchColumn() ?: 0);
        return $ps->createOnlinePaymentOrder($orderId, $total, $user, 'commerce');
    }

    /** Confirm a COD order (no gateway). */
    public static function confirmCod(PDO $db, int $orderId): array
    {
        return (new PaymentService($db))->confirmCODOrder($orderId);
    }

    // ── Advance / partial payment (P4) ──────────────────────────────────────
    //  Custom-tint can't be returned, so an ADVANCE is captured online before
    //  the order submits; the balance is collected COD at the door. The webhook
    //  reads the advance row (below) to mark the order `partially_paid`. Bookings
    //  never create an advance row, so their exact-amount path is untouched.

    /** Required advance % for an order (app_settings.commerce_advance_default_pct; default 50). */
    public static function advancePct(PDO $db): float
    {
        try {
            $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key='commerce_advance_default_pct' LIMIT 1");
            $s->execute();
            $v = $s->fetchColumn();
            if ($v !== false && is_numeric($v)) return max(0.0, min(100.0, (float)$v));
        } catch (\Throwable $e) { /* default */ }
        return 50.0;
    }

    /** Record (or refresh) the advance intent for an order. Returns the amounts. */
    public static function recordAdvanceIntent(PDO $db, int $orderId, ?float $pct = null): array
    {
        $t = $db->prepare("SELECT total FROM orders WHERE id=? LIMIT 1");
        $t->execute([$orderId]);
        $total = round((float)($t->fetchColumn() ?: 0), 2);
        if ($total <= 0) return ['ok' => false, 'reason' => 'order_total_unknown'];

        $pct = $pct ?? self::advancePct($db);
        $advance = round($total * $pct / 100, 2);
        if ($advance <= 0 || $advance > $total) $advance = $total; // misconfig → full upfront
        $balance = round($total - $advance, 2);

        $db->prepare(
            "INSERT INTO commerce_order_payments (order_id, kind, expected_amount, captured_amount, balance_amount, status, created_at, updated_at)
             VALUES (?, 'advance', ?, 0, ?, 'pending', NOW(), NOW())
             ON DUPLICATE KEY UPDATE expected_amount=VALUES(expected_amount), balance_amount=VALUES(balance_amount), captured_amount=0, status='pending', updated_at=NOW()"
        )->execute([$orderId, $advance, $balance]);

        return ['ok' => true, 'advance' => $advance, 'balance' => $balance, 'total' => $total, 'pct' => $pct];
    }

    /**
     * Start an ADVANCE online payment (part-capture) through the gateway-agnostic
     * layer. Balance is collected COD at delivery. $gateways injectable for tests.
     */
    public static function initAdvance(PDO $db, int $orderId, array $user, ?float $pct = null, ?PaymentGatewayManager $gateways = null): array
    {
        $intent = self::recordAdvanceIntent($db, $orderId, $pct);
        if (empty($intent['ok'])) return $intent;
        $ps = new PaymentService($db, $gateways);
        $pay = $ps->createOnlinePaymentOrder($orderId, (float)$intent['advance'], $user, 'commerce');
        return ['ok' => true] + $intent + ['payment' => $pay];
    }

    /** The advance record for an order (null if none). */
    public static function advanceStatus(PDO $db, int $orderId): ?array
    {
        try {
            $s = $db->prepare("SELECT kind, expected_amount, captured_amount, balance_amount, status, gateway_txn_id FROM commerce_order_payments WHERE order_id=? AND kind='advance' LIMIT 1");
            $s->execute([$orderId]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (\Throwable $e) { return null; }
    }

    /** Balance still due at the door (0 if no advance or not yet captured). */
    public static function balanceDue(PDO $db, int $orderId): float
    {
        $a = self::advanceStatus($db, $orderId);
        if (!$a) return 0.0;
        return $a['status'] === 'captured' ? round((float)$a['balance_amount'], 2) : 0.0;
    }

    /**
     * What the RIDER collects at the door — computed server-side (never in JS):
     *   prepaid → 0 · COD → the handoff cod_amount (fallback order total) · advance
     *   → the tracked balance. The prepaid/COD contract is the one CommerceDelivery
     *   ::enrichHandoff wrote onto the `deliveries` row (is_prepaid / cod_amount);
     *   the order's payment_status/total are the fallback.
     * @return array{type:string, amount:float}
     */
    public static function collectAtDoor(PDO $db, int $orderId): array
    {
        // 1) A captured ADVANCE wins — the balance is collected at the door.
        $adv = self::advanceStatus($db, $orderId);
        if ($adv && $adv['status'] === 'captured') return ['type' => 'advance', 'amount' => round((float)$adv['balance_amount'], 2)];

        // 2) The delivery handoff contract (prepaid vs COD at the door).
        $d = $db->prepare("SELECT is_prepaid, cod_amount FROM deliveries WHERE order_id=? ORDER BY id DESC LIMIT 1");
        $d->execute([$orderId]);
        $h = $d->fetch(PDO::FETCH_ASSOC) ?: null;

        // 3) Order payment state + total as the fallback.
        $s = $db->prepare("SELECT payment_method, payment_status, total FROM orders WHERE id=? LIMIT 1");
        $s->execute([$orderId]);
        $o = $s->fetch(PDO::FETCH_ASSOC);
        if (!$o) return ['type' => 'unknown', 'amount' => 0.0];

        if ($o['payment_status'] === 'paid' || ($h && (int)$h['is_prepaid'] === 1)) {
            return ['type' => 'prepaid', 'amount' => 0.0];
        }
        if (strtolower((string)$o['payment_method']) === 'cod' || $o['payment_status'] === 'unpaid') {
            $amt = ($h && $h['cod_amount'] !== null && (float)$h['cod_amount'] > 0) ? (float)$h['cod_amount'] : (float)$o['total'];
            return ['type' => 'cod', 'amount' => round($amt, 2)];
        }
        return ['type' => 'prepaid', 'amount' => 0.0];
    }

    /**
     * Record the cash the rider says they collected; flag a mismatch vs expected
     * (logged now; the PB9 ops queue surfaces it). Best-effort audit row.
     * @return array{ok:bool, expected:float, collected:float, mismatch:bool}
     */
    public static function recordCashCollected(PDO $db, int $orderId, int $deliveryId, int $riderId, float $collected): array
    {
        $exp = self::collectAtDoor($db, $orderId)['amount'];
        $mismatch = abs(round($collected, 2) - round($exp, 2)) > 0.01;
        try {
            $db->prepare("INSERT INTO commerce_cash_collections (order_id, delivery_id, rider_id, expected, collected, mismatch, created_at) VALUES (?,?,?,?,?,?,NOW())")
               ->execute([$orderId, $deliveryId, $riderId, round($exp, 2), round($collected, 2), $mismatch ? 1 : 0]);
        } catch (\Throwable $e) { error_log('[recordCashCollected] ' . $e->getMessage()); }
        return ['ok' => true, 'expected' => round($exp, 2), 'collected' => round($collected, 2), 'mismatch' => $mismatch];
    }
}
