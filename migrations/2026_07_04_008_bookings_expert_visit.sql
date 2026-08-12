-- ============================================================================
--  WorkToGo V2 — Phase 2: structured Expert Visit fields on bookings
--  Migration: 2026_07_04_008_bookings_expert_visit
-- ----------------------------------------------------------------------------
--  PURPOSE
--    Expert Visit replaces the customer "inspection" concept (backend stays
--    compatible — a paid expert visit still runs as booking_mode='inspection'
--    through the existing Cashfree pay-to-confirm path). Persist the visit as
--    STRUCTURED columns so free-vs-paid, resolved fee, and estimate-vs-actual
--    are all queryable (category_id / city_id already live on bookings).
--
--    * visit_type          -> 'expert_visit' (NULL for non-visit bookings)
--    * expert_visit_fee     -> the resolved fee charged for the visit (0 if free)
--    * expert_visit_free    -> 1 = free visit, 0 = paid
--    * final_quote_amount   -> the expert's REAL quote entered after visiting,
--                              enabling the estimate-vs-actual metric later
--
--  The fee itself is resolved server-side by ExpertVisitPricing::resolve()
--  (category+city precedence) — never trusted from the client. All columns are
--  NULLABLE, so existing rows and every other booking mode are unaffected.
--
--  SAFETY / IDEMPOTENCY: ADD COLUMN/KEY IF NOT EXISTS (re-run safe). No FK needed.
--    Do NOT run against prod from here — apply on STAGING first, verify, then prod.
-- ============================================================================

ALTER TABLE `bookings`
  ADD COLUMN IF NOT EXISTS `visit_type`         varchar(20) NULL DEFAULT NULL
    COMMENT 'expert_visit (NULL = not a visit booking)',
  ADD COLUMN IF NOT EXISTS `expert_visit_fee`   decimal(10,2) NULL DEFAULT NULL
    COMMENT 'resolved expert-visit fee charged (0 if free)',
  ADD COLUMN IF NOT EXISTS `expert_visit_free`  tinyint(1) NULL DEFAULT NULL
    COMMENT '1 = free expert visit, 0 = paid',
  ADD COLUMN IF NOT EXISTS `final_quote_amount` decimal(12,2) NULL DEFAULT NULL
    COMMENT 'expert quote entered after the visit (estimate-vs-actual)',
  ADD KEY IF NOT EXISTS `idx_bookings_visit_type` (`visit_type`);

-- ============================================================================
--  VERIFICATION (run after applying)
-- ----------------------------------------------------------------------------
--  SHOW COLUMNS FROM bookings LIKE 'visit_type';         -- 1 row, Null=YES
--  SHOW COLUMNS FROM bookings LIKE 'expert_visit_fee';   -- 1 row, Null=YES
--  SHOW COLUMNS FROM bookings LIKE 'expert_visit_free';  -- 1 row, Null=YES
--  SHOW COLUMNS FROM bookings LIKE 'final_quote_amount'; -- 1 row, Null=YES
--  -- Existing rows unaffected (all NULL):
--  SELECT COUNT(*) FROM bookings WHERE visit_type IS NOT NULL;   -- 0 until first expert visit
--  -- Reporting the structure enables:
--  -- SELECT visit_type, expert_visit_free, COUNT(*), SUM(expert_visit_fee)
--  --   FROM bookings WHERE visit_type='expert_visit' GROUP BY expert_visit_free;
-- ============================================================================
