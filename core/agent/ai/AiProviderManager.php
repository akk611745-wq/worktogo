<?php
/**
 * AiProviderManager.php — WorkToGo Agent Layer :: provider factory + router + failover
 * ----------------------------------------------------------------------------
 * The AI-layer analogue of PaymentGatewayManager. It builds provider adapters
 * from config, resolves the primary/secondary provider PER TASK ('understand' |
 * 'generate' | 'default'), fails over primary → secondary on a RETRYABLE failure
 * only, and is circuit-broken so a flapping provider is skipped for a cool-down
 * window (the same protection heart/pipeline/BrainConnector uses).
 *
 * ══ THE PRIVACY INVARIANT ════════════════════════════════════════════════════
 * complete() accepts a SanitizedPrompt and nothing else. This is the single point
 * that invokes an adapter, and it cannot be handed raw text — so every provider
 * call is guaranteed to have passed the privacy wall.
 *
 * ── Independence / degrade-don't-die ─────────────────────────────────────────
 *   • Providers are chosen per task; one task's misconfig can't affect another.
 *   • Config is DB-free and never die()s at include; a config/DB problem surfaces
 *     as a clean failed AiResponse, never a fatal.
 *   • The default routing carries the built-in `mock` as secondary, so even with
 *     no external provider configured the agent still answers (deterministically)
 *     — "if the AI is removed, the system keeps working".
 *
 * Model selection is config-driven: providers.<name>.model (or the adapter's env
 * default) is passed through as a per-call option — never hardcoded in logic.
 */

declare(strict_types=1);

require_once __DIR__ . '/AiProvider.php';
require_once __DIR__ . '/AiResponse.php';
require_once __DIR__ . '/MockAiAdapter.php';
require_once __DIR__ . '/AnthropicAdapter.php';
require_once __DIR__ . '/OpenAiAdapter.php';
require_once __DIR__ . '/GeminiAdapter.php';

final class AiProviderManager
{
    private const CB_THRESHOLD = 5;    // consecutive failures before a provider's circuit opens
    private const CB_WINDOW_S  = 60;   // seconds a provider is skipped once open

    private array $config;
    private ?PDO  $db;
    /** @var array<string,AiProvider> lazily built, cached */
    private array $adapters = [];
    private ?array $routingCache = null;

    public function __construct(?PDO $db = null, ?array $config = null)
    {
        $this->db = $db;
        if ($config !== null) {
            $this->config = $config;                       // inject for tests
        } else {
            $loaded = @include __DIR__ . '/../../../config/ai.providers.php';
            $this->config = is_array($loaded) ? $loaded : [];
        }
    }

    // ── Adapter factory ──────────────────────────────────────────────────────────

    public function knownProviders(): array
    {
        return array_keys($this->config['providers'] ?? []);
    }

    public function adapter(string $name): ?AiProvider
    {
        $name = strtolower(trim($name));
        if (isset($this->adapters[$name])) {
            return $this->adapters[$name];
        }
        $registry = $this->config['providers'][$name] ?? null;
        if (!$registry) {
            return null;
        }
        $class = (string) ($registry['adapter'] ?? '');
        if ($class === '' || !class_exists($class)) {
            error_log("[AiProviderManager] adapter class not found for '$name': $class");
            return null;
        }
        $instance = new $class();
        if (!($instance instanceof AiProvider)) {
            error_log("[AiProviderManager] '$class' does not implement AiProvider.");
            return null;
        }
        return $this->adapters[$name] = $instance;
    }

    /** Enabled in config AND holding real credentials. */
    public function isUsable(string $name): bool
    {
        $name = strtolower(trim($name));
        if (empty($this->config['providers'][$name]['enabled'])) {
            return false;
        }
        $a = $this->adapter($name);
        return $a !== null && $a->isConfigured();
    }

    // ── Routing (per task, admin-override aware) ──────────────────────────────────

    /** Resolve ['primary'=>?string,'secondary'=>?string] for a task. */
    public function routeFor(string $task): array
    {
        $task    = strtolower(trim($task)) ?: 'default';
        $routing = $this->routing();
        $entry   = $routing[$task] ?? $routing['default'] ?? ['primary' => 'mock', 'secondary' => null];
        return [
            'primary'   => isset($entry['primary'])    ? (string) $entry['primary']   : 'mock',
            'secondary' => !empty($entry['secondary']) ? (string) $entry['secondary'] : null,
        ];
    }

