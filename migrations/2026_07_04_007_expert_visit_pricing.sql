-- ============================================================================
--  WorkToGo V2 — Phase 2: Expert Visit pricing config (admin-controlled)
--  Migration: 2026_07_04_007_expert_visit_pricing
-- ----------------------------------------------------------------------------
--  PURPOSE (V2 vision — Expert Visit, zero developer dependency)
--    Admin controls the expert-visit fee entirely from the panel. ONE global
--    default applies everywhere automatically; admin may OVERRIDE it per-category
--    and/or per-city, and mark any scope FREE. All structured + queryable so it
--    scales to many cities/categories.
--
--  STORAGE
--    * Global default -> app_settings keys (reuses the existing settings
--      mechanism, like inspection_price):
--        expert_visit_fee  (number)  — the default amount
--        expert_visit_free (boolean) — global FREE flag
--    * Overrides -> expert_visit_pricing table (category_id / city_id nullable):
--        a row can scope by category, by city, or by both.
--
--  PRECEDENCE (most-specific active override wins):
--        category + city   >   category   >   city   >   global default
--    A row is valid only if is_free = 1 OR amount IS NOT NULL. The resolver
--    (core/helpers/ExpertVisitPricing.php) implements this exact order.
--
--  DOES NOT touch the existing inspection (₹299 / inspection_price) flow — this
--    is NEW config layered above it, consumed by the customer pay-flow next task.
--
--  SAFETY / IDEMPOTENCY: CREATE TABLE IF NOT EXISTS (FKs inside CREATE) + seed
--    via INSERT ... ON DUPLICATE KEY UPDATE (never overwrites an admin-set value).
--    Depends on migration 001 (cities) + categories. Do NOT run against prod from
--    here — apply on STAGING first, verify, then prod.
-- ============================================================================

-- ── 1. Overrides table (category_id / city_id nullable = scope) ─────────────
CREATE TABLE IF NOT EXISTS `expert_visit_pricing` (
  `id`          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'NULL = all categories',
  `city_id`     bigint(20) UNSIGNED DEFAULT NULL COMMENT 'NULL = all cities',
  `amount`      decimal(10,2) DEFAULT NULL COMMENT 'fee for this scope (ignored when is_free=1)',
  `is_free`     tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = expert visit is free for this scope',
  `is_active`   tinyint(1) NOT NULL DEFAULT 1,
  `created_at`  datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`  datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_evp_scope`    (`category_id`, `city_id`, `is_active`),
  KEY `idx_evp_city`     (`city_id`),
  CONSTRAINT `fk_evp_category`
    FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_evp_city`
    FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Seed the global default (does not overwrite an admin-set value) ───────
INSERT INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`, `updated_at`)
VALUES ('expert_visit_fee', '299', 'number', 'Expert Visit Fee (₹) — global default', 1, NOW())
ON DUPLICATE KEY UPDATE `updated_at` = `updated_at`;

INSERT INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`, `updated_at`)
VALUES ('expert_visit_free', '0', 'boolean', 'Expert Visit Free (global) — 1 = free everywhere', 1, NOW())
ON DUPLICATE KEY UPDATE `updated_at` = `updated_at`;

-- ============================================================================
--  VERIFICATION (run after applying)
-- ----------------------------------------------------------------------------
--  SHOW TABLES LIKE 'expert_visit_pricing';           -- 1 row
--  SELECT setting_key, setting_value FROM app_settings
--    WHERE setting_key IN ('expert_visit_fee','expert_visit_free');   -- 299 / 0
--  -- Add a category override (₹499 for category 3):
--  -- INSERT INTO expert_visit_pricing (category_id, amount, is_free) VALUES (3, 499, 0);
--  -- Add a FREE city override (free in city 1, all categories):
--  -- INSERT INTO expert_visit_pricing (city_id, is_free) VALUES (1, 1);
--  -- Resolver precedence is exercised by ExpertVisitPricing::resolve($db, cat, city).
-- ============================================================================
