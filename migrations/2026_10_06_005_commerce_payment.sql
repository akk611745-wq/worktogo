-- ============================================================================
--  WorkToGo Commerce — S5: payment methods config (online primary + COD fallback)
--  Migration: 2026_10_06_005_commerce_payment
-- ----------------------------------------------------------------------------
--  The multi-gateway abstraction already exists and is commerce-aware:
--    lib/payment/PaymentGatewayManager (primary→secondary FAILOVER, per-engine
--    routing, runtime-overridable via app_settings.payment_gateway_routing) +
--    PaymentGateway interface + CashfreeAdapter (live) + RazorpayAdapter (stub).
--    PaymentService::createOnlinePaymentOrder(..., 'commerce') + idempotent
--    processWebhookEvent() are reused as-is (webhook = sole paid authority).
--  This migration only adds the configurable ENABLED-METHODS list for commerce
--  (online primary, COD fallback). Enabling a 2nd gateway or failover stays
--  config-only (payment.gateways.php / the app_settings routing override) — no
--  code change. Additive; 10.4-safe; idempotent.
-- ============================================================================

INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`) VALUES
  ('commerce_payment_methods', 'online,cod', 'text',
   'Enabled commerce payment methods, priority order (online primary, COD fallback)', 0);
