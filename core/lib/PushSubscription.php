<?php
// ============================================================
//  WorkToGo CORE — FCM Push Subscription Storage
//  Saves/retrieves browser FCM tokens per user_id.
// ============================================================

class PushSubscription
{
    // ── Save (or refresh) a token for a user ──────────────────
    // A token can only ever belong to one user at a time — if the
    // browser's token was previously tied to a different account
    // (e.g. shared device, account switch), re-point it.
    public static function save(PDO $db, int $userId, string $token, string $deviceType = 'web'): void
    {
        $token = trim($token);
        if ($userId <= 0 || $token === '') {
            return;
        }

        $stmt = $db->prepare(
            "INSERT INTO fcm_tokens (user_id, token, device_type)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id),
                                     device_type = VALUES(device_type),
                                     updated_at = NOW()"
        );
        $stmt->execute([$userId, $token, $deviceType]);
    }

    // ── Get all tokens for a user ───────────────────────────────
    public static function tokensForUser(PDO $db, int $userId): array
    {
        $stmt = $db->prepare("SELECT token FROM fcm_tokens WHERE user_id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // ── Get all tokens for every user with a given role ─────────
    // Used to notify "the admin" without hardcoding a user_id.
    public static function tokensForRole(PDO $db, string $role): array
    {
        $stmt = $db->prepare(
            "SELECT t.token
             FROM fcm_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE u.role = ? AND u.deleted_at IS NULL"
        );
        $stmt->execute([$role]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // ── Remove a token (e.g. FCM reports it as invalid) ─────────
    public static function remove(PDO $db, string $token): void
    {
        $db->prepare("DELETE FROM fcm_tokens WHERE token = ?")->execute([$token]);
    }
}
