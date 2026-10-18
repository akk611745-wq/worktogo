-- ============================================================================
--  WorkToGo Commerce — Paint PB7: alerts "nervous system"
--  Migration: 2026_10_06_030_notifier
-- ----------------------------------------------------------------------------
--  PURPOSE: fan every order event out to the right person on the right channel
--  (in-app + FCM push + MSG91 SMS/WhatsApp), each with its own tone, and LOG
--  every attempt so a channel failure is recorded and NEVER blocks an order.
--   - commerce_notification_log : one row per (event, recipient, channel) attempt.
--   - commerce_notify_channels  : admin on/off per channel (JSON).
--  ADDITIVE; no engine edit. B3-compliant.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `commerce_notification_log` (
  `id`         bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`   bigint(20) UNSIGNED NULL,
  `user_id`    bigint(20) UNSIGNED NULL,
  `audience`   varchar(12) NULL,     -- customer | seller | rider
  `channel`    varchar(12) NOT NULL, -- inapp | push | sms | whatsapp
  `event_type` varchar(40) NOT NULL,
  `tone`       varchar(32) NULL,
  `priority`   varchar(12) NULL,     -- normal | high | critical
  `status`     varchar(12) NOT NULL, -- sent | queued | skipped | failed
  `error`      varchar(240) NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_nl_order` (`order_id`),
  KEY `idx_nl_event` (`event_type`),
  KEY `idx_nl_status` (`status`)
) ENGINE=InnoDB;

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_notify_channels', '{"inapp":1,"push":1,"sms":1,"whatsapp":1}', 'json', 'Order-alert channels turned on (admin toggles)', 0);
