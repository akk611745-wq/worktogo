<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — CommerceEventBus  (S4)
//  commerce-system-blueprint §G. The nervous system: an order/event fires →
//  (1) it is appended to the order TIMELINE (commerce_events), (2) the right
//  parties are NOTIFIED (per-user rows in the shared `notifications` table), and
//  (3) ops/support FLAGS are raised/cleared (commerce_order_flags). These are the
//  hooks the ops/support console, owner view and later steps read.
//
//  Deterministic + data-driven: the event→audience map and flag rules are
//  registries (data, not branching logic) and can move to the DB later; the
//  high-value threshold is already in app_settings (configurable). No dependency
//  on the never-touch shopping engine; reuses the shared notifications table
//  rather than duplicating notification infra. Commerce-scoped.
// ─────────────────────────────────────────────────────────────────────────────

class CommerceEventBus
{
    /** event_type → audiences that receive a per-user notification (ops uses flags, not per-user rows). */
    public static function audienceMap(): array
    {
        return [
            'order_created'    => ['customer', 'seller'],
            'order_accepted'   => ['customer'],
            'order_ready'      => ['customer'],
            'out_for_delivery' => ['customer'],
            'delivered'        => ['customer', 'seller'],
            'order_rejected'   => ['customer'],
            'payment_failed'   => ['customer'],
            'cancelled'        => ['customer'],
            'refunded'         => ['customer'],
            // routing/exception events notify no customer; they drive ops flags:
            'order_offered'    => [],
            'order_rerouted'   => [],
            'order_timeout'    => [],
            'reroute_failed'   => [],
            'delivery_failed'  => [],
        ];
    }

    /** event_type → the ops/support flag it raises. */
    public static function flagRules(): array
    {
        return [
            'order_timeout'   => 'needs_ops',
            'reroute_failed'  => 'needs_ops',
            'delivery_failed' => 'needs_ops',
            'payment_failed'  => 'payment_issue',
            'vehicle_doubt'   => 'vehicle_doubt',   // PB8 → PB9 ops queue
        ];
    }

    public static function highValueThreshold(PDO $db): float
    {
        try {
            $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key='commerce_high_value_threshold' LIMIT 1");
            $s->execute();
            $v = $s->fetchColumn();
            return $v === false ? 0.0 : (float)$v;
        } catch (\Throwable $e) { return 0.0; }
    }

