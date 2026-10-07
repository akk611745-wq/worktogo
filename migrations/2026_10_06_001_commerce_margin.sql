-- ============================================================================
--  WorkToGo Commerce — S1: MARGIN model foundation
--  Migration: 2026_10_06_001_commerce_margin
-- ----------------------------------------------------------------------------
--  PURPOSE (commerce-system-blueprint §E; FINAL scope):
--    Move commerce money from COMMISSION (deduct a % from the seller) to MARGIN
--    (seller is paid their base in full; WorkToGo keeps the spread it added on
--    top). This migration is ADDITIVE + NULLABLE only — it changes NO existing
--    column and does NOT touch the service (bookings) ledger path.
--
--  WHAT THIS DELIVERS
--    1. 3-tier margin config:
--         platform level  -> app_settings.commerce_margin_policy (JSON)
--         category level   -> categories.margin_pct / margin_flat  (override)
--         product level    -> products.margin_pct  / margin_flat   (override)
--       Resolution order (in MarginEngine): product ?? category ?? platform.
--    2. Seller-base capture so the ledger can split margin exactly:
--         orders.seller_subtotal         (per (sub-)order: the seller's base)
--         order_items.seller_base_price  (per line: the seller's base)
--       (subtotal/total stay = the customer price, now incl. margin.)
--    3. Loss-proof floor settings (admin-tunable): the system must never accept
--       an order where margin - platform cost exposure < floor.
--
--  SAFETY / IDEMPOTENCY
--    * ADD COLUMN IF NOT EXISTS (MariaDB) — re-run safe, no data loss.
--    * INSERT IGNORE on app_settings (keyed by unique setting_key) — a re-run
--      never clobbers an admin-tuned value.
--    * NOTE (S-b finding): target DB is MariaDB >= 10.5 / MySQL 8. Apply on
--      STAGING first; this file carries no COMMENT-after-CHECK, so it is 10.4-safe.
-- ============================================================================

-- 1. Category-level margin override (nullable; NULL = inherit platform default)
ALTER TABLE `categories`
  ADD COLUMN IF NOT EXISTS `margin_pct`  decimal(6,2)  NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `margin_flat` decimal(12,2) NULL DEFAULT NULL;

-- 2. Product-level margin override (nullable; highest precedence)
ALTER TABLE `products`
  ADD COLUMN IF NOT EXISTS `margin_pct`  decimal(6,2)  NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `margin_flat` decimal(12,2) NULL DEFAULT NULL;

-- 3. Seller-base capture (nullable; populated at checkout by the adapter later)
ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `seller_subtotal` decimal(12,2) NULL DEFAULT NULL;

ALTER TABLE `order_items`
  ADD COLUMN IF NOT EXISTS `seller_base_price` decimal(12,2) NULL DEFAULT NULL;

-- 4. Margin policy + loss-proof floor (platform defaults; admin tunes live)
INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_margin_policy',
   '{"platform_margin_pct":8,"platform_margin_flat":0,"min_margin_flat":2,"rounding":"nearest_1"}',
   'json', 'Commerce margin policy (platform default + min floor + rounding)', 0),
  ('commerce_loss_proof_floor', '0', 'number',
   'Commerce loss-proof floor: minimum (order margin - platform cost exposure)', 0),
  ('commerce_loss_proof_enabled', '1', 'boolean',
   'If 1, reject an order where (margin - platform cost exposure) < floor', 0);
