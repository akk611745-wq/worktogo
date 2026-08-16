<?php
/**
 * VendorAccountability — WorkToGo V2 bypass / non-completion auto-flag (READ-ONLY
 * detection). Computes per-vendor outcome signals from EXISTING data and applies
 * a fully threshold-driven flag rule so admin sees who to stop giving work to,
 * without hunting manually (V2-VISION §5).
 *
 * It NEVER changes assignment/booking logic and writes no outcome rows — signals
 * are DERIVED (no drift):
 *   - jobs             -> completed / cancelled / stalled (assigned but not
 *                         completed past a threshold) = the non-completion signal
 *   - auto_assignments -> accepted / rejected / timeout = the take-the-offer
 *                         (bypass/avoidance) signal
 *   - vendors.manual_flag (optional col) -> admin force-flag
 *
 * Every threshold is read from app_settings (migration 2026_08_15_001), so the
 * rule is ZERO-hardcoded and admin-tunable; missing settings fall back to the
 * defaults below, and missing tables/columns degrade to empty rather than error.
 *
 * The pure evaluate() rule engine is separated from the SQL so it is trivially
 * testable and identical for the single-vendor and all-vendors paths.
 */
class VendorAccountability
{
    /** Code defaults — overridden by app_settings `vendor_flag_*` rows. */
    const DEFAULTS = [
        'window_days'         => 30,  // lookback window
        'stall_hours'         => 72,  // unfinished job older than this = stalled
        'min_assigned'        => 5,   // min jobs before rate-based flagging
        'noncompletion_count' => 3,   // stalled jobs that trigger a flag
        'min_completion_rate' => 50,  // completion % below -> flag
        'min_offered'         => 5,   // min offers before decline-rate flagging
        'max_reject_rate'     => 60,  // decline/ignore % above -> flag
    ];

    /** @var array<string,bool> cached table/column existence */
    private static $has = [];

    // ── schema probes ─────────────────────────────────────────────────────────
    public static function hasTable(PDO $db, string $t): bool
    {
        $k = 'T:' . $t;
        if (isset(self::$has[$k])) return self::$has[$k];
        try {
            $s = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
            $s->execute([$t]);
            return self::$has[$k] = ((int)$s->fetchColumn() > 0);
        } catch (\Throwable $e) { return self::$has[$k] = false; }
    }

    public static function hasColumn(PDO $db, string $t, string $c): bool
    {
        $k = 'C:' . $t . '.' . $c;
        if (isset(self::$has[$k])) return self::$has[$k];
        try {
            $s = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $s->execute([$t, $c]);
            return self::$has[$k] = ((int)$s->fetchColumn() > 0);
        } catch (\Throwable $e) { return self::$has[$k] = false; }
    }

    /** Resolve thresholds (app_settings overrides defaults), all ints. */
    public static function thresholds(PDO $db): array
    {
        $t = self::DEFAULTS;
        try {
            $rows = $db->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE 'vendor_flag_%'")
                       ->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($t as $short => $_) {
                $key = 'vendor_flag_' . $short;
                if (isset($rows[$key]) && is_numeric($rows[$key])) {
                    $t[$short] = (int)$rows[$key];
                }
            }
        } catch (\Throwable $e) { /* settings table absent -> defaults */ }
        return $t;
    }

