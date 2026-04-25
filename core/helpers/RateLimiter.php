<?php
/**
 * WorkToGo Core — Redis-less Database Rate Limiter
 * Provides robust rate limiting to prevent brute force and spam.
 */

class RateLimiter
{
    /**
     * Check if request is allowed based on IP and Action.
     * Returns true if allowed, false if limited.
     */
    public static function check(string $action, int $maxAttempts, int $decaySeconds): bool
    {
        $db = getDB();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $key = hash('sha256', $action . '|' . $ip);

        // Cleanup expired
        $db->prepare("DELETE FROM rate_limits WHERE expires_at <= NOW()")->execute();

        // Increment or Insert
        $stmt = $db->prepare("
            INSERT INTO rate_limits (`key`, action, ip_address, attempts, expires_at)
            VALUES (?, ?, ?, 1, DATE_ADD(NOW(), INTERVAL ? SECOND))
            ON DUPLICATE KEY UPDATE 
                attempts = attempts + 1
        ");
        $stmt->execute([$key, $action, $ip, $decaySeconds]);

        // Check current attempts
        $stmt = $db->prepare("SELECT attempts FROM rate_limits WHERE `key` = ?");
        $stmt->execute([$key]);
        $attempts = (int)$stmt->fetchColumn();

        if ($attempts > $maxAttempts) {
            // Log attack/spam
            if (class_exists('Logger')) {
                Logger::warning('Rate limit exceeded', ['action' => $action, 'ip' => $ip, 'attempts' => $attempts]);
            }
            return false;
        }

        return true;
    }

    /**
     * Clear the limit upon success (e.g., successful login)
     */
    public static function clear(string $action): void
    {
        $db = getDB();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $key = hash('sha256', $action . '|' . $ip);
        
        $db->prepare("DELETE FROM rate_limits WHERE `key` = ?")->execute([$key]);
    }
}
