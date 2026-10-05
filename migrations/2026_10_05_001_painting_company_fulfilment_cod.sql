-- ============================================================================
--  WorkToGo V2 — Launch unit: company self-fulfilment + COD money record
--  Migration: 2026_10_05_001_painting_company_fulfilment_cod
-- ----------------------------------------------------------------------------
--  PURPOSE (founder intent: a one-city Painting/Waterproofing unit that can run
--    day-1 with NO registered vendors — the COMPANY fulfils the job with its own
--    worker and keeps the FULL margin — while still supporting vendors as backup,
--    with every job RECORDED: who did it, the price, and how cash was collected.)
--
--    This is CATEGORY-AGNOSTIC (works for the next category with zero code) — it
--    only adds structured record columns the admin/ledger already know how to use.
--
--    bookings.fulfilment_type     -> 'company' = done by the company's own team
--                                    (no registered vendor, no vendor commission,
--                                    100% company margin); 'vendor' = a registered
--                                    vendor was assigned (normal commission split).
--                                    NULL on legacy/unassigned rows => treated as
--                                    vendor unless no vendor_id is set.
--    bookings.company_worker_name -> who physically did a company-fulfilled job
--                                    (plain record, e.g. "Ramesh (company team)").
--    bookings.payment_collected_by-> who collected the COD/manual payment
--                                    ('company' | 'vendor' | a name) — audit trail.
--    bookings.payment_collected_at-> when the cash was marked collected.
--
--    (total + final_quote_amount already exist — migration 2026_07_04_008. The
--     admin "Set final amount" action writes both; the ledger already reads total.)
--
--  services.vendor_id -> made NULLABLE so a CATEGORY can own a company "anchor"
--    service (vendor_id NULL) instead of being tied to one registered vendor's
--    row (today Painting/Waterproofing only resolve because a seed tied them to
--    vendor #11; deleting that vendor cascades the anchor away). A NULL-owner
--    anchor is stable and lets admin stand up the next category with zero code.
--    The FK (services_ibfk_1 -> vendors.id) tolerates NULL, so it is kept.
--
--  SAFETY / IDEMPOTENCY: ADD COLUMN/KEY IF NOT EXISTS + an information_schema
--    guard around the MODIFY (only runs while vendor_id is still NOT NULL), so a
--    re-run is a no-op. All new columns are NULLABLE — existing rows and every
--    other booking mode/category are unaffected.
--    Do NOT run against prod from here — apply on STAGING first, verify, then prod.
-- ============================================================================

-- ── 1. Structured company-fulfilment + COD record columns (all nullable) ────
ALTER TABLE `bookings`
  ADD COLUMN IF NOT EXISTS `fulfilment_type`      varchar(20)  NULL DEFAULT NULL
    COMMENT 'company = own-worker (100% margin, no vendor); vendor = registered vendor',
  ADD COLUMN IF NOT EXISTS `company_worker_name`  varchar(120) NULL DEFAULT NULL
    COMMENT 'who did a company-fulfilled job (record only)',
  ADD COLUMN IF NOT EXISTS `payment_collected_by` varchar(120) NULL DEFAULT NULL
    COMMENT 'who collected the COD/manual payment (company | vendor | name)',
  ADD COLUMN IF NOT EXISTS `payment_collected_at` datetime     NULL DEFAULT NULL
    COMMENT 'when the COD/manual payment was marked collected',
  ADD KEY IF NOT EXISTS `idx_bookings_fulfilment_type` (`fulfilment_type`);

-- ── 2. Let a category own a company anchor service (vendor_id NULL) ──────────
--    Guarded: only MODIFY while the column is still NOT NULL (keeps the re-run
--    safe and leaves the FK intact — a foreign key permits NULL values).
SET @vendor_id_notnull := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services'
     AND COLUMN_NAME = 'vendor_id' AND IS_NULLABLE = 'NO'
);
SET @sql := IF(@vendor_id_notnull > 0,
  'ALTER TABLE `services` MODIFY COLUMN `vendor_id` bigint(20) UNSIGNED NULL',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ============================================================================
--  VERIFICATION (run after applying)
-- ----------------------------------------------------------------------------
--  SHOW COLUMNS FROM bookings LIKE 'fulfilment_type';       -- 1 row, Null=YES
--  SHOW COLUMNS FROM bookings LIKE 'company_worker_name';   -- 1 row, Null=YES
--  SHOW COLUMNS FROM bookings LIKE 'payment_collected_by';  -- 1 row, Null=YES
--  SHOW COLUMNS FROM bookings LIKE 'payment_collected_at';  -- 1 row, Null=YES
--  SHOW COLUMNS FROM services LIKE 'vendor_id';             -- Null=YES
--  -- Existing rows unaffected (all new columns NULL):
--  SELECT COUNT(*) FROM bookings WHERE fulfilment_type IS NOT NULL;  -- 0 until first assign
--  -- Reporting the structure now enables (company vs vendor margin, cash collected):
--  -- SELECT fulfilment_type, COUNT(*), SUM(total)
--  --   FROM bookings WHERE status='completed' GROUP BY fulfilment_type;
-- ============================================================================
