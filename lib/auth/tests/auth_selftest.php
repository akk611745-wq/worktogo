<?php
/**
 * auth_selftest.php — WorkToGo Smart Auth Layer proof (no key, no DB, no HTTP)
 * ----------------------------------------------------------------------------
 * Proves the founder's must-be-right guarantees for the auth abstraction with
 * injected fake channels + a fake PDO — no live SMS provider or DB:
 *   F1 OTP via primary (success, no failover).
 *   F2 primary retryable failure → SECONDARY delivers (failover=true).
 *   F3 primary TERMINAL failure → NO failover (secondary not tried).
 *   F4 primary usable but no usable secondary → clean failure, no_usable_channel.
 *   A1 an UNCONFIGURED channel (WhatsApp stub) is NOT offered; SMS is.
 *   A2 Google is offered only when GOOGLE_CLIENT_ID is set.
 *   R1 reveal OFF (default) ⇒ no per-account facts (no enumeration oracle).
 *   R2 reveal ON + a password user ⇒ password + phone_otp offered, preferred password.
 *   R3 reveal ON + an OTP-only user ⇒ password NOT offered, preferred phone_otp.
 *   R4 reveal ON + not registered ⇒ preferred phone_otp (register via OTP).
 *   S1 a send result NEVER contains the OTP (metadata only).
 *   C1 the real config: msg91 enabled(real), whatsapp+sms2 are disabled stubs.
 *
 *   Run:  php lib/auth/tests/auth_selftest.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$pass = 0; $fail = 0;
function check(string $label, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  $label\n"; }
    else       { $fail++; echo "FAIL  $label\n"; }
}

// ── Fake channels (parameterless so the manager can `new $class()`) ──────────
final class SelftestSmsOk implements OtpChannel {
    public function name(): string { return 'ok'; }
    public function kinds(): array { return ['sms']; }
    public function isConfigured(): bool { return true; }
    public function send(string $p, string $o, array $c = []): array {
        return ['success' => true, 'channel' => $this->name(), 'http_code' => 200, 'retryable' => false, 'error' => null];
    }
}
final class SelftestSmsSecondaryOk implements OtpChannel {
    public function name(): string { return 'secondary'; }
    public function kinds(): array { return ['sms']; }
    public function isConfigured(): bool { return true; }
    public function send(string $p, string $o, array $c = []): array {
        return ['success' => true, 'channel' => $this->name(), 'http_code' => 200, 'retryable' => false, 'error' => null];
    }
}
final class SelftestSmsRetryFail implements OtpChannel {
    public function name(): string { return 'retryfail'; }
    public function kinds(): array { return ['sms']; }
    public function isConfigured(): bool { return true; }
    public function send(string $p, string $o, array $c = []): array {
        return ['success' => false, 'channel' => $this->name(), 'http_code' => 503, 'retryable' => true, 'error' => 'down'];
    }
}
final class SelftestSmsTermFail implements OtpChannel {
    public function name(): string { return 'termfail'; }
    public function kinds(): array { return ['sms']; }
    public function isConfigured(): bool { return true; }
    public function send(string $p, string $o, array $c = []): array {
        return ['success' => false, 'channel' => $this->name(), 'http_code' => 400, 'retryable' => false, 'error' => 'bad number'];
    }
}

$OTP = '654321';

// ── F1: primary delivers ─────────────────────────────────────────────────────
$cfg1 = ['channels' => ['p' => ['adapter' => 'SelftestSmsOk', 'enabled' => true],
                        's' => ['adapter' => 'SelftestSmsSecondaryOk', 'enabled' => true]],
         'routing' => ['sms' => ['primary' => 'p', 'secondary' => 's']]];
$r1 = (new OtpChannelManager(null, $cfg1))->sendWithFailover('sms', '+919876543210', $OTP);
check('F1 primary delivers (no failover)', !empty($r1['success']) && $r1['failover'] === false && $r1['channel'] === 'ok');

// ── F2: primary retryable fail → secondary ───────────────────────────────────
$cfg2 = ['channels' => ['p' => ['adapter' => 'SelftestSmsRetryFail', 'enabled' => true],
                        's' => ['adapter' => 'SelftestSmsSecondaryOk', 'enabled' => true]],
         'routing' => ['sms' => ['primary' => 'p', 'secondary' => 's']]];
$r2 = (new OtpChannelManager(null, $cfg2))->sendWithFailover('sms', '+919876543210', $OTP);
check('F2 retryable primary fails over to secondary', !empty($r2['success']) && $r2['failover'] === true && $r2['channel'] === 'secondary');

// ── F3: primary terminal fail → NO failover ──────────────────────────────────
$cfg3 = ['channels' => ['p' => ['adapter' => 'SelftestSmsTermFail', 'enabled' => true],
                        's' => ['adapter' => 'SelftestSmsSecondaryOk', 'enabled' => true]],
         'routing' => ['sms' => ['primary' => 'p', 'secondary' => 's']]];
$r3 = (new OtpChannelManager(null, $cfg3))->sendWithFailover('sms', '+919876543210', $OTP);
check('F3 terminal primary does NOT fail over', empty($r3['success']) && $r3['failover'] === false && count($r3['attempts']) === 1);

// ── F4: no usable secondary → clean failure + no_usable_channel signal ───────
$cfg4 = ['channels' => ['p' => ['adapter' => 'SelftestSmsRetryFail', 'enabled' => true]],
         'routing' => ['sms' => ['primary' => 'p', 'secondary' => null]]];
$r4 = (new OtpChannelManager(null, $cfg4))->sendWithFailover('sms', '+919876543210', $OTP);
check('F4 retryable primary, no secondary → clean failure', empty($r4['success']) && $r4['failover'] === false);

// ── A1/A2: config-awareness — only configured methods are offered ─────────────
putenv('GOOGLE_CLIENT_ID=selftest.apps.googleusercontent.com'); // Google configured
$cfgR = ['channels' => ['p'  => ['adapter' => 'SelftestSmsOk',       'enabled' => true],
                        'wa' => ['adapter' => 'WhatsAppOtpChannel',  'enabled' => true]], // stub ⇒ not configured
         'routing'  => ['sms' => ['primary' => 'p', 'secondary' => null],
                        'whatsapp' => ['primary' => 'wa', 'secondary' => 'p']],
         'methods'  => ['password' => true, 'phone_otp' => true, 'whatsapp_otp' => true, 'google' => true],
         'reveal_account_methods' => false];
$mgrR = new OtpChannelManager(null, $cfgR);
$resR = new LoginMethodResolver($mgrR, $cfgR);
$pm   = $resR->platformMethods();
check('A1 phone_otp offered (SMS usable)',          in_array('phone_otp', $pm, true));
check('A1 whatsapp_otp NOT offered (stub, unconfigured)', !in_array('whatsapp_otp', $pm, true));
check('A1 channels() reports whatsapp false, sms true', $resR->channels()['whatsapp'] === false && $resR->channels()['sms'] === true);
check('A2 google offered when GOOGLE_CLIENT_ID set', in_array('google', $pm, true));

// ── R1: reveal OFF ⇒ no per-account facts (secure default) ───────────────────
final class RStmt extends PDOStatement {
    public function __construct(public $row) {}
    #[\ReturnTypeWillChange] public function execute($params = null): bool { return true; }
    #[\ReturnTypeWillChange] public function fetch($mode = 0, ...$a) { return $this->row; }
}
final class RPdo extends PDO {
    public function __construct(public $row) {}
    #[\ReturnTypeWillChange] public function prepare($q, $o = []) { return new RStmt($this->row); }
}
$offRes = $resR->methodsForIdentifier(new RPdo(['password' => '$2y$x', 'google_id' => null]), '+919876543210');
check('R1 reveal OFF ⇒ account facts withheld', $offRes['account'] === null && in_array('phone_otp', $offRes['methods'], true));

// ── R2/R3/R4: reveal ON refines per identifier ───────────────────────────────
$cfgReveal = $cfgR; $cfgReveal['reveal_account_methods'] = true;
$resV = new LoginMethodResolver(new OtpChannelManager(null, $cfgReveal), $cfgReveal);

$pwUser = $resV->methodsForIdentifier(new RPdo(['password' => '$2y$abc', 'google_id' => null]), '+919876543210');
check('R2 password user ⇒ password + phone_otp, preferred password',
      in_array('password', $pwUser['methods'], true) && in_array('phone_otp', $pwUser['methods'], true) && $pwUser['preferred'] === 'password');

$otpUser = $resV->methodsForIdentifier(new RPdo(['password' => '', 'google_id' => null]), '+919876543210');
check('R3 OTP-only user ⇒ password NOT offered, preferred phone_otp',
      !in_array('password', $otpUser['methods'], true) && $otpUser['preferred'] === 'phone_otp');

$newUser = $resV->methodsForIdentifier(new RPdo(false), '+919876543210');
check('R4 not-registered phone ⇒ preferred phone_otp (register via OTP)',
      ($newUser['account']['registered'] ?? true) === false && $newUser['preferred'] === 'phone_otp');

// ── S1: a send result NEVER contains the OTP (metadata only) ─────────────────
check('S1 send result carries NO OTP (privacy)', strpos((string) json_encode($r1), $OTP) === false);

// ── C1: the REAL config — msg91 real/enabled; sms2 + whatsapp disabled stubs ──
$realMgr = new OtpChannelManager(null); // loads config/auth.channels.php
check('C1 msg91 is a known channel',            in_array('msg91', $realMgr->knownChannels(), true));
check('C1 whatsapp stub not usable by default', $realMgr->channelUsable('whatsapp') === false);
check('C1 sms2 stub not usable by default',     $realMgr->channelUsable('sms2') === false);

echo "\n----------------------------------------\n";
echo "RESULT: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
