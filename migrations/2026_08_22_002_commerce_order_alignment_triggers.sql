-- ============================================================================
--  WorkToGo V2 — Commerce Phase 2: Order-system alignment (data-layer triggers)
--  Migration: 2026_08_22_002_commerce_order_alignment_triggers
-- ----------------------------------------------------------------------------
--  PURPOSE (COMMERCE/commerce-04-order-alignment.md)
--    Connect the production-grade, never-touch order engine (OrderService /
--    CartService / ProductController — REUSED AS-IS) to the Phase-1 commerce
--    data model (stores, city_id) WITHOUT editing a single line of that engine.
--
--    The engine's INSERTs list explicit columns that do NOT include the new
--    store_id / city_id (by design — the engine predates them). Rather than
--    edit the never-touch engine, this migration adds fail-safe DATABASE
--    TRIGGERS that DERIVE store_id / city_id from the rows the engine already
--    writes (vendor_id, product_id, user_id, order_id). Every product,
--    inventory row, order, order item, and delivery therefore lands correctly
--    aligned to the store/city model — for the current dormant engine AND for
--    any future writer (admin, seller API) — with zero engine changes.
--
--  WHY TRIGGERS (the clean, no-jugaad, no-never-touch-edit approach)
--    * Order/product creation lives ONLY in never-touch files. A trigger is the
--      one place we can guarantee alignment without touching them.
--    * Atomic + money-safe: triggers run INSIDE the engine's own transaction, so
--      alignment commits exactly with the order — never a fragile after-the-fact
--      step, never a partial state.
--    * Data-driven: values are DERIVED from real rows (the vendor's store, the
--      product's store, the user's city) — nothing hardcoded.
--    * All-paths: works no matter which code path inserts (engine now; admin /
--      seller-API later).
--    * NON-DESTRUCTIVE + future-proof: every assignment is COALESCE(NEW.col, …),
--      i.e. it only fills a value when the caller left it NULL. The day the
--      engine (or a new writer) sets store_id / city_id EXPLICITLY, that explicit
--      value wins and the trigger is a no-op — so triggers never fight a future
--      explicit-write refactor; they are a safety net that quietly steps aside.
--    * FAIL-SAFE: every derivation is a scalar subquery with LIMIT 1 (never
--      multi-row) and COALESCE fallbacks; NO trigger ever raises an error or
--      SIGNAL, so a trigger can NEVER roll back / block an order. Worst case a
--      column stays NULL (harmless) rather than failing the sale.
--
--  MONEY-SAFETY (unchanged — confirmed, not modified here)
--    These triggers touch ONLY the descriptive store_id / city_id columns. They
--    do NOT touch money: no wallet_transactions, no totals, no ledger. The
--    order/booking ledger collision fix stays exactly as wired in LedgerEngine —
--    processOrderCompletion tags wallet rows reference_type='order' + reference_id
--    = order_id, processBookingCompletion tags 'booking' + booking_id, and reads
--    are scoped by walletRefMatch — so service bookings and commerce orders can
--    never cross in the ledger. store_id / city_id are not read by any money path
--    (verified: LedgerEngine reads vendor_id/total/subtotal/delivery_fee/
--    commission_rate/delivery_id/payment_method only). No double-count is
--    possible from this migration.
--
--  DEPENDS ON: 2026_08_22_001_commerce_store_foundation (the store_id/city_id
--    columns + stores table). migrate.php applies in filename order, so 001
--    (this date) runs before 002. Also uses users.city_id (2026_07_04_001) and
--    vendors (base).
--
--  OPERATIONAL CAVEAT (flag for founder/CTO): creating triggers needs the DB
--    user to hold the TRIGGER privilege. Hostinger shared MySQL usually grants it
--    to the schema owner, but VERIFY on staging. If the host does NOT allow
--    triggers, the documented fallback is the explicit-write alternative in
--    commerce-04-order-alignment.md §5 (a minimal, surfaced edit to the engine's
--    create paths, to be decided in the activation phase) — NOT applied here.
--
--  SAFETY / IDEMPOTENCY: DROP TRIGGER IF EXISTS before each CREATE (re-run safe).
--    Every trigger body is a SINGLE statement (multi-assignment SET, or one
--    UPDATE) — no BEGIN…END, no internal semicolons — so it runs cleanly through
--    migrate.php's PDO exec() the same way the other migrations do. InnoDB /
--    utf8mb4. DO NOT run against prod from here — STAGING first, verify, then prod.
-- ============================================================================


-- ── 1. products: new product → its vendor's store + city ────────────────────
--    ProductController::create inserts products(vendor_id, …) without store_id/
--    city_id. Derive the vendor's (single) store and the vendor's city.
DROP TRIGGER IF EXISTS `trg_products_commerce_align`;
CREATE TRIGGER `trg_products_commerce_align`
BEFORE INSERT ON `products`
FOR EACH ROW
SET
  NEW.`store_id` = COALESCE(
    NEW.`store_id`,
    (SELECT s.`id` FROM `stores` s
       WHERE s.`owner_vendor_id` = NEW.`vendor_id` AND s.`ownership` = 'vendor'
       ORDER BY (s.`status` = 'active') DESC, s.`id` ASC
       LIMIT 1)
  ),
  NEW.`city_id` = COALESCE(
    NEW.`city_id`,
    (SELECT v.`city_id` FROM `vendors` v WHERE v.`id` = NEW.`vendor_id`)
  );


