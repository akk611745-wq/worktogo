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
}
