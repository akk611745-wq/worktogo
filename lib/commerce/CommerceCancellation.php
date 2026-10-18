<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — CommerceCancellation  (Paint PB16)
//  Pre-delivery cancellation with a stage→cost-bearer rule (all limits admin
//  config). Decision + money-record live here; the actual order cancel reuses
//  the never-touch OrderService::cancel (called by the shop adapter), and the
//  money reversal reuses LedgerEngine::processRefund on the order branch.
//
//  Stages (from deliveries + routing signals — no engine edit):
//    placed   : no seller has accepted yet            → customer cancels FREE.
//    accepted : accepted, rider hasn't picked up      → admin fee (flat + %).
//    picked   : rider picked up / delivered           → no cancel; return flow.
//  Seller cancel after accept → reroute (RoutingEngine) + a seller-score penalty.
//  Custom-tint advance is non-refundable once making has started.
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/RoutingEngine.php';
require_once __DIR__ . '/CommercePayment.php';
require_once __DIR__ . '/CommerceEventBus.php';

class CommerceCancellation
{
    private static function cfgNum(PDO $db, string $key, float $def): float
    {
        try {
            $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1");
            $s->execute([$key]); $v = $s->fetchColumn();
            if ($v !== false && is_numeric($v)) return (float)$v;
        } catch (\Throwable $e) {}
        return $def;
    }

    /** Lifecycle stage of a parent order: placed | accepted | picked. */
    public static function stage(PDO $db, int $orderId): string
    {
        try {
            $d = $db->prepare("SELECT 1 FROM deliveries WHERE order_id=? AND status IN ('picked','delivered') LIMIT 1");
            $d->execute([$orderId]);
            if ($d->fetchColumn()) return 'picked';
            $a = $db->prepare("SELECT 1 FROM commerce_routing_signals WHERE order_id=? AND event='accepted' LIMIT 1");
            $a->execute([$orderId]);
            if ($a->fetchColumn()) return 'accepted';
        } catch (\Throwable $e) {}
        return 'placed';
    }

    /** Is the captured advance still refundable? (false once making has started.) */
    public static function advanceRefundable(PDO $db, int $orderId): bool
    {
        $adv = CommercePayment::advanceStatus($db, $orderId);
        if (!$adv || $adv['status'] !== 'captured') return true; // no captured advance → nothing special
        try {
            $s = $db->prepare("SELECT 1 FROM commerce_routing_signals WHERE order_id=? AND event='making_started' LIMIT 1");
            $s->execute([$orderId]);
            return !$s->fetchColumn(); // making started → advance NOT refundable
        } catch (\Throwable $e) { return true; }
    }

    /**
     * Decision for a customer cancel (no side effects).
     * @return array{stage:string, cancellable:bool, requires_return:bool, fee:float, cost_bearer:string}
     */
    public static function quote(PDO $db, int $orderId): array
    {
        $stage = self::stage($db, $orderId);
        if ($stage === 'picked') return ['stage' => $stage, 'cancellable' => false, 'requires_return' => true, 'fee' => 0.0, 'cost_bearer' => 'none'];
        if ($stage === 'placed') return ['stage' => $stage, 'cancellable' => true, 'requires_return' => false, 'fee' => 0.0, 'cost_bearer' => 'none'];
        // accepted → admin fee (flat + % of total), borne by the customer
        $t = $db->prepare("SELECT total FROM orders WHERE id=? LIMIT 1"); $t->execute([$orderId]);
        $total = (float)($t->fetchColumn() ?: 0);
        $fee = round(self::cfgNum($db, 'commerce_cancel_fee_after_accept', 0) + $total * self::cfgNum($db, 'commerce_cancel_fee_pct', 0) / 100, 2);
        return ['stage' => $stage, 'cancellable' => true, 'requires_return' => $fee > 0 ? false : false, 'fee' => $fee, 'cost_bearer' => $fee > 0 ? 'customer' : 'none'];
    }

    /**
     * Settle a customer cancellation: record who bears the cost, and refund a
     * prepaid order (minus any fee; advance retained if making started). The
     * ORDER cancel itself is done by the caller via OrderService::cancel.
     * @return array{ok:bool, stage:string, fee:float, refund_amount:float, cost_bearer:string, prepaid:bool}
     */
    public static function settle(PDO $db, int $orderId, string $actor, ?array $quote, ?string $reason = null): array
    {
        $q = $quote ?: self::quote($db, $orderId);
        $o = $db->prepare("SELECT total, payment_status FROM orders WHERE id=? LIMIT 1");
        $o->execute([$orderId]); $ord = $o->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'payment_status' => 'unpaid'];
        $total = (float)$ord['total'];
        $prepaid = in_array($ord['payment_status'], ['paid', 'partially_paid'], true);

