-- ============================================================================
--  WorkToGo Commerce — S7: Ops/Support console hooks (tickets + comms)
--  Migration: 2026_10_06_007_commerce_ops
-- ----------------------------------------------------------------------------
--  commerce-system-blueprint §K / §Support. The exception QUEUE is the S4
--  `commerce_order_flags` (needs_ops / payment_issue / high_value_hold / …) read
--  as tickets. This adds the ticket OWNER + ESCALATION fields, an order-tied
--  MESSAGES table (customer↔ops / ops↔seller / ops↔rider), and the configurable
--  SLA. Ops ACTIONS (resolve / approve-hold / reassign / cancel) reuse the event
--  bus + DeliveryEngine::adminReassign + OrderService::cancel. Additive; 10.4-safe.
-- ============================================================================

ALTER TABLE `commerce_order_flags`
  ADD COLUMN IF NOT EXISTS `owner_user_id` bigint(20) UNSIGNED NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `escalated_at`  datetime NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `commerce_messages` (
  `id`         bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`   bigint(20) UNSIGNED NOT NULL,
  `from_type`  varchar(16) NOT NULL,   -- customer | ops | seller | rider | system
  `from_id`    bigint(20) UNSIGNED NULL,
  `to_type`    varchar(16) NULL,
  `body`       varchar(1000) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cm_order` (`order_id`, `created_at`)
) ENGINE=InnoDB;

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_ticket_sla_sec', '900', 'number', 'Exception-ticket SLA seconds (breached when older and unresolved)', 0);
