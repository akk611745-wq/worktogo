# Commerce 04 — Order-System Reuse & Alignment (Phase 2)

> **Purpose:** Connect the production-grade, never-touch order engine to the Phase-1 commerce data model (stores, city_id, media) so orders flow cleanly through the new structure — **reusing the proven order logic unchanged**, aligned via a data-layer adapter, money-safe, without breaking the engine.
> **Delivered by:** migration `2026_08_22_002_commerce_order_alignment_triggers.sql` (STAGING; not run here). Builds on Phase-1 `2026_08_22_001_commerce_store_foundation.sql` (commit `f2faf55` in this worktree — see note).
> **Standard:** Better than Amazon, no jugaad. Reuse the order core as-is; add only the alignment; keep money-safety first.
> **Date:** 2026-08-22.
>
> **Commit-hash note:** the task referenced Phase-1 as `13f9dd7`; that object does not exist in this `v2` worktree. The Phase-1 store-foundation commit here is **`f2faf55`** and its migration file is present. Flagging so the founder/CTO can reconcile (likely a different environment's hash).

---

## 1. What is REUSED AS-IS (the production-grade order engine — zero edits)

Read in full; **not one line changed**. `body/shopping-engine/api/orders/OrderService.php` (+ `OrderController`, `CartService`, `ProductController`) is genuinely production-grade and is reused exactly:

| Order capability | Where | Reused behaviour (unchanged) |
|---|---|---|
| **COD checkout** | `OrderService::create` | Rate-limit (5 parent orders/user/60s), address validation, COD hard-lock (`ALLOWED_PAYMENT_METHODS=['cod']`), empty/zero-total guards. |
| **Multi-vendor split** | `create` `:216-298` | Parent order + per-vendor sub-orders + `order_items` snapshots. |
| **Inventory locking** | `getInventoryForUpdate` `:799`, `create` | `SELECT … FOR UPDATE` inside the txn; checkout-time product-active re-validation; `reserved += qty` on checkout, physical `quantity` deducted only on delivery, released on cancel. |
| **Vendor status machine** | `updateVendorOrderStatus` `:429` | Guarded pending→confirmed→processing→shipped→delivered transitions with stock effects. |
| **Cancellation** | `cancel` `:337` | Releases reserved inventory across sub-orders; cancels parent + sub-orders. |
| **Auto-dispatch hook** | `dispatchToDelivery` `:965` | Creates a `deliveries` row + notifies delivery (external today; native later). |
| **N+1-safe reads / history** | `vendorList`, `list`, `detail` | Batch item fetch; paginated. |
| **Product + inventory create** | `ProductController::create` | Inserts `products` then an `inventory` row in one txn. |
| **Money settlement** | `LedgerEngine::processOrderCompletion` | Order ledger split, already collision-safe (see §4). Reused unchanged. |

**Nothing about this logic is rewritten, patched, or wrapped.** The engine keeps its exact behaviour.

---

## 2. What needed ALIGNMENT (and why it couldn't be code)

The engine's `INSERT`s use explicit column lists that predate — and therefore omit — the Phase-1 `store_id` / `city_id` columns:
- `OrderService::create` → `INSERT INTO orders (order_number, …, notes, …)` — no `store_id`/`city_id`.
- order-item insert → `INSERT INTO order_items (order_id, product_id, vendor_id, …)` — no `store_id`/`city_id`.
- `ProductController::create` → `INSERT INTO products (vendor_id, category_id, …)` then `INSERT INTO inventory (product_id, quantity, …)` — no `store_id`/`city_id`.

So new rows would land with `store_id`/`city_id` = NULL. To fix that **in code** would require editing those `INSERT`s — but they live **only** in never-touch files. Per the task's own guidance ("if order creation truly lives only in a never-touch file, propose the cleanest safe approach and flag it"), the alignment is done at the **data layer** instead.

---

## 3. The alignment: fail-safe data-layer triggers (NEW, permitted, no engine edit)

Six `BEFORE`/`AFTER INSERT` triggers derive the new columns from the rows the engine already writes:

| Trigger | On | Derives |
|---|---|---|
| `trg_products_commerce_align` | products (BEFORE) | `store_id` ← the vendor's store; `city_id` ← the vendor's city |
| `trg_inventory_commerce_align` | inventory (BEFORE) | `store_id`/`city_id` ← the product |
| `trg_orders_commerce_align` | orders (BEFORE) | `store_id` ← the (sub-order) vendor's store; `city_id` ← the customer's city |
| `trg_order_items_commerce_align` | order_items (BEFORE) | `store_id` ← the product; `city_id` ← the order's city |
| `trg_order_items_propagate_store` | order_items (AFTER) | back-fills the sub-order's `store_id`/`city_id` from the line if still NULL |
| `trg_deliveries_commerce_align` | deliveries (BEFORE) | `store_id`/`city_id` ← the order |

**Design properties (why this is world-class, not a hack):**
- **Atomic / money-safe** — triggers run inside the engine's own transaction; alignment commits exactly with the order.
- **Data-driven** — every value is derived from a real row (vendor's store, product's store, user's city); nothing hardcoded.
- **All writers** — the current dormant engine, and any future admin/seller-API writer, all self-align.
- **Never fights a future refactor** — every assignment is `COALESCE(NEW.col, …)`, so an explicit write (if the engine is ever opened) **wins** and the trigger no-ops. Triggers are a safety net that steps aside.
- **Fail-safe** — every derivation is a `LIMIT 1` scalar subquery with `COALESCE` fallbacks; **no `SIGNAL`, no error path**, so a trigger can **never** roll back or block an order. Worst case a column stays NULL (harmless), never a lost sale.
- **Single-statement bodies** — each trigger is one multi-assignment `SET` or one `UPDATE` (no `BEGIN…END`, no internal `;`), so it runs cleanly through `migrate.php`'s `$pdo->exec()`.

**Products/inventory ↔ stores tie (task point 2, confirmed):** Phase-1 backfilled existing `products.store_id` / `inventory.store_id`; these triggers keep **new** products and inventory tied to the vendor's store automatically. `inventory` grain is unchanged (one row per product), so `OrderService`'s `WHERE product_id = ?` locking is untouched.

---

## 4. MONEY-SAFETY (confirmed, unchanged — no double-count)

- The triggers write **only** the descriptive `store_id`/`city_id`. They touch **no** money: no `wallet_transactions`, no totals, no ledger. (Verification query in the migration asserts no trigger references `wallet_transactions`.)
- The **order/booking collision fix stays exactly as wired** in `LedgerEngine`: `processOrderCompletion` tags wallet rows `reference_type='order'` + `reference_id=order_id`; `processBookingCompletion` tags `'booking'` + `booking_id`; reads are scoped by `walletRefMatch`. Service bookings and commerce orders **cannot cross** in the ledger.
- No money path reads `store_id`/`city_id` (LedgerEngine reads `vendor_id/total/subtotal/delivery_fee/commission_rate/delivery_id/payment_method` only), so this alignment introduces **zero** double-count or settlement risk.
- Locking + idempotency are the engine's, unchanged: `FOR UPDATE` inventory locks; `processOrderCompletion` idempotent via `orders.ledger_status` + a scoped existing-ledger-row check.

> Separate/known (NOT this task): `processOrderCompletion` still has **no caller** — commerce orders don't yet *trigger* settlement. That wiring is gap-map Phase **C1** (a permitted change), tracked there. This task keeps the money model safe and aligned; it does not turn settlement on.

---

## 5. NEVER-TOUCH FLAG + the explicit-write alternative (flagged, NOT applied)

**Did anything require touching `shopping-engine` / `orders.js`? — NO.** The alignment is entirely in a new migration; the engine is untouched.

**The alternative, for the founder/CTO to decide in the activation phase** (documented, deliberately **not** applied): when the engine is intentionally opened to un-dormant shopping, the same alignment could instead be done by explicit writes — the minimal, surfaced edit would be:
- `OrderService::create`: add `store_id`, `city_id` to the `orders` and `order_items` `INSERT` column lists, deriving `city_id` from the checkout payload / user and `store_id` from the vendor's store (or the product's store per line).
- `ProductController::create`: add `store_id`, `city_id` to the `products` `INSERT` (from the vendor's store) and to the `inventory` `INSERT`.

**Recommendation:** keep the **triggers** as the standing alignment (they require no engine edit and self-heal all write paths). If/when the engine is opened for the activation phase, add the explicit writes too **as clarity** — the `COALESCE` triggers will then no-op, so the two never conflict, and the triggers remain a safety net for the admin/seller-API paths. Choosing explicit-writes-only (and dropping the triggers) is also valid — it's a founder/CTO call, surfaced here, not made unilaterally.

**Operational caveat (verify on staging):** `CREATE TRIGGER` needs the DB user's **TRIGGER privilege**. If Hostinger's shared MySQL doesn't grant it to the schema owner, fall back to the explicit-write approach above.

---

## 6. REUSE vs EDIT vs NEW

- **REUSE AS-IS:** the entire order engine (`OrderService`/`OrderController`/`CartService`/`ProductController`) and `LedgerEngine` — behaviour unchanged, zero edits.
- **EDIT:** none. (No never-touch file, and no other file, was modified.)
- **NEW:** one migration adding six fail-safe alignment triggers (`2026_08_22_002_…`) + this doc.

---

## 7. Self-documentation

This file joins `commerce-01/02/03` in `WTG-SYSTEM-BRAIN/COMMERCE/`. The migration is the in-repo, self-documenting record of the alignment (committed on `v2` with the engine). A separate commerce team can read exactly what's reused, how alignment works, why it's money-safe, and the explicit-write option — without re-reading the engine.

---

## OPEN QUESTIONS (founder / CTO)

1. **Triggers vs explicit writes** — approve triggers as the standing alignment (recommended), or prefer explicit writes in the engine during activation (a surfaced never-touch edit)? They can coexist (triggers no-op under COALESCE).
2. **TRIGGER privilege on the host** — confirm Hostinger grants it on staging; if not, we use the explicit-write fallback.
3. **`order_items.city_id` semantics** — set to the **order's delivery city** (chosen), vs the fulfilling store's city. They're identical single-city; confirm the delivery-city choice for multi-city reporting.
4. **Phase-1 hash reconciliation** — task cited `13f9dd7`; this worktree's Phase-1 commit is `f2faf55`. Confirm which environment is canonical.
