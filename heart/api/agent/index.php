<?php
/**
 * /api/agent/* — WorkToGo Agent Layer HTTP entry (foundation)
 * ----------------------------------------------------------------------------
 * The single, REGISTER-GATED front door to the agent. It is a thin, clean-scoped
 * entry that reuses the platform's own primitives (AuthMiddleware, Response,
 * CityResolver, RateLimiter) and delegates all logic to core/agent/*. It does NOT
 * touch any engine (service/shopping/video/orders) — this phase only proves the
 * privacy wall + the pluggable provider layer end to end.
 *
 * Dispatched from heart/index.php on prefix /api/agent, using the front-controller
 * globals $db (PDO), $method, $uri.
 *
 * Endpoints:
 *   POST /api/agent/message  — one turn (register-gated). Body: { message,
 *                              language?, session_id? } → { reply, provider, … }
 *   GET  /api/agent/status   — booleans only (enabled + which providers are
 *                              usable). No secrets. For verifying config/swap.
 *
 * Gating & safety, in order:
 *   1. REGISTER-GATE — AuthMiddleware::require(): no valid JWT ⇒ 401. The agent is
 *      only ever available to a registered user (number+OTP / existing identity).
 *   2. KILL-SWITCH — app_settings.agent_enabled must be truthy, else 503. Default
 *      is OFF, so the agent never spends or calls out until the founder enables it.
 *   3. RATE LIMIT — per-user, reusing the shared RateLimiter.
 *   4. PRIVACY WALL — every provider call goes through PiiWall (enforced by type).
 *      A wall refusal (PiiLeakException) returns a safe 422, never a leak.
 */

declare(strict_types=1);

require_once SYSTEM_ROOT . '/core/helpers/RateLimiter.php';
require_once SYSTEM_ROOT . '/core/agent/bootstrap.php';

/** Read a boolean-ish app_settings flag defensively (missing/unreadable ⇒ default). */
$agentSetting = function (string $key, bool $default) use ($db): bool {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        if ($val === false || $val === null || $val === '') {
            return $default;
        }
        return in_array(strtolower((string) $val), ['1', 'true', 'on', 'yes'], true);
    } catch (\Throwable $e) {
        return $default;
    }
};

try {

    // ── GET /api/agent/status — secrets-free health/config view (register-gated) ──
    if ($method === 'GET' && $uri === '/api/agent/status') {
        AuthMiddleware::require();                       // registered users only
        $manager = new AiProviderManager($db);
        $providers = [];
        foreach ($manager->knownProviders() as $name) {
            $providers[$name] = $manager->isUsable($name); // booleans only — never a key
        }
        Response::success([
            'enabled'    => $agentSetting('agent_enabled', false),
            'providers'  => $providers,                    // e.g. { mock:true, anthropic:false, … }
            'routing'    => [
                'understand' => $manager->routeFor('understand'),
                'generate'   => $manager->routeFor('generate'),
            ],
        ]);
        exit;
    }

    // ── POST /api/agent/message — one turn ───────────────────────────────────────
    if ($method === 'POST' && $uri === '/api/agent/message') {
        // 1. Register-gate.
        $auth   = AuthMiddleware::require();
        $userId = (int) ($auth['user_id'] ?? 0);

        // Governance: everything the admin control-room decides, loaded fresh.
        $policy = new AgentPolicy($db);
        $limits = $policy->limits();
        $guards = $policy->guardrails();

        // 2. Kill-switch (default OFF — never spends until the founder enables it).
        if (!$policy->agentEnabled()) {
            Response::unavailable('The assistant is not enabled yet.');
        }

        // 3. Per-user rate limit (admin-configurable).
        if (!RateLimiter::check('agent_msg_' . $userId, $limits['msg_per_min'], 60)) {
            Response::tooManyRequests('You are messaging too fast. Please wait a moment.');
        }

        // 4. Validate input. A turn is a message, an explicit action (tapped
        //    card), or a confirmation of a pending write — at least one.
        $body    = json_decode(file_get_contents('php://input'), true) ?: [];
        $message = is_string($body['message'] ?? null) ? trim($body['message']) : '';
        $action  = is_array($body['action'] ?? null) ? $body['action'] : null;
        $confirm = is_string($body['confirm_token'] ?? null) ? $body['confirm_token'] : '';
        $event   = is_string($body['event'] ?? null) ? trim($body['event']) : '';
        $session = is_string($body['session_id'] ?? null) ? trim($body['session_id']) : '';
        if ($message === '' && $action === null && $confirm === '' && $event === '') {
            Response::validation('A message, action, confirm_token, or event is required.');
        }
        if (mb_strlen($message) > $guards['max_message_chars']) {
            Response::validation('Message is too long (max ' . $guards['max_message_chars'] . ' characters).');
        }

        // 4b. Per-session action cap (admin-configurable). Only real turns count.
        if ($session !== '' && $event === ''
            && !RateLimiter::check('agent_sess_' . substr($session, 0, 64), $limits['actions_per_session'], 3600)) {
            Response::tooManyRequests('This session has reached its limit. Please start a new chat.');
        }

        // 5. Forward the caller's own JWT to the backend so the engine authorizes
        //    the REAL user (RBAC/ownership/city). The agent never elevates.
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        $bearer     = str_starts_with($authHeader, 'Bearer ') ? trim(substr($authHeader, 7)) : null;

        // 6. Build the action runtime: wall + planner + tool registry + engine.
        $wall         = new PiiWall($db);
        $manager      = new AiProviderManager($db);
        $registry     = ToolRegistry::defaultRegistry();
        $planner      = new ToolPlanner($wall, $manager, $registry);
        $engine       = new HttpEngineClient($bearer);
        $memory       = new AgentMemory($db);
        $demand       = new DemandSignals($db);   // Phase 9: capture demand signals (our data only)
        $orchestrator = new AgentActionOrchestrator($wall, $planner, $registry, $engine, $db, $memory, $policy, $demand);
        $ctx          = AgentContext::fromAuth($auth, $body, $db);

        try {
            $result = $orchestrator->handle($ctx, [
                'event'         => $event,
                'message'       => $message,
                'action'        => $action,
                'confirm_token' => $confirm,
            ]);
        } catch (PiiLeakException $e) {
            // Fail-closed: nothing was sent. Ask the user to rephrase — never echo the reason.
            Logger::warning('agent.pii_blocked', ['request_id' => $ctx->requestId]);
            // Record the block (metadata only) so the control-room monitor can see it.
            try {
                $db->prepare(
                    "INSERT INTO agent_action_log (request_id, user_id, tool, mode, source, status, http_status, created_at)
                     VALUES (?, ?, '-', 'chat', 'wall', 'pii_blocked', 0, NOW())"
                )->execute([$ctx->requestId, $ctx->userId]);
            } catch (\Throwable $ignored) {}
            Response::error(
                "Sorry, I couldn't process that safely. Please try again without sensitive details "
                . "like phone numbers or IDs.",
                422,
                'PII_BLOCKED'
            );
        }

        Response::success($result);
        exit;
    }

    Response::notFound('Agent endpoint');

} catch (AgentDisabledException $e) {
    Response::unavailable('The assistant is not enabled yet.');
} catch (\Throwable $e) {
    error_log('[api/agent] ' . $e->getMessage());
    Response::unavailable('The assistant is unavailable right now.');
}
