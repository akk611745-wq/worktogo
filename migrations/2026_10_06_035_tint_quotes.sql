-- ============================================================================
--  WorkToGo Commerce — Paint PB10a: custom-tint quote machinery
--  Migration: 2026_10_06_035_tint_quotes
-- ----------------------------------------------------------------------------
--  PURPOSE: the request→quote lifecycle for a made-to-order tinted shade. The
--  customer picks brand + shade CODE, sees an instant ESTIMATE, and the request
--  lands on a seller as a quote box (accept + enter colour price within
--  commerce_quote_timeout_sec — PB6). No answer → reroute to the next seller.
--  Pricing math / advance (PB10b) and the price band / repeat-lock (PB10c) build
--  on this row. Dormant until a brand is enabled (commerce_custom_tint_brands).
--  ADDITIVE; reuses CustomTint + the shade fandeck; no engine edit. B3-compliant.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `commerce_tint_quotes` (
  `id`              bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         bigint(20) UNSIGNED NULL,
  `customer_ref`    varchar(40) NULL,
  `brand`           varchar(80) NOT NULL,
  `shade_code`      varchar(40) NOT NULL,
  `shade_name`      varchar(120) NULL,
  `base_type`       varchar(40) NULL,            -- seller fills from the shade book; never faked
  `product_id`      bigint(20) UNSIGNED NULL,    -- the parent paint product
  `litres`          decimal(8,2) NOT NULL DEFAULT 0.00,
  `pack_size`       varchar(16) NULL,
  `pack_unit`       varchar(8)  NULL,
  `mode`            enum('new','repaint') NOT NULL DEFAULT 'new',
  `estimate`        decimal(12,2) NULL,
  `colour_price`    decimal(12,2) NULL,          -- seller's raw colour price (PB10b prices it)
  `margin`          decimal(12,2) NULL,
  `final_price`     decimal(12,2) NULL,          -- PB10b
  `locked_per_litre` decimal(12,4) NULL,         -- PB10c repeat-lock
  `status`          enum('quoted','priced','accepted','expired','rerouted','needs_ops','cancelled','ordered') NOT NULL DEFAULT 'quoted',
  `vendor_id`       bigint(20) UNSIGNED NULL,
  `store_id`        bigint(20) UNSIGNED NULL,
  `city_id`         bigint(20) UNSIGNED NULL,
  `reroute_count`   int(11) NOT NULL DEFAULT 0,
  `consent`         tinyint(1) NOT NULL DEFAULT 0,
  `order_id`        bigint(20) UNSIGNED NULL,
  `created_at`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tq_status` (`status`),
  KEY `idx_tq_vendor` (`vendor_id`, `status`),
  KEY `idx_tq_user` (`user_id`, `brand`, `shade_code`)
) ENGINE=InnoDB;

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_tint_estimate_per_litre', '250', 'number', 'Fallback custom-tint estimate (₹ per litre) until the price band has data', 0);
