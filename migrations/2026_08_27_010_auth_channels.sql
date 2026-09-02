-- ============================================================================
--  WorkToGo V2 — Smart Auth Layer: OTP-channel routing (admin override)
--  Migration: 2026_08_27_010_auth_channels
-- ----------------------------------------------------------------------------
--  PURPOSE
--    Seed the admin-tunable OTP-channel routing the Smart Auth Layer reads
--    (OtpChannelManager merges app_settings.auth_channel_routing on TOP of the
--    file defaults in config/auth.channels.php — the exact analogue of
--    payment_gateway_routing). An admin can re-route SMS/WhatsApp primary/
--    secondary (once a 2nd SMS provider or WhatsApp is connected) with zero code.
--
--    No schema change is needed: OTP channels are selected by config + env
--    credentials (isConfigured()), and the OTP/verify/rate-limit/JWT tables are
--    untouched. This migration only seeds one settings row.
--
--    NOTE: which METHODS are offered and the enumeration-safe
--    `reveal_account_methods` flag live in config/auth.channels.php (reveal is a
--    security setting, secure-default OFF, changed via code review — not a runtime
--    toggle). Only channel ROUTING is admin-overridable here.
--
--  SAFETY / IDEMPOTENCY: INSERT IGNORE on the unique setting_key only — never
--    overwrites an admin's existing value. No ALTER/DROP, no data loss.
--
--    ⚠ STAGING ONLY — do NOT run on production from here. Apply on staging via
--    migrate.php and verify with the (commented) checks at the bottom first.
-- ============================================================================

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`)
VALUES
  ('auth_channel_routing',
   '{"sms":{"primary":"msg91","secondary":"log"},"whatsapp":{"primary":"whatsapp","secondary":"msg91"}}',
   'json', 'Auth: OTP channel routing (primary/secondary per delivery kind) — admin failover control', 0);

-- ============================================================================
--  VERIFICATION (run manually on staging AFTER applying — do not uncomment here)
-- ----------------------------------------------------------------------------
--  SELECT setting_key, value_type, setting_value FROM app_settings
--    WHERE setting_key = 'auth_channel_routing';
--  -- To give prod SMS real failover, set routing.sms.secondary to a configured
--  -- 2nd provider ('sms2') once its creds are live; to enable WhatsApp OTP,
--  -- configure the whatsapp channel + flip enabled=true in config/auth.channels.php.
-- ============================================================================
