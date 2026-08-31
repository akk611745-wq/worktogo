<?php
/**
 * actions_selftest.php — WorkToGo Agent Layer :: Phase 2 proof (no key, no DB, no HTTP)
 * ----------------------------------------------------------------------------
 * Drives the real action runtime with a MOCK AI provider and a MOCK engine client
 * to prove the founder's must-be-right guarantees for the tool layer:
 *
 *   • "painter chahiye" → real vendor search (deterministic, zero AI cost)
 *   • an AI-PROPOSED tool goes THROUGH THE WALL (provider only sees scrubbed text)
 *     and is then executed by our backend
 *   • WRITES never execute on propose — they return a confirmation token; only on
 *     confirm is the booking created via the backend
 *   • the AI can NOT call a tool that isn't exposed to the role (allow-list)
 *   • RBAC: a customer can't use a vendor tool; a vendor can
 *   • a tampered confirmation token is rejected
 *
 *   Run:  php core/agent/tests/actions_selftest.php
 */

declare(strict_types=1);

putenv('JWT_SECRET=selftest-secret-please-ignore-0000');
putenv('ANTHROPIC_API_KEY=');   // ensure no real provider is used

require_once __DIR__ . '/../bootstrap.php';

$pass = 0; $fail = 0;
function check(string $label, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  $label\n"; }
    else       { $fail++; echo "FAIL  $label\n"; }
}

// ── Test doubles ─────────────────────────────────────────────────────────────

/** A mock AI provider that returns a strict-JSON tool proposal (never real data). */
final class SelftestPlannerProvider implements AiProvider {
    public ?string $lastSeenText = null;
    public function name(): string { return 'selftest'; }
    public function isConfigured(): bool { return true; }
    public function complete(SanitizedPrompt $prompt, array $options = []): AiResponse {
        $t = $prompt->userText;
        $this->lastSeenText = $t;
        if (stripos($t, 'renovate') !== false) {
            $json = '{"tool":"find_vendors","args":{"category":"painting"},"say":"Sure, let me find painters."}';
        } elseif (stripos($t, 'danger') !== false) {
            $json = '{"tool":"delete_all","args":{},"say":"ok"}';   // an UNEXPOSED tool
        } else {
            $json = '{"tool":"none","args":{},"say":"How can I help?"}';
        }
        return AiResponse::ok('selftest', $prompt->task, $json, 'selftest-1');
    }
}

/** A mock backend: records calls, returns canned real-shaped data. */
final class MockEngineClient implements EngineClient {
    public array $calls = [];
    public function get(string $path, array $query = []): EngineResult {
        $this->calls[] = ['GET', $path, $query];
        if ($path === '/api/vendors') {
            return EngineResult::ok(200, ['vendors' => [
                ['id' => 101, 'business_name' => 'Sharma Painters', 'rating' => 4.8],
                ['id' => 102, 'business_name' => 'City Paint Co',  'rating' => 4.5],
            ], 'pagination' => ['total' => 2]]);
        }
        if (preg_match('#^/api/vendors/\d+$#', $path)) {
            return EngineResult::ok(200, ['vendor' => ['id' => 101, 'business_name' => 'Sharma Painters']]);
        }
        if ($path === '/api/vendor/jobs')    { return EngineResult::ok(200, ['jobs' => [['id' => 9, 'status' => 'pending']]]); }
        if ($path === '/api/vendor/profile') { return EngineResult::ok(200, ['profile' => ['business_name' => 'Sharma Painters']]); }
        return EngineResult::ok(200, []);
    }
    public function post(string $path, array $body = []): EngineResult {
        $this->calls[] = ['POST', $path, $body];
        if ($path === '/api/service/request') {
            return EngineResult::ok(201, ['booking_id' => 555, 'status' => 'pending', 'booking_mode' => $body['booking_mode'] ?? null]);
        }
        return EngineResult::ok(200, []);
    }
    public function patch(string $path, array $body = []): EngineResult {
        $this->calls[] = ['PATCH', $path, $body];
        return EngineResult::ok(200, ['updated' => true]);
    }
    public function countPost(string $path): int {
        return count(array_filter($this->calls, fn ($c) => $c[0] === 'POST' && $c[1] === $path));
    }
}

$cfg = [
    'providers' => [
        'selftest' => ['adapter' => 'SelftestPlannerProvider', 'enabled' => true],
        'mock'     => ['adapter' => 'MockAiAdapter',            'enabled' => true],
    ],
    'routing' => [
        'default'    => ['primary' => 'selftest', 'secondary' => 'mock'],
        'understand' => ['primary' => 'selftest', 'secondary' => 'mock'],
    ],
];

