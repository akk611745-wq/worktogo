<?php
/**
 * PaymentGatewayManager.php  —  WorkToGo Payment Abstraction Layer
 * ----------------------------------------------------------------------------
 * The factory + router + failover engine for payment gateways.
 *
 * Responsibilities:
 *   • Build adapters from config (config/payment.gateways.php), lazily & cached.
 *   • Resolve the primary/secondary gateway for a given ENGINE
 *     ('commerce' | 'service' | 'video'), with an optional admin override read
 *     from app_settings.payment_gateway_routing (JSON).
 *   • createOrderWithFailover(): try primary; on a RETRYABLE (infra/gateway-down)
 *     failure only, try the secondary — returning which gateway handled it.
 *   • adapter(): fetch a specific adapter by name (used by the webhook to verify
 *     with the gateway the request declares).
 *
 * ── Money-safety / independence guarantees ───────────────────────────────────
 *   • No shared mutable state between engines — each resolve()/createOrder call
 *     stands alone, so one engine's gateway outage or misconfig cannot affect
 *     another engine's payment path.
 *   • Failover only on primary.retryable === true (never on a legitimate
 *     decline/validation error), and only to an enabled + configured secondary.
 *   • The customer is handed exactly ONE payable session (the successful
 *     gateway's), so failover cannot double-charge.
 *   • Degrade-don't-die: config/DB problems are caught and surfaced as a clean
 *     failure, never a fatal that could cascade across engines.
 */

require_once __DIR__ . '/PaymentGateway.php';
require_once __DIR__ . '/CashfreeAdapter.php';
require_once __DIR__ . '/RazorpayAdapter.php';

class PaymentGatewayManager
{
    private array $config;
    private ?PDO  $db;
    /** @var array<string,PaymentGateway> */
    private array $adapters = [];
    /** @var array<string,array>|null cached, merged routing (file defaults + DB override) */
    private ?array $routingCache = null;

    /**
     * @param PDO|null   $db     Optional — enables the admin routing override lookup.
     * @param array|null $config Optional — inject config (tests); defaults to the config file.
     */
    public function __construct(?PDO $db = null, ?array $config = null)
    {
        $this->db = $db;
        if ($config !== null) {
            $this->config = $config;
        } else {
            $loaded = @include __DIR__ . '/../../config/payment.gateways.php';
            $this->config = is_array($loaded) ? $loaded : [];
        }
    }

    // ── Adapter factory ─────────────────────────────────────────────────────────

    /** Names of gateways declared in the registry (regardless of enabled state). */
    public function knownGateways(): array
    {
        return array_keys($this->config['gateways'] ?? []);
    }

    /**
     * Fetch an adapter by name. Returns null for an unknown gateway.
     * Does NOT check 'enabled' — the webhook needs to build an adapter to verify
     * even if the gateway was later disabled for new orders.
     */
    public function adapter(string $name): ?PaymentGateway
    {
        $name = strtolower(trim($name));
        if (isset($this->adapters[$name])) {
            return $this->adapters[$name];
        }
        $registry = $this->config['gateways'][$name] ?? null;
        if (!$registry) {
            return null;
        }
        $class = (string)($registry['adapter'] ?? '');
        if ($class === '' || !class_exists($class)) {
            error_log("[PaymentGatewayManager] Adapter class not found for gateway '$name': $class");
            return null;
        }
        $instance = new $class();
        if (!($instance instanceof PaymentGateway)) {
            error_log("[PaymentGatewayManager] '$class' does not implement PaymentGateway.");
            return null;
        }
        return $this->adapters[$name] = $instance;
    }

    /** Is a gateway both enabled in config AND holding real credentials? */
    public function isUsable(string $name): bool
    {
        $name = strtolower(trim($name));
        if (empty($this->config['gateways'][$name]['enabled'])) {
            return false;
        }
        $adapter = $this->adapter($name);
        return $adapter !== null && $adapter->isConfigured();
    }

    // ── Routing resolution (per engine, override-aware) ─────────────────────────

    /**
     * Resolve ['primary'=>?string, 'secondary'=>?string] for an engine.
     * Order of precedence: admin DB override → config file → 'default' → cashfree.
     */
    public function routeFor(string $engine): array
    {
        $engine  = strtolower(trim($engine)) ?: 'default';
        $routing = $this->routing();

        $entry = $routing[$engine]
            ?? $routing['default']
            ?? ['primary' => 'cashfree', 'secondary' => null];

        return [
            'primary'   => isset($entry['primary'])   ? (string)$entry['primary']   : 'cashfree',
            'secondary' => !empty($entry['secondary']) ? (string)$entry['secondary'] : null,
        ];
    }

