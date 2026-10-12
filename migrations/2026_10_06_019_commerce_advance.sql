-- ============================================================================
--  WorkToGo Commerce — Paint P4: advance / partial-payment seam
--  Migration: 2026_10_06_019_commerce_advance
-- ----------------------------------------------------------------------------
--  PURPOSE (gap #1): custom-tint needs an ADVANCE (part-capture) before the
--  order submits, with the balance collected COD at the door. The shared
--  webhook (PaymentService) today fails any capture whose amount != the full
--  order total. This adds a commerce-scoped advance ledger so a part-capture
--  can mark the order `partially_paid` and track the balance — WITHOUT touching
--  the bookings exact-amount path (bookings never get an advance row).
--
--    commerce_order_payments: one 'advance' row per order
--      expected_amount  — the advance the gateway should capture
--      captured_amount  — what the webhook actually captured
--      balance_amount   — total − captured (collected COD at delivery)
--      status           — pending → captured (or failed/cancelled/settled)
--
--  SAFETY: new table only + one config key (INSERT IGNORE). FK to orders with
--    ON DELETE CASCADE. ADDITIVE; no engine edit. B3-compliant (no DEFAULT
--    CHARSET; inherits the DB collation).
-- ============================================================================

CREATE TABLE IF NOT EXISTS `commerce_order_payments` (
  `id`              bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`        bigint(20) UNSIGNED NOT NULL,
  `kind`            varchar(16) NOT NULL DEFAULT 'advance',
  `expected_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `captured_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `balance_amount`  decimal(10,2) NOT NULL DEFAULT 0.00,
  `status`          enum('pending','captured','settled','failed','cancelled') NOT NULL DEFAULT 'pending',
  `gateway_txn_id`  varchar(191) NULL,
  `created_at`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_order_kind` (`order_id`, `kind`),
  KEY `idx_cop_status` (`status`),
  CONSTRAINT `fk_cop_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_advance_default_pct', '50', 'text', 'Custom-tint advance % required before order submits (default 50; balance COD at door)', 0);
