<?php
/**
 * GeminiAdapter.php — WorkToGo Agent Layer :: Google Gemini provider (STUB / slot)
 * ----------------------------------------------------------------------------
 * A clean, drop-in slot — full AiProvider contract, guarded OFF until real
 * credentials are set AND the transport is implemented and validated. It never
 * routes or spends until deliberately enabled in config/ai.providers.php.
 *
 * To turn it on (a later, deliberate task):
 *   1. .env: GEMINI_API_KEY (+ optional GEMINI_MODEL, default e.g. a Flash model).
 *   2. Implement post()/parse against the Generative Language API
 *        POST https://generativelanguage.googleapis.com/v1beta/models/<model>:generateContent?key=<key>
 *        body:  { systemInstruction:{parts:[{text}]}, contents:[{role:'user',parts:[{text}]}] }
 *        reply: candidates[0].content.parts[*].text ; usageMetadata.promptTokenCount/…
 *   3. Flip isConfigured() to check the key, then enable 'gemini' in config and
 *      point a task's primary/secondary at it. No orchestrator/wall change needed.
 *
 * Until then complete() returns a retryable failure so the manager fails over to
 * the configured secondary — never a hard error.
 */

declare(strict_types=1);

require_once __DIR__ . '/AiProvider.php';

final class GeminiAdapter implements AiProvider
{
    public function name(): string
    {
        return 'gemini';
    }

    /** STUB: intentionally always false until the adapter is implemented + validated. */
    public function isConfigured(): bool
    {
        return false;
    }

    public function complete(SanitizedPrompt $prompt, array $options = []): AiResponse
    {
        $prompt->assertSealed();
        return AiResponse::fail(
            $this->name(),
            $prompt->task,
            'gemini adapter is a stub — not yet implemented/enabled',
            true // retryable ⇒ manager fails over to the secondary
        );
    }
}
