<?php
/**
 * LoginMethodResolver.php  —  WorkToGo Smart Auth Layer
 * ----------------------------------------------------------------------------
 * The "system knows what's available" brain. Given the platform config + what is
 * actually configured (channels usable, Google client set) — and, optionally, a
 * specific identifier — it decides which login/registration methods to OFFER, so
 * the frontend presents the right options and the user takes the fastest path,
 * never stuck on one method.
 *
 * ── Config-awareness ──────────────────────────────────────────────────────────
 * A method is offered ONLY if it is enabled in config AND its backing capability
 * is present: phone_otp needs a usable SMS channel; whatsapp_otp needs a usable
 * WhatsApp channel; google needs GOOGLE_CLIENT_ID(S). So WhatsApp isn't shown
 * until it is connected — the founder's "only configured methods are offered".
 *
 * ── Enumeration-safety (secure by default) ────────────────────────────────────
 * Per-identifier ACCOUNT facts (registered? has a password?) are returned ONLY
 * when config.reveal_account_methods is true. Default OFF ⇒ this is never an
 * account-existence oracle; the platform methods are still returned so the UI can
 * offer the right options and let the user choose. Turn it on (rate-limited) to
 * lead a known user straight to their fastest method.
 */

require_once __DIR__ . '/OtpChannelManager.php';

final class LoginMethodResolver
{
    public function __construct(
        private readonly OtpChannelManager $mgr,
        private readonly array $config
    ) {}

    /** Which methods the PLATFORM can offer right now (no account facts). */
    public function platformMethods(): array
    {
        $m   = [];
        $cfg = (array) ($this->config['methods'] ?? []);
        if (!empty($cfg['password'])) {
            $m[] = 'password';
        }
        if (!empty($cfg['phone_otp']) && $this->mgr->smsUsable()) {
            $m[] = 'phone_otp';
        }
        if (!empty($cfg['whatsapp_otp']) && $this->mgr->channelUsable('whatsapp')) {
            $m[] = 'whatsapp_otp';
        }
        if (!empty($cfg['google']) && self::googleConfigured()) {
            $m[] = 'google';
        }
        return $m;
    }

    /** OTP delivery kinds that are actually usable (for the UI's channel switch). */
    public function channels(): array
    {
        return [
            'sms'      => $this->mgr->smsUsable(),
            'whatsapp' => $this->mgr->channelUsable('whatsapp'),
        ];
    }

    public function revealAccountMethods(): bool
    {
        return !empty($this->config['reveal_account_methods']);
    }

    /**
     * Methods offerable for a specific identifier (phone or email). Account facts
     * are included only when reveal is enabled (see file header).
     *
     * @return array{identifier_type:string,methods:array,preferred:?string,account:?array}
     */
    public function methodsForIdentifier(PDO $db, string $identifier): array
    {
        $identifier = trim($identifier);
        $type       = self::identifierType($identifier);
        $platform   = $this->platformMethods();

        // Offerable subset for this identifier type. Password login accepts a phone
        // OR an email; Google yields an email; OTP needs a phone.
        $methods = array_values(array_filter($platform, static function (string $m) use ($type) {
            if ($type === 'email') {
                return in_array($m, ['password', 'google'], true);
            }
            if ($type === 'phone') {
                return in_array($m, ['password', 'phone_otp', 'whatsapp_otp'], true);
            }
            return true; // unknown identifier → offer everything the platform has
        }));

        // Secure default: no per-account facts, no preferred lead.
        if (!$this->revealAccountMethods() || $type === 'unknown') {
            return ['identifier_type' => $type, 'methods' => $methods, 'preferred' => null, 'account' => null];
        }

        // Reveal ON: refine with account facts (booleans only — never the hash/PII).
        $account   = $this->lookupAccount($db, $identifier, $type);
        $preferred = null;
        if ($account === null) {
            // Not registered → fastest path is OTP (which also registers) for a
            // phone; an email would register with a password.
            $preferred = ($type === 'phone') ? 'phone_otp' : 'password';
        } else {
            if (!$account['has_password']) {
                // OTP-only account: there is no password to offer.
                $methods   = array_values(array_diff($methods, ['password']));
                $preferred = ($type === 'phone') ? 'phone_otp' : ($account['has_google'] ? 'google' : 'phone_otp');
            } else {
                $preferred = 'password';
            }
        }

        return [
            'identifier_type' => $type,
            'methods'         => $methods,
            'preferred'       => $preferred,
            'account'         => $account === null
                ? ['registered' => false]
                : ['registered' => true, 'has_password' => $account['has_password'], 'has_google' => $account['has_google']],
        ];
    }

    // ── internals ────────────────────────────────────────────────────────────────

    private function lookupAccount(PDO $db, string $identifier, string $type): ?array
    {
        try {
            if ($type === 'email') {
                $stmt = $db->prepare(
                    "SELECT password, google_id FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1"
                );
                $stmt->execute([$identifier]);
            } else {
                $norm = preg_replace('/\D+/', '', $identifier);
                $stmt = $db->prepare(
                    "SELECT password, google_id FROM users
                      WHERE (phone = ?
                         OR REPLACE(REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-',''),'(','') = ?)
                        AND deleted_at IS NULL
                      LIMIT 1"
                );
                $stmt->execute([$identifier, $norm]);
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            return [
                'has_password' => trim((string) ($row['password'] ?? '')) !== '',
                'has_google'   => trim((string) ($row['google_id'] ?? '')) !== '',
            ];
        } catch (\Throwable $e) {
            // Degrade to "unknown account" — never leak a DB error, never fatal.
            return null;
        }
    }

    private static function identifierType(string $id): string
    {
        if ($id === '') {
            return 'unknown';
        }
        if (filter_var($id, FILTER_VALIDATE_EMAIL)) {
            return 'email';
        }
        $digits = preg_replace('/\D+/', '', $id);
        if (strlen((string) $digits) >= 8) {
            return 'phone';
        }
        return 'unknown';
    }

    private static function googleConfigured(): bool
    {
        $ids = (string) (getenv('GOOGLE_CLIENT_IDS') ?: getenv('GOOGLE_CLIENT_ID') ?: '');
        return trim($ids) !== '';
    }
}
