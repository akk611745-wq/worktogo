<?php

/**
 * BrainCore - RuleValidator
 *
 * Validates rules BEFORE they reach the RuleEngine.
 * Called by AdminController on create/update.
 * Also used by a batch validation admin endpoint.
 *
 * ─── WHAT IS VALIDATED ───────────────────────────────────────
 *
 * condition_exp:
 *   - Must not be empty
 *   - Must not be JSON (common admin mistake)
 *   - Each part must be: field OPERATOR value
 *   - field must be a known context field
 *   - operator must be supported for that field type
 *   - value must not be empty
 *
 * action_exp:
 *   - Must not be empty
 *   - Must not be JSON
 *   - Must contain at least one valid "key: value" pair
 *   - Each pair must have a recognised action key
 *
 * ─── USAGE ───────────────────────────────────────────────────
 *
 *   $errors = RuleValidator::validateCondition($conditionExp);
 *   $errors = RuleValidator::validateAction($actionExp);
 *   $errors = RuleValidator::validate($conditionExp, $actionExp);
 *   // $errors = [] means valid
 */

class RuleValidator
{
    // Known context fields and their allowed operators
    private const FIELD_TYPES = [
        'location'    => 'string',
        'category'    => 'string',
        'event_count' => 'numeric',
        'device_type' => 'string',
        'time_of_day' => 'string',
        'day_of_week' => 'string',
    ];

    private const STRING_OPS  = ['=', '!='];
    private const NUMERIC_OPS = ['=', '!=', '>', '<', '>=', '<='];

    // Known valid action keys
    private const VALID_ACTION_KEYS = [
        'banner', 'boost_category', 'action', 'message',
        'show_popup', 'notify_user', 'notify_vendor', 'log_only',
    ];

    /**
     * Validate both condition_exp and action_exp.
     *
     * @return array  Empty = valid. Non-empty = list of error strings.
     */
    public static function validate(string $conditionExp, string $actionExp): array
    {
        return array_merge(
            self::validateCondition($conditionExp),
            self::validateAction($actionExp)
        );
    }

    /**
     * Validate condition_exp string.
     *
     * @return array  Error messages (empty = valid)
     */
    public static function validateCondition(string $exp): array
    {
        $errors = [];
        $exp    = trim($exp);

        if ($exp === '') {
            return ['condition_exp cannot be empty.'];
        }

        // Reject JSON format — common admin mistake
        if (self::looksLikeJson($exp)) {
            $errors[] = 'condition_exp must NOT be JSON. Use format: "field = value" or "field = value AND field2 = value2". Example: "time_of_day = evening AND category = electronics"';
            return $errors; // No point checking further
        }

        // Split on AND — case-insensitive
        $parts = preg_split('/\s+AND\s+/i', $exp);

        foreach ($parts as $i => $part) {
            $part = trim($part);
            if ($part === '') continue;

            $partNum = $i + 1;

            // Find operator
            $parsed = self::parseConditionPart($part);

            if ($parsed === null) {
                $errors[] = "condition part #{$partNum} '{$part}' is invalid. No recognized operator found. Valid operators: = != > < >= <=";
                continue;
            }

            [$field, $op, $value] = $parsed;

            // Unknown field
            if (!array_key_exists($field, self::FIELD_TYPES)) {
                $known = implode(', ', array_keys(self::FIELD_TYPES));
                $errors[] = "condition part #{$partNum}: unknown field '{$field}'. Known fields: {$known}";
                continue;
            }

            // Wrong operator for field type
            $fieldType     = self::FIELD_TYPES[$field];
            $allowedOps    = $fieldType === 'numeric' ? self::NUMERIC_OPS : self::STRING_OPS;

            if (!in_array($op, $allowedOps, true)) {
                $allowed = implode(', ', $allowedOps);
                $errors[] = "condition part #{$partNum}: operator '{$op}' not valid for field '{$field}' ({$fieldType}). Allowed: {$allowed}";
            }

            // Empty value
            if ($value === '') {
                $errors[] = "condition part #{$partNum}: value is empty for field '{$field}'.";
            }

            // Numeric field — value must be numeric
            if ($fieldType === 'numeric' && !is_numeric($value)) {
                $errors[] = "condition part #{$partNum}: field '{$field}' requires a numeric value, got '{$value}'.";
            }
        }

        return $errors;
    }

    /**
     * Validate action_exp string.
     *
     * @return array  Error messages (empty = valid)
     */
    public static function validateAction(string $exp): array
    {
        $errors = [];
        $exp    = trim($exp);

        if ($exp === '') {
            return ['action_exp cannot be empty.'];
        }

        // Reject JSON format
        if (self::looksLikeJson($exp)) {
            $errors[] = 'action_exp must NOT be JSON. Use pipe-separated format: "banner: Sale | boost_category: electronics". Got JSON instead.';
            return $errors;
        }

        $pairs     = explode('|', $exp);
        $validPairs = 0;

        foreach ($pairs as $i => $pair) {
            $pair = trim($pair);
            if ($pair === '') continue;

            if (!strpos($pair, ':') !== false) {
                $errors[] = "action pair #{$i}: '{$pair}' is missing ':' separator. Format must be 'key: value'.";
                continue;
            }

            [$key, $val] = explode(':', $pair, 2);
            $key = trim($key);
            $val = trim($val);

            if ($key === '') {
                $errors[] = "action pair #{$i}: key is empty.";
                continue;
            }

            if ($val === '') {
                $errors[] = "action pair #{$i}: value is empty for key '{$key}'.";
                continue;
            }

            // Warn on unknown action keys (soft warning, not hard error)
            // Unknown keys are allowed — system is extensible
            $validPairs++;
        }

        if ($validPairs === 0) {
            $errors[] = 'action_exp has no valid key:value pairs. Example: "banner: Sale | boost_category: electronics"';
        }

        return $errors;
    }

    // ──────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────────────────

    /**
     * Detect if a string is JSON (object or array).
     * Common mistake: admins paste JSON into condition/action fields.
     */
    private static function looksLikeJson(string $s): bool
    {
        $s = trim($s);
        return (strpos($s, '{') === 0 || strpos($s, '[') === 0);
    }

    /**
     * Parse "field operator value" — same logic as RuleEngine.
     * Returns [field, op, value] or null.
     */
    private static function parseConditionPart(string $part): ?array
    {
        foreach (['>=', '<=', '!=', '>', '<', '='] as $op) {
            if (strpos($part, $op) !== false) {
                [$field, $value] = explode($op, $part, 2);
                $field = trim($field);
                $value = trim($value);
                if ($field !== '' && $value !== '') {
                    return [$field, $op, $value];
                }
            }
        }
        return null;
    }
}
