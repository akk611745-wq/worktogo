<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — OpsConsole  (S7)
//  commerce-system-blueprint §K / §Support. The ops/support BACKEND that reads
//  the S4 hooks (timeline/flags/events) and acts — reusing the event bus +
//  DeliveryEngine::adminReassign + OrderService::cancel (none of them edited):
//     • exceptionQueue()  — active flags as tickets (type, age, SLA breach, owner, escalated)
//     • claim/escalate/resolve — ticket ownership + escalation ladder
//     • approveHold/rejectHold — human-decision window (large/risky order approval)
//     • reassignRider/cancelOrder — full manual override on any order
//     • postMessage/thread — order-tied comms (customer↔ops / ops↔seller / ops↔rider)
//     • liveOrders/ownerSummary — the live board + owner view
//  Every action writes the order timeline (CommerceEventBus). All values DB-config.
//  No per-order work ever requires the founder: this is the seat the team uses.
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/CommerceEventBus.php';
require_once __DIR__ . '/../../core/delivery/DeliveryEngine.php';
require_once __DIR__ . '/../../body/shopping-engine/api/orders/OrderService.php'; // pulls CartService

class OpsConsole
{
    public static function ticketSlaSec(PDO $db): int
    {
        try {
            $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key='commerce_ticket_sla_sec' LIMIT 1");
            $s->execute();
            $v = $s->fetchColumn();
            return $v === false ? 900 : (int)$v;
        } catch (\Throwable $e) { return 900; }
    }

