<?php
/**
 * memory_selftest.php — WorkToGo Agent Layer :: Phase 4 memory proof (no key, no DB)
 * ----------------------------------------------------------------------------
 * Proves the memory/personalization guarantees WITHOUT a DB or an AI key:
 *   • hints() emits ONLY coarse, non-identifying tokens (returning / pref_category
 *     / area_known) — never the user id, the area value, or the city value.
 *   • personalizedGreeting() recognises a returning user and differs from a new one.
 *   • the privacy wall ACCEPTS the anonymized hints (no leak), and FAILS CLOSED if
 *     a hint ever contained PII (the tripwire covers meta.hints too).
 *
 *   Run:  php core/agent/tests/memory_selftest.php
 */

declare(strict_types=1);

putenv('JWT_SECRET=selftest-secret-please-ignore-0000');
require_once __DIR__ . '/../bootstrap.php';

$pass = 0; $fail = 0;
function check(string $label, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  $label\n"; }
    else       { $fail++; echo "FAIL  $label\n"; }
}

$mem = new AgentMemory(null); // no DB → load() is empty; we feed rows directly

// A returning user's INTERNAL memory row (as load() would return it).
$returning = [
    'turn_count'      => 4,
    'pref_categories' => ['painting' => 3, 'plumber' => 1],
    'last_category'   => 'painting',
    'last_area'       => 'Rajpur Road, Haldwani',   // INTERNAL — must never surface in hints
    'last_city_id'    => 7,                           // INTERNAL — must never surface in hints
    'recent_tools'    => ['find_vendors', 'create_booking'],
];
$newUser = ['turn_count' => 0, 'pref_categories' => [], 'last_category' => null, 'last_area' => null, 'last_city_id' => null, 'recent_tools' => []];

// ── 1. hints are anonymized ──────────────────────────────────────────────────
$hints = $mem->hints($returning);
$blob  = strtolower(implode('|', $hints));
check('1a returning flag present',           in_array('returning', $hints, true));
check('1b top preferred category present',   in_array('pref_category:painting', $hints, true));
check('1c area_known FLAG present',          in_array('area_known', $hints, true));
check('1d hints DO NOT leak the area value', strpos($blob, 'rajpur') === false);
check('1e hints DO NOT leak the city id',    strpos($blob, '7') === false && strpos($blob, 'city') === false);
check('1f new user has no hints',            $mem->hints($newUser) === []);

// ── 2. personalized greeting ─────────────────────────────────────────────────
$gr = $mem->personalizedGreeting($returning, 'Aman');
$gn = $mem->personalizedGreeting($newUser, 'Aman');
check('2a returning greeting is personalized', $gr['personalized'] === true);
check('2b returning greeting names the habit', stripos($gr['reply'], 'painting') !== false);
check('2c new-user greeting is generic',       $gn['personalized'] === false);

// ── 3. the wall accepts clean hints, and fails closed on a PII-laden hint ────
$wall = new PiiWall(null);
$ctx  = AgentContext::fromAuth(['user_id' => 1, 'role' => 'customer'], [], null);
$okThrew = false;
try { $wall->sanitize($ctx, 'painter chahiye', 'You are the assistant.', ['find_vendors'], 'understand', $hints); }
catch (PiiLeakException $e) { $okThrew = true; }
check('3a wall accepts anonymized hints (no leak)', $okThrew === false);

$leaked = false;
try {
    // A mis-derived hint that carries PII must be refused by the tripwire.
    $wall->sanitize($ctx, 'hi', 'You are the assistant.', ['find_vendors'], 'understand', ['call 9876543210']);
} catch (PiiLeakException $e) { $leaked = true; }
check('3b wall FAILS CLOSED on a PII-laden hint', $leaked === true);

echo "\n----------------------------------------\n";
echo "RESULT: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