/** Build a fresh runtime (shared wall so re-hydrate works). */
function newSetup(array $cfg): array {
    $wall     = new PiiWall(null);
    $manager  = new AiProviderManager(null, $cfg);
    $registry = ToolRegistry::defaultRegistry();
    $planner  = new ToolPlanner($wall, $manager, $registry);
    $engine   = new MockEngineClient();
    $orch     = new AgentActionOrchestrator($wall, $planner, $registry, $engine, null);
    return [$orch, $engine, $manager];
}
function customer(): AgentContext { return AgentContext::fromAuth(['user_id' => 1, 'role' => 'customer'], [], null); }
function vendor(): AgentContext   { return AgentContext::fromAuth(['user_id' => 2, 'role' => 'vendor_service'], [], null); }

// ── A. Deterministic read: "painter chahiye" → real vendor search ────────────
[$orch, $engine] = newSetup($cfg);
$rA = $orch->handle(customer(), ['message' => 'painter chahiye']);
check('A1 deterministic picked find_vendors', ($rA['tool'] ?? '') === 'find_vendors');
check('A2 read executed',                     ($rA['executed'] ?? false) === true);
check('A3 real vendors returned (2)',         count($rA['data']['vendors'] ?? []) === 2);
check('A4 backend GET /api/vendors called',   $engine->calls[0][1] === '/api/vendors');

// ── B. AI-proposed read goes THROUGH THE WALL, then executes ─────────────────
[$orch, $engine, $manager] = newSetup($cfg);
$rB = $orch->handle(customer(), ['message' => 'I want to renovate my home, call me on 9876543210']);
$provider = $manager->adapter('selftest');
check('B1 AI proposal executed find_vendors', ($rB['tool'] ?? '') === 'find_vendors' && ($rB['executed'] ?? false) === true);
check('B2 provider NEVER saw the raw phone',  strpos($provider->lastSeenText ?? '', '9876543210') === false);
check('B3 provider saw a <PHONE_1> placeholder', strpos($provider->lastSeenText ?? '', '<PHONE_1>') !== false);

// ── C. WRITE proposes, does NOT execute; returns a confirm token ─────────────
[$orch, $engine] = newSetup($cfg);
$rC = $orch->handle(customer(), ['action' => ['tool' => 'create_booking',
        'args' => ['vendor_id' => 101, 'scheduled_at' => '2099-01-01 10:00:00']]]);
check('C1 write requires confirmation',   ($rC['requires_confirmation'] ?? false) === true);
check('C2 write NOT executed on propose', ($rC['executed'] ?? true) === false);
check('C3 confirm_token issued',          !empty($rC['confirm_token']));
check('C4 backend NOT called yet',        $engine->countPost('/api/service/request') === 0);

// ── D. Confirm executes the write via the real backend ───────────────────────
[$orch, $engine] = newSetup($cfg);
$rD = $orch->handle(customer(), ['confirm_token' => $rC['confirm_token']]);
check('D1 confirmed write executed',      ($rD['executed'] ?? false) === true);
check('D2 booking created (id 555)',      (int)($rD['data']['booking_id'] ?? 0) === 555);
check('D3 backend POST /api/service/request called once', $engine->countPost('/api/service/request') === 1);
check('D4 booking_mode was direct_vendor', ($rD['data']['booking_mode'] ?? '') === 'direct_vendor');

// ── E. Allow-list: AI proposes an UNEXPOSED tool → rejected, no call ─────────
[$orch, $engine] = newSetup($cfg);
$rE = $orch->handle(customer(), ['message' => 'do something danger']);
check('E1 unexposed tool rejected', ($rE['rejected'] ?? false) === true);
check('E2 nothing executed',        ($rE['executed'] ?? true) === false);
check('E3 backend never called',    count($engine->calls) === 0);

// ── F. RBAC: customer can't use a vendor tool; vendor can ────────────────────
[$orch, $engine] = newSetup($cfg);
$rF1 = $orch->handle(customer(), ['action' => ['tool' => 'list_my_jobs', 'args' => []]]);
check('F1 customer blocked from vendor tool', ($rF1['rejected'] ?? false) === true && count($engine->calls) === 0);
[$orch, $engine] = newSetup($cfg);
$rF2 = $orch->handle(vendor(), ['message' => 'show my jobs']);
check('F2 vendor allowed list_my_jobs', ($rF2['tool'] ?? '') === 'list_my_jobs' && ($rF2['executed'] ?? false) === true);

// ── G. Tampered confirm token is rejected ────────────────────────────────────
[$orch, $engine] = newSetup($cfg);
$bad = substr($rC['confirm_token'], 0, -3) . 'zzz';
$rG  = $orch->handle(customer(), ['confirm_token' => $bad]);
check('G1 tampered token rejected',    ($rG['executed'] ?? true) === false);
check('G2 no booking created',         $engine->countPost('/api/service/request') === 0);

echo "\n----------------------------------------\n";
echo "RESULT: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
