-- ============================================================================
--  WorkToGo V2 — Phase 1: disambiguate wallet_transactions across engines (H3)
--  Migration: 2026_07_04_004_wallet_transactions_reference_type
-- ----------------------------------------------------------------------------
--  PROBLEM (audit-06 H3 / audit-05 / audit-08 activation landmine)
--    wallet_transactions.order_id is overloaded: LedgerEngine::processBooking-
--    Completion writes a service booking_id into it, while processOrderCompletion
--    / processRefund write a shopping order_id into the SAME column. Booking #N and
--    Order #N therefore share one id-space. It's "safe" today only because Shopping
--    is dormant; the moment Shopping activates, a refund/idempotency lookup for
--    order #N can match booking #N's rows -> wrong reversal / double-count /
--    corrupted vendor balance. This must be fixed BEFORE Shopping is ever enabled.
--
--  FIX (mirror the existing house pattern — transactions.reference_type/_id)
--    Add reference_type + reference_id so every wallet row names its source
--    unambiguously. reference_type is varchar (generalises transactions' enum so a
--    future engine needs no ALTER); reference_id holds the source id. The old
--    order_id column is LEFT IN PLACE, DORMANT (still written for backward-compat,
--    no longer the disambiguation key) — a later cleanup phase can drop it.
--
--  BACKFILL IS UNAMBIGUOUS: every existing wallet_transactions row was written by
--    processBookingCompletion (processOrderCompletion has no caller — audit-05, and
--    processRefund reads the orders table so it never produced rows while Shopping
--    was dormant). So all existing rows are service bookings -> reference_type
--    'booking', reference_id = order_id.
--
--  SAFETY / IDEMPOTENCY: ADD COLUMN/KEY IF NOT EXISTS + backfill WHERE ... IS NULL.
--    No amounts, splits, or settlement behaviour change. Do NOT run against prod
--    from here — apply on STAGING first, verify, then prod.
-- ============================================================================


-- ── 1. Add the disambiguating columns (order_id kept, now dormant) ──────────
--    reference_type NOT NULL DEFAULT 'booking' auto-tags existing rows (all of
--    which are bookings); the code always sets it explicitly for new rows.
ALTER TABLE `wallet_transactions`
  ADD COLUMN IF NOT EXISTS `reference_type` varchar(20) NOT NULL DEFAULT 'booking'
    COMMENT 'booking|order|... — the engine/source of this ledger row' AFTER `order_id`,
  ADD COLUMN IF NOT EXISTS `reference_id` bigint(20) UNSIGNED NULL
    COMMENT 'source id within reference_type (booking_id / order_id)' AFTER `reference_type`,
  ADD KEY IF NOT EXISTS `idx_wt_reference` (`reference_type`, `reference_id`);


-- ── 2. Backfill existing rows: all are service bookings ─────────────────────
UPDATE `wallet_transactions`
   SET `reference_id` = `order_id`
 WHERE `reference_id` IS NULL
   AND `order_id` IS NOT NULL;

-- Defensive: ensure every existing row is tagged 'booking' (the DEFAULT already
-- set this on ADD COLUMN; this re-asserts it and is safe to re-run).
UPDATE `wallet_transactions`
   SET `reference_type` = 'booking'
 WHERE (`reference_type` IS NULL OR `reference_type` = '');


-- ============================================================================
--  VERIFICATION (run after applying; expected results noted)
-- ----------------------------------------------------------------------------
--  SHOW COLUMNS FROM wallet_transactions LIKE 'reference_type';   -- 1 row
--  SHOW COLUMNS FROM wallet_transactions LIKE 'reference_id';     -- 1 row
--  -- Every existing row tagged as a booking with a source id:
--  SELECT COUNT(*) FROM wallet_transactions
--    WHERE reference_type <> 'booking' OR reference_id IS NULL;   -- expect 0
--  -- reference_id matches the legacy order_id it was copied from:
--  SELECT COUNT(*) FROM wallet_transactions
--    WHERE order_id IS NOT NULL AND reference_id <> order_id;     -- expect 0
--  -- Index present:
--  SHOW INDEX FROM wallet_transactions WHERE Key_name = 'idx_wt_reference';
-- ============================================================================
