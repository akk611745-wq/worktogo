<?php
/**
 * AgentOrchestrator.php — WorkToGo Agent Layer :: turn runtime skeleton (foundation)
 * ----------------------------------------------------------------------------
 * The minimal "Dost runtime" for one turn. In this FOUNDATION phase it proves the
 * spine end-to-end and nothing more:
 *
 *     user message + internal AgentContext
 *        → PiiWall.sanitize()      (strip PII, build coarse envelope, fail-closed)
 *        → AiProviderManager.complete()  (pluggable provider, failover, breaker)
 *        → PiiWall.rehydrate()     (restore the user's own words for display)
 *        → reply
 *
 * There are NO tools/actions, NO memory, NO UI, NO voice yet — those are later
 * phases. The orchestrator's only cleverness here is being a faithful conductor:
 * it never lets raw user text reach a provider except through the wall, it
 * degrades cleanly when the AI is unavailable (system-first), and it finalises
 * one audit row per turn.
 *
 * ⚠ TEST-ONLY (audit L2): this foundation skeleton is NOT the production runtime.
 * The live entry (heart/api/agent/index.php) uses AgentActionOrchestrator. This is
 * retained only for foundation_selftest, which deliberately surfaces the mock's
 * echo to prove the wall's re-hydration round-trip (a real user never sees mock
 * text — the production planner guards that, see ToolPlanner::viaAi / audit M3).
 * Do NOT wire production traffic to this class.
 *
 * It is deliberately construction-injected (wall, manager, db) so it can be
 * driven headless by the self-test with db=null and a mock provider.
 */

declare(strict_types=1);

require_once __DIR__ . '/AgentExceptions.php';
require_once __DIR__ . '/AgentContext.php';
require_once __DIR__ . '/PiiWall.php';
require_once __DIR__ . '/ai/AiProviderManager.php';

final class AgentOrchestrator
{
    /** Static, developer-authored system instruction — contains NO user data. */
    private const SYSTEM_INSTRUCTION =
        "You are WorkToGo's friendly assistant. Understand the user's request in their own language "
        . "and reply briefly and warmly. Some details may appear as masked tags like <PHONE_1> or "
        . "<ADDR_1>; keep any such tags exactly as they are — do not guess what they contain. "
        . "You have no tools yet, so simply acknowledge what the user wants.";

    /** Public capability verbs advertised to the provider this phase (kept minimal). */
    private const CAPABILITIES = ['understand'];

    public function __construct(
        private readonly PiiWall            $wall,
        private readonly AiProviderManager  $manager,
        private readonly ?PDO               $db = null,
    ) {}

    /**
     * Run one turn. Returns a structured result for the caller to render.
     *
     * @throws PiiLeakException      if the wall refuses to send (fail-closed) — the
     *                               caller surfaces a safe "couldn't process" message.
     * @return array{reply:string,provider:string,model:?string,degraded:bool,failover:bool,request_id:string,redactions:array}
     */
    public function handle(AgentContext $ctx, string $userMessage): array
    {
        // 1. Wall: sanitize → sealed prompt. PiiLeakException propagates (refusal).
        $prompt = $this->wall->sanitize(
            $ctx,
            $userMessage,
            self::SYSTEM_INSTRUCTION,
            self::CAPABILITIES,
            'understand'
        );

        // 2. Provider (pluggable, with failover + breaker). Never throws.
        $resp = $this->manager->complete($prompt, 'understand');

        // 3. Finalise the audit row, then drop the request-scoped PII map.
        $this->finaliseAudit($ctx, $resp);

        if (!$resp->success) {
            // System-first degrade: the app keeps working without the AI.
            $redactions = $this->wall->redactionSummary($ctx->requestId);
            $this->wall->forget($ctx->requestId);
            return [
                'reply'      => "I'm having a little trouble understanding right now. "
                                . "Please try again in a moment.",
                'provider'   => $resp->provider,
                'model'      => $resp->model,
                'degraded'   => true,
                'failover'   => $resp->failover,
                'request_id' => $ctx->requestId,
                'redactions' => $redactions,
            ];
        }

        // 4. Re-hydrate for the OWNER (safe — their own data), then forget the map.
        $reply = $this->wall->rehydrate($ctx->requestId, $resp->text);
        $redactions = $this->wall->redactionSummary($ctx->requestId);
        $this->wall->forget($ctx->requestId);

        return [
            'reply'      => $reply,
            'provider'   => $resp->provider,
            'model'      => $resp->model,
            'degraded'   => false,
            'failover'   => $resp->failover,
            'request_id' => $ctx->requestId,
            'redactions' => $redactions,
        ];
    }

    /**
     * Complete the single audit row the wall opened at egress with the provider
     * outcome (provider, model, tokens, latency, status, failover). COUNTS only —
     * never raw content. Fully defensive: an audit miss never breaks a turn.
     */
    private function finaliseAudit(AgentContext $ctx, AiResponse $resp): void
    {
        if (!$this->db instanceof PDO) {
            return;
        }
        try {
            $stmt = $this->db->prepare(
                "UPDATE agent_ai_log
                    SET provider = :provider, model = :model,
                        prompt_tokens = :ptok, completion_tokens = :ctok,
                        latency_ms = :ms, status = :status, failover = :failover, error = :error
                  WHERE request_id = :rid"
            );
            $stmt->execute([
                ':provider' => $resp->provider,
                ':model'    => $resp->model,
                ':ptok'     => $resp->promptTokens,
                ':ctok'     => $resp->completionTokens,
                ':ms'       => $resp->latencyMs,
                ':status'   => $resp->success ? 'ok' : 'failed',
                ':failover' => $resp->failover ? 1 : 0,
                ':error'    => $resp->error ? mb_substr($resp->error, 0, 250) : null,
                ':rid'      => $ctx->requestId,
            ]);
        } catch (\Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warning('agent.audit finalise skipped', ['error' => $e->getMessage()]);
            }
        }
    }
}
