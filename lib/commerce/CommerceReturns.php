<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — CommerceReturns  (S9)
//  commerce-system-blueprint §8. Source-aware return/refund:
//    request → approve → received (rider returns it to the SOURCE seller) → refund.
//  Refund reuses LedgerEngine::processRefund (reverses vendor/platform/rider ledger
//  + triggers the gateway refund for online). COD has no prepaid, so its refund is
//  recorded as a store-credit / cash obligation (the COD-refund SOP) — ledger is
//  still reversed. Per-category RISK CLASS (returnable_if_sealed / no_return /
//  perishable) + return WINDOW + who-bears-cost are all DB-config.
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/../../core/helpers/LedgerEngine.php';
require_once __DIR__ . '/CommerceEventBus.php';
require_once __DIR__ . '/PaintUnit.php'; // P5: custom-tint = non-returnable (attribute-driven)

class CommerceReturns
{
    /** Custom-tinted (made-to-order) items are non-returnable regardless of category (P5). */
    private static function hasCustomTintItem(PDO $db, int $orderId, ?int $orderItemId): bool
    {
        try {
            if ($orderItemId) {
                $q = $db->prepare("SELECT p.attributes FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.id=? LIMIT 1");
                $q->execute([$orderItemId]);
                $rows = [$q->fetchColumn()];
            } else {
                $q = $db->prepare("SELECT p.attributes FROM order_items oi JOIN products p ON p.id=oi.product_id JOIN orders o ON o.id=oi.order_id WHERE o.parent_order_id=? OR oi.order_id=?");
                $q->execute([$orderId, $orderId]);
                $rows = $q->fetchAll(PDO::FETCH_COLUMN);
            }
            foreach ($rows as $a) {
                if (!$a) continue;
                $attrs = json_decode((string)$a, true);
                if (is_array($attrs) && PaintUnit::isCustomTint($attrs)) return true;
            }
        } catch (\Throwable $e) { /* default: not custom-tint */ }
        return false;
    }

