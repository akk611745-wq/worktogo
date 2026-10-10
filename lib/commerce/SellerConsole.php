<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — SellerConsole  (S15)
//  core-completion-plan §2 S15. The backend for the external shop ("seller"): the
//  core foundation WorkToGo is built on — no own stock, sellers only. A seller is
//  a `vendor_shopping` user; every action here is scoped to THEIR vendor_id.
//  Capabilities: availability (ON/OFF/BUSY, reusing S2 fields), the offered-orders
//  INBOX (customer shown only as the opaque S14 ref), ACCEPT / REJECT / STOCK-OUT
//  (closing the S3 offer loop), earnings, and the seller's own Customer-ID history.
//
//  (A) ACCEPT-GATES-DISPATCH (CEO decision): the rider is dispatched only AFTER the
//  seller accepts, so no wasted trip to a shop that rejects. Checkout now only marks
//  the sub-order OFFERED (S3); the dispatch (native DeliveryEngine + S6 handoff/OTP)
//  happens here on accept. Config `commerce_accept_gates_dispatch` (default on) can
//  switch back to legacy dispatch-at-checkout. No engine is edited — DeliveryEngine /
//  OrderService are called via their public methods only.
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/RoutingEngine.php';
require_once __DIR__ . '/CustomerId.php';

class SellerConsole
{
    /** The authenticated user's commerce seller vendor_id, or null if not a seller. */
    public static function resolveVendorId(PDO $db, int $userId): ?int
    {
        if ($userId <= 0) return null;
        $s = $db->prepare("SELECT id FROM vendors WHERE user_id=? ORDER BY (`type`='shopping') DESC, id ASC LIMIT 1");
        $s->execute([$userId]);
        $v = $s->fetchColumn();
        return ($v === false || $v === null) ? null : (int)$v;
    }

    /** Whether the rider is gated to seller-accept (default on). */
    public static function acceptGatesDispatch(PDO $db): bool
    {
        try {
            $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key='commerce_accept_gates_dispatch' LIMIT 1");
            $s->execute();
            $v = $s->fetchColumn();
            return $v === false || $v === null || $v === '' ? true : ((string)$v !== '0');
        } catch (\Throwable $e) { return true; }
    }

    // ── availability (ON / OFF / BUSY) — reuses S2 vendors.is_online / busy_until ─
    public static function setAvailability(PDO $db, int $vendorId, string $status, int $busyMinutes = 30): array
    {
        $status = strtolower(trim($status));
        if ($status === 'online')  { $db->prepare("UPDATE vendors SET is_online=1, busy_until=NULL, updated_at=NOW() WHERE id=?")->execute([$vendorId]); }
        elseif ($status === 'offline') { $db->prepare("UPDATE vendors SET is_online=0, busy_until=NULL, updated_at=NOW() WHERE id=?")->execute([$vendorId]); }
        elseif ($status === 'busy') { $mins = max(1, min(600, $busyMinutes)); $db->prepare("UPDATE vendors SET is_online=1, busy_until=(NOW() + INTERVAL ? MINUTE), updated_at=NOW() WHERE id=?")->execute([$mins, $vendorId]); }
        else return ['ok' => false, 'reason' => 'bad_status'];
        return ['ok' => true] + self::availability($db, $vendorId);
    }

    public static function availability(PDO $db, int $vendorId): array
    {
        $s = $db->prepare("SELECT is_online, busy_until, (busy_until IS NOT NULL AND busy_until > NOW()) AS busy FROM vendors WHERE id=? LIMIT 1");
        $s->execute([$vendorId]);
        $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        $online = (int)($r['is_online'] ?? 0) === 1;
        $busy = (int)($r['busy'] ?? 0) === 1;
        return ['status' => !$online ? 'offline' : ($busy ? 'busy' : 'online'), 'is_online' => $online, 'busy_until' => $r['busy_until'] ?? null];
    }

