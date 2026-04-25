<?php

/**
 * BrainCore v3 — TimezoneResolver
 *
 * ─── PURPOSE ─────────────────────────────────────────────────
 *
 *   Resolves the correct timezone for a request WITHOUT any
 *   hardcoded country or region assumptions.
 *
 *   BrainCore is global. An Indian user, a UAE user, and a UK
 *   user all get time_of_day calculated in THEIR timezone, not
 *   the server timezone.
 *
 * ─── RESOLUTION PRIORITY ─────────────────────────────────────
 *
 *   1. User profile timezone  — stored in brain_user_profiles
 *      Best source: the user told us their timezone explicitly
 *      or it was set when their account was created.
 *
 *   2. Request param `tz`    — caller sends ?tz=Asia/Dubai
 *      Heart can detect the browser's timezone via
 *      Intl.DateTimeFormat().resolvedOptions().timeZone
 *      and pass it in the request. Very accurate.
 *
 *   3. Server default        — php.ini / date.timezone setting
 *      Last resort. Never crashes. Works even if everything else
 *      is missing (e.g. server-to-server API calls with no user).
 *
 * ─── VALIDATION ──────────────────────────────────────────────
 *
 *   Only IANA timezone identifiers are accepted.
 *   e.g. "Asia/Kolkata", "America/New_York", "Europe/London"
 *   Any unrecognized string is silently rejected and the next
 *   priority level is tried.
 *
 * ─── USAGE ───────────────────────────────────────────────────
 *
 *   // In ContextBuilder / DecisionController:
 *   $tz = TimezoneResolver::resolve($userId, $clientId, $requestTz);
 *
 *   // Then use it for all date calculations:
 *   $hour = (int) (new DateTime('now', new DateTimeZone($tz)))->format('G');
 *
 * ─── PYTHON MIGRATION NOTE ───────────────────────────────────
 *
 *   Port as: braincore/timezone_resolver.py
 *   Use zoneinfo.ZoneInfo from Python 3.9+ (no pytz needed)
 *   import zoneinfo; tz = zoneinfo.ZoneInfo(tz_string)
 */

class TimezoneResolver
{
    /** Fallback when nothing else resolves */
    const DEFAULT_TZ = 'UTC';

    /**
     * Resolve the best timezone for this request.
     *
     * @param  string|null $userId      Logged-in user ID (if any)
     * @param  string|null $clientId    Client ID for profile lookup
     * @param  string|null $requestTz   Timezone from request param ?tz=
     * @return string  Valid IANA timezone identifier
     */
    public static function resolve(
        ?string $userId,
        ?string $clientId,
        ?string $requestTz
    ): string {
        // ── Priority 1: User profile timezone ─────────────────
        if ($userId && $clientId) {
            $profileTz = self::fromUserProfile($userId, $clientId);
            if ($profileTz !== null) {
                return $profileTz;
            }
        }

        // ── Priority 2: Request param tz ──────────────────────
        if ($requestTz !== null) {
            $clean = self::validate($requestTz);
            if ($clean !== null) {
                return $clean;
            }
        }

        // ── Priority 3: Server default ─────────────────────────
        $serverTz = date_default_timezone_get();
        if ($serverTz && $serverTz !== 'UTC') {
            $validated = self::validate($serverTz);
            if ($validated !== null) {
                return $validated;
            }
        }

        return self::DEFAULT_TZ;
    }

    /**
     * Compute time_of_day label from a resolved timezone.
     *
     *   morning   →  5:00 – 11:59
     *   afternoon → 12:00 – 16:59
     *   evening   → 17:00 – 20:59
     *   night     → 21:00 –  4:59
     *
     * @param  string $timezone  IANA timezone identifier
     * @return string
     */
    public static function getTimeOfDay(string $timezone): string
    {
        try {
            $dt   = new DateTime('now', new DateTimeZone($timezone));
            $hour = (int) $dt->format('G');
        } catch (\Throwable $e) {
            $hour = (int) date('G');  // fallback to server time
        }

        if ($hour >= 5  && $hour <= 11) return 'morning';
        if ($hour >= 12 && $hour <= 16) return 'afternoon';
        if ($hour >= 17 && $hour <= 20) return 'evening';
        return 'night';
    }

    /**
     * Compute day_of_week from a resolved timezone.
     *
     * @param  string $timezone
     * @return string  e.g. "monday", "saturday"
     */
    public static function getDayOfWeek(string $timezone): string
    {
        try {
            $dt = new DateTime('now', new DateTimeZone($timezone));
            return strtolower($dt->format('l'));
        } catch (\Throwable $e) {
            return strtolower(date('l'));
        }
    }

    /**
     * Get current local time string for logging/context snapshot.
     *
     * @param  string $timezone
     * @return string  e.g. "2026-04-09 19:34:21"
     */
    public static function getLocalTime(string $timezone): string
    {
        try {
            $dt = new DateTime('now', new DateTimeZone($timezone));
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return date('Y-m-d H:i:s');
        }
    }

    // ──────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────────────────

    /**
     * Look up user's stored timezone from brain_user_profiles.
     *
     * Returns null if user has no profile or profile has no timezone.
     */
    private static function fromUserProfile(string $userId, string $clientId): ?string
    {
        try {
            $db  = getDB();
            $sql = "
                SELECT timezone
                FROM   brain_user_profiles
                WHERE  user_id   = :user_id
                  AND  client_id = :client_id
                LIMIT  1
            ";
            $st = $db->prepare($sql);
            $st->execute([':user_id' => $userId, ':client_id' => $clientId]);
            $row = $st->fetch();

            if (!$row || empty($row['timezone'])) {
                return null;
            }

            return self::validate($row['timezone']);

        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Validate an IANA timezone string.
     *
     * Returns the cleaned string if valid, null if not.
     * Uses PHP's built-in DateTimeZone — no external libraries.
     */
    private static function validate(string $tz): ?string
    {
        $tz = trim($tz);
        if ($tz === '') return null;

        try {
            new DateTimeZone($tz);
            return $tz;
        } catch (\Exception $e) {
            return null;
        }
    }
}
