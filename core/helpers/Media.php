<?php
/**
 * Media — WorkToGo V2 unified media model (DB layer only).
 *
 * Thin create/read/status helper over the engine-agnostic `media` table
 * (migrations/2026_07_04_003_create_media_table.sql). It deliberately does
 * NOT handle uploads, files, or CDN calls — Phase 2 builds the vendor-profile
 * upload + provider integration ON TOP of this. Here we only own the rows:
 * add media for a polymorphic owner, list approved media, and move it through
 * the pending -> approved/rejected moderation flow.
 *
 * Owner is polymorphic (owner_type + owner_id) so any entity — 'vendor',
 * 'user', 'service', 'product', 'category', or a future 'feed_item' — can own
 * media with no schema change. Storage is provider-agnostic (storage_provider
 * + storage_key + public_url), so switching local disk -> R2/S3/Stream later is
 * data, not code.
 *
 * Every method is defensive: if the `media` table does not exist yet (migration
 * not applied on an environment), reads return [] and writes return 0/false
 * instead of throwing.
 */
class Media
{
    /** Moderation states (must match the `media.status` enum). */
    const STATUS_PENDING  = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    /** Columns a caller may set on add(); everything else is DB/flow-managed. */
    private const INSERTABLE = [
        'owner_type', 'owner_id', 'media_type', 'purpose',
        'storage_provider', 'storage_key', 'public_url', 'thumbnail_url',
        'mime_type', 'file_size_bytes', 'width', 'height', 'duration_seconds',
        'caption', 'alt_text', 'location_label', 'lat', 'lng', 'city_id',
        'status', 'sort_order', 'uploaded_by_user_id', 'meta',
    ];

    /** @var bool|null cached table-existence flag for this request */
    private static $tableExists = null;

