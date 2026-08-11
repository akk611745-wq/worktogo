-- ============================================================================
--  WorkToGo V2 — Phase 1: unified `media` table foundation (photos now, video later)
--  Migration: 2026_07_04_003_create_media_table
-- ----------------------------------------------------------------------------
--  PURPOSE (audit-08 foundation implication: "ONE unified media table + CDN")
--    One engine-agnostic table that holds ALL media — vendor profile photos,
--    vendor work photos, category/banner images NOW, and video-engine reels
--    LATER — owned by any entity via the shared users/vendors identity, so no
--    engine needs a media rewrite when video/shopping activate.
--
--    This is SCHEMA FOUNDATION ONLY. No upload handling, no CDN calls, no UI —
--    those come in Phase 2 on top of this table + the thin core/helpers/Media.php.
--
--  KEY DESIGN CHOICES (why this won't need a rewrite)
--    * Polymorphic owner (owner_type + owner_id): any entity — vendor, user,
--      service, product, category, or a future feed_item — can own media without
--      a schema change. owner_id has NO FK (app-enforced), matching the repo's
--      existing polymorphic pattern (transactions.reference_*, wallet_transactions
--      .entity_*, auto_assignments.entity_*). owner_type is varchar (not enum) so
--      new owner kinds never need an ALTER.
--    * Provider-agnostic storage: storage_provider ('local'|'r2'|'s3'|'stream'|
--      'bunny'...) + storage_key (provider-relative key / asset UID) + public_url
--      (servable/CDN URL). Switching from local disk to R2/S3/Cloudflare Stream is
--      data, not code: write a different provider + key, keep the same table and
--      readers. media_type is varchar too (image|video|audio|doc — extensible).
--    * Video-ready now: thumbnail_url + duration_seconds + width/height exist as
--      nullable columns, so the video-engine and the feed slot in with zero ALTER.
--    * Feed-ready now: status (approved-only filter), city_id (city-scoped feed),
--      sort_order (ranking/order), caption + location tag (Instagram-style cards).
--      Indexes below let FeedRankingEngine pull approved media by owner / by
--      city+type cleanly.
--    * Moderation built in: status enum(pending|approved|rejected) defaults to
--      'pending' so nothing goes live until approved (the founder's approval flow);
--      uploaded_by / reviewed_by / reviewed_at / review_notes give an audit trail.
--    * NO is_active flag on purpose — visibility = status='approved' AND
--      deleted_at IS NULL. Avoids the status-vs-is_active drift audit-02 flagged
--      on categories.
--
--  SAFETY / IDEMPOTENCY: CREATE TABLE IF NOT EXISTS (re-run safe; FKs live inside
--    the CREATE so no guard dance is needed). InnoDB / utf8mb4. Do NOT run against
--    prod from here — apply on STAGING first, verify, then prod.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `media` (
  `id`                  bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,

  -- ── Polymorphic owner (no FK on owner_id — app-enforced, engine-agnostic) ──
  `owner_type`          varchar(32)  NOT NULL COMMENT 'vendor|user|service|product|category|feed_item|...',
  `owner_id`            bigint(20) UNSIGNED NOT NULL,

  -- ── What kind of asset + what it is used for ──────────────────────────────
  `media_type`          varchar(16)  NOT NULL DEFAULT 'image' COMMENT 'image|video|audio|doc',
  `purpose`             varchar(32)  NOT NULL DEFAULT 'gallery' COMMENT 'profile_photo|work_photo|banner|video_reel|gallery|...',

  -- ── Provider-agnostic storage (switch providers without code changes) ─────
  `storage_provider`    varchar(24)  NOT NULL DEFAULT 'local' COMMENT 'local|r2|s3|stream|bunny',
  `storage_key`         varchar(512) NOT NULL COMMENT 'provider-relative key/path, or asset UID (e.g. Stream)',
  `public_url`          varchar(1024) DEFAULT NULL COMMENT 'servable/CDN URL; may be derived from provider+key',
  `thumbnail_url`       varchar(1024) DEFAULT NULL COMMENT 'poster/thumb for video + feed cards',

  -- ── Technical metadata (all nullable; feed/video use them) ────────────────
  `mime_type`           varchar(100) DEFAULT NULL,
  `file_size_bytes`     bigint(20) UNSIGNED DEFAULT NULL,
  `width`               int(10) UNSIGNED DEFAULT NULL,
  `height`              int(10) UNSIGNED DEFAULT NULL,
  `duration_seconds`    int(10) UNSIGNED DEFAULT NULL COMMENT 'video/audio length',

  -- ── Presentation + location tag (Instagram-style, V2 vision) ──────────────
  `caption`             varchar(500) DEFAULT NULL,
  `alt_text`            varchar(255) DEFAULT NULL,
  `location_label`      varchar(150) DEFAULT NULL COMMENT 'e.g. "Mukhani, Haldwani" — location-tagged work photos',
  `lat`                 decimal(10,7) DEFAULT NULL,
  `lng`                 decimal(10,7) DEFAULT NULL,
  `city_id`             bigint(20) UNSIGNED DEFAULT NULL COMMENT 'future city-scoped feed',

  -- ── Moderation / approval flow ────────────────────────────────────────────
  `status`              enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `sort_order`          int(11) NOT NULL DEFAULT 0,
  `uploaded_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `reviewed_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `reviewed_at`         datetime DEFAULT NULL,
  `review_notes`        varchar(255) DEFAULT NULL,

  -- ── Extensible metadata + timestamps + soft delete ────────────────────────
  `meta`                longtext DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at`          datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`          datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at`          datetime DEFAULT NULL,

  PRIMARY KEY (`id`),
  KEY `idx_media_owner`         (`owner_type`, `owner_id`),
  KEY `idx_media_owner_status`  (`owner_type`, `owner_id`, `status`),
  KEY `idx_media_owner_purpose` (`owner_type`, `owner_id`, `purpose`),
  KEY `idx_media_feed`          (`status`, `media_type`, `city_id`),
  KEY `idx_media_city`          (`city_id`),
  KEY `idx_media_status`        (`status`),
  KEY `idx_media_uploaded_by`   (`uploaded_by_user_id`),
  CONSTRAINT `fk_media_city`
    FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_media_uploaded_by`
    FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_media_reviewed_by`
    FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  VERIFICATION (run after applying)
-- ----------------------------------------------------------------------------
--  SHOW TABLES LIKE 'media';                          -- expect 1 row
--  SHOW COLUMNS FROM media;                           -- expect all columns above
--  SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
--    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'media'
--      AND CONSTRAINT_TYPE = 'FOREIGN KEY';           -- expect fk_media_city/_uploaded_by/_reviewed_by
--  -- Smoke insert (then delete):
--  -- INSERT INTO media (owner_type, owner_id, media_type, purpose, storage_provider,
--  --   storage_key, public_url, status)
--  --   VALUES ('vendor', 1, 'image', 'work_photo', 'local',
--  --           'vendors/1/work/sample.jpg', '/uploads/vendors/1/work/sample.jpg', 'approved');
-- ============================================================================