    // ── offered-orders inbox (customer = opaque S14 ref; seller stays blind) ─────
    public static function inbox(PDO $db, int $vendorId): array
    {
        $q = $db->prepare(
            "SELECT o.id AS sub_order_id, o.parent_order_id, o.order_number, o.subtotal, o.total, o.created_at,
                    COALESCE(par.user_id, o.user_id) AS cust_uid
               FROM orders o LEFT JOIN orders par ON par.id = o.parent_order_id
              WHERE o.vendor_id = :v AND o.delivery_status = 'offered'
              ORDER BY o.created_at DESC"
        );
        $q->execute([':v' => $vendorId]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $it = $db->prepare("SELECT product_name, quantity, unit_price, line_total, attributes FROM order_items WHERE order_id=?");
            $it->execute([(int)$r['sub_order_id']]);
            $items = [];
            foreach ($it->fetchAll(PDO::FETCH_ASSOC) as $ir) {
                $attrs = null; if (!empty($ir['attributes'])) { $j = json_decode((string)$ir['attributes'], true); if (is_array($j)) $attrs = $j; }
                $items[] = ['product_name' => $ir['product_name'], 'quantity' => (int)$ir['quantity'], 'unit_price' => (float)$ir['unit_price'], 'line_total' => (float)$ir['line_total'], 'attributes' => $attrs];
            }
            $out[] = [
                'sub_order_id' => (int)$r['sub_order_id'],
                'order_number' => $r['order_number'],
                'customer_ref' => CustomerId::forUser($db, (int)$r['cust_uid']), // opaque; no PII
                'subtotal'     => (float)$r['subtotal'],
                'offered_at'   => $r['created_at'],
                'items'        => $items,
            ];
        }
        return $out;
    }

    // ── accept / reject / stock-out (closes the S3 loop; accept gates dispatch) ──
    public static function accept(PDO $db, int $vendorId, int $subOrderId): array
    {
        $res = RoutingEngine::sellerAccept($db, $subOrderId, $vendorId);
        if (empty($res['ok'])) return $res;
        $res['gated'] = self::acceptGatesDispatch($db);
        $res['dispatched'] = false;
        if ($res['gated']) {
            try {
                require_once dirname(__DIR__, 2) . '/core/delivery/DeliveryEngine.php';
                require_once __DIR__ . '/CommerceDelivery.php';
                $res['delivery'] = [
                    'created' => DeliveryEngine::createForOrder($db, $subOrderId, null, null, (float)($res['subtotal'] ?? 0)),
                    'handoff' => CommerceDelivery::enrichHandoff($db, $subOrderId),
                    'otp'     => CommerceDelivery::issueOtp($db, $subOrderId),
                ];
                $res['dispatched'] = true;
            } catch (\Throwable $e) {
                error_log('[SellerConsole::accept dispatch] ' . $e->getMessage());
                $res['dispatch_error'] = true; // accept stands; ops can reassign (S7)
            }
        }
        return $res;
    }

    public static function reject(PDO $db, int $vendorId, int $subOrderId, string $reason = ''): array
    {
        return RoutingEngine::sellerReject($db, $subOrderId, $vendorId, $reason, false);
    }

    public static function stockOut(PDO $db, int $vendorId, int $subOrderId, string $reason = 'out of stock'): array
    {
        return RoutingEngine::sellerReject($db, $subOrderId, $vendorId, $reason, true);
    }

    // ── earnings (thin read; fills in fully once seller_subtotal is recorded, S16) ─
    public static function earnings(PDO $db, int $vendorId): array
    {
        $s = $db->prepare(
            "SELECT
               SUM(CASE WHEN status='delivered' THEN 1 ELSE 0 END) AS delivered_count,
               COALESCE(SUM(CASE WHEN status='delivered' THEN total END),0) AS gross_delivered,
               COALESCE(SUM(CASE WHEN status='delivered' THEN COALESCE(seller_subtotal, subtotal) END),0) AS seller_earned,
               SUM(CASE WHEN status IN ('confirmed','processing','shipped') THEN 1 ELSE 0 END) AS in_progress
             FROM orders WHERE vendor_id=?"
        );
        $s->execute([$vendorId]);
        $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'delivered_count' => (int)($r['delivered_count'] ?? 0),
            'in_progress'     => (int)($r['in_progress'] ?? 0),
            'gross_delivered' => round((float)($r['gross_delivered'] ?? 0), 2),
            'seller_earned'   => round((float)($r['seller_earned'] ?? 0), 2),
        ];
    }

    public static function customerHistory(PDO $db, int $vendorId, string $customerRef): array
    {
        return CustomerId::sellerHistory($db, $vendorId, $customerRef);
    }
}
