<?php
/**
 * SanitizedPrompt.php — WorkToGo Agent Layer (foundation)
 * ----------------------------------------------------------------------------
 * The ONLY object an AI provider adapter will accept. It represents text that
 * has already passed the privacy wall: scrubbed of PII, reduced to a coarse,
 * non-identifying envelope, and cleared by the wall's egress tripwire.
 *
 * ══ THE STRUCTURAL GUARANTEE ═════════════════════════════════════════════════
 * A SanitizedPrompt can ONLY be created by PiiWall. Every provider adapter's
 * complete() signature accepts SanitizedPrompt and nothing else, and the manager
 * that dispatches to adapters accepts SanitizedPrompt and nothing else. Therefore
 * there is no code path to a provider that does not first pass through the wall.
 *
 * This is enforced three ways, deepest first:
 *   1. The constructor is PRIVATE — no `new SanitizedPrompt(...)` anywhere.
 *   2. The only factory, seal(), verifies at runtime that its immediate caller is
 *      PiiWall (debug_backtrace caller check). Any other caller throws
 *      AgentSecurityException. This is the "friend class" guarantee PHP allows.
 *   3. The type system: adapters and the manager cannot be handed a raw string —
 *      they require this type, which only the wall can produce.
 *
 * What it carries OUTBOUND (everything here is safe to send):
 *   • system   — our static developer-authored instruction (no user data)
 *   • userText — the user's utterance, PII scrubbed to placeholders (<PHONE_1>…)
 *   • task     — which language job this is ('understand' | 'generate')
 *   • meta     — coarse, allow-listed, non-identifying (role/lang/period/caps)
 *
 * What it does NOT carry (never travels toward a provider):
 *   • the real user_id / city_id / session
 *   • the placeholder → original map (that stays INSIDE the wall, keyed by
 *     requestId, and is used only on the RETURN path to re-hydrate for the user)
 */

declare(strict_types=1);

final class SanitizedPrompt
{
    private bool $sealed;

    private function __construct(
        public readonly string $system,
        public readonly string $userText,
        public readonly string $task,
        public readonly array  $meta,
        public readonly string $requestId,
    ) {
        $this->sealed = true;
    }

    /**
     * Mint a sealed prompt. CALLABLE ONLY BY PiiWall.
     *
     * The caller check is the teeth of the structural guarantee: seal() inspects
     * the one frame above it and refuses unless that frame is a PiiWall method.
     * Combined with the private constructor, nothing outside the wall can create
     * a SanitizedPrompt — so nothing can reach a provider without being scrubbed.
     */
    public static function seal(
        string $system,
        string $userText,
        string $task,
        array  $meta,
        string $requestId
    ): self {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $callerClass = $frames[1]['class'] ?? '';
        if ($callerClass !== 'PiiWall') {
            throw new AgentSecurityException(
                'SanitizedPrompt may only be created by the privacy wall (PiiWall).'
            );
        }
        return new self($system, $userText, $task, $meta, $requestId);
    }

    /** Defensive self-attestation used by the manager/adapters. Always true once constructed. */
    public function assertSealed(): void
    {
        if ($this->sealed !== true) {
            throw new AgentSecurityException('Unsealed prompt reached a provider path.');
        }
    }

    /**
     * The provider-neutral message list. Adapters translate this into their own
     * wire shape. Only sanitized, safe-to-send content is present.
     *
     * @return array<int,array{role:string,content:string}>
     */
    public function messages(): array
    {
        return [
            ['role' => 'user', 'content' => $this->userText],
        ];
    }
}
