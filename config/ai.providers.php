<?php
/**
 * ai.providers.php — WorkToGo Agent Layer :: AI provider registry + per-task routing
 * ----------------------------------------------------------------------------
 * The ONLY place you change to add an AI provider or re-route a task's
 * primary/secondary — no code. The exact analogue of config/payment.gateways.php.
 *
 * DB-free and side-effect-free: this file is included in many contexts and must
 * never fatal. These are the safe DEFAULTS; an admin may override 'routing' at
 * runtime via app_settings.agent_ai_routing (JSON), which AiProviderManager
 * merges on top defensively.
 *
 * ── Providers ────────────────────────────────────────────────────────────────
 *   mock      — built-in, key-less. The safe fallback + the test/verification
 *               provider. Always enabled; never spends; never leaves the box.
 *   anthropic — the REAL reference adapter (Claude, Messages API). Enabled here,
 *               but only USABLE when ANTHROPIC_API_KEY is set (isConfigured()).
 *   openai    — STUB slot (disabled). Enable only after the adapter is
 *               implemented + a key is set + validated on the provider's test env.
 *   gemini    — STUB slot (disabled). Same discipline.
 *
 *   'model' per provider is the model that provider should use — CONFIG-DRIVEN,
 *   never hardcoded in logic. Point 'anthropic'.model at a cheaper/faster model
 *   for the high-volume "understand" task whenever you like; the code doesn't
 *   change. Leaving it unset lets the adapter's own env default apply.
 *
 * ── Routing (per TASK, not per provider) ─────────────────────────────────────
 *   Each language task has its own primary/secondary. secondary = the failover
 *   used only when the primary is DOWN/erroring (retryable), never on a terminal
 *   decline. Default secondary is 'mock' so the agent always answers — the
 *   "if the AI is removed, the system still works" guarantee, in config.
 */

return [
    'providers' => [
        'mock' => [
            'adapter' => 'MockAiAdapter',
            'enabled' => true,     // always on: fallback + verification
        ],
        'anthropic' => [
            'adapter' => 'AnthropicAdapter',
            'enabled' => true,     // usable only when ANTHROPIC_API_KEY is present
            'model'   => 'claude-opus-5',  // config-driven; swap for a cheaper model anytime
        ],
        'openai' => [
            'adapter' => 'OpenAiAdapter',
            'enabled' => false,    // STUB — enable only after implement + creds + validate
        ],
        'gemini' => [
            'adapter' => 'GeminiAdapter',
            'enabled' => false,    // STUB — enable only after implement + creds + validate
        ],
    ],

    // secondary = null ⇒ no failover for that task. 'mock' ⇒ always-answer degrade.
    'routing' => [
        'default'    => ['primary' => 'anthropic', 'secondary' => 'mock'],
        'understand' => ['primary' => 'anthropic', 'secondary' => 'mock'],
        'generate'   => ['primary' => 'anthropic', 'secondary' => 'mock'],
    ],
];
