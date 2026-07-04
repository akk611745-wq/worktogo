-- ============================================================================
--  WorkToGo V2 — Phase 1: cities table + city_id foundation
--  Migration: 2026_07_04_001_cities_and_city_id_foundation
-- ----------------------------------------------------------------------------
--  PURPOSE
--    Introduce a real city dimension so V2 is multi-city-ready and the already-
--    built Brain unified feed (audit-08) has the columns it needs to eventually
--    work. Today there is NO cities table and NO city_id anywhere; location is
--    free text end-to-end (audit-07). This migration adds the PARENT city layer
--    ONLY — it does NOT remove or change the existing free-text area/locality
--    fields (vendors.service_localities, bookings.customer_locality, the
--    ServiceVendorEligibility text matching). Those stay; city_id sits above them.
--
--  SCOPE (this migration)
--    1. CREATE TABLE cities  (+ seed Haldwani as the first city, slug='haldwani')
--    2. ADD city_id (nullable FK -> cities.id) to: users, vendors, bookings, services
--    3. BACKFILL every existing row in those 4 tables to the Haldwani city_id
--       (the whole platform is single-city today, so all existing data is Haldwani)
--    4. Add the FK constraints (guarded so the file is safe to re-run)
--
--  SAFETY / IDEMPOTENCY
--    - No DROP, no data loss, no NOT NULL added to existing columns.
--    - city_id is nullable so the ADD COLUMN never fails on existing rows, and the
--      backfill can run in a second step before the FK is enforced.
--    - CREATE TABLE IF NOT EXISTS / ADD COLUMN IF NOT EXISTS / ADD KEY IF NOT EXISTS
--      / INSERT ... ON DUPLICATE KEY / UPDATE ... WHERE city_id IS NULL, and the FK
--      adds are guarded via information_schema — the whole file is safe to re-run.
--    - migrate.php records this in migrations_log so it only auto-applies once;
--      on live it is applied manually via phpMyAdmin (per repo convention).
--    - Run order matters: CREATE cities -> SEED -> ADD COLUMNs -> BACKFILL -> ADD FKs.
--      Do NOT reorder (FKs are added last, after backfill, though city_id is
--      nullable so ordering is not strictly required for constraint satisfaction).
--
--  DO NOT RUN against any live/prod DB from here. Apply on STAGING first
--  (see STAGING STEPS in the task report), verify, then schedule for prod.
--
-- ----------------------------------------------------------------------------
--  BRAIN FEED RECONCILIATION  (audit-08) — documentation, not executed here
-- ----------------------------------------------------------------------------
--  The Brain unified feed is currently DEAD because it queries columns that do
--  not exist. This migration supplies the city dimension it needs, but the feed
--  SQL must still be updated (a Phase-5 Brain/Feed task, NOT this foundation
--  commit — changing that code blind, with no local PHP runtime, is not safe).
--  Exact required changes, recorded here so nothing is forgotten:
--
--  (a) brain/BrainCore.php :: buildUserFeed()
--        NOW : SELECT city FROM users WHERE id = ?            -- users.city does NOT exist
--        V2  : SELECT c.slug, c.id AS city_id
--                FROM users u LEFT JOIN cities c ON c.id = u.city_id
--               WHERE u.id = ?
--              (use city_id for matching; use cities.name/slug for the display
--               "Trending in <city>" string).
--
--  (b) brain/FeedRankingEngine.php :: getTrendingItems($limit, $city)
--        NOW : SELECT id, business_name, type, rating, total_orders, city,
--                     ((rating*0.4)+(total_orders*0.3)) AS base_score
--                FROM vendors WHERE city = ? AND status='active' ...
--                     -- vendors.city AND vendors.total_orders BOTH do NOT exist
--        V2  : match on v.city_id = ? (int) instead of a city string, and source
--              the order-count from a real place (see total_orders note below).
--
--  (c) vendors.total_orders  — FLAGGED, intentionally NOT added here.
--        The feed expects a per-vendor order/booking count. There is no such
--        column. Adding an unmaintained counter now would just create another
--        drifting denormalized field (exactly the anti-pattern audit-02/-06
--        warn about). Decide in the feed-build task between:
--          (i)  compute on read, e.g.
--               (SELECT COUNT(*) FROM bookings b
--                  WHERE b.vendor_id = v.id AND b.status = 'completed') AS total_orders
--          (ii) add a real maintained counter (column + increment on completion).
--        Recommend (i) until volume warrants (ii). This is a founder/feature
--        decision, not a foundation column — see OPEN QUESTIONS in the report.
--
--  (d) Brain tenant reconciliation: client_id='haldwani' / location='haldwani'
--        (hardcoded in brain/engine/*, HabitModel — audit-07 §1) maps 1:1 to
--        cities.slug='haldwani' seeded below. The slug IS the bridge:
--            brain client_id/location  <->  cities.slug  <->  cities.id (city_id)
--        Phase-5 should derive the Brain client/location from the active city's
--        slug instead of the literal 'haldwani'. No code change is made here
--        (untestable without a runtime); the seed establishes the anchor.
-- ============================================================================


-- ── 1. cities table ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cities` (
  `id`         bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       varchar(100) NOT NULL,
  `slug`       varchar(120) NOT NULL,
  `state`      varchar(100) DEFAULT NULL,
  `country`    varchar(100) NOT NULL DEFAULT 'India',
  `center_lat` decimal(10,7) DEFAULT NULL,
  `center_lng` decimal(10,7) DEFAULT NULL,
  `is_active`  tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cities_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 2. Seed the first city: Haldwani ────────────────────────────────────────
--    slug='haldwani' is the reconciliation anchor for the Brain client_id (d).
--    Idempotent via the UNIQUE slug + ON DUPLICATE KEY UPDATE.
INSERT INTO `cities` (`name`, `slug`, `state`, `country`, `center_lat`, `center_lng`, `is_active`, `sort_order`)
VALUES ('Haldwani', 'haldwani', 'Uttarakhand', 'India', 29.2183440, 79.5130053, 1, 1)
ON DUPLICATE KEY UPDATE
  `name`   = VALUES(`name`),
  `state`  = VALUES(`state`),
  `is_active` = VALUES(`is_active`);


-- ── 3. Add city_id (nullable FK column + index) to the 4 core tables ─────────
--    Nullable + no DEFAULT: existing rows get NULL here, then step 4 backfills
--    them to Haldwani. New rows will be NULL until the create paths are wired to
--    set city_id (documented follow-up — not in this foundation migration).
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `city_id` bigint(20) UNSIGNED NULL DEFAULT NULL,
  ADD KEY IF NOT EXISTS `idx_users_city` (`city_id`);

ALTER TABLE `vendors`
  ADD COLUMN IF NOT EXISTS `city_id` bigint(20) UNSIGNED NULL DEFAULT NULL,
  ADD KEY IF NOT EXISTS `idx_vendors_city` (`city_id`);

ALTER TABLE `bookings`
  ADD COLUMN IF NOT EXISTS `city_id` bigint(20) UNSIGNED NULL DEFAULT NULL,
  ADD KEY IF NOT EXISTS `idx_bookings_city` (`city_id`);

ALTER TABLE `services`
  ADD COLUMN IF NOT EXISTS `city_id` bigint(20) UNSIGNED NULL DEFAULT NULL,
  ADD KEY IF NOT EXISTS `idx_services_city` (`city_id`);


-- ── 4. Backfill existing rows to Haldwani ───────────────────────────────────
--    The platform is single-city today, so every existing user/vendor/booking/
--    service belongs to Haldwani. WHERE city_id IS NULL makes this re-runnable.
UPDATE `users`    SET `city_id` = (SELECT `id` FROM `cities` WHERE `slug` = 'haldwani' LIMIT 1) WHERE `city_id` IS NULL;
UPDATE `vendors`  SET `city_id` = (SELECT `id` FROM `cities` WHERE `slug` = 'haldwani' LIMIT 1) WHERE `city_id` IS NULL;
UPDATE `bookings` SET `city_id` = (SELECT `id` FROM `cities` WHERE `slug` = 'haldwani' LIMIT 1) WHERE `city_id` IS NULL;
UPDATE `services` SET `city_id` = (SELECT `id` FROM `cities` WHERE `slug` = 'haldwani' LIMIT 1) WHERE `city_id` IS NULL;


-- ── 5. Add FK constraints (guarded via information_schema, so re-run is safe) ─
--    ON DELETE SET NULL: a city should be deactivated (is_active=0), not deleted;
--    if one ever is deleted, dependent rows keep their data with city_id NULL
--    rather than cascading away real bookings/users.

-- users.city_id -> cities.id
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'fk_users_city');
SET @sql := IF(@fk = 0,
  'ALTER TABLE `users` ADD CONSTRAINT `fk_users_city` FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- vendors.city_id -> cities.id
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND CONSTRAINT_NAME = 'fk_vendors_city');
SET @sql := IF(@fk = 0,
  'ALTER TABLE `vendors` ADD CONSTRAINT `fk_vendors_city` FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- bookings.city_id -> cities.id
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND CONSTRAINT_NAME = 'fk_bookings_city');
SET @sql := IF(@fk = 0,
  'ALTER TABLE `bookings` ADD CONSTRAINT `fk_bookings_city` FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- services.city_id -> cities.id
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND CONSTRAINT_NAME = 'fk_services_city');
SET @sql := IF(@fk = 0,
  'ALTER TABLE `services` ADD CONSTRAINT `fk_services_city` FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ============================================================================
--  VERIFICATION (run after applying; expected results noted)
-- ----------------------------------------------------------------------------
--  SELECT id, name, slug FROM cities;                                  -- >= 1 row: Haldwani, slug 'haldwani'
--  SHOW COLUMNS FROM users    LIKE 'city_id';                          -- 1 row, Null=YES
--  SHOW COLUMNS FROM vendors  LIKE 'city_id';                          -- 1 row, Null=YES
--  SHOW COLUMNS FROM bookings LIKE 'city_id';                          -- 1 row, Null=YES
--  SHOW COLUMNS FROM services LIKE 'city_id';                          -- 1 row, Null=YES
--  SELECT COUNT(*) FROM users    WHERE city_id IS NULL;                -- expect 0 after backfill
--  SELECT COUNT(*) FROM vendors  WHERE city_id IS NULL;                -- expect 0
--  SELECT COUNT(*) FROM bookings WHERE city_id IS NULL;                -- expect 0
--  SELECT COUNT(*) FROM services WHERE city_id IS NULL;                -- expect 0
--  SELECT CONSTRAINT_NAME, TABLE_NAME FROM information_schema.TABLE_CONSTRAINTS
--    WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME LIKE 'fk_%_city';  -- expect 4 rows
-- ============================================================================
