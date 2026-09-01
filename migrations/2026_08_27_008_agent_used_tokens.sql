-- ============================================================================
--  WorkToGo V2 — Agent Layer Security Fix: confirm-token single-use (audit H1)
--  Migration: 2026_08_27_008_agent_used_tokens
-- ----------------------------------------------------------------------------
--  PURPOSE
--    The agent's write-confirmation token (ConfirmToken) is HMAC-signed, uid-bound
--    and short-lived — but was REPLAYABLE within its 10-minute TTL: submitting the
--    same token twice could double-execute a write (duplicate booking/order). This
--    tiny store makes each confirmation SINGLE-USE.
--
--    ConfirmToken::consume() claims a token's `jti` (its nonce) here with an INSERT;
--    the PRIMARY KEY on `jti` is the atomic gate — the first caller inserts and the
--    write proceeds; a replay (or concurrent duplicate) hits a duplicate-key and is
--    refused. Expired rows are purged opportunistically (expires_at). No PII: only
--    an opaque nonce + the internal user id + the token's expiry.
--
--    Until this table exists, ConfirmToken::consume() degrades to the prior
--    (stateless, replayable) behaviour — so APPLY THIS to activate single-use.
--
--  SAFETY / IDEMPOTENCY: CREATE TABLE IF NOT EXISTS only. No ALTER/DROP of any
--    existing table, no backfill, no data loss. InnoDB / utf8mb4.
--
--    ⚠ STAGING ONLY — do NOT run on production from here. Apply on staging via
--    migrate.php and verify with the (commented) checks at the bottom first.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `agent_used_tokens` (
  `jti`        VARCHAR(64)  NOT NULL COMMENT 'opaque confirm-token nonce (128-bit hex); the single-use key',
  `user_id`    BIGINT UNSIGNED NOT NULL COMMENT 'INTERNAL — who the confirmation belonged to; never sent anywhere',
  `expires_at` DATETIME     NOT NULL COMMENT 'token exp; rows past this are purged',
  `used_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`jti`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  VERIFICATION (run manually on staging AFTER applying — do not uncomment here)
-- ----------------------------------------------------------------------------
--  SHOW TABLES LIKE 'agent_used_tokens';                 -- expect 1 row
--  SHOW COLUMNS FROM `agent_used_tokens`;                 -- 4 columns, PK jti
--  -- A confirmed write inserts one row; a replay of the same token inserts none
--  -- (duplicate-key) and the agent refuses it.
--  SELECT jti, user_id, expires_at FROM agent_used_tokens ORDER BY used_at DESC LIMIT 5;
-- ============================================================================
