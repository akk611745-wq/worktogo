<?php
/**
 * foundation_selftest.php — WorkToGo Agent Layer :: headless proof (foundation)
 * ----------------------------------------------------------------------------
 * Proves the privacy wall + pluggable provider layer end to end WITHOUT any live
 * API key, DB, or HTTP — it drives the real classes directly with the built-in
 * MockAiAdapter. Run it whenever PHP is available:
 *
 *     php core/agent/tests/foundation_selftest.php
 *
 * It asserts the founder's must-be-right guarantees:
 *   • PII never reaches a provider (the mock only ever sees placeholders).
 *   • The reply is re-hydrated for the OWNER (their own data comes back).
 *   • Providers are swappable/failover by config (unconfigured real provider ⇒
 *     degrade to mock; primary=mock ⇒ no failover).
 *   • A SanitizedPrompt cannot be minted outside the wall (structural guarantee).
 *   • The wall fails CLOSED if PII would still leave (tripwire).
 *   • The agent is register-gated at the context boundary.
 *
 * Exit code is non-zero if any check fails (CI-friendly).
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$pass = 0; $fail = 0;
function check(string $label, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  $label\n"; }
    else       { $fail++; echo "FAIL  $label\n"; }
}

// Ensure the real Anthropic provider is UNCONFIGURED for this run (forces degrade).
putenv('ANTHROPIC_API_KEY=');

$phone = '9876543210';
$email = 'ramesh.kumar@example.com';
$ref   = 'WTG_1024_1700000000';
$msg   = "Hi, mera number $phone hai, email $email, order $ref — mujhe ek acha painter chahiye";

// Config: real provider primary (no key ⇒ unusable), mock as safe secondary.
$config = [
    'providers' => [
        'mock'      => ['adapter' => 'MockAiAdapter',    'enabled' => true],
        'anthropic' => ['adapter' => 'AnthropicAdapter', 'enabled' => true, 'model' => 'claude-opus-5'],
        'openai'    => ['adapter' => 'OpenAiAdapter',    'enabled' => false],
        'gemini'    => ['adapter' => 'GeminiAdapter',    'enabled' => false],
    ],
    'routing' => [
        'default'    => ['primary' => 'anthropic', 'secondary' => 'mock'],
        'understand' => ['primary' => 'anthropic', 'secondary' => 'mock'],
    ],
];

// ── 1. Full turn: wall → manager(failover) → mock → re-hydrate ────────────────
$wall    = new PiiWall(null);
$manager = new AiProviderManager(null, $config);
$orch    = new AgentOrchestrator($wall, $manager, null);
$ctx     = AgentContext::fromAuth(['user_id' => 1, 'role' => 'customer'], ['language' => 'hi'], null);
$res     = $orch->handle($ctx, $msg);

$mock = $manager->adapter('mock');
$seen = $mock->lastSeenText ?? '';

check('provider degraded to mock (real AI unconfigured)', $res['provider'] === 'mock');
check('failover flag is set',                              $res['failover'] === true);
check('mock NEVER saw the raw phone',    strpos($seen, $phone) === false);
check('mock NEVER saw the raw email',    strpos($seen, $email) === false);
check('mock NEVER saw the raw order ref',strpos($seen, $ref)   === false);
check('mock saw a <PHONE_1> placeholder',strpos($seen, '<PHONE_1>') !== false);
check('mock saw an <EMAIL_1> placeholder',strpos($seen, '<EMAIL_1>') !== false);
check('mock saw an <ID_1> placeholder',  strpos($seen, '<ID_1>')    !== false);
check('reply re-hydrates the phone for the owner', strpos($res['reply'], $phone) !== false);
check('redaction summary counted phone', ($res['redactions']['PHONE'] ?? 0) >= 1);
check('redaction summary counted email', ($res['redactions']['EMAIL'] ?? 0) >= 1);

// ── 2. Provider swap by config: primary=mock ⇒ no failover ────────────────────
$config2 = $config;
$config2['routing']['understand'] = ['primary' => 'mock', 'secondary' => null];
$o2  = new AgentOrchestrator(new PiiWall(null), new AiProviderManager(null, $config2), null);
$r2  = $o2->handle(AgentContext::fromAuth(['user_id' => 2, 'role' => 'customer'], [], null), 'need a plumber');
check('provider swappable by config (primary=mock ⇒ no failover)',
      $r2['provider'] === 'mock' && $r2['failover'] === false);

// ── 3. Structural guarantee: SanitizedPrompt cannot be forged ─────────────────
try {
    SanitizedPrompt::seal('sys', 'user', 'understand', [], 'rid-x');
    check('forging a SanitizedPrompt outside the wall throws', false);
} catch (AgentSecurityException $e) {
    check('forging a SanitizedPrompt outside the wall throws', true);
}

// ── 4. Fail-closed tripwire: PII in the outbound blob is refused ──────────────
try {
    // PII placed in the SYSTEM text (not scrubbed) must be caught by the tripwire.
    (new PiiWall(null))->sanitize(
        AgentContext::fromAuth(['user_id' => 3, 'role' => 'customer'], [], null),
        'hello',
        'Please call me at 9876543210.',
        [],
        'understand'
    );
    check('wall fails CLOSED when PII would still leave', false);
} catch (PiiLeakException $e) {
    check('wall fails CLOSED when PII would still leave', true);
}

// ── 5. Register-gate at the context boundary ──────────────────────────────────
try {
    AgentContext::fromAuth([], [], null);   // no authenticated user
    check('AgentContext requires an authenticated user (register-gate)', false);
} catch (AgentSecurityException $e) {
    check('AgentContext requires an authenticated user (register-gate)', true);
}

echo "\n----------------------------------------\n";
echo "RESULT: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
