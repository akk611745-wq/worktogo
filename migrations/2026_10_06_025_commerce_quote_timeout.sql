-- ============================================================================
--  WorkToGo Commerce — Paint PB6: commerce lifecycle cron config
--  Migration: 2026_10_06_025_commerce_quote_timeout
-- ----------------------------------------------------------------------------
--  PURPOSE: config for cron/commerce_lifecycle.php.
--   - commerce_lifecycle_enabled   : master on/off for the commerce cron.
--   - commerce_quote_timeout_sec   : custom-tint quote accept window (default
--     180s = 3 min). The quote-timeout sweep (RoutingEngine::expireStaleQuotes)
--     reroutes a quote the seller didn't answer in time. Used by PB10 custom-tint;
--     wired now so no code change is needed when the quote flow lands.
--  (Offer timeout reuses the existing commerce_offer_timeout_sec, default 120s.)
--  ADDITIVE; no engine edit. B3-compliant.
-- ============================================================================

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_lifecycle_enabled', '1', 'text', 'Master switch for the commerce lifecycle cron (offer + quote timeouts)', 0),
  ('commerce_quote_timeout_sec', '180', 'number', 'Seconds a seller has to answer a custom-tint quote before it reroutes (default 180 = 3 min)', 0);
