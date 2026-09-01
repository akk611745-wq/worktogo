<?php
/**
 * ConfirmToken.php — WorkToGo Agent Layer :: signed write-confirmation (Phase 2)
 * ----------------------------------------------------------------------------
 * A WRITE tool is never executed on the turn it is proposed. The orchestrator
 * hands back a signed, short-lived token that binds {user, tool, args}. Only when
 * the SAME user sends that token back is the write executed — and the engine then
 * re-validates everything. This makes "confirmation required for writes"
 * tamper-proof and stateless (no pending-action table needed):
 *
 *   • HMAC-SHA256 over the payload ⇒ the tool/args cannot be altered after issue.
 *   • uid binding ⇒ user A's confirmation can't trigger user B's action.
 *   • exp ⇒ a stale confirmation can't be replayed later.
 *   • nonce + a used-token store ⇒ SINGLE-USE: the SAME confirmation can't be
 *     replayed within its TTL to double-execute a write (audit H1). The signature
 *     stays stateless for integrity/uid/exp; only the tiny used-nonce set is
 *     persisted (agent_used_tokens), claimed atomically before the write runs.
 *
 * The token carries only ids/enums/datetimes (no PII) and is shown to the user
 * whose action it is. The secret is the app's JWT_SECRET (or AGENT_CONFIRM_SECRET).
 */

declare(strict_types=1);

final class ConfirmToken
{
    private const VERSION  = 1;
    private const DEFAULT_TTL = 600; // 10 minutes

    /** Issue a signed token binding this user + proposed write. */
    public static function issue(int $userId, string $tool, array $args, int $ttl = self::DEFAULT_TTL): string
    {
        $payload = [
            'v'     => self::VERSION,
            'uid'   => $userId,
            'tool'  => $tool,
            'args'  => $args,
            'exp'   => time() + max(30, $ttl),
            'nonce' => bin2hex(random_bytes(16)),   // 128-bit jti for the single-use store (audit H1)
        ];
        $body = self::b64url(json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}');
        $sig  = self::sign($body);
        return $body . '.' . $sig;
    }

    /**
     * Verify a token for a specific user. Returns ['tool'=>..,'args'=>..] on
     * success, or null on any failure (bad signature, wrong user, expired, …).
     */
    public static function verify(string $token, int $userId): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$body, $sig] = $parts;
        if (!hash_equals(self::sign($body), $sig)) {
            return null;
        }
        $payload = json_decode(self::b64urlDecode($body), true);
        if (!is_array($payload)) {
            return null;
        }
        if (($payload['v'] ?? 0) !== self::VERSION) {
            return null;
        }
        if ((int) ($payload['uid'] ?? 0) !== $userId) {
            return null;
        }
        if ((int) ($payload['exp'] ?? 0) <= time()) {
            return null;
        }
        $tool = (string) ($payload['tool'] ?? '');
        $args = is_array($payload['args'] ?? null) ? $payload['args'] : [];
        if ($tool === '') {
            return null;
        }
        // Return the full VERIFIED payload — the caller needs `nonce` to enforce
        // single-use (audit H1), plus uid/exp for the used-token store.
        return [
            'tool'  => $tool,
            'args'  => $args,
            'uid'   => (int) ($payload['uid'] ?? 0),
            'exp'   => (int) ($payload['exp'] ?? 0),
            'nonce' => (string) ($payload['nonce'] ?? ''),
        ];
    }

    /**
     * Enforce SINGLE-USE (audit H1). Atomically claims this token's nonce in the
     * used-token store so the SAME confirmation cannot be replayed within its TTL
     * to double-execute a write. The PRIMARY KEY on `jti` is the gate: the first
     * caller inserts and proceeds; a concurrent/duplicate caller hits a duplicate
     * key and is refused. Must be called AFTER verify() and BEFORE the write runs.
     *
     * @return bool true ⇒ first use, may proceed; false ⇒ replay, refuse.
     *
     * Defensive posture: with no DB it degrades to the prior stateless behaviour
     * (returns true) so writes aren't blocked before the migration is applied —
     * single-use activates once `agent_used_tokens` exists (a table-missing error
     * degrades the same way). Any OTHER db error fails SAFE (false) so a write is
     * never doubled on ambiguity. A malformed payload is refused.
     */
    public static function consume(?PDO $db, array $payload): bool
    {
        $jti = (string) ($payload['nonce'] ?? '');
        $uid = (int) ($payload['uid'] ?? 0);
        $exp = (int) ($payload['exp'] ?? 0);
        if ($jti === '' || $uid <= 0) {
            return false; // malformed → refuse (safe)
        }
        if (!$db instanceof PDO) {
            return true;  // no store available → prior behaviour (caller logs)
        }
        try {
            // Opportunistic purge of expired rows (bounded — only confirmed writes).
            $db->prepare("DELETE FROM agent_used_tokens WHERE expires_at <= NOW()")->execute();
            $db->prepare(
                "INSERT INTO agent_used_tokens (jti, user_id, expires_at, used_at)
                 VALUES (:jti, :uid, FROM_UNIXTIME(:exp), NOW())"
            )->execute([':jti' => $jti, ':uid' => $uid, ':exp' => $exp]);
            return true;  // first use — claimed
        } catch (\PDOException $e) {
            $state = (string) $e->getCode();
            if ($state === '23000') { return false; }  // duplicate jti → replay, refuse
            if ($state === '42S02') { return true; }    // table absent → single-use not yet active
            if (class_exists('Logger')) {
                Logger::warning('agent.confirm consume error', ['sqlstate' => $state]);
            }
            return false;  // unknown DB error → fail safe (never double-execute a write)
        }
    }

    private static function sign(string $body): string
    {
        return hash_hmac('sha256', $body, self::secret());
    }

    private static function secret(): string
    {
        $s = (string) (getenv('AGENT_CONFIRM_SECRET') ?: getenv('JWT_SECRET') ?: '');
        if ($s === '' && defined('JWT_SECRET')) {
            $s = (string) JWT_SECRET;
        }
        // Derive a dedicated sub-key so agent confirmations never share the raw
        // JWT signing key surface.
        return hash('sha256', $s . '|wtg-agent-confirm');
    }

    private static function b64url(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/'), false);
    }
}
