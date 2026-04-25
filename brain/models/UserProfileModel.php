<?php

/**
 * BrainCore - UserProfileModel
 *
 * USER MEMORY + SCORING + DECAY SYSTEM
 *
 * ─── WHAT THIS DOES ──────────────────────────────────────────
 *
 *   Maintains a per-user preference profile.
 *   Every event updates the user's score_data JSON.
 *   The highest-scoring category (after decay) = top_category.
 *
 * ─── SCORING WEIGHTS ─────────────────────────────────────────
 *
 *   product_view     → +1   (weak signal)
 *   service_click    → +1
 *   search           → +1
 *   add_to_cart      → +3   (moderate intent)
 *   order_placed     → +10  (strong confirmation)
 *   (all other types → +1 as default)
 *
 * ─── SIGNAL FILTER ───────────────────────────────────────────
 *
 *   SIGNAL_THRESHOLD = 3
 *   A category score (post-decay) must reach this before qualifying
 *   as top_category. Single random views never define a user.
 *
 * ─── DECAY SYSTEM ────────────────────────────────────────────
 *
 *   Old activity loses weight automatically.
 *   Decay is applied at READ time — no cron job needed.
 *   Raw scores are never erased; decay is calculated on the fly.
 *
 *   Decay table:
 *     0–2 days old   → 100%  (full weight)
 *     3–6 days old   → 70%
 *     7–13 days old  → 40%
 *     14+ days old   → 10%   (minimum residual, not zero)
 *
 *   score_data storage format (Step 11):
 *     {
 *       "electronics": { "score": 25, "last_updated": "2025-01-15" },
 *       "grocery":     { "score": 5,  "last_updated": "2025-01-10" }
 *     }
 *
 *   Effective score at decision time:
 *     effective = raw_score × decay_multiplier(days_since_last_updated)
 *
 *   MIGRATION NOTE:
 *     Old flat format { "electronics": 25 } is auto-detected and
 *     migrated to structured format on first write. No manual migration.
 *
 * ─── SIGNAL PRIORITY (Step 11) ───────────────────────────────
 *
 *   DecisionModel uses effective scores to determine priority:
 *
 *     user_score (effective, decayed)
 *         > location_score (from RuleEngine geo rules)
 *         > global default (fallback)
 *
 *   getEffectiveScores() is exposed for DecisionModel to consume.
 *
 * ─── PUBLIC INTERFACE ────────────────────────────────────────
 *
 *   UserProfileModel::updateFromEvent(array $eventData): void
 *   UserProfileModel::getProfile(string $userId, string $clientId): ?array
 *   UserProfileModel::getTopCategory(string $userId, string $clientId): ?string
 *   UserProfileModel::getEffectiveScores(string $userId, string $clientId): array
 */

class UserProfileModel
{
    // ── Score Weights ──────────────────────────────────────────
    private const WEIGHTS = [
        'product_view'  => 1,
        'service_click' => 1,
        'search'        => 1,
        'add_to_cart'   => 3,
        'order_placed'  => 10,
    ];

    // ── Signal Filter Threshold (applied to effective/decayed score) ──
    private const SIGNAL_THRESHOLD = 3;

    // ── Default weight for unrecognised event types ────────────
    private const DEFAULT_WEIGHT = 1;

    // ── Decay Multipliers ──────────────────────────────────────
    // Applied based on how many days since category was last touched.
    //
    //  age (days)  | multiplier | meaning
    //  ────────────────────────────────────
    //  0–2         |   1.00     | fresh signal, full weight
    //  3–6         |   0.70     | slightly stale
    //  7–13        |   0.40     | old data, reduced influence
    //  14+         |   0.10     | very old, near-zero (not zero)
    private const DECAY_TABLE = [
        ['max_days' => 2,           'multiplier' => 1.00],
        ['max_days' => 6,           'multiplier' => 0.70],
        ['max_days' => 13,          'multiplier' => 0.40],
        ['max_days' => PHP_INT_MAX, 'multiplier' => 0.10],
    ];

    // ─────────────────────────────────────────────────────────────
    // PUBLIC: Update profile from a raw event
    // ─────────────────────────────────────────────────────────────

