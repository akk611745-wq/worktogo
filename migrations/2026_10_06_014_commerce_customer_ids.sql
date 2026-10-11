-- ============================================================================
--  WorkToGo Commerce — S14: Customer-ID layer (opaque, no PII)
--  Migration: 2026_10_06_014_commerce_customer_ids
-- ----------------------------------------------------------------------------
--  PURPOSE (core-completion-plan §2 S14; CEO C.4):
--    A stable, OPAQUE Customer ID per customer so a seller can recognise a repeat
--    buyer — and see that ID's own history WITH THEM — without ever seeing a name,
--    phone or address (seller stays blind, S10). One row maps an internal user to
--    a random public ref; the ref is all the seller ever sees. The seller-facing
--    history is computed live from orders/order_items (no PII columns selected) and
--    is isolated per seller (vendor_id) — no cross-seller leakage. Builds on S10
--    seller-hidden + S3 signals + S9 per-seller settlement.
--
--  SAFETY / IDEMPOTENCY
--    * CREATE TABLE IF NOT EXISTS; unique on both user_id and customer_ref.
--      ADDITIVE only; no change to orders/order_items/engine (the ref is derived
--      from the existing orders.user_id, so no order schema change is needed).
--    * 10.4-safe: no COMMENT-after-CHECK, no CHECK constraints.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `commerce_customer_ids` (
  `user_id`     bigint(20) UNSIGNED NOT NULL,
  `customer_ref` varchar(24) NOT NULL,
  `created_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uniq_customer_ref` (`customer_ref`)
) ENGINE=InnoDB;
