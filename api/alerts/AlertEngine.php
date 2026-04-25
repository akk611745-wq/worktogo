<?php
/**
 * AlertEngine — Core Alert Business Logic
 * -------------------------------------------------------
 * Responsibilities:
 *   1. Create alerts with deduplication
 *   2. Fetch alerts (delta-based, no full reloads)
 *   3. Mark alerts as seen (single or batch)
 *   4. Provide unseen count
 *   5. Cleanup expired alerts (call from a cron job)
 *
 * Dependencies:
 *   - PDO instance ($pdo) from your existing DB layer
 *   - config.php constants
 *
 * Usage:
 *   require_once 'config.php';
 *   $engine = new AlertEngine($pdo);
 * -------------------------------------------------------
 */

require_once __DIR__ . '/config.php';

class AlertEngine
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ══════════════════════════════════════════════════════
    //  CREATE
    // ══════════════════════════════════════════════════════

    /**
     * Create one alert.
     *
     * @param  array $data {
     *   user_id|vendor_id : int   (one required)
     *   type              : string (see ALERT_TYPE_META keys)
     *   title             : string
     *   message           : string
     *   ref_type          : string  default 'none'
     *   ref_id            : int     default null
     * }
     * @return int|false  alert_id on success, false if deduped/invalid
     */
    public function createAlert(array $data): int|false
    {
        // ── Validate recipient ──────────────────────────────
        $userId   = isset($data['user_id'])   ? (int) $data['user_id']   : null;
        $vendorId = isset($data['vendor_id']) ? (int) $data['vendor_id'] : null;

        if (!$userId && !$vendorId) {
            return false;
        }

        // ── Validate type ───────────────────────────────────
        $type = $data['type'] ?? '';
        if (!array_key_exists($type, ALERT_TYPE_META)) {
            return false;
        }

        $title    = mb_substr(trim($data['title']   ?? ''), 0, 120);
        $message  = mb_substr(trim($data['message'] ?? ''), 0, 500);
        $refType  = $data['ref_type'] ?? 'none';
        $refId    = isset($data['ref_id']) ? (int) $data['ref_id'] : null;

        if ($title === '' || $message === '') {
            return false;
        }

        // ── Deduplication guard ─────────────────────────────
        if ($this->isDuplicate($userId, $vendorId, $type, $refType, $refId)) {
            return false;
        }

        // ── Insert ──────────────────────────────────────────
        $sql = "INSERT INTO " . ALERT_TABLE . "
                    (user_id, vendor_id, type, title, message, ref_type, ref_id)
                VALUES
                    (:user_id, :vendor_id, :type, :title, :message, :ref_type, :ref_id)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id'   => $userId,
            ':vendor_id' => $vendorId,
            ':type'      => $type,
            ':title'     => $title,
            ':message'   => $message,
            ':ref_type'  => $refType,
            ':ref_id'    => $refId,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Convenience: create alerts for multiple recipients at once.
     * Returns array of created alert_ids (false entries are deduped).
     */
    public function broadcastAlert(array $recipientIds, string $role, array $data): array
    {
        $ids = [];
        $key = ($role === 'vendor') ? 'vendor_id' : 'user_id';

        foreach ($recipientIds as $rid) {
            $ids[] = $this->createAlert(array_merge($data, [$key => $rid]));
        }

        return array_filter($ids);
    }

    // ══════════════════════════════════════════════════════
    //  FETCH (delta-based)
    // ══════════════════════════════════════════════════════

    /**
     * Fetch alerts for a recipient since a given timestamp.
     * This is the core of the polling loop — returns ONLY new data.
     *
     * @param  int         $recipientId
     * @param  string      $role        'user' | 'vendor'
     * @param  string|null $lastTs      ISO datetime from previous poll (null = cold start)
     * @param  bool        $unreadOnly  If true, skip already-seen alerts
     * @return array {
     *   alerts      : array of alert rows
     *   unseen_count: int
     *   server_ts   : string  current server datetime (use as next lastTs)
     *   has_new     : bool
     * }
     */
    public function fetchAlerts(
        int $recipientId,
        string $role,
        ?string $lastTs = null,
        bool $unreadOnly = false
    ): array {
        $col = ($role === 'vendor') ? 'vendor_id' : 'user_id';

        // Cold start: look back ALERT_DELTA_COLD_START seconds
        if ($lastTs === null) {
            $lastTs = date('Y-m-d H:i:s', time() - ALERT_DELTA_COLD_START);
        }

        $seenClause = $unreadOnly ? 'AND seen_at IS NULL' : '';

        $sql = "SELECT
                    alert_id,
                    type,
                    title,
                    message,
                    ref_type,
                    ref_id,
                    seen_at,
                    created_at
                FROM " . ALERT_TABLE . "
                WHERE {$col}   = :rid
                  AND created_at > :last_ts
                  {$seenClause}
                ORDER BY created_at DESC
                LIMIT " . ALERT_PAGE_SIZE;

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':rid' => $recipientId, ':last_ts' => $lastTs]);
        $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Enrich each alert with UI meta
        foreach ($alerts as &$alert) {
            $meta = ALERT_TYPE_META[$alert['type']] ?? [];
            $alert['icon']       = $meta['icon']  ?? 'icon-info';
            $alert['play_sound'] = $meta['sound']  ?? false;
            $alert['badge']      = $meta['badge']  ?? false;
        }
        unset($alert);

        return [
            'alerts'       => $alerts,
            'unseen_count' => $this->getUnseenCount($recipientId, $col),
            'server_ts'    => date('Y-m-d H:i:s'),
            'has_new'      => count($alerts) > 0,
        ];
    }

    // ══════════════════════════════════════════════════════
    //  MARK SEEN
    // ══════════════════════════════════════════════════════

    /**
     * Mark one or many alerts as seen.
     * Only updates alerts that actually belong to the recipient
     * (prevents cross-user tampering).
     *
     * @param  int    $recipientId
     * @param  string $role        'user' | 'vendor'
     * @param  array  $alertIds    e.g. [12, 13, 14]  (empty = mark all unseen)
     * @return int    rows updated
     */
    public function markSeen(int $recipientId, string $role, array $alertIds = []): int
    {
        $col = ($role === 'vendor') ? 'vendor_id' : 'user_id';
        $now = date('Y-m-d H:i:s');

        if (empty($alertIds)) {
            // Mark all unseen for this recipient
            $sql  = "UPDATE " . ALERT_TABLE . "
                     SET seen_at = :now
                     WHERE {$col} = :rid AND seen_at IS NULL";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':now' => $now, ':rid' => $recipientId]);
        } else {
            // Mark only specified IDs (validate ownership)
            $placeholders = implode(',', array_fill(0, count($alertIds), '?'));
            $sql  = "UPDATE " . ALERT_TABLE . "
                     SET seen_at = ?
                     WHERE {$col} = ?
                       AND alert_id IN ({$placeholders})
                       AND seen_at IS NULL";
            $params = array_merge([$now, $recipientId], array_map('intval', $alertIds));
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
        }

        return $stmt->rowCount();
    }

    // ══════════════════════════════════════════════════════
    //  COUNTS
    // ══════════════════════════════════════════════════════

    public function getUnseenCount(int $recipientId, string $col): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM " . ALERT_TABLE . "
             WHERE {$col} = :rid AND seen_at IS NULL"
        );
        $stmt->execute([':rid' => $recipientId]);
        return (int) $stmt->fetchColumn();
    }

    // ══════════════════════════════════════════════════════
    //  DEDUPLICATION
    // ══════════════════════════════════════════════════════

    private function isDuplicate(
        ?int $userId,
        ?int $vendorId,
        string $type,
        string $refType,
        ?int $refId
    ): bool {
        $col = $userId ? 'user_id' : 'vendor_id';
        $rid = $userId ?? $vendorId;
        $window = date('Y-m-d H:i:s', time() - ALERT_DEDUP_WINDOW);

        $refClause = ($refId !== null)
            ? "AND ref_type = :ref_type AND ref_id = :ref_id"
            : "AND ref_type = :ref_type AND ref_id IS NULL";

        $sql = "SELECT COUNT(*) FROM " . ALERT_TABLE . "
                WHERE {$col}      = :rid
                  AND type        = :type
                  AND created_at >= :window
                  {$refClause}";

        $params = [':rid' => $rid, ':type' => $type, ':window' => $window, ':ref_type' => $refType];
        if ($refId !== null) {
            $params[':ref_id'] = $refId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() >= ALERT_DEDUP_MAX;
    }

    // ══════════════════════════════════════════════════════
    //  CLEANUP (run via cron: 0 3 * * * php cleanup.php)
    // ══════════════════════════════════════════════════════

    /**
     * Delete seen alerts older than ALERT_EXPIRE_DAYS.
     * Unseen alerts are never auto-deleted.
     */
    public function cleanup(): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . ALERT_EXPIRE_DAYS . ' days'));
        $stmt = $this->db->prepare(
            "DELETE FROM " . ALERT_TABLE . "
             WHERE seen_at IS NOT NULL AND created_at < :cutoff
             LIMIT 1000"   // Batched to avoid locking
        );
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }
}
