<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — CommerceCodGuard  (Paint PB12)
//  COD-abuse controls + abandoned-order cleanup. Additive: honest customers are
//  never affected. A customer may hold at most commerce_cod_open_cap open
//  (undelivered, uncancelled) COD orders; repeated COD no-pays accrue strikes in
//  commerce_cod_strikes and, past commerce_cod_nopay_limit, block COD for them.
//  cleanupAbandoned() cancels unpaid ONLINE orders that have held stock past
//  commerce_abandoned_order_min, reusing the never-touch OrderService::cancel
//  (which releases the reservation) — so stock is never locked forever.
// ─────────────────────────────────────────────────────────────────────────────

class CommerceCodGuard
{
    private static function num(PDO $db, string $key, float $def): float
    {
        try { $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1"); $s->execute([$key]); $v = $s->fetchColumn();
              if ($v !== false && is_numeric($v)) return (float)$v; } catch (\Throwable $e) {}
        return $def;
    }

    /** Open (undelivered, uncancelled) COD orders for a customer. */
    public static function openCodCount(PDO $db, int $userId): int
    {
        try {
            $s = $db->prepare("SELECT COUNT(*) FROM orders
                                WHERE user_id=? AND parent_order_id IS NULL AND payment_method='cod'
                                  AND status NOT IN ('delivered','cancelled','refunded','returned')");
            $s->execute([$userId]); return (int)$s->fetchColumn();
        } catch (\Throwable $e) { return 0; }
    }

    private static function strikeRow(PDO $db, int $userId): array
    {
        try { $s = $db->prepare("SELECT strikes, blocked FROM commerce_cod_strikes WHERE user_id=? LIMIT 1"); $s->execute([$userId]);
              $r = $s->fetch(PDO::FETCH_ASSOC); if ($r) return ['strikes' => (int)$r['strikes'], 'blocked' => (int)$r['blocked'] === 1]; } catch (\Throwable $e) {}
        return ['strikes' => 0, 'blocked' => false];
    }

    /**
     * May this customer place a COD order right now?
     * @return array{ok:bool, reason:string}
     */
    public static function codAllowed(PDO $db, int $userId): array
    {
        $st = self::strikeRow($db, $userId);
        if ($st['blocked']) return ['ok' => false, 'reason' => 'cod_blocked'];
        $cap = (int)self::num($db, 'commerce_cod_open_cap', 3);
        if ($cap > 0 && self::openCodCount($db, $userId) >= $cap) return ['ok' => false, 'reason' => 'cod_cap'];
        return ['ok' => true, 'reason' => 'ok'];
    }

    /** Customer-facing message for a COD block/cap. */
    public static function message(string $reason): string
    {
        if ($reason === 'cod_blocked') return 'Cash on Delivery isn’t available on your account. Please pay online.';
        if ($reason === 'cod_cap')     return 'You have too many open Cash-on-Delivery orders. Complete them first, or pay online.';
        return 'Cash on Delivery isn’t available right now.';
    }

    /** Record a COD no-pay; blocks the customer once strikes reach the limit. */
    public static function recordNoPay(PDO $db, int $userId): array
    {
        $limit = (int)self::num($db, 'commerce_cod_nopay_limit', 3);
        try {
            $db->prepare("INSERT INTO commerce_cod_strikes (user_id, strikes, blocked, updated_at) VALUES (?,1,0,NOW())
                          ON DUPLICATE KEY UPDATE strikes=strikes+1, updated_at=NOW()")->execute([$userId]);
            $row = self::strikeRow($db, $userId);
            if ($limit > 0 && $row['strikes'] >= $limit && !$row['blocked']) {
                $db->prepare("UPDATE commerce_cod_strikes SET blocked=1, updated_at=NOW() WHERE user_id=?")->execute([$userId]);
                $row['blocked'] = true;
            }
            return ['ok' => true] + $row;
        } catch (\Throwable $e) { error_log('[cod strike] ' . $e->getMessage()); return ['ok' => false]; }
    }

    /** Admin: lift a COD block / reset strikes. */
    public static function clearStrikes(PDO $db, int $userId): array
    {
        try { $db->prepare("UPDATE commerce_cod_strikes SET strikes=0, blocked=0, updated_at=NOW() WHERE user_id=?")->execute([$userId]); } catch (\Throwable $e) {}
        return ['ok' => true, 'user_id' => $userId];
    }

    /**
     * Cancel unpaid ONLINE orders that have held stock past the abandoned age,
     * releasing the reservation via the never-touch OrderService::cancel.
     * @return int number of orders cleaned up
     */
    public static function cleanupAbandoned(PDO $db): int
    {
        $mins = (int)self::num($db, 'commerce_abandoned_order_min', 30);
        if ($mins <= 0) return 0;
        try {
            $q = $db->prepare("SELECT id, user_id FROM orders
                                WHERE parent_order_id IS NULL AND payment_method <> 'cod'
                                  AND payment_status = 'unpaid' AND status IN ('pending','confirmed')
                                  AND created_at < (NOW() - INTERVAL ? MINUTE)
                                LIMIT 200");
            $q->execute([$mins]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) { return 0; }
        $n = 0;
        foreach ($rows as $o) {
            try {
                require_once dirname(__DIR__, 2) . '/body/shopping-engine/api/cart/CartService.php';
                require_once dirname(__DIR__, 2) . '/body/shopping-engine/api/orders/OrderService.php';
                $svc = new OrderService($db, new CartService($db));
                $svc->cancel((int)$o['user_id'], (int)$o['id']);   // releases reserved stock
                $n++;
                // Free any rider too — best-effort, never undoes the cancel above.
                try {
                    require_once dirname(__DIR__, 2) . '/core/delivery/DeliveryEngine.php';
                    if (class_exists('DeliveryEngine')) DeliveryEngine::cancelForOrder($db, (int)$o['id']);
                } catch (\Throwable $de) { error_log('[abandoned cleanup] delivery ' . $o['id'] . ': ' . $de->getMessage()); }
            } catch (\Throwable $e) { error_log('[abandoned cleanup] order ' . $o['id'] . ': ' . $e->getMessage()); }
        }
        return $n;
    }
}
