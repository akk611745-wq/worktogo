-- Migration: Add image_url, module, is_active, and sort_order columns to categories table

ALTER TABLE `categories`
ADD COLUMN IF NOT EXISTS `image_url` VARCHAR(500) NULL,
ADD COLUMN IF NOT EXISTS `module` VARCHAR(100) NULL,
ADD COLUMN IF NOT EXISTS `is_active` TINYINT(1) DEFAULT 1,
ADD COLUMN IF NOT EXISTS `sort_order` INT DEFAULT 0;
