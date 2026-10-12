-- ============================================================================
--  WorkToGo Commerce — Paint P5: custom-tint STRUCTURE (data-empty + OFF)
--  Migration: 2026_10_06_020_custom_tint
-- ----------------------------------------------------------------------------
--  PURPOSE: build the custom-tint spine NOW, fill the data later (Prisma, P6),
--  keep it OFF in config (commerce_unit_config.paint.custom_tint_enabled=false,
--  from P1) so ready paint runs and custom tint stays dark until the real
--  shade/base/code data lands.
--
--    commerce_paint_shades    — the fandeck: brand + shade_code → shade_name,
--                               base_type, finish. DATA-EMPTY until Prisma.
--                               Powers the brand-code guard (a code must exist
--                               WITHIN the chosen brand, else it's rejected).
--    commerce_tint_order_log  — "this customer got this brand+code from this
--                               seller" → repeat routes to the same seller so
--                               the shade matches. DATA-EMPTY (no tint orders).
--
--  Config: commerce_confirm_call (ops confirm-call on/off + thresholds) and
--  commerce_custom_tint_consent_required (the "I understand this is a custom
--  shade" checkbox). Advance % already lives in commerce_advance_default_pct (P4).
--
--  SAFETY: new tables + config only (INSERT IGNORE). ADDITIVE; no engine edit.
--    B3-compliant (no DEFAULT CHARSET; inherits the DB collation).
-- ============================================================================

CREATE TABLE IF NOT EXISTS `commerce_paint_shades` (
  `id`           bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand`        varchar(80)  NOT NULL,
  `product_line` varchar(120) NULL,
  `shade_code`   varchar(40)  NOT NULL,
  `shade_name`   varchar(120) NULL,
  `base_type`    varchar(40)  NULL,
  `finish`       varchar(24)  NULL,
  `source`       varchar(40)  NOT NULL DEFAULT 'prisma',
  `created_at`   datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_shade_brand_code` (`brand`, `shade_code`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `commerce_tint_order_log` (
  `id`           bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      bigint(20) UNSIGNED NULL,
  `customer_ref` varchar(40)  NULL,
  `brand`        varchar(80)  NOT NULL,
  `shade_code`   varchar(40)  NOT NULL,
  `base_type`    varchar(40)  NULL,
  `vendor_id`    bigint(20) UNSIGNED NOT NULL,
  `order_id`     bigint(20) UNSIGNED NULL,
  `created_at`   datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tintlog_cust` (`user_id`, `brand`, `shade_code`)
) ENGINE=InnoDB;

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_confirm_call', '{"enabled":false,"min_value":5000,"custom_always":true}', 'text', 'Ops confirm-call: on/off + min order value + always-for-custom', 0),
  ('commerce_custom_tint_consent_required', '1', 'text', 'Require the "I understand this is a custom shade" consent before a custom-tint order', 0);
