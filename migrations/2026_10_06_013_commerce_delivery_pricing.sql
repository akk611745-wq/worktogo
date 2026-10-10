-- ============================================================================
--  WorkToGo Commerce — S13: delivery absorb + no-flicker price + area rate cache
--  Migration: 2026_10_06_013_commerce_delivery_pricing
-- ----------------------------------------------------------------------------
--  PURPOSE (core-completion-plan §2 S13; CEO C.3):
--    Compose ONE final server-side price = S12 margin + delivery fee under an
--    admin-configurable ABSORB policy (high-margin → free delivery; cheap item →
--    eat what the margin allows above the loss-proof floor, hide part of the rest
--    inside the product price, show only a small delivery line). Loss-proof
--    preserved. Cache area delivery rates so repeated quotes are instant (no
--    flicker); a live quote runs only on a cache miss / edge.
--
--  SAFETY / IDEMPOTENCY
--    * CREATE TABLE IF NOT EXISTS; INSERT IGNORE on the unique setting_key —
--      re-run safe, never clobbers an admin-tuned policy. ADDITIVE only; no engine
--      change (DeliveryEngine is called, not edited).
--    * 10.4-safe: no COMMENT-after-CHECK, no CHECK constraints.
-- ============================================================================

-- 1. Absorb policy + rate-cache TTL (admin-tunable; in the AdminControlRoom allowlist)
INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_delivery_absorb',
   '{"enabled":true,"hide_ratio":0.5,"absorb_cap_pct":100,"min_shown_delivery":0}',
   'json', 'Commerce delivery-absorb policy (free-if-margin-covers; hide/show split for cheap items)', 0),
  ('commerce_delivery_rate_ttl_sec', '900', 'number',
   'Commerce area delivery-rate cache TTL in seconds (no-flicker speed; 0 = always live)', 0);

-- 2. Area delivery-rate cache (keyed by a coarse area key; payload = the full quote)
CREATE TABLE IF NOT EXISTS `commerce_delivery_rate_cache` (
  `area_key`   varchar(64)   NOT NULL,
  `fee`        decimal(10,2) NOT NULL DEFAULT 0.00,
  `available`  tinyint(1)    NOT NULL DEFAULT 1,
  `payload`    text          NULL,
  `cached_at`  datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`area_key`),
  KEY `idx_cdrc_cached` (`cached_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
