<?php
/**
 * ================================================================
 *  WorkToGo — HEART SYSTEM v1.2.0
 *  Pipeline Step 1 :: heart/context_builder.php
 *
 *  Converts raw HTTP payload → normalised context array.
 *  Context is passed UNCHANGED through every pipeline step.
 *
 *  v1.2.0 changes:
 *  - JWT extraction REMOVED (was unverified — security risk)
 *  - user_id comes from request payload ONLY
 *  - language validated against known BCP-47 codes
 *  - IP extraction respects TRUST_PROXY setting
 *  - data/filters array depth limited to prevent payload abuse
 * ================================================================
 */

declare(strict_types=1);

class ContextBuilder
{
    // Allowed 2-letter language codes (BCP-47 subset)
    private const ALLOWED_LANGS = [
        'hi', 'en', 'ta', 'te', 'ml', 'kn', 'mr', 'bn', 'gu', 'pa',
        'ur', 'or', 'as', 'ne', 'si', 'fr', 'de', 'es', 'pt', 'zh',
        'ja', 'ko', 'ar', 'ru', 'tr', 'vi', 'th', 'id', 'ms',
    ];

    /**
     * Build a normalised context from raw payload.
     * All pipeline components receive this same array — never modified after this.
     */
    public static function build(array $payload): array
    {
        return [
            'request_id' => self::generateId(),
            'timestamp'  => time(),
            'datetime'   => date('Y-m-d H:i:s'),

            // What the caller wants
            'intent'     => self::sanitiseString($payload['intent'] ?? ''),
            'query'      => self::sanitiseString($payload['query']  ?? ''),
            'filters'    => self::safeArray($payload['filters'] ?? null),
            'data'       => self::safeArray($payload['data']    ?? null),

            // Who is calling (NO JWT — user_id from payload only)
            'user'       => self::buildUser($payload),

            // Where they are
            'location'   => self::buildLocation($payload),

            // When (always IST)
            'time'       => self::buildTime(),

            // How they're connecting
            'channel'    => self::buildChannel($payload),

            // Session state
            'session'    => [
                'id'      => self::sanitiseString($payload['session_id'] ?? ''),
                'history' => self::safeArray($payload['history'] ?? null, maxDepth: 1),
            ],

            // Pagination
            'pagination' => self::buildPagination($payload),
        ];
    }

    // ── User ──────────────────────────────────────────────────
    private static function buildUser(array $p): array
    {
        // v1.2.0: user_id from request payload ONLY — no JWT parsing
        $userId = isset($p['user_id']) && is_string($p['user_id'])
            ? trim($p['user_id'])
            : null;

        // Reject suspiciously long user IDs
        if ($userId !== null && strlen($userId) > 128) {
            $userId = null;
        }

        $lang = $p['language'] ?? self::detectLang();
        $lang = in_array($lang, self::ALLOWED_LANGS, true) ? $lang : 'en';

        return [
            'id'           => $userId,
            'role'         => in_array($p['user_role'] ?? '', ['guest', 'customer', 'premium', 'admin'], true)
                                ? $p['user_role']
                                : 'guest',
            'language'     => $lang,
            'currency'     => preg_match('/^[A-Z]{3}$/', $p['currency'] ?? '') ? $p['currency'] : 'INR',
            'preferences'  => self::safeArray($p['preferences'] ?? null),
            'is_premium'   => (bool)($p['is_premium']  ?? false),
            'is_anonymous' => empty($userId),
        ];
    }

    // ── Location ─────────────────────────────────────────────
    private static function buildLocation(array $p): array
    {
        $lat = $p['lat'] ?? $p['latitude']  ?? $_SERVER['HTTP_X_GEO_LAT'] ?? null;
        $lng = $p['lng'] ?? $p['longitude'] ?? $_SERVER['HTTP_X_GEO_LNG'] ?? null;

        $latF = ($lat !== null && is_numeric($lat)) ? (float)$lat : null;
        $lngF = ($lng !== null && is_numeric($lng)) ? (float)$lng : null;

        // Validate coordinate ranges
        if ($latF !== null && ($latF < -90 || $latF > 90))   { $latF = null; }
        if ($lngF !== null && ($lngF < -180 || $lngF > 180)) { $lngF = null; }

        $city    = self::sanitiseString($p['city']    ?? $_SERVER['HTTP_X_GEO_CITY']    ?? '');
        $country = self::sanitiseString($p['country'] ?? $_SERVER['HTTP_X_GEO_COUNTRY'] ?? 'IN');

        // Validate country code
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            $country = 'IN';
        }

