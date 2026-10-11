-- ============================================================================
--  WorkToGo V2 — Phase 2: vendor profile structured fields + service areas
--  Migration: 2026_07_04_009_vendor_profile_fields
-- ----------------------------------------------------------------------------
--  PURPOSE (V2 vision — rich, trusted, vendor-controlled profile at scale)
--    Make the vendor profile real and STRUCTURED so the system can use it for
--    matching + intelligence, and so it stays flexible for future engines.
--
--    * bio/about       -> reuse the existing vendors.description (no new column;
--                         avoids drift). Already text.
--    * experience_years-> already exists (vendors.experience_years).
--    * multi-category  -> already canonical in vendor_categories (Phase 1). The
--                         profile now writes ALL selected categories there.
--    * languages       -> NEW vendors.languages (JSON array of language codes,
--                         e.g. ["hi","en"]). Small bounded vocabulary, primarily
--                         display + soft matching for the mixed Hindi/English
--                         audience — a JSON array is structured/queryable without
--                         over-normalising into a table. i18n-ready: option
--                         LABELS live in code as {code,label}, so a locale layer
--                         can be added later with no schema change.
--    * service areas   -> NEW vendor_service_areas table (see below).
--
--  WHY vendor_service_areas IS A TABLE, NOT A JSON BLOB (scale justification)
--    A vendor covers MANY areas (1:many). The matching engine must answer "which
--    active vendors cover this booking's area in this city" across millions of
--    rows — that needs indexed JOINs, not a per-row JSON scan. So areas live in a
--    normalized table, each row city-scoped (city_id) to prevent cross-city false
--    matches (audit-07), indexed on (vendor_id) for profile reads and
--    (city_id, area_name) for reverse matching. The legacy vendors.service_localities
--    text (a JSON-array mirror) is KEPT and dual-written so the current text
--    matching + customer cards keep working; the table is the canonical V2 home
--    and matching can migrate to it later with no rewrite.
--
--  FUTURE ENGINES (no schema conflict): these profile fields describe the VENDOR
--    (engine-agnostic), and the shared `vendors` identity already lets future
--    engines attach by vendor_id (media via owner_type='vendor'; products/videos
--    FK vendor_id). Nothing here assumes "service only".
--
--  SAFETY / IDEMPOTENCY: ADD COLUMN IF NOT EXISTS + CREATE TABLE IF NOT EXISTS
--    (FKs inside CREATE). Backward-compatible — existing vendors unaffected.
--    Depends on migration 001 (cities). Do NOT run against prod from here.
-- ============================================================================

-- ── 1. Languages the vendor speaks (JSON array; e.g. ["hi","en"]) ───────────
ALTER TABLE `vendors`
  ADD COLUMN IF NOT EXISTS `languages` longtext NULL DEFAULT NULL
    COMMENT 'JSON array of language codes the vendor speaks'
    CHECK (json_valid(`languages`));

-- ── 2. Structured, city-scoped service areas (1:many, indexed for matching) ──
CREATE TABLE IF NOT EXISTS `vendor_service_areas` (
  `id`         bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `vendor_id`  bigint(20) UNSIGNED NOT NULL,
  `city_id`    bigint(20) UNSIGNED DEFAULT NULL COMMENT 'city this area belongs to (audit-07 scoping)',
  `area_name`  varchar(120) NOT NULL,
  `is_active`  tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_vsa_vendor`    (`vendor_id`, `is_active`),
  KEY `idx_vsa_city_area` (`city_id`, `area_name`),
  CONSTRAINT `fk_vsa_vendor`
    FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_vsa_city`
    FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  VERIFICATION (run after applying)
-- ----------------------------------------------------------------------------
--  SHOW COLUMNS FROM vendors LIKE 'languages';          -- 1 row, Null=YES
--  SHOW TABLES LIKE 'vendor_service_areas';             -- 1 row
--  SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
--    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_service_areas'
--      AND CONSTRAINT_TYPE = 'FOREIGN KEY';             -- fk_vsa_vendor / fk_vsa_city
--  -- Existing vendors unaffected (languages NULL, no area rows yet):
--  SELECT COUNT(*) FROM vendors WHERE languages IS NOT NULL;         -- 0
--  SELECT COUNT(*) FROM vendor_service_areas;                        -- 0
--  -- Reverse-match example the structure enables:
--  -- SELECT DISTINCT vendor_id FROM vendor_service_areas
--  --   WHERE is_active=1 AND city_id=? AND area_name LIKE ?;
-- ============================================================================
