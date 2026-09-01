-- ============================================================================
--  WorkToGo V2 — Agent Layer Security Fix: safe default AI-budget cap (audit M1)
--  Migration: 2026_08_27_009_agent_budget_default
-- ----------------------------------------------------------------------------
--  PURPOSE
--    The agent's AI-budget cap seeded by 2026_08_27_005 was 0 = UNLIMITED, so a
--    real AI key could run a runaway bill by accident before an admin set a cap
--    (audit M1). This sets a SAFE default cap ($20/month) with auto-pause, so once
--    monthly spend passes it the agent stops CALLING the external AI (it degrades
--    to deterministic understanding — it does not break). The control-room can
--    raise it any time; an admin who wants unlimited can deliberately set 0.
--
--  SAFETY / IDEMPOTENCY:
--    • The UPDATE only rewrites the UNTOUCHED old default ({"monthly_cap_usd":0,…})
--      — it NEVER overwrites a value an admin has customised.
--    • INSERT IGNORE seeds the row only if it is absent (e.g. 005 not applied here).
--    Runs after 005 (filename order), so on a fresh apply 005 seeds cap 0 and this
--    corrects it to 20. No ALTER/DROP, no data loss.
--
--    ⚠ STAGING ONLY — do NOT run on production from here. Apply on staging via
--    migrate.php and verify with the (commented) checks at the bottom first.
-- ============================================================================

UPDATE `app_settings`
   SET `setting_value` = '{"monthly_cap_usd":20,"auto_pause":true}'
 WHERE `setting_key` = 'agent_budget'
   AND `setting_value` = '{"monthly_cap_usd":0,"auto_pause":true}';

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`)
VALUES
  ('agent_budget', '{"monthly_cap_usd":20,"auto_pause":true}',
   'json', 'Agent: monthly AI-cost cap (USD; 0=unlimited) + auto-pause', 0);

-- ============================================================================
--  VERIFICATION (run manually on staging AFTER applying — do not uncomment here)
-- ----------------------------------------------------------------------------
--  SELECT setting_value FROM app_settings WHERE setting_key = 'agent_budget';
--  -- expect {"monthly_cap_usd":20,"auto_pause":true} unless an admin changed it.
-- ============================================================================