        $fee = (float)$q['fee'];
        $refund = 0.0;
        if ($prepaid) {
            // amount actually collected so far
            $adv = CommercePayment::advanceStatus($db, $orderId);
            $paid = ($adv && $adv['status'] === 'captured') ? (float)$adv['captured_amount'] : $total;
            $refund = max(0.0, round($paid - $fee, 2));
            // custom-tint advance non-refundable once making started
            if ($adv && $adv['status'] === 'captured' && !self::advanceRefundable($db, $orderId)) {
                $refund = max(0.0, round($refund - (float)$adv['captured_amount'], 2));
            }
            if ($refund > 0) {
                // Reverse the order-branch ledger (prepaid → back to source). Reused,
                // not reimplemented; lazy-required so every entry path has it.
                try {
                    if (!class_exists('Core\\Helpers\\LedgerEngine')) {
                        $le = __DIR__ . '/../../core/helpers/LedgerEngine.php';
                        if (is_file($le)) require_once $le;
                    }
                    if (class_exists('Core\\Helpers\\LedgerEngine')) \Core\Helpers\LedgerEngine::processRefund($orderId);
                } catch (\Throwable $e) { error_log('[cancel refund] ' . $e->getMessage()); }
            }
        }

        try {
            $db->prepare("INSERT INTO commerce_cancellations (order_id, stage, actor, cost_bearer, fee, refund_amount, reason, created_at) VALUES (?,?,?,?,?,?,?,NOW())")
               ->execute([$orderId, $q['stage'], $actor, $q['cost_bearer'], $fee, $refund, $reason !== null ? mb_substr($reason, 0, 240) : null]);
        } catch (\Throwable $e) { error_log('[cancel log] ' . $e->getMessage()); }

        try { CommerceEventBus::emit($db, 'order_cancelled', ['order_id' => $orderId, 'actor_type' => $actor, 'payload' => ['stage' => $q['stage'], 'fee' => $fee, 'refund' => $refund]]); } catch (\Throwable $e) {}

        return ['ok' => true, 'stage' => $q['stage'], 'fee' => $fee, 'refund_amount' => $refund, 'cost_bearer' => $q['cost_bearer'], 'prepaid' => $prepaid];
    }

    /**
     * Seller cancels a sub-order AFTER accepting → reroute to the next seller +
     * a seller-score penalty signal. The customer's order continues (not cancelled).
     */
    public static function sellerCancel(PDO $db, int $subOrderId, int $vendorId, string $reason = ''): array
    {
        $so = $db->prepare("SELECT id, parent_order_id, store_id, vendor_id FROM orders WHERE id=? LIMIT 1");
        $so->execute([$subOrderId]);
        $sub = $so->fetch(PDO::FETCH_ASSOC);
        if (!$sub) return ['ok' => false, 'reason' => 'not_found'];
        if ((int)$sub['vendor_id'] !== $vendorId) return ['ok' => false, 'reason' => 'not_yours'];
        $parent = $sub['parent_order_id'] !== null ? (int)$sub['parent_order_id'] : $subOrderId;
        // penalty signal (lowers the seller's fulfilment score)
        RoutingEngine::recordSignal($db, $parent, $subOrderId, $sub['store_id'] !== null ? (int)$sub['store_id'] : null, $vendorId, 'seller_cancelled', null, ['reason' => mb_substr($reason, 0, 120)]);
        try { CommerceEventBus::emit($db, 'seller_cancelled', ['order_id' => $parent, 'sub_order_id' => $subOrderId, 'actor_type' => 'seller', 'actor_id' => $vendorId, 'payload' => ['reason' => $reason]]); } catch (\Throwable $e) {}
        $reroute = RoutingEngine::rerouteOnFailure($db, $subOrderId);
        try {
            $db->prepare("INSERT INTO commerce_cancellations (order_id, sub_order_id, stage, actor, cost_bearer, fee, refund_amount, reason, created_at) VALUES (?,?, 'accepted','seller','seller',0,0,?,NOW())")
               ->execute([$parent, $subOrderId, mb_substr($reason, 0, 240)]);
        } catch (\Throwable $e) {}
        return ['ok' => true, 'reroute' => $reroute, 'penalised' => true];
    }

    /**
     * Seller fulfilment score 0..1 (accepted & delivered vs rejected/timeout/
     * stockout/seller_cancelled). Cancellations drag it down. No history → 1.0.
     */
    public static function sellerScore(PDO $db, int $vendorId): float
    {
        try {
            $s = $db->prepare("SELECT
                    SUM(event IN ('accepted')) AS good,
                    SUM(event IN ('accepted','rejected','timeout','stockout','seller_cancelled')) AS total,
                    SUM(event='seller_cancelled') AS cancels
                  FROM commerce_routing_signals WHERE vendor_id=?");
            $s->execute([$vendorId]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            $total = (int)($r['total'] ?? 0);
            if ($total === 0) return 1.0;
            $good = (int)($r['good'] ?? 0); $cancels = (int)($r['cancels'] ?? 0);
            $penalty = self::cfgNum($db, 'commerce_cancel_seller_penalty', 1);
            // each cancel counts as the configured weight against them, on top of the denominator.
            $score = ($good - $penalty * $cancels) / ($total + $penalty * $cancels);
            return round(max(0.0, min(1.0, $score)), 4);
        } catch (\Throwable $e) { return 1.0; }
    }
}
