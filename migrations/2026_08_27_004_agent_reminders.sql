-- ============================================================================
--  WorkToGo V2 — Agent Layer Phase 6: vendor-agent reminders
--  Migration: 2026_08_27_004_agent_reminders
-- ----------------------------------------------------------------------------
--  PURPOSE
--    Agent-owned reminders (e.g. a vendor's "weekly work-photo upload"). The
--    agent is the thing that reminds, so it owns this small store, keyed to the
--    internal user id. DELIVERY (turning a due reminder into a push) hooks into
--    the EXISTING notifications/FCM mechanism via a cron in a follow-up — this
--    table is the store the vendor-agent writes when it sets a reminder.
--
--    Columns: user_id (internal), title, cadence (daily|weekly|once), next_at
--    (when it is next due), active, timestamps.
--
--  SAFETY / IDEMPOTENCY: CREATE TABLE IF NOT EXISTS only. No ALTER/DROP of any
--    existing table, no backfill, no data loss. InnoDB / utf8mb4.
--
--    ⚠ STAGING ONLY — do NOT run on production from here. Apply on staging via
--    migrate.php and verify with the (commented) checks at the bottom first.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `agent_reminders` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED NOT NULL COMMENT 'INTERNAL identity — never sent to any provider',
  `title`      VARCHAR(150) NOT NULL,
  `cadence`    VARCHAR(16)  NOT NULL DEFAULT 'weekly' COMMENT 'daily|weekly|once',
  `next_at`    DATETIME     NOT NULL,
  `active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user`   (`user_id`),
  KEY `idx_due`    (`active`, `next_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  VERIFICATION (run manually on staging AFTER applying — do not uncomment here)
-- ----------------------------------------------------------------------------
--  SHOW TABLES LIKE 'agent_reminders';                  -- expect 1 row
--  SHOW COLUMNS FROM `agent_reminders`;                  -- 8 columns, PK id
--  SELECT user_id, title, cadence, next_at FROM agent_reminders ORDER BY id DESC LIMIT 5;
-- ============================================================================
