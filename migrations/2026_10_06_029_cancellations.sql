-- ============================================================================
--  WorkToGo Commerce — Paint PB16: pre-delivery cancellation + cost-bearer rule
--  Migration: 2026_10_06_029_cancellations
-- ----------------------------------------------------------------------------
--  PURPOSE: record every cancellation with its stage and WHO BEARS THE COST
--  (the money reversal itself reuses LedgerEngine::processRefund on the order
--  branch). Stage rules + fees are admin-config (app_settings below):
--    placed  (before a seller accepts) → customer cancels FREE.
--    accepted(after accept, before pickup) → admin-set fee (flat + / or %).
--    picked  → no cancel; the return flow (s9) takes over.
--  Seller cancel after accept → reroute + a seller-score penalty signal.
--  Custom-tint advance is non-refundable once making has started (existing rule).
--  ADDITIVE; no engine edit. B3-compliant.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `commerce_cancellations` (
  `id`            bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`      bigint(20) UNSIGNED NOT NULL,
  `sub_order_id`  bigint(20) UNSIGNED NULL,
  `stage`         varchar(16) NOT NULL,
  `actor`         varchar(12) NOT NULL,                 -- customer | seller | admin
  `cost_bearer`   varchar(12) NOT NULL DEFAULT 'none',  -- none | customer | seller | platform
  `fee`           decimal(12,2) NOT NULL DEFAULT 0.00,
  `refund_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `reason`        varchar(240) NULL,
  `created_at`    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cxl_order` (`order_id`),
  KEY `idx_cxl_actor` (`actor`)
) ENGINE=InnoDB;

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_cancel_fee_after_accept', '0', 'number', 'Flat cancellation fee (₹) when a customer cancels after the seller accepted, before pickup', 0),
  ('commerce_cancel_fee_pct', '0', 'number', 'Cancellation fee as % of order total (after accept, before pickup); added to the flat fee', 0),
  ('commerce_cancel_seller_penalty', '1', 'number', 'Seller-score penalty weight for a post-accept seller cancellation', 0);
