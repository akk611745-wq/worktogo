<?php

/**
 * BrainCore - SuggestionEngine
 *
 * Analyzes brain_improvements to find repeated patterns
 * and auto-generates rule suggestions.
 *
 * ─── WHAT THIS DOES ──────────────────────────────────────────
 * 1. Reads brain_improvements WHERE reason = 'no_rule_match'
 * 2. Groups by (client_id, location, category)
 * 3. If a group has >= MIN_OCCURRENCES → generate a suggestion
 * 4. Saves to brain_suggestions (via SuggestionModel)
 *
 * ─── NO ML, NO AI ────────────────────────────────────────────
 * Pure pattern counting. Simple threshold check.
 * If (location + category) appears >= 10 times with no rule match
 * → system says: "you probably need a rule here"
 *
 * ─── SUGGESTED RULE FORMAT ───────────────────────────────────
 * condition_exp: "location = haldwani AND category = electronics"
 * action_exp:    "banner: Electronics Trending | boost_category: electronics"
 *
 * Admin reviews → approves → becomes a real brain_rule.
 * ────────────────────────────────────────────────────────────
 */

class SuggestionEngine
{
    /**
     * Minimum number of no_rule_match occurrences
     * for a (location + category) pair to become a suggestion.
     *
     * Set to 10 for production. Lower for testing (e.g. 2).
     */
    private const MIN_OCCURRENCES = 10;

    /**
     * How many days back to look in brain_improvements.
     * Only recent failures matter — old ones may be stale.
     */
    private const LOOKBACK_DAYS = 30;

    /**
     * Main entry point.
     * Call this from a cron job or manually via admin endpoint.
     *
     * Runs the full analysis and stores new suggestions.
     *
     * @param  string|null $clientId  Optional: analyze one client only
     * @return array                  Summary of what was generated
     */
    public static function analyze(?string $clientId = null): array
    {
        // Step 1: Count no_rule_match patterns from brain_improvements
        $patterns = self::countPatterns($clientId);

        if (empty($patterns)) {
            return [
                'patterns_found'      => 0,
                'suggestions_created' => 0,
                'suggestions'         => [],
            ];
        }

        // Step 2: Filter patterns that cross the threshold
        $suggestions = [];
        $created     = 0;

        foreach ($patterns as $row) {
            if ((int) $row['occurrences'] < self::MIN_OCCURRENCES) {
                continue;
            }

            // Step 3: Build the suggestion data
            $suggestionData = self::buildSuggestion($row);

            // Step 4: Save to brain_suggestions (dedup is inside SuggestionModel)
            $id = SuggestionModel::create(
                $row['client_id'],
                'rule_suggestion',
                $suggestionData
            );

            if ($id !== null) {
                $created++;
                $suggestions[] = array_merge(['id' => $id], $suggestionData);
            }
        }

        return [
            'patterns_found'      => count($patterns),
            'suggestions_created' => $created,
            'suggestions'         => $suggestions,
        ];
    }

    // ──────────────────────────────────────────────────────────
    // PRIVATE: Data Mining
    // ──────────────────────────────────────────────────────────

    /**
     * Query brain_improvements and group by (client_id, location, category).
     * Returns only no_rule_match entries within the lookback window.
     *
     * @param  string|null $clientId
     * @return array  [ ['client_id', 'location', 'category', 'occurrences'] ]
     */
    private static function countPatterns(?string $clientId): array
    {
        $db = getDB();

        // We extract location and category from the event_data JSON column.
        // brain_improvements stores: event_data = { "context": { "location": "...", "category": "..." } }
        //
        // JSON_UNQUOTE(JSON_EXTRACT(...)) gives us a clean string.
        // NULLIF(..., 'null') converts SQL-null-as-string to real NULL.

        $where  = [
            "reason    = 'no_rule_match'",
            "status    = 'pending'",
            "created_at >= DATE_SUB(NOW(), INTERVAL :lookback DAY)",
        ];
        $params = [':lookback' => self::LOOKBACK_DAYS];

        if ($clientId !== null) {
            $where[]              = 'client_id = :client_id';
            $params[':client_id'] = $clientId;
        }

        $whereStr = implode(' AND ', $where);

        $sql = "
            SELECT
                client_id,
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.context.location')), 'null') AS location,
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.context.category')), 'null') AS category,
                COUNT(*) AS occurrences
            FROM  brain_improvements
            WHERE $whereStr
            GROUP BY client_id, location, category
            HAVING location IS NOT NULL
               AND category IS NOT NULL
            ORDER BY occurrences DESC
        ";

        $st = $db->prepare($sql);
        $st->execute($params);

        return $st->fetchAll();
    }

    // ──────────────────────────────────────────────────────────
    // PRIVATE: Suggestion Builder
    // ──────────────────────────────────────────────────────────

    /**
     * Given a pattern row, build a structured suggestion.
     *
     * This is the "intelligence" — it builds a ready-to-use rule
     * based purely on the data pattern. No ML involved.
     *
     * Pattern:  location=haldwani, category=electronics, occurrences=14
     * Suggests:
     *   condition_exp: "location = haldwani AND category = electronics"
     *   action_exp:    "banner: Electronics Trending | boost_category: electronics"
     *
     * @param  array $row  From countPatterns()
     * @return array       Full suggestion_data structure
     */
    private static function buildSuggestion(array $row): array
    {
        $location   = strtolower(trim($row['location']));
        $category   = strtolower(trim($row['category']));
        $occurrences = (int) $row['occurrences'];

        // ── Suggested rule fields ─────────────────────────────
        $categoryLabel = ucwords(str_replace('_', ' ', $category));
        $locationLabel = ucwords($location);

        $suggestedRule = [
            'name'          => "{$categoryLabel} Boost - {$locationLabel}",
            'description'   => "Auto-suggested: {$occurrences} unmatched requests for {$category} in {$location}",
            'trigger_exp'   => "event_count > 5",
            'condition_exp' => "location = {$location} AND category = {$category}",
            'action_exp'    => "banner: {$categoryLabel} Trending | boost_category: {$category}",
            'rule_type'     => 'geo',
            'priority'      => 1,
        ];

        // ── Full suggestion_data payload ──────────────────────
        return [
            'pattern' => [
                'location'    => $location,
                'category'    => $category,
                'occurrences' => $occurrences,
                'reason'      => 'no_rule_match',
            ],
            'suggested_rule' => $suggestedRule,
            'note' => "System found {$occurrences} requests with no matching rule "
                    . "for location={$location} + category={$category}. "
                    . "Approve to create this rule automatically.",
        ];
    }
}
