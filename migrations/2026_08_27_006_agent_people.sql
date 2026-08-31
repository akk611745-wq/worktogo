-- ============================================================================
--  WorkToGo V2 — Agent Layer Phase 8: connections ("my people")
--  Migration: 2026_08_27_006_agent_people
-- ----------------------------------------------------------------------------
--  PURPOSE
--    "Social-lite" (NOT a full WhatsApp): a user saves their PEOPLE — sister,
--    friend, papa — so they can order/gift FOR them by talking ("behen ke liye
--    kapde bhej do"). One row per saved recipient, keyed to the internal user id.
--
--    This is PII (the recipient's name/phone/address) and it lives ENTIRELY
--    inside our system, behind the privacy wall. It is NEVER sent to the external
--    AI — the agent resolves the person by an internal id; only anonymized hints
--    (e.g. "ordering for a saved contact") may cross, and the wall additionally
--    scrubs saved contact NAMES out of the user's message (see PiiWall +
--    AgentPeople::names). To the AI it is one anonymous WorkToGo agent, never the
--    individual recipients.
--
--    Columns:
--      user_id (internal owner) · name · phone · relation (behen/friend/…) ·
--      address_text · city · lat/lng (optional delivery coords) · timestamps.
--
--  SAFETY / IDEMPOTENCY: CREATE TABLE IF NOT EXISTS only. No ALTER/DROP of any
--    existing table, no backfill, no data loss. InnoDB / utf8mb4.
--
--    ⚠ STAGING ONLY — do NOT run on production from here. Apply on staging via
--    migrate.php and verify with the (commented) checks at the bottom first.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `agent_people` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      BIGINT UNSIGNED NOT NULL COMMENT 'INTERNAL owner — never sent to any provider',
  `name`         VARCHAR(120) NOT NULL,
  `phone`        VARCHAR(20)  NULL DEFAULT NULL,
  `relation`     VARCHAR(40)  NULL DEFAULT NULL COMMENT 'behen / bhai / friend / papa / …',
  `address_text` VARCHAR(400) NULL DEFAULT NULL,
  `city`         VARCHAR(120) NULL DEFAULT NULL,
  `lat`          DECIMAL(10,7) NULL DEFAULT NULL,
  `lng`          DECIMAL(10,7) NULL DEFAULT NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_user_relation` (`user_id`, `relation`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  VERIFICATION (run manually on staging AFTER applying — do not uncomment here)
-- ----------------------------------------------------------------------------
--  SHOW TABLES LIKE 'agent_people';                     -- expect 1 row
--  SHOW COLUMNS FROM `agent_people`;                     -- 11 columns, PK id
--  SELECT user_id, name, relation, city FROM agent_people ORDER BY id DESC LIMIT 5;
-- ============================================================================
