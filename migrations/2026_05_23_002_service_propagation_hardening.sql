-- WorkToGo — Final internal service propagation hardening
-- Safe additive/contract migration only: allows admin_queue/requeue null assignment states.

ALTER TABLE `bookings`
  MODIFY COLUMN `vendor_id` BIGINT(20) UNSIGNED NULL;

ALTER TABLE `jobs`
  MODIFY COLUMN `vendor_id` BIGINT(20) UNSIGNED NULL;

CREATE INDEX IF NOT EXISTS `idx_jobs_booking_status_vendor`
  ON `jobs` (`booking_id`, `status`, `vendor_id`);

CREATE INDEX IF NOT EXISTS `idx_transactions_reference_status`
  ON `transactions` (`reference_type`, `reference_id`, `status`);