        return [
            'lat'        => $latF,
            'lng'        => $lngF,
            'city'       => $city,
            'state'      => self::sanitiseString($p['state']   ?? ''),
            'country'    => $country,
            'pincode'    => preg_match('/^\d{6}$/', $p['pincode'] ?? '') ? $p['pincode'] : null,
            'timezone'   => 'Asia/Kolkata',
            'has_coords' => ($latF !== null && $lngF !== null),
        ];
    }

    // ── Time (always Asia/Kolkata) ────────────────────────────
    private static function buildTime(): array
    {
        $tz   = new \DateTimeZone('Asia/Kolkata');
        $now  = new \DateTimeImmutable('now', $tz);
        $hour = (int)$now->format('G');
        $dow  = (int)$now->format('N');

        return [
            'unix'        => $now->getTimestamp(),
            'date'        => $now->format('Y-m-d'),
            'time_str'    => $now->format('H:i'),
            'hour'        => $hour,
            'day_of_week' => $dow,
            'is_weekend'  => in_array($dow, [6, 7], true),
            'timezone'    => 'Asia/Kolkata',
            'period'      => match(true) {
                $hour >= 5  && $hour < 12 => 'morning',
                $hour >= 12 && $hour < 17 => 'afternoon',
                $hour >= 17 && $hour < 21 => 'evening',
                default                   => 'night',
            },
        ];
    }

    // ── Channel ───────────────────────────────────────────────
    private static function buildChannel(array $p): array
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        return [
            'app'      => self::sanitiseString($p['app'] ?? $p['app_type'] ?? 'unknown'),
            'platform' => self::detectPlatform($ua),
            'version'  => isset($p['version']) ? self::sanitiseString($p['version']) : null,
            'ip'       => self::clientIp(),
        ];
    }

    // ── Pagination ────────────────────────────────────────────
    private static function buildPagination(array $p): array
    {
        $page    = max(1, (int)($p['page']     ?? 1));
        $perPage = min(100, max(1, (int)($p['per_page'] ?? 20)));

        return [
            'page'     => $page,
            'per_page' => $perPage,
            'offset'   => ($page - 1) * $perPage,
        ];
    }

    // ── Helpers ───────────────────────────────────────────────

    private static function generateId(): string
    {
        return 'hrt-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }

    private static function sanitiseString(mixed $val): string
    {
        if (!is_string($val)) return '';
        return mb_substr(trim($val), 0, 512);
    }

    private static function safeArray(mixed $val, int $maxDepth = 3): array
    {
        if (!is_array($val)) return [];
        // Flatten if too deep to prevent memory abuse
        return self::limitDepth($val, $maxDepth);
    }

    private static function limitDepth(array $arr, int $maxDepth): array
    {
        if ($maxDepth <= 0) return [];
        $result = [];
        $count  = 0;
        foreach ($arr as $k => $v) {
            if (++$count > 50) break; // max 50 keys per level
            $result[$k] = is_array($v) ? self::limitDepth($v, $maxDepth - 1) : $v;
        }
        return $result;
    }

    private static function detectLang(): string
    {
        $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en';
        $lang   = substr(preg_replace('/[^a-zA-Z].*/', '', $accept), 0, 2);
        return strtolower($lang) ?: 'en';
    }

    private static function detectPlatform(string $ua): string
    {
        return match(true) {
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'ios',
            str_contains($ua, 'Android')                             => 'android',
            str_contains($ua, 'Flutter')                             => 'flutter',
            str_contains($ua, 'Windows')                             => 'windows',
            str_contains($ua, 'Macintosh')                           => 'mac',
            default                                                  => 'web',
        };
    }

    private static function clientIp(): string
    {
        $trustProxy = (bool)(getenv('TRUST_PROXY') ?: false);
        if ($trustProxy && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}