    /** Active flags as the exception-ticket queue (oldest first), with SLA breach. */
    public static function exceptionQueue(PDO $db): array
    {
        $sla = self::ticketSlaSec($db);
        $sql = "SELECT f.id AS flag_id, f.order_id, f.flag, f.reason, f.owner_user_id, f.escalated_at, f.created_at,
                       TIMESTAMPDIFF(SECOND, f.created_at, NOW()) AS age_sec,
                       o.order_number, o.status, o.payment_status, o.delivery_status, o.total
                  FROM commerce_order_flags f
                  JOIN orders o ON o.id = f.order_id
                 WHERE f.active = 1
                 ORDER BY f.created_at ASC";
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['age_sec']      = (int)$r['age_sec'];
            $r['sla_breached'] = $r['age_sec'] > $sla;
            $r['escalated']    = $r['escalated_at'] !== null;
        }
        return $rows;
    }

    public static function claim(PDO $db, int $orderId, string $flag, int $opsUserId): bool
    {
        $u = $db->prepare("UPDATE commerce_order_flags SET owner_user_id=? WHERE order_id=? AND flag=? AND active=1");
        $u->execute([$opsUserId, $orderId, $flag]);
        return $u->rowCount() > 0;
    }

    public static function escalate(PDO $db, int $orderId, string $flag, int $opsUserId, ?string $note = null): bool
    {
        $u = $db->prepare("UPDATE commerce_order_flags SET escalated_at=NOW() WHERE order_id=? AND flag=? AND active=1");
        $u->execute([$orderId, $flag]);
        if ($u->rowCount() > 0) {
            CommerceEventBus::emit($db, 'ticket_escalated', ['order_id' => $orderId, 'actor_type' => 'ops', 'actor_id' => $opsUserId, 'payload' => ['flag' => $flag, 'note' => $note]]);
            return true;
        }
        return false;
    }

    public static function resolve(PDO $db, int $orderId, string $flag, int $opsUserId, ?string $note = null): bool
    {
        $cleared = CommerceEventBus::clearFlag($db, $orderId, $flag);
        if ($cleared > 0) {
            CommerceEventBus::emit($db, 'ticket_resolved', ['order_id' => $orderId, 'actor_type' => 'ops', 'actor_id' => $opsUserId, 'payload' => ['flag' => $flag, 'note' => $note]]);
            return true;
        }
        return false;
    }

    // ── Human-decision window (large / risky orders held for approval) ───────────
    public static function approveHold(PDO $db, int $orderId, int $opsUserId): bool
    {
        if (CommerceEventBus::clearFlag($db, $orderId, 'high_value_hold') > 0) {
            CommerceEventBus::emit($db, 'order_approved', ['order_id' => $orderId, 'actor_type' => 'ops', 'actor_id' => $opsUserId, 'to_state' => 'approved']);
            return true;
        }
        return false;
    }

    public static function rejectHold(PDO $db, int $orderId, int $opsUserId, string $reason): array
    {
        CommerceEventBus::clearFlag($db, $orderId, 'high_value_hold');
        CommerceEventBus::emit($db, 'order_rejected_by_ops', ['order_id' => $orderId, 'actor_type' => 'ops', 'actor_id' => $opsUserId, 'reason' => $reason]);
        return self::cancelOrder($db, $orderId, $opsUserId, 'rejected at approval: ' . $reason);
    }

    // ── Overrides (reuse existing engines) ──────────────────────────────────────
    public static function reassignRider(PDO $db, int $orderId, ?int $riderId = null): array
    {
        $s = $db->prepare("SELECT id FROM deliveries WHERE order_id=? ORDER BY id DESC LIMIT 1");
        $s->execute([$orderId]);
        $deliveryId = (int)($s->fetchColumn() ?: 0);
        if ($deliveryId === 0) return ['reassigned' => false, 'reason' => 'no_delivery'];
        try {
            $res = DeliveryEngine::adminReassign($db, $deliveryId, $riderId);
            CommerceEventBus::emit($db, 'rider_reassigned', ['order_id' => $orderId, 'actor_type' => 'ops', 'payload' => $res]);
            return $res;
        } catch (\Throwable $e) {
            return ['reassigned' => false, 'reason' => $e->getMessage()];
        }
    }

    public static function cancelOrder(PDO $db, int $orderId, int $opsUserId, string $reason): array
    {
        $o = $db->prepare("SELECT user_id, status FROM orders WHERE id=? AND parent_order_id IS NULL LIMIT 1");
        $o->execute([$orderId]);
        $ord = $o->fetch(PDO::FETCH_ASSOC);
        if (!$ord) return ['cancelled' => false, 'reason' => 'not_found'];
        try {
            $svc = new OrderService($db, new CartService($db));
            $res = $svc->cancel((int)$ord['user_id'], $orderId);   // reuse engine cancel (releases reserved stock)
            try { DeliveryEngine::cancelForOrder($db, $orderId); } catch (\Throwable $de) {}
            CommerceEventBus::clearFlag($db, $orderId, 'needs_ops');
            CommerceEventBus::emit($db, 'cancelled', ['order_id' => $orderId, 'actor_type' => 'ops', 'actor_id' => $opsUserId, 'to_state' => 'cancelled', 'reason' => $reason]);
            return ['cancelled' => true] + $res;
        } catch (\Throwable $e) {
            return ['cancelled' => false, 'reason' => $e->getMessage()];
        }
    }

    // ── Order-tied comms ─────────────────────────────────────────────────────────
    public static function postMessage(PDO $db, int $orderId, string $fromType, ?int $fromId, string $body, ?string $toType = null): int
    {
        $db->prepare("INSERT INTO commerce_messages (order_id, from_type, from_id, to_type, body, created_at) VALUES (?,?,?,?,?,NOW())")
           ->execute([$orderId, $fromType, $fromId, $toType, mb_substr($body, 0, 1000)]);
        return (int)$db->lastInsertId();
    }

    public static function thread(PDO $db, int $orderId): array
    {
        $s = $db->prepare("SELECT id, from_type, from_id, to_type, body, created_at FROM commerce_messages WHERE order_id=? ORDER BY id");
        $s->execute([$orderId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Live board + owner view ──────────────────────────────────────────────────
    public static function liveOrders(PDO $db, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $s = $db->prepare(
            "SELECT o.id, o.order_number, o.status, o.payment_status, o.delivery_status, o.total, o.created_at,
                    (SELECT COUNT(*) FROM commerce_order_flags f WHERE f.order_id=o.id AND f.active=1) AS open_flags
               FROM orders o
              WHERE o.parent_order_id IS NULL
              ORDER BY o.created_at DESC
              LIMIT $limit"
        );
        $s->execute();
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function ownerSummary(PDO $db): array
    {
        $byType = $db->query("SELECT flag, COUNT(*) c FROM commerce_order_flags WHERE active=1 GROUP BY flag")->fetchAll(PDO::FETCH_KEY_PAIR);
        $openTickets = array_sum(array_map('intval', $byType));
        $awaiting = (int)($byType['high_value_hold'] ?? 0);
        $ordersToday = (int)$db->query("SELECT COUNT(*) FROM orders WHERE parent_order_id IS NULL AND created_at >= CURDATE()")->fetchColumn();
        return [
            'open_tickets'       => $openTickets,
            'by_type'            => $byType,
            'awaiting_approval'  => $awaiting,
            'orders_today'       => $ordersToday,
        ];
    }
}
