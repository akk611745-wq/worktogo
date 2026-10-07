-- ============================================================================
--  WorkToGo Commerce — S4: event bus + order timeline + ops/support flags
--  Migration: 2026_10_06_004_commerce_events
-- ----------------------------------------------------------------------------
--  commerce-system-blueprint §G (nervous system). The HOOKS every later step
--  reads (ops/support console, notifications, owner view):
--    1. commerce_events       — append-only ORDER TIMELINE (every lifecycle event
--       with actor + from/to state + payload). "No order ever invisible."
--    2. commerce_order_flags  — ops/support hooks (needs_ops / payment_issue /
--       high_value_hold / stuck): raised by the bus, cleared on resolution.
--  User-facing notifications REUSE the shared `notifications` table (per-user),
--  so we do not duplicate notification infra. Thresholds live in app_settings
--  (configurable). Additive; 10.4-safe (plain JSON longtext); no FKs on the logs
--  (lightweight append; keys indexed); inherits DB default collation.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `commerce_events` (
  `id`           bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`     bigint(20) UNSIGNED DEFAULT NULL,
  `sub_order_id` bigint(20) UNSIGNED DEFAULT NULL,
  `event_type`   varchar(48) NOT NULL,
  `actor_type`   varchar(24) DEFAULT NULL,   -- system|seller|customer|rider|ops|admin
  `actor_id`     bigint(20) UNSIGNED DEFAULT NULL,
  `from_state`   varchar(32) DEFAULT NULL,
  `to_state`     varchar(32) DEFAULT NULL,
  `payload`      longtext DEFAULT NULL,
  `created_at`   datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ce_order` (`order_id`, `created_at`),
  KEY `idx_ce_type` (`event_type`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `commerce_order_flags` (
  `id`         bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`   bigint(20) UNSIGNED NOT NULL,
  `flag`       varchar(48) NOT NULL,
  `active`     tinyint(1) NOT NULL DEFAULT 1,
  `reason`     varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `cleared_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cof_order_active` (`order_id`, `active`),
  KEY `idx_cof_flag_active` (`flag`, `active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_high_value_threshold', '5000', 'number', 'Order total at/above which it is flagged for human approval (0 = off)', 0);
