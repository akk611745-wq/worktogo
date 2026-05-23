-- WorkToGo — Service vendor operational eligibility support
-- Safe additive migration only. Reuses vendors + bookings + existing slots.

ALTER TABLE `vendors`
  ADD COLUMN IF NOT EXISTS `service_localities` TEXT NULL AFTER `lng`,
  ADD COLUMN IF NOT EXISTS `service_area_notes` VARCHAR(255) NULL AFTER `service_localities`;

CREATE INDEX IF NOT EXISTS `idx_vendors_service_status_online`
  ON `vendors` (`status`, `is_online`);

CREATE INDEX IF NOT EXISTS `idx_bookings_vendor_schedule_status`
  ON `bookings` (`vendor_id`, `scheduled_at`, `status`);
