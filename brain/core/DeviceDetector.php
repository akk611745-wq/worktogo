<?php

/**
 * BrainCore - DeviceDetector
 *
 * DEVICE CONTEXT DETECTION
 *
 * ─── PURPOSE ─────────────────────────────────────────────────
 *
 *   Detects device type from the HTTP User-Agent header.
 *   Returns one of three values: mobile / tablet / desktop.
 *
 *   This value flows into:
 *     1. Decision context → rules can match on device_type
 *     2. UIBlockModel     → controls max_items per block
 *
 * ─── HOW DETECTION WORKS ─────────────────────────────────────
 *
 *   Pure string matching on User-Agent. No libraries.
 *   Order matters: tablet is checked before mobile because
 *   tablet UAs often contain "mobile" too (e.g. Android tablets).
 *
 *   Caller can also pass device_type explicitly in the request.
 *   Explicit value from caller WINS over auto-detected value.
 *   Reason: some clients (React Native apps) know their device
 *   better than the UA string does.
 *
 * ─── DETECTION SIGNALS ───────────────────────────────────────
 *
 *   TABLET  → "ipad", "tablet", "kindle", "silk", "playbook", "gt-p"
 *   MOBILE  → "mobile", "android" (non-tablet), "iphone", "ipod",
 *             "blackberry", "opera mini", "iemobile", "wpdesktop"
 *   DESKTOP → everything else
 *
 * ─── PUBLIC INTERFACE ────────────────────────────────────────
 *
 *   DeviceDetector::detect(?string $explicitValue = null): string
 *     → Returns 'mobile' | 'tablet' | 'desktop'
 *     → explicitValue: if caller sent device_type param, pass it here
 */

class DeviceDetector
{
    // Valid device_type values
    private const VALID_TYPES = ['mobile', 'tablet', 'desktop'];

    // ── Tablet signals (checked BEFORE mobile) ─────────────────
    // Tablets often include "mobile" in their UA — check tablet first.
    private const TABLET_SIGNALS = [
        'ipad', 'tablet', 'kindle', 'silk',
        'playbook', 'gt-p', 'sm-t', 'kfthwi',
    ];

    // ── Mobile signals ─────────────────────────────────────────
    private const MOBILE_SIGNALS = [
        'mobile', 'iphone', 'ipod', 'blackberry',
        'opera mini', 'iemobile', 'wpdesktop',
        'windows phone', 'palm', 'symbian',
    ];

    // ─────────────────────────────────────────────────────────────
    // PUBLIC: Detect device type
    // ─────────────────────────────────────────────────────────────

    /**
     * Detect the device type.
     *
     * Priority:
     *   1. Explicit caller value (if valid) — always wins
     *   2. User-Agent detection from $_SERVER['HTTP_USER_AGENT']
     *   3. 'desktop' as the safe default if UA is missing or unrecognised
     *
     * @param  string|null $explicitValue  Value from $_GET['device_type'] if sent
     * @return string  'mobile' | 'tablet' | 'desktop'
     */
    public static function detect(?string $explicitValue = null): string
    {
        // ── 1. Caller explicitly declared device type ──────────
        // Trust the caller — they know their environment.
        if ($explicitValue !== null) {
            $clean = strtolower(trim($explicitValue));
            if (in_array($clean, self::VALID_TYPES, true)) {
                return $clean;
            }
            // Invalid value supplied → fall through to auto-detect
        }

        // ── 2. Auto-detect from User-Agent ─────────────────────
        $ua = strtolower(
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        );

        if (empty($ua)) {
            return 'desktop'; // No UA → assume server/API call
        }

        // Check tablet first (many tablets include "mobile" in UA)
        foreach (self::TABLET_SIGNALS as $signal) {
            if (strpos($ua, $signal) !== false) {
                return 'tablet';
            }
        }

        // Check mobile
        foreach (self::MOBILE_SIGNALS as $signal) {
            if (strpos($ua, $signal) !== false) {
                return 'mobile';
            }
        }

        // Android without "mobile" keyword = Android tablet
        // e.g. "Mozilla/5.0 (Linux; Android 11; SM-T505)"
        if (strpos($ua, 'android') !== false && !strpos($ua, 'mobile') !== false) {
            return 'tablet';
        }

        // ── 3. Default: desktop ────────────────────────────────
        return 'desktop';
    }
}
