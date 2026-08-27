<?php
/**
 * AiProvider.php — WorkToGo Agent Layer :: the AI provider contract (foundation)
 * ----------------------------------------------------------------------------
 * The single interface every AI provider (Anthropic/Claude, OpenAI, Gemini, a
 * future OWN model, or the built-in mock) implements. The whole agent talks to
 * THIS interface, never to a concrete provider — adding or swapping a provider is
 * one adapter + one config line, exactly like the payment abstraction.
 *
 * ══ THE PRIVACY INVARIANT, ENFORCED BY THE TYPE SYSTEM ═══════════════════════
 * complete() accepts a SanitizedPrompt and NOTHING ELSE. A SanitizedPrompt can
 * only be produced by PiiWall. Therefore an adapter physically cannot be handed
 * raw user text — every provider call has already passed the privacy wall.
 *
 * Adapters MUST:
 *   • read their own credentials from env/config (self-contained, like the
 *     payment CashfreeClient) and expose isConfigured();
 *   • NOT throw for ordinary provider/network failures — return AiResponse::fail
 *     with the right `retryable` flag so the manager can fail over;
 *   • send ONLY what the SanitizedPrompt carries (system + sanitized messages +
 *     coarse meta) — never enrich the request with anything else.
 */

declare(strict_types=1);

require_once __DIR__ . '/AiResponse.php';
require_once __DIR__ . '/../SanitizedPrompt.php';

interface AiProvider
{
    /** Canonical lowercase provider name, e.g. 'anthropic'. Stored on the audit row. */
    public function name(): string;

    /**
     * Are real, non-placeholder credentials present? Used both as a call guard
     * and as failover eligibility — the manager never routes to an unconfigured
     * provider.
     */
    public function isConfigured(): bool;

    /**
     * Run one language completion for an ALREADY-SANITIZED prompt.
     * MUST NOT throw for ordinary failures — return AiResponse::fail(...).
     *
     * @param SanitizedPrompt $prompt  wall-sealed, PII-free (the only accepted input)
     * @param array           $options optional per-call overrides (model, max_tokens, …)
     */
    public function complete(SanitizedPrompt $prompt, array $options = []): AiResponse;
}
