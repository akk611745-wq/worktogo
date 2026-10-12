-- ============================================================================
--  WorkToGo Commerce — Paint: PER-BRAND custom-tint go-live switch
--  Migration: 2026_10_06_021_custom_tint_brands
-- ----------------------------------------------------------------------------
--  PURPOSE: custom tint is enabled ONE BRAND AT A TIME, not globally. The admin
--  keeps an "enabled brands" list; a brand's tintable shades become orderable
--  only once that brand is on the list. Starts EMPTY = no brand live (so after
--  the Prisma data load, nothing is orderable until a brand is switched on as it
--  is onboarded, e.g. Berger).
--
--  commerce_custom_tint_brands = JSON array of brand names (case-insensitive
--  match against a product's attributes.brand). Read by UnitConfig at the
--  OrderabilityResolver layer-4 hook; managed by AdminControlRoom / CustomTint.
--
--  SAFETY: one config key (INSERT IGNORE keeps an admin value). Supersedes the
--  old global commerce_unit_config.paint.custom_tint_enabled flag (now inert).
--  ADDITIVE; no engine edit. B3-compliant.
-- ============================================================================

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_custom_tint_brands', '[]', 'text', 'Custom-tint enabled brands (per-brand go-live; empty = none live)', 0);
