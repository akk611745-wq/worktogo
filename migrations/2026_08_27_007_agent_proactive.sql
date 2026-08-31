-- ============================================================================
--  WorkToGo V2 — Agent Layer Phase 9: proactive nervous-system
--  Migration: 2026_08_27_007_agent_proactive
-- ----------------------------------------------------------------------------
--  PURPOSE
--    Two things turn the agent from reactive into proactive, both OUR-system-side
--    (no PII ever reaches the external AI):
--
--    1. REMINDER DELIVERY. Phase 6 stores agent reminders (agent_reminders); this
--       adds the delivery bookkeeping columns a cron needs to FIRE them via the
--       existing notifications/FCM channel, idempotently:
--         • last_fired_at — when the reminder was last delivered (NULL = never)
--         • fire_count    — how many times it has been delivered (audit)
--         • last_error    — a short note if the last delivery attempt failed
--       (The store, cadence and next_at already exist from 2026_08_27_004.)
--
--    2. DEMAND-DETECTION. The system watches its OWN data and tells the admin/team
--       where to grow — "X category ki demand hai but few/no vendors here". Two
--       small agent-owned tables:
--         • agent_demand_events  — one row per real ask the agent saw (a search /
--           browse), with the category, city (INTERNAL id), area, and how many
--           results the ENGINE returned (result_count = a live supply proxy). Only
--           market metadata + an internal user_id (for distinct-user counting) —
--           never PII, never leaves the system.
--         • agent_demand_signals — the aggregated signal per (scope, category,
--           city): ask_count, distinct_users, supply_estimate, status. A cron
--           upserts these and, when demand is high AND supply is low, raises an
--           ADMIN ALERT via the existing FCM/alerts mechanism. The admin control-
--           room reads this table as a "growth signals" list.
--
--    Tunable thresholds live in app_settings (no hardcoding) — see the seeds below.
--
--  SAFETY / IDEMPOTENCY: ADD COLUMN IF NOT EXISTS + CREATE TABLE IF NOT EXISTS +
--    INSERT IGNORE only. No DROP, no destructive ALTER, no backfill, no data loss.
--    InnoDB / utf8mb4. MariaDB "IF NOT EXISTS" column guards (house style).
--
--    ⚠ STAGING ONLY — do NOT run on production from here. Apply on staging via
--    migrate.php and verify with the (commented) checks at the bottom first.
-- ============================================================================

-- ── 1. Reminder delivery bookkeeping (idempotent ALTERs) ─────────────────────
ALTER TABLE `agent_reminders`
  ADD COLUMN IF NOT EXISTS `last_fired_at` DATETIME     NULL DEFAULT NULL AFTER `next_at`,
  ADD COLUMN IF NOT EXISTS `fire_count`    INT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_fired_at`,
  ADD COLUMN IF NOT EXISTS `last_error`    VARCHAR(255) NULL DEFAULT NULL AFTER `fire_count`;

-- ── 2. Demand-detection: raw ask events (our data; no PII to any provider) ────
CREATE TABLE IF NOT EXISTS `agent_demand_events` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope`        VARCHAR(16)  NOT NULL DEFAULT 'service' COMMENT 'service|commerce',
  `category_key` VARCHAR(80)  NOT NULL COMMENT 'normalized category/search term — market metadata, not PII',
  `city_id`      BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'INTERNAL city id (0 = unknown/all); never sent to a provider',
  `area`         VARCHAR(120) NULL DEFAULT NULL COMMENT 'optional free-text locality the user typed',
  `result_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'how many results the engine returned = live supply proxy',
  `user_id`      BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'INTERNAL — distinct-user counting only; never leaves the system',
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_agg`  (`scope`, `category_key`, `city_id`, `created_at`),
  KEY `idx_when` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Demand-detection: aggregated growth signals (admin-facing) ────────────
CREATE TABLE IF NOT EXISTS `agent_demand_signals` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope`           VARCHAR(16)  NOT NULL DEFAULT 'service' COMMENT 'service|commerce',
  `category_key`    VARCHAR(80)  NOT NULL,
  `city_id`         BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'INTERNAL city id (0 = unknown/all)',
  `area`            VARCHAR(120) NULL DEFAULT NULL COMMENT 'representative area (most recent seen)',
  `ask_count`       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'asks seen in the detection window',
  `distinct_users`  INT UNSIGNED NOT NULL DEFAULT 0,
  `supply_estimate` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'min results the engine returned = how many vendors/stores show up',
  `status`          VARCHAR(16)  NOT NULL DEFAULT 'open' COMMENT 'open|alerted|resolved',
  `first_seen_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `alerted_at`      DATETIME     NULL DEFAULT NULL,
  `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_signal` (`scope`, `category_key`, `city_id`),
  KEY `idx_status` (`status`, `last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. Tunable settings (no hardcoding) — INSERT IGNORE never overwrites admin ─
INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`)
VALUES
  ('agent_reminders_enabled', '1', 'boolean',
     'Agent: deliver due reminders (the reminder-delivery cron)', 0),
  ('agent_reminders_batch',   '200', 'number',
     'Agent: max reminders delivered per cron run', 0),
  ('agent_demand_detect_enabled', '1', 'boolean',
     'Agent: compute demand/growth signals (the demand-detection cron)', 0),
  ('agent_demand_thresholds',
     '{"window_days":14,"min_asks":5,"min_distinct_users":3,"max_supply":2,"cooldown_hours":168}',
     'json', 'Agent: demand-detection thresholds (window, min asks/users, max supply, alert cooldown)', 0);

-- ============================================================================
--  VERIFICATION (run manually on staging AFTER applying — do not uncomment here)
-- ----------------------------------------------------------------------------
--  SHOW COLUMNS FROM `agent_reminders` LIKE 'last_fired_at';   -- expect 1 row
--  SHOW COLUMNS FROM `agent_reminders` LIKE 'fire_count';      -- expect 1 row
--  SHOW TABLES LIKE 'agent_demand_events';                     -- expect 1 row
--  SHOW TABLES LIKE 'agent_demand_signals';                    -- expect 1 row
--  SELECT setting_key, value_type, setting_value FROM app_settings
--    WHERE setting_key LIKE 'agent_%' ORDER BY setting_key;
-- ============================================================================
