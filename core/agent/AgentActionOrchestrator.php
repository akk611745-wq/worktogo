<?php
/**
 * AgentActionOrchestrator.php — WorkToGo Agent Layer :: the action runtime (Phase 2)
 * ----------------------------------------------------------------------------
 * The turn runtime that lets the agent DO real work, safely. It is the single
 * choke point where the AI's PROPOSAL becomes an authorized, executed action:
 *
 *   message ─▶ ToolPlanner (system-first, else AI via the privacy wall)
 *           ─▶ [ALLOW-LIST + RBAC] the proposed tool must be exposed to the role
 *           ─▶ read  : execute now via the real backend (reads run freely)
 *              write : DO NOT execute — issue a signed confirmation token
 *           ─▶ confirm_token ─▶ verify (same user, unexpired) ─▶ execute write
 *           ─▶ audit every step ; re-hydrate the reply for the owner
 *
 * The AI decides NOTHING about data or money. It only proposes language→tool.
 * Every tool call goes through the backend, which authorizes (RBAC/ownership/
 * city), validates and executes. Writes additionally require the user's explicit
 * confirmation. The AI can never reach a tool we did not expose to the role.
 */

declare(strict_types=1);

require_once __DIR__ . '/AgentExceptions.php';
require_once __DIR__ . '/AgentContext.php';
require_once __DIR__ . '/PiiWall.php';
require_once __DIR__ . '/ToolPlanner.php';
require_once __DIR__ . '/AgentMemory.php';
require_once __DIR__ . '/AgentPolicy.php';
require_once __DIR__ . '/tools/ToolRegistry.php';
require_once __DIR__ . '/tools/AgentTool.php';   // defines ToolContext + AgentTool
require_once __DIR__ . '/tools/EngineClient.php';
require_once __DIR__ . '/tools/ConfirmToken.php';

final class AgentActionOrchestrator
{
    public function __construct(
        private readonly PiiWall      $wall,
        private readonly ToolPlanner  $planner,
        private readonly ToolRegistry $registry,
        private readonly EngineClient $engine,
        private readonly ?PDO         $db = null,
        private readonly ?AgentMemory $memory = null,
        private readonly ?AgentPolicy $policy = null,
    ) {}

    /**
     * Handle one turn.
     *
     * @param array $input { message?, action?{tool,args}, confirm_token? }
     * @return array a structured result the client renders
     */
    public function handle(AgentContext $ctx, array $input): array
    {
        $tc = new ToolContext($ctx, $this->engine, $this->db);

        // Load this user's memory ONCE (internal only). Used for the personalized
        // opener, and to derive the anonymized hints the planner may send the AI.
        $mem   = $this->memory ? $this->memory->load($ctx->userId) : [];
        $hints = $this->memory ? $this->memory->hints($mem) : [];

        // ── Path 0: session open → a personalized greeting (from OUR memory) ─────
        if (($input['event'] ?? '') === 'open') {
            $g = $this->memory
                ? $this->memory->personalizedGreeting($mem, 'dost', $ctx->role)
                : ['reply' => 'Namaste! 👋 Bataiye kya chahiye?', 'chips' => [], 'personalized' => false];
            $this->audit($ctx, '-', 'chat', 'open', 'chat', 0, [], null);
            return $this->finish($ctx, [
                'reply'        => $g['reply'],
                'tool'         => null,
                'executed'     => false,
                'chips'        => $g['chips'] ?? [],
                'personalized' => (bool) ($g['personalized'] ?? false),
            ]);
        }

        // ── Path A: confirmation of a pending WRITE ──────────────────────────────
        $confirm = is_string($input['confirm_token'] ?? null) ? $input['confirm_token'] : '';
        if ($confirm !== '') {
            return $this->executeConfirmed($ctx, $tc, $confirm);
        }

        // ── Path B: an explicit client-chosen tool (e.g. a tapped card) ──────────
        if (is_array($input['action'] ?? null) && !empty($input['action']['tool'])) {
            $tool = (string) $input['action']['tool'];
            $args = is_array($input['action']['args'] ?? null) ? $input['action']['args'] : [];
            return $this->dispatchProposal($ctx, $tc, $tool, $args, '', 'client');
        }

        // ── Path C: a natural-language message → plan a tool ─────────────────────
        $message = is_string($input['message'] ?? null) ? trim($input['message']) : '';
        if ($message === '') {
            return $this->chat($ctx, 'Tell me what you need — for example, "painter chahiye".', 'none');
        }

        // The planner gets only the anonymized hints (never the memory row). When
        // the AI budget is exhausted (admin auto-pause), it runs deterministic-only.
        $aiAllowed = !$this->policy || $this->policy->aiAllowed();
        $plan = $this->planner->plan($ctx, $message, $hints, $aiAllowed);
        if ($plan->tool === null) {
            return $this->chat($ctx, $plan->say !== '' ? $plan->say : 'How can I help?', $plan->source);
        }
        return $this->dispatchProposal($ctx, $tc, $plan->tool, $plan->args, $plan->say, $plan->source);
    }

