-- ============================================================================
--  WorkToGo Commerce — Paint PB14: pincode / area serviceability
--  Migration: 2026_10_06_026_serviceability
-- ----------------------------------------------------------------------------
--  PURPOSE: a customer sees/orders only products deliverable to THEIR address.
--   - commerce_serviceability : admin-config serviceable areas.
--       scope='city'   → a pincode the PLATFORM serves in a city.
--       scope='seller' → a pincode a specific store delivers to (and/or a
--                        radius_km override from the shop).
--     A city with NO 'city' rows is served whole (single-city launch fallback,
--     so Haldwani works before pincodes are entered). A seller with NO 'seller'
--     pincode rows falls back to the existing store radius gate.
--   - commerce_service_requests : the "Notify me" demand list (pincode + phone)
--     from customers in an area we don't serve yet — admin's expand-next signal.
--  ADDITIVE; no engine edit. B3-compliant.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `commerce_serviceability` (
  `id`         bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope`      enum('city','seller') NOT NULL,
  `city_id`    bigint(20) UNSIGNED NULL,
  `store_id`   bigint(20) UNSIGNED NULL,
  `pincode`    varchar(10) NULL,
  `radius_km`  decimal(6,2) NULL,
  `active`     tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_svc_city` (`city_id`, `scope`, `active`),
  KEY `idx_svc_store` (`store_id`, `scope`, `active`),
  KEY `idx_svc_pin` (`pincode`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `commerce_service_requests` (
  `id`         bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `pincode`    varchar(10) NOT NULL,
  `phone`      varchar(20) NOT NULL,
  `city_id`    bigint(20) UNSIGNED NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sr_pin` (`pincode`),
  KEY `idx_sr_created` (`created_at`)
) ENGINE=InnoDB;
