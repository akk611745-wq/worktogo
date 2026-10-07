-- ============================================================================
--  WorkToGo Commerce — S6: delivery handoff contract + proof/OTP
--  Migration: 2026_10_06_006_commerce_delivery_handoff
-- ----------------------------------------------------------------------------
--  commerce-system-blueprint §F. The native DeliveryEngine already does pricing,
--  nearest-rider auto-assign, accept/reject/pickup/deliver, reassign, cancel,
--  track; the lifecycle cron already retries no-rider + flags stuck/late. This
--  migration adds the HANDOFF CONTRACT fields (so delivery can pick the right
--  vehicle + know prepaid vs COD) and PROOF/OTP capture — the two genuine gaps.
--  Additive/nullable on the shared `deliveries` table; 10.4-safe; idempotent.
--  All thresholds/timeouts are configurable (app_settings), never hardcoded.
-- ============================================================================

ALTER TABLE `deliveries`
  ADD COLUMN IF NOT EXISTS `total_weight_kg`   decimal(8,2)  NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `required_vehicle`  varchar(24)   NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `is_prepaid`        tinyint(1)    NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `cod_amount`        decimal(12,2) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `ready_vs_custom`   varchar(12)   NULL DEFAULT 'ready',
  ADD COLUMN IF NOT EXISTS `pickup_proof`      varchar(255)  NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `delivery_proof`    varchar(255)  NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `delivery_otp_hash` varchar(255)  NULL DEFAULT NULL;

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_vehicle_thresholds',
   '[{"max_kg":20,"vehicle":"bike"},{"max_kg":80,"vehicle":"e_rickshaw"},{"max_kg":null,"vehicle":"loader"}]',
   'json', 'Weight (kg) → delivery vehicle thresholds (ordered; null max = catch-all)', 0),
  ('commerce_delivery_accept_timeout_sec', '120', 'number', 'Seconds an assigned rider has to accept before auto-reassign', 0),
  ('commerce_delivery_max_reassign', '3', 'number', 'Max auto-reassign attempts before a delivery is flagged no_rider (ops)', 0);
