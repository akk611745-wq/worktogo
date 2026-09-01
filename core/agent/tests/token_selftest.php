<?php
/**
 * token_selftest.php — WorkToGo Agent Layer :: confirm-token single-use proof (audit H1)
 * ----------------------------------------------------------------------------
 * Proves the write-confirmation token is now SINGLE-USE and still tamper/uid/exp
 * safe, WITHOUT a live DB — a tiny fake PDO models the `agent_used_tokens` store
 * (PRIMARY KEY on jti). It asserts:
 *   T1 verify() returns the full payload (tool/args/uid/nonce) needed to consume.
 *   T2 first consume() of a token succeeds (claimed).
 *   T3 a REPLAY of the same token is rejected (duplicate jti) — no double-write.
 *   T4 with no DB, consume() degrades to allow (prior behaviour, pre-migration).
 *   T5 with the table absent, consume() degrades to allow (single-use not active).
 *   T6 a token minted for user A is rejected for user B (uid bind — unchanged).
 *   T7 a tampered token is rejected (HMAC — unchanged).
 *
 *   Run:  php core/agent/tests/token_selftest.php
 */

declare(strict_types=1);

putenv('JWT_SECRET=selftest-secret-please-ignore-0000');
require_once __DIR__ . '/../tools/ConfirmToken.php';

$pass = 0; $fail = 0;
function check(string $label, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  $label\n"; }
    else       { $fail++; echo "FAIL  $label\n"; }
}

// ── Fake used-token store: models the PK-on-jti gate ─────────────────────────
final class FakePdoEx extends PDOException {
    public function __construct(string $msg, string $sqlstate) { parent::__construct($msg); $this->code = $sqlstate; }
}
final class UTStmt extends PDOStatement {
    public function __construct(public string $sql, public UsedTokenPdo $pdo) {}
    #[\ReturnTypeWillChange]
    public function execute($params = null): bool {
        if ($this->pdo->tableMissing) {
            throw new FakePdoEx("Base table or view not found: 'agent_used_tokens'", '42S02');
        }
        if (stripos($this->sql, 'INSERT INTO agent_used_tokens') !== false) {
            $jti = (string) (($params ?? [])[':jti'] ?? '');
            if (isset($this->pdo->jtis[$jti])) {
                throw new FakePdoEx('Integrity constraint violation: 1062 Duplicate entry', '23000');
            }
            $this->pdo->jtis[$jti] = true;
        }
        return true; // DELETE purge, or a fresh INSERT
    }
}
final class UsedTokenPdo extends PDO {
    public array $jtis = [];
    public bool  $tableMissing = false;
    public function __construct() {}                       // bypass real connect
    #[\ReturnTypeWillChange]
    public function prepare($query, $options = []) { return new UTStmt((string) $query, $this); }
}

// ── T1: verify returns the full payload ──────────────────────────────────────
$tok = ConfirmToken::issue(7, 'checkout', ['address_id' => 5]);
$pay = ConfirmToken::verify($tok, 7);
check('T1 verify returns tool/args/uid/nonce',
    $pay !== null && $pay['tool'] === 'checkout' && (int) ($pay['args']['address_id'] ?? 0) === 5
    && (int) ($pay['uid'] ?? 0) === 7 && !empty($pay['nonce']));

// ── T2/T3: single-use — first claim OK, replay refused ───────────────────────
$store = new UsedTokenPdo();
check('T2 first use is claimed',   ConfirmToken::consume($store, $pay) === true);
check('T3 replay is rejected',     ConfirmToken::consume($store, $pay) === false);
// A DIFFERENT token (fresh nonce) is independent — still claimable.
$pay2 = ConfirmToken::verify(ConfirmToken::issue(7, 'checkout', ['address_id' => 5]), 7);
check('T3b a different token still works', ConfirmToken::consume($store, $pay2) === true);

// ── T4: no DB → degrade to allow (prior behaviour, pre-migration) ────────────
check('T4 no DB degrades to allow', ConfirmToken::consume(null, $pay) === true);

// ── T5: table absent → degrade to allow (single-use not yet active) ──────────
$missing = new UsedTokenPdo(); $missing->tableMissing = true;
check('T5 table-absent degrades to allow', ConfirmToken::consume($missing, $pay) === true);

// ── T6/T7: uid-bind + tamper still hold (unchanged guarantees) ───────────────
check('T6 wrong-user token rejected', ConfirmToken::verify($tok, 999) === null);
$bad = substr($tok, 0, -3) . 'zzz';
check('T7 tampered token rejected',   ConfirmToken::verify($bad, 7) === null);

echo "\n----------------------------------------\n";
echo "RESULT: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
