-- ============================================================================
--  WorkToGo V2 — Commerce Phase 5: NATIVE delivery engine (own scope)
--  Migration: 2026_08_22_003_delivery_engine
-- ----------------------------------------------------------------------------
--  PURPOSE (COMMERCE/commerce-07-delivery.md)
--    Stand up a clean, native delivery engine on the motherboard's own pieces —
--    the deliveries + driver_wallets tables, users.role='delivery' identity,
--    LedgerEngine::isDriverBlocked cash logic, and the Haversine distance math —
--    and DISCARD the external SwiftDeliver dependency (the founder's unfinished
--    early build). This is COMPOSITION, not from scratch.
--
--    Delivery is a SEPARATE engine with its own scope. This migration adds only
--    delivery-owned schema:
--      1. delivery_rate_cards — admin-configurable pricing PER CITY (base + per-km
--         + min + free-above + rider/platform split). The system COMPUTES the
--         delivery price by distance (store → customer) from this card; admins set
--         the rates, no code change.
--      2. delivery_riders — a rider's OPERATIONAL state (online, live lat/lng, last
--         ping, capacity) kept in the delivery scope so it never pollutes `users`.
--         A rider is still a users.role='delivery' row; this is only their
--         live availability/location for nearest-rider assignment.
--      3. deliveries: pricing + geo + tracking columns (distance, price, splits,
--         pickup/drop lat-lng, tracking token, accepted/picked timestamps, ETA).
--
--    RBAC: reuses the delivery.view / delivery.manage permissions and the
--    dispatch_ops role already seeded in 2026_08_13_001 — no new perms here.
--
--  CLEAN SEPARATION: no commerce/service tables are altered. deliveries is the
--    delivery engine's own table (it only shares order_id/store_id/city_id keys
--    with the motherboard). Nothing here touches the never-touch order engine.
--
--  DEPENDS ON: 2026_07_04_001 (cities), 2026_08_22_001 (deliveries.store_id/
--    city_id, stores.lat/lng). migrate.php applies in filename order.
--
--  SAFETY / IDEMPOTENCY: CREATE TABLE IF NOT EXISTS; ADD COLUMN/KEY IF NOT EXISTS;
--    guarded FK adds via information_schema; INSERT ... ON DUPLICATE KEY for the
--    seed. No DROP, no data loss. InnoDB / utf8mb4. STAGING only — do NOT run on
--    prod from here; verify with the block at the bottom first.
-- ============================================================================


