<?php
/**
 * DemandSignals.php — WorkToGo Agent Layer :: demand-detection (Phase 9)
 * ----------------------------------------------------------------------------
 * "The system tells us where to grow." The agent is the only thing that sees a
 * user ASK for something and get FEW or NO results — a booking can't record a
 * category with zero vendors, but a search can. This class captures those asks
 * and, on a schedule, turns them into ADMIN GROWTH SIGNALS: "N users asked for
 * {category} in this city but only {supply} vendors show up — add supply here."
 *
 * Two responsibilities, both entirely OUR-system-side (no PII to any AI):
 *   • record()  — called by the orchestrator after a real search/browse read. It
 *                 stores market metadata only: scope, category, INTERNAL city id,
 *                 optional area text, the engine's result_count (a live supply
 *                 proxy), and the INTERNAL user_id (for distinct-user counting —
 *                 it never leaves the system). Fail-open: never breaks a turn.
 *   • detect()  — called by the demand cron. It aggregates recent events per
 *                 (scope, category, city), upserts agent_demand_signals, and when
 *                 demand is high AND supply is low it raises an admin alert through
 *                 the injected notifier (the existing FCM/alerts mechanism), with a
 *                 cooldown so it never spams. The external AI is never involved.
 *
 * The notifier is injected so the cron wires the real FcmNotifier + alerts row and
 * the self-test injects a mock — DemandSignals itself has no FCM dependency.
 */

declare(strict_types=1);

final class DemandSignals
{
    private const DEFAULT_THRESHOLDS = [
        'window_days'        => 14,
        'min_asks'           => 5,
        'min_distinct_users' => 3,
        'max_supply'         => 2,
        'cooldown_hours'     => 168, // 7 days between repeat alerts for the same signal
    ];

    public function __construct(private readonly ?PDO $db = null) {}

    // ── Capture ──────────────────────────────────────────────────────────────────

