<?php
/**
 * ================================================================
 *  WorkToGo — HEART SYSTEM v1.2.0
 *  Pipeline Step 6 :: heart/response_builder.php
 *
 *  Merges all pipeline results into one JSON envelope.
 *  Response format is FIXED — all apps receive the same structure.
 *
 *  ALWAYS returns valid JSON. NEVER crashes.
 *
 *  v1.2.0 changes:
 *  - Debug mode controlled by caller ($debugMode param)
 *    (gated in router.php via HeartDebug::isEnabled())
 *  - Engine error blocks show generic message (not raw cURL codes)
 *  - fromCache() does not modify cached payload fields beyond request_id
 * ================================================================
 */

declare(strict_types=1);

// ── Response envelope ─────────────────────────────────────────
class HeartResponse
{
    public function __construct(
        private readonly array $payload,
        private readonly int   $httpCode = 200
    ) {}

    public function send(): void
    {
        http_response_code($this->httpCode);
        echo json_encode(
            $this->payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    public function toArray(): array
    {
        return $this->payload;
    }
}


// ── Builder ───────────────────────────────────────────────────
class ResponseBuilder
{
    /**
     * Build the final pipeline response.
     */
    public static function build(
        array $context,
        array $resolved,
        array $brainResult,
        array $engineResults,
        bool  $debugMode = false
    ): HeartResponse {
        $blocks = self::buildBlocks($context, $brainResult, $engineResults);
        $meta   = self::buildMeta($context, $engineResults, $resolved);

        $payload = [
            'ok'         => true,
            'request_id' => $context['request_id'],
            'intent'     => $context['intent'],
            'blocks'     => $blocks,
            'meta'       => $meta,
            '_cache'     => false,
        ];

        // Expose winner in meta
        $payload['meta']['winner'] = $resolved['winner_source'];

        // Brain warning (safe — no internal detail)
        if ($brainResult['fallback'] ?? false) {
            $payload['_warning'] = 'AI layer temporarily unavailable — using safe defaults.';
        }

        // v1.2.0: debug block only with valid admin key
        if ($debugMode) {
            $payload['_debug'] = [
                'brain'    => $brainResult,
                'engines'  => $engineResults,
                'resolved' => $resolved,
                'context'  => $context,
            ];
        }

        HeartLogger::info('Response built', [
            'request_id'  => $context['request_id'],
            'blocks'      => count($blocks),
            'winner'      => $resolved['winner_source'],
            'duration_ms' => round((microtime(true) - HEART_START) * 1000, 2),
        ]);

        return new HeartResponse($payload, 200);
    }

    /**
     * Return a cached (Reflex fast-return) response.
     */
    public static function fromCache(array $cached, array $context): HeartResponse
    {
        // Update only the request_id for tracing — preserve all other cached fields
        $cached['request_id'] = $context['request_id'];
        $cached['_cache']     = true;
        unset($cached['_cache_hit']);

        return new HeartResponse($cached, 200);
    }

    // ── Block builders ────────────────────────────────────────

    private static function buildBlocks(
        array $context,
        array $brainResult,
        array $engineResults
    ): array {
        $blocks = [];

        // Brain reasoning block (only if Brain was actually called)
        if (
            ($brainResult['source'] === 'brain')
            && !empty($brainResult['reasoning'])
            && is_string($brainResult['reasoning'])
        ) {
            $blocks[] = [
                'type'    => 'brain_insight',
                'content' => $brainResult['reasoning'],
                'score'   => $brainResult['score'],
            ];
        }

        // Engine result blocks
        foreach ($engineResults as $name => $result) {
            if (!($result['ok'] ?? false) || empty($result['data'])) {
                $blocks[] = [
                    'type'    => 'engine_error',
                    'engine'  => $name,
                    // v1.2.0: generic message — no raw internal error
                    'message' => 'This service is temporarily unavailable. Please try again shortly.',
                ];
                continue;
            }

            $blocks[] = self::engineBlock($name, $result['data'], $brainResult);
        }

        // Empty state — always at least one block
        if (empty($blocks)) {
            $blocks[] = [
                'type'    => 'empty',
                'message' => 'No results available. Please try again.',
            ];
        }

        return $blocks;
    }

    private static function engineBlock(
        string $name,
        array  $data,
        array  $brainResult
    ): array {
        $block = [
            'type'   => $name . '_results',
            'engine' => $name,
            'items'  => $data['items']   ?? $data['results'] ?? $data['data'] ?? [],
            'total'  => $data['total']   ?? $data['count']   ?? null,
        ];

        // Ensure items is always an array
        if (!is_array($block['items'])) {
            $block['items'] = [];
        }

        switch ($name) {
            case 'shopping':
                $block['featured']   = is_array($data['featured']   ?? null) ? $data['featured']   : [];
                $block['categories'] = is_array($data['categories'] ?? null) ? $data['categories'] : [];
                $block['sort']       = $brainResult['decision']['sort'] ?? 'relevance';
                break;

            case 'service':
                $block['available_slots'] = is_array($data['slots']     ?? null) ? $data['slots']     : [];
                $block['providers']       = is_array($data['providers'] ?? null) ? $data['providers'] : [];
                break;

            case 'delivery':
                $block['eta']      = $data['eta']      ?? null;
                $block['tracking'] = $data['tracking'] ?? null;
                $block['live_map'] = $data['map']      ?? null;
                break;

            case 'video':
                $block['stream_url'] = $data['stream_url']  ?? null;
                $block['thumbnails'] = is_array($data['thumbnails'] ?? null) ? $data['thumbnails'] : [];
                $block['duration']   = $data['duration']    ?? null;
                break;
        }

        return $block;
    }

    // ── Meta builder ─────────────────────────────────────────

    private static function buildMeta(
        array $context,
        array $engineResults,
        array $resolved
    ): array {
        $engineHealth = [];
        foreach ($engineResults as $name => $result) {
            $engineHealth[$name] = ($result['ok'] ?? false) ? 'ok' : 'error';
        }

        return [
            'pagination'     => $context['pagination'],
            'language'       => $context['user']['language'],
            'currency'       => $context['user']['currency'],
            'location_used'  => $context['location']['has_coords'],
            'time_period'    => $context['time']['period'],
            'timezone'       => $context['time']['timezone'],
            'engine_health'  => $engineHealth,
            'duration_ms'    => round((microtime(true) - HEART_START) * 1000, 2),
            'heart_version'  => HEART_VERSION,
            'conflict'       => $resolved['conflict'],
        ];
    }
}
