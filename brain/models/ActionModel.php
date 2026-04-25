<?php

/**
 * BrainCore v3 — ActionModel
 *
 * ─── v3 CHANGES ──────────────────────────────────────────────
 *
 *   Added throttle_fp column storage.
 *   When storing an action, the fingerprint used by ActionThrottle
 *   is now written to the dedicated throttle_fp column (CHAR 8).
 *   This enables the indexed lookup in ActionThrottle v3.
 *
 *   The throttle_fp is computed and passed in by ActionEngine.
 *   ActionModel just stores it.
 */

class ActionModel
{
    /**
     * Store a triggered action.
     *
     * @param  string      $decisionId
     * @param  string      $clientId
     * @param  string      $actionType
     * @param  array       $actionData
     * @param  string      $status
     * @param  string|null $note
     * @param  string|null $throttleFp   8-char dedup fingerprint from ActionThrottle
     * @return string  UUID of stored row
     */
    public static function store(
        string  $decisionId,
        string  $clientId,
        string  $actionType,
        array   $actionData,
        string  $status    = 'simulated',
        ?string $note      = null,
        ?string $throttleFp = null
    ): string {
        $db = getDB();
        $id = self::generateUuid();

        $sql = "
            INSERT INTO brain_actions
                (id, decision_id, client_id, action_type, action_data,
                 status, note, throttle_fp, created_at)
            VALUES
                (:id, :decision_id, :client_id, :action_type, :action_data,
                 :status, :note, :throttle_fp, NOW())
        ";

        $st = $db->prepare($sql);
        $st->execute([
            ':id'          => $id,
            ':decision_id' => $decisionId,
            ':client_id'   => $clientId,
            ':action_type' => $actionType,
            ':action_data' => json_encode($actionData, JSON_UNESCAPED_UNICODE),
            ':status'      => $status,
            ':note'        => $note,
            ':throttle_fp' => $throttleFp,
        ]);

        return $id;
    }

    /**
     * Get recent actions for a client.
     */
    public static function getRecent(string $clientId, int $limit = 50): array
    {
        $db  = getDB();
        $sql = "
            SELECT id, decision_id, client_id, action_type,
                   action_data, status, note, created_at
            FROM   brain_actions
            WHERE  client_id = :client_id
            ORDER  BY created_at DESC
            LIMIT  :limit
        ";

        $st = $db->prepare($sql);
        $st->execute([':client_id' => $clientId, ':limit' => $limit]);
        $rows = $st->fetchAll();

        foreach ($rows as &$row) {
            if (!empty($row['action_data'])) {
                $row['action_data'] = json_decode($row['action_data'], true);
            }
        }

        return $rows;
    }

    private static function generateUuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