    /**
     * Pure rule engine: given a vendor's raw signal counts + thresholds, decide
     * flagged / severity / risk_score and the human-readable reasons (the "why").
     * No DB — unit-testable and shared by both query paths.
     */
    public static function evaluate(array $s, array $t): array
    {
        $assigned  = (int)($s['jobs_assigned']  ?? 0);
        $completed = (int)($s['jobs_completed'] ?? 0);
        $cancelled = (int)($s['jobs_cancelled'] ?? 0);
        $stalled   = (int)($s['jobs_stalled']   ?? 0);
        $offered   = (int)($s['offered']  ?? 0);
        $rejected  = (int)($s['rejected'] ?? 0);
        $timeout   = (int)($s['timeout']  ?? 0);
        $manual    = !empty($s['manual_flag']);

        $completionRate = $assigned > 0 ? (int)round($completed / $assigned * 100) : null;
        $declined       = $rejected + $timeout;
        $declineRate    = $offered  > 0 ? (int)round($declined / $offered * 100) : null;

        $reasons = []; $score = 0;

        // Rule 1 — non-completion: jobs assigned but never finished (stalled).
        if ($stalled >= $t['noncompletion_count']) {
            $reasons[] = [
                'code'   => 'non_completion',
                'label'  => "{$stalled} job" . ($stalled === 1 ? '' : 's') . " assigned but not completed",
                'detail' => "≥ {$t['noncompletion_count']} unfinished > {$t['stall_hours']}h in {$t['window_days']}d",
            ];
            $score += 40 + min(30, ($stalled - $t['noncompletion_count']) * 10);
        }
        // Rule 2 — low completion rate (only once there's a meaningful sample).
        if ($assigned >= $t['min_assigned'] && $completionRate !== null && $completionRate < $t['min_completion_rate']) {
            $reasons[] = [
                'code'   => 'low_completion',
                'label'  => "Completion rate {$completionRate}%",
                'detail' => "below {$t['min_completion_rate']}% over {$assigned} jobs",
            ];
            $score += min(40, $t['min_completion_rate'] - $completionRate);
        }
        // Rule 3 — declines/ignores too many offers (bypass/avoidance).
        if ($offered >= $t['min_offered'] && $declineRate !== null && $declineRate > $t['max_reject_rate']) {
            $reasons[] = [
                'code'   => 'high_decline',
                'label'  => "Declined/ignored {$declineRate}% of offers",
                'detail' => "{$declined} of {$offered} offers (rejected/timed out)",
            ];
            $score += min(30, $declineRate - $t['max_reject_rate']);
        }
        // Rule 4 — admin force-flag.
        if ($manual) {
            $reasons[] = [
                'code'   => 'manual',
                'label'  => 'Manually flagged by admin',
                'detail' => trim((string)($s['flag_notes'] ?? '')) ?: 'admin review',
            ];
            $score += 50;
        }

        $flagged  = count($reasons) > 0;
        $score    = min(100, $score);
        $severity = !$flagged ? 'none'
            : (($score >= 70 || count($reasons) >= 3) ? 'critical'
            : (($score >= 40 || count($reasons) >= 2) ? 'high' : 'medium'));

        return [
            'flagged'         => $flagged,
            'severity'        => $severity,
            'risk_score'      => $score,
            'reasons'         => $reasons,
            'metrics'         => [
                'jobs_assigned'   => $assigned,
                'jobs_completed'  => $completed,
                'jobs_cancelled'  => $cancelled,
                'jobs_stalled'    => $stalled,
                'completion_rate' => $completionRate,
                'offered'         => $offered,
                'accepted'        => (int)($s['accepted'] ?? 0),
                'declined'        => $declined,
                'decline_rate'    => $declineRate,
            ],
        ];
    }

    // ── raw per-vendor signal aggregation (windowed) ──────────────────────────
    /**
     * @param int|null $vendorId one vendor, or null for all
     * @return array<int,array> keyed by vendor_id -> raw signal counts
     */
    private static function rawSignals(PDO $db, array $t, ?int $vendorId): array
    {
        $now         = new \DateTimeImmutable('now');
        $windowFrom  = $now->modify('-' . max(1, (int)$t['window_days']) . ' days')->format('Y-m-d H:i:s');
        $stallCutoff = $now->modify('-' . max(1, (int)$t['stall_hours']) . ' hours')->format('Y-m-d H:i:s');
        $out = [];

        // jobs-derived signals
        if (self::hasTable($db, 'jobs')) {
            $sql = "SELECT vendor_id,
                       COUNT(*) AS jobs_assigned,
                       SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS jobs_completed,
                       SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS jobs_cancelled,
                       SUM(CASE WHEN status NOT IN ('completed','cancelled')
                                 AND completed_at IS NULL
                                 AND COALESCE(assignment_lock_time, started_at, created_at) < :stall
                                THEN 1 ELSE 0 END) AS jobs_stalled
                    FROM jobs
                   WHERE vendor_id IS NOT NULL AND vendor_id > 0 AND created_at >= :from";
            $bind = [':stall' => $stallCutoff, ':from' => $windowFrom];
            if ($vendorId !== null) { $sql .= " AND vendor_id = :vid"; $bind[':vid'] = $vendorId; }
            $sql .= " GROUP BY vendor_id";
            foreach (self::fetchAll($db, $sql, $bind) as $r) {
                $vid = (int)$r['vendor_id'];
                $out[$vid] = array_merge($out[$vid] ?? [], [
                    'jobs_assigned'  => (int)$r['jobs_assigned'],
                    'jobs_completed' => (int)$r['jobs_completed'],
                    'jobs_cancelled' => (int)$r['jobs_cancelled'],
                    'jobs_stalled'   => (int)$r['jobs_stalled'],
                ]);
            }
        }

        // auto_assignments-derived signals (offer acceptance behaviour)
        if (self::hasTable($db, 'auto_assignments')) {
            $sql = "SELECT vendor_id,
                       COUNT(*) AS offered,
                       SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) AS accepted,
                       SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected,
                       SUM(CASE WHEN status = 'timeout'  THEN 1 ELSE 0 END) AS timeout
                    FROM auto_assignments
                   WHERE vendor_id IS NOT NULL AND vendor_id > 0
                     AND entity_type = 'job' AND assigned_at >= :from";
            $bind = [':from' => $windowFrom];
            if ($vendorId !== null) { $sql .= " AND vendor_id = :vid"; $bind[':vid'] = $vendorId; }
            $sql .= " GROUP BY vendor_id";
            foreach (self::fetchAll($db, $sql, $bind) as $r) {
                $vid = (int)$r['vendor_id'];
                $out[$vid] = array_merge($out[$vid] ?? [], [
                    'offered'  => (int)$r['offered'],
                    'accepted' => (int)$r['accepted'],
                    'rejected' => (int)$r['rejected'],
                    'timeout'  => (int)$r['timeout'],
                ]);
            }
        }

