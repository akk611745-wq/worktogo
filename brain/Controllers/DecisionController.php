<?php

/**
 * BrainCore v3 — DecisionController
 * PHP 7.2+ compatible
 *
 * Handles: GET /api/decision
 * Auth: X-Api-Key header + client_id param
 */

class DecisionController
{
    public static function decide(): void
    {
        // ── 1. Read X-Api-Key header ───────────────────────────
        $apiKey = trim($_SERVER['HTTP_X_API_KEY'] ?? '');
        if ($apiKey === '') {
            Response::error('Missing required header: X-Api-Key', 401);
        }

        // ── 2. Validate client ─────────────────────────────────
        $input    = $_GET;
        $clientId = trim($input['client_id'] ?? '');

        if ($clientId === '') {
            Response::error('Missing required param: client_id', 422);
        }

        if (!ClientModel::validateApiKey($clientId, $apiKey)) {
            Response::error('Invalid client_id or X-Api-Key', 401);
        }

        // ── 3. Rate limit ──────────────────────────────────────
        if (!RateLimiter::isAllowed($clientId, '/api/decision')) {
            $meta = RateLimiter::getMeta();
            Response::error(
                "Rate limit exceeded. Max {$meta['limit']} requests per {$meta['window']}.",
                429
            );
        }

        // ── 4. Build context ───────────────────────────────────
        $context = ContextBuilder::build($input, $clientId);

        // ── 5. Resolve decision ────────────────────────────────
        $decisionResult = DecisionModel::resolve($context);
        $decision       = $decisionResult['decision'];
        $fromCache      = $decisionResult['from_cache'];

        // ── 6. Log only on non-cache hit ───────────────────────
        $decisionId = null;
        if (!$fromCache) {
            $decisionId = DecisionModel::log(
                $clientId,
                $context,
                $decision,
                $decision['rule_matched'] ?? null
            );
        }

        // ── 7. Execute actions (rule matches only) ─────────────
        if (!$fromCache && ($decision['source'] ?? '') === 'rule_engine' && $decisionId) {
            ActionEngine::execute($decisionId, $clientId, $decision);
        }

        // ── 8. Build UI blocks ─────────────────────────────────
        $deviceType = $context['device_type'];
        $blocks     = UIBlockModel::build($decision, $clientId, $deviceType);

        // ── 9. Return Heart-compatible response ────────────────
        Response::success([
            'blocks' => $blocks,
            'meta'   => [
                'decision_id' => $decisionId,
                'source'      => $decision['source'] ?? 'fallback',
                'timezone'    => $context['timezone'],
                'time_of_day' => $context['time_of_day'],
                'day_of_week' => $context['day_of_week'],
                'device_type' => $deviceType,
                'cached'      => $fromCache,
            ],
        ]);
    }