    /**
     * Fire an event: append to the timeline, raise flags, notify audiences.
     * @param array $ctx order_id, sub_order_id?, actor_type?, actor_id?, from_state?, to_state?, payload?, reason?
     * @return int the commerce_events row id
     */
    public static function emit(PDO $db, string $eventType, array $ctx = []): int
    {
        $orderId = isset($ctx['order_id']) ? (int)$ctx['order_id'] : null;
        $subId   = isset($ctx['sub_order_id']) ? (int)$ctx['sub_order_id'] : null;

        // 1. timeline
        $db->prepare("INSERT INTO commerce_events (order_id, sub_order_id, event_type, actor_type, actor_id, from_state, to_state, payload, created_at)
                      VALUES (?,?,?,?,?,?,?,?,NOW())")
           ->execute([
               $orderId, $subId, $eventType,
               $ctx['actor_type'] ?? 'system',
               isset($ctx['actor_id']) ? (int)$ctx['actor_id'] : null,
               $ctx['from_state'] ?? null, $ctx['to_state'] ?? null,
               isset($ctx['payload']) ? json_encode($ctx['payload']) : null,
           ]);
        $eventId = (int)$db->lastInsertId();

        // 2. ops/support flags
        $rules = self::flagRules();
        if ($orderId && isset($rules[$eventType])) {
            self::raiseFlag($db, $orderId, $rules[$eventType], $ctx['reason'] ?? $eventType);
        }
        // high-value human-approval hold
        if ($orderId && $eventType === 'order_created') {
            $thr = self::highValueThreshold($db);
            if ($thr > 0) {
                $t = $db->prepare("SELECT total FROM orders WHERE id=? LIMIT 1");
                $t->execute([$orderId]);
                $total = (float)($t->fetchColumn() ?: 0);
                if ($total >= $thr) self::raiseFlag($db, $orderId, 'high_value_hold', 'total ' . $total . ' >= ' . $thr);
            }
        }

        // 3. notifications (per-user, shared table)
        if ($orderId) {
            foreach (self::audienceMap()[$eventType] ?? [] as $audience) {
                [$title, $body] = self::messageFor($eventType, $audience);
                foreach (self::resolveRecipients($db, $orderId, $audience) as $uid) {
                    self::notify($db, $uid, $title, $body, $eventType);
                }
            }
        }

        // 4. PB7 external fan-out (push / SMS / WhatsApp) + per-event tone + log.
        //    Best-effort: a channel failure is logged inside the notifier and can
        //    NEVER bubble up to block the order.
        try {
            if (!class_exists('CommerceNotifier')) { $f = __DIR__ . '/CommerceNotifier.php'; if (is_file($f)) require_once $f; }
            if (!class_exists('FcmNotifier'))       { $f = __DIR__ . '/../../core/lib/FcmNotifier.php'; if (is_file($f)) require_once $f; }
            if (class_exists('CommerceNotifier')) CommerceNotifier::dispatch($db, $eventType, $orderId, $ctx);
        } catch (\Throwable $e) { error_log('[eventbus notify] ' . $e->getMessage()); }

        return $eventId;
    }

    public static function raiseFlag(PDO $db, int $orderId, string $flag, ?string $reason = null): void
    {
        $c = $db->prepare("SELECT id FROM commerce_order_flags WHERE order_id=? AND flag=? AND active=1 LIMIT 1");
        $c->execute([$orderId, $flag]);
        if ($c->fetch()) return; // already active — no duplicate
        $db->prepare("INSERT INTO commerce_order_flags (order_id, flag, active, reason, created_at) VALUES (?,?,1,?,NOW())")
           ->execute([$orderId, $flag, $reason]);
    }

    public static function clearFlag(PDO $db, int $orderId, string $flag): int
    {
        $u = $db->prepare("UPDATE commerce_order_flags SET active=0, cleared_at=NOW() WHERE order_id=? AND flag=? AND active=1");
        $u->execute([$orderId, $flag]);
        return $u->rowCount();
    }

    public static function activeFlags(PDO $db, int $orderId): array
    {
        $s = $db->prepare("SELECT flag FROM commerce_order_flags WHERE order_id=? AND active=1 ORDER BY id");
        $s->execute([$orderId]);
        return $s->fetchAll(PDO::FETCH_COLUMN);
    }

    /** The full timeline for one order (ops console / owner view). */
    public static function timeline(PDO $db, int $orderId): array
    {
        $s = $db->prepare("SELECT id, event_type, actor_type, from_state, to_state, created_at FROM commerce_events WHERE order_id=? ORDER BY id");
        $s->execute([$orderId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function notify(PDO $db, int $userId, string $title, string $body, string $type): void
    {
        $db->prepare("INSERT INTO notifications (user_id, title, body, type, is_read, created_at) VALUES (?,?,?,?,0,NOW())")
           ->execute([$userId, $title, $body, substr($type, 0, 50)]);
    }

    /** Resolve per-user recipients from the order (seller is hidden from the customer, not from settlement). */
    private static function resolveRecipients(PDO $db, int $orderId, string $audience): array
    {
        try {
            if ($audience === 'customer') {
                $s = $db->prepare("SELECT user_id FROM orders WHERE id=? LIMIT 1");
                $s->execute([$orderId]);
                $uid = $s->fetchColumn();
                return $uid ? [(int)$uid] : [];
            }
            if ($audience === 'seller') {
                $s = $db->prepare("SELECT DISTINCT v.user_id FROM orders o JOIN vendors v ON v.id=o.vendor_id WHERE o.parent_order_id=? AND v.user_id IS NOT NULL");
                $s->execute([$orderId]);
                return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
            }
            if ($audience === 'rider') {
                $s = $db->prepare("SELECT driver_id FROM deliveries WHERE order_id=? AND driver_id IS NOT NULL ORDER BY id DESC LIMIT 1");
                $s->execute([$orderId]);
                $did = $s->fetchColumn();
                return $did ? [(int)$did] : [];
            }
        } catch (\Throwable $e) { /* best-effort */ }
        return [];
    }

    /** Customer-facing copy keeps the seller hidden; seller copy is operational. */
    private static function messageFor(string $eventType, string $audience): array
    {
        $m = [
            'order_created'    => ['customer' => ['Order placed', 'Your WorkToGo order is confirmed.'],
                                   'seller'   => ['New order', 'You have a new order to accept.']],
            'order_accepted'   => ['customer' => ['Order confirmed', 'Your order is being prepared.']],
            'order_ready'      => ['customer' => ['Order ready', 'Your order is ready and will be out for delivery.']],
            'out_for_delivery' => ['customer' => ['Out for delivery', 'Your order is on the way.']],
            'delivered'        => ['customer' => ['Delivered', 'Your order has been delivered. Enjoy!'],
                                   'seller'   => ['Delivered', 'An order you fulfilled was delivered.']],
            'order_rejected'   => ['customer' => ['Finding another store', 'We’re getting your order from another nearby store.']],
            'payment_failed'   => ['customer' => ['Payment issue', 'There was a problem with your payment. Please retry.']],
            'cancelled'        => ['customer' => ['Order cancelled', 'Your order has been cancelled.']],
            'refunded'         => ['customer' => ['Refund initiated', 'Your refund has been initiated.']],
        ];
        return $m[$eventType][$audience] ?? ['WorkToGo', 'Order update.'];
    }
}
