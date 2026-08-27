<?php
/**
 * MockAiAdapter.php — WorkToGo Agent Layer :: built-in key-less provider
 * ----------------------------------------------------------------------------
 * A real AiProvider that needs no credentials and makes no network call. It has
 * two jobs:
 *   1. VERIFICATION — it lets the whole pipeline (wall → manager → provider →
 *      re-hydrate) be proven end-to-end with no live API key. Because it only
 *      ever receives a SanitizedPrompt, it also proves the wall stripped PII:
 *      whatever it echoes back is exactly what a real provider would have seen.
 *   2. DEGRADE-DON'T-DIE — configured as the default `secondary`, it is the safe
 *      fallback when the real provider is down or unconfigured. The agent still
 *      responds (deterministically) instead of erroring — the founder's
 *      "if the AI is removed, the system still works" guarantee, made concrete.
 *
 * It deliberately does NOT understand language — it echoes. Real understanding is
 * a real provider's job. The mock exists to prove wiring and to fail safe.
 */

declare(strict_types=1);

require_once __DIR__ . '/AiProvider.php';

final class MockAiAdapter implements AiProvider
{
    /** Exposed for tests: the exact sanitized text this adapter was handed. */
    public ?string $lastSeenText = null;

    public function name(): string
    {
        return 'mock';
    }

    /** Always usable — no credentials required. */
    public function isConfigured(): bool
    {
        return true;
    }

    public function complete(SanitizedPrompt $prompt, array $options = []): AiResponse
    {
        $prompt->assertSealed();               // defence in depth
        $this->lastSeenText = $prompt->userText;

        // Echo the SANITIZED text. If a real PII value ever appeared here, the
        // wall would have failed closed before we were called.
        $reply = '[mock:' . $prompt->task . '] I understood: "' . $prompt->userText . '"';

        return AiResponse::ok(
            provider: $this->name(),
            task: $prompt->task,
            text: $reply,
            model: 'mock-echo-1',
            promptTokens: (int) ceil(mb_strlen($prompt->userText) / 4),
            completionTokens: (int) ceil(mb_strlen($reply) / 4),
            latencyMs: 0,
            raw: ['mock' => true, 'meta' => $prompt->meta]
        );
    }
}
