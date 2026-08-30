-- ============================================================================
--  WorkToGo V2 — Agent Layer Phase 4: per-user memory / personalization
--  Migration: 2026_08_27_003_agent_memory
-- ----------------------------------------------------------------------------
--  PURPOSE
--    Give the agent a memory so repeat use is fast and personal ("I know you
--    like painting", "area already known"). One row per user, keyed to OUR
--    internal user id. This data lives ENTIRELY inside our system, behind the
--    privacy wall — it is NEVER sent to the external AI. Only tiny, derived,
--    NON-IDENTIFYING hints (e.g. "returning", "pref_category:painting",
--    "area_known") may cross the wall, and even those are allow-listed +
--    tripwire-scanned (see core/agent/AgentMemory.php and PiiWall.php).
--
--    Columns:
--      user_id        — INTERNAL identity; the memory key. Never sent out.
--      turn_count     — how many agent turns this user has had (new vs returning)
--      pref_categories— JSON map { slug: count } of what they use most
--      last_category  — their most recent category (public slug)
--      last_area      — INTERNAL free-text area/locality (NEVER sent to the AI;
--                       used only to avoid re-asking)
--      last_city_id   — INTERNAL city id (never sent to the AI)
--      recent_tools   — JSON list of the last few tools used
--
--  SAFETY / IDEMPOTENCY: CREATE TABLE IF NOT EXISTS only. No ALTER/DROP of any
--    existing table, no backfill, no data loss. InnoDB / utf8mb4.
--
--    ⚠ STAGING ONLY — do NOT run on production from here. Apply on staging via
--    migrate.php and verify with the (commented) checks at the bottom first.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `agent_memory` (
  `user_id`         BIGINT UNSIGNED NOT NULL
                      COMMENT 'INTERNAL identity — the memory key; never sent to any provider',
  `turn_count`      INT UNSIGNED NOT NULL DEFAULT 0,
  `pref_categories` JSON         NULL DEFAULT NULL COMMENT '{ "painting": 3, "plumber": 1 }',
  `last_category`   VARCHAR(40)  NULL DEFAULT NULL,
  `last_area`       VARCHAR(150) NULL DEFAULT NULL
                      COMMENT 'INTERNAL area/locality — never sent to the AI; used to avoid re-asking',
  `last_city_id`    BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'INTERNAL — never sent to the AI',
  `recent_tools`    JSON         NULL DEFAULT NULL COMMENT 'last few tool names used',
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  KEY `idx_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  VERIFICATION (run manually on staging AFTER applying — do not uncomment here)
-- ----------------------------------------------------------------------------
--  SHOW TABLES LIKE 'agent_memory';                     -- expect 1 row
--  SHOW COLUMNS FROM `agent_memory`;                     -- 9 columns, PK user_id
--  -- After a couple of agent turns, a returning user's row should look like:
--  SELECT user_id, turn_count, last_category, pref_categories FROM agent_memory LIMIT 5;
-- ============================================================================
