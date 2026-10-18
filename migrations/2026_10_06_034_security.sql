-- ============================================================================
--  WorkToGo Commerce — Paint PB12: security hardening (additive guards only)
--  Migration: 2026_10_06_034_security
-- ----------------------------------------------------------------------------
--  PURPOSE: COD-abuse controls + abandoned-order cleanup config. All additive;
--  no engine edit, no behaviour change for honest users.
--   - commerce_cod_strikes : per-customer no-pay strikes + a block flag.
--   - config: open-COD cap, no-pay strike limit, abandoned-order age, media cap.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `commerce_cod_strikes` (
  `user_id`    bigint(20) UNSIGNED NOT NULL,
  `strikes`    int(11) NOT NULL DEFAULT 0,
  `blocked`    tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB;

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_cod_open_cap', '3', 'number', 'Max open (undelivered) COD orders a customer may have at once', 0),
  ('commerce_cod_nopay_limit', '3', 'number', 'COD no-pay strikes before a customer is blocked from COD', 0),
  ('commerce_abandoned_order_min', '30', 'number', 'Minutes an unpaid online order may hold stock before cleanup cancels it', 0),
  ('commerce_media_max_mb', '5', 'number', 'Max upload size (MB) for product / KYC media', 0);
