-- ============================================================================
--  WorkToGo V2 — Agent Layer Foundation: egress audit + kill-switch + routing
--  Migration: 2026_08_27_001_agent_foundation
-- ----------------------------------------------------------------------------
--  PURPOSE
--    Support the agent foundation (privacy wall + pluggable AI-provider layer)
--    with ZERO impact on any existing table or flow. Three additive things:
--
--      1. `agent_ai_log` (NEW TABLE) — one row per agent turn, recording WHAT
--         LEFT to an external AI provider, WITHOUT SECRETS. It stores only:
--         the request id, the internal user id (OUR audit linkage — never sent
--         out), the provider/model that handled it, the task, a JSON summary of
--         PII REDACTION COUNTS by type (e.g. {"PHONE":1,"EMAIL":1} — counts, not
--         values), token counts, latency, status and failover. No prompt text,
--         no user PII, no API keys are ever written here. This is the auditable
--         proof that the wall ran and what it stripped.
--
--      2. app_settings['agent_enabled'] (boolean) — the KILL-SWITCH. Seeded to
--         '0' (OFF) so the agent never calls out or spends until the founder
--         explicitly enables it. The HTTP entry refuses (503) while this is off,
--         and because the platform is system-first, everything else keeps working.
--
--      3. app_settings['agent_ai_routing'] (JSON) — the admin-editable per-task
--         provider routing override. Seeded to the same safe defaults as
--         config/ai.providers.php (anthropic primary, mock secondary). If this
--         row is absent the code falls back to the config file, so this is a
--         convenience/visibility seed, not a hard dependency.
--
--  SAFETY / IDEMPOTENCY: CREATE TABLE IF NOT EXISTS; INSERT IGNORE on the unique
--    setting_key. No ALTER of any existing table, no DROP, no backfill, no data
--    loss. InnoDB / utf8mb4.
--
--    ⚠ STAGING ONLY — do NOT run on production from here. Apply on staging via
--    migrate.php and verify with the (commented) checks at the bottom first.
-- ============================================================================

-- ── 1. Egress audit log (counts only — never raw content or secrets) ──────────
CREATE TABLE IF NOT EXISTS `agent_ai_log` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id`        VARCHAR(64)  NOT NULL,
  `user_id`           BIGINT UNSIGNED NULL DEFAULT NULL
                        COMMENT 'INTERNAL audit linkage only — never sent to any provider',
  `provider`          VARCHAR(40)  NOT NULL DEFAULT 'pending'
                        COMMENT 'which provider handled the turn (mock|anthropic|openai|gemini)',
  `model`             VARCHAR(80)  NULL DEFAULT NULL,
  `task`              VARCHAR(40)  NOT NULL DEFAULT 'understand',
  `redactions`        JSON         NULL DEFAULT NULL
                        COMMENT 'PII redaction COUNTS by type, e.g. {"PHONE":1} — counts, not values',
  `prompt_chars`      INT UNSIGNED NOT NULL DEFAULT 0
                        COMMENT 'length of the SANITIZED outbound text (chars) — size only',
  `prompt_tokens`     INT UNSIGNED NOT NULL DEFAULT 0,
  `completion_tokens` INT UNSIGNED NOT NULL DEFAULT 0,
  `latency_ms`        INT UNSIGNED NOT NULL DEFAULT 0,
  `status`            VARCHAR(20)  NOT NULL DEFAULT 'egress'
                        COMMENT 'egress|ok|failed',
  `failover`          TINYINT(1)   NOT NULL DEFAULT 0,
  `error`             VARCHAR(255) NULL DEFAULT NULL,
  `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_request` (`request_id`),
  KEY `idx_user`     (`user_id`),
  KEY `idx_created`  (`created_at`),
  KEY `idx_provider` (`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Kill-switch (OFF by default — the agent never spends until enabled) ─────
INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`)
VALUES (
  'agent_enabled',
  '0',
  'boolean',
  'Agent: master kill-switch (0=off). The AI assistant is disabled until turned on.',
  0
);

-- ── 3. Admin-editable per-task provider routing (mirrors config/ai.providers.php)
INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`)
VALUES (
  'agent_ai_routing',
  '{"default":{"primary":"anthropic","secondary":"mock"},"understand":{"primary":"anthropic","secondary":"mock"},"generate":{"primary":"anthropic","secondary":"mock"}}',
  'json',
  'Agent: per-task AI provider routing (primary/secondary + failover)',
  0
);

-- ============================================================================
--  VERIFICATION (run manually on staging AFTER applying — do not uncomment here)
-- ----------------------------------------------------------------------------
--  SHOW TABLES LIKE 'agent_ai_log';                       -- expect 1 row
--  SHOW COLUMNS FROM `agent_ai_log`;                       -- 14 columns, uniq_request on request_id
--  SELECT setting_key, value_type, setting_value
--    FROM app_settings WHERE setting_key IN ('agent_enabled','agent_ai_routing');
--  -- Kill-switch must read '0' (OFF) until the founder enables the agent:
--  SELECT setting_value FROM app_settings WHERE setting_key = 'agent_enabled';   -- expect '0'
-- ============================================================================