    // ── Dispatch a proposed tool: enforce allow-list, then read/execute or gate ──
    private function dispatchProposal(
        AgentContext $ctx, ToolContext $tc, string $toolName, array $args, string $say, string $source
    ): array {
        // GUARDRAIL: allow-list + RBAC. The AI/client cannot reach a tool that is
        // not exposed to this role.
        if (!$this->registry->isExposedTo($toolName, $ctx->role)) {
            $this->audit($ctx, $toolName, '-', $source, 'rejected', 0, array_keys($args), 'not exposed to role');
            return $this->finish($ctx, [
                'reply'    => "Sorry, I can't do that.",
                'tool'     => $toolName,
                'rejected' => true,
                'executed' => false,
            ]);
        }

        $tool = $this->registry->get($toolName);

        // GOVERNANCE: an admin may have paused this capability (or all writes).
        if ($blocked = $this->policyBlock($ctx, $toolName, $tool->mode(), $source)) {
            return $blocked;
        }

        // WRITE: never execute now — require explicit confirmation. The confirm is
        // also surfaced as a unified `widget` so the UI has one renderer for all
        // inline actions (confirm / upload / otp).
        if ($tool->mode() === 'write') {
            $token = ConfirmToken::issue($ctx->userId, $toolName, $args);
            $this->audit($ctx, $toolName, 'write', $source, 'proposed', 0, array_keys($args), null);
            $reply = $say !== '' ? $say : 'Please confirm to proceed.';
            return $this->finish($ctx, [
                'reply'                 => $reply,
                'tool'                  => $toolName,
                'requires_confirmation' => true,
                'confirm_token'         => $token,
                'args_summary'          => array_keys($args),
                'executed'              => false,
                'widget'                => ['type' => 'confirm', 'question' => $reply, 'confirm_token' => $token],
            ]);
        }

        // READ: run freely through the backend.
        $result = $tool->run($tc, $args);
        if ($result->ok && $this->memory) {
            $this->memory->record($ctx, $toolName, $args); // learn from this turn (internal)
        }
        return $this->respondFromResult($ctx, $toolName, 'read', $source, $result, $say);
    }

    // ── Execute a confirmed WRITE ────────────────────────────────────────────────
    private function executeConfirmed(AgentContext $ctx, ToolContext $tc, string $token): array
    {
        $payload = ConfirmToken::verify($token, $ctx->userId);
        if ($payload === null) {
            $this->audit($ctx, '-', 'write', 'confirm', 'rejected', 0, [], 'invalid/expired confirm token');
            return $this->finish($ctx, [
                'reply'    => "That confirmation has expired or is invalid. Please try again.",
                'executed' => false,
            ]);
        }

        $toolName = $payload['tool'];
        $args     = $payload['args'];

        // Re-check the allow-list at execution time (defence in depth).
        if (!$this->registry->isExposedTo($toolName, $ctx->role)) {
            $this->audit($ctx, $toolName, 'write', 'confirm', 'rejected', 0, array_keys($args), 'not exposed to role');
            return $this->finish($ctx, ['reply' => "Sorry, I can't do that.", 'tool' => $toolName, 'executed' => false]);
        }

        $tool = $this->registry->get($toolName);
        if ($tool->mode() !== 'write') {
            // A confirm token should only ever carry a write.
            $this->audit($ctx, $toolName, $tool->mode(), 'confirm', 'rejected', 0, array_keys($args), 'non-write confirm');
            return $this->finish($ctx, ['reply' => "Sorry, that action can't be confirmed.", 'tool' => $toolName, 'executed' => false]);
        }

        // GOVERNANCE re-check at execution time (capability / writes may have been
        // paused between propose and confirm).
        if ($blocked = $this->policyBlock($ctx, $toolName, 'write', 'confirm')) {
            return $blocked;
        }

        $result = $tool->run($tc, $args);
        if ($result->ok && $this->memory) {
            $this->memory->record($ctx, $toolName, $args); // learn from this confirmed action
        }
        return $this->respondFromResult($ctx, $toolName, 'write', 'confirm', $result, 'Done.');
    }

