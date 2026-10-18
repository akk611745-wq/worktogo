<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — CommerceReports  (Paint PB3b + PB11)
//  Read-only reporting over the EXISTING order + ledger data — no new money path,
//  no engine edit. PB3b uses it for the live margin preview, the money dashboard
//  numbers, and the per-seller settlement view; PB11 adds the customer invoice +
//  seller report builders. Everything here only SELECTs (invoices read the order).
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/MarginEngine.php';
require_once __DIR__ . '/CommerceSettlement.php';

class CommerceReports
{
    /** Live sample price for a seller base at the CURRENT margin config (slab/flat + growth + floor). */
    public static function marginPreview(PDO $db, float $sellerBase, ?int $categoryId = null): array
    {
        $r = MarginEngine::priceFromBase($db, max(0.0, $sellerBase), null, null, null, null, $categoryId);
        $cp = (float)($r['customer_price'] ?? 0);
        $mg = (float)($r['margin'] ?? 0);
        return [
            'seller_base'    => round($sellerBase, 2),
            'margin'         => round($mg, 2),
            'customer_price' => round($cp, 2),
            'margin_pct'     => $sellerBase > 0 ? round($mg / $sellerBase * 100, 1) : 0.0,
            'source'         => $r['source'] ?? 'flat',
            'growth_mult'    => MarginEngine::growthMultiplier($db),
        ];
    }

    /** Read-only money dashboard numbers: orders, revenue, margin, delivery, COD vs prepaid. */
    public static function moneyStats(PDO $db, int $days = 30): array
    {
        $o = $db->prepare(
            "SELECT COUNT(*) AS orders,
                    COALESCE(SUM(total),0) AS revenue,
                    COALESCE(SUM(delivery_fee),0) AS delivery,
                    SUM(payment_method='cod') AS cod,
                    SUM(payment_method<>'cod') AS prepaid,
                    SUM(payment_status='paid') AS paid_orders
               FROM orders
              WHERE parent_order_id IS NULL AND status <> 'cancelled'
                AND created_at >= (NOW() - INTERVAL ? DAY)"
        );
        $o->execute([$days]);
        $r = $o->fetch(PDO::FETCH_ASSOC) ?: [];
        $split = CommerceSettlement::accountingSplit($db, $days);
        $margin = round((float)($split['own']['margin'] ?? 0) + (float)($split['facilitated']['margin'] ?? 0), 2);
        return [
            'days'        => $days,
            'orders'      => (int)($r['orders'] ?? 0),
            'revenue'     => round((float)($r['revenue'] ?? 0), 2),
            'margin'      => $margin,
            'delivery'    => round((float)($r['delivery'] ?? 0), 2),
            'cod'         => (int)($r['cod'] ?? 0),
            'prepaid'     => (int)($r['prepaid'] ?? 0),
            'paid_orders' => (int)($r['paid_orders'] ?? 0),
            'split'       => $split,
        ];
    }

    /** Per-seller settlement: what's owed (wallet), what's paid out, and the margin we earned on their orders. */
    public static function perSellerSettlement(PDO $db): array
    {
        $sql = "SELECT v.id AS vendor_id, v.business_name,
                       COALESCE(w.available_balance,0) AS available,
                       COALESCE(w.pending_balance,0)   AS pending,
                       COALESCE((SELECT SUM(p.amount) FROM commerce_payouts p
                                  WHERE p.payee_type='vendor' AND p.payee_id=v.id AND p.status='paid'),0) AS paid,
                       COALESCE((SELECT SUM(o.subtotal - COALESCE(o.seller_subtotal,o.subtotal)) FROM orders o
                                  WHERE o.vendor_id=v.id AND o.parent_order_id IS NOT NULL AND o.status<>'cancelled'),0) AS margin_earned
                  FROM vendors v
                  JOIN vendor_wallets w ON w.vendor_id = v.id
                 ORDER BY (COALESCE(w.available_balance,0)+COALESCE(w.pending_balance,0)) DESC";
        try {
            $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) { return []; }
        foreach ($rows as &$r) {
            $r['vendor_id'] = (int)$r['vendor_id'];
            $r['owed']      = round((float)$r['available'] + (float)$r['pending'], 2);
            $r['available'] = round((float)$r['available'], 2);
            $r['pending']   = round((float)$r['pending'], 2);
            $r['paid']      = round((float)$r['paid'], 2);
            $r['margin_earned'] = round((float)$r['margin_earned'], 2);
        }
        return $rows;
    }
}
