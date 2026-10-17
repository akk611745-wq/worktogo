-- ============================================================================
--  WorkToGo Commerce — Paint PB2: seller add-product support
--  Migration: 2026_10_06_022_seller_catalogue
-- ----------------------------------------------------------------------------
--  PURPOSE: (1) an audit of post-live price/stock edits a seller makes to a pack
--  (no re-approval needed, but every change is logged so admin can review);
--  (2) the brand picker list for the add-product screen (admin-editable;
--  sellers may also type a custom brand).
--  ADDITIVE; no engine edit. B3-compliant (no DEFAULT CHARSET; no FK, kept
--  decoupled like the KYC tables).
-- ============================================================================

CREATE TABLE IF NOT EXISTS `commerce_offer_changes` (
  `id`         bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `vendor_id`  bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `field`      varchar(16) NOT NULL,
  `old_value`  varchar(64) NULL,
  `new_value`  varchar(64) NULL,
  `changed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_offchg_prod` (`vendor_id`, `product_id`),
  KEY `idx_offchg_at` (`changed_at`)
) ENGINE=InnoDB;

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_paint_brands',
   '["Berger","Asian Paints","Nerolac","Dulux","Indigo","Nippon","Birla Opus","Jotun","Sherwin Williams","Kansai Nerolac","JSW Paints","Shalimar","Acro","Sheenlac"]',
   'text', 'Paint brand picker list (seller add-product; sellers may also type a custom brand)', 0);
