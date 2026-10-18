-- ============================================================================
--  WorkToGo Commerce — Paint PB10c: price band + repeat-lock
--  Migration: 2026_10_06_037_tint_band
-- ----------------------------------------------------------------------------
--  PURPOSE: every priced quote feeds a price band per (brand, shade_code). A
--  quote whose per-litre is above the admin band threshold is an OUTLIER: it is
--  NOT shown to the customer (reroute to the next seller) and surfaces in the PB9
--  ops queue. Repeat orders of a shade the customer already had priced are priced
--  instantly from the locked per-litre (same seller, no re-ask).
--  Band = computed live from commerce_tint_quotes; this only adds the outlier mark
--  + thresholds. ADDITIVE; no engine edit. B3-compliant.
-- ============================================================================

ALTER TABLE `commerce_tint_quotes`
  ADD COLUMN IF NOT EXISTS `outlier_flag` tinyint(1) NOT NULL DEFAULT 0 AFTER `reroute_count`,
  ADD COLUMN IF NOT EXISTS `colour_per_litre` decimal(12,4) NULL AFTER `locked_per_litre`;

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_tint_band_outlier_pct', '50', 'number', 'A tint quote whose per-litre is this % above the band median is an outlier (hidden, rerouted)', 0),
  ('commerce_tint_band_min_samples', '3', 'number', 'Quotes needed in a (brand,shade) band before outlier rejection kicks in', 0);
