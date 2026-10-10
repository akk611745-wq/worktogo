<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — CustomerId  (S14)
//  core-completion-plan §2 S14; CEO C.4. A stable, OPAQUE customer identity the
//  seller can recognise WITHOUT any PII. One map row (user_id → random ref); the
//  ref is all a seller ever sees. The seller-facing history is derived live from
//  orders/order_items — no name/phone/address is ever selected — and is strictly
//  isolated per seller (by vendor_id): a seller sees only their own orders for a
//  ref, never another seller's. `resolveRef` is server-internal only and is never
//  returned to a seller. Builds on S10 seller-hidden + S9 per-seller settlement.
// ─────────────────────────────────────────────────────────────────────────────

class CustomerId
{
    /** Get-or-create the opaque ref for a user. Stable across calls. */
    public static function forUser(PDO $db, int $userId): string
    {
        if ($userId <= 0) return '';
        $s = $db->prepare("SELECT customer_ref FROM commerce_customer_ids WHERE user_id = ? LIMIT 1");
        $s->execute([$userId]);
        $ref = $s->fetchColumn();
        if ($ref !== false && $ref !== null && $ref !== '') return (string)$ref;

        // Create — retry on the (rare) ref collision.
        for ($i = 0; $i < 6; $i++) {
            $cand = self::generateRef();
            try {
                $db->prepare("INSERT INTO commerce_customer_ids (user_id, customer_ref, created_at) VALUES (?,?, NOW())")
                   ->execute([$userId, $cand]);
                return $cand;
            } catch (\Throwable $e) {
                // Another request may have created this user's row first → re-read.
                $s->execute([$userId]);
                $ref = $s->fetchColumn();
                if ($ref !== false && $ref !== null && $ref !== '') return (string)$ref;
                // else ref collided → loop and try a new candidate
            }
        }
        return '';
    }

    /** Opaque, non-sequential, no-PII ref, e.g. "C7F3A9B2". */
    private static function generateRef(): string
    {
        try { $hex = bin2hex(random_bytes(5)); }
        catch (\Throwable $e) { $hex = substr(md5(uniqid('', true)), 0, 10); }
        return 'C' . strtoupper(substr($hex, 0, 8));
    }

    /** Ref → user_id. SERVER-INTERNAL ONLY — never expose to a seller. */
    public static function resolveRef(PDO $db, string $ref): ?int
    {
        $ref = trim($ref);
        if ($ref === '') return null;
        $s = $db->prepare("SELECT user_id FROM commerce_customer_ids WHERE customer_ref = ? LIMIT 1");
        $s->execute([$ref]);
        $uid = $s->fetchColumn();
        return ($uid === false || $uid === null) ? null : (int)$uid;
    }

    /**
     * A seller's OWN history with a customer ref: the lines this seller fulfilled
     * for that customer, newest first, plus aggregates (order count, total qty,
     * last ordered, and a per-product "bought N times") — for exact repeat-match
     * and seller motivation. Isolated by vendor_id (no cross-seller leakage) and
     * free of PII (no name/phone/address selected). Unknown ref → empty.
     */
    public static function sellerHistory(PDO $db, int $sellerVendorId, string $customerRef): array
    {
        $empty = ['customer_ref' => $customerRef, 'order_count' => 0, 'item_count' => 0, 'total_qty' => 0, 'last_ordered_at' => null, 'items' => [], 'products' => []];
        $uid = self::resolveRef($db, $customerRef);
        if ($uid === null || $sellerVendorId <= 0) return $empty;

        $sql = "SELECT oi.product_id, oi.product_name, oi.quantity, oi.unit_price, oi.line_total,
                       oi.attributes, o.id AS order_id, o.order_number, o.status, o.created_at
                  FROM order_items oi
                  JOIN orders o   ON o.id = oi.order_id
                  LEFT JOIN orders par ON par.id = o.parent_order_id
                 WHERE oi.vendor_id = :seller
                   AND COALESCE(par.user_id, o.user_id) = :uid
                 ORDER BY o.created_at DESC, oi.id DESC";
        $st = $db->prepare($sql);
        $st->execute([':seller' => $sellerVendorId, ':uid' => $uid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = []; $orderIds = []; $totalQty = 0; $last = null; $byProduct = [];
        foreach ($rows as $r) {
            $qty = (int)$r['quantity'];
            $totalQty += $qty;
            $orderIds[(int)$r['order_id']] = true;
            if ($last === null) $last = $r['created_at']; // rows are newest-first
            $attrs = null;
            if (!empty($r['attributes'])) { $j = json_decode((string)$r['attributes'], true); if (is_array($j)) $attrs = $j; }
            $items[] = [
                'product_id'   => (int)$r['product_id'],
                'product_name' => $r['product_name'],
                'quantity'     => $qty,
                'unit_price'   => (float)$r['unit_price'],
                'line_total'   => (float)$r['line_total'],
                'attributes'   => $attrs,                 // shade/base/code snapshot etc. (repeat-match fuel)
                'order_number' => $r['order_number'],
                'status'       => $r['status'],
                'ordered_at'   => $r['created_at'],
            ];
            $key = (int)$r['product_id'];
            if (!isset($byProduct[$key])) $byProduct[$key] = ['product_id' => $key, 'product_name' => $r['product_name'], 'times' => 0, 'total_qty' => 0];
            $byProduct[$key]['times']     += 1;
            $byProduct[$key]['total_qty'] += $qty;
        }

        return [
            'customer_ref'    => $customerRef,
            'order_count'     => count($orderIds),
            'item_count'      => count($items),
            'total_qty'       => $totalQty,
            'last_ordered_at' => $last,
            'items'           => $items,
            'products'        => array_values($byProduct),
        ];
    }
}