    /**
     * Insert a media row for an owner and return its id (0 on failure / no table).
     * Required: owner_type, owner_id, storage_key. Sensible defaults are applied
     * for media_type ('image'), purpose ('gallery'), storage_provider ('local'),
     * and status ('pending' — nothing is live until approved).
     *
     * @param array $data keys from self::INSERTABLE (plus 'meta' as array|string)
     */
    public static function add(PDO $db, array $data): int
    {
        if (!self::hasTable($db)) {
            return 0;
        }

        $ownerType  = trim((string) ($data['owner_type'] ?? ''));
        $ownerId    = (int) ($data['owner_id'] ?? 0);
        $storageKey = trim((string) ($data['storage_key'] ?? ''));
        if ($ownerType === '' || $ownerId <= 0 || $storageKey === '') {
            return 0;
        }

        // Defaults
        $data['media_type']       = $data['media_type']       ?? 'image';
        $data['purpose']          = $data['purpose']          ?? 'gallery';
        $data['storage_provider'] = $data['storage_provider'] ?? 'local';
        $data['status']           = in_array(($data['status'] ?? ''), [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED], true)
            ? $data['status'] : self::STATUS_PENDING;

        // meta may be passed as an array — store JSON.
        if (isset($data['meta']) && is_array($data['meta'])) {
            $data['meta'] = json_encode($data['meta'], JSON_UNESCAPED_SLASHES);
        }

        $cols = [];
        $binds = [];
        foreach (self::INSERTABLE as $col) {
            if (array_key_exists($col, $data)) {
                $cols[] = $col;
                $binds[':' . $col] = $data[$col];
            }
        }
        if (empty($cols)) {
            return 0;
        }

        try {
            $sql = 'INSERT INTO `media` (`' . implode('`, `', $cols) . '`) VALUES ('
                 . implode(', ', array_map(fn($c) => ':' . $c, $cols)) . ')';
            $db->prepare($sql)->execute($binds);
            return (int) $db->lastInsertId();
        } catch (\Throwable $e) {
            error_log('[Media::add] ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Approved, non-deleted media for an owner, ordered for display/feed.
     * Optionally filter by media_type ('image' | 'video' | ...).
     */
    public static function listApprovedForOwner(PDO $db, string $ownerType, int $ownerId, ?string $mediaType = null): array
    {
        if (!self::hasTable($db)) {
            return [];
        }
        $sql = "SELECT * FROM `media`
                 WHERE owner_type = :ot AND owner_id = :oid
                   AND status = :status AND deleted_at IS NULL";
        $bind = [':ot' => $ownerType, ':oid' => $ownerId, ':status' => self::STATUS_APPROVED];
        if ($mediaType !== null && $mediaType !== '') {
            $sql .= " AND media_type = :mt";
            $bind[':mt'] = $mediaType;
        }
        $sql .= " ORDER BY sort_order ASC, created_at DESC";
        return self::fetchAll($db, $sql, $bind);
    }

    /**
     * All non-deleted media for an owner (the owner's own management view).
     * Optionally filter by status.
     */
    public static function listForOwner(PDO $db, string $ownerType, int $ownerId, ?string $status = null): array
    {
        if (!self::hasTable($db)) {
            return [];
        }
        $sql = "SELECT * FROM `media`
                 WHERE owner_type = :ot AND owner_id = :oid AND deleted_at IS NULL";
        $bind = [':ot' => $ownerType, ':oid' => $ownerId];
        if ($status !== null && $status !== '') {
            $sql .= " AND status = :status";
            $bind[':status'] = $status;
        }
        $sql .= " ORDER BY sort_order ASC, created_at DESC";
        return self::fetchAll($db, $sql, $bind);
    }

    /** Fetch a single media row (or null). */
    public static function get(PDO $db, int $id): ?array
    {
        if (!self::hasTable($db)) {
            return null;
        }
        try {
            $stmt = $db->prepare("SELECT * FROM `media` WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            error_log('[Media::get] ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Move a media row through the moderation flow.
     * Records reviewer + timestamp when a reviewer id is supplied.
     */
    public static function setStatus(PDO $db, int $id, string $status, ?int $reviewedByUserId = null, ?string $notes = null): bool
    {
        if (!self::hasTable($db)) {
            return false;
        }
        if (!in_array($status, [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
            return false;
        }
        try {
            $stmt = $db->prepare(
                "UPDATE `media`
                    SET status = :status,
                        reviewed_by_user_id = :rby,
                        reviewed_at = CASE WHEN :rby2 IS NULL THEN reviewed_at ELSE NOW() END,
                        review_notes = :notes,
                        updated_at = NOW()
                  WHERE id = :id AND deleted_at IS NULL"
            );
            $stmt->execute([
                ':status' => $status,
                ':rby'    => $reviewedByUserId,
                ':rby2'   => $reviewedByUserId,
                ':notes'  => $notes,
                ':id'     => $id,
            ]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            error_log('[Media::setStatus] ' . $e->getMessage());
            return false;
        }
    }

    /** Convenience: approve. */
    public static function approve(PDO $db, int $id, ?int $reviewedByUserId = null, ?string $notes = null): bool
    {
        return self::setStatus($db, $id, self::STATUS_APPROVED, $reviewedByUserId, $notes);
    }

    /** Convenience: reject. */
    public static function reject(PDO $db, int $id, ?int $reviewedByUserId = null, ?string $notes = null): bool
    {
        return self::setStatus($db, $id, self::STATUS_REJECTED, $reviewedByUserId, $notes);
    }

    /** Soft-delete a media row (keeps the asset record for audit). */
    public static function softDelete(PDO $db, int $id): bool
    {
        if (!self::hasTable($db)) {
            return false;
        }
        try {
            $stmt = $db->prepare("UPDATE `media` SET deleted_at = NOW(), updated_at = NOW() WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$id]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            error_log('[Media::softDelete] ' . $e->getMessage());
            return false;
        }
    }

    /** Admin moderation queue: pending, non-deleted, oldest first. */
    public static function pendingQueue(PDO $db, ?string $ownerType = null, int $limit = 100): array
    {
        if (!self::hasTable($db)) {
            return [];
        }
        $sql = "SELECT * FROM `media` WHERE status = :status AND deleted_at IS NULL";
        $bind = [':status' => self::STATUS_PENDING];
        if ($ownerType !== null && $ownerType !== '') {
            $sql .= " AND owner_type = :ot";
            $bind[':ot'] = $ownerType;
        }
        $sql .= " ORDER BY created_at ASC LIMIT " . max(1, min(500, $limit));
        return self::fetchAll($db, $sql, $bind);
    }

    /** Does the `media` table exist yet? Cached per request. */
    public static function hasTable(PDO $db): bool
    {
        if (self::$tableExists !== null) {
            return self::$tableExists;
        }
        try {
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'media'"
            );
            $stmt->execute();
            return self::$tableExists = ((int) $stmt->fetchColumn() > 0);
        } catch (\Throwable $e) {
            return self::$tableExists = false;
        }
    }

    // ── internal ─────────────────────────────────────────────────────────────
    private static function fetchAll(PDO $db, string $sql, array $bind): array
    {
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($bind);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[Media::fetchAll] ' . $e->getMessage());
            return [];
        }
    }
}