    /**
     * Update (or create) a user profile based on an incoming event.
     * Called by EventController after every successful event store.
     * Skipped silently if user_id or category is missing.
     *
     * @param  array $eventData  Must contain: client_id, event_type
     *                           Optional: user_id, category
     * @return void
     */
    public static function updateFromEvent(array $eventData): void
    {
        $userId   = $eventData['user_id']    ?? null;
        $clientId = $eventData['client_id']  ?? null;
        $category = $eventData['category']   ?? null;
        $type     = $eventData['event_type'] ?? 'unknown';

        if (!$userId || !$clientId || !$category) {
            return;
        }

        $category = strtolower(trim($category));

        // ── 1. Load existing profile ───────────────────────────
        $profile   = self::getProfile($userId, $clientId);
        $scoreData = [];

        if ($profile && !empty($profile['score_data'])) {
            $scoreData = self::normalizeScoreData(
                json_decode($profile['score_data'], true) ?? []
            );
        }

        // ── 2. Apply weight + stamp today on this category ────
        $weight = self::WEIGHTS[$type] ?? self::DEFAULT_WEIGHT;
        $today  = date('Y-m-d');

        if (isset($scoreData[$category])) {
            $scoreData[$category]['score']        += $weight;
            $scoreData[$category]['last_updated']  = $today;
        } else {
            $scoreData[$category] = [
                'score'        => $weight,
                'last_updated' => $today,
            ];
        }

        // ── 3. Compute top_category (decayed scores + signal filter)
        $topCategory = self::computeTopCategory($scoreData);

        // ── 4. Upsert profile ──────────────────────────────────
        if ($profile) {
            self::updateProfile($userId, $clientId, $scoreData, $topCategory);
        } else {
            self::createProfile($userId, $clientId, $scoreData, $topCategory);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // PUBLIC: Read profile
    // ─────────────────────────────────────────────────────────────

    /**
     * Fetch the full profile row for a user.
     *
     * @param  string $userId
     * @param  string $clientId
     * @return array|null
     */
    public static function getProfile(string $userId, string $clientId): ?array
    {
        $db  = getDB();
        $sql = "
            SELECT *
            FROM   brain_user_profiles
            WHERE  user_id   = :user_id
            AND    client_id = :client_id
            LIMIT  1
        ";

        $st = $db->prepare($sql);
        $st->execute([':user_id' => $userId, ':client_id' => $clientId]);

        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Return top_category for a user.
     * Returns null if no category has crossed SIGNAL_THRESHOLD after decay.
     */
    public static function getTopCategory(string $userId, string $clientId): ?string
    {
        $profile = self::getProfile($userId, $clientId);
        return $profile['top_category'] ?? null;
    }

    /**
     * Return effective (decayed) scores for all categories.
     *
     * Used by DecisionModel for signal priority:
     *   user_score > location_score > global
     *
     * Returns array sorted descending: ["electronics" => 17.5, "grocery" => 2.0]
     *
     * @param  string $userId
     * @param  string $clientId
     * @return array  category => effective_score (float)
     */
    public static function getEffectiveScores(string $userId, string $clientId): array
    {
        $profile = self::getProfile($userId, $clientId);

        if (!$profile || empty($profile['score_data'])) {
            return [];
        }

        $scoreData = self::normalizeScoreData(
            json_decode($profile['score_data'], true) ?? []
        );

        $effective = [];
        foreach ($scoreData as $category => $data) {
            $effective[$category] = self::applyDecay(
                $data['score'],
                $data['last_updated']
            );
        }

        arsort($effective); // highest effective score first
        return $effective;
    }

    // ─────────────────────────────────────────────────────────────
    // PRIVATE: Decay logic
    // ─────────────────────────────────────────────────────────────

    /**
     * Apply decay multiplier to a raw score based on age.
     *
     * @param  float  $rawScore
     * @param  string $lastUpdated   Date string "Y-m-d"
     * @return float                 Effective score after decay
     */
    private static function applyDecay(float $rawScore, string $lastUpdated): float
    {
        $days = self::daysSince($lastUpdated);

        $multiplier = 0.10; // fallback: 14+ days old
        foreach (self::DECAY_TABLE as $tier) {
            if ($days <= $tier['max_days']) {
                $multiplier = $tier['multiplier'];
                break;
            }
        }

        return round($rawScore * $multiplier, 4);
    }

    /**
     * Calculate days between a date string and today.
     */
    private static function daysSince(string $dateString): int
    {
        try {
            $then = new \DateTime($dateString);
            $now  = new \DateTime('today');
            return (int) $now->diff($then)->days;
        } catch (\Throwable $e) {
            return 999; // treat bad dates as very old
        }
    }

    // ─────────────────────────────────────────────────────────────
    // PRIVATE: Compute top_category
    // ─────────────────────────────────────────────────────────────

    /**
     * Find category with highest effective score above SIGNAL_THRESHOLD.
     *
     * Both decay AND signal filter are applied here.
     *
     * Example:
     *   raw:       electronics=25 (3 days old → ×0.70 = 17.5)
     *              grocery=5      (today     → ×1.00 = 5.0)
     *              fashion=1      (today     → ×1.00 = 1.0) ← below threshold
     *   qualified: electronics=17.5, grocery=5.0
     *   winner:    electronics
     *
     * @param  array $scoreData  Structured: { cat: { score, last_updated } }
     * @return string|null
     */
    private static function computeTopCategory(array $scoreData): ?string
    {
        $effective = [];

        foreach ($scoreData as $category => $data) {
            $eff = self::applyDecay($data['score'], $data['last_updated']);
            if ($eff >= self::SIGNAL_THRESHOLD) {
                $effective[$category] = $eff;
            }
        }

        if (empty($effective)) {
            return null;
        }

        arsort($effective);
        return (string) array_key_first($effective);
    }

    // ─────────────────────────────────────────────────────────────
    // PRIVATE: Migration (Step 10 flat format → Step 11 structured)
    // ─────────────────────────────────────────────────────────────

    /**
     * Silently migrate old flat score_data to structured format.
     *
     * Old (Step 10): { "electronics": 25 }
     * New (Step 11): { "electronics": { "score": 25, "last_updated": "Y-m-d" } }
     *
     * Uses today as last_updated for migrated rows.
     * This gives them full decay weight (no penalty for existing users).
     *
     * @param  array $rawData
     * @return array  Structured format guaranteed
     */
    private static function normalizeScoreData(array $rawData): array
    {
        $today      = date('Y-m-d');
        $normalized = [];

        foreach ($rawData as $category => $value) {
            if (is_array($value) && isset($value['score'])) {
                // Already Step 11 format
                $normalized[$category] = $value;
            } elseif (is_numeric($value)) {
                // Step 10 flat format — migrate
                $normalized[$category] = [
                    'score'        => (float) $value,
                    'last_updated' => $today,
                ];
            }
            // Anything else = corrupted, skip silently
        }

        return $normalized;
    }

    // ─────────────────────────────────────────────────────────────
    // PRIVATE: DB writes
    // ─────────────────────────────────────────────────────────────

    private static function createProfile(
        string  $userId,
        string  $clientId,
        array   $scoreData,
        ?string $topCategory
    ): void {
        $db = getDB();
        $id = self::generateUuid();

        $sql = "
            INSERT INTO brain_user_profiles
                (id, user_id, client_id, top_category, last_activity, score_data, created_at, updated_at)
            VALUES
                (:id, :user_id, :client_id, :top_category, NOW(), :score_data, NOW(), NOW())
        ";

        $st = $db->prepare($sql);
        $st->execute([
            ':id'           => $id,
            ':user_id'      => $userId,
            ':client_id'    => $clientId,
            ':top_category' => $topCategory,
            ':score_data'   => json_encode($scoreData),
        ]);
    }

    private static function updateProfile(
        string  $userId,
        string  $clientId,
        array   $scoreData,
        ?string $topCategory
    ): void {
        $db = getDB();

        $sql = "
            UPDATE brain_user_profiles
            SET
                score_data    = :score_data,
                top_category  = :top_category,
                last_activity = NOW(),
                updated_at    = NOW()
            WHERE  user_id   = :user_id
            AND    client_id = :client_id
        ";

        $st = $db->prepare($sql);
        $st->execute([
            ':score_data'   => json_encode($scoreData),
            ':top_category' => $topCategory,
            ':user_id'      => $userId,
            ':client_id'    => $clientId,
        ]);
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
