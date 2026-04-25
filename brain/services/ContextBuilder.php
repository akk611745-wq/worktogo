<?php

/**
 * BrainCore v3 — ContextBuilder
 *
 * ─── PURPOSE ─────────────────────────────────────────────────
 *
 *   Assembles the full context array passed to DecisionModel.
 *   Centralises all context injection so DecisionController
 *   stays thin: validate → build context → resolve → respond.
 *
 *   All time signals are computed from the RESOLVED timezone,
 *   not the server clock. No hardcoded assumptions.
 *
 * ─── WHAT IT BUILDS ──────────────────────────────────────────
 *
 *   From request params (normalised):
 *     client_id, user_id, location, category, device_type
 *
 *   Injected dynamically (no hardcoding):
 *     timezone     → resolved via TimezoneResolver
 *     time_of_day  → morning / afternoon / evening / night (in user's TZ)
 *     day_of_week  → monday … sunday (in user's TZ)
 *     local_time   → e.g. "2026-04-09 19:34:00" for logging
 *
 *   From device detection:
 *     device_type  → mobile / tablet / desktop
 *
 *   From user profile (if user_id present):
 *     category_source         → user_preference | caller | null
 *     user_effective_score    → float (decayed category score)
 *
 * ─── INPUT VALIDATION ────────────────────────────────────────
 *
 *   All string inputs are:
 *     - Trimmed
 *     - Lowercased (location, category, time_of_day, day_of_week)
 *     - Length-checked (max 100 chars → truncated safely)
 *
 * ─── USAGE ───────────────────────────────────────────────────
 *
 *   $context = ContextBuilder::build($_GET);
 *   // context is ready to pass to DecisionModel::resolve()
 */

class ContextBuilder
{
    /** Max length for string context fields — prevents oversized DB writes */
    const MAX_FIELD_LENGTH = 100;

    /**
     * Build the full context array from raw request input.
     *
     * @param  array  $input      Raw request params (from $_GET or $_POST)
     * @param  string $clientId   Already-validated client ID
     * @return array              Full context array
     */
    public static function build(array $input, string $clientId): array
    {
        $userId   = isset($input['user_id']) ? self::clean($input['user_id']) : null;

        // ── Base context from request ──────────────────────────
        $context = [
            'client_id'  => $clientId,
            'user_id'    => $userId,
            'location'   => isset($input['location'])
                              ? strtolower(self::clean($input['location']))
                              : null,
            'category'   => isset($input['category'])
                              ? strtolower(self::clean($input['category']))
                              : null,
        ];

        // ── Timezone resolution (dynamic, no hardcoding) ───────
        // Priority: user profile TZ → request ?tz= → server default
        $requestTz = isset($input['tz']) ? trim($input['tz']) : null;
        $timezone  = TimezoneResolver::resolve($userId, $clientId, $requestTz);

        $context['timezone']    = $timezone;
        $context['time_of_day'] = isset($input['time_of_day'])
            ? strtolower(self::clean($input['time_of_day']))
            : TimezoneResolver::getTimeOfDay($timezone);

        $context['day_of_week'] = isset($input['day_of_week'])
            ? strtolower(self::clean($input['day_of_week']))
            : TimezoneResolver::getDayOfWeek($timezone);

        $context['local_time']  = TimezoneResolver::getLocalTime($timezone);

        // ── Device type detection ──────────────────────────────
        $context['device_type'] = DeviceDetector::detect($input['device_type'] ?? null);

        // ── User preference injection ──────────────────────────
        // If user has a known top_category AND caller did not specify
        // one → inject it so the habit/rule engine can use it.
        if ($userId && $context['category'] === null) {
            $userTopCategory = UserProfileModel::getTopCategory($userId, $clientId);
            if ($userTopCategory !== null) {
                $context['category']        = $userTopCategory;
                $context['category_source'] = 'user_preference';
            }
        } else {
            $context['category_source'] = $context['category'] !== null ? 'caller' : null;
        }

        return $context;
    }

    /**
     * Sanitise a single string field.
     *
     * Trims whitespace and enforces max length.
     * Returns null if input is empty after trimming.
     */
    private static function clean(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') return null;

        // Enforce max length — prevents DB overflow attacks
        if (mb_strlen($trimmed) > self::MAX_FIELD_LENGTH) {
            $trimmed = mb_substr($trimmed, 0, self::MAX_FIELD_LENGTH);
        }

        return $trimmed;
    }
}
