<?php
/**
 * AnthropicAdapter.php — WorkToGo Agent Layer :: reference AI provider (Claude)
 * ----------------------------------------------------------------------------
 * The one REAL, working provider adapter, chosen as the reference because the
 * Anthropic Messages API is clean and well-specified. It talks to the API with
 * raw cURL — deliberately, to match this codebase's house style (Cashfree and the
 * Brain connector are hand-rolled cURL too; there is no Composer autoloader wired
 * for an official SDK, and the app hand-loads its own .env). Swapping in the
 * official `anthropic-ai/sdk` later is a drop-in change behind this same adapter.
 *
 * Credentials are read from the environment (self-contained, like CashfreeClient):
 *   • ANTHROPIC_API_KEY        — required; isConfigured() is false without it
 *   • AGENT_ANTHROPIC_MODEL    — optional; defaults to 'claude-opus-5'
 *   • AGENT_ANTHROPIC_MAXTOK   — optional; defaults to 512 (understanding is short)
 *
 * Model choice is CONFIG-DRIVEN, never hardcoded into logic — the founder/admin
 * can point this at a cheaper, faster model (e.g. a Haiku-class model) for the
 * high-volume "understand" task without touching code. The default is the most
 * capable Claude model per Anthropic guidance.
 *
 * It NEVER throws for ordinary failures — it returns AiResponse::fail() with the
 * correct `retryable` flag so the manager can fail over exactly like the payment
 * layer. It sends ONLY what the SanitizedPrompt carries — no enrichment.
 */

declare(strict_types=1);

require_once __DIR__ . '/AiProvider.php';

final class AnthropicAdapter implements AiProvider
{
    private const ENDPOINT       = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION    = '2023-06-01';
    private const DEFAULT_MODEL  = 'claude-opus-5';
    private const DEFAULT_MAXTOK = 512;
    private const CONNECT_TIMEOUT_S = 3;
    private const READ_TIMEOUT_S    = 20;

    public function name(): string
    {
        return 'anthropic';
    }

    public function isConfigured(): bool
    {
        $key = (string) (getenv('ANTHROPIC_API_KEY') ?: '');
        // Present, plausibly real (Anthropic keys start 'sk-ant-'), not a placeholder.
        return $key !== ''
            && stripos($key, 'placeholder') === false
            && stripos($key, 'your_') === false
            && strlen($key) >= 20;
    }

    public function complete(SanitizedPrompt $prompt, array $options = []): AiResponse
    {
        $prompt->assertSealed();

        if (!$this->isConfigured()) {
            // Treat "no creds" as retryable so the manager can fail over to a
            // configured secondary (or the mock) instead of hard-failing.
            return AiResponse::fail($this->name(), $prompt->task, 'anthropic not configured', true);
        }

        $model   = (string) ($options['model']      ?? (getenv('AGENT_ANTHROPIC_MODEL')  ?: self::DEFAULT_MODEL));
        $maxTok  = (int)    ($options['max_tokens'] ?? (getenv('AGENT_ANTHROPIC_MAXTOK') ?: self::DEFAULT_MAXTOK));

        // Build the request from ONLY the sealed prompt. No temperature/top_p —
        // sampling params are rejected by current Claude models.
        $body = [
            'model'      => $model,
            'max_tokens' => max(64, $maxTok),
            'system'     => $prompt->system,
            'messages'   => $prompt->messages(),
        ];

        $started = microtime(true);
        [$httpCode, $raw, $errno, $curlErr] = $this->post($body);
        $latencyMs = (int) round((microtime(true) - $started) * 1000);

        // Transport-level failures → retryable (gateway down / network).
        if ($errno !== 0) {
            return AiResponse::fail($this->name(), $prompt->task, "curl {$errno}: {$curlErr}", true, $latencyMs);
        }

        $decoded = json_decode((string) $raw, true);

        // Map HTTP status the same way the payment layer classifies failover:
        //   0/5xx/429/401/403 → retryable (down/erroring/creds) ; 400/404/422 → terminal.
        if ($httpCode < 200 || $httpCode >= 300) {
            $retryable = ($httpCode >= 500 || $httpCode === 429 || $httpCode === 401 || $httpCode === 403);
            $msg = is_array($decoded) ? ($decoded['error']['message'] ?? "HTTP {$httpCode}") : "HTTP {$httpCode}";
            return AiResponse::fail($this->name(), $prompt->task, $msg, $retryable, $latencyMs, $decoded);
        }

        if (!is_array($decoded)) {
            return AiResponse::fail($this->name(), $prompt->task, 'non-JSON provider response', true, $latencyMs);
        }

        // A safety refusal (HTTP 200, stop_reason=refusal) is a terminal decline.
        if (($decoded['stop_reason'] ?? '') === 'refusal') {
            return AiResponse::fail($this->name(), $prompt->task, 'provider_refusal', false, $latencyMs, $decoded);
        }

        $text = $this->extractText($decoded);
        $usage = $decoded['usage'] ?? [];

        return AiResponse::ok(
            provider: $this->name(),
            task: $prompt->task,
            text: $text,
            model: (string) ($decoded['model'] ?? $model),
            promptTokens: (int) ($usage['input_tokens'] ?? 0),
            completionTokens: (int) ($usage['output_tokens'] ?? 0),
            latencyMs: $latencyMs,
            raw: $decoded
        );
    }

    /** Concatenate the text blocks of a Messages API response. */
    private function extractText(array $decoded): string
    {
        $out = '';
        foreach (($decoded['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $out .= (string) ($block['text'] ?? '');
            }
        }
        return trim($out);
    }

    /**
     * POST to the Messages API. Returns [httpCode, rawBody, curlErrno, curlError].
     * Never throws.
     *
     * @return array{0:int,1:string,2:int,3:string}
     */
    private function post(array $body): array
    {
        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_S,
            CURLOPT_TIMEOUT        => self::READ_TIMEOUT_S,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . (string) getenv('ANTHROPIC_API_KEY'),
                'anthropic-version: ' . self::API_VERSION,
            ],
        ]);
        $raw     = curl_exec($ch);
        $code    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno   = curl_errno($ch);
        $err     = curl_error($ch);
        curl_close($ch);

        return [$code, is_string($raw) ? $raw : '', $errno, $err];
    }
}
