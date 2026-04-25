<?php

/**
 * BrainCore - FallbackResponse
 *
 * Safe default response when no rule matches.
 *
 * ─── PURPOSE ─────────────────────────────────────────────────
 *
 * Previously the fallback was hardcoded inside DecisionModel::fallbackDecision().
 * Now it is a separate class so:
 *   (a) Fallback logic can be tested independently
 *   (b) Future: fallback can be DB-configurable per client
 *   (c) Python migration: this is the first thing to port
 *
 * ─── FALLBACK STRATEGY ───────────────────────────────────────
 *
 *   Priority (most specific → least specific):
 *   1. Client-specific fallback (future: from DB)
 *   2. Category-aware fallback (if category in context)
 *   3. Global safe default
 *
 *   The fallback is always safe — it never returns null fields
 *   and always includes source = 'fallback' for audit.
 *
 * ─── PYTHON MIGRATION NOTE ───────────────────────────────────
 *
 *   Port this class as: braincore/fallback.py
 *   Input:  context dict
 *   Output: decision dict (same structure as RuleEngine output)
 */

class FallbackResponse
{
    /**
     * Build a safe fallback decision for the given context.
     *
     * @param  array  $context   Full request context
     * @param  string $reason    Why fallback triggered: 'no_rule_match' | 'engine_error'
     * @return array             Decision array — same shape as RuleEngine match
     */
    public static function build(array $context, string $reason = 'no_rule_match'): array
    {
        // Category-aware fallback: if we know the category, boost it
        $boostCategory = 'trending';
        if (!empty($context['category'])) {
            $boostCategory = $context['category']; // Boost what user is browsing
        }

        // Time-aware fallback banner
        $banner = self::resolveDefaultBanner($context['time_of_day'] ?? null);

        DecisionLogger::info('fallback.triggered', [
            'client' => $context['client_id'] ?? 'unknown',
            'reason' => $reason,
            'tod'    => $context['time_of_day'] ?? null,
            'cat'    => $context['category']    ?? null,
        ]);

        return [
            'banner'         => $banner,
            'boost_category' => $boostCategory,
            'action'         => 'none',
            'rule_matched'   => null,
            'rule_name'      => null,
            'source'         => 'fallback',
            'fallback_reason'=> $reason,
        ];
    }

    /**
     * Time-aware default banner selection.
     * Returns a generic banner name — frontend handles actual rendering.
     */
    private static function resolveDefaultBanner(?string $timeOfDay): string
    {
        if ($timeOfDay === 'morning')   return 'morning_deals';
        if ($timeOfDay === 'evening')   return 'evening_specials';
        return 'general';
    }
}
