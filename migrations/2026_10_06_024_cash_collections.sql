-- ============================================================================
--  WorkToGo Commerce — Paint PB5: rider cash-collection audit
--  Migration: 2026_10_06_024_cash_collections
-- ----------------------------------------------------------------------------
--  PURPOSE: when a rider marks the cash they collected at the door, record it
--  against the expected amount (server-computed by CommercePayment::collectAtDoor).
--  A mismatch is flagged here now; the PB9 ops queue surfaces flagged rows later.
--  ADDITIVE; no engine edit. B3-compliant.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `commerce_cash_collections` (
  `id`          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`    bigint(20) UNSIGNED NOT NULL,
  `delivery_id` bigint(20) UNSIGNED NULL,
  `rider_id`    bigint(20) UNSIGNED NOT NULL,
  `expected`    decimal(12,2) NOT NULL DEFAULT 0.00,
  `collected`   decimal(12,2) NOT NULL DEFAULT 0.00,
  `mismatch`    tinyint(1) NOT NULL DEFAULT 0,
  `created_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cc_order` (`order_id`),
  KEY `idx_cc_rider` (`rider_id`),
  KEY `idx_cc_mismatch` (`mismatch`)
) ENGINE=InnoDB;
