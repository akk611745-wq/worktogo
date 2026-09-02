<?php
/**
 * OtpChannelManager.php  —  WorkToGo Smart Auth Layer
 * ----------------------------------------------------------------------------
 * The factory + router + failover engine for OTP channels — the direct analogue
 * of PaymentGatewayManager.
 *
 * Responsibilities:
 *   • Build channel adapters from config/auth.channels.php, lazily & cached.
 *   • Resolve primary/secondary for a delivery KIND ('sms' | 'whatsapp'), with an
 *     optional admin override from app_settings.auth_channel_routing (JSON).
 *   • sendWithFailover(): try primary; on a RETRYABLE (provider-down) failure
 *     only, try the secondary — returning which channel delivered.
 *   • Usability helpers the login-method resolver uses to offer only channels that
 *     are actually configured (config-awareness).
 *
 * Guarantees (mirroring the payment layer):
 *   • Failover only on primary.retryable === true (never on a legitimate reject),
 *     and only to an enabled + configured secondary.
 *   • Degrade-don't-die: config/DB problems are caught and surfaced as a clean
 *     failure, never a fatal.
 *   • No single dependency: if a provider is down/unconfigured, another configured
 *     channel still delivers.
 */

require_once __DIR__ . '/OtpChannel.php';
require_once __DIR__ . '/Msg91SmsChannel.php';
require_once __DIR__ . '/Sms2Channel.php';
require_once __DIR__ . '/WhatsAppOtpChannel.php';
require_once __DIR__ . '/LogOtpChannel.php';

class OtpChannelManager
{
    private array $config;
    private ?PDO  $db;
    /** @var array<string,OtpChannel> */
    private array $adapters = [];
    private ?array $routingCache = null;

    public function __construct(?PDO $db = null, ?array $config = null)
    {
        $this->db = $db;
        if ($config !== null) {
            $this->config = $config;
        } else {
            $loaded = @include __DIR__ . '/../../config/auth.channels.php';
            $this->config = is_array($loaded) ? $loaded : [];
        }
    }

    // ── Adapter factory ─────────────────────────────────────────────────────────

    public function knownChannels(): array
    {
        return array_keys($this->config['channels'] ?? []);
    }

    public function adapter(string $name): ?OtpChannel
    {
        $name = strtolower(trim($name));
        if (isset($this->adapters[$name])) {
            return $this->adapters[$name];
        }
        $registry = $this->config['channels'][$name] ?? null;
        if (!$registry) {
            return null;
        }
        $class = (string) ($registry['adapter'] ?? '');
        if ($class === '' || !class_exists($class)) {
            error_log("[OtpChannelManager] Adapter class not found for channel '$name': $class");
            return null;
        }
        $instance = new $class();
        if (!($instance instanceof OtpChannel)) {
            error_log("[OtpChannelManager] '$class' does not implement OtpChannel.");
            return null;
        }
        return $this->adapters[$name] = $instance;
    }

    /** Enabled in config AND holding real credentials. */
    public function isUsable(string $name): bool
    {
        $name = strtolower(trim($name));
        if (empty($this->config['channels'][$name]['enabled'])) {
            return false;
        }
        $a = $this->adapter($name);
        return $a !== null && $a->isConfigured();
    }

    /** Is a specific channel usable? (convenience for the resolver). */
    public function channelUsable(string $name): bool
    {
        return $this->isUsable($name);
    }

    /** Is at least one REAL SMS channel usable right now? (log/dev counts only in dev). */
    public function smsUsable(): bool
    {
        foreach ($this->knownChannels() as $name) {
            $a = $this->adapter($name);
            if ($a !== null && in_array('sms', $a->kinds(), true) && $this->isUsable($name)) {
                return true;
            }
        }
        return false;
    }

    // ── Routing (per delivery kind, override-aware) ─────────────────────────────

    /** Resolve ['primary'=>?string,'secondary'=>?string] for a delivery kind. */
    public function routeFor(string $kind): array
    {
        $kind    = strtolower(trim($kind)) ?: 'default';
        $routing = $this->routing();
        $entry   = $routing[$kind] ?? $routing['default'] ?? ['primary' => 'msg91', 'secondary' => null];
        return [
            'primary'   => isset($entry['primary'])    ? (string) $entry['primary']   : 'msg91',
            'secondary' => !empty($entry['secondary']) ? (string) $entry['secondary'] : null,
        ];
    }

