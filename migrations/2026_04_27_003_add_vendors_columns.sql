-- Migration: Add new columns to vendors table and rename vendor_type to type

ALTER TABLE `vendors`
ADD COLUMN IF NOT EXISTS `deleted_at` TIMESTAMP NULL,
ADD COLUMN IF NOT EXISTS `description` TEXT,
ADD COLUMN IF NOT EXISTS `logo_url` VARCHAR(500),
ADD COLUMN IF NOT EXISTS `total_reviews` INT DEFAULT 0,
ADD COLUMN IF NOT EXISTS `address_id` BIGINT UNSIGNED NULL;

ALTER TABLE `vendors`
CHANGE COLUMN IF EXISTS `vendor_type` `type` ENUM('service', 'shopping') NOT NULL;
