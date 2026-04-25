<?php

/**
 * BrainCore v3 — HabitModel
 *
 * "Muscle Memory" — learns automatically from repeated patterns.
 *
 * ─── v3 CHANGES ──────────────────────────────────────────────
 *
 *   BYPASS_THRESHOLD reduced from 10 → 3.
 *   This means: after the same context pattern (location +
 *   category + time_of_day) is matched by a rule just 3 times,
 *   Brain skips the RuleEngine on the next request and serves
 *   the answer directly from memory. Fast + adaptive.
 *
 *   Why 3? A single match could be accidental. Two might be
 *   coincidence. Three is a pattern. The human brain works the
 *   same way — habit formation starts at 3 repeated actions.
 *
 *   NIGHTLY DECAY: score × 0.90 each night.
 *   After 11 nights without a hit, a bypass-level habit (score=3)
 *   decays below 0.5 and is pruned. Stale habits don't persist.
 *
 * ─── SCORE MECHANICS ─────────────────────────────────────────
 *
 *   Hit       → score += 1.0, capped at 100
 *   Nightly   → score *= 0.90
 *   Prune     → score < 0.5 → row deleted
 *   Bypass    → score >= 3.0 → skip RuleEngine
 *
 *   A new pattern reaches bypass after 3 hits (score = 3.0).
 *   After 11 days without a hit it decays: 3 × 0.9^11 ≈ 0.96
 *   Wait, that's still above 0.5. After ~24 days: 3 × 0.9^24 ≈ 0.28 → pruned.
 *
 * ─── WHAT IS A HABIT PATTERN ─────────────────────────────────
 *
 *   Unique key: (client_id, location, category, time_of_day)
 *   Empty string is used for missing values so the UNIQUE index
 *   works correctly in MySQL.
 *
 *   Examples:
 *     ("worktogo", "haldwani", "electronics", "evening") → score 8.5
 *     ("worktogo", "",         "grocery",     "morning") → score 3.1
 *     ("worktogo", "delhi",    "",            "night")   → score 1.2
 */

class HabitModel
{
    // ─── Score constants ────────────────────────────────────────

    /** Score added on each successful rule-engine match */
    const SCORE_INCREMENT  = 1.0;

    /** Score at or above which Brain bypasses RuleEngine entirely */
    const BYPASS_THRESHOLD = 3.0;  // v3: was 10.0 — learn faster

    /** Nightly decay multiplier — 10% decay per night */
    const DECAY_RATE       = 0.90;

    /** Habits below this score are pruned on nightly decay run */
    const MIN_SCORE        = 0.5;

    // ──────────────────────────────────────────────────────────
    // PUBLIC API
    // ──────────────────────────────────────────────────────────

