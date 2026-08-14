<?php
/**
 * PaymentReport — WorkToGo V2 Payment Room computation (READ-ONLY).
 *
 * Computes the admin revenue "control room" entirely from STRUCTURED data
 * (no text parsing): bookings (total, payment_status, payment_method,
 * category_id, city_id, expert_visit_fee/free, estimate/final_quote),
 * wallet_transactions (the LedgerEngine's platform-commission + vendor-earning
 * rows), and cities/categories/vendors for labels.
 *
 * IT NEVER MOVES MONEY. Only SELECTs — no INSERT/UPDATE/DELETE, no LedgerEngine
 * calls, no webhook/settlement side effects. It is the reporting mirror of the
 * ledger, not a second source of truth.
 *
 * SCHEMA-DEFENSIVE. Every V2 column it leans on is optional-checked against
 * information_schema and degrades gracefully: a missing column/table drops that
 * one metric (null / [] / 'unavailable') instead of erroring the whole room. So
 * it runs on a partially-migrated staging DB and gets richer as migrations land.
 *
 * MONEY MODEL (how each number is derived):
 *   collected        = SUM(bookings.total) WHERE payment_status='paid'  (real captured money)
 *   online_paid/pending/failed/refunded = same, sliced by payment_method='online' + status
 *   cod_value        = SUM(job value) WHERE payment_method='cod'        (off-platform cash GMV)
 *   gmv              = SUM(job value) over all bookings                 (money flowing through)
 *      job value     = COALESCE(final_quote_amount, estimate_amount, NULLIF(total,0), 0)
 *   commission_earned/vendor_credited/pending/settled = from wallet_transactions
 *      (entity_type platform|vendor, type credit|debit, status pending|settled|refunded),
 *      scoped to reference_type='booking' when that column exists (excludes dormant order rows).
 */
class PaymentReport
{
    /** @var array<string,bool> cached table existence */
    private static $tableCache = [];
    /** @var array<string,bool> cached column existence, keyed "table.col" */
    private static $colCache = [];

