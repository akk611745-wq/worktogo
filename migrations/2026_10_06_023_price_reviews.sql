-- ============================================================================
--  WorkToGo Commerce — Paint PB3a: price-jump review queue
--  Migration: 2026_10_06_023_price_reviews
-- ----------------------------------------------------------------------------
--  PURPOSE: a live pack price edit beyond an admin % (commerce_price_jump_pct,
--  default 30, up or down) does NOT go live — it waits here for admin review.
--  Small edits stay instant; stock edits are always instant.
--  ADDITIVE; no engine edit. B3-compliant.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `commerce_price_reviews` (
  `id`          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`  bigint(20) UNSIGNED NOT NULL,
  `vendor_id`   bigint(20) UNSIGNED NOT NULL,
  `old_base`    decimal(10,2) NULL,
  `new_base`    decimal(10,2) NOT NULL,
  `status`      enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reason`      varchar(240) NULL,
  `reviewed_by` bigint(20) UNSIGNED NULL,
  `created_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewed_at` datetime NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pr_status` (`status`),
  KEY `idx_pr_prod` (`product_id`)
) ENGINE=InnoDB;

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_price_jump_pct', '30', 'text', 'Live pack price-edit jump % (up/down) beyond which the change waits for admin review', 0);
