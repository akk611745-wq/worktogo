<?php
/**
 * OpenAiAdapter.php — WorkToGo Agent Layer :: OpenAI provider (STUB / slot)
 * ----------------------------------------------------------------------------
 * A clean, drop-in slot — the full AiProvider contract is implemented, but every
 * call path is guarded OFF (isConfigured() returns false) until real credentials
 * are set AND the transport is filled in and validated. It never routes or spends
 * until deliberately enabled in config/ai.providers.php. Same discipline as the
 * payment layer's RazorpayAdapter.
 *
 * To turn it on (a later, deliberate task):
 *   1. .env: OPENAI_API_KEY (+ optional OPENAI_MODEL, OPENAI_ORG).
 *   2. Implement post()/parse against the Chat Completions API
 *        POST https://api.openai.com/v1/chat/completions
 *        headers: Authorization: Bearer <key>
 *        body:    { model, max_tokens, messages:[{role:'system'|'user', content}] }
 *        reply:   choices[0].message.content ; usage.prompt_tokens/completion_tokens
 *   3. Flip isConfigured() to check the key, then enable 'openai' in config and
 *      point a task's primary/secondary at it. No orchestrator/wall change needed.
 *
 * Until then complete() returns a retryable failure so the manager simply fails
 * over to the configured secondary — never a hard error.
 */

declare(strict_types=1);

require_once __DIR__ . '/AiProvider.php';

final class OpenAiAdapter implements AiProvider
{
    public function name(): string
    {
        return 'openai';
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
            'openai adapter is a stub — not yet implemented/enabled',
            true // retryable ⇒ manager fails over to the secondary
        );
    }
}
