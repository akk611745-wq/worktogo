-- ============================================================================
--  WorkToGo Commerce — Paint PB8: vehicle doubt → seller suggestion → override
--  Migration: 2026_10_06_031_vehicle_doubt
-- ----------------------------------------------------------------------------
--  PURPOSE: when the weight→vehicle pick is UNCLEAR (weight unknown, near a
--  threshold boundary, multi-item, or high-value — all admin thresholds) the
--  auto pick is marked "needs confirm" instead of dispatching blind. The seller
--  can suggest a vehicle (they know the goods); admin can override either way.
--  Resolved → the final vehicle is written to the delivery and dispatch proceeds.
--   - commerce_vehicle_doubts : one open row per order needing a vehicle decision.
--  Reuses CommerceDelivery::requiredVehicle + the commerce_vehicle_thresholds list.
--  ADDITIVE; no engine edit. B3-compliant.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `commerce_vehicle_doubts` (
  `id`                bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`          bigint(20) UNSIGNED NOT NULL,       -- the (parent) order the delivery is on
  `sub_order_id`      bigint(20) UNSIGNED NULL,
  `auto_vehicle`      varchar(24) NULL,
  `suggested_vehicle` varchar(24) NULL,
  `suggested_by`      bigint(20) UNSIGNED NULL,
  `final_vehicle`     varchar(24) NULL,
  `options`           varchar(240) NULL,                  -- JSON list of vehicle choices
  `reason`            varchar(160) NULL,
  `weight_kg`         decimal(8,2) NULL,
  `status`            enum('pending','resolved') NOT NULL DEFAULT 'pending',
  `resolved_by`       bigint(20) UNSIGNED NULL,
  `resolved_role`     varchar(12) NULL,                   -- seller | admin
  `created_at`        datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_vd_order` (`order_id`),
  KEY `idx_vd_status` (`status`)
) ENGINE=InnoDB;

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_vehicle_doubt_near_pct', '15', 'number', 'Weight within this % of a vehicle threshold boundary → ask for confirmation', 0),
  ('commerce_vehicle_doubt_min_items', '3', 'number', 'Orders with at least this many line items → ask for vehicle confirmation', 0);