    // ── Shape a reply from an engine result ──────────────────────────────────────
    private function respondFromResult(
        AgentContext $ctx, string $toolName, string $mode, string $source, EngineResult $result, string $okSay
    ): array {
        if (!$result->ok) {
            $this->audit($ctx, $toolName, $mode, $source, 'failed', $result->status, [], $result->error);
            return $this->finish($ctx, [
                'reply'    => $this->friendlyError($result),
                'tool'     => $toolName,
                'executed' => false,
                'degraded' => true,
            ]);
        }

        $this->audit($ctx, $toolName, $mode, $source, 'executed', $result->status, [], null);
        $data = is_array($result->data) ? $result->data : [];
        $out  = [
            'reply'    => $okSay !== '' ? $okSay : 'Here you go.',
            'tool'     => $toolName,
            'executed' => true,
            'data'     => $data,           // OUR real data → to the user (never went to the AI)
        ];
        // A widget tool returns a UI-action descriptor under `_widget`; lift it to
        // the top level so the chat UI can render + complete it inline.
        if (isset($data['_widget']) && is_array($data['_widget'])) {
            $out['widget'] = $data['_widget'];
            unset($out['data']['_widget']);
        }
        return $this->finish($ctx, $out);
    }

    private function chat(AgentContext $ctx, string $say, string $source): array
    {
        $this->audit($ctx, '-', 'chat', $source, 'chat', 0, [], null);
        return $this->finish($ctx, ['reply' => $say, 'tool' => null, 'executed' => false]);
    }

    /** Re-hydrate the reply for the owner and drop the request-scoped PII map. */
    private function finish(AgentContext $ctx, array $out): array
    {
        if (isset($out['reply']) && is_string($out['reply'])) {
            $out['reply'] = $this->wall->rehydrate($ctx->requestId, $out['reply']);
        }
        $out['request_id'] = $ctx->requestId;
        $this->wall->forget($ctx->requestId);
        return $out;
    }

    /**
     * Data-driven governance gate: returns a finished refusal if the admin has
     * paused this capability or all writes, else null. (The kill-switch itself is
     * enforced at the entry.)
     */
    private function policyBlock(AgentContext $ctx, string $toolName, string $mode, string $source): ?array
    {
        if ($this->policy === null) {
            return null;
        }
        $group = $this->policy->groupForTool($toolName);
        if (!$this->policy->capabilityEnabled($group)) {
            $this->audit($ctx, $toolName, $mode, $source, 'blocked', 0, [], 'capability paused: ' . $group);
            return $this->finish($ctx, [
                'reply'    => "Yeh feature abhi kuch der ke liye paused hai. Thodi der baad try kijiye. 🙏",
                'tool'     => $toolName,
                'blocked'  => true,
                'executed' => false,
            ]);
        }
        if ($mode === 'write' && !$this->policy->writesEnabled()) {
            $this->audit($ctx, $toolName, $mode, $source, 'blocked', 0, [], 'writes paused');
            return $this->finish($ctx, [
                'reply'    => "Abhi nayi booking/order/change temporarily paused hai — aap dekh sakte hain, baad mein kar sakte hain. 🙂",
                'tool'     => $toolName,
                'blocked'  => true,
                'executed' => false,
            ]);
        }
        return null;
    }

    private function friendlyError(EngineResult $r): string
    {
        // The backend's own message is safe to surface (no secrets); fall back generically.
        return $r->error && $r->status >= 400 && $r->status < 500
            ? $r->error
            : "I couldn't complete that right now. Please try again in a moment.";
    }

    /**
     * Audit one action step — tool + mode + status + arg KEYS only (never values,
     * never PII). Defensive: a missing table or write error never breaks a turn.
     */
    private function audit(
        AgentContext $ctx, string $tool, string $mode, string $source, string $status,
        int $httpStatus, array $argKeys, ?string $error
    ): void {
        if (class_exists('Logger')) {
            Logger::info('agent.action', [
                'request_id' => $ctx->requestId, 'user_id' => $ctx->userId,
                'tool' => $tool, 'mode' => $mode, 'source' => $source,
                'status' => $status, 'http' => $httpStatus,
            ]);
        }
        if (!$this->db instanceof PDO) {
            return;
        }
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO agent_action_log
                    (request_id, user_id, tool, mode, source, status, http_status, arg_keys, error, created_at)
                 VALUES (:rid, :uid, :tool, :mode, :source, :status, :http, :keys, :error, NOW())"
            );
            $stmt->execute([
                ':rid'    => $ctx->requestId,
                ':uid'    => $ctx->userId,
                ':tool'   => mb_substr($tool, 0, 60),
                ':mode'   => mb_substr($mode, 0, 20),
                ':source' => mb_substr($source, 0, 20),
                ':status' => mb_substr($status, 0, 20),
                ':http'   => $httpStatus,
                ':keys'   => json_encode(array_values($argKeys), JSON_UNESCAPED_UNICODE) ?: '[]',
                ':error'  => $error ? mb_substr($error, 0, 250) : null,
            ]);
        } catch (\Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warning('agent.action audit skipped', ['error' => $e->getMessage()]);
            }
        }
    }
}
