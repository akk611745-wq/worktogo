-- WorkToGo — Vendor/Category Junction + bookings.category_id
-- Phase 0.2: Decouples vendor identity from service rows so one vendor
-- can cover multiple categories without creating duplicate vendor cards.
--
-- Safe: no DROP, no NOT NULL additions to existing rows, no schema removes.
-- Run via phpMyAdmin (manual) — migrate.php is blocked on live.
--
-- Verified against live DB (2026-06-15):
--   vendor_categories table: does NOT exist
--   bookings.category_id column: does NOT exist
--   services rows for vendor 11 (Aakash): 5 rows, category_id 1–5
--
-- Run order: CREATE → INSERT IGNORE → ALTER → UPDATE (backfill)

-- ── 1. Junction table ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `vendor_categories` (
  `vendor_id`   bigint(20) UNSIGNED NOT NULL,
  `category_id` bigint(20) UNSIGNED NOT NULL,
  PRIMARY KEY (`vendor_id`, `category_id`),
  KEY `idx_vc_vendor`   (`vendor_id`),
  KEY `idx_vc_category` (`category_id`),
  CONSTRAINT `vc_ibfk_1` FOREIGN KEY (`vendor_id`)
    REFERENCES `vendors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vc_ibfk_2` FOREIGN KEY (`category_id`)
    REFERENCES `categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Populate from existing services rows ───────────────────────────────
--    Vendor 11 has 5 active services (category_id 1–5) → 5 junction rows.
--    INSERT IGNORE is idempotent — safe to re-run.
INSERT IGNORE INTO `vendor_categories` (`vendor_id`, `category_id`)
SELECT DISTINCT `vendor_id`, `category_id`
FROM `services`
WHERE `category_id` IS NOT NULL;

-- ── 3. Add category_id to bookings (nullable FK, default NULL) ────────────
--    Placed after service_id. NULL = legacy booking created before this migration.
--    ON DELETE SET NULL keeps bookings intact if a category is removed.
ALTER TABLE `bookings`
  ADD COLUMN `category_id` bigint(20) UNSIGNED NULL DEFAULT NULL
    AFTER `service_id`,
  ADD KEY `idx_bookings_category` (`category_id`),
  ADD CONSTRAINT `bookings_ibfk_cat`
    FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
    ON DELETE SET NULL;

-- ── 4. Backfill bookings.category_id from the linked service row ──────────
--    Only sets rows where category_id is still NULL and service has one.
UPDATE `bookings` b
JOIN   `services` s ON s.id = b.service_id
SET    b.category_id = s.category_id
WHERE  b.category_id IS NULL
  AND  s.category_id IS NOT NULL;

-- ── Verification queries (run after migration, expect noted results) ───────
-- SELECT COUNT(*) FROM vendor_categories;                     -- expect 5
-- SELECT COUNT(*) FROM bookings WHERE category_id IS NULL;   -- expect 0 (or legacy only)
-- SHOW COLUMNS FROM bookings LIKE 'category_id';             -- expect 1 row, Null=YES
