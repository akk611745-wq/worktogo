<?php

/**
 * BrainCore - FeedbackModel
 *
 * ACTION FEEDBACK LOOP
 *
 * ─── PURPOSE ─────────────────────────────────────────────────
 *
 *   Closes the loop between BrainCore's output and user behaviour.
 *   Every executed action gets a feedback row (default: ignored).
 *   When the user actually responds → row is updated to "clicked".
 *
 * ─── FLOW ────────────────────────────────────────────────────
 *
 *   1. ActionEngine::execute() completes
 *      → FeedbackModel::insert() called with action result
 *      → Inserts row: user_response = "ignored"
 *
 *   2. Client app detects user clicked a banner / popup / notification
 *      → POST /api/feedback { decision_id, action_id }
 *      → FeedbackModel::markClicked($actionId)
 *      → Updates row: user_response = "clicked"
 *
 * ─── WHY "ignored" IS THE DEFAULT ────────────────────────────
 *
 *   We cannot know if a user ignored something — they just do nothing.
 *   So we start pessimistic: assume ignored.
 *   Only an explicit click signal changes the record.
 *   This makes click rate stats accurate and conservative.
 *
 * ─── PUBLIC INTERFACE ────────────────────────────────────────
 *
 *   FeedbackModel::insert(string $decisionId, string $actionId,
 *                         string $clientId, string $actionType): string
 *     → Called by ActionEngine. Returns feedback UUID.
 *
 *   FeedbackModel::markClicked(string $actionId): bool
 *     → Called by FeedbackController. Returns true if row was updated.
 *
 *   FeedbackModel::getClickRate(string $clientId, string $actionType,
 *                               int $days = 30): array
 *     → Returns click rate stats. Ready for admin dashboard.
 */

class FeedbackModel
{
    // ─────────────────────────────────────────────────────────────
    // PUBLIC: Insert feedback row (called by ActionEngine)
    // ─────────────────────────────────────────────────────────────

    /**
     * Insert a new feedback record immediately after an action executes.
     *
     * Default user_response = "ignored" (conservative — we assume no engagement
     * until the client explicitly reports a click via markClicked()).
     *
     * @param  string $decisionId  UUID of the parent decision
     * @param  string $actionId    UUID from brain_actions (can be empty string if store failed)
     * @param  string $clientId    Platform identifier
     * @param  string $actionType  e.g. "show_popup", "notify_user"
     * @return string              UUID of the inserted feedback row
     */
    public static function insert(
        string $decisionId,
        string $actionId,
        string $clientId,
        string $actionType
    ): string {
        $db = getDB();
        $id = self::generateUuid();

        // action_id may be empty if ActionModel::store() failed — store null in that case
        $safeActionId = !empty($actionId) ? $actionId : null;

        $sql = "
            INSERT INTO brain_feedback
                (id, decision_id, action_id, client_id, action_type, user_response, created_at, updated_at)
            VALUES
                (:id, :decision_id, :action_id, :client_id, :action_type, 'ignored', NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                updated_at = updated_at
        ";
        // ON DUPLICATE KEY: action_id has a UNIQUE constraint.
        // If ActionEngine retries and calls insert() twice for the same action,
        // the second call is silently ignored (no duplicate error, no data change).

        $st = $db->prepare($sql);
        $st->execute([
            ':id'          => $id,
            ':decision_id' => $decisionId,
            ':action_id'   => $safeActionId,
            ':client_id'   => $clientId,
            ':action_type' => $actionType,
        ]);

        return $id;
    }

    // ─────────────────────────────────────────────────────────────
    // PUBLIC: Mark as clicked (called by FeedbackController)
    // ─────────────────────────────────────────────────────────────

    /**
     * Update a feedback row from "ignored" to "clicked".
     *
     * Called when the client app reports the user interacted.
     * Only updates if current value is "ignored" — prevents double-counting
     * if client sends the click signal more than once.
     *
     * @param  string $actionId  UUID of the brain_actions row
     * @return bool              true = updated, false = row not found / already clicked
     */
    public static function markClicked(string $actionId): bool
    {
        $db = getDB();

        $sql = "
            UPDATE brain_feedback
            SET    user_response = 'clicked',
                   updated_at   = NOW()
            WHERE  action_id     = :action_id
            AND    user_response = 'ignored'
        ";

        $st = $db->prepare($sql);
        $st->execute([':action_id' => $actionId]);

        return $st->rowCount() > 0;
    }

    // ─────────────────────────────────────────────────────────────
    // PUBLIC: Stats — click rate per action type
    // ─────────────────────────────────────────────────────────────

    /**
     * Calculate click rate for a client's action type over N days.
     *
     * Returns:
     *   total     → total actions fired in window
     *   clicked   → how many got a click response
     *   click_rate → percentage (0–100), rounded to 2 decimal places
     *
     * Used by: admin dashboard, future rule weight adjustments.
     *
     * @param  string $clientId
     * @param  string $actionType   e.g. "show_popup"
     * @param  int    $days         Look-back window in days (default 30)
     * @return array
     */
    public static function getClickRate(
        string $clientId,
        string $actionType,
        int    $days = 30
    ): array {
        $db = getDB();

        $sql = "
            SELECT
                COUNT(*)                                       AS total,
                SUM(user_response = 'clicked')                AS clicked
            FROM  brain_feedback
            WHERE client_id   = :client_id
            AND   action_type = :action_type
            AND   created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
        ";

        $st = $db->prepare($sql);
        $st->execute([
            ':client_id'   => $clientId,
            ':action_type' => $actionType,
            ':days'        => $days,
        ]);

        $row     = $st->fetch(PDO::FETCH_ASSOC);
        $total   = (int) ($row['total']   ?? 0);
        $clicked = (int) ($row['clicked'] ?? 0);

        return [
            'client_id'   => $clientId,
            'action_type' => $actionType,
            'days'        => $days,
            'total'       => $total,
            'clicked'     => $clicked,
            'click_rate'  => $total > 0
                               ? round(($clicked / $total) * 100, 2)
                               : 0.0,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // PRIVATE: UUID
    // ─────────────────────────────────────────────────────────────

    private static function generateUuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
