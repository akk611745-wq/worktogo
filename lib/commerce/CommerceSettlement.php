<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — CommerceSettlement  (S9)
//  commerce-system-blueprint §P. Thin seam over LedgerEngine's wallets:
//    • settleEligible()  — move vendor pending → available, but ONLY earnings
//      older than the return-hold window (so a return can still claw back).
//    • runPayout()       — turn available balances into actual PAYOUT records
//      (bank/UPI, weekly cadence driven by ops/cron) + a debit for the record.
//    • accountingSplit() — OWN (company stores) vs FACILITATED (vendor stores)
//      gross + margin, kept separate for the legal/tax tracks.
//  All windows/methods are DB-config. LedgerEngine + wallets are reused, not edited.
// ─────────────────────────────────────────────────────────────────────────────

class CommerceSettlement
{
    private static function setting(PDO $db, string $key, $def)
    {
        try { $s = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1"); $s->execute([$key]); $v = $s->fetchColumn(); return $v === false ? $def : $v; }
        catch (\Throwable $e) { return $def; }
    }
    public static function returnHoldHours(PDO $db): int { return (int)self::setting($db, 'commerce_return_hold_hours', 48); }

    /**
     * Settle vendor earnings that are past the return-hold window: pending →
     * available. Earnings younger than the hold stay pending (a return can reverse
     * them). Uses wallet_transactions.created_at as the earned-at time (TZ-safe NOW()).
     * @return array{settled_count:int,total:float}
     */
    public static function settleEligible(PDO $db): array
    {
        $hold = self::returnHoldHours($db);
        $db->beginTransaction();
        try {
            $sel = $db->prepare(
                "SELECT id, entity_id, amount FROM wallet_transactions
                  WHERE entity_type='vendor' AND status='pending'
                    AND created_at < (NOW() - INTERVAL ? HOUR)
                  FOR UPDATE"
            );
            $sel->execute([$hold]);
            $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
            $move = $db->prepare("UPDATE vendor_wallets SET pending_balance=GREATEST(0,pending_balance-?), available_balance=available_balance+? WHERE vendor_id=?");
            $mark = $db->prepare("UPDATE wallet_transactions SET status='settled' WHERE id=?");
            $total = 0.0;
            foreach ($rows as $t) { $a = (float)$t['amount']; $move->execute([$a, $a, (int)$t['entity_id']]); $mark->execute([(int)$t['id']]); $total += $a; }
            $db->commit();
            return ['settled_count' => count($rows), 'total' => round($total, 2)];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return ['settled_count' => 0, 'total' => 0.0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Pay out available vendor balances: one commerce_payouts record per vendor,
     * available_balance → 0, plus a wallet debit for the trail. Weekly cadence is
     * the caller's (ops/cron); this does the batch.
     * @return array list of {vendor_id, amount, payout_id}
     */
    public static function runPayout(PDO $db, string $method = 'bank', ?int $adminId = null): array
    {
        $allowed = explode(',', (string)self::setting($db, 'commerce_payout_methods', 'bank,upi'));
        if (!in_array($method, array_map('trim', $allowed), true)) $method = 'bank';
        $out = [];
        $db->beginTransaction();
        try {
            $vs = $db->query("SELECT vendor_id, available_balance FROM vendor_wallets WHERE available_balance > 0 FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
            $ins = $db->prepare("INSERT INTO commerce_payouts (payee_type, payee_id, amount, method, status, period_end, created_at) VALUES ('vendor',?,?,?,'initiated',NOW(),NOW())");
            foreach ($vs as $v) {
                $amt = round((float)$v['available_balance'], 2);
                if ($amt <= 0) continue;
                $ins->execute([(int)$v['vendor_id'], $amt, $method]);
                $payoutId = (int)$db->lastInsertId();
                $db->prepare("UPDATE vendor_wallets SET available_balance = available_balance - ? WHERE vendor_id=?")->execute([$amt, (int)$v['vendor_id']]);
                $out[] = ['vendor_id' => (int)$v['vendor_id'], 'amount' => $amt, 'payout_id' => $payoutId];
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
        }
        return $out;
    }

    /** Mark a payout actually paid (finance confirms the bank/UPI transfer). */
    public static function markPayoutPaid(PDO $db, int $payoutId, ?string $reference = null): bool
    {
        $u = $db->prepare("UPDATE commerce_payouts SET status='paid', reference=?, paid_at=NOW() WHERE id=? AND status='initiated'");
        $u->execute([$reference, $payoutId]);
        return $u->rowCount() > 0;
    }

    /**
     * OWN (company stores) vs FACILITATED (vendor stores) split for delivered
     * sub-orders in the window — kept separate for the legal/tax tracks.
     * margin: company sub = full subtotal; vendor sub = subtotal − seller_subtotal.
     */
    public static function accountingSplit(PDO $db, int $days = 30): array
    {
        $sql = "SELECT s.ownership AS own, COUNT(*) AS n,
                       COALESCE(SUM(o.subtotal),0) AS gross,
                       COALESCE(SUM(CASE WHEN s.ownership='company' THEN o.subtotal
                                         ELSE o.subtotal - COALESCE(o.seller_subtotal, o.subtotal) END),0) AS margin
                  FROM orders o
                  JOIN stores s ON s.id = o.store_id
                 WHERE o.parent_order_id IS NOT NULL
                   AND o.created_at >= (NOW() - INTERVAL ? DAY)
                 GROUP BY s.ownership";
        $st = $db->prepare($sql); $st->execute([$days]);
        $res = ['own' => ['orders' => 0, 'gross' => 0.0, 'margin' => 0.0], 'facilitated' => ['orders' => 0, 'gross' => 0.0, 'margin' => 0.0]];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $bucket = ($r['own'] === 'company') ? 'own' : 'facilitated';
            $res[$bucket] = ['orders' => (int)$r['n'], 'gross' => round((float)$r['gross'], 2), 'margin' => round((float)$r['margin'], 2)];
        }
        return $res;
    }
}
