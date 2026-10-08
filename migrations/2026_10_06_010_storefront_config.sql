-- ============================================================================
--  WorkToGo Commerce — S10: CUSTOMER-APP storefront config
--  Migration: 2026_10_06_010_storefront_config
-- ----------------------------------------------------------------------------
--  PURPOSE (commerce-system-blueprint §Q/§U; FINAL scope — customer app):
--    The customer app must be founder-free and Unit-driven: what it shows is
--    DB-config, not hardcoded. This migration seeds the four app_settings keys
--    the storefront composer (lib/commerce/StorefrontComposer.php) and the
--    /api/shop adapter read:
--      1. commerce_seller_visibility — per-category seller-identity policy.
--         The customer app can HIDE the seller (show "fulfilled by <platform>")
--         so WorkToGo is not seller-dependent. JSON:
--           {"default":"shown","hidden":[catId,...],"shown":[catId,...]}
--         default 'shown' keeps today's behaviour until a Unit opts to hide.
--      2. commerce_platform_label — the brand shown when the seller is hidden.
--      3. commerce_unit_ui — per-Unit (category) UI config the app renders from
--         (display_mode / add label / show-unit), so a new Unit needs no code.
--      4. commerce_related_limit — how many related products the detail shows.
--
--  SAFETY / IDEMPOTENCY
--    * INSERT IGNORE on app_settings (keyed by unique setting_key) — a re-run
--      never clobbers an admin-tuned value. ADDITIVE only; no column changes.
--    * 10.4-safe: no COMMENT-after-CHECK, no new tables.
-- ============================================================================

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_seller_visibility',
   '{"default":"shown","hidden":[],"shown":[]}',
   'json', 'Commerce: per-category seller-identity visibility (customer app)', 0),
  ('commerce_platform_label', 'WorkToGo', 'text',
   'Commerce: brand shown as fulfiller when the seller is hidden', 1),
  ('commerce_unit_ui',
   '{"default":{"display_mode":"grid","add_label":"ADD","show_unit":true},"categories":{}}',
   'json', 'Commerce: per-Unit (category) customer-app UI config', 1),
  ('commerce_related_limit', '8', 'number',
   'Commerce: number of related products on the product detail', 1);