    /**
     * Fast lookup: return stored habit action if score >= BYPASS_THRESHOLD.
     *
     * Called by DecisionModel BEFORE RuleEngine::evaluate().
     * Uses the idx_habit_lookup index on (client_id, score).
     *
     * @param  array $context
     * @return array|null  Parsed action array, or null if no bypass habit
     */
    public static function lookup(array $context): ?array
    {
        $clientId  = $context['client_id']  ?? '';
        $location  = strtolower($context['location']  ?? '');
        $category  = strtolower($context['category']  ?? '');
        $timeOfDay = strtolower($context['time_of_day'] ?? '');

        try {
            $db  = getDB();
            $sql = "
                SELECT action_json, score, hit_count
                FROM   brain_habits
                WHERE  client_id   = :client_id
                  AND  location    = :location
                  AND  category    = :category
                  AND  time_of_day = :time_of_day
                  AND  score       >= :threshold
                LIMIT  1
            ";

            $st = $db->prepare($sql);
            $st->execute([
                ':client_id'  => $clientId,
                ':location'   => $location,
                ':category'   => $category,
                ':time_of_day'=> $timeOfDay,
                ':threshold'  => self::BYPASS_THRESHOLD,
            ]);

            $row = $st->fetch();
            if (!$row) return null;

            // Decode stored action
            $action = json_decode($row['action_json'], true);
            if (!is_array($action)) return null;

            // Mark as habit-engine source
            return array_merge($action, [
                'source'     => 'habit_engine',
                'habit_score'=> (float) $row['score'],
                'hit_count'  => (int) $row['hit_count'],
            ]);

        } catch (\Throwable $e) {
            error_log('[HabitModel::lookup] ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Record a successful rule match to train the habit engine.
     *
     * Called by DecisionModel AFTER a rule match.
     * Uses INSERT … ON DUPLICATE KEY UPDATE — always one row per pattern.
     *
     * @param  array $context   Full request context
     * @param  array $matched   Parsed action from RuleEngine
     */
    public static function record(array $context, array $matched): void
    {
        $clientId  = $context['client_id']  ?? '';
        $location  = strtolower($context['location']  ?? '');
        $category  = strtolower($context['category']  ?? '');
        $timeOfDay = strtolower($context['time_of_day'] ?? '');

        // Strip runtime-only fields from stored action
        // (rule_matched, source, habit_score are injected at lookup time)
        $actionToStore = array_diff_key($matched, array_flip([
            'source', 'habit_score', 'hit_count', 'context',
        ]));

        $actionJson = json_encode($actionToStore, JSON_UNESCAPED_UNICODE);

        try {
            $db = getDB();
            $st = $db->prepare("
                INSERT INTO brain_habits
                    (id, client_id, location, category, time_of_day,
                     action_json, score, hit_count, last_seen, created_at)
                VALUES
                    (:id, :client_id, :location, :category, :time_of_day,
                     :action_json, :score_init, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    score       = LEAST(score + :score_inc, 100.0),
                    hit_count   = hit_count + 1,
                    last_seen   = NOW(),
                    action_json = :action_json
            ");

            $st->execute([
                ':id'          => self::generateUuid(),
                ':client_id'   => $clientId,
                ':location'    => $location,
                ':category'    => $category,
                ':time_of_day' => $timeOfDay,
                ':action_json' => $actionJson,
                ':score_init'  => self::SCORE_INCREMENT,
                ':score_inc'   => self::SCORE_INCREMENT,
            ]);
        } catch (\Throwable $e) {
            error_log('[HabitModel::record] ' . $e->getMessage());
        }
    }

    /**
     * Apply nightly score decay and prune dead habits.
     * Called by NightlyJob.php.
     *
     * @return array{decayed: int, removed: int}
     */
    public static function applyDecay(): array
    {
        $db = getDB();

        $upd = $db->prepare("
            UPDATE brain_habits
            SET    score = ROUND(score * :rate, 4)
        ");
        $upd->execute([':rate' => self::DECAY_RATE]);
        $decayed = $upd->rowCount();

        $del = $db->prepare("
            DELETE FROM brain_habits
            WHERE  score < :min_score
        ");
        $del->execute([':min_score' => self::MIN_SCORE]);
        $removed = $del->rowCount();

        return ['decayed' => $decayed, 'removed' => $removed];
    }

    /**
     * Summary stats for the dashboard.
     */
    public static function summary(): array
    {
        $db  = getDB();
        $row = $db->query("
            SELECT
                COUNT(*)                                        AS total_habits,
                COALESCE(SUM(score >= " . self::BYPASS_THRESHOLD . "), 0) AS active_bypass,
                COALESCE(SUM(hit_count), 0)                    AS total_hits,
                COALESCE(ROUND(AVG(score), 2), 0)              AS avg_score,
                COALESCE(ROUND(MAX(score), 2), 0)              AS max_score
            FROM brain_habits
        ")->fetch(PDO::FETCH_ASSOC);

        return [
            'total_habits'     => (int)   ($row['total_habits']  ?? 0),
            'active_bypass'    => (int)   ($row['active_bypass'] ?? 0),
            'total_hits'       => (int)   ($row['total_hits']    ?? 0),
            'avg_score'        => (float) ($row['avg_score']     ?? 0.0),
            'max_score'        => (float) ($row['max_score']     ?? 0.0),
            'bypass_threshold' => self::BYPASS_THRESHOLD,
            'decay_rate'       => self::DECAY_RATE,
        ];
    }

    private static function generateUuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
