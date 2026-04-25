<?php

/**
 * BrainCore - RuleEngine
 *
 * Evaluates active rules against a context.
 *
 * ─── RETURN VALUE CHANGE (Step 6) ────────────────────────────
 *
 * evaluate() now returns a structured result array:
 *
 *   [
 *     'matched'       => array|null,  // Parsed action if a rule matched
 *     'invalid_rules' => [            // Rules that failed to parse
 *       ['rule_id' => '...', 'rule_name' => '...', 'bad_condition' => '...'],
 *       ...
 *     ]
 *   ]
 *
 * WHY:
 *   Previously returned null on no-match and silently skipped
 *   invalid conditions. Now the caller (DecisionModel) receives
 *   full visibility into BOTH failure types so they can be
 *   stored as improvements.
 *
 * ─── CONDITION FORMAT ────────────────────────────────────────
 *
 * Conditions: plain text joined by AND.
 * Each condition: "field operator value"
 *
 * Supported fields:   location, category, event_count
 * Supported operators: =, !=, >, <, >=, <=
 *
 * Examples:
 *   "location = haldwani"
 *   "location = haldwani AND category = electronics"
 *   "event_count > 50"
 *   "location = haldwani AND event_count >= 20"
 *
 * ─── ACTION FORMAT ───────────────────────────────────────────
 *
 * key: value pairs separated by |
 *
 * Examples:
 *   "banner: AC Repair | boost_category: electronics"
 *   "banner: Sale | boost_category: home | action: show_popup"
 */

class RuleEngine
{
    /**
     * Run the rule engine against the given context.
     *
     * @param  array $context  Keys: client_id, location, category, user_id
     * @return array {
     *   matched:       array|null — action output if a rule matched
     *   invalid_rules: array     — list of rules with bad condition_exp
     * }
     */
    public static function evaluate(array $context): array
    {
        $result = [
            'matched'       => null,
            'invalid_rules' => [],
        ];

        // ── 1. Fetch active rules (priority ASC) ───────────────
        $rules = self::fetchActiveRules($context['client_id'] ?? null);

        if (empty($rules)) {
            return $result;
        }

        // ── 2. Pre-fetch event_count (used by all rules) ───────
        $eventCount = self::getEventCount($context);

        // ── 3. Loop rules ──────────────────────────────────────
        foreach ($rules as $rule) {

            // Try to evaluate — returns: true (match) | false (no match) | 'invalid' (bad syntax)
            $evaluation = self::matchesCondition($rule['condition_exp'], $context, $eventCount);

            if ($evaluation === 'invalid') {
                // Rule has a bad condition — log it, continue to next rule
                $result['invalid_rules'][] = [
                    'rule_id'       => $rule['id'],
                    'rule_name'     => $rule['name'],
                    'bad_condition' => $rule['condition_exp'],
                ];
                continue;
            }

            if ($evaluation === true) {
                // First match wins
                $result['matched'] = self::parseAction(
                    $rule['action_exp'],
                    $rule['id'],
                    $rule['name']
                );
                // Stop here — don't continue checking lower-priority rules
                return $result;
            }

            // evaluation === false → condition didn't match, try next rule
        }

        return $result;
    }

    // ──────────────────────────────────────────────────────────
    // PRIVATE METHODS
    // ──────────────────────────────────────────────────────────

    /**
     * Fetch all active rules for a client, ordered by priority.
     *
     * Includes both client-specific and global rules (client_id IS NULL).
     */
    private static function fetchActiveRules(?string $clientId): array
    {
        $db  = getDB();
        $sql = "
            SELECT id, name, condition_exp, action_exp, rule_type, priority
            FROM   brain_rules
            WHERE  is_active = 1
              AND  (client_id = :client_id OR client_id IS NULL)
            ORDER  BY priority ASC
        ";

        $st = $db->prepare($sql);
        $st->execute([':client_id' => $clientId]);

        return $st->fetchAll();
    }

    /**
     * Count recent events for this context (last 24 hours).
     * Fetched once and reused across all rule evaluations.
     */
    private static function getEventCount(array $context): int
    {
        $db = getDB();

        $where  = ['client_id = :client_id', 'created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'];
        $params = [':client_id' => $context['client_id'] ?? null];

        if (!empty($context['location'])) {
            $where[]             = 'location = :location';
            $params[':location'] = $context['location'];
        }

        if (!empty($context['category'])) {
            $where[]             = 'category = :category';
            $params[':category'] = $context['category'];
        }

        $sql = 'SELECT COUNT(*) AS total FROM brain_events WHERE ' . implode(' AND ', $where);
        $st  = $db->prepare($sql);
        $st->execute($params);

        return (int) $st->fetch()['total'];
    }

