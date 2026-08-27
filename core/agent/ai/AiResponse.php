<?php
/**
 * AiResponse.php — WorkToGo Agent Layer :: AI provider result (foundation)
 * ----------------------------------------------------------------------------
 * One normalised, provider-agnostic result shape — the AI-layer analogue of the
 * payment layer's normalised createOrder() array. Every adapter returns THIS, so
 * the orchestrator never depends on a provider's native response shape.
 *
 * `retryable` mirrors the payment layer's failover signal: true ⇒ the provider
 * is down / erroring (network, 5xx, 401/403 creds, 429) and the manager may fail
 * over to a secondary. false ⇒ a legitimate/terminal rejection (400/404/422) that
 * would fail identically elsewhere — do NOT fail over.
 *
 * Adapters MUST NOT throw for ordinary provider/network failures — they return
 * ok=false with retryable set, so the manager decides on failover.
 */

declare(strict_types=1);

final class AiResponse
{
    private function __construct(
        public readonly bool    $success,
        public readonly string  $provider,
        public readonly string  $task,
        public readonly string  $text,
        public readonly ?string $model,
        public readonly int     $promptTokens,
        public readonly int     $completionTokens,
        public readonly int     $latencyMs,
        public readonly bool    $retryable,
        public readonly ?string $error,
        public readonly mixed   $raw,
        public readonly bool    $failover = false,
        public readonly array   $attempts = [],
    ) {}

    public static function ok(
        string $provider,
        string $task,
        string $text,
        ?string $model = null,
        int $promptTokens = 0,
        int $completionTokens = 0,
        int $latencyMs = 0,
        mixed $raw = null
    ): self {
        return new self(
            success: true, provider: $provider, task: $task, text: $text, model: $model,
            promptTokens: $promptTokens, completionTokens: $completionTokens,
            latencyMs: $latencyMs, retryable: false, error: null, raw: $raw
        );
    }

    public static function fail(
        string $provider,
        string $task,
        string $error,
        bool $retryable,
        int $latencyMs = 0,
        mixed $raw = null
    ): self {
        return new self(
            success: false, provider: $provider, task: $task, text: '', model: null,
            promptTokens: 0, completionTokens: 0, latencyMs: $latencyMs,
            retryable: $retryable, error: $error, raw: $raw
        );
    }

    /** Return a copy carrying failover bookkeeping (set by the manager). */
    public function withFailover(bool $failover, array $attempts): self
    {
        return new self(
            success: $this->success, provider: $this->provider, task: $this->task,
            text: $this->text, model: $this->model, promptTokens: $this->promptTokens,
            completionTokens: $this->completionTokens, latencyMs: $this->latencyMs,
            retryable: $this->retryable, error: $this->error, raw: $this->raw,
            failover: $failover, attempts: $attempts
        );
    }
}
