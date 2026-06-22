-- WorkToGo — Vendor trust fields: verified badge + experience years
-- Adds two nullable/defaulted columns to `vendors`. No data backfill needed,
-- no existing rows affected beyond the new default values.
-- Run via phpMyAdmin (manual) — migrate.php is blocked on live.

ALTER TABLE `vendors` ADD COLUMN `is_verified` TINYINT(1) DEFAULT 0;
ALTER TABLE `vendors` ADD COLUMN `experience_years` TINYINT UNSIGNED DEFAULT NULL;
