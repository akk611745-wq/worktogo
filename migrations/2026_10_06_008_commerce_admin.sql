-- ============================================================================
--  WorkToGo Commerce — S8: Admin Control Room (zero-code live config + audit)
--  Migration: 2026_10_06_008_commerce_admin
-- ----------------------------------------------------------------------------
--  commerce-system-blueprint §L. Everything the control room tunes already lives
--  in app_settings (margins, timeouts, radius, thresholds, loss-proof floor,
--  global kill-switch commerce_enabled) + delivery_rate_cards + store/vendor/
--  rider state + wallet_transactions (money). This migration adds only:
--    1. commerce_config_audit — WHO changed WHICH value/rule, WHEN (old→new).
--    2. commerce_paused — unit/zone/seller kill-switch sets (global stays
--       commerce_enabled). Read by OrderabilityResolver so a pause is enforced.
--  Additive; 10.4-safe; idempotent. No hardcoded values — all admin-editable live.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `commerce_config_audit` (
  `id`            bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_user_id` bigint(20) UNSIGNED NULL DEFAULT NULL,
  `action`        varchar(24) NOT NULL,   -- set | pause | resume | override
  `setting_key`   varchar(100) NULL DEFAULT NULL,
  `target`        varchar(64) NULL DEFAULT NULL,   -- e.g. order:123, category:5, store:9, rider:7
  `old_value`     text NULL DEFAULT NULL,
  `new_value`     text NULL DEFAULT NULL,
  `reason`        varchar(255) NULL DEFAULT NULL,
  `created_at`    datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cca_created` (`created_at`),
  KEY `idx_cca_key` (`setting_key`)
) ENGINE=InnoDB;

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_paused', '{"categories":[],"cities":[],"stores":[]}', 'json',
   'Paused commerce scopes — unit (category) / zone (city) / seller (store) kill-switches', 0);
