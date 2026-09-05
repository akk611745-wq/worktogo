<?php
/**
 * AgentPolicy.php — WorkToGo Agent Layer :: the governance authority (Phase 7)
 * ----------------------------------------------------------------------------
 * The agent is powerful (service + commerce + vendor). It must NEVER be
 * uncontrolled. This class is the single, data-driven source of truth for what
 * the agent may do RIGHT NOW — loaded fresh each request from app_settings, so
 * the admin control-room changes behaviour with zero code and it takes effect on
 * the next turn.
 *
 * It answers, and the entry / orchestrator / planner ENFORCE:
 *   • agentEnabled()          — the global kill-switch (agent_enabled).
 *   • capabilityEnabled(grp)  — per-capability toggle (service/commerce/vendor).
 *   • writesEnabled()         — pause ALL writes with one flag (reads still work).
 *   • limits()                — per-user msg/min + max actions per session.
 *   • guardrails()            — safety knobs (max message length, …).
 *   • aiAllowed() / budget()  — monthly AI-cost cap + auto-pause. When the cap is
 *                               hit, the agent stops CALLING the external AI (it
 *                               degrades to deterministic understanding) — it does
 *                               not break. Protects against runaway API bills.
 *
 * Fully defensive: a missing settings row / table degrades to safe defaults —
 * never a broken turn. No PII is involved anywhere here.
 */

declare(strict_types=1);

final class AgentPolicy
{
    // 'commerce' defaults OFF: at a service-only launch the agent must not offer
    // customers commerce tools (browse/checkout/order_for) that the rest of the app
    // hides (role-04 must-fix). Turn it on in the control-room (agent_capabilities
    // JSON) when commerce launches. service/vendor/writes stay on by default.
    private const CAP_DEFAULTS    = ['service' => true, 'commerce' => false, 'vendor' => true, 'writes' => true];
    private const LIMIT_DEFAULTS  = ['msg_per_min' => 30, 'actions_per_session' => 20];
    // A SAFE non-zero default cap (audit M1): a real AI key can never run a runaway
    // bill by accident — spend past this auto-pauses the external AI (degrades to
    // deterministic, never breaks). The control-room can raise it; an admin may set
    // 0 to opt into unlimited deliberately.
    private const BUDGET_DEFAULTS = ['monthly_cap_usd' => 20.0, 'auto_pause' => true];
    private const GUARD_DEFAULTS  = ['max_message_chars' => 2000];

    /** Per-1M-token [input, output] USD prices, for live spend from agent_ai_log. */
    private const PRICES = [
        'claude-opus-5'    => [5.0, 25.0], 'claude-opus-4-8' => [5.0, 25.0], 'claude-opus-4-7' => [5.0, 25.0],
        'claude-sonnet-5'  => [2.0, 10.0], 'claude-sonnet-4-6' => [3.0, 15.0], 'claude-haiku-4-5' => [1.0, 5.0],
        'mock-echo-1'      => [0.0, 0.0],  'default' => [3.0, 15.0],
    ];

    /** Which capability group each tool belongs to (governance mapping). */
    private const TOOL_GROUP = [
        // service
        'find_vendors' => 'service', 'get_vendor_profile' => 'service', 'get_packages' => 'service',
        'get_expert_visit_price' => 'service', 'create_booking' => 'service', 'request_expert_visit' => 'service',
        'list_my_jobs' => 'service', 'get_my_profile' => 'service',
        // commerce
        'browse_products' => 'commerce', 'get_product' => 'commerce', 'view_cart' => 'commerce',
        'add_to_cart' => 'commerce', 'list_orders' => 'commerce', 'track_order' => 'commerce', 'checkout' => 'commerce',
        // vendor
        'start_listing' => 'vendor', 'save_listing' => 'vendor', 'my_status' => 'vendor',
        'set_reminder' => 'vendor', 'upload_work_photo' => 'vendor',
        // proactive: listing one's own reminders is a private, ungated read (Phase 9)
        'list_reminders' => 'core',
        // connections — gifting is commerce; managing your own people is core
        'order_for' => 'commerce',
        'list_people' => 'core', 'get_person' => 'core', 'add_person' => 'core', 'save_person' => 'core',
        // core (never gated by a capability toggle)
        'verify_phone' => 'core',
    ];

    private bool  $enabled;
    private array $capabilities;
    private array $limits;
    private array $budget;
    private array $guardrails;
    private ?PDO  $db;
    private ?float $spendCache = null;