    /** Merged routing table (file defaults with an optional admin JSON override on top). */
    private function routing(): array
    {
        if ($this->routingCache !== null) {
            return $this->routingCache;
        }
        $routing = (array)($this->config['routing'] ?? []);

        // Optional admin override from app_settings.payment_gateway_routing (JSON).
        // Fully defensive: any problem falls back to the file defaults.
        if ($this->db instanceof PDO) {
            try {
                $stmt = $this->db->prepare(
                    "SELECT setting_value FROM app_settings WHERE setting_key = 'payment_gateway_routing' LIMIT 1"
                );
                $stmt->execute();
                $raw = $stmt->fetchColumn();
                if (is_string($raw) && $raw !== '') {
                    $override = json_decode($raw, true);
                    if (is_array($override)) {
                        foreach ($override as $engine => $entry) {
                            if (is_array($entry)) {
                                $routing[strtolower((string)$engine)] = $entry;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Table/row absent or unreadable → silently keep file defaults.
                error_log('[PaymentGatewayManager] routing override unavailable: ' . $e->getMessage());
            }
        }

        return $this->routingCache = $routing;
    }

    // ── Create order with failover ──────────────────────────────────────────────

    /**
     * Create a payment order for $engine, failing over primary → secondary on a
     * retryable (gateway-down) failure only.
     *
     * @param string $engine  'commerce' | 'service' | 'video' | …
     * @param array  $request Normalised create-order request (see PaymentGateway).
     * @return array          The chosen adapter's normalised result, PLUS:
     *                        'gateway'  => name that handled it,
     *                        'failover' => bool (true if secondary handled it),
     *                        'attempts' => per-gateway [name,http_code,retryable,error].
     */
    public function createOrderWithFailover(string $engine, array $request): array
    {
        $route    = $this->routeFor($engine);
        $attempts = [];

        // ── Primary ────────────────────────────────────────────────────────────
        $primaryName = $route['primary'];
        $primary     = $this->adapter($primaryName);

        if ($primary === null || !$this->isUsable($primaryName)) {
            $attempts[] = ['gateway' => $primaryName, 'http_code' => 0, 'retryable' => true,
                           'error' => 'primary not usable (disabled/unconfigured)'];
        } else {
            // Adapter appends its own ?gateway=NAME to the webhook URL.
            $primaryReq = $this->withGatewayNotify($request, $primaryName);
            $res = $primary->createOrder($primaryReq);
            $attempts[] = $this->attemptSummary($res);
            if (!empty($res['success'])) {
                $res['failover'] = false;
                $res['attempts'] = $attempts;
                return $res;
            }
            // Not retryable ⇒ a real decline/validation error: do NOT fail over
            // (the same request would fail identically elsewhere).
            if (empty($res['retryable'])) {
                $res['failover'] = false;
                $res['attempts'] = $attempts;
                return $res;
            }
            error_log("[PaymentGatewayManager] primary '$primaryName' retryable failure for engine '$engine' (http={$res['http_code']}); trying secondary.");
        }

        // ── Secondary (failover) ────────────────────────────────────────────────
        $secondaryName = $route['secondary'];
        if ($secondaryName !== null && $this->isUsable($secondaryName)) {
            $secondary    = $this->adapter($secondaryName);
            $secondaryReq = $this->withGatewayNotify($request, $secondaryName);
            $res = $secondary->createOrder($secondaryReq);
            $attempts[] = $this->attemptSummary($res);
            if (!empty($res['success'])) {
                error_log("[PaymentGatewayManager] engine '$engine' FAILED OVER to secondary '$secondaryName'.");
                $res['failover'] = true;
                $res['attempts'] = $attempts;
                return $res;
            }
            $res['failover'] = true;
            $res['attempts'] = $attempts;
            return $res;
        }

        // No usable secondary — surface a clean failure.
        return [
            'success'            => false,
            'gateway'            => $primaryName,
            'gateway_order_id'   => null,
            'payment_session_id' => null,
            'order_token'        => null,
            'mode'               => 'production',
            'http_code'          => 0,
            'retryable'          => false,
            'error'              => 'Payment gateway unavailable. Please try again shortly.',
            'raw'                => null,
            'failover'           => false,
            'attempts'           => $attempts,
        ];
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /** Append ?gateway=NAME to notify_url so the webhook can pick the right verifier. */
    private function withGatewayNotify(array $request, string $gateway): array
    {
        $notify = (string)($request['notify_url'] ?? '');
        if ($notify !== '') {
            $sep = (strpos($notify, '?') === false) ? '?' : '&';
            $request['notify_url'] = $notify . $sep . 'gateway=' . rawurlencode($gateway);
        }
        return $request;
    }

    private function attemptSummary(array $res): array
    {
        return [
            'gateway'   => (string)($res['gateway'] ?? 'unknown'),
            'http_code' => (int)($res['http_code'] ?? 0),
            'retryable' => (bool)($res['retryable'] ?? false),
            'error'     => $res['error'] ?? null,
            'success'   => (bool)($res['success'] ?? false),
        ];
    }
}
