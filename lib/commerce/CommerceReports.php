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

    // ── PB11 billing ─────────────────────────────────────────────────────────

    private static function cfgRaw(PDO $db, string $key, $def = null)
    {
        try { $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1"); $s->execute([$key]); $v = $s->fetchColumn();
              return $v === false ? $def : $v; } catch (\Throwable $e) { return $def; }
    }

    /** Invoice header business details (name/address/email/phone) + GSTIN + tax rate. */
    public static function invoiceConfig(PDO $db): array
    {
        $biz = json_decode((string)self::cfgRaw($db, 'commerce_invoice_business', ''), true);
        if (!is_array($biz)) $biz = ['name' => 'WorkToGo', 'address' => '', 'email' => '', 'phone' => ''];
        return [
            'gstin'     => (string)self::cfgRaw($db, 'commerce_gstin', ''),
            'tax_rate'  => (float)self::cfgRaw($db, 'commerce_tax_rate_pct', 0),
            'business'  => $biz,
        ];
    }

    /**
     * Build a GST-ready customer invoice from an order's detail (reused from the
     * never-touch OrderService — passed in by the adapter). Taxes only if a rate
     * is configured; the order total is authoritative and the lines reconcile to it.
     * @param array $order  OrderService::detail() result (vendor_orders[].items[])
     */
    public static function customerInvoice(PDO $db, array $order): array
    {
        $cfg = self::invoiceConfig($db);
        $lines = [];
        $itemsTotal = 0.0;
        foreach (($order['vendor_orders'] ?? []) as $vo) {
            foreach (($vo['items'] ?? []) as $it) {
                $lt = (float)($it['line_total'] ?? ((float)($it['unit_price'] ?? 0) * (int)($it['quantity'] ?? 1)));
                $itemsTotal += $lt;
                $lines[] = [
                    'name'       => $it['name'] ?? $it['product_name'] ?? 'Item',
                    'quantity'   => (int)($it['quantity'] ?? 1),
                    'unit_price' => round((float)($it['unit_price'] ?? 0), 2),
                    'line_total' => round($lt, 2),
                ];
            }
        }
        $grand    = round((float)($order['total'] ?? $itemsTotal), 2);
        $delivery = round((float)($order['delivery_fee'] ?? max(0, $grand - $itemsTotal)), 2);
        $taxRate  = (float)$cfg['tax_rate'];
        // When a tax rate is set, the grand total is treated as tax-inclusive and
        // the tax is shown extracted from it (no change to what the customer pays).
        $taxableBase = $grand; $tax = 0.0;
        if ($taxRate > 0) { $taxableBase = round($grand / (1 + $taxRate / 100), 2); $tax = round($grand - $taxableBase, 2); }
        return [
            'invoice_no'   => $order['order_number'] ?? ('INV-' . ($order['id'] ?? '')),
            'date'         => $order['created_at'] ?? null,
            'business'     => $cfg['business'],
            'gstin'        => $cfg['gstin'],
            'bill_to'      => is_array($order['shipping_address'] ?? null) ? $order['shipping_address'] : (json_decode((string)($order['shipping_address'] ?? ''), true) ?: null),
            'lines'        => $lines,
            'items_total'  => round($itemsTotal, 2),
            'delivery_fee' => $delivery,
            'tax_rate'     => $taxRate,
            'taxable_value'=> $taxRate > 0 ? $taxableBase : null,
            'tax'          => $taxRate > 0 ? $tax : null,
            'grand_total'  => $grand,
            'payment_method' => $order['payment_method'] ?? 'cod',
            'payment_status' => $order['payment_status'] ?? null,
        ];
    }

    /** Seller report: sales, units sold, margin split, stock movement, payouts. */
    public static function sellerReport(PDO $db, int $vendorId, int $days = 30): array
    {
        $sales = ['orders' => 0, 'gross' => 0.0, 'to_seller' => 0.0, 'platform_margin' => 0.0, 'units' => 0];
        try {
            $s = $db->prepare(
                "SELECT COUNT(DISTINCT o.id) AS orders,
                        COALESCE(SUM(o.subtotal),0) AS gross,
                        COALESCE(SUM(COALESCE(o.seller_subtotal,o.subtotal)),0) AS to_seller
                   FROM orders o
                  WHERE o.vendor_id=? AND o.parent_order_id IS NOT NULL AND o.status<>'cancelled'
                    AND o.created_at >= (NOW() - INTERVAL ? DAY)");
            $s->execute([$vendorId, $days]);
            $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
            $sales['orders'] = (int)($r['orders'] ?? 0);
            $sales['gross'] = round((float)($r['gross'] ?? 0), 2);
            $sales['to_seller'] = round((float)($r['to_seller'] ?? 0), 2);
            $sales['platform_margin'] = round($sales['gross'] - $sales['to_seller'], 2);
            $u = $db->prepare("SELECT COALESCE(SUM(oi.quantity),0) FROM order_items oi JOIN orders o ON o.id=oi.order_id
                                WHERE o.vendor_id=? AND o.parent_order_id IS NOT NULL AND o.status<>'cancelled' AND o.created_at >= (NOW() - INTERVAL ? DAY)");
            $u->execute([$vendorId, $days]); $sales['units'] = (int)$u->fetchColumn();
        } catch (\Throwable $e) {}

        $stock = [];
        try {
            $st = $db->prepare("SELECT product_id, field, old_value, new_value, changed_at AS created_at FROM commerce_offer_changes
                                 WHERE vendor_id=? AND field='stock' AND changed_at >= (NOW() - INTERVAL ? DAY) ORDER BY changed_at DESC LIMIT 100");
            $st->execute([$vendorId, $days]); $stock = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {}

        $payouts = [];
        try {
            $p = $db->prepare("SELECT amount, method, status, reference, created_at, paid_at FROM commerce_payouts
                                WHERE payee_type='vendor' AND payee_id=? ORDER BY created_at DESC LIMIT 100");
            $p->execute([$vendorId]); $payouts = $p->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {}

        return ['days' => $days, 'sales' => $sales, 'stock_movement' => $stock, 'payouts' => $payouts];
    }

    /** Flatten a seller report to CSV text (download). */
    public static function sellerReportCsv(array $rep): string
    {
        $out = "WorkToGo seller report — last {$rep['days']} days\n\n";
        $s = $rep['sales'];
        $out .= "Sales summary\norders,units,gross,to_seller,platform_margin\n";
        $out .= "{$s['orders']},{$s['units']},{$s['gross']},{$s['to_seller']},{$s['platform_margin']}\n\n";
        $out .= "Stock movement\nproduct_id,from,to,when\n";
        foreach ($rep['stock_movement'] as $m) $out .= "{$m['product_id']},{$m['old_value']},{$m['new_value']},{$m['created_at']}\n";
        $out .= "\nPayouts\namount,method,status,reference,created,paid\n";
        foreach ($rep['payouts'] as $p) $out .= "{$p['amount']},{$p['method']},{$p['status']},{$p['reference']},{$p['created_at']},{$p['paid_at']}\n";
        return $out;
    }
}