    /**
     * @param PDO|null   $db       reads governance from app_settings
     * @param array|null $override inject settings directly (tests/config); when
     *                             given, the DB is not read for settings.
     */
    public function __construct(?PDO $db = null, ?array $override = null)
    {
        $this->db = $db;
        $s = $override !== null ? $override : $this->loadSettings();
        $this->enabled      = $this->truthy($s['agent_enabled'] ?? '0');
        $this->capabilities = array_merge(self::CAP_DEFAULTS,   $this->json($s['agent_capabilities'] ?? null));
        $this->limits       = array_merge(self::LIMIT_DEFAULTS, $this->json($s['agent_limits'] ?? null));
        $this->budget       = array_merge(self::BUDGET_DEFAULTS,$this->json($s['agent_budget'] ?? null));
        $this->guardrails   = array_merge(self::GUARD_DEFAULTS, $this->json($s['agent_guardrails'] ?? null));
    }

    public static function load(?PDO $db = null): self
    {
        return new self($db);
    }

    // ── Reads the whole agent-settings set once ──────────────────────────────────
    private function loadSettings(): array
    {
        if (!$this->db instanceof PDO) {
            return [];
        }
        try {
            $stmt = $this->db->query(
                "SELECT setting_key, setting_value FROM app_settings
                 WHERE setting_key IN ('agent_enabled','agent_capabilities','agent_limits','agent_budget','agent_guardrails')"
            );
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[$r['setting_key']] = $r['setting_value'];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ── Governance answers ───────────────────────────────────────────────────────

    public function agentEnabled(): bool { return $this->enabled; }

    /** 'core' is never gated; service/commerce/vendor read their toggle. */
    public function capabilityEnabled(string $group): bool
    {
        if ($group === 'core' || $group === '') {
            return true;
        }
        return (bool) ($this->capabilities[$group] ?? true);
    }

    public function writesEnabled(): bool { return (bool) ($this->capabilities['writes'] ?? true); }

    public function limits(): array
    {
        return [
            'msg_per_min'         => max(1, (int) ($this->limits['msg_per_min'] ?? 30)),
            'actions_per_session' => max(1, (int) ($this->limits['actions_per_session'] ?? 20)),
        ];
    }

    public function guardrails(): array
    {
        return ['max_message_chars' => max(1, (int) ($this->guardrails['max_message_chars'] ?? 2000))];
    }

    public function groupForTool(string $name): string
    {
        return self::TOOL_GROUP[$name] ?? 'core';
    }

    /** Is the external AI allowed right now (budget not exceeded)? */
    public function aiAllowed(): bool
    {
        $b = $this->budget();
        return !$b['paused'];
    }

    /** Budget snapshot for the control-room + enforcement. */
    public function budget(): array
    {
        $cap   = (float) ($this->budget['monthly_cap_usd'] ?? 0);
        $auto  = (bool) ($this->budget['auto_pause'] ?? true);
        $spent = $this->spendThisMonthUsd();
        return [
            'monthly_cap_usd' => $cap,
            'auto_pause'      => $auto,
            'spent_usd'       => round($spent, 4),
            'remaining_usd'   => $cap > 0 ? round(max(0, $cap - $spent), 4) : null,
            'paused'          => ($cap > 0 && $auto && $spent >= $cap),
        ];
    }

    /** Live this-month AI spend (USD) derived from agent_ai_log token counts. */
    public function spendThisMonthUsd(): float
    {
        if ($this->spendCache !== null) {
            return $this->spendCache;
        }
        $spend = 0.0;
        if ($this->db instanceof PDO) {
            try {
                $stmt = $this->db->query(
                    "SELECT model, SUM(prompt_tokens) pt, SUM(completion_tokens) ct
                       FROM agent_ai_log
                      WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
                      GROUP BY model"
                );
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $price = self::PRICES[(string) ($r['model'] ?? '')] ?? self::PRICES['default'];
                    $spend += ((float) $r['pt'] / 1_000_000) * $price[0]
                            + ((float) $r['ct'] / 1_000_000) * $price[1];
                }
            } catch (\Throwable $e) {
                $spend = 0.0;
            }
        }
        return $this->spendCache = $spend;
    }

    /** Everything the control-room shows (no PII). */
    public function snapshot(): array
    {
        return [
            'enabled'      => $this->enabled,
            'capabilities' => [
                'service'  => (bool) ($this->capabilities['service']  ?? true),
                'commerce' => (bool) ($this->capabilities['commerce'] ?? true),
                'vendor'   => (bool) ($this->capabilities['vendor']   ?? true),
                'writes'   => (bool) ($this->capabilities['writes']   ?? true),
            ],
            'limits'     => $this->limits(),
            'budget'     => $this->budget(),
            'guardrails' => $this->guardrails(),
        ];
    }

    // ── helpers ──────────────────────────────────────────────────────────────────
    private function json($raw): array
    {
        if (is_array($raw)) { return $raw; }
        $d = json_decode((string) ($raw ?? ''), true);
        return is_array($d) ? $d : [];
    }
    private function truthy($v): bool
    {
        return in_array(strtolower((string) $v), ['1', 'true', 'on', 'yes'], true);
    }
}