    /**
     * POST /api/decision — Heart integration entry point.
     *
     * Accepts JSON body from Heart's BrainConnector.
     * Auth:    X-Api-Key header + client_id in JSON body.
     * Returns: Heart-compatible envelope with decision, score,
     *          confidence, reasoning, and engine_hints.
     *
     * FIX 3: HTTP method alignment (POST + JSON body).
     * FIX 4: Auth reads X-Api-Key header (Heart sends this).
     * FIX 5: Response shaped to match Heart's normalise() expectations.
     */
    public static function decidePost(): void
    {
        // ── 1. Read X-Api-Key header ───────────────────────────
        $apiKey = trim($_SERVER['HTTP_X_API_KEY'] ?? '');
        if ($apiKey === '') {
            Response::error('Missing required header: X-Api-Key', 401);
        }

        // ── 2. Parse JSON body ─────────────────────────────────
        $raw   = defined('HEART_INTERNAL_INC') ? ($GLOBALS['HEART_PAYLOAD'] ?? '') : file_get_contents('php://input');
        $input = json_decode((string)$raw, true);
        if (!is_array($input)) {
            Response::error('Request body must be valid JSON.', 400);
        }

        $clientId = trim($input['client_id'] ?? '');
        if ($clientId === '') {
            Response::error('Missing required field: client_id', 422);
        }

        if (!ClientModel::validateApiKey($clientId, $apiKey)) {
            Response::error('Invalid client_id or X-Api-Key', 401);
        }

        // ── 3. Rate limit ──────────────────────────────────────
        if (!RateLimiter::isAllowed($clientId, '/api/decision')) {
            $meta = RateLimiter::getMeta();
            Response::error(
                "Rate limit exceeded. Max {$meta['limit']} requests per {$meta['window']}.",
                429
            );
        }

        // ── 4. Map Heart context → Brain context ───────────────
        // Heart sends a rich context object; Brain needs flat GET-style params.
        $brainInput = [
            'client_id'   => $clientId,
            'location'    => $input['location']['city']  ?? $input['location']['state'] ?? null,
            'category'    => $input['filters']['category'] ?? $input['data']['category'] ?? null,
            'user_id'     => $input['user']['id']        ?? null,
            'tz'          => $input['time']['timezone']  ?? $input['location']['timezone'] ?? null,
            'device_type' => $input['channel']['platform'] ?? null,
            'intent'      => $input['intent']            ?? null,
        ];

        $context = ContextBuilder::build($brainInput, $clientId);

        // ── 5. Resolve decision ────────────────────────────────
        $decisionResult = DecisionModel::resolve($context);
        $decision       = $decisionResult['decision'];
        $fromCache      = $decisionResult['from_cache'];

        // ── 6. Log ────────────────────────────────────────────
        $decisionId = null;
        if (!$fromCache) {
            $decisionId = DecisionModel::log(
                $clientId,
                $context,
                $decision,
                $decision['rule_matched'] ?? null
            );
        }

        // ── 7. Execute actions ─────────────────────────────────
        if (!$fromCache && ($decision['source'] ?? '') === 'rule_engine' && $decisionId) {
            ActionEngine::execute($decisionId, $clientId, $decision);
        }

        // ── 8. Build UI blocks ─────────────────────────────────
        $deviceType = $context['device_type'];
        $blocks     = UIBlockModel::build($decision, $clientId, $deviceType);

        // ── 9. FIX 5: Return Heart-normalise()-compatible structure ──
        // Heart's BrainConnector::normalise() reads these top-level keys:
        //   decision, score, confidence, reasoning, engine_hints, metadata
        $source    = $decision['source'] ?? 'fallback';
        $reasoning = 'Brain decision: ' . $source
            . ' | rule: ' . ($decision['rule_name'] ?? 'none')
            . ' | tod: '  . ($context['time_of_day'] ?? '?');

        // Build engine_hints from decision (pass boost signals to engines)
        $engineHints = [];
        if (!empty($decision['boost_category'])) {
            $engineHints['shopping'] = ['boost_category' => $decision['boost_category']];
            $engineHints['service']  = ['boost_category' => $decision['boost_category']];
        }
        if (!empty($decision['banner'])) {
            $engineHints['shopping']['banner'] = $decision['banner'];
        }

        Response::success([
            'decision'     => [
                'blocks'    => $blocks,
                'source'    => $source,
                'rule'      => $decision['rule_name'] ?? null,
                'cached'    => $fromCache,
                'sort'      => $decision['sort']      ?? 'relevance',
                'banner'    => $decision['banner']    ?? null,
            ],
            'score'        => (float) ($decision['score'] ?? ($source === 'rule_engine' ? 0.85 : 0.5)),
            'confidence'   => (float) ($decision['confidence'] ?? ($source === 'rule_engine' ? 0.9 : 0.6)),
            'reasoning'    => $reasoning,
            'engine_hints' => $engineHints,
            'metadata'     => [
                'decision_id' => $decisionId,
                'timezone'    => $context['timezone'],
                'time_of_day' => $context['time_of_day'],
                'day_of_week' => $context['day_of_week'],
                'device_type' => $deviceType,
            ],
        ]);
    }
}