        return $out;
    }

    /** The admin-decision columns for a set of vendors (or all), if present. */
    private static function decisionRows(PDO $db, ?int $vendorId): array
    {
        if (!self::hasTable($db, 'vendors')) return [];
        $cols = ['id', 'business_name'];
        foreach (['manual_flag', 'flag_reviewed_at', 'flag_notes', 'work_throttle'] as $c) {
            if (self::hasColumn($db, 'vendors', $c)) $cols[] = $c; else $cols[] = "NULL AS {$c}";
        }
        $sql  = "SELECT " . implode(', ', $cols) . " FROM vendors";
        $bind = [];
        if ($vendorId !== null) { $sql .= " WHERE id = :vid"; $bind[':vid'] = $vendorId; }
        $rows = [];
        foreach (self::fetchAll($db, $sql, $bind) as $r) { $rows[(int)$r['id']] = $r; }
        return $rows;
    }

    /** Full accountability record for one vendor. */
    public static function forVendor(PDO $db, int $vendorId): array
    {
        $t    = self::thresholds($db);
        $sig  = self::rawSignals($db, $t, $vendorId)[$vendorId] ?? [];
        $dec  = self::decisionRows($db, $vendorId)[$vendorId] ?? [];
        $sig['manual_flag'] = $dec['manual_flag'] ?? null;
        $sig['flag_notes']  = $dec['flag_notes']  ?? null;
        $eval = self::evaluate($sig, $t);
        $eval['vendor_id']     = $vendorId;
        $eval['vendor_name']   = $dec['business_name'] ?? ('Vendor #' . $vendorId);
        $eval['work_throttle'] = $dec['work_throttle'] ?? null;
        $eval['reviewed_at']   = $dec['flag_reviewed_at'] ?? null;
        $eval['thresholds']    = $t;
        return $eval;
    }

    /**
     * All currently-flagged vendors, worst-first. Returns a summary + list; each
     * entry carries its reasons, metrics and admin-decision state.
     */
    public static function flaggedList(PDO $db, int $limit = 100): array
    {
        $t    = self::thresholds($db);
        $sigs = self::rawSignals($db, $t, null);
        $dec  = self::decisionRows($db, null);

        // Include vendors that ONLY have a manual flag (no recent jobs).
        foreach ($dec as $vid => $d) {
            if (!empty($d['manual_flag']) && !isset($sigs[$vid])) { $sigs[$vid] = []; }
        }

        $flagged = [];
        foreach ($sigs as $vid => $sig) {
            $d = $dec[$vid] ?? [];
            $sig['manual_flag'] = $d['manual_flag'] ?? null;
            $sig['flag_notes']  = $d['flag_notes']  ?? null;
            $eval = self::evaluate($sig, $t);
            if (!$eval['flagged']) continue;
            $eval['vendor_id']     = (int)$vid;
            $eval['vendor_name']   = $d['business_name'] ?? ('Vendor #' . $vid);
            $eval['work_throttle'] = $d['work_throttle'] ?? null;
            $eval['reviewed_at']   = $d['flag_reviewed_at'] ?? null;
            $flagged[] = $eval;
        }

        usort($flagged, function ($a, $b) {
            if ($b['risk_score'] !== $a['risk_score']) return $b['risk_score'] <=> $a['risk_score'];
            return count($b['reasons']) <=> count($a['reasons']);
        });

        $unreviewed = 0;
        foreach ($flagged as $f) { if (empty($f['reviewed_at'])) $unreviewed++; }

        return [
            'summary'    => [
                'flagged_count'    => count($flagged),
                'unreviewed_count' => $unreviewed,
                'evaluated'        => count($sigs),
            ],
            'thresholds' => $t,
            'vendors'    => array_slice($flagged, 0, max(1, $limit)),
        ];
    }

    private static function fetchAll(PDO $db, string $sql, array $bind): array
    {
        try {
            $s = $db->prepare($sql);
            $s->execute($bind);
            return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[VendorAccountability] ' . $e->getMessage());
            return [];
        }
    }
}
