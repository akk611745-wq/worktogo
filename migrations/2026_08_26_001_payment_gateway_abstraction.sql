-- ============================================================================
--  WorkToGo V2 — Payment Abstraction Layer: gateway attribution + routing
--  Migration: 2026_08_26_001_payment_gateway_abstraction
-- ----------------------------------------------------------------------------
--  PURPOSE
--    Support the multi-gateway payment abstraction (Cashfree now, Razorpay and
--    others by config) with failover, WITHOUT weakening any existing money
--    safety and WITHOUT breaking the current Cashfree-only flow.
--
--    Two things are added:
--      1. orders.payment_gateway  + bookings.payment_gateway  (VARCHAR, NULLABLE)
--         Records WHICH gateway created/settled each payment so the webhook can
--         verify + settle with the correct gateway, and so failover is auditable.
--         NULL is treated as 'cashfree' everywhere (backward-compatible): every
--         existing row and the inline service-engine path keep working untouched.
--      2. app_settings['payment_gateway_routing'] (JSON) — the admin-editable
--         override for per-engine primary/secondary selection. Seeded to the
--         same safe defaults as config/payment.gateways.php (cashfree primary,
--         no secondary). If this row is absent the code falls back to the config
--         file, so this is a convenience/visibility seed, not a hard dependency.
--
--    transactions.gateway already exists (varchar(40)) — the code now writes the
--    real gateway there instead of a hardcoded 'cashfree'. No change needed here.
--
--  ENGINE INDEPENDENCE: the JSON has one entry PER ENGINE (commerce/service/
--    video), so an admin can re-route or add a secondary for one engine without
--    touching another. Nothing here alters engine tables' behaviour.
--
--  SAFETY / IDEMPOTENCY: ADD COLUMN IF NOT EXISTS; INSERT IGNORE on the unique
--    setting_key. No DROP, no backfill of existing rows (NULL == cashfree by
--    convention), no data loss. Nullable columns with no default change = zero
--    risk to existing INSERTs. InnoDB / utf8mb4.
--
--    ⚠ STAGING ONLY — do NOT run on production from here. Apply on staging via
--    migrate.php and verify with the (commented) checks at the bottom first.
-- ============================================================================

-- ── 1. Gateway attribution columns (nullable ⇒ NULL means 'cashfree') ─────────
ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `payment_gateway` varchar(40) NULL DEFAULT NULL
    COMMENT 'Gateway that handled this payment (cashfree|razorpay|…); NULL=cashfree (legacy)'
    AFTER `payment_id`;

ALTER TABLE `bookings`
  ADD COLUMN IF NOT EXISTS `payment_gateway` varchar(40) NULL DEFAULT NULL
    COMMENT 'Gateway that handled this payment (cashfree|razorpay|…); NULL=cashfree (legacy)'
    AFTER `payment_id`;

-- ── 2. Admin-editable per-engine routing override (safe defaults) ─────────────
--    Mirrors config/payment.gateways.php. Set an engine's "secondary" to
--    "razorpay" (and enable it in config) to arm failover for that engine.
INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `label`, `is_public`)
VALUES (
  'payment_gateway_routing',
  '{"default":{"primary":"cashfree","secondary":null},"commerce":{"primary":"cashfree","secondary":null},"service":{"primary":"cashfree","secondary":null},"video":{"primary":"cashfree","secondary":null}}',
  'json',
  'Payment: per-engine gateway routing (primary/secondary + failover)',
  0
);

-- ============================================================================
--  VERIFICATION (run manually on staging AFTER applying — do not uncomment here)
-- ----------------------------------------------------------------------------
--  SHOW COLUMNS FROM `orders`   LIKE 'payment_gateway';   -- expect 1 row, NULLable
--  SHOW COLUMNS FROM `bookings` LIKE 'payment_gateway';   -- expect 1 row, NULLable
--  SELECT setting_key, value_type, setting_value
--    FROM app_settings WHERE setting_key = 'payment_gateway_routing';
--  -- Existing rows must still read payment_gateway = NULL (⇒ treated as cashfree):
--  SELECT id, payment_status, payment_gateway FROM orders   ORDER BY id DESC LIMIT 5;
--  SELECT id, payment_status, payment_gateway FROM bookings ORDER BY id DESC LIMIT 5;
-- ============================================================================
