-- ============================================================================
--  WorkToGo Commerce — Paint PB4: customer storefront module switch + coverage
--  Migration: 2026_10_06_027_paint_shop_switch
-- ----------------------------------------------------------------------------
--  PURPOSE: config keys for the paint customer storefront.
--   - commerce_shop_enabled        : global master switch (default 0 = off, so the
--                                     service pilot is unchanged until an admin turns
--                                     paint on). SERVICE_ONLY_MODE is never flipped.
--   - commerce_shop_enabled_cities : JSON list of city ids where paint is ON
--                                     (per-city switch; set from the admin screen).
--   - commerce_coverage_sqft_per_litre : paint coverage rate for the customer's
--                                     coverage calculator (default 140 sqft/L/coat).
--  ADDITIVE; no engine edit. B3-compliant.
-- ============================================================================

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_shop_enabled', '0', 'text', 'Global master switch for the customer paint shop module', 0),
  ('commerce_shop_enabled_cities', '[]', 'json', 'City IDs where the paint shop module is ON', 0),
  ('commerce_coverage_sqft_per_litre', '140', 'number', 'Paint coverage rate (sq ft per litre per coat) for the coverage calculator', 1);
