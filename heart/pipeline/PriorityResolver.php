<?php
/**
 * ================================================================
 *  WorkToGo — HEART SYSTEM v1.2.0
 *  Pipeline Step 5 :: heart/priority_resolver.php
 *
 *  Resolves the "winning" response when multiple sources
 *  (Reflex, Brain, Engines) provide results.
 *
 *  Priority order: Reflex > Brain > Engine > Default
 *  Weights are deterministic — same inputs always same winner.
 *
 *  Returns a flat resolved array passed to ResponseBuilder.
 *  Always returns a result — never throws or returns null.
 * ================================================================
 */

declare(strict_types=1);

class PriorityResolver
{
    // Source weights — higher wins
    private const WEIGHTS = [
        'reflex_cache'   => 100,
        'reflex_habit'   => 90,
        'brain'          => 70,
        'engine'         => 50,
        'fallback'       => 20,
        'not_configured' => 15,
        'skipped'        => 10,
        'default'        => 5,
    ];

    // Conflict threshold: top-2 within this gap = conflict flag
    private const CONFLICT_GAP = 10;

    /**
     * Resolve the winning result from all pipeline sources.
     *
     * @param  array|null $reflexResult   From ReflexEngine::check()
     * @param  array      $brainResult    From BrainConnector::call()
     * @param  array      $engineResults  From EngineLoader::dispatch()
     * @return array                      Resolved result with winner_source
     */
    public static function resolve(
        ?array $reflexResult,
        array  $brainResult,
        array  $engineResults
    ): array {
        $candidates = [];

        // ── Reflex candidate ─────────────────────────────────
        if ($reflexResult !== null) {
            $src    = $reflexResult['_source'] ?? 'reflex_cache';
            $score  = (float)($reflexResult['_score'] ?? 3);
            $weight = min(100, (self::WEIGHTS[$src] ?? 90) + (int)$score);
            $candidates[] = [
                'source'   => $src,
                'priority' => $weight,
                'data'     => $reflexResult,
                'type'     => 'reflex',
            ];
        }

        // ── Brain candidate ───────────────────────────────────
        $brainSrc  = $brainResult['source'] ?? 'skipped';
        $brainConf = (float)($brainResult['confidence'] ?? 0.0);
        $brainWeight = match($brainSrc) {
            'brain'    => min(99, (int)round(self::WEIGHTS['brain'] * max(0.0, $brainConf))),
            'fallback' => self::WEIGHTS['fallback'],
            default    => self::WEIGHTS[$brainSrc] ?? self::WEIGHTS['skipped'],
        };
        $candidates[] = [
            'source'   => $brainSrc,
            'priority' => $brainWeight,
            'data'     => $brainResult,
            'type'     => 'brain',
        ];

        // ── Engine candidates ─────────────────────────────────
        foreach ($engineResults as $name => $result) {
            if (!($result['ok'] ?? false)) {
                continue; // Skip failed engines
            }
            $relevance = min(1.0, max(0.0, (float)($result['data']['relevance'] ?? 1.0)));
            $weight    = min(69, (int)round(self::WEIGHTS['engine'] * $relevance));
            $candidates[] = [
                'source'   => 'engine:' . $name,
                'priority' => $weight,
                'data'     => $result,
                'type'     => 'engine',
                'engine'   => $name,
            ];
        }

        // ── Guaranteed default fallback ───────────────────────
        $candidates[] = [
            'source'   => 'default',
            'priority' => self::WEIGHTS['default'],
            'data'     => ['status' => 'ok'],
            'type'     => 'default',
        ];

        // Sort descending by priority (stable — PHP usort is stable in 8.0+)
        usort($candidates, fn($a, $b) => $b['priority'] <=> $a['priority']);

        $winner   = $candidates[0];
        $conflict = count($candidates) >= 2
            && abs($candidates[0]['priority'] - $candidates[1]['priority']) < self::CONFLICT_GAP;

        if ($conflict) {
            HeartLogger::warn('Priority conflict — multiple sources close in weight', [
                'winner'    => $winner['source'],
                'priority'  => $winner['priority'],
                'runner_up' => $candidates[1]['source'],
            ]);
        }

        return [
            'winner_source'  => $winner['source'],
            'winner_type'    => $winner['type'],
            'winner_data'    => $winner['data'],
            'priority'       => $winner['priority'],
            'conflict'       => $conflict,
            'all_candidates' => $candidates,
        ];
    }
}
