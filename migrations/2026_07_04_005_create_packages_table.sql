-- ============================================================================
--  WorkToGo V2 — Phase 2: packages (admin-controlled, per-category)
--  Migration: 2026_07_04_005_create_packages_table
-- ----------------------------------------------------------------------------
--  PURPOSE (V2 vision — Packages)
--    Fully admin-controlled service packages per category (Standard / Premium /
--    Luxury or any free-text name). Each package can drive a live customer
--    estimate (rate_per_sqft x area) and/or a flat base_price. ZERO hardcoding:
--    admin creates/edits packages; nothing is baked into code.
--
--  RELATIONSHIPS (reuse existing V2 foundation)
--    * category_id  -> categories(id)  (required; ON DELETE CASCADE — a category's
--                       packages go with it)
--    * city_id      -> cities(id)      (nullable: NULL = global/all-city package;
--                       set to scope a package to one city later)
--    * cover_media_id -> media(id)     (nullable: the package cover photo lives in
--                       the unified media table, owner_type='package'; this FK is a
--                       direct pointer so the customer read is a clean single JOIN)
--
--  CUSTOMER-SIDE READ (next task) is designed to be trivial:
--    SELECT p.*, m.public_url AS cover_url
--      FROM packages p
--      LEFT JOIN media m ON m.id = p.cover_media_id
--     WHERE p.category_id = ? AND p.is_active = 1
--       AND (p.city_id IS NULL OR p.city_id = ?)
--     ORDER BY p.sort_order ASC, p.id ASC;
--    Live estimate = base_price + (rate_per_sqft * area_sqft), whichever fields
--    the admin filled (both nullable so a package can be flat-price OR per-sqft).
--
--  SAFETY / IDEMPOTENCY: CREATE TABLE IF NOT EXISTS (FKs inside the CREATE, so
--    re-run safe). InnoDB / utf8mb4. Depends on migrations 001 (cities) + 003
--    (media) having run first — they carry lower dates so `migrate.php` applies
--    them earlier. Do NOT run against prod from here — apply on STAGING first.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `packages` (
  `id`             bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id`    bigint(20) UNSIGNED NOT NULL,
  `city_id`        bigint(20) UNSIGNED DEFAULT NULL COMMENT 'NULL = global; set to scope to one city',
  `name`           varchar(100) NOT NULL COMMENT 'free text — Standard/Premium/Luxury or anything',
  `description`    varchar(500) DEFAULT NULL,
  `features`       longtext DEFAULT NULL COMMENT 'JSON array of feature strings' CHECK (json_valid(`features`)),
  `rate_per_sqft`  decimal(10,2) DEFAULT NULL COMMENT 'for live sqft estimate (nullable)',
  `base_price`     decimal(12,2) DEFAULT NULL COMMENT 'optional flat price for non-sqft packages',
  `min_sqft`       int(10) UNSIGNED DEFAULT NULL COMMENT 'optional minimum billable area for the estimate',
  `inspection_fee` decimal(10,2) DEFAULT NULL COMMENT 'optional per-package expert-visit fee',
  `cover_media_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'FK media.id — cover photo',
  `is_active`      tinyint(1) NOT NULL DEFAULT 1,
  `sort_order`     int(11) NOT NULL DEFAULT 0,
  `created_at`     datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`     datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_packages_category` (`category_id`, `is_active`, `sort_order`),
  KEY `idx_packages_city` (`city_id`),
  KEY `idx_packages_cover` (`cover_media_id`),
  CONSTRAINT `fk_packages_category`
    FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_packages_city`
    FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_packages_cover_media`
    FOREIGN KEY (`cover_media_id`) REFERENCES `media` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  VERIFICATION (run after applying)
-- ----------------------------------------------------------------------------
--  SHOW TABLES LIKE 'packages';                       -- expect 1 row
--  SHOW COLUMNS FROM packages;                        -- expect all columns above
--  SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
--    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'packages'
--      AND CONSTRAINT_TYPE = 'FOREIGN KEY';           -- expect fk_packages_category/_city/_cover_media
--  -- Smoke insert (replace 1 with a real category id), then clean up:
--  -- INSERT INTO packages (category_id, name, description, features, rate_per_sqft, base_price, is_active, sort_order)
--  --   VALUES (1, 'Standard', 'Basic package', JSON_ARRAY('1 coat','labour included'), 12.50, NULL, 1, 1);
-- ============================================================================
