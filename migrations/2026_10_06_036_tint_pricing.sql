-- ============================================================================
--  WorkToGo Commerce — Paint PB10b: custom-tint pricing + accept + advance
--  Migration: 2026_10_06_036_tint_pricing
-- ----------------------------------------------------------------------------
--  PURPOSE: the seller's colour price is run through the SAME MarginEngine on the
--  total, then capped by an admin CEILING (× the instant estimate) so the colour
--  can't balloon. The customer sees ONE final price and accepts; an advance
--  (commerce_advance_default_pct, default 50%) is taken, then making begins
--  (advance non-refundable once making starts — the PB16 'making_started' rule).
--  Reuses MarginEngine + CommercePayment advance + the ledger split. No engine edit.
-- ============================================================================

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_tint_ceiling_mult', '2.0', 'number', 'Custom-tint final price ceiling as a multiple of the instant estimate (anti-balloon)', 0);
