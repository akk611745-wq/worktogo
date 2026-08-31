-- ============================================================================
--  WorkToGo V2 — Agent Layer Phase 7: admin control-room governance settings
--  Migration: 2026_08_27_005_agent_governance
-- ----------------------------------------------------------------------------
--  PURPOSE
--    Seed the data-driven knobs the admin control-room uses to GOVERN the agent
--    — everything is app_settings, so the founder/admin changes behaviour with
--    zero code and it takes effect on the next turn. The orchestrator, planner
--    and entry READ these and ENFORCE them (see core/agent/AgentPolicy.php).
--
--      agent_capabilities (json) — per-capability toggles. Turn a whole class of
--        tools on/off (service / commerce / vendor) and pause ALL writes with one
--        flag. Reads keep working while writes are paused.
--        { "service":true, "commerce":true, "vendor":true, "writes":true }
--
--      agent_limits (json) — per-user message rate + max actions per session.
--        { "msg_per_min":30, "actions_per_session":20 }
--
--      agent_budget (json) — a monthly AI-cost cap (USD, provider currency) with
--        auto-pause to protect against runaway API bills. 0 = unlimited. When the
--        cap is hit and auto_pause is on, the agent stops CALLING the external AI
--        (it degrades to deterministic understanding) — it does not break.
--        { "monthly_cap_usd":0, "auto_pause":true }
--
--      agent_guardrails (json) — safety knobs (e.g. max message length).
--        { "max_message_chars":2000 }
--
--    The GLOBAL KILL-SWITCH (agent_enabled) and the AI routing (agent_ai_routing)
--    already exist (migrations 2026_08_27_001 / the payment-style AI layer) — the
--    control-room surfaces those too; they are not re-seeded here.
--
--  SAFETY / IDEMPOTENCY: INSERT IGNORE on the unique setting_key only — never
--    overwrites an admin's existing values. No ALTER/DROP, no data loss.
--
--    ⚠ STAGING ONLY — do NOT run on production from here. Apply on staging via
--    migrate.php and verify with the (commented) checks at the bottom first.
-- ============================================================================

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`)
VALUES
  ('agent_capabilities',
   '{"service":true,"commerce":true,"vendor":true,"writes":true}',
   'json', 'Agent: per-capability toggles (service/commerce/vendor/writes)', 0),
  ('agent_limits',
   '{"msg_per_min":30,"actions_per_session":20}',
   'json', 'Agent: per-user message rate + max actions per session', 0),
  ('agent_budget',
   '{"monthly_cap_usd":0,"auto_pause":true}',
   'json', 'Agent: monthly AI-cost cap (USD; 0=unlimited) + auto-pause', 0),
  ('agent_guardrails',
   '{"max_message_chars":2000}',
   'json', 'Agent: safety knobs (max message length, …)', 0);

-- ============================================================================
--  VERIFICATION (run manually on staging AFTER applying — do not uncomment here)
-- ----------------------------------------------------------------------------
--  SELECT setting_key, value_type, setting_value FROM app_settings
--    WHERE setting_key LIKE 'agent_%' ORDER BY setting_key;
--  -- Expect: agent_budget, agent_capabilities, agent_enabled, agent_guardrails,
--  --         agent_limits, agent_ai_routing  (+ agent_ai_log/agent_action_log
--  --         tables from earlier migrations feed the monitor).
-- ============================================================================
