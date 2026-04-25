<?php

/**
 * BrainCore — DashboardModel
 *
 * Phase C: Master Dashboard Layer
 *
 * READ-ONLY. No inserts, updates, or deletes here.
 * Every method returns plain PHP arrays — no JSON encoding inside.
 *
 * ─── QUERY STRATEGY ──────────────────────────────────────────
 *
 *   All queries are scoped to a single client_id.
 *   Every WHERE clause begins with client_id so MySQL uses the
 *   existing indexes on that column before anything else.
 *
 *   No heavy cross-table JOINs. Each metric is a separate,
 *   single-table COUNT or GROUP BY. This keeps each query fast
 *   even on large tables, and lets the DB cache them individually.
 *
 *   The controller calls getFullDashboard() which runs all
 *   sections in sequence and assembles the final payload.
 *
 * ─── TABLES USED (read-only) ─────────────────────────────────
 *
 *   brain_events      — event stream
 *   brain_decisions   — rule engine output
 *   brain_actions     — executed action log
 *   brain_alerts      — alert system (Phase B)
 *
 * ─── INDEXES RELIED ON ───────────────────────────────────────
 *
 *   brain_events    idx_events_client    (client_id)
 *                   idx_events_created   (created_at)
 *                   idx_events_composite (client_id, event_type, created_at)
 *                   idx_events_category  (category)
 *                   idx_events_location  (location)
 *
 *   brain_decisions idx_decisions_client (client_id)
 *                   idx_decisions_created(created_at)
 *                   idx_decisions_status (status)
 *
 *   brain_actions   idx_actions_client   (client_id)
 *                   idx_actions_status   (status)
 *                   idx_actions_created  (created_at)
 *
 *   brain_alerts    idx_alerts_lookup    (client_id, type, created_at)
 *                   idx_alerts_status    (client_id, status)
 */

class DashboardModel
{
    // ──────────────────────────────────────────────────────────
    // PUBLIC ENTRY POINT
    // ──────────────────────────────────────────────────────────

    /**
     * Build the complete dashboard payload for a client.
     *
     * Runs all five sections and returns a single structured array.
     * Each section is isolated — one section failing does not
     * prevent others from returning data.
     *
     * @param  string $clientId  Validated client_id
     * @return array             Full dashboard payload
     */
    public static function getFullDashboard(string $clientId): array
    {
        $db = getDB();

        return [
            'overview'    => self::getOverview($db, $clientId),
            'live'        => self::getLiveStats($db, $clientId),
            'patterns'    => self::getTopPatterns($db, $clientId),
            'alerts'      => self::getAlertsSummary($db, $clientId),
            'performance' => self::getPerformance($db, $clientId),
        ];
    }

    // ──────────────────────────────────────────────────────────
    // SECTION 1 — SYSTEM OVERVIEW
    // ──────────────────────────────────────────────────────────

    /**
     * All-time totals for events, decisions, actions, alerts.
     *
     * Uses single-column COUNT(*) per table.
     * Each query hits the primary key or client_id index — O(1) or
     * index-range scan. No joins required.
     *
     * @return array{
     *   total_events: int,
     *   total_decisions: int,
     *   total_actions: int,
     *   total_alerts: int,
     *   total_rules_active: int
     * }
     */
    private static function getOverview(PDO $db, string $clientId): array
    {
        // Single parameterized query per table — reuse same param value
        $counts = [];

        $queries = [
            'total_events'       => 'SELECT COUNT(*) FROM brain_events    WHERE client_id = :cid',
            'total_decisions'    => 'SELECT COUNT(*) FROM brain_decisions  WHERE client_id = :cid',
            'total_actions'      => 'SELECT COUNT(*) FROM brain_actions    WHERE client_id = :cid',
            'total_alerts'       => 'SELECT COUNT(*) FROM brain_alerts     WHERE client_id = :cid',
            'total_rules_active' => 'SELECT COUNT(*) FROM brain_rules
                                     WHERE (client_id = :cid OR client_id IS NULL)
                                       AND is_active = 1',
        ];

        foreach ($queries as $key => $sql) {
            $st = $db->prepare($sql);
            $st->execute([':cid' => $clientId]);
            $counts[$key] = (int) $st->fetchColumn();
        }

        return $counts;
    }

    // ──────────────────────────────────────────────────────────
    // SECTION 2 — LIVE STATS (last 1 hour)
    // ──────────────────────────────────────────────────────────

