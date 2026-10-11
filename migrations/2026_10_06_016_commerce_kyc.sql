-- ============================================================================
--  WorkToGo Commerce — S18: KYC / onboarding (seller + rider)
--  Migration: 2026_10_06_016_commerce_kyc
-- ----------------------------------------------------------------------------
--  PURPOSE (core-completion follow-on; CEO S18): a seller cannot be paid and a
--  rider cannot be paid/assigned without KYC + a PAYOUT TARGET (bank/UPI). This
--  adds two KYC tables + a submit→review→approve workflow, plus the payout-target
--  columns S9 settlement needs. Documents are referenced by `media`.id (uploaded
--  through the existing media flow); kept as app-level nullable ids (no hard FK)
--  so KYC stays decoupled and additive. ADDITIVE only; no engine edit.
--
--  B3-compliant: no `DEFAULT CHARSET` (tables inherit the DB default collation).
--  10.x-safe: no COMMENT-after-CHECK, no CHECK constraints.
-- ============================================================================

-- 1. SELLER KYC (one per vendor)
CREATE TABLE IF NOT EXISTS `commerce_seller_kyc` (
  `id`                  bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `vendor_id`           bigint(20) UNSIGNED NOT NULL,
  `owner_name`          varchar(150) NULL,
  `shop_address`        varchar(500) NULL,
  `pincode`             varchar(12)  NULL,
  `lat`                 decimal(10,7) NULL,
  `lng`                 decimal(10,7) NULL,
  `gst_number`          varchar(20)  NULL,
  `enrolment_no`        varchar(40)  NULL COMMENT 'for unregistered sellers (no GST)',
  `pan`                 varchar(15)  NULL,
  `bank_account_name`   varchar(150) NULL,
  `bank_account_number` varchar(40)  NULL,
  `bank_ifsc`           varchar(15)  NULL,
  `upi_id`              varchar(80)  NULL,
  `operating_hours`     varchar(255) NULL,
  `service_area`        varchar(500) NULL,
  `shop_photo_media_id`    bigint(20) UNSIGNED NULL,
  `id_proof_media_id`      bigint(20) UNSIGNED NULL,
  `address_proof_media_id` bigint(20) UNSIGNED NULL,
  `status`              enum('draft','submitted','approved','rejected') NOT NULL DEFAULT 'draft',
  `review_note`         varchar(500) NULL,
  `submitted_at`        datetime NULL,
  `reviewed_at`         datetime NULL,
  `reviewed_by`         bigint(20) UNSIGNED NULL,
  `created_at`          datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_seller_kyc_vendor` (`vendor_id`),
  KEY `idx_seller_kyc_status` (`status`),
  CONSTRAINT `fk_seller_kyc_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- 2. RIDER KYC (one per delivery user)
CREATE TABLE IF NOT EXISTS `commerce_rider_kyc` (
  `id`                  bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `rider_user_id`       bigint(20) UNSIGNED NOT NULL,
  `full_name`           varchar(150) NULL,
  `phone`               varchar(20)  NULL,
  `address`             varchar(500) NULL,
  `licence_number`      varchar(40)  NULL,
  `vehicle_type`        varchar(24)  NULL,
  `vehicle_number`      varchar(20)  NULL,
  `rc_number`           varchar(40)  NULL,
  `bank_account_name`   varchar(150) NULL,
  `bank_account_number` varchar(40)  NULL,
  `bank_ifsc`           varchar(15)  NULL,
  `upi_id`              varchar(80)  NULL,
  `photo_media_id`      bigint(20) UNSIGNED NULL,
  `licence_media_id`    bigint(20) UNSIGNED NULL,
  `rc_media_id`         bigint(20) UNSIGNED NULL,
  `id_proof_media_id`   bigint(20) UNSIGNED NULL,
  `status`              enum('draft','submitted','approved','rejected') NOT NULL DEFAULT 'draft',
  `review_note`         varchar(500) NULL,
  `submitted_at`        datetime NULL,
  `reviewed_at`         datetime NULL,
  `reviewed_by`         bigint(20) UNSIGNED NULL,
  `created_at`          datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_rider_kyc_user` (`rider_user_id`),
  KEY `idx_rider_kyc_status` (`status`),
  CONSTRAINT `fk_rider_kyc_user` FOREIGN KEY (`rider_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- 3. KYC-review permission + grants (reuses the S11 RBAC)
INSERT INTO `permissions` (`perm_key`, `label`, `engine`, `group_label`) VALUES
  ('commerce.kyc.review', 'Review / approve seller + rider KYC', 'commerce', 'Commerce KYC')
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `engine` = VALUES(`engine`), `group_label` = VALUES(`group_label`);

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id` FROM `roles` r JOIN `permissions` p
WHERE p.`perm_key` = 'commerce.kyc.review' AND r.`slug` IN ('super_admin', 'commerce_ops');
