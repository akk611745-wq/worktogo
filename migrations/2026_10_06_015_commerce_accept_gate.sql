-- ============================================================================
--  WorkToGo Commerce — S15: seller accept-gates-dispatch flag
--  Migration: 2026_10_06_015_commerce_accept_gate
-- ----------------------------------------------------------------------------
--  PURPOSE (core-completion-plan §4; CEO decision A):
--    With external sellers, the rider must be dispatched only AFTER the seller
--    accepts — no wasted trip to a shop that rejects. This flag switches the order
--    lifecycle: ON (default) → checkout only marks the sub-order OFFERED (S3) and
--    the dispatch (DeliveryEngine + S6 handoff/OTP) happens on seller-accept
--    (SellerConsole); OFF → legacy dispatch-at-checkout. Admin-tunable.
--
--  SAFETY / IDEMPOTENCY: INSERT IGNORE on the unique setting_key; additive only;
--    no engine change; 10.4-safe (no COMMENT-after-CHECK).
-- ============================================================================

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_accept_gates_dispatch', '1', 'boolean',
   'Commerce: dispatch the rider only after the seller accepts (1=on, the model default; 0=legacy dispatch-at-checkout)', 0);
