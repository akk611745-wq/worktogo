<?php

/**
 * BrainCore - ImprovementModel
 *
 * The self-learning layer. Every failure the system encounters
 * is captured here — not silently dropped.
 *
 * ─── WHY THIS EXISTS ─────────────────────────────────────────
 * When the brain doesn't know what to do, it should say so.
 * Admin reviews these entries, writes suggestions, and converts
 * them into real rules. Over time, the gap list shrinks.
 *
 * ─── TWO FAILURE TYPES ───────────────────────────────────────
 *
 * 1. no_rule_match
 *    A valid request came in but zero rules matched the context.
 *    Action: admin writes a new rule for this pattern.
 *
 * 2. invalid_rule
 *    A rule exists but its condition_exp could not be parsed.
 *    Action: admin fixes the condition syntax of that rule.
 *    rule_id is always stored so admin knows which rule to fix.
 *
 * ─── DEDUPLICATION ───────────────────────────────────────────
 * Same (client_id + reason + fingerprint) within the last hour
 * is NOT stored again. Prevents spam from repeated requests
 * that all hit the same gap or the same broken rule.
 */

class ImprovementModel
{
    /**
     * Store a new improvement entry.
     *
     * @param  string      $clientId
     * @param  array       $context
     * @param  string      $reason        "no_rule_match" | "invalid_rule"
     * @param  string|null $ruleId
     * @param  string|null $detail
     * @return string|null  UUID of stored entry, or null if deduplicated
     */
    public static function storeImprovement(
        string  $clientId,
        array   $context,
        string  $reason,
        ?string $ruleId  = null,
        ?string $detail  = null
    ): ?string {
        // ── Deduplication check (M5 FIX) ──────────────────────
        // Fingerprint is now stored in its own indexed CHAR(32) column.
        // Replaces the previous full-table LIKE '%fingerprint%' scan.
        // Query now hits the idx_improv_fingerprint index directly.
        $fingerprint = md5($clientId . $reason . ($ruleId ?? '') . json_encode($context));

        if (self::recentlyLogged($clientId, $reason, $fingerprint)) {
            return null;
        }

        // ── Build event_data snapshot ──────────────────────────
        $eventData = array_filter([
            'context' => $context,
            'detail'  => $detail,
        ]);

        // ── Insert ─────────────────────────────────────────────
        $db  = getDB();
        $id  = self::generateUuid();

        $sql = "
            INSERT INTO brain_improvements
                (id, client_id, event_data, fingerprint, reason, rule_id, status, created_at)
            VALUES
                (:id, :client_id, :event_data, :fingerprint, :reason, :rule_id, 'pending', NOW())
        ";

        $st = $db->prepare($sql);
        $st->execute([
            ':id'          => $id,
            ':client_id'   => $clientId,
            ':event_data'  => json_encode($eventData, JSON_UNESCAPED_UNICODE),
            ':fingerprint' => $fingerprint,
            ':reason'      => $reason,
            ':rule_id'     => $ruleId,
        ]);

        return $id;
    }

    /**
     * Get all pending improvements, newest first.
     *
     * Used by the admin endpoint to show what needs attention.
     *
     * @param  string|null $clientId  Optional — filter by client
     * @param  int         $limit     Max results to return
     * @return array
     */
    public static function getPendingImprovements(?string $clientId = null, int $limit = 50): array
    {
        $db = getDB();

        $where  = ["i.status = 'pending'"];
        $params = [];

        if ($clientId !== null) {
            $where[]           = 'i.client_id = :client_id';
            $params[':client_id'] = $clientId;
        }

        $whereStr = implode(' AND ', $where);

        // Join brain_rules to show rule name when reason = invalid_rule
        $sql = "
            SELECT
                i.id,
                i.client_id,
                i.reason,
                i.event_data,
                i.rule_id,
                r.name      AS rule_name,
                i.suggestion,
                i.status,
                i.created_at
            FROM  brain_improvements i
            LEFT  JOIN brain_rules r ON r.id = i.rule_id
            WHERE $whereStr
            ORDER BY i.created_at DESC
            LIMIT :limit
        ";

        $params[':limit'] = $limit;

        $st = $db->prepare($sql);
        $st->execute($params);

        $rows = $st->fetchAll();

        // Decode event_data JSON for each row
        foreach ($rows as &$row) {
            if (!empty($row['event_data'])) {
                $row['event_data'] = json_decode($row['event_data'], true);
            }
        }

        return $rows;
    }

    // ──────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────────────────

    /**
     * Check if this exact gap was already logged in the last hour.
     *
     * M5 FIX: Now queries the indexed fingerprint CHAR(32) column directly.
     * Previous implementation used LIKE '%fingerprint%' on the JSON blob —
     * a full table scan that grows unbounded with traffic.
     * This query hits idx_improv_fingerprint (client_id, fingerprint, created_at).
     *
     * @param  string $clientId
     * @param  string $reason
     * @param  string $fingerprint  md5(context+reason+ruleId)
     * @return bool
     */
    private static function recentlyLogged(
        string $clientId,
        string $reason,
        string $fingerprint
    ): bool {
        $db  = getDB();
        $sql = "
            SELECT id
            FROM   brain_improvements
            WHERE  client_id   = :client_id
              AND  reason      = :reason
              AND  fingerprint = :fingerprint
              AND  created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            LIMIT  1
        ";

        $st = $db->prepare($sql);
        $st->execute([
            ':client_id'   => $clientId,
            ':reason'      => $reason,
            ':fingerprint' => $fingerprint,
        ]);

        return (bool) $st->fetch();
    }

    /**
     * Generate a UUID v4.
     */
    private static function generateUuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
