<?php

/**
 * BrainCore - AlertModel
 *
 * Storage layer for the brain_alerts table.
 * Handles insert, deduplication, resolution, and read queries.
 *
 * ─── TABLE: brain_alerts ─────────────────────────────────────
 *
 *   id           CHAR(36)   UUID
 *   client_id    VARCHAR(64)
 *   type         VARCHAR(64)   delivery_delay / high_demand_location /
 *                              action_failure / system_anomaly / system_error
 *   severity     ENUM          low / medium / high / critical
 *   message      TEXT          Human-readable description
 *   context_json JSON          Full context payload for debugging
 *   status       ENUM          pending / resolved
 *   created_at   DATETIME
 *   resolved_at  DATETIME      NULL until resolved
 *
 * ─── DEDUPLICATION ───────────────────────────────────────────
 *
 *   Same (client_id + type + severity) within DEDUP_MINUTES will
 *   NOT create a duplicate alert row. This prevents alert storms
 *   from a single repeated failure flooding the table.
 *
 * ─── INDEXES ─────────────────────────────────────────────────
 *
 *   idx_alerts_lookup    (client_id, type, created_at)  — primary query index
 *   idx_alerts_status    (client_id, status)             — open alert queries
 */

class AlertModel
{
    /** Suppress duplicate (client+type+severity) alerts within this window. */
    const DEDUP_MINUTES = 15;

    // ──────────────────────────────────────────────────────────
    // WRITE
    // ──────────────────────────────────────────────────────────

