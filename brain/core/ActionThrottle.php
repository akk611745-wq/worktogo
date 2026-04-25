<?php

/**
 * BrainCore v3 — ActionThrottle
 *
 * ─── v3 CHANGES ──────────────────────────────────────────────
 *
 *   PERFORMANCE: Replaced LIKE '%fingerprint%' full-table scan
 *   with a direct indexed lookup on the throttle_fp column.
 *
 *   BEFORE (v2): action_data LIKE '%abc12345%'
 *     → Full scan of the longtext column. O(n) where n = table rows.
 *     → Fatal at 10,000+ rows.
 *
 *   AFTER (v3): throttle_fp = 'abc12345'
 *     → Hits idx_throttle index on (client_id, action_type, throttle_fp, created_at).
 *     → O(log n). Fast even at 1M+ rows.
 *
 *   REQUIRES: brain_actions.throttle_fp CHAR(8) column (added in
 *   migration 006_adaptive_upgrade.sql). ActionModel::store()
 *   must be called with the fingerprint (see ActionModel v3).
 */

class ActionThrottle
{
    /** Throttle window in seconds */
    private const WINDOW_SECONDS = 10;

    /**
     * Check if this action should be throttled.
     *
     * @param  string $clientId
     * @param  string $actionType
     * @param  array  $context    Keys: location, category
     * @return bool   true = throttled (skip), false = proceed
     */
    public static function isThrottled(
        string $clientId,
        string $actionType,
        array  $context
    ): bool {
        // log_only and none are never throttled
        if (in_array($actionType, ['log_only', 'none'])) {
            return false;
        }

        $fingerprint = self::makeFingerprint($clientId, $actionType, $context);
        $cutoff      = date('Y-m-d H:i:s', time() - self::WINDOW_SECONDS);

        $db  = getDB();
        $sql = "
            SELECT id
            FROM   brain_actions
            WHERE  client_id    = :client_id
              AND  action_type  = :action_type
              AND  throttle_fp  = :fp
              AND  created_at  >= :cutoff
            LIMIT  1
        ";

        $st = $db->prepare($sql);
        $st->execute([
            ':client_id'   => $clientId,
            ':action_type' => $actionType,
            ':fp'          => $fingerprint,
            ':cutoff'      => $cutoff,
        ]);

        return (bool) $st->fetch();
    }

    /**
     * Generate an 8-char dedup fingerprint.
     * Stored in brain_actions.throttle_fp for indexed lookup.
     */
    public static function makeFingerprint(
        string $clientId,
        string $actionType,
        array  $context
    ): string {
        $key = implode('|', [
            $clientId,
            $actionType,
            strtolower($context['location'] ?? ''),
            strtolower($context['category'] ?? ''),
        ]);
        return substr(md5($key), 0, 8);
    }
}
