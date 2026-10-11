-- ============================================================================
--  WorkToGo Commerce — Paint Unit P1: attribute convention + 7 categories + hook
--  Migration: 2026_10_06_018_paint_unit
-- ----------------------------------------------------------------------------
--  PURPOSE (Paint P1 — the smallest foundation): seed the 7 paint family
--  categories and the `commerce_unit_config` that the OrderabilityResolver
--  layer-4 unit-rule hook (UnitConfig) consumes. No engine columns — paint
--  attributes ride the existing products.attributes JSON (see PaintUnit).
--
--  commerce_unit_config:
--    units.paint.enabled            = true   (paint unit live)
--    units.paint.custom_tint_enabled= false  (custom tint stays OFF until the
--                                              Prisma shade/base/code import)
--    paint_category_slugs           = the 7 category rows below
--
--  SAFETY: categories inserted idempotently (INSERT ... FROM DUAL WHERE NOT
--    EXISTS on slug) — safe on fresh + existing DBs. INSERT IGNORE on the
--    unique app_settings key keeps any admin-set value. Additive; no engine
--    edit; default-safe (unconfigured DBs pass layer-4). B3-compliant
--    (no DEFAULT CHARSET, no COMMENT-after-CHECK).
-- ============================================================================

-- ── 7 paint family categories (type=product, active) ────────────────────────
INSERT INTO `categories` (`name`, `slug`, `type`, `status`)
SELECT 'Interior Emulsion', 'paint-interior-emulsion', 'product', 'active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `categories` WHERE `slug` = 'paint-interior-emulsion');

INSERT INTO `categories` (`name`, `slug`, `type`, `status`)
SELECT 'Exterior Emulsion', 'paint-exterior-emulsion', 'product', 'active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `categories` WHERE `slug` = 'paint-exterior-emulsion');

INSERT INTO `categories` (`name`, `slug`, `type`, `status`)
SELECT 'Enamel', 'paint-enamel', 'product', 'active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `categories` WHERE `slug` = 'paint-enamel');

INSERT INTO `categories` (`name`, `slug`, `type`, `status`)
SELECT 'Distemper', 'paint-distemper', 'product', 'active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `categories` WHERE `slug` = 'paint-distemper');

INSERT INTO `categories` (`name`, `slug`, `type`, `status`)
SELECT 'Primer', 'paint-primer', 'product', 'active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `categories` WHERE `slug` = 'paint-primer');

INSERT INTO `categories` (`name`, `slug`, `type`, `status`)
SELECT 'Putty', 'paint-putty', 'product', 'active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `categories` WHERE `slug` = 'paint-putty');

INSERT INTO `categories` (`name`, `slug`, `type`, `status`)
SELECT 'Waterproofing', 'paint-waterproofing', 'product', 'active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `categories` WHERE `slug` = 'paint-waterproofing');

-- ── unit_config consumed by the OrderabilityResolver layer-4 hook ────────────
INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_unit_config',
   '{"units":{"paint":{"enabled":true,"custom_tint_enabled":false,"label":"Paint"}},"paint_category_slugs":["paint-interior-emulsion","paint-exterior-emulsion","paint-enamel","paint-distemper","paint-primer","paint-putty","paint-waterproofing"]}',
   'text',
   'Commerce unit config: per-unit enable + flags (paint.custom_tint_enabled OFF until Prisma) + category→unit map',
   0);