    private static function setting(PDO $db, string $key, $def)
    {
        try { $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1"); $s->execute([$key]); $v = $s->fetchColumn(); return $v === false ? $def : $v; }
        catch (\Throwable $e) { return $def; }
    }
    public static function windowHours(PDO $db): int { return (int)self::setting($db, 'commerce_return_window_hours', 48); }

    /** Per-category risk class (configurable). Non-returnable ⇒ request is refused. */
    public static function riskClass(PDO $db, ?int $categoryId): string
    {
        $raw = self::setting($db, 'commerce_return_risk', '');
        $cfg = is_string($raw) ? json_decode($raw, true) : null;
        $default = is_array($cfg) ? ($cfg['default'] ?? 'returnable_if_sealed') : 'returnable_if_sealed';
        if ($categoryId !== null && is_array($cfg)) {
            if (in_array((int)$categoryId, array_map('intval', $cfg['no_return'] ?? []), true)) return 'no_return';
            if (in_array((int)$categoryId, array_map('intval', $cfg['perishable'] ?? []), true)) return 'perishable';
        }
        return $default;
    }

    /**
     * Customer requests a return. Eligible only if delivered, within the window,
     * and the item's category is returnable. Records the SOURCE (vendor/store the
     * item came from) + the refund method (online → gateway; COD → store credit).
     */
    public static function requestReturn(PDO $db, int $orderId, int $customerId, string $reason, ?int $orderItemId = null): array
    {
        $o = $db->prepare("SELECT id, user_id, status, payment_method, total, delivered_at, created_at FROM orders WHERE id=? AND parent_order_id IS NULL LIMIT 1");
        $o->execute([$orderId]);
        $ord = $o->fetch(PDO::FETCH_ASSOC);
        if (!$ord) return ['ok' => false, 'reason' => 'order_not_found'];
        if ($ord['status'] !== 'delivered') return ['ok' => false, 'reason' => 'not_delivered'];

        // Within return window? (delivered_at, else created_at)
        $window = self::windowHours($db);
        $wq = $db->prepare("SELECT (COALESCE(?, ?) >= (NOW() - INTERVAL ? HOUR)) AS ok");
        $wq->execute([$ord['delivered_at'], $ord['created_at'], $window]);
        if ((int)$wq->fetchColumn() !== 1) return ['ok' => false, 'reason' => 'window_passed'];

        // Source + category: from the chosen item, else the first sub-order.
        $srcVendor = null; $srcStore = null; $catId = null;
        if ($orderItemId) {
            $it = $db->prepare("SELECT oi.vendor_id, oi.store_id, p.category_id FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id WHERE oi.id=? LIMIT 1");
            $it->execute([$orderItemId]); $row = $it->fetch(PDO::FETCH_ASSOC) ?: [];
            $srcVendor = $row['vendor_id'] ?? null; $srcStore = $row['store_id'] ?? null; $catId = $row['category_id'] ?? null;
        } else {
            $sub = $db->prepare("SELECT o.vendor_id, o.store_id, (SELECT p.category_id FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.order_id=o.id LIMIT 1) AS cat FROM orders o WHERE o.parent_order_id=? LIMIT 1");
            $sub->execute([$orderId]); $row = $sub->fetch(PDO::FETCH_ASSOC) ?: [];
            $srcVendor = $row['vendor_id'] ?? null; $srcStore = $row['store_id'] ?? null; $catId = $row['cat'] ?? null;
        }

        $risk = self::riskClass($db, $catId !== null ? (int)$catId : null);
        if (self::hasCustomTintItem($db, $orderId, $orderItemId)) {
            return ['ok' => false, 'reason' => 'not_returnable', 'risk_class' => 'no_return'];
        }
        if ($risk === 'no_return' || $risk === 'perishable') {
            return ['ok' => false, 'reason' => 'not_returnable', 'risk_class' => $risk];
        }

        $method = ($ord['payment_method'] === 'online') ? 'gateway' : 'cod_store_credit';
        $amount = round((float)$ord['total'], 2); // order-level refund (matches LedgerEngine::processRefund)
        $db->prepare(
            "INSERT INTO commerce_returns (order_id, order_item_id, source_vendor_id, source_store_id, reason, risk_class, status, refund_amount, refund_method, return_cost_bearer, created_at, updated_at)
             VALUES (?,?,?,?,?,?, 'requested', ?,?, 'platform', NOW(), NOW())"
        )->execute([$orderId, $orderItemId, $srcVendor, $srcStore, mb_substr($reason, 0, 255), $risk, $amount, $method]);
        $id = (int)$db->lastInsertId();
        CommerceEventBus::emit($db, 'return_requested', ['order_id' => $orderId, 'actor_type' => 'customer', 'actor_id' => $customerId, 'payload' => ['return_id' => $id, 'method' => $method]]);
        return ['ok' => true, 'return_id' => $id, 'risk_class' => $risk, 'refund_method' => $method, 'refund_amount' => $amount, 'source_store_id' => $srcStore];
    }

    private static function setStatus(PDO $db, int $returnId, string $from, string $to, ?int $actorId, string $actorType, string $event): bool
    {
        $r = $db->prepare("SELECT order_id FROM commerce_returns WHERE id=? AND status=? LIMIT 1");
        $r->execute([$returnId, $from]);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        $db->prepare("UPDATE commerce_returns SET status=?, updated_at=NOW() WHERE id=?")->execute([$to, $returnId]);
        CommerceEventBus::emit($db, $event, ['order_id' => (int)$row['order_id'], 'actor_type' => $actorType, 'actor_id' => $actorId, 'payload' => ['return_id' => $returnId]]);
        return true;
    }

    public static function approve(PDO $db, int $returnId, int $opsId): bool
    {
        return self::setStatus($db, $returnId, 'requested', 'approved', $opsId, 'ops', 'return_approved');
    }

    /** Rider has returned the item to the SOURCE seller. */
    public static function markReceived(PDO $db, int $returnId, int $riderId): bool
    {
        return self::setStatus($db, $returnId, 'approved', 'received', $riderId, 'rider', 'return_received');
    }

    /**
     * Issue the refund once received: reverse the ledger (LedgerEngine::processRefund,
     * which also triggers the gateway refund for an online order). For COD the ledger
     * is reversed and the customer refund is a recorded store-credit/cash obligation
     * (the COD-refund SOP — finance actions it).
     */
    public static function refund(PDO $db, int $returnId, int $opsId): array
    {
        $r = $db->prepare("SELECT order_id, status, refund_method FROM commerce_returns WHERE id=? LIMIT 1");
        $r->execute([$returnId]);
        $ret = $r->fetch(PDO::FETCH_ASSOC);
        if (!$ret) return ['ok' => false, 'reason' => 'not_found'];
        if ($ret['status'] !== 'received') return ['ok' => false, 'reason' => 'not_received'];

        // LedgerEngine::processRefund references the namespaced Core\Helpers\Database /
        // Logger (but those classes are global) — alias them defensively so the reused
        // method resolves in any runtime context (see findings: latent namespace issue).
        if (!class_exists('Core\\Helpers\\Database') && class_exists('Database')) class_alias('Database', 'Core\\Helpers\\Database');
        if (!class_exists('Core\\Helpers\\Logger') && class_exists('Logger'))     class_alias('Logger', 'Core\\Helpers\\Logger');

        // Reverse the order ledger (idempotent; online → gateway refund inside).
        $okLedger = \Core\Helpers\LedgerEngine::processRefund((int)$ret['order_id']);

        $db->prepare("UPDATE commerce_returns SET status='refunded', resolved_at=NOW(), updated_at=NOW() WHERE id=?")->execute([$returnId]);
        CommerceEventBus::emit($db, 'refunded', ['order_id' => (int)$ret['order_id'], 'actor_type' => 'ops', 'actor_id' => $opsId, 'payload' => ['return_id' => $returnId, 'method' => $ret['refund_method']]]);
        // COD refunds leave a store-credit/cash obligation for finance (SOP); online is gateway-reversed above.
        return ['ok' => true, 'ledger_reversed' => (bool)$okLedger, 'method' => $ret['refund_method'],
                'cod_obligation' => ($ret['refund_method'] !== 'gateway')];
    }

    public static function reject(PDO $db, int $returnId, int $opsId, string $reason): bool
    {
        return self::setStatus($db, $returnId, 'requested', 'rejected', $opsId, 'ops', 'return_rejected');
    }
}