    // ── schema probes (cached per request) ────────────────────────────────────
    public static function hasTable(PDO $db, string $table): bool
    {
        if (array_key_exists($table, self::$tableCache)) return self::$tableCache[$table];
        try {
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
            );
            $stmt->execute([$table]);
            return self::$tableCache[$table] = ((int)$stmt->fetchColumn() > 0);
        } catch (\Throwable $e) {
            return self::$tableCache[$table] = false;
        }
    }

    public static function hasCol(PDO $db, string $table, string $col): bool
    {
        $k = $table . '.' . $col;
        if (array_key_exists($k, self::$colCache)) return self::$colCache[$k];
        try {
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
            );
            $stmt->execute([$table, $col]);
            return self::$colCache[$k] = ((int)$stmt->fetchColumn() > 0);
        } catch (\Throwable $e) {
            return self::$colCache[$k] = false;
        }
    }

    /** Map a period token to [label, fromDatetime|null]. */
    public static function periodRange(string $period): array
    {
        $now = new \DateTimeImmutable('now');
        switch ($period) {
            case 'today': return ['Today',            $now->setTime(0, 0)->format('Y-m-d H:i:s')];
            case '7d':    return ['Last 7 days',      $now->modify('-7 days')->format('Y-m-d H:i:s')];
            case '90d':   return ['Last 90 days',     $now->modify('-90 days')->format('Y-m-d H:i:s')];
            case 'month': return ['This month',       $now->modify('first day of this month')->setTime(0, 0)->format('Y-m-d H:i:s')];
            case 'year':  return ['This year',        $now->setDate((int)$now->format('Y'), 1, 1)->setTime(0, 0)->format('Y-m-d H:i:s')];
            case 'all':   return ['All time',         null];
            case '30d':
            default:      return ['Last 30 days',     $now->modify('-30 days')->format('Y-m-d H:i:s')];
        }
    }

    /** The COALESCE(...) SQL for a booking's job value, using whatever columns exist. */
    private static function valueExpr(PDO $db, string $alias = ''): string
    {
        $p = $alias !== '' ? $alias . '.' : '';
        $parts = [];
        if (self::hasCol($db, 'bookings', 'final_quote_amount')) $parts[] = "{$p}final_quote_amount";
        if (self::hasCol($db, 'bookings', 'estimate_amount'))    $parts[] = "{$p}estimate_amount";
        $parts[] = "NULLIF({$p}total, 0)";
        $parts[] = "0";
        return 'COALESCE(' . implode(', ', $parts) . ')';
    }

    // ══ THE ROOM ═════════════════════════════════════════════════════════════
    public static function room(PDO $db, string $period = '30d', ?int $cityId = null): array
    {
        [$periodLabel, $from] = self::periodRange($period);

        $hasCity = self::hasCol($db, 'bookings', 'city_id');
        $hasCat  = self::hasCol($db, 'bookings', 'category_id');
        $hasEV   = self::hasCol($db, 'bookings', 'expert_visit_fee') && self::hasCol($db, 'bookings', 'expert_visit_free');
        $hasWalletRef = self::hasTable($db, 'wallet_transactions') && self::hasCol($db, 'wallet_transactions', 'reference_type');
        $cityId  = ($hasCity && $cityId && $cityId > 0) ? $cityId : null;
        $valueExpr = self::valueExpr($db);

        // Shared WHERE for booking queries.
        $bWhere = []; $bBind = [];
        if ($from !== null) { $bWhere[] = 'created_at >= :from'; $bBind[':from'] = $from; }
        if ($cityId !== null) { $bWhere[] = 'city_id = :city'; $bBind[':city'] = $cityId; }
        $bWhereSql = $bWhere ? ('WHERE ' . implode(' AND ', $bWhere)) : '';

        // ── 1. Booking-side KPIs, online/cash split, expert visits ────────────
        $evSelect = '';
        if ($hasEV) {
            $evSelect =
              ", COALESCE(SUM(CASE WHEN expert_visit_free = 1 THEN 1 END), 0) AS ev_free_count
               , COALESCE(SUM(CASE WHEN (expert_visit_free = 0 OR expert_visit_free IS NULL) AND COALESCE(expert_visit_fee,0) > 0 THEN 1 END), 0) AS ev_paid_count
               , COALESCE(SUM(CASE WHEN COALESCE(expert_visit_fee,0) > 0 AND payment_status = 'paid' THEN expert_visit_fee END), 0) AS ev_fees_collected";
        }
        $sql = "SELECT
                  COUNT(*) AS bookings_count,
                  COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN total END), 0) AS collected,
                  COALESCE(SUM(CASE WHEN payment_method = 'online' AND payment_status = 'paid'     THEN total END), 0) AS online_paid,
                  COALESCE(SUM(CASE WHEN payment_method = 'online' AND payment_status = 'unpaid'   THEN total END), 0) AS online_pending,
                  COALESCE(SUM(CASE WHEN payment_method = 'online' AND payment_status = 'failed'   THEN total END), 0) AS online_failed,
                  COALESCE(SUM(CASE WHEN payment_status = 'refunded' THEN total END), 0) AS refunded,
                  COALESCE(SUM(CASE WHEN payment_method = 'cod' THEN {$valueExpr} END), 0) AS cod_value,
                  COALESCE(SUM({$valueExpr}), 0) AS gmv
                  {$evSelect}
                FROM bookings {$bWhereSql}";
        $kpi = self::fetchRow($db, $sql, $bBind);

        // ── 2. Company commission vs vendor payouts (the ledger) ──────────────
        $commission = self::commissionVsPayout($db, $from, $cityId, $hasWalletRef);

        // ── 3. Per-category P&L ───────────────────────────────────────────────
        $byCategory = $hasCat ? self::byDimension($db, 'category', $from, $cityId, $valueExpr, $hasWalletRef) : [];

        // ── 4. Per-city breakdown ─────────────────────────────────────────────
        $byCity = $hasCity ? self::byDimension($db, 'city', $from, null, $valueExpr, $hasWalletRef) : [];

        // ── 5. Top vendors (system gave X / earned Y) ─────────────────────────
        $topVendors = self::topVendors($db, $from, $cityId, $valueExpr, 10);

        // Derived, human-friendly totals.
        $collected   = (float)($kpi['collected'] ?? 0);
        $onlinePaid  = (float)($kpi['online_paid'] ?? 0);
        $codValue    = (float)($kpi['cod_value'] ?? 0);
        $onlinePend  = (float)($kpi['online_pending'] ?? 0);
        $revenuePic  = $onlinePaid + $codValue; // total money picture (collected online + cash GMV)
        $collRate    = ($onlinePaid + $onlinePend) > 0 ? round($onlinePaid / ($onlinePaid + $onlinePend) * 100, 1) : null;

        return [
            'period'      => ['token' => $period, 'label' => $periodLabel, 'from' => $from, 'city_id' => $cityId],
            'kpis'        => [
                'gmv'             => (float)($kpi['gmv'] ?? 0),
                'collected'       => $collected,
                'revenue_picture' => $revenuePic,
                'bookings_count'  => (int)($kpi['bookings_count'] ?? 0),
                'commission_earned' => (float)$commission['commission_earned'],
                'vendor_payouts'    => (float)$commission['vendor_credited'],
            ],
            'online_vs_cash' => [
                'online_paid' => $onlinePaid,
                'cod_value'   => $codValue,
                'total'       => $revenuePic,
                'online_pct'  => $revenuePic > 0 ? round($onlinePaid / $revenuePic * 100, 1) : 0,
                'cod_pct'     => $revenuePic > 0 ? round($codValue  / $revenuePic * 100, 1) : 0,
            ],
            'pending_vs_collected' => [
                'collected'       => $onlinePaid,
                'pending'         => $onlinePend,
                'failed'          => (float)($kpi['online_failed'] ?? 0),
                'refunded'        => (float)($kpi['refunded'] ?? 0),
                'collection_rate' => $collRate,
            ],
            'expert_visits' => $hasEV ? [
                'free_count'     => (int)($kpi['ev_free_count'] ?? 0),
                'paid_count'     => (int)($kpi['ev_paid_count'] ?? 0),
                'fees_collected' => (float)($kpi['ev_fees_collected'] ?? 0),
            ] : null,
            'commission_vs_payout' => $commission,
            'by_category' => $byCategory,
            'by_city'     => $byCity,
            'top_vendors' => $topVendors,
            'meta'        => [
                'has_city_dim'      => $hasCity,
                'has_category_dim'  => $hasCat,
                'has_expert_visit'  => $hasEV,
                'ledger_scoped'     => $hasWalletRef ? 'booking' : 'all',
                'read_only'         => true,
            ],
        ];
    }

    /** Company commission vs vendor payouts from wallet_transactions (ledger). */
    private static function commissionVsPayout(PDO $db, ?string $from, ?int $cityId, bool $hasWalletRef): array
    {
        $empty = [
            'commission_earned' => 0.0, 'commission_reversed' => 0.0, 'company_net' => 0.0,
            'vendor_credited' => 0.0, 'vendor_pending' => 0.0, 'vendor_settled' => 0.0,
            'wallet_pending_balance' => 0.0, 'wallet_available_balance' => 0.0,
        ];
        if (!self::hasTable($db, 'wallet_transactions')) return $empty;

        // City scoping needs a bookings join (ledger rows carry no city).
        $joinCity = ($cityId !== null && self::hasCol($db, 'bookings', 'city_id'));
        $bind = [];
        $where = []; $join = '';
        if ($from !== null) { $where[] = 'wt.created_at >= :from'; $bind[':from'] = $from; }
        if ($hasWalletRef) { $where[] = "wt.reference_type = 'booking'"; }
        if ($joinCity && $hasWalletRef) {
            $join = 'JOIN bookings b ON b.id = wt.reference_id';
            $where[] = 'b.city_id = :city'; $bind[':city'] = $cityId;
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $sql = "SELECT
                  COALESCE(SUM(CASE WHEN wt.entity_type='platform' AND wt.type='credit' THEN wt.amount END),0) AS commission_earned,
                  COALESCE(SUM(CASE WHEN wt.entity_type='platform' AND wt.type='debit'  THEN wt.amount END),0) AS commission_reversed,
                  COALESCE(SUM(CASE WHEN wt.entity_type='vendor'   AND wt.type='credit' THEN wt.amount END),0) AS vendor_credited,
                  COALESCE(SUM(CASE WHEN wt.entity_type='vendor'   AND wt.type='credit' AND wt.status='pending' THEN wt.amount END),0) AS vendor_pending,
                  COALESCE(SUM(CASE WHEN wt.entity_type='vendor'   AND wt.type='credit' AND wt.status='settled' THEN wt.amount END),0) AS vendor_settled
                FROM wallet_transactions wt {$join} {$whereSql}";
        $r = self::fetchRow($db, $sql, $bind);
        $out = [
            'commission_earned'   => (float)($r['commission_earned'] ?? 0),
            'commission_reversed' => (float)($r['commission_reversed'] ?? 0),
            'vendor_credited'     => (float)($r['vendor_credited'] ?? 0),
            'vendor_pending'      => (float)($r['vendor_pending'] ?? 0),
            'vendor_settled'      => (float)($r['vendor_settled'] ?? 0),
        ];
        $out['company_net'] = round($out['commission_earned'] - $out['commission_reversed'], 2);

        // Current standing across vendor wallets (not period-bound; a live snapshot).
        $out['wallet_pending_balance'] = 0.0; $out['wallet_available_balance'] = 0.0;
        if (self::hasTable($db, 'vendor_wallets')) {
            $w = self::fetchRow($db, "SELECT COALESCE(SUM(pending_balance),0) AS p, COALESCE(SUM(available_balance),0) AS a FROM vendor_wallets", []);
            $out['wallet_pending_balance']   = (float)($w['p'] ?? 0);
            $out['wallet_available_balance'] = (float)($w['a'] ?? 0);
        }
        return $out;
    }

    /**
     * Per-category or per-city breakdown: bookings + gmv + collected + commission.
     * $dim = 'category' | 'city'.
     */
    private static function byDimension(PDO $db, string $dim, ?string $from, ?int $cityId, string $valueExpr, bool $hasWalletRef): array
    {
        $col   = $dim === 'category' ? 'category_id' : 'city_id';
        $table = $dim === 'category' ? 'categories'  : 'cities';
        $nameOk = self::hasTable($db, $table);

        // Booking aggregates grouped by the dimension.
        $where = []; $bind = [];
        if ($from !== null)   { $where[] = 'b.created_at >= :from'; $bind[':from'] = $from; }
        if ($cityId !== null) { $where[] = 'b.city_id = :city';     $bind[':city'] = $cityId; }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $nameSel  = $nameOk ? 'd.name' : "CONCAT('#', b.{$col})";
        $join     = $nameOk ? "LEFT JOIN {$table} d ON d.id = b.{$col}" : '';
        $vexpr    = self::valueExpr($db, 'b');

        $sql = "SELECT b.{$col} AS dim_id, {$nameSel} AS dim_name,
                       COUNT(*) AS bookings,
                       COALESCE(SUM(CASE WHEN b.payment_status='paid' THEN b.total END),0) AS collected,
                       COALESCE(SUM({$vexpr}),0) AS gmv
                FROM bookings b {$join} {$whereSql}
                GROUP BY b.{$col}, dim_name
                ORDER BY gmv DESC";
        $rows = self::fetchAll($db, $sql, $bind);

        // Commission per dimension (join ledger -> bookings).
        $commByDim = [];
        if ($hasWalletRef) {
            $cw = []; $cb = [];
            if ($from !== null)   { $cw[] = 'wt.created_at >= :from'; $cb[':from'] = $from; }
            if ($cityId !== null) { $cw[] = 'b.city_id = :city';      $cb[':city'] = $cityId; }
            $cw[] = "wt.reference_type = 'booking'";
            $cw[] = "wt.entity_type = 'platform'";
            $cw[] = "wt.type = 'credit'";
            $cwSql = 'WHERE ' . implode(' AND ', $cw);
            $csql = "SELECT b.{$col} AS dim_id, COALESCE(SUM(wt.amount),0) AS commission
                     FROM wallet_transactions wt JOIN bookings b ON b.id = wt.reference_id
                     {$cwSql} GROUP BY b.{$col}";
            foreach (self::fetchAll($db, $csql, $cb) as $cr) {
                $commByDim[(string)$cr['dim_id']] = (float)$cr['commission'];
            }
        }

        $totalGmv = 0.0;
        foreach ($rows as $r) { $totalGmv += (float)$r['gmv']; }
        $out = [];
        foreach ($rows as $r) {
            $id = $r['dim_id'];
            $gmv = (float)$r['gmv'];
            $out[] = [
                'id'         => $id !== null ? (int)$id : null,
                'name'       => $r['dim_name'] ?? ('#' . $id),
                'bookings'   => (int)$r['bookings'],
                'collected'  => (float)$r['collected'],
                'gmv'        => $gmv,
                'commission' => $commByDim[(string)$id] ?? null,
                'share_pct'  => $totalGmv > 0 ? round($gmv / $totalGmv * 100, 1) : 0,
            ];
        }
        return $out;
    }

    /** Top vendors by earnings: jobs given/completed, job value, earned, pending. */
    private static function topVendors(PDO $db, ?string $from, ?int $cityId, string $valueExpr, int $limit = 10): array
    {
        if (!self::hasTable($db, 'vendors')) return [];
        $vexpr = self::valueExpr($db, 'b');
        $where = ['b.vendor_id IS NOT NULL', 'b.vendor_id > 0']; $bind = [];
        if ($from !== null)   { $where[] = 'b.created_at >= :from'; $bind[':from'] = $from; }
        if ($cityId !== null) { $where[] = 'b.city_id = :city';     $bind[':city'] = $cityId; }
        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $nameCol = self::hasCol($db, 'vendors', 'business_name') ? 'v.business_name' : "CONCAT('Vendor #', v.id)";
        $sql = "SELECT v.id AS vendor_id, {$nameCol} AS vendor_name,
                       COUNT(DISTINCT b.id) AS jobs_given,
                       COALESCE(SUM(CASE WHEN b.status='completed' THEN 1 END),0) AS jobs_completed,
                       COALESCE(SUM({$vexpr}),0) AS job_value
                FROM bookings b JOIN vendors v ON v.id = b.vendor_id
                {$whereSql}
                GROUP BY v.id, vendor_name
                ORDER BY job_value DESC
                LIMIT " . max(1, min(50, $limit));
        $rows = self::fetchAll($db, $sql, $bind);
        if (!$rows) return [];

        // Earnings per vendor from the ledger (period-bound).
        $earn = [];
        if (self::hasTable($db, 'wallet_transactions')) {
            $ew = ["entity_type = 'vendor'", "type = 'credit'"]; $eb = [];
            if ($from !== null) { $ew[] = 'created_at >= :from'; $eb[':from'] = $from; }
            $esql = "SELECT entity_id AS vendor_id,
                            COALESCE(SUM(amount),0) AS earned,
                            COALESCE(SUM(CASE WHEN status='pending' THEN amount END),0) AS pending
                     FROM wallet_transactions WHERE " . implode(' AND ', $ew) . " GROUP BY entity_id";
            foreach (self::fetchAll($db, $esql, $eb) as $er) {
                $earn[(string)$er['vendor_id']] = ['earned' => (float)$er['earned'], 'pending' => (float)$er['pending']];
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $vid = (string)$r['vendor_id'];
            $out[] = [
                'vendor_id'      => (int)$r['vendor_id'],
                'vendor_name'    => $r['vendor_name'],
                'jobs_given'     => (int)$r['jobs_given'],
                'jobs_completed' => (int)$r['jobs_completed'],
                'job_value'      => (float)$r['job_value'],
                'earned'         => $earn[$vid]['earned']  ?? null,
                'pending'        => $earn[$vid]['pending'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Single vendor's revenue history — "the system gave me X work, I earned Y".
     * Read-only; safe to later expose to the vendor themselves.
     */
    public static function vendorRevenue(PDO $db, int $vendorId, string $period = 'all'): array
    {
        [$periodLabel, $from] = self::periodRange($period);
        $out = [
            'vendor_id' => $vendorId,
            'period'    => ['token' => $period, 'label' => $periodLabel, 'from' => $from],
            'vendor_name' => null,
            'jobs_given' => 0, 'jobs_completed' => 0, 'job_value' => 0.0,
            'earned' => 0.0, 'pending' => 0.0, 'settled' => 0.0,
            'wallet' => ['pending_balance' => 0.0, 'available_balance' => 0.0],
            'recent' => [],
        ];
        if (self::hasTable($db, 'vendors')) {
            $vn = self::hasCol($db, 'vendors', 'business_name') ? 'business_name' : 'id';
            $v = self::fetchRow($db, "SELECT {$vn} AS name FROM vendors WHERE id = ? LIMIT 1", [$vendorId]);
            $out['vendor_name'] = $v['name'] ?? ('Vendor #' . $vendorId);
        }

        $vexpr = self::valueExpr($db, 'b');
        $where = ['b.vendor_id = :vid']; $bind = [':vid' => $vendorId];
        if ($from !== null) { $where[] = 'b.created_at >= :from'; $bind[':from'] = $from; }
        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $agg = self::fetchRow($db,
            "SELECT COUNT(*) AS jobs_given,
                    COALESCE(SUM(CASE WHEN b.status='completed' THEN 1 END),0) AS jobs_completed,
                    COALESCE(SUM({$vexpr}),0) AS job_value
             FROM bookings b {$whereSql}", $bind);
        $out['jobs_given']     = (int)($agg['jobs_given'] ?? 0);
        $out['jobs_completed'] = (int)($agg['jobs_completed'] ?? 0);
        $out['job_value']      = (float)($agg['job_value'] ?? 0);

        if (self::hasTable($db, 'wallet_transactions')) {
            $ew = ["entity_type = 'vendor'", "entity_id = :vid", "type = 'credit'"]; $eb = [':vid' => $vendorId];
            if ($from !== null) { $ew[] = 'created_at >= :from'; $eb[':from'] = $from; }
            $e = self::fetchRow($db,
                "SELECT COALESCE(SUM(amount),0) AS earned,
                        COALESCE(SUM(CASE WHEN status='pending' THEN amount END),0) AS pending,
                        COALESCE(SUM(CASE WHEN status='settled' THEN amount END),0) AS settled
                 FROM wallet_transactions WHERE " . implode(' AND ', $ew), $eb);
            $out['earned']  = (float)($e['earned'] ?? 0);
            $out['pending'] = (float)($e['pending'] ?? 0);
            $out['settled'] = (float)($e['settled'] ?? 0);
        }

        if (self::hasTable($db, 'vendor_wallets')) {
            $w = self::fetchRow($db, "SELECT pending_balance, available_balance FROM vendor_wallets WHERE vendor_id = ? LIMIT 1", [$vendorId]);
            if ($w) {
                $out['wallet']['pending_balance']   = (float)$w['pending_balance'];
                $out['wallet']['available_balance'] = (float)$w['available_balance'];
            }
        }

        // A few recent jobs for context.
        $numCol = self::hasCol($db, 'bookings', 'booking_number') ? 'booking_number' : 'id';
        $recent = self::fetchAll($db,
            "SELECT id, {$numCol} AS ref, status, payment_status, payment_method,
                    {$vexpr} AS value, created_at
             FROM bookings b WHERE b.vendor_id = ? ORDER BY created_at DESC LIMIT 10", [$vendorId]);
        foreach ($recent as $r) {
            $out['recent'][] = [
                'id' => (int)$r['id'], 'ref' => $r['ref'], 'status' => $r['status'],
                'payment_status' => $r['payment_status'], 'payment_method' => $r['payment_method'],
                'value' => (float)$r['value'], 'created_at' => $r['created_at'],
            ];
        }
        return $out;
    }

    // ── internal query helpers (fail-soft) ────────────────────────────────────
    private static function fetchRow(PDO $db, string $sql, array $bind): array
    {
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($bind);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[PaymentReport::fetchRow] ' . $e->getMessage());
            return [];
        }
    }

    private static function fetchAll(PDO $db, string $sql, array $bind): array
    {
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($bind);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[PaymentReport::fetchAll] ' . $e->getMessage());
            return [];
        }
    }
}