    /**
     * Evaluate a condition_exp string against the current context.
     *
     * Returns:
     *   true      → all conditions matched
     *   false     → one or more conditions did NOT match
     *   'invalid' → condition_exp could not be parsed (bad syntax in DB)
     *
     * @param  string $conditionExp
     * @param  array  $context
     * @param  int    $eventCount
     * @return bool|string (bool or string "invalid")
     */
    private static function matchesCondition(
        string $conditionExp,
        array  $context,
        int    $eventCount
    ) {
        $parts = preg_split('/\s+AND\s+/i', $conditionExp);

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) continue;

            $parsed = self::parseConditionPart($part);

            if ($parsed === null) {
                // Could not parse this condition — flag entire rule as invalid
                return 'invalid';
            }

            [$field, $operator, $value] = $parsed;

            if (!self::evaluateSingle($field, $operator, $value, $context, $eventCount)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Parse "field operator value" into [field, op, value].
     * Returns null if no recognised operator is found.
     */
    private static function parseConditionPart(string $part): ?array
    {
        // Check longest operators first — >= before >, <= before <
        $operators = ['>=', '<=', '!=', '>', '<', '='];

        foreach ($operators as $op) {
            if (strpos($part, $op) !== false) {
                [$field, $value] = explode($op, $part, 2);
                $field = trim($field);
                $value = trim($value);

                // Field and value must not be empty
                if ($field === '' || $value === '') return null;

                return [$field, $op, $value];
            }
        }

        return null;
    }

    /**
     * Evaluate a single condition [field, operator, value].
     *
     * Supported fields:
     *   location     → string match (= / !=)
     *   category     → string match (= / !=)
     *   event_count  → numeric comparison (all 6 operators)
     *   device_type  → string match: mobile / tablet / desktop  (Step 12)
     *   time_of_day  → string match: morning / afternoon / evening / night  (Step 10)
     *   day_of_week  → string match: monday … sunday  (Step 10)
     */
    private static function evaluateSingle(
        string $field,
        string $operator,
        string $value,
        array  $context,
        int    $eventCount
    ): bool {
        switch ($field) {
            case 'location':
                return self::compareString($context['location'] ?? '', $operator, $value);

            case 'category':
                return self::compareString($context['category'] ?? '', $operator, $value);

            case 'event_count':
                return self::compareNumeric($eventCount, $operator, (int) $value);

            // ── Step 12: Device context ─────────────────────────
            // Rules can now match: condition_exp = "device_type = mobile"
            case 'device_type':
                return self::compareString($context['device_type'] ?? '', $operator, $value);

            // ── Step 10: Time-of-day signal ─────────────────────
            // Rules can now match: condition_exp = "time_of_day = evening"
            case 'time_of_day':
                return self::compareString($context['time_of_day'] ?? '', $operator, $value);

            // ── Step 10: Day-of-week signal ─────────────────────
            // Rules can now match: condition_exp = "day_of_week = saturday"
            case 'day_of_week':
                return self::compareString($context['day_of_week'] ?? '', $operator, $value);

            default:
                // Unknown field — treat as no-match (not crash, not invalid)
                // Logged silently; does not mark the rule as invalid.
                return false;
        }
    }

    /**
     * String comparison (case-insensitive, = and != only).
     */
    private static function compareString(string $actual, string $operator, string $expected): bool
    {
        $actual   = strtolower(trim($actual));
        $expected = strtolower(trim($expected));

        return match ($operator) {
            '='  => $actual === $expected,
            '!=' => $actual !== $expected,
            default => false,
        };
    }

    /**
     * Numeric comparison — all 6 operators.
     */
    private static function compareNumeric(int $actual, string $operator, int $expected): bool
    {
        return match ($operator) {
            '='  => $actual === $expected,
            '!=' => $actual !== $expected,
            '>'  => $actual >   $expected,
            '<'  => $actual <   $expected,
            '>=' => $actual >=  $expected,
            '<=' => $actual <=  $expected,
            default => false,
        };
    }

    /**
     * Parse action_exp into a structured array.
     *
     * "banner: AC Repair | boost_category: electronics"
     * → ['banner' => 'AC Repair', 'boost_category' => 'electronics',
     *    'rule_matched' => uuid, 'rule_name' => '...', 'source' => 'rule_engine']
     */
    private static function parseAction(string $actionExp, string $ruleId, string $ruleName): array
    {
        $result = [
            'rule_matched' => $ruleId,
            'rule_name'    => $ruleName,
            'source'       => 'rule_engine',
        ];

        foreach (explode('|', $actionExp) as $pair) {
            $pair = trim($pair);
            if (!strpos($pair, ':') !== false) continue;

            [$key, $val] = explode(':', $pair, 2);
            $key = trim($key);
            $val = trim($val);

            if ($key !== '') {
                $result[$key] = $val;
            }
        }

        return $result;
    }
}
