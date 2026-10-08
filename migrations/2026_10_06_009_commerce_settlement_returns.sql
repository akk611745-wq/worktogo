-- ============================================================================
--  WorkToGo Commerce — S9: settlement payout + returns/refund + accounting
--  Migration: 2026_10_06_009_commerce_settlement_returns
-- ----------------------------------------------------------------------------
--  commerce-system-blueprint §P / §8. Reuses LedgerEngine (runSettlement moves
--  vendor pending→available; processRefund reverses the ledger + triggers the
--  gateway refund for online) and stores.ownership (own vs facilitated). Adds:
--    1. commerce_payouts — the payout RECORD (bank/UPI, weekly) a settlement run
--       produces (balance → actual payout), so money leaving the platform is logged.
--    2. commerce_returns — source-aware return/refund lifecycle (requested →
--       approved → received → refunded/rejected), with risk class + method +
--       who-bears-return-cost.
--  All thresholds configurable (app_settings). Additive; 10.4-safe; idempotent.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `commerce_payouts` (
  `id`           bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `payee_type`   varchar(16) NOT NULL DEFAULT 'vendor',   -- vendor | rider (future)
  `payee_id`     bigint(20) UNSIGNED NOT NULL,
  `amount`       decimal(12,2) NOT NULL,
  `method`       varchar(16) NOT NULL DEFAULT 'bank',      -- bank | upi
  `status`       varchar(16) NOT NULL DEFAULT 'initiated', -- initiated | paid | failed
  `reference`    varchar(80) NULL DEFAULT NULL,
  `period_start` datetime NULL DEFAULT NULL,
  `period_end`   datetime NULL DEFAULT NULL,
  `created_at`   datetime NOT NULL DEFAULT current_timestamp(),
  `paid_at`      datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cp_payee` (`payee_type`, `payee_id`, `status`),
  KEY `idx_cp_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `commerce_returns` (
  `id`                 bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`           bigint(20) UNSIGNED NOT NULL,
  `order_item_id`      bigint(20) UNSIGNED NULL DEFAULT NULL,
  `source_vendor_id`   bigint(20) UNSIGNED NULL DEFAULT NULL,
  `source_store_id`    bigint(20) UNSIGNED NULL DEFAULT NULL,
  `reason`             varchar(255) NULL DEFAULT NULL,
  `risk_class`         varchar(24) NOT NULL DEFAULT 'returnable_if_sealed',
  `status`             varchar(16) NOT NULL DEFAULT 'requested', -- requested|approved|received|refunded|rejected
  `refund_amount`      decimal(12,2) NULL DEFAULT NULL,
  `refund_method`      varchar(24) NULL DEFAULT NULL,  -- gateway | cod_store_credit | cod_cash
  `return_cost_bearer` varchar(16) NOT NULL DEFAULT 'platform',
  `created_at`         datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`         datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `resolved_at`        datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cr_order` (`order_id`),
  KEY `idx_cr_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_return_window_hours', '48', 'number', 'Hours after delivery a return may be requested (0 = none)', 0),
  ('commerce_return_hold_hours',   '48', 'number', 'Hold vendor earnings this long before payout (covers returns)', 0),
  ('commerce_payout_frequency',    'weekly', 'text', 'Vendor payout cadence (ops/cron trigger)', 0),
  ('commerce_payout_methods',      'bank,upi', 'text', 'Allowed payout methods', 0),
  ('commerce_return_risk',         '{"default":"returnable_if_sealed","no_return":[],"perishable":[]}', 'json',
   'Per-category return risk class (category_id lists; else default)', 0);
