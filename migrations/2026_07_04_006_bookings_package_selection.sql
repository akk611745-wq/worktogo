-- ============================================================================
--  WorkToGo V2 — Phase 2: structured package selection on bookings
--  Migration: 2026_07_04_006_bookings_package_selection
-- ----------------------------------------------------------------------------
--  PURPOSE (founder intent: structured, not text — so the platform can report,
--    learn and scale to millions of rows)
--    When a customer picks a package in the Company View, persist the choice as
--    STRUCTURED columns instead of a text blob, so per-category revenue, demand
--    by area, and estimate-vs-actual are all computable later.
--
--  DESIGN: columns on `bookings`, NOT a child table — a booking carries AT MOST
--    ONE optional package selection (1:1), so a junction table would add a join
--    to every read for no benefit. All columns are NULLABLE: free_lead /
--    inspection / direct_vendor bookings (and every existing row) simply leave
--    them NULL — a general company request is valid with no package.
--
--    * package_id      -> packages(id)  (FK, ON DELETE SET NULL) — joinable for
--                         reporting (revenue per category via package->category)
--    * package_name    -> immutable snapshot of the package name at booking time
--                         (same pattern as order_items.product_name in the
--                         shopping engine), so history survives a package rename
--                         or delete and admin can read it without a join
--    * area_sqft       -> the customer's entered area (structured input)
--    * estimate_amount -> the estimate shown, RECOMPUTED server-side from the
--                         package rate/base + area (not client-trusted)
--
--  SAFETY / IDEMPOTENCY: ADD COLUMN/KEY IF NOT EXISTS + information_schema-guarded
--    FK (re-run safe). Depends on migration 005 (packages) — 006 sorts later.
--    Do NOT run against prod from here — apply on STAGING first, verify, then prod.
-- ============================================================================

-- ── 1. Structured package-selection columns (all nullable) ──────────────────
ALTER TABLE `bookings`
  ADD COLUMN IF NOT EXISTS `package_id`      bigint(20) UNSIGNED NULL DEFAULT NULL
    COMMENT 'FK packages.id — the package the customer selected (nullable)',
  ADD COLUMN IF NOT EXISTS `package_name`    varchar(100) NULL DEFAULT NULL
    COMMENT 'immutable snapshot of the package name at booking time',
  ADD COLUMN IF NOT EXISTS `area_sqft`       decimal(10,2) NULL DEFAULT NULL
    COMMENT 'customer-entered area used for the live estimate',
  ADD COLUMN IF NOT EXISTS `estimate_amount` decimal(12,2) NULL DEFAULT NULL
    COMMENT 'server-recomputed estimate shown to the customer',
  ADD KEY IF NOT EXISTS `idx_bookings_package` (`package_id`);

-- ── 2. FK bookings.package_id -> packages.id (guarded, re-run safe) ──────────
--    ON DELETE SET NULL: deleting a package keeps the booking (and its
--    package_name snapshot / area / estimate) intact; only the live link nulls.
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND CONSTRAINT_NAME = 'fk_bookings_package');
SET @sql := IF(@fk = 0,
  'ALTER TABLE `bookings` ADD CONSTRAINT `fk_bookings_package` FOREIGN KEY (`package_id`) REFERENCES `packages` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ============================================================================
--  VERIFICATION (run after applying)
-- ----------------------------------------------------------------------------
--  SHOW COLUMNS FROM bookings LIKE 'package_id';       -- 1 row, Null=YES
--  SHOW COLUMNS FROM bookings LIKE 'package_name';     -- 1 row, Null=YES
--  SHOW COLUMNS FROM bookings LIKE 'area_sqft';        -- 1 row, Null=YES
--  SHOW COLUMNS FROM bookings LIKE 'estimate_amount';  -- 1 row, Null=YES
--  SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
--    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings'
--      AND CONSTRAINT_NAME = 'fk_bookings_package';     -- expect 1 row
--  -- Existing rows are unaffected (all four columns NULL):
--  SELECT COUNT(*) FROM bookings WHERE package_id IS NOT NULL;  -- 0 until packages are booked
--  -- Reporting example the structure now enables:
--  -- SELECT c.name, COUNT(*) AS bookings, SUM(b.estimate_amount) AS est_value
--  --   FROM bookings b JOIN packages p ON p.id = b.package_id
--  --   JOIN categories c ON c.id = p.category_id GROUP BY c.name;
-- ============================================================================
