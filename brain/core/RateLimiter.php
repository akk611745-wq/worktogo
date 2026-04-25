<?php

/**
 * BrainCore v3 — RateLimiter
 *
 * ─── v3 CHANGES ──────────────────────────────────────────────
 *
 *   PERFORMANCE: The DELETE purge of expired rows now runs
 *   PROBABILISTICALLY — only on ~2% of requests.
 *   This reduces DB load by ~98% without affecting correctness.
 *   The window COUNT is still accurate because we count rows
 *   in the current window by window_start >= cutoff, not by
 *   relying on cleanup to have happened.
 *
 *   WHY IT'S SAFE: Expired rows do not affect the count query
 *   because we filter by window_start >= cutoff. The DELETE is
 *   only for table hygiene, not for counting correctness.
 *
 * ─── SLIDING WINDOW ──────────────────────────────────────────
 *
 *   100 requests per 60 seconds per (client_id + endpoint).
 *   Cutoff computed in PHP (avoids the MySQL INTERVAL bind bug).
 *
 * ─── PROBABILISTIC PURGE ─────────────────────────────────────
 *
 *   DELETE runs when rand(0, 49) === 0 → ~2% of requests.
 *   On 100 req/min: DELETE runs ~2 times/min instead of 100.
 *   Table stays lean; DB is not hammered on every request.
 */

class RateLimiter
{
    const LIMIT  = 100;
    const WINDOW = 60; // seconds

    /** DELETE purge probability: 1 in PURGE_ODDS requests */
    const PURGE_ODDS = 50; // ~2%

    /**
     * Check rate limit and record this hit if allowed.
     *
     * @param  string $clientId
     * @param  string $endpoint
     * @return bool   true = allowed, false = blocked
     */
    public static function isAllowed(string $clientId, string $endpoint = '/api/decision'): bool
    {
        $db     = getDB();
        $cutoff = date('Y-m-d H:i:s', time() - self::WINDOW);

        // ── Probabilistic purge — ~2% of requests ─────────────
        // Removes expired rows for table hygiene without hammering
        // the DB on every single request.
        if (rand(0, self::PURGE_ODDS - 1) === 0) {
            $del = $db->prepare("
                DELETE FROM brain_rate_limits
                WHERE  client_id    = :client_id
                  AND  endpoint     = :endpoint
                  AND  window_start < :cutoff
            ");
            $del->execute([
                ':client_id' => $clientId,
                ':endpoint'  => $endpoint,
                ':cutoff'    => $cutoff,
            ]);
        }

        // ── Count current valid hits ───────────────────────────
        $cnt = $db->prepare("
            SELECT COUNT(*) AS total
            FROM   brain_rate_limits
            WHERE  client_id    = :client_id
              AND  endpoint     = :endpoint
              AND  window_start >= :cutoff
        ");
        $cnt->execute([
            ':client_id' => $clientId,
            ':endpoint'  => $endpoint,
            ':cutoff'    => $cutoff,
        ]);

        $total = (int) $cnt->fetch()['total'];

        if ($total >= self::LIMIT) {
            return false;
        }

        // ── Record this hit ────────────────────────────────────
        $ins = $db->prepare("
            INSERT INTO brain_rate_limits
                (client_id, endpoint, ip_address, hit_count, window_start, created_at)
            VALUES
                (:client_id, :endpoint, :ip, 1, NOW(), NOW())
        ");
        $ins->execute([
            ':client_id' => $clientId,
            ':endpoint'  => $endpoint,
            ':ip'        => self::getClientIp(),
        ]);

        return true;
    }

    public static function getMeta(): array
    {
        return [
            'limit'  => self::LIMIT,
            'window' => self::WINDOW . 's',
        ];
    }

    private static function getClientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = trim(explode(',', $_SERVER[$h])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }
}
