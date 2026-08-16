-- ============================================================================
--  WorkToGo V2 — Phase 2: Vendor accountability / bypass auto-flag (foundation)
--  Migration: 2026_08_15_001_vendor_accountability
-- ----------------------------------------------------------------------------
--  PURPOSE (V2-VISION §5 Bypass Prevention: "auto-flag vendors who repeatedly
--    bypass or fail to close … the admin should not have to hunt for them")
--
--    The DETECTION is computed live from EXISTING data — no outcome-event table
--    is duplicated (that would drift). Per-vendor signals come from:
--      * jobs             — completed / cancelled / assigned-but-not-completed
--                           (stalled past a threshold) = the non-completion signal
--      * auto_assignments — accepted / rejected / timeout = the take-the-offer
--                           (bypass/avoidance) signal
--    (see core/helpers/VendorAccountability.php). This migration only lays the
--    two things computation can't derive: (1) the TUNABLE RULE thresholds, and
--    (2) the admin DECISION layer (the system flags; the admin acts).
--
--  WHAT THIS ADDS
--    1. app_settings rows for every flag threshold — ZERO hardcoding, admin-
--       tunable from the panel. Missing rows fall back to code defaults, so the
--       flag works before/without this migration too.
--    2. Nullable admin-decision columns on `vendors` (backward-compatible, and
--       NOT read by assignment — assignment/booking logic is unchanged):
--         manual_flag       — admin force-flags a vendor regardless of signals
--         flag_reviewed_at  — admin acknowledged the flag (mutes the top alert)
--         flag_notes        — admin's note
--         work_throttle     — the FUTURE admin toggle to reduce/stop work
--                             ('normal'|'reduced'|'paused'); a hook only, not yet
--                             enforced anywhere (no auto-block).
--    3. Composite indexes so the windowed per-vendor aggregates stay fast at
--       millions of jobs.
--
--  SAFETY / IDEMPOTENCY: ADD COLUMN IF NOT EXISTS / ADD KEY IF NOT EXISTS /
--    INSERT ... ON DUPLICATE KEY (keyed by app_settings.setting_key unique). All
--    columns nullable. Re-run safe. DO NOT run against prod from here — apply on
--    STAGING first, verify, then prod.
-- ============================================================================

-- ── 1. Tunable flag thresholds (admin can change any of these) ──────────────
--    app_settings already has UNIQUE KEY `setting_key`, so ON DUPLICATE KEY is
--    keyed on it (no schema change needed here).
INSERT INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('vendor_flag_window_days',        '30', 'number', 'Accountability: lookback window (days)',                       0),
  ('vendor_flag_stall_hours',        '72', 'number', 'Accountability: hours before an unfinished job counts stalled',0),
  ('vendor_flag_min_assigned',       '5',  'number', 'Accountability: min jobs before rate-based flagging',          0),
  ('vendor_flag_noncompletion_count','3',  'number', 'Accountability: stalled/non-completed jobs that trigger a flag',0),
  ('vendor_flag_min_completion_rate','50', 'number', 'Accountability: completion % below which a vendor is flagged', 0),
  ('vendor_flag_min_offered',        '5',  'number', 'Accountability: min offers before decline-rate flagging',      0),
  ('vendor_flag_max_reject_rate',    '60', 'number', 'Accountability: decline/ignore % above which a vendor is flagged',0)
ON DUPLICATE KEY UPDATE
  `value_type` = VALUES(`value_type`), `label` = VALUES(`label`);
  -- NOTE: setting_value is intentionally NOT overwritten, so a re-run never
  -- clobbers a threshold the admin has already tuned.

-- ── 2. Admin decision layer on vendors (nullable, not enforced by assignment) ─
ALTER TABLE `vendors`
  ADD COLUMN IF NOT EXISTS `manual_flag`      tinyint(1)   NULL DEFAULT NULL COMMENT 'admin force-flag (1) regardless of computed signals',
  ADD COLUMN IF NOT EXISTS `flag_reviewed_at` datetime     NULL DEFAULT NULL COMMENT 'admin acknowledged the accountability flag',
  ADD COLUMN IF NOT EXISTS `flag_notes`       varchar(255) NULL DEFAULT NULL COMMENT 'admin note on the flag / decision',
  ADD COLUMN IF NOT EXISTS `work_throttle`    varchar(12)  NULL DEFAULT NULL COMMENT 'future admin toggle: normal|reduced|paused (hook only, not yet enforced)';

-- ── 3. Scale: composite indexes for the windowed per-vendor aggregates ──────
ALTER TABLE `jobs`
  ADD KEY IF NOT EXISTS `idx_jobs_vendor_status_created` (`vendor_id`, `status`, `created_at`);

ALTER TABLE `auto_assignments`
  ADD KEY IF NOT EXISTS `idx_aa_vendor_type_status` (`vendor_id`, `entity_type`, `status`);

-- ============================================================================
--  VERIFICATION (run after applying)
-- ----------------------------------------------------------------------------
--  SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE 'vendor_flag_%';
--    -- expect 7 threshold rows
--  SHOW COLUMNS FROM vendors LIKE 'work_throttle';   -- expect 1 row, Null=YES
--  SHOW COLUMNS FROM vendors LIKE 'manual_flag';     -- expect 1 row, Null=YES
--  SHOW INDEX FROM jobs WHERE Key_name = 'idx_jobs_vendor_status_created';       -- expect rows
--  SHOW INDEX FROM auto_assignments WHERE Key_name = 'idx_aa_vendor_type_status';-- expect rows
-- ============================================================================