-- ── 2. inventory: new stock row → the product's store + city ────────────────
--    ProductController::create inserts the inventory row right after the product
--    (same txn), so the product (with its just-derived store/city) is visible.
DROP TRIGGER IF EXISTS `trg_inventory_commerce_align`;
CREATE TRIGGER `trg_inventory_commerce_align`
BEFORE INSERT ON `inventory`
FOR EACH ROW
SET
  NEW.`store_id` = COALESCE(NEW.`store_id`, (SELECT p.`store_id` FROM `products` p WHERE p.`id` = NEW.`product_id`)),
  NEW.`city_id`  = COALESCE(NEW.`city_id`,  (SELECT p.`city_id`  FROM `products` p WHERE p.`id` = NEW.`product_id`));


-- ── 3. orders: new order → customer's city + (sub-order) vendor's store ─────
--    OrderService::create inserts a parent order (vendor_id NULL) then per-vendor
--    sub-orders (vendor_id set). city comes from the customer (user); store from
--    the vendor's store (NULL on the parent, which has no vendor — correct).
DROP TRIGGER IF EXISTS `trg_orders_commerce_align`;
CREATE TRIGGER `trg_orders_commerce_align`
BEFORE INSERT ON `orders`
FOR EACH ROW
SET
  NEW.`store_id` = COALESCE(
    NEW.`store_id`,
    (SELECT s.`id` FROM `stores` s
       WHERE s.`owner_vendor_id` = NEW.`vendor_id` AND s.`ownership` = 'vendor'
       ORDER BY (s.`status` = 'active') DESC, s.`id` ASC
       LIMIT 1)
  ),
  NEW.`city_id` = COALESCE(
    NEW.`city_id`,
    (SELECT u.`city_id` FROM `users` u WHERE u.`id` = NEW.`user_id`)
  );


-- ── 4. order_items: new line → the product's store + the order's city ───────
--    store_id = the fulfilling store (supply); city_id = the order's delivery
--    city (demand), falling back to the product's city.
DROP TRIGGER IF EXISTS `trg_order_items_commerce_align`;
CREATE TRIGGER `trg_order_items_commerce_align`
BEFORE INSERT ON `order_items`
FOR EACH ROW
SET
  NEW.`store_id` = COALESCE(NEW.`store_id`, (SELECT p.`store_id` FROM `products` p WHERE p.`id` = NEW.`product_id`)),
  NEW.`city_id`  = COALESCE(
    NEW.`city_id`,
    (SELECT o.`city_id` FROM `orders`   o WHERE o.`id` = NEW.`order_id`),
    (SELECT p.`city_id` FROM `products` p WHERE p.`id` = NEW.`product_id`)
  );


-- ── 5. order_items → back-fill the parent sub-order's store/city if still NULL
--    Belt-and-braces: if the sub-order's vendor-store lookup (trigger 3) found
--    nothing but the line's product HAS a store, adopt it. COALESCE means a store
--    already set is never overwritten. Single UPDATE (no internal semicolons).
DROP TRIGGER IF EXISTS `trg_order_items_propagate_store`;
CREATE TRIGGER `trg_order_items_propagate_store`
AFTER INSERT ON `order_items`
FOR EACH ROW
UPDATE `orders`
   SET `store_id` = COALESCE(`store_id`, NEW.`store_id`),
       `city_id`  = COALESCE(`city_id`,  NEW.`city_id`)
 WHERE `id` = NEW.`order_id`;


-- ── 6. deliveries: new delivery → its order's store + city ──────────────────
--    Preps the native delivery engine (Phase C4): OrderService::dispatchToDelivery
--    and the admin assign path insert deliveries(order_id, …) without store/city.
DROP TRIGGER IF EXISTS `trg_deliveries_commerce_align`;
CREATE TRIGGER `trg_deliveries_commerce_align`
BEFORE INSERT ON `deliveries`
FOR EACH ROW
SET
  NEW.`store_id` = COALESCE(NEW.`store_id`, (SELECT o.`store_id` FROM `orders` o WHERE o.`id` = NEW.`order_id`)),
  NEW.`city_id`  = COALESCE(NEW.`city_id`,  (SELECT o.`city_id`  FROM `orders` o WHERE o.`id` = NEW.`order_id`));


-- ============================================================================
--  VERIFICATION (run on staging after applying)
-- ----------------------------------------------------------------------------
--  -- All six triggers present:
--  SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING
--    FROM information_schema.TRIGGERS
--   WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'trg_%commerce%'
--      OR TRIGGER_NAME LIKE 'trg_order_items_%';                 -- expect 6 rows
--
--  -- FUNCTIONAL SMOKE TEST (on a staging copy; roll back after):
--  --   Given a shopping vendor V (type='shopping') with a store S (from Phase 1)
--  --   in city C, and a customer U in city C:
--  --   1) INSERT a product for V (no store_id/city_id) → row gets store_id=S,
--  --      city_id=V.city.  Its inventory row inherits store_id=S, city_id=C.
--  --   2) INSERT an order for U with a vendor sub-order for V, and an order_item
--  --      for the product → order.city_id=C, sub-order.store_id=S, item.store_id=S,
--  --      item.city_id=C.
--  --   3) INSERT a deliveries row for that order → delivery.store_id=S, city_id=C.
--  --   Assert none of these required any change to OrderService/ProductController.
--
--  -- MONEY-SAFETY (unchanged): the triggers write no wallet/ledger rows —
--  SELECT COUNT(*) FROM information_schema.TRIGGERS
--   WHERE TRIGGER_SCHEMA = DATABASE()
--     AND ACTION_STATEMENT LIKE '%wallet_transactions%';         -- expect 0
-- ============================================================================
