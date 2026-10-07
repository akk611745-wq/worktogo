-- ============================================================================
--  WorkToGo Commerce — S3: deterministic supply routing + signal capture
--  Migration: 2026_10_06_003_commerce_routing
-- ----------------------------------------------------------------------------
--  commerce-system-blueprint §D. Adds:
--    1. commerce_routing_signals — append-only log of every routing event
--       (offered / accepted / rejected / timeout / stockout / rerouted /
--        reroute_failed). This is the FUEL for the future Brain (ranking),
--        captured from day one while routing stays deterministic if-else.
--    2. Configurable routing values (admin-tunable; NO hardcoded timeout):
--         commerce_offer_timeout_sec  (default 120 = 2 min)
--         commerce_offer_max_retries  (default 3)
--         commerce_default_radius_km  (default 8)
--  Additive only; 10.4-safe (plain JSON longtext, no CHECK/COMMENT); idempotent.
--  No FKs on the signal log (lightweight analytics append; keys are indexed).
--  No COLLATE clause → inherits the DB default (portable across environments).
-- ============================================================================

CREATE TABLE IF NOT EXISTS `commerce_routing_signals` (
  `id`           bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`     bigint(20) UNSIGNED DEFAULT NULL,
  `sub_order_id` bigint(20) UNSIGNED DEFAULT NULL,
  `store_id`     bigint(20) UNSIGNED DEFAULT NULL,
  `vendor_id`    bigint(20) UNSIGNED DEFAULT NULL,
  `event`        varchar(32) NOT NULL,
  `response_sec` int(11) DEFAULT NULL,
  `meta`         longtext DEFAULT NULL,
  `created_at`   datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_crs_order` (`order_id`),
  KEY `idx_crs_sub` (`sub_order_id`),
  KEY `idx_crs_store_event` (`store_id`, `event`),
  KEY `idx_crs_event_created` (`event`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_offer_timeout_sec', '120', 'number', 'Seconds a seller has to accept before auto-reroute',        0),
  ('commerce_offer_max_retries', '3',   'number', 'Max reroute attempts before an order goes to ops',           0),
  ('commerce_default_radius_km', '8',   'number', 'Default serviceable radius (km) when a store has none set',   0);

-- products.sku is UNIQUE (one seller's offer per SKU), so it cannot identify the
-- SAME item across different sellers' stores. `match_key` is a NULLABLE, NON-unique
-- equivalence key (e.g. brand+line+pack, or a shared code) that groups equivalent
-- offers across stores so routing can pick/ reroute among them + fall back to the
-- company dark-store. Seed of the canonical-product model; full canonical catalogue
-- is deferred (FINAL scope "Still Not Now"). Additive/nullable; 10.4-safe.
ALTER TABLE `products`
  ADD COLUMN IF NOT EXISTS `match_key` varchar(150) NULL DEFAULT NULL,
  ADD KEY IF NOT EXISTS `idx_products_match_key` (`match_key`);