    /** Merged routing (file defaults + optional admin JSON override on top). */
    private function routing(): array
    {
        if ($this->routingCache !== null) {
            return $this->routingCache;
        }
        $routing = (array) ($this->config['routing'] ?? []);
        if ($this->db instanceof PDO) {
            try {
                $stmt = $this->db->prepare(
                    "SELECT setting_value FROM app_settings WHERE setting_key = 'auth_channel_routing' LIMIT 1"
                );
                $stmt->execute();
                $raw = $stmt->fetchColumn();
                if (is_string($raw) && $raw !== '') {
                    $override = json_decode($raw, true);
                    if (is_array($override)) {
                        foreach ($override as $kind => $entry) {
                            if (is_array($entry)) {
                                $routing[strtolower((string) $kind)] = $entry;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                error_log('[OtpChannelManager] routing override unavailable: ' . $e->getMessage());
            }
        }
        return $this->routingCache = $routing;
    }

    // ── Send with failover ──────────────────────────────────────────────────────

    /**
     * Deliver $otp to $phone via $kind ('sms' | 'whatsapp'), failing over
     * primary → secondary on a retryable (provider-down) failure only.
     *
     * @return array The chosen channel's normalised result, PLUS:
     *               'channel'  => name that delivered (or last tried),
     *               'failover' => bool (secondary delivered),
     *               'attempts' => per-channel [channel,http_code,retryable,error,success],
     *               'no_usable_channel' => bool (neither primary nor secondary usable).
     */
    public function sendWithFailover(string $kind, string $phone, string $otp, array $ctx = []): array
    {
        $route    = $this->routeFor($kind);
        $attempts = [];
        $anyUsable = false;

        // ── Primary ────────────────────────────────────────────────────────────
        $primaryName = $route['primary'];
        if ($primaryName !== null && $this->isUsable($primaryName)) {
            $anyUsable = true;
            $res = $this->adapter($primaryName)->send($phone, $otp, $ctx);
            $attempts[] = $this->summary($res);
            if (!empty($res['success'])) {
                return $this->decorate($res, false, $attempts, false);
            }
            if (empty($res['retryable'])) {
                // Legitimate reject (e.g. bad number) — would fail identically
                // elsewhere; do not fail over.
                return $this->decorate($res, false, $attempts, false);
            }
            error_log("[OtpChannelManager] primary '$primaryName' retryable failure for kind '$kind'; trying secondary.");
        } else {
            $attempts[] = ['channel' => (string) $primaryName, 'http_code' => 0, 'retryable' => true,
                           'error' => 'primary not usable (disabled/unconfigured)', 'success' => false];
        }

        // ── Secondary (failover) ────────────────────────────────────────────────
        $secondaryName = $route['secondary'];
        if ($secondaryName !== null && $this->isUsable($secondaryName)) {
            $anyUsable = true;
            $res = $this->adapter($secondaryName)->send($phone, $otp, $ctx);
            $attempts[] = $this->summary($res);
            if (!empty($res['success'])) {
                error_log("[OtpChannelManager] kind '$kind' FAILED OVER to secondary '$secondaryName'.");
            }
            return $this->decorate($res, true, $attempts, false);
        }

        // No usable channel at all — signal the caller (so it may use a legacy path).
        return [
            'success'   => false,
            'channel'   => (string) $primaryName,
            'http_code' => 0,
            'retryable' => false,
            'error'     => 'no usable OTP channel',
            'failover'  => false,
            'attempts'  => $attempts,
            'no_usable_channel' => !$anyUsable,
        ];
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function decorate(array $res, bool $failover, array $attempts, bool $noUsable): array
    {
        $res['failover'] = $failover;
        $res['attempts'] = $attempts;
        $res['no_usable_channel'] = $noUsable;
        return $res;
    }

    private function summary(array $res): array
    {
        return [
            'channel'   => (string) ($res['channel'] ?? 'unknown'),
            'http_code' => (int) ($res['http_code'] ?? 0),
            'retryable' => (bool) ($res['retryable'] ?? false),
            'error'     => $res['error'] ?? null,
            'success'   => (bool) ($res['success'] ?? false),
        ];
    }
}
