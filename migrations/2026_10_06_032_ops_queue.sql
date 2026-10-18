-- ============================================================================
--  WorkToGo Commerce — Paint PB9: human-support escalation queue (ops console)
--  Migration: 2026_10_06_032_ops_queue
-- ----------------------------------------------------------------------------
--  PURPOSE: ONE queue that tells the human exactly what to decide + a suggested
--  action, fed from vehicle doubt (PB8), no-seller-accepted (routing exhausted),
--  rider cash mismatch (PB5), high-value hold, cancellation dispute, payment
--  stuck. Actions (assign/override/resolve) are logged.
--   - commerce_cash_collections gains resolved_at/resolved_by (ops reconciles).
--   - commerce_ops_actions : an audit of every ops decision from the queue.
--   - commerce_ops_auto_escalate : which kinds jump the queue automatically.
--  Reuses OpsConsole (claim/escalate/resolve) + the order-flags model.
--  ADDITIVE; no engine edit. B3-compliant.
-- ============================================================================

ALTER TABLE `commerce_cash_collections`
  ADD COLUMN IF NOT EXISTS `resolved_at` datetime NULL AFTER `mismatch`,
  ADD COLUMN IF NOT EXISTS `resolved_by` bigint(20) UNSIGNED NULL AFTER `resolved_at`;

CREATE TABLE IF NOT EXISTS `commerce_ops_actions` (
  `id`         bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind`       varchar(32) NOT NULL,
  `ref_id`     bigint(20) UNSIGNED NULL,
  `order_id`   bigint(20) UNSIGNED NULL,
  `action`     varchar(32) NOT NULL,
  `note`       varchar(400) NULL,
  `actor_id`   bigint(20) UNSIGNED NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_oa_order` (`order_id`),
  KEY `idx_oa_kind` (`kind`)
) ENGINE=InnoDB;

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_ops_auto_escalate', '["high_value_hold","needs_ops","payment_issue"]', 'json', 'Ops-queue kinds that auto-escalate (jump the queue)', 0);
