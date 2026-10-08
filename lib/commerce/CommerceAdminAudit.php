<?php
// ─────────────────────────────────────────────────────────────────────────────
//  WorkToGo Commerce — CommerceAdminAudit  (S11)
//  commerce-system-blueprint §R. ONE uniform trail for every privileged commerce
//  action taken through the ops/admin HTTP layer: who (user + seat role), which
//  permission authorised it, what action, on what target, and the outcome. This
//  sits ALONGSIDE the specific trails the backends already keep (S8
//  commerce_config_audit for config, S4 commerce_events for order timeline) — it
//  is the single cross-cutting "who did what" log a founder-free org needs.
//  Pure-write, fully defensive: an audit hiccup must NEVER fail the action.
// ─────────────────────────────────────────────────────────────────────────────

class CommerceAdminAudit
{
    /**
     * Record one privileged action. $auth is the resolved JWT payload (user_id +
     * role). $opts: method, route, target_type, target_id, summary(array|string),
     * outcome ('ok'|'denied'|'error'). Returns the row id (0 on failure — swallowed).
     */
    public static function log(PDO $db, ?array $auth, ?string $permKey, string $action, array $opts = []): int
    {
        try {
            $summary = $opts['summary'] ?? null;
            if (is_array($summary)) $summary = json_encode(self::scrub($summary));
            $stmt = $db->prepare(
                "INSERT INTO commerce_admin_audit
                   (actor_user_id, actor_role, perm_key, action, http_method, route, target_type, target_id, request_summary, outcome, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?, NOW())"
            );
            $stmt->execute([
                isset($auth['user_id']) ? (int)$auth['user_id'] : null,
                isset($auth['role']) ? (string)$auth['role'] : null,
                $permKey,
                mb_substr($action, 0, 80),
                isset($opts['method']) ? mb_substr((string)$opts['method'], 0, 8) : null,
                isset($opts['route']) ? mb_substr((string)$opts['route'], 0, 191) : null,
                isset($opts['target_type']) ? mb_substr((string)$opts['target_type'], 0, 40) : null,
                isset($opts['target_id']) ? mb_substr((string)$opts['target_id'], 0, 64) : null,
                $summary !== null ? mb_substr((string)$summary, 0, 4000) : null,
                mb_substr((string)($opts['outcome'] ?? 'ok'), 0, 16),
            ]);
            return (int)$db->lastInsertId();
        } catch (\Throwable $e) {
            error_log('[CommerceAdminAudit] ' . $e->getMessage());
            return 0;
        }
    }

    /** Never persist secrets/PII in the request summary. */
    private static function scrub(array $data): array
    {
        $deny = ['password', 'token', 'secret', 'card', 'cvv', 'otp', 'authorization'];
        $out = [];
        foreach ($data as $k => $v) {
            $lk = strtolower((string)$k);
            $hit = false;
            foreach ($deny as $d) { if (str_contains($lk, $d)) { $hit = true; break; } }
            $out[$k] = $hit ? '[redacted]' : (is_array($v) ? self::scrub($v) : $v);
        }
        return $out;
    }

    /** Recent audit rows (for the admin view). */
    public static function recent(PDO $db, int $limit = 100): array
    {
        try {
            $limit = max(1, min(500, $limit));
            return $db->query("SELECT id, actor_user_id, actor_role, perm_key, action, http_method, route, target_type, target_id, outcome, created_at
                                 FROM commerce_admin_audit ORDER BY id DESC LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) { return []; }
    }
}