    /**
     * Record one demand event (a real ask the agent served). Metadata only — no PII.
     * Fail-open: any error is swallowed so a user turn is never broken.
     */
    public function record(
        string $scope,
        string $categoryKey,
        int $cityId,
        ?string $area,
        int $resultCount,
        ?int $userId
    ): void {
        if (!$this->db instanceof PDO) {
            return;
        }
        $categoryKey = mb_substr(trim($categoryKey), 0, 80);
        if ($categoryKey === '') {
            return;
        }
        $scope = in_array($scope, ['service', 'commerce'], true) ? $scope : 'service';
        try {
            $this->db->prepare(
                "INSERT INTO agent_demand_events (scope, category_key, city_id, area, result_count, user_id, created_at)
                 VALUES (:scope, :cat, :city, :area, :rc, :uid, NOW())"
            )->execute([
                ':scope' => $scope,
                ':cat'   => $categoryKey,
                ':city'  => max(0, $cityId),
                ':area'  => $area !== null && $area !== '' ? mb_substr($area, 0, 120) : null,
                ':rc'    => max(0, $resultCount),
                ':uid'   => $userId,
            ]);
        } catch (\Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warning('agent.demand record skipped', ['error' => $e->getMessage()]);
            }
        }
    }

    // ── Detect (cron) ────────────────────────────────────────────────────────────

    /**
     * Aggregate recent events into growth signals, upsert them, and raise an admin
     * alert for each newly-qualifying (high-demand, low-supply) signal.
     *
     * @param array    $thresholds override the app_settings thresholds (tests/config)
     * @param callable $notifyAdmin fn(array $signal): void — raises the admin alert
     *                              (the cron wires FcmNotifier::notifyRole + alerts)
     * @return array{groups:int,upserted:int,alerted:int}
     */
    public function detect(array $thresholds, callable $notifyAdmin): array
    {
        $t = array_merge(self::DEFAULT_THRESHOLDS, $thresholds);
        $summary = ['groups' => 0, 'upserted' => 0, 'alerted' => 0];
        if (!$this->db instanceof PDO) {
            return $summary;
        }

        $window = (int) $t['window_days'];
        $groups = $this->aggregate($window);
        $summary['groups'] = count($groups);

        foreach ($groups as $g) {
            $signal = $this->upsert($g);
            if ($signal === null) {
                continue;
            }
            $summary['upserted']++;

            $qualifies = $signal['ask_count'] >= (int) $t['min_asks']
                && $signal['distinct_users'] >= (int) $t['min_distinct_users']
                && $signal['supply_estimate'] <= (int) $t['max_supply'];

            if ($qualifies && $this->shouldAlert($signal, (int) $t['cooldown_hours'])) {
                try {
                    $notifyAdmin($signal);
                    $this->markAlerted((int) $signal['id']);
                    $summary['alerted']++;
                } catch (\Throwable $e) {
                    if (class_exists('Logger')) {
                        Logger::warning('agent.demand alert failed', ['error' => $e->getMessage()]);
                    }
                }
            }
        }

        return $summary;
    }

    /** Aggregate raw events in the window per (scope, category, city). */
    private function aggregate(int $windowDays): array
    {
        $windowDays = max(1, min(365, $windowDays));
        try {
            $stmt = $this->db->prepare(
                "SELECT scope, category_key, city_id,
                        COUNT(*)                    AS ask_count,
                        COUNT(DISTINCT user_id)     AS distinct_users,
                        MIN(result_count)           AS supply_estimate,
                        SUBSTRING_INDEX(GROUP_CONCAT(area ORDER BY created_at DESC SEPARATOR '\\n'), '\\n', 1) AS area
                   FROM agent_demand_events
                  WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$windowDays} DAY)
                  GROUP BY scope, category_key, city_id"
            );
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Upsert one aggregated group into agent_demand_signals; returns the fresh row. */
    private function upsert(array $g): ?array
    {
        try {
            $this->db->prepare(
                "INSERT INTO agent_demand_signals
                    (scope, category_key, city_id, area, ask_count, distinct_users, supply_estimate,
                     status, first_seen_at, last_seen_at)
                 VALUES (:scope, :cat, :city, :area, :asks, :users, :supply, 'open', NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    area            = VALUES(area),
                    ask_count       = VALUES(ask_count),
                    distinct_users  = VALUES(distinct_users),
                    supply_estimate = VALUES(supply_estimate),
                    last_seen_at    = NOW()"
            )->execute([
                ':scope'  => (string) $g['scope'],
                ':cat'    => (string) $g['category_key'],
                ':city'   => (int) $g['city_id'],
                ':area'   => ($g['area'] ?? '') !== '' ? mb_substr((string) $g['area'], 0, 120) : null,
                ':asks'   => (int) $g['ask_count'],
                ':users'  => (int) $g['distinct_users'],
                ':supply' => (int) $g['supply_estimate'],
            ]);

            $sel = $this->db->prepare(
                "SELECT id, scope, category_key, city_id, area, ask_count, distinct_users,
                        supply_estimate, status, alerted_at
                   FROM agent_demand_signals
                  WHERE scope = :scope AND category_key = :cat AND city_id = :city
                  LIMIT 1"
            );
            $sel->execute([':scope' => (string) $g['scope'], ':cat' => (string) $g['category_key'], ':city' => (int) $g['city_id']]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            // Normalise numeric types for the caller.
            foreach (['id', 'city_id', 'ask_count', 'distinct_users', 'supply_estimate'] as $k) {
                $row[$k] = (int) $row[$k];
            }
            return $row;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Alert only if never alerted, or the cooldown has elapsed since the last alert. */
    private function shouldAlert(array $signal, int $cooldownHours): bool
    {
        if (($signal['status'] ?? 'open') !== 'alerted' || empty($signal['alerted_at'])) {
            return true;
        }
        $last = strtotime((string) $signal['alerted_at']);
        if ($last === false) {
            return true;
        }
        return (time() - $last) >= max(1, $cooldownHours) * 3600;
    }

    private function markAlerted(int $id): void
    {
        try {
            $this->db->prepare(
                "UPDATE agent_demand_signals SET status = 'alerted', alerted_at = NOW() WHERE id = :id"
            )->execute([':id' => $id]);
        } catch (\Throwable $e) { /* best-effort */ }
    }
}
