# Commerce + Delivery — Readiness, Edge-Case & Security Audit (READ-ONLY)

> **Purpose:** A rigorous, honest, stress-tested assessment of the commerce + delivery build (Phases 1–5) before it touches real money and real deliveries. The founder's standard: *"the real world will try to break it — will it break or hold?"* This audit tries to break it on paper and does not gloss over gaps.
> **Method:** READ-ONLY review of the `v2` commerce/delivery code (commits `f2faf55`, `5d8fb9f`, `5ab5daa`, `230eb0d`, `b5b0049`) against the COMMERCE docs 01–07. Every finding cites real code. Nothing edited.
> **Date:** 2026-08-22 · Branch `v2`. No PHP runtime here — dynamic checks are flagged as staging items.

---

## HEADLINE

**Readiness for "tested + launch-ready (real money + real deliveries)": ≈ 55%.** The scaffolding is real and much of it is production-grade — but it is **not safe to run real money or real deliveries yet**, for three concrete reasons: (1) **order settlement is completely unwired** — `LedgerEngine::processOrderCompletion` has **no caller**, so no vendor earning, platform commission, rider payout, or COD cash is ever booked, and the cash-limit guard is therefore dead; (2) several **founder-named delivery edge cases would genuinely break** — no-rider orders sit *pending forever*, a rider who abandons after pickup leaves a *stuck* delivery, and cancelling an order *orphans* its delivery; (3) a real **IDOR** lets any logged-in customer enumerate any order's rider name, phone, and live location.

