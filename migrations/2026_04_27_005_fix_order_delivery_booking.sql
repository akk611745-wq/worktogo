-- 1. Add payment_id to orders table
ALTER TABLE `orders` 
ADD COLUMN IF NOT EXISTS `payment_id` VARCHAR(255) DEFAULT NULL AFTER `payment_method`;

-- 2. Add updated_at to deliveries table
ALTER TABLE `deliveries`
ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

-- 3. Add updated_at to booking_slot_reservations table
ALTER TABLE `booking_slot_reservations`
ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;