-- ── 1. delivery_rate_cards — admin-configurable pricing per city ────────────
CREATE TABLE IF NOT EXISTS `delivery_rate_cards` (
  `id`                   bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `city_id`              bigint(20) UNSIGNED DEFAULT NULL COMMENT 'NULL = platform default card',
  `name`                 varchar(120) NOT NULL DEFAULT 'Standard',
  `base_fare`            decimal(10,2) NOT NULL DEFAULT 20.00 COMMENT 'flat pickup fee',
  `per_km_rate`          decimal(10,2) NOT NULL DEFAULT 8.00  COMMENT 'charge per km (store -> customer)',
  `min_fee`              decimal(10,2) NOT NULL DEFAULT 20.00 COMMENT 'floor delivery fee',
  `free_above_subtotal`  decimal(10,2) DEFAULT NULL COMMENT 'free delivery when item subtotal >= this (NULL = never)',
  `rider_share_pct`      decimal(5,2)  NOT NULL DEFAULT 80.00 COMMENT '% of the delivery fee paid to the rider',
  `platform_margin_pct`  decimal(5,2)  NOT NULL DEFAULT 20.00 COMMENT '% of the delivery fee kept by the platform',
  `max_distance_km`      decimal(6,2)  DEFAULT NULL COMMENT 'beyond this, delivery unavailable (NULL = no cap)',
  `is_active`            tinyint(1) NOT NULL DEFAULT 1,
  `created_at`           datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`           datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ratecard_city` (`city_id`, `is_active`),
  CONSTRAINT `fk_ratecard_city` FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed a default card for Haldwani (editable from the delivery control-room).
INSERT INTO `delivery_rate_cards` (`city_id`, `name`, `base_fare`, `per_km_rate`, `min_fee`, `free_above_subtotal`, `rider_share_pct`, `platform_margin_pct`, `max_distance_km`, `is_active`)
SELECT c.`id`, 'Haldwani Standard', 20.00, 8.00, 20.00, 499.00, 80.00, 20.00, 15.00, 1
FROM `cities` c WHERE c.`slug` = 'haldwani'
  AND NOT EXISTS (SELECT 1 FROM `delivery_rate_cards` r WHERE r.`city_id` = c.`id`);


-- ── 2. delivery_riders — rider operational state (own scope) ────────────────
CREATE TABLE IF NOT EXISTS `delivery_riders` (
  `id`             bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `rider_id`       bigint(20) UNSIGNED NOT NULL COMMENT 'users.id with role=delivery',
  `city_id`        bigint(20) UNSIGNED DEFAULT NULL,
  `is_online`      tinyint(1) NOT NULL DEFAULT 0 COMMENT 'available to receive assignments',
  `lat`            decimal(10,7) DEFAULT NULL COMMENT 'last reported location',
  `lng`            decimal(10,7) DEFAULT NULL,
  `last_ping_at`   datetime DEFAULT NULL,
  `vehicle_type`   varchar(24) DEFAULT 'bike',
  `max_active`     int(11) NOT NULL DEFAULT 3 COMMENT 'max concurrent active deliveries',
  `active_count`   int(11) NOT NULL DEFAULT 0 COMMENT 'current active (assigned/picked) deliveries',
  `created_at`     datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`     datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_rider` (`rider_id`),
  KEY `idx_rider_avail` (`is_online`, `city_id`),
  KEY `idx_rider_geo` (`lat`, `lng`),
  CONSTRAINT `fk_rider_user` FOREIGN KEY (`rider_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rider_city` FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 3. deliveries: pricing + geo + tracking columns (additive, nullable) ────
ALTER TABLE `deliveries`
  ADD COLUMN IF NOT EXISTS `rate_card_id`  bigint(20) UNSIGNED NULL DEFAULT NULL AFTER `city_id`,
  ADD COLUMN IF NOT EXISTS `distance_km`   decimal(6,2)  NULL DEFAULT NULL AFTER `rate_card_id`,
  ADD COLUMN IF NOT EXISTS `price`         decimal(10,2) NULL DEFAULT NULL COMMENT 'delivery fee charged' AFTER `distance_km`,
  ADD COLUMN IF NOT EXISTS `rider_payout`  decimal(10,2) NULL DEFAULT NULL AFTER `price`,
  ADD COLUMN IF NOT EXISTS `platform_fee`  decimal(10,2) NULL DEFAULT NULL AFTER `rider_payout`,
  ADD COLUMN IF NOT EXISTS `pickup_lat`    decimal(10,7) NULL DEFAULT NULL AFTER `platform_fee`,
  ADD COLUMN IF NOT EXISTS `pickup_lng`    decimal(10,7) NULL DEFAULT NULL AFTER `pickup_lat`,
  ADD COLUMN IF NOT EXISTS `drop_lat`      decimal(10,7) NULL DEFAULT NULL AFTER `pickup_lng`,
  ADD COLUMN IF NOT EXISTS `drop_lng`      decimal(10,7) NULL DEFAULT NULL AFTER `drop_lat`,
  ADD COLUMN IF NOT EXISTS `tracking_token` varchar(40) NULL DEFAULT NULL AFTER `drop_lng`,
  ADD COLUMN IF NOT EXISTS `eta_minutes`   int(11) NULL DEFAULT NULL AFTER `tracking_token`,
  ADD COLUMN IF NOT EXISTS `accepted_at`   datetime NULL DEFAULT NULL AFTER `assigned_at`,
  ADD COLUMN IF NOT EXISTS `picked_at`     datetime NULL DEFAULT NULL AFTER `accepted_at`,
  ADD KEY IF NOT EXISTS `idx_deliveries_status_city` (`status`, `city_id`),
  ADD KEY IF NOT EXISTS `idx_deliveries_driver_status` (`driver_id`, `status`),
  ADD KEY IF NOT EXISTS `idx_deliveries_token` (`tracking_token`);

-- deliveries.rate_card_id -> delivery_rate_cards.id (guarded)
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME='deliveries' AND CONSTRAINT_NAME='fk_deliveries_ratecard');
SET @sql := IF(@fk=0, 'ALTER TABLE `deliveries` ADD CONSTRAINT `fk_deliveries_ratecard` FOREIGN KEY (`rate_card_id`) REFERENCES `delivery_rate_cards`(`id`) ON DELETE SET NULL ON UPDATE CASCADE', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ============================================================================
--  VERIFICATION (staging)
-- ----------------------------------------------------------------------------
--  SHOW TABLES LIKE 'delivery_rate_cards';                       -- 1 row
--  SHOW TABLES LIKE 'delivery_riders';                           -- 1 row
--  SELECT city_id, base_fare, per_km_rate, min_fee FROM delivery_rate_cards;  -- Haldwani seed
--  SHOW COLUMNS FROM deliveries LIKE 'price';                    -- 1 row
--  SHOW COLUMNS FROM deliveries LIKE 'tracking_token';           -- 1 row
--  -- FK present:
--  SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
--    WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='fk_deliveries_ratecard';
-- ============================================================================