- **Biggest strengths (genuinely solid):** the reused `OrderService` (server-authoritative amounts, `FOR UPDATE` stock locks, checkout-time re-validation, rate-limit); the **lock-safe rider assignment** (`FOR UPDATE` + `SKIP LOCKED` — double-assignment really is prevented); the **wallet `reference_type` collision fix** (order vs booking money can't cross); RBAC-gated, fail-closed admin/delivery control-rooms; per-user order scoping.
- **Biggest gaps/edge-cases:** order settlement unwired (money is inert); no delivery cron (no stuck / no-rider / late detection, no auto-retry, no escalation); order-cancel doesn't cancel the delivery; rider-reject can bounce back to the same rider; no smart batching; multi-vendor order → single delivery; no city enforcement at checkout; client-supplied drop coordinates let the delivery fee be shaved.
- **Security count:** **1 HIGH** (delivery-track IDOR), **5 MEDIUM**, **4 LOW**, plus **1 CRITICAL functional/money gap** (settlement unwired).
- **Verdict:** a real, working *happy-path* system — order → priced delivery → nearest rider → tracking demonstrably flows — but it will **break** on the adversarial and unhappy paths the founder named, and it **moves no money**. **Not launch-ready** until the §6 must-fixes land; after them, yes.

---

## 1. FEATURE READINESS MATRIX

Legend: ✅ BUILT (functional, code-verified) · 🟡 PARTIAL (works with a named gap) · ⛔ MISSING.

| Capability | State | Honest one-line |
|---|---|---|
| Shop-type management (flexible, admin-addable) | ✅ BUILT | `store_types` CRUD; any shop type is a row, zero code (`admin/commerce/store-types`). |
| Store management (dual company/vendor, city, geo, logo) | ✅ BUILT | `stores` CRUD; company dark-store backs onto a canonical company vendor. |
| Product catalog (admin CRUD + inventory) | ✅ BUILT | Create/edit/status + photo→`media` mirrored into `products.images`; stock editor. |
| Inventory (stock per product) | 🟡 PARTIAL | One row per product (grain unchanged); **per-store stock deferred**; no stock-in audit trail. |
| Cart | ✅ BUILT | Reuses `CartService` (atomic upsert, TTL). |
| Checkout (COD) | ✅ BUILT | Reuses `OrderService::create` (split, locks, rate-limit); server-authoritative amount. |
| Multi-vendor order split | ✅ BUILT | Parent + per-vendor sub-orders + item snapshots (engine). |
| Payment — COD | ✅ BUILT | COD end-to-end. |
| Payment — online (Cashfree) | ⛔ MISSING (by design) | Engine COD-locks online (`ALLOWED_PAYMENT_METHODS=['cod']`); one-const unlock + ₹1 test needed. |
| **Order settlement (commission/earnings/COD cash)** | ⛔ **MISSING** | `processOrderCompletion` has **no caller** — no money is ever booked. |
| Storefront browse (city-scoped, dual-store, company-first) | ✅ BUILT | `/api/shop/*` adapter reuses `ProductService`; skeleton/empty/error states. |
| Storefront product detail | ✅ BUILT | Reuses `ProductService::detail` + store context. |
| Storefront order tracking (status timeline) | ✅ BUILT | Order status timeline + rider card (via delivery track). |
| Delivery pricing (distance × admin rate card) | ✅ BUILT | `delivery_rate_cards` per city; `DeliveryEngine::quote`; shown at checkout. |
| Delivery creation on order | ✅ BUILT | `createForOrder` on checkout (clean adapter). |
| Rider assignment (nearest, lock-safe) | ✅ BUILT | `assignNearest` (`FOR UPDATE`+`SKIP LOCKED`+capacity+cash guard) — **one-shot only**. |
| Rider app (own login/scope) | ✅ BUILT | `/rider/` — online/location/queue/accept-pickup-deliver. |
| Admin commerce control-room | ✅ BUILT | Stores/types/catalog/stock. |
| Admin delivery control-room | 🟡 PARTIAL | Rate cards + live board + riders + manual reassign; **no stuck/late alerts, read-only monitoring**. |
| Delivery lifecycle mgmt (stuck / no-rider / late / escalation) | ⛔ MISSING | No cron, no timeout, no retry, no alert (§2). |
| Refunds (commerce) | 🟡 PARTIAL | `processRefund` exists (order-scoped) but no ledger rows to reverse until settlement runs; COD refund is off-ledger. |

**Overall build %:** the *surfaces* are ~80% built; **launch-readiness ≈ 55%** once money-wiring + edge-cases + security are weighted (they're the parts that decide whether it holds under stress).

---

## 2. DELIVERY EDGE CASES (founder-named — stress-tested)

| Edge case | Handled? | Reality (code-grounded) |
|---|---|---|
| **Rider assigned but not moving / order stuck** | ⛔ NO | Nothing detects a delivery sitting in `assigned`/`picked`. `eta_minutes` is **only ever read, never set** (`DeliveryEngine.php:321`). No cron watches deliveries (the only cron, `cron/auto_assign.php`, times out *vendor* `auto_assignments`, not deliveries). Admin sees the live board only if they look. **Needs a delivery-watchdog cron + timeout → alert/escalation.** |
| **No rider available** | ⛔ NO (breaks) | `assignNearest` returns null → delivery stays `pending`. `createForOrder` calls it **once**; there is **no retry loop, no queue, no alert**. The order sits pending **forever** until an admin manually reassigns. **Needs a retry cron + "no rider" alert.** |
| **Rider rejects after accepting** | 🟡 PARTIAL + BUG | `riderReject` (from `assigned`) sets pending and calls `assignNearest` — but it can **re-assign to the same rider** (the rejecter's `active_count` was decremented, so they're an eligible candidate again; no exclusion). After **pickup** there is *no reject path* → abandonment leaves it stuck at `picked`. |
| **Rider en-route problem → backup rider** | ⛔ NO | No "reassign a picked delivery" / backup-rider flow. Once `picked`, only the same rider can `deliver`. |
| **Batching (multiple orders / one rider, same-area)** | 🟡 PARTIAL | Capacity exists (`max_active`, `active_count`) so a rider can hold up to N concurrent — but assignment is **nearest-only, not same-area batched**; no route grouping. |
| **Duplicate / double assignment protection** | ✅ YES (real) | `assignNearest` locks the delivery `FOR UPDATE` (re-checks `status='pending'`) and locks candidate riders `FOR UPDATE SKIP LOCKED`, then `active_count++` in-txn — two deliveries can't grab the same rider, and one delivery can't be assigned twice. This is genuinely sound. |
| **Late / delayed detection + admin visibility** | ⛔ NO | No ETA set, no SLA, no late flag. Admin board shows status but not "overdue". |
| **COD cash reconciliation (collect + cash limit)** | ⛔ NO (dead) | `isDriverBlocked` compares `cash_in_hand ≥ collection_limit`, but `cash_in_hand` is only incremented by `processOrderCompletion` — which **has no caller**. So cash is never recorded, the limit **never triggers**, and there is no remittance/settlement flow. COD reconciliation is non-functional. |

**Delivery-edge verdict:** the *assignment mechanics* are strong, but the *lifecycle* (what happens when reality deviates) is largely unbuilt. Under stress this **breaks** (stuck/pending-forever/abandoned) rather than degrading gracefully.

---

## 3. COMMERCE EDGE CASES

| Edge case | Handled? | Reality |
|---|---|---|
| Out-of-stock at checkout | ✅ YES | `OrderService::getInventoryForUpdate` re-checks `active`+stock **inside** the txn with `FOR UPDATE`; rejects cleanly. |
| Price / amount tampering | ✅ YES (items) | Order amount is computed server-side from cart/DB prices, never from the client. **But** the **delivery fee** trusts client-supplied `drop_lat/drop_lng` (§4-MEDIUM). |
| Cart abandonment | ✅ OK | 30-day TTL on cart rows. |
| Multi-vendor partial fulfilment | 🟡 PARTIAL | Per-vendor sub-orders can progress/cancel independently, **but delivery creates ONE delivery from the primary store** — a 2-vendor order isn't split into 2 pickups/deliveries. |
| Order cancellation | 🟡 PARTIAL + BUG | `OrderService::cancel` releases inventory + cancels sub-orders, but **does not touch `deliveries`** (never-touch, delivery-unaware). Cancelling an order with an assigned rider **orphans** the delivery (rider still holds it, `active_count` not freed). |
| Refund path | 🟡 PARTIAL | Order-scoped `processRefund` + Cashfree refund exist, but there are **no ledger rows to reverse** until settlement is wired; COD has nothing to refund. |
| Concurrent stock (race) | ✅ YES | `SELECT … FOR UPDATE` serializes; `reserved`/`quantity` are race-safe. |
| City-mismatch (order from a store not in the customer's area) | ⛔ NO | Browse is city-scoped, but `/api/shop/cart` and checkout accept **any** `product_id` — a crafted request can cart a product from another city's store. Delivery would then price a huge distance (or `unavailable`) but the **order still places**. No city guard at cart/checkout. |

---

## 4. SECURITY (grouped by severity)

### 🔴 CRITICAL (functional/money)
- **C1 — Order settlement unwired → no money is safe *because none moves*.** `processOrderCompletion` has no caller (`grep` confirms only a comment references it). No vendor earning, platform commission, rider payout, or COD `cash_in_hand` is booked. Consequence beyond "no revenue": the **cash-limit guard is inert**, COD reconciliation doesn't exist, and refunds have nothing to reverse. This must be wired and staging-verified before real orders.

### 🟠 HIGH
- **H1 — IDOR on delivery tracking.** `GET /api/delivery/track/{orderId}` does `AuthMiddleware::requireRole(ROLE_CUSTOMER, ROLE_ADMIN)` then `DeliveryEngine::track($db, $orderId)` with **no check that the order belongs to the caller** (`heart/api/delivery/index.php:55-59`). Order ids are sequential, so any logged-in user can enumerate every order and read the assigned **rider's name, phone, and live lat/lng** plus status. PII + business-intel leak at scale. *(The customer's own order endpoints `/api/shop/orders/{id}` ARE scoped by `user_id` — this hole is only the delivery-track route.)*

### 🟡 MEDIUM
- **M1 — Delivery-fee manipulation.** The fee is distance-based from **client-supplied `drop_lat/drop_lng`** (`/api/shop/checkout` → `createForOrder`). A customer can send coordinates next to the store to zero/shrink the fee; the drop coords are never checked against the real address. Server-authoritative for items, **not for delivery**.
- **M2 — No city enforcement at cart/checkout** (see §3) — cross-city ordering is possible via direct API.
- **M3 — Order cancel orphans the delivery** (see §3) — a rider keeps an active, cancelled-order delivery; capacity leaks.
- **M4 — Rider reject can bounce to the same rider** (see §2) — no exclusion of the just-rejected rider.
- **M5 — No rate-limiting on shop/rider endpoints.** Only `OrderService` self-limits checkout (5/min). Cart-add, `/api/shop/delivery-quote`, rider location-ping, and public browse have **no throttle** — scrape/spam surface.

### 🟢 LOW
- **L1 — Public delivery quote** (`GET /api/delivery/quote`, no auth) leaks store geo via price probing. Low impact.
- **L2 — Multi-store cart quote uses only the first item's store** for pickup — fee inaccuracy for mixed carts (not a hole, a correctness nit).
- **L3 — `worktogo-company` vendor find-or-create** isn't locked; a rare concurrent race could 23000-fail one request.
- **L4 — Rider `lat/lng` not range-validated** on ping (authed; low risk).

### Security that IS solid (verified)
- **Order/cart/rider-action authorization is properly scoped:** `OrderService::detail/list` filter by `user_id`; rider accept/pickup/deliver check `driver_id = riderId` (`DeliveryEngine::ownedDelivery`). No IDOR there.
- **RBAC fail-closed:** commerce + delivery admin endpoints are behind `AuthMiddleware::requireRole(ROLE_ADMIN)` + `Permission::enforce('stores.*'/'delivery.*')`, which denies on any missing perm/table (Phase-1 RBAC). Rider endpoints require `ROLE_DELIVERY`.
- **Amount authority (items)** and **inventory locking** are server-side and race-safe.

---

## 5. MOTHERBOARD / MONEY SAFETY WITH BOTH ENGINES LIVE

- **No collision, no double-count — but for the wrong reason.** The `wallet_transactions.reference_type/reference_id` fix (migration 004, wired in `LedgerEngine`) means order money (`reference_type='order'`) and booking money (`'booking'`) can never cross — this is real and correct. However, **commerce writes no ledger rows at all today** (settlement unwired), so the *only* reason there's no collision right now is that commerce money is inert. When `processOrderCompletion` is wired, the `reference_type` separation is what keeps it safe — so the fix is the right foundation, it just isn't exercised yet.
- **Service side is unaffected:** `processBookingCompletion` (called from the service engine + admin) continues to settle bookings with `reference_type='booking'`. Turning commerce on does not disturb it.
- **Delivery money is also inert:** rider payout + COD cash live only as columns on `deliveries`/`orders.delivery_fee`; nothing books them into `driver_wallets` until settlement runs.
- **The status-mirror overlap to watch:** both `DeliveryEngine::mirrorOrder` and the never-touch `OrderService::updateVendorOrderStatus` write `orders.status`; and a customer cancel + a late delivery-mirror could fight over the same order's status. Low money impact, but a state-consistency edge to resolve when settlement is wired (settlement should key off a single authoritative "delivered" signal).

**Bottom line:** money is *structurally* safe (the collision fix is correct), but **operationally inert** — the system cannot yet handle real money because it doesn't move any, and the one path that will move it (`processOrderCompletion`) is unwired and unverified.

---

## 6. WHAT'S NEEDED FOR "TESTED + LAUNCH-READY"

**Must-fix (code) — ordered by risk:**
1. **Wire order settlement** — call `processOrderCompletion` on the authoritative "delivered" event (recommend: `DeliveryEngine::riderDeliver`), booking vendor earning / platform commission / rider payout / COD `cash_in_hand`; then staging-verify the ledger with `reference_type='order'`. *(~1–1.5 days; unblocks COD reconciliation + the cash-limit guard.)* **CRITICAL.**
2. **Fix the delivery-track IDOR** — scope `track` to the order's `user_id` (or a tracking token). *(~2 hrs.)* **HIGH.**
3. **Delivery watchdog cron** — detect `pending` (no-rider) → retry `assignNearest` + alert; `assigned`/`picked` past an SLA → flag "stuck/late" on the admin board + alert. *(~1–2 days.)* Closes no-rider-forever, stuck, and late-detection at once.
4. **Cancel → cancel delivery** — a delivery-aware cancel path (permitted adapter) that, when an order is cancelled, cancels its delivery + frees the rider's `active_count`. *(~half day.)*
5. **Rider reject exclusion + post-pickup handover** — exclude the just-rejected rider from immediate reassignment; add an admin/rider "handover/backup" path for a `picked` delivery. *(~half day.)*
6. **Delivery-fee integrity** — derive the drop location server-side (geocode the saved address) or validate client coords against it; don't price purely on client-sent lat/lng. *(~half day.)*
7. **City guard at cart/checkout** — reject carting/checkout of a product whose store isn't in the customer's city. *(~2 hrs.)*
8. **Rate-limit** cart-add, delivery-quote, and rider-ping. *(~2 hrs.)*
9. **Multi-vendor delivery** (if launching multi-store) — one delivery per vendor sub-order. *(~1 day; can defer if launching dark-store-only.)*

**Staging-only (no code — environment):**
- Run the three commerce migrations (`2026_08_22_001/002/003`) on a prod-dump copy; verify each block.
- Cashfree **online** (only if enabling): un-COD-lock the engine (surfaced 1-line decision) + ₹1 live round-trip; confirm the webhook books `reference_type='order'`.
- Onboard a real `role='delivery'` rider account + confirm geolocation on a real device.
- Load/soak the assignment path (concurrent orders) to confirm `SKIP LOCKED` holds under contention.

**Rough total to launch-ready:** ~**5–7 focused engineering-days** of must-fixes + the staging pass.

---

## 7. HONEST VERDICT

**Is it a real, functioning system? — Yes, on the happy path.** A customer can browse a city-scoped, company-first storefront, add to cart, check out COD with a distance-based fee, have a delivery auto-created and priced, get the nearest online rider assigned (with genuinely sound locking), watch the status advance as the rider accepts/picks/delivers, and the admin can manage rate cards + watch a live board. The reused order core and the assignment locking are production-grade. That is not a mock.

**Is it launch-ready for real money + real deliveries? — No, not yet.** Weighted honestly it is **~55%**. It will **hold** on the happy path but **break** exactly where the founder expected the real world to push: **no rider → stuck forever; rider abandons after pickup → stuck; order cancelled → orphaned delivery; and it books no money at all** (settlement unwired → no payouts, no commission, no COD reconciliation, dead cash-limit). Plus a real **IDOR** leaking rider PII.

**Biggest risks (ranked):**
1. **Money is inert** (`processOrderCompletion` unwired) — you cannot safely take real orders that never settle.
2. **Delivery lifecycle gaps** — no-rider/stuck/late/cancel-cascade will produce hung orders and confused customers/riders on day one.
3. **Delivery-track IDOR** — rider PII/location enumerable by any user.
4. **Fee + city integrity** — client can shave the delivery fee; cross-city orders slip through.

**Is it safe to handle real money + real deliveries once the §6 must-fixes land? — Yes.** The foundations that are hard to get right (server-authoritative amounts, transactional inventory, lock-safe assignment, the ledger collision fix, fail-closed RBAC, per-user scoping) are already correct. The must-fixes are **wiring + lifecycle + a handful of guards**, not a redesign — ~5–7 days plus the staging pass. Land those, verify on staging, and this holds under real-world stress. Ship it before them and it will visibly break.

---

*Read-only audit of the Phase 1–5 commerce/delivery code on `v2`. Every finding is grounded in cited code; the design was stress-tested against the founder's named edge cases. No files were modified. This is the pre-launch punch-list.*