    /**
     * Create a new alert, unless a duplicate was recently fired.
     *
     * Returns the new alert UUID, or null if deduplicated (suppressed).
     * Never throws — caller is always non-blocking.
     *
     * @param  string $clientId
     * @param  string $type      snake_case alert type constant
     * @param  string $severity  low | medium | high | critical
     * @param  string $message   Human-readable description
     * @param  array  $context   Any relevant payload for debugging
     * @return string|null       UUID of new alert, or null if suppressed
     */
    public static function create(
        string $clientId,
        string $type,
        string $severity,
        string $message,
        array  $context = []
    ): ?string {
        try {
            if (self::recentlyFired($clientId, $type, $severity)) {
                return null; // Deduplicated — suppress silently
            }

            $db  = getDB();
            $id  = self::generateUuid();

            $st = $db->prepare("
                INSERT INTO brain_alerts
                    (id, client_id, type, severity, message, context_json, status, created_at)
                VALUES
                    (:id, :client_id, :type, :severity, :message, :context_json, 'pending', NOW())
            ");

            $st->execute([
                ':id'           => $id,
                ':client_id'    => $clientId,
                ':type'         => $type,
                ':severity'     => $severity,
                ':message'      => substr($message, 0, 1000), // Guard oversized messages
                ':context_json' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            return $id;

        } catch (\Throwable $e) {
            error_log('[AlertModel::create] ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Mark an alert as resolved.
     *
     * @param  string $alertId  UUID of the alert to resolve
     * @return bool             true if a row was updated
     */
    public static function resolve(string $alertId): bool
    {
        try {
            $db = getDB();
            $st = $db->prepare("
                UPDATE brain_alerts
                SET    status = 'resolved', resolved_at = NOW()
                WHERE  id     = :id
                  AND  status = 'pending'
            ");
            $st->execute([':id' => $alertId]);
            return $st->rowCount() > 0;
        } catch (\Throwable $e) {
            error_log('[AlertModel::resolve] ' . $e->getMessage());
            return false;
        }
    }

    // ──────────────────────────────────────────────────────────
    // READ
    // ──────────────────────────────────────────────────────────

    /**
     * Get recent alerts for a client, newest first.
     *
     * Uses idx_alerts_lookup (client_id, type, created_at).
     *
     * @param  string      $clientId
     * @param  string|null $status    pending | resolved | null (all)
     * @param  string|null $type      Filter to a specific alert type
     * @param  int         $limit
     * @return array
     */
    public static function getRecent(
        string  $clientId,
        ?string $status = null,
        ?string $type   = null,
        int     $limit  = 50
    ): array {
        try {
            $db     = getDB();
            $where  = ['client_id = :client_id'];
            $params = [':client_id' => $clientId];

            if ($status !== null) {
                $where[]          = 'status = :status';
                $params[':status'] = $status;
            }

            if ($type !== null) {
                $where[]        = 'type = :type';
                $params[':type'] = $type;
            }

            $sql = "
                SELECT id, client_id, type, severity, message, context_json,
                       status, created_at, resolved_at
                FROM   brain_alerts
                WHERE  " . implode(' AND ', $where) . "
                ORDER  BY created_at DESC
                LIMIT  :limit
            ";

            $st = $db->prepare($sql);
            foreach ($params as $k => $v) {
                $st->bindValue($k, $v);
            }
            $st->bindValue(':limit', $limit, PDO::PARAM_INT);
            $st->execute();

            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            // Decode context_json for each row
            foreach ($rows as &$row) {
                $row['context'] = json_decode($row['context_json'] ?? '{}', true) ?? [];
                unset($row['context_json']);
            }

            return $rows;

        } catch (\Throwable $e) {
            error_log('[AlertModel::getRecent] ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Severity-grouped counts for the admin summary endpoint.
     *
     * @param  string $clientId
     * @return array{total: int, pending: int, by_severity: array, by_type: array}
     */
    public static function getSummary(string $clientId): array
    {
        try {
            $db = getDB();

            // Total + pending
            $totals = $db->prepare("
                SELECT
                    COUNT(*)                   AS total,
                    SUM(status = 'pending')    AS pending,
                    SUM(status = 'resolved')   AS resolved
                FROM  brain_alerts
                WHERE client_id = :client_id
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $totals->execute([':client_id' => $clientId]);
            $t = $totals->fetch(PDO::FETCH_ASSOC);

            // Grouped by severity (pending only)
            $bySeverity = $db->prepare("
                SELECT severity, COUNT(*) AS count
                FROM   brain_alerts
                WHERE  client_id = :client_id
                  AND  status    = 'pending'
                GROUP  BY severity
            ");
            $bySeverity->execute([':client_id' => $clientId]);
            $severityRows = $bySeverity->fetchAll(PDO::FETCH_ASSOC);

            $severityMap = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
            foreach ($severityRows as $row) {
                $severityMap[$row['severity']] = (int) $row['count'];
            }

            // Grouped by type (pending only)
            $byType = $db->prepare("
                SELECT type, COUNT(*) AS count
                FROM   brain_alerts
                WHERE  client_id = :client_id
                  AND  status    = 'pending'
                GROUP  BY type
                ORDER  BY count DESC
            ");
            $byType->execute([':client_id' => $clientId]);
            $typeRows = $byType->fetchAll(PDO::FETCH_ASSOC);

            $typeMap = [];
            foreach ($typeRows as $row) {
                $typeMap[$row['type']] = (int) $row['count'];
            }

            return [
                'last_24h_total' => (int) ($t['total']    ?? 0),
                'pending'        => (int) ($t['pending']  ?? 0),
                'resolved'       => (int) ($t['resolved'] ?? 0),
                'by_severity'    => $severityMap,
                'by_type'        => $typeMap,
            ];

        } catch (\Throwable $e) {
            error_log('[AlertModel::getSummary] ' . $e->getMessage());
            return [
                'last_24h_total' => 0, 'pending' => 0, 'resolved' => 0,
                'by_severity' => [], 'by_type' => [],
            ];
        }
    }

    // ──────────────────────────────────────────────────────────
    // PRIVATE
    // ──────────────────────────────────────────────────────────

    /**
     * Returns true if an identical (client+type+severity) alert already
     * exists in pending state within the dedup window.
     */
    private static function recentlyFired(
        string $clientId,
        string $type,
        string $severity
    ): bool {
        $db = getDB();
        $st = $db->prepare("
            SELECT COUNT(*) AS cnt
            FROM   brain_alerts
            WHERE  client_id  = :client_id
              AND  type       = :type
              AND  severity   = :severity
              AND  status     = 'pending'
              AND  created_at >= DATE_SUB(NOW(), INTERVAL :mins MINUTE)
        ");
        $st->execute([
            ':client_id' => $clientId,
            ':type'      => $type,
            ':severity'  => $severity,
            ':mins'      => self::DEDUP_MINUTES,
        ]);
        return ((int) $st->fetch(PDO::FETCH_ASSOC)['cnt']) > 0;
    }

    private static function generateUuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
