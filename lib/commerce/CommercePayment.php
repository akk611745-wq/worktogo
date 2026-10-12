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
}