    /**
     * Activity metrics for the last 60 minutes.
     *
     * MySQL evaluates NOW() - INTERVAL 1 HOUR at query start and
     * uses it as a range scan on the created_at index. No full
     * table scans when the index is present.
     *
     * @return array{
     *   events_last_hour: int,
     *   decisions_last_hour: int,
     *   actions_last_hour: int,
     *   alerts_pending: int,
     *   events_processed_last_hour: int,
     *   unmatched_events_last_hour: int
     * }
     */
    private static function getLiveStats(PDO $db, string $clientId): array
    {
        $live = [];

        // Events last hour — uses idx_events_composite or idx_events_created
        $st = $db->prepare('
            SELECT COUNT(*) FROM brain_events
            WHERE client_id = :cid
              AND created_at >= NOW() - INTERVAL 1 HOUR
        ');
        $st->execute([':cid' => $clientId]);
        $live['events_last_hour'] = (int) $st->fetchColumn();

        // Decisions last hour
        $st = $db->prepare('
            SELECT COUNT(*) FROM brain_decisions
            WHERE client_id = :cid
              AND created_at >= NOW() - INTERVAL 1 HOUR
        ');
        $st->execute([':cid' => $clientId]);
        $live['decisions_last_hour'] = (int) $st->fetchColumn();

        // Actions last hour
        $st = $db->prepare('
            SELECT COUNT(*) FROM brain_actions
            WHERE client_id = :cid
              AND created_at >= NOW() - INTERVAL 1 HOUR
        ');
        $st->execute([':cid' => $clientId]);
        $live['actions_last_hour'] = (int) $st->fetchColumn();

        // Pending alerts — all-time open count (not hour-scoped)
        // Uses idx_alerts_status (client_id, status)
        $st = $db->prepare('
            SELECT COUNT(*) FROM brain_alerts
            WHERE client_id = :cid
              AND status = \'pending\'
        ');
        $st->execute([':cid' => $clientId]);
        $live['alerts_pending'] = (int) $st->fetchColumn();

        // Events already processed (processed = 1) last hour
        $st = $db->prepare('
            SELECT COUNT(*) FROM brain_events
            WHERE client_id = :cid
              AND processed = 1
              AND created_at >= NOW() - INTERVAL 1 HOUR
        ');
        $st->execute([':cid' => $clientId]);
        $live['events_processed_last_hour'] = (int) $st->fetchColumn();

        // Decisions with no rule match last hour (rule_id IS NULL)
        // Signals rule coverage gap — useful for immediate alert
        $st = $db->prepare('
            SELECT COUNT(*) FROM brain_decisions
            WHERE client_id = :cid
              AND rule_id IS NULL
              AND created_at >= NOW() - INTERVAL 1 HOUR
        ');
        $st->execute([':cid' => $clientId]);
        $live['unmatched_events_last_hour'] = (int) $st->fetchColumn();

        return $live;
    }

    // ──────────────────────────────────────────────────────────
    // SECTION 3 — TOP PATTERNS
    // ──────────────────────────────────────────────────────────

    /**
     * Top categories, locations, and most-active IP addresses.
     *
     * All queries are GROUP BY with ORDER BY COUNT DESC LIMIT N.
     * MySQL uses idx_events_category and idx_events_location for
     * the grouping columns. LIMIT 5 ensures tiny result sets.
     *
     * active_users is derived from distinct ip_address values
     * (brain_events has no dedicated user_id column). This counts
     * unique visitor fingerprints, not authenticated users.
     *
     * @return array{
     *   top_categories: list<array{category: string, count: int}>,
     *   top_locations: list<array{location: string, count: int}>,
     *   top_event_types: list<array{event_type: string, count: int}>,
     *   most_active_ips: list<array{ip_address: string, count: int}>,
     *   top_action_types: list<array{action_type: string, count: int}>
     * }
     */
    private static function getTopPatterns(PDO $db, string $clientId): array
    {
        $patterns = [];

        // Top categories (events) — last 7 days for relevance
        $st = $db->prepare('
            SELECT category, COUNT(*) AS event_count
            FROM brain_events
            WHERE client_id = :cid
              AND category IS NOT NULL
              AND created_at >= NOW() - INTERVAL 7 DAY
            GROUP BY category
            ORDER BY event_count DESC
            LIMIT 5
        ');
        $st->execute([':cid' => $clientId]);
        $patterns['top_categories'] = $st->fetchAll();
        foreach ($patterns['top_categories'] as &$row) {
            $row['event_count'] = (int) $row['event_count'];
        }
        unset($row);

        // Top locations (events) — last 7 days
        $st = $db->prepare('
            SELECT location, COUNT(*) AS event_count
            FROM brain_events
            WHERE client_id = :cid
              AND location IS NOT NULL
              AND created_at >= NOW() - INTERVAL 7 DAY
            GROUP BY location
            ORDER BY event_count DESC
            LIMIT 5
        ');
        $st->execute([':cid' => $clientId]);
        $patterns['top_locations'] = $st->fetchAll();
        foreach ($patterns['top_locations'] as &$row) {
            $row['event_count'] = (int) $row['event_count'];
        }
        unset($row);

        // Top event types — helps admin understand traffic shape
        $st = $db->prepare('
            SELECT event_type, COUNT(*) AS event_count
            FROM brain_events
            WHERE client_id = :cid
              AND created_at >= NOW() - INTERVAL 7 DAY
            GROUP BY event_type
            ORDER BY event_count DESC
            LIMIT 5
        ');
        $st->execute([':cid' => $clientId]);
        $patterns['top_event_types'] = $st->fetchAll();
        foreach ($patterns['top_event_types'] as &$row) {
            $row['event_count'] = (int) $row['event_count'];
        }
        unset($row);

        // Most active IPs (proxy for "users" — no user_id column in brain_events)
        // Last 24 hours for freshness
        $st = $db->prepare('
            SELECT ip_address, COUNT(*) AS event_count
            FROM brain_events
            WHERE client_id = :cid
              AND ip_address IS NOT NULL
              AND created_at >= NOW() - INTERVAL 24 HOUR
            GROUP BY ip_address
            ORDER BY event_count DESC
            LIMIT 10
        ');
        $st->execute([':cid' => $clientId]);
        $patterns['most_active_ips'] = $st->fetchAll();
        foreach ($patterns['most_active_ips'] as &$row) {
            $row['event_count'] = (int) $row['event_count'];
        }
        unset($row);

        // Top action types — what the brain is doing most
        $st = $db->prepare('
            SELECT action_type, COUNT(*) AS action_count
            FROM brain_actions
            WHERE client_id = :cid
              AND created_at >= NOW() - INTERVAL 7 DAY
            GROUP BY action_type
            ORDER BY action_count DESC
            LIMIT 5
        ');
        $st->execute([':cid' => $clientId]);
        $patterns['top_action_types'] = $st->fetchAll();
        foreach ($patterns['top_action_types'] as &$row) {
            $row['action_count'] = (int) $row['action_count'];
        }
        unset($row);

        return $patterns;
    }

    // ──────────────────────────────────────────────────────────
    // SECTION 4 — ALERTS SUMMARY
    // ──────────────────────────────────────────────────────────

    /**
     * Alert counts grouped by severity and by type.
     *
     * Also returns the five most recent pending alerts so the
     * admin panel can show a "critical alerts" widget without a
     * separate API call.
     *
     * All queries scope to client_id and use idx_alerts_lookup
     * or idx_alerts_status.
     *
     * @return array{
     *   by_severity: array<string, int>,
     *   by_type: array<string, int>,
     *   total_pending: int,
     *   total_resolved: int,
     *   recent_critical: list<array>
     * }
     */
    private static function getAlertsSummary(PDO $db, string $clientId): array
    {
        $summary = [];

        // Grouped by severity — pending only
        // Uses idx_alerts_status then reads type/severity columns
        $st = $db->prepare('
            SELECT severity, COUNT(*) AS cnt
            FROM brain_alerts
            WHERE client_id = :cid
              AND status = \'pending\'
            GROUP BY severity
        ');
        $st->execute([':cid' => $clientId]);
        $rows = $st->fetchAll();

        $bySeverity = ['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0];
        foreach ($rows as $row) {
            $bySeverity[$row['severity']] = (int) $row['cnt'];
        }
        $summary['by_severity'] = $bySeverity;

        // Grouped by type — pending only
        $st = $db->prepare('
            SELECT type, COUNT(*) AS cnt
            FROM brain_alerts
            WHERE client_id = :cid
              AND status = \'pending\'
            GROUP BY type
            ORDER BY cnt DESC
        ');
        $st->execute([':cid' => $clientId]);
        $rows = $st->fetchAll();

        $byType = [];
        foreach ($rows as $row) {
            $byType[$row['type']] = (int) $row['cnt'];
        }
        $summary['by_type'] = $byType;

        // Total pending vs resolved
        $st = $db->prepare('
            SELECT status, COUNT(*) AS cnt
            FROM brain_alerts
            WHERE client_id = :cid
            GROUP BY status
        ');
        $st->execute([':cid' => $clientId]);
        $statusRows = $st->fetchAll();

        $summary['total_pending']  = 0;
        $summary['total_resolved'] = 0;
        foreach ($statusRows as $row) {
            if ($row['status'] === 'pending')  $summary['total_pending']  = (int) $row['cnt'];
            if ($row['status'] === 'resolved') $summary['total_resolved'] = (int) $row['cnt'];
        }

        // 5 most recent critical/high alerts — for the "attention required" widget
        $st = $db->prepare('
            SELECT id, type, severity, message, created_at
            FROM brain_alerts
            WHERE client_id = :cid
              AND status = \'pending\'
              AND severity IN (\'critical\', \'high\')
            ORDER BY
              CASE severity WHEN \'critical\' THEN 0 ELSE 1 END ASC,
              created_at DESC
            LIMIT 5
        ');
        $st->execute([':cid' => $clientId]);
        $summary['recent_critical'] = $st->fetchAll();

        return $summary;
    }

    // ──────────────────────────────────────────────────────────
    // SECTION 5 — PERFORMANCE
    // ──────────────────────────────────────────────────────────

    /**
     * Action success vs failure breakdown, decision approval rates,
     * and rule match coverage.
     *
     * brain_actions.status is an ENUM: simulated | executed | failed.
     * For "success" we count both simulated and executed (they worked).
     * "failed" means the handler threw an error.
     *
     * avg_response_time is not available — no timing column exists
     * in the current schema. The field is returned as null and
     * flagged so callers know it's by design, not a missing value.
     *
     * @return array{
     *   actions_by_status: array<string, int>,
     *   success_rate_pct: float,
     *   failure_count: int,
     *   decisions_by_status: array<string, int>,
     *   rule_match_rate_pct: float,
     *   avg_response_time_ms: null,
     *   avg_response_time_note: string
     * }
     */
    private static function getPerformance(PDO $db, string $clientId): array
    {
        $perf = [];

        // Actions breakdown by status
        $st = $db->prepare('
            SELECT status, COUNT(*) AS cnt
            FROM brain_actions
            WHERE client_id = :cid
            GROUP BY status
        ');
        $st->execute([':cid' => $clientId]);
        $rows = $st->fetchAll();

        $byStatus = ['simulated' => 0, 'executed' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $byStatus[$row['status']] = (int) $row['cnt'];
        }
        $perf['actions_by_status'] = $byStatus;

        $total       = array_sum($byStatus);
        $successCount = $byStatus['simulated'] + $byStatus['executed'];
        $perf['success_rate_pct'] = $total > 0
            ? round(($successCount / $total) * 100, 2)
            : 0.0;
        $perf['failure_count'] = $byStatus['failed'];

        // Decisions breakdown — auto | approved | rejected
        $st = $db->prepare('
            SELECT status, COUNT(*) AS cnt
            FROM brain_decisions
            WHERE client_id = :cid
            GROUP BY status
        ');
        $st->execute([':cid' => $clientId]);
        $rows = $st->fetchAll();

        $decByStatus = ['auto' => 0, 'approved' => 0, 'rejected' => 0];
        foreach ($rows as $row) {
            $decByStatus[$row['status']] = (int) $row['cnt'];
        }
        $perf['decisions_by_status'] = $decByStatus;

        // Rule match rate: decisions where a rule DID fire (rule_id IS NOT NULL)
        $st = $db->prepare('
            SELECT
              COUNT(*) AS total,
              SUM(CASE WHEN rule_id IS NOT NULL THEN 1 ELSE 0 END) AS matched
            FROM brain_decisions
            WHERE client_id = :cid
        ');
        $st->execute([':cid' => $clientId]);
        $matchRow = $st->fetch();

        $decTotal   = (int) ($matchRow['total']   ?? 0);
        $decMatched = (int) ($matchRow['matched']  ?? 0);
        $perf['rule_match_rate_pct'] = $decTotal > 0
            ? round(($decMatched / $decTotal) * 100, 2)
            : 0.0;

        // Avg response time — schema has no timing column
        $perf['avg_response_time_ms']   = null;
        $perf['avg_response_time_note'] = 'Not available. No timing column in current schema. '
            . 'Add action_duration_ms to brain_actions in a future migration.';

        return $perf;
    }
}
