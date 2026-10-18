-- ============================================================================
--  WorkToGo Commerce — Paint PB15: customer saved-address book
--  Migration: 2026_10_06_028_user_addresses
-- ----------------------------------------------------------------------------
--  PURPOSE: a customer saves multiple delivery addresses (Home / Shop / Site),
--  one default, and picks one at checkout. LANDMARK is required (riders need it);
--  an optional GPS pin (lat/lng) rides along. The pincode is validated against
--  PB14 serviceability at save. The chosen address becomes the order's
--  shipping_address JSON (full, incl. landmark + pin) so the rider sees it all.
--  ADDITIVE; no engine edit. B3-compliant.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `commerce_user_addresses` (
  `id`         bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    bigint(20) UNSIGNED NOT NULL,
  `label`      varchar(16) NOT NULL DEFAULT 'home',
  `name`       varchar(120) NOT NULL,
  `phone`      varchar(20)  NOT NULL,
  `line1`      varchar(240) NOT NULL,
  `landmark`   varchar(160) NOT NULL,
  `city`       varchar(120) NOT NULL,
  `state`      varchar(120) NOT NULL,
  `pincode`    varchar(10)  NOT NULL,
  `city_id`    bigint(20) UNSIGNED NULL,
  `lat`        decimal(10,7) NULL,
  `lng`        decimal(10,7) NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_at` datetime NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ua_user` (`user_id`, `deleted_at`),
  KEY `idx_ua_default` (`user_id`, `is_default`)
) ENGINE=InnoDB;
