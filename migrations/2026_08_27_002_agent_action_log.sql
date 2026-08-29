-- ============================================================================
--  WorkToGo V2 — Agent Layer Phase 2: action/tool audit log
--  Migration: 2026_08_27_002_agent_action_log
-- ----------------------------------------------------------------------------
--  PURPOSE
--    One row per agent TOOL step, so every action the agent proposes, executes,
--    rejects, or fails is auditable. Like the AI egress log, it records METADATA
--    ONLY — never argument VALUES, never PII:
--      • tool          — which tool (find_vendors, create_booking, …)
--      • mode          — read | write | chat
--      • source        — deterministic | ai | client | confirm
--      • status        — executed | proposed | rejected | failed | chat
--      • http_status   — the backend endpoint's HTTP status (0 for no call)
--      • arg_keys      — JSON list of which argument KEYS were present (not values)
--      • error         — a short backend error message when a call failed
--    This proves the guardrails ran (allow-list rejects, write-confirmation
--    gates, RBAC) without the log itself becoming a data-leak surface.
--
--  SAFETY / IDEMPOTENCY: CREATE TABLE IF NOT EXISTS only. No ALTER/DROP of any
--    existing table, no backfill, no data loss. InnoDB / utf8mb4.
--
--    ⚠ STAGING ONLY — do NOT run on production from here. Apply on staging via
--    migrate.php and verify with the (commented) checks at the bottom first.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `agent_action_log` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id`  VARCHAR(64)  NOT NULL,
  `user_id`     BIGINT UNSIGNED NULL DEFAULT NULL
                  COMMENT 'INTERNAL audit linkage only — never sent to any provider',
  `tool`        VARCHAR(60)  NOT NULL,
  `mode`        VARCHAR(20)  NOT NULL DEFAULT 'read'   COMMENT 'read|write|chat',
  `source`      VARCHAR(20)  NOT NULL DEFAULT 'ai'     COMMENT 'deterministic|ai|client|confirm',
  `status`      VARCHAR(20)  NOT NULL DEFAULT 'chat'   COMMENT 'executed|proposed|rejected|failed|chat',
  `http_status` INT UNSIGNED NOT NULL DEFAULT 0        COMMENT 'backend endpoint HTTP status (0=no call)',
  `arg_keys`    JSON         NULL DEFAULT NULL          COMMENT 'which argument KEYS were present — never values',
  `error`       VARCHAR(255) NULL DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_request` (`request_id`),
  KEY `idx_user`    (`user_id`),
  KEY `idx_tool`    (`tool`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  VERIFICATION (run manually on staging AFTER applying — do not uncomment here)
-- ----------------------------------------------------------------------------
--  SHOW TABLES LIKE 'agent_action_log';                 -- expect 1 row
--  SHOW COLUMNS FROM `agent_action_log`;                 -- 11 columns
--  -- After a few agent turns, the trail should look like:
--  SELECT tool, mode, source, status, http_status FROM agent_action_log
--    ORDER BY id DESC LIMIT 10;
-- ============================================================================
