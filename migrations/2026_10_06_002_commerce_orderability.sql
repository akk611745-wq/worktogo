-- ============================================================================
--  WorkToGo Commerce — S2: seller BUSY state + global commerce kill-switch
--  Migration: 2026_10_06_002_commerce_orderability
-- ----------------------------------------------------------------------------
--  Supports the 4-layer orderability resolver (commerce-system-blueprint §C):
--    Catalogue ≠ Seller active ≠ Stock available ≠ Orderable.
--  Seller availability states:
--    ON   = vendors.is_online = 1 AND busy_until is null/past   (existing col + this)
--    OFF  = vendors.is_online = 0
--    BUSY = vendors.is_online = 1 AND busy_until in the future  (time-boxed; auto-clears,
--           no cron, no hardcoded timeout — whoever toggles sets busy_until)
--  Plus a configurable GLOBAL kill-switch (admin control room).
--  Additive + nullable only; 10.4-safe (no COMMENT-after-CHECK). Idempotent.
-- ============================================================================

ALTER TABLE `vendors`
  ADD COLUMN IF NOT EXISTS `busy_until` datetime NULL DEFAULT NULL;

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_enabled', '1', 'boolean', 'Global commerce kill-switch: 0 pauses all commerce ordering', 0);