    /** File defaults, with an optional admin JSON override (app_settings.agent_ai_routing). */
    private function routing(): array
    {
        if ($this->routingCache !== null) {
            return $this->routingCache;
        }
        $routing = (array) ($this->config['routing'] ?? []);
        if ($this->db instanceof PDO) {
            try {
                $stmt = $this->db->prepare(
                    "SELECT setting_value FROM app_settings WHERE setting_key = 'agent_ai_routing' LIMIT 1"
                );
                $stmt->execute();
                $raw = $stmt->fetchColumn();
                if (is_string($raw) && $raw !== '') {
                    $override = json_decode($raw, true);
                    if (is_array($override)) {
                        foreach ($override as $task => $entry) {
                            if (is_array($entry)) {
                                $routing[strtolower((string) $task)] = $entry;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                error_log('[AiProviderManager] routing override unavailable: ' . $e->getMessage());
            }
        }
        return $this->routingCache = $routing;
    }

    // ── Complete with failover ────────────────────────────────────────────────────

    /**
     * Run one completion for a SANITIZED prompt, failing over primary → secondary
     * on a retryable failure only. THE ONLY METHOD THAT INVOKES A PROVIDER.
     *
     * @param SanitizedPrompt $prompt wall-sealed input (the type makes bypass impossible)
     * @param string|null     $task   defaults to the prompt's task
     */
    public function complete(SanitizedPrompt $prompt, ?string $task = null): AiResponse
    {
        $prompt->assertSealed();                       // defence in depth
        $task     = $task ?: $prompt->task;
        $route    = $this->routeFor($task);
        $attempts = [];

        // ── Primary ──────────────────────────────────────────────────────────────
        $primaryName = $route['primary'];
        $res = $this->tryProvider($primaryName, $prompt, $attempts);
        if ($res !== null) {
            if ($res->success) {
                return $res->withFailover(false, $attempts);
            }
            if (!$res->retryable) {
                return $res->withFailover(false, $attempts);   // terminal — do not fail over
            }
            $this->recordFailure($primaryName);
            error_log("[AiProviderManager] primary '$primaryName' retryable failure for task '$task'; trying secondary.");
        }

        // ── Secondary (failover) ─────────────────────────────────────────────────
        $secondaryName = $route['secondary'];
        if ($secondaryName !== null) {
            $res = $this->tryProvider($secondaryName, $prompt, $attempts);
            if ($res !== null) {
                if ($res->success) {
                    error_log("[AiProviderManager] task '$task' FAILED OVER to secondary '$secondaryName'.");
                    return $res->withFailover(true, $attempts);
                }
                if ($res->retryable) {
                    $this->recordFailure($secondaryName);
                }
                return $res->withFailover(true, $attempts);
            }
        }

        // No usable provider — clean, non-fatal failure (orchestrator degrades).
        return AiResponse::fail($primaryName, $task, 'no usable AI provider', false)
            ->withFailover(false, $attempts);
    }

    /**
     * Attempt one provider. Returns null when the provider is unusable or its
     * circuit is open (so the caller moves on); otherwise its AiResponse.
     */
    private function tryProvider(string $name, SanitizedPrompt $prompt, array &$attempts): ?AiResponse
    {
        if (!$this->isUsable($name)) {
            $attempts[] = ['provider' => $name, 'skipped' => 'unusable'];
            return null;
        }
        if ($this->isCircuitOpen($name)) {
            $attempts[] = ['provider' => $name, 'skipped' => 'circuit_open'];
            return null;
        }

        $options = ['model' => $this->config['providers'][$name]['model'] ?? null];
        $options = array_filter($options, static fn ($v) => $v !== null);

        $res = $this->adapter($name)->complete($prompt, $options);
        if ($res->success) {
            $this->recordSuccess($name);
        }
        $attempts[] = [
            'provider'  => $res->provider,
            'success'   => $res->success,
            'retryable' => $res->retryable,
            'error'     => $res->error,
            'ms'        => $res->latencyMs,
        ];
        return $res;
    }

    // ── Circuit breaker (APCu; no-op when APCu is absent) ─────────────────────────

    private function isCircuitOpen(string $name): bool
    {
        return function_exists('apcu_fetch')
            && (bool) (apcu_fetch('agent_ai_cb_open_' . $name) ?: false);
    }

    private function recordFailure(string $name): void
    {
        if (!function_exists('apcu_add')) {
            return;
        }
        $key = 'agent_ai_cb_fails_' . $name;
        apcu_add($key, 0, self::CB_WINDOW_S);
        if ((int) apcu_inc($key) >= self::CB_THRESHOLD && function_exists('apcu_store')) {
            apcu_store('agent_ai_cb_open_' . $name, true, self::CB_WINDOW_S);
            error_log("[AiProviderManager] circuit opened for '$name' ({$name} failing).");
        }
    }

    private function recordSuccess(string $name): void
    {
        if (function_exists('apcu_delete')) {
            apcu_delete('agent_ai_cb_fails_' . $name);
            apcu_delete('agent_ai_cb_open_' . $name);
        }
    }
}
