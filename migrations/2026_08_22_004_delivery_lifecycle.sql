-- ============================================================================
--  WorkToGo V2 — Commerce Fix Phase 1: delivery lifecycle / edge-case columns
--  Migration: 2026_08_22_004_delivery_lifecycle
-- ----------------------------------------------------------------------------
--  PURPOSE (audit commerce-08 §2): the native delivery engine had no lifecycle
--  handling — a no-rider order sat pending forever, an assigned/picked delivery
--  could get stuck with nobody noticing, and lateness was undetected. This adds
--  the delivery-owned columns the lifecycle cron (cron/delivery_lifecycle.php)
--  needs to detect and flag those states, plus admin-tunable thresholds.
--
--    deliveries.flag             none|no_rider|stuck|late — surfaced in the admin
--                                 control-room "needs attention" view
--    deliveries.flagged_at       when the current flag was raised (once per flag)
--    deliveries.assign_attempts  no-rider retry counter (cron increments)
--    deliveries.last_attempt_at  last assignment attempt (retry pacing)
--
--  CLEAN SCOPE: delivery-owned columns only; no commerce/service table touched.
--  DEPENDS ON: 2026_08_22_003 (deliveries pricing/geo columns).
--  SAFETY: ADD COLUMN/KEY IF NOT EXISTS; threshold seed is NOT-EXISTS guarded.
--  STAGING only — do NOT run on prod from here; verify, then schedule.
-- ============================================================================

ALTER TABLE `deliveries`
  ADD COLUMN IF NOT EXISTS `flag` enum('none','no_rider','stuck','late') NOT NULL DEFAULT 'none' AFTER `eta_minutes`,
  ADD COLUMN IF NOT EXISTS `flagged_at` datetime NULL DEFAULT NULL AFTER `flag`,
  ADD COLUMN IF NOT EXISTS `assign_attempts` int(11) NOT NULL DEFAULT 0 AFTER `flagged_at`,
  ADD COLUMN IF NOT EXISTS `last_attempt_at` datetime NULL DEFAULT NULL AFTER `assign_attempts`,
  ADD KEY IF NOT EXISTS `idx_deliveries_flag` (`flag`, `status`),
  ADD KEY IF NOT EXISTS `idx_deliveries_lifecycle` (`status`, `assigned_at`, `picked_at`);

-- ── Admin-tunable lifecycle thresholds (seed once; edit in platform_settings) ─
INSERT INTO `platform_settings` (`key`, `value`, `type`, `label`)
SELECT * FROM (SELECT 'delivery_lifecycle_enabled' AS k, '1' AS v, 'boolean' AS t, 'Delivery lifecycle cron enabled' AS l) x
WHERE NOT EXISTS (SELECT 1 FROM `platform_settings` p WHERE p.`key` = 'delivery_lifecycle_enabled');
INSERT INTO `platform_settings` (`key`, `value`, `type`, `label`)
SELECT * FROM (SELECT 'delivery_no_rider_retry_sec','30','integer','Retry rider assignment every N seconds') x
WHERE NOT EXISTS (SELECT 1 FROM `platform_settings` p WHERE p.`key` = 'delivery_no_rider_retry_sec');
INSERT INTO `platform_settings` (`key`, `value`, `type`, `label`)
SELECT * FROM (SELECT 'delivery_no_rider_alert_min','5','integer','Alert admin if no rider after N minutes') x
WHERE NOT EXISTS (SELECT 1 FROM `platform_settings` p WHERE p.`key` = 'delivery_no_rider_alert_min');
INSERT INTO `platform_settings` (`key`, `value`, `type`, `label`)
SELECT * FROM (SELECT 'delivery_accept_timeout_min','5','integer','Flag stuck if a rider does not accept within N minutes') x
WHERE NOT EXISTS (SELECT 1 FROM `platform_settings` p WHERE p.`key` = 'delivery_accept_timeout_min');
INSERT INTO `platform_settings` (`key`, `value`, `type`, `label`)
SELECT * FROM (SELECT 'delivery_pickup_stuck_min','20','integer','Flag stuck if not picked up within N minutes of assignment') x
WHERE NOT EXISTS (SELECT 1 FROM `platform_settings` p WHERE p.`key` = 'delivery_pickup_stuck_min');
INSERT INTO `platform_settings` (`key`, `value`, `type`, `label`)
SELECT * FROM (SELECT 'delivery_deliver_stuck_min','45','integer','Flag stuck if not delivered within N minutes of pickup') x
WHERE NOT EXISTS (SELECT 1 FROM `platform_settings` p WHERE p.`key` = 'delivery_deliver_stuck_min');
INSERT INTO `platform_settings` (`key`, `value`, `type`, `label`)
SELECT * FROM (SELECT 'delivery_late_grace_min','15','integer','Flag late once now exceeds ETA by N minutes') x
WHERE NOT EXISTS (SELECT 1 FROM `platform_settings` p WHERE p.`key` = 'delivery_late_grace_min');

-- ============================================================================
--  VERIFICATION (staging)
--  SHOW COLUMNS FROM deliveries LIKE 'flag';                     -- 1 row
--  SHOW COLUMNS FROM deliveries LIKE 'assign_attempts';          -- 1 row
--  SELECT `key`,`value` FROM platform_settings WHERE `key` LIKE 'delivery_%';  -- 7 rows
-- ============================================================================
