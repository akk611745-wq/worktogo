# Commerce Engine 02 — Gap Map & Build Plan (READ-ONLY planning)

> **Purpose:** Turn the commerce current-state inventory (`commerce-audit-01-inventory.md`) into an actionable, dependency-ordered **build plan** for a separate Commerce engine (Shopping + own Delivery) that plugs into the V2 motherboard with its own admin control-room and its own team. This is the commerce equivalent of `V2-BUILD-BLUEPRINT.md` for the service engine.
> **Method:** READ-ONLY planning. Read `commerce-audit-01-inventory.md`, the V2 planning docs (`V2-VISION`, `V2-GAP-MAP`, `V2-BUILD-BLUEPRINT`, `V2-READINESS`), and re-verified the actual shopping/delivery/assignment code on branch `v2` (worktree `wtg-v2/`) + the prod dump. Every disposition cites real code (`file:line`). **No feature code was written; nothing edited.** `shopping-engine`, `video-engine`, `orders.js` read for planning only.
> **Founder standard applied throughout — NO jugaad.** Every piece gets one of four dispositions: **REUSE-AS-IS** (100% correct → lift into V2 unchanged) · **EDIT-TO-BEST** (good work, not best → improve, don't discard) · **BUILD-CLEAN** (useless/broken/missing → build proper) · **DISCARD** (throw away). The delivery engine (SwiftDeliver, the founder's own early Railway test build, unfinished, currently shut off) is judged the same way: salvage what's genuinely good, otherwise build clean — **no dependence on the unfinished external system.**
> **Date:** 2026-08-19 · Branch `v2`, tree clean · No PHP/Node runtime here (live-verify items flagged).

---

## HEADLINE

**Reuse-vs-build split:** the shopping **transaction core is REUSE-AS-IS** (production-grade and worth protecting); the **surfaces are BUILD-CLEAN** (no customer storefront, no company/inventory admin, no dual-store model); **delivery is DISCARD-the-external + BUILD-CLEAN-native** — and the good news is the motherboard already contains every native part needed (native `deliveries`/`driver_wallets` tables, `users.role='delivery'`, `isDriverBlocked`, `DeliveryPricingEngine`, and a proven lock-safe assignment cron), so a proper WTG delivery engine is a *composition* job, not a from-scratch build.

- **REUSE-AS-IS (protect, don't touch logic):** `OrderService` v3.0, `CartService`, `ProductService` core, `LedgerEngine` order/booking split + the `reference_type` collision fix (already wired), `lib/PaymentService` Cashfree gateway + webhook, the native `deliveries`/`driver_wallets` tables, `DeliveryPricingEngine`, the `auto_assign.php` lock pattern.
- **EDIT-TO-BEST:** the engine gateway (wire 4 declared-but-unhandled intents), `ProductService` (add `city_id` filter, drop duplicate `images_json`, move to unified `media`), the online-dispatch header bug, config fragmentation, the delivery PHP shim (keep quote/webhook, repoint dispatch at the native assigner).
- **BUILD-CLEAN:** customer shopping storefront (catalog/detail/cart/checkout/tracking), commerce admin control-room (company product mgmt + inventory + orders + dual-store routing + delivery ops + revenue), the dual-store schema+logic, the native delivery-assignment engine + driver PWA, `city_id` across commerce tables, vendor↔engine junction, the engine's own schema/migrations for self-documentation.
- **DISCARD:** dependence on the external SwiftDeliver Node/SQLite/Railway system (unfinished, WooCommerce-derived, non-motherboard identity); the second, unreconciled delivery path; the `X-SwiftDeliver-Secret` online-dispatch call.

**Total effort to "tested + one-click pluggable": ≈ 28–40 build sessions ≈ 9–13 calendar weeks** (solo founder reviewing between sessions), the majority in customer UI + delivery + admin, not in the shopping backend.

**Biggest risks:** (1) delivery is a real net-new build once you (correctly) stop depending on SwiftDeliver — but it's bounded because the native pieces exist; (2) the customer storefront is the single largest chunk; (3) money stays inert until `processOrderCompletion` is wired and staging-verified; (4) dual-store routing is genuinely new product logic that needs a founder decision on the company-vendor concept.

---

## 0. Disposition legend (how to read §1)

| Tag | Meaning | Founder rule |
|---|---|---|
| ✅ **REUSE-AS-IS** | Correct, production-grade. Lift into V2 unchanged (only mechanical adds like a `city_id` column write). | Don't rewrite good work. |
| 🟧 **EDIT-TO-BEST** | Good but not best. Improve into best-quality; keep the good bones. | Don't throw away; don't ship half-done. |
| 🔵 **BUILD-CLEAN** | Missing, thin, or broken beyond patching. Build proper from a clean base (reusing motherboard patterns). | No jugaad; build it right. |
| ⛔ **DISCARD** | Useless/unfinished/misaligned. Remove the dependency. | Cut it cleanly. |

---

## 1. Capability-by-capability disposition (code-grounded)

| # | Capability | Disposition | Reason (with code) | Work to reach "best" |
|---|---|---|---|---|
| 1 | **Product catalog (read: list/detail/search)** | ✅ REUSE-AS-IS core + 🟧 edits | `ProductService` is production-grade: FULLTEXT `MATCH…AGAINST` (`ProductService.php:57,74`), 5 sort modes, pagination, `available = quantity-reserved`, PII-safe (`:227`). | Add `city_id` filter (dual-store scoping); read images from unified `media` instead of `images`+`images_json` dual columns (`:225,271`). |
| 2 | **Product write (CRUD)** | 🟧 EDIT-TO-BEST | `ProductController::create/update` real, validated, **vendor-scoped only** (`ProductController.php:98` binds `:vendor => $vendorId`). Admin can only archive (`heart/api/admin/index.php:2168`). | Add a **company/admin product-create path** (dark-store owner) + `city_id`/`store_id` on insert. Keep the vendor path. |
| 3 | **Cart** | ✅ REUSE-AS-IS | `CartService` atomic upsert `INSERT…ON DUPLICATE KEY UPDATE quantity=LEAST(...)` on `UNIQUE(user_id,product_id)` (`CartService.php:207`), stock-capped, 30-day TTL. Race-safe. | None (optional: multi-store cart grouping surfaces in UI, not service). |
| 4 | **Checkout / OrderService** | ✅ REUSE-AS-IS | Crown jewel. Multi-vendor split, `FOR UPDATE` inventory locks with checkout-time product re-validation (`OrderService.php:799`), reserve/deduct/release, rate-limit (`:763`). Transaction-safe. | Two mechanical adds only: write `city_id` on order insert; call the settlement trigger (row 7). Do **not** refactor the logic. |
| 5 | **Inventory integrity** | ✅ REUSE-AS-IS | Row-locked reserve on checkout, physical deduct on delivery, release on cancel (`OrderService.php:265,494,382`). `inventory` table (quantity/reserved/allow_backorder/low_stock_alert). | Add `city_id`/warehouse scoping for dark-stores (row 15). |
| 6 | **Multi-vendor order split** | ✅ REUSE-AS-IS | Parent order + per-vendor sub-orders + `order_items` snapshots (`OrderService.php:216-298`); `orders` table has `parent_order_id`, `ledger_status`, per-stage timestamps (dump `orders`, line 891). | Extend the same split to route company-store vs vendor-store sub-orders (row 14 logic, not a rewrite). |
| 7 | **Order → ledger / commission settlement** | 🔵 BUILD-CLEAN (wire) | `LedgerEngine::processOrderCompletion` exists and is collision-safe (`LedgerEngine.php:136`, tags `reference_type='order'`) but **has no caller** — orders settle no money. | Add ONE trigger (recommend: native delivery "delivered" webhook → `processOrderCompletion`), then staging-verify. Small but critical. |
| 8 | **Payment path (COD + online)** | ✅ REUSE-AS-IS gateway + 🟧 unlock | Shared `lib/PaymentService::createOnlinePaymentOrder` (`:39`) + order-first webhook reconciliation (`:351`) already handle orders; `ALLOWED_PAYMENT_METHODS=['cod']` (`OrderService.php:49`) locks online off. | Unlock `online` post-Cashfree-live; §3. |
| 9 | **Customer order tracking / history** | 🟧 EDIT-TO-BEST (wire) | `OrderService::list/detail` exist (`:555,611`) but the gateway `index.php` doesn't route `shopping:list_orders`/`order_status` (declared in `router.php:200,205`, unhandled in `index.php` → empty). | Wire the 4 unhandled intents in the gateway; then history/tracking work end-to-end. |
| 10 | **Customer shopping storefront UI** | 🔵 BUILD-CLEAN | `app/pages/` has only `orders.js` — a thin 139-line read-only stub with service-shaped statuses (`orders.js:45`). **No catalog/detail/cart/checkout page.** `SHOPPING_UI:false` (`config.js:73`). | Build the full storefront reusing the polished service UI patterns (Company View shell, empty/loading/error states). Largest single chunk. |
| 11 | **Vendor shopping panel** | 🟧 EDIT-TO-BEST | `vendor/dashboard-shopping.html` (1426 lines), `products.php`, `orders.php` exist, hidden. Substantial but unverified live, and split across PHP/HTML generations. | Consolidate to one panel (same call the service engine made — retire the legacy PHP/HTML split), gate by `vendor_shopping` role. |
| 12 | **Admin order ops** | 🟧 EDIT-TO-BEST | Real endpoints: list/detail/status/cancel (`heart/api/admin/index.php:1574-2229`); `admin/orders.html` (236 lines), hidden. | Extend into the commerce control-room (refunds surface, per-vendor sub-order ops, SLA/exceptions). |
| 13 | **Admin product / inventory / warehouse mgmt** | 🔵 BUILD-CLEAN | Admin can list + archive products only; **no create, no stock-in, no warehouse UI.** `inventory`/`warehouses` tables exist but no management surface. | Build company product mgmt + inventory receive/adjust + warehouse-per-city. Required for dark-stores. |
| 14 | **Dual-store model (company dark-store + local vendor)** | 🔵 BUILD-CLEAN | No concept exists: `vendors.type` is a single enum `('service','shopping')` (dump `:1373`); no store table, no company-vendor, no per-area supply routing. | New schema + routing logic; §5. Net-new product surface. |
| 15 | **Delivery — assignment + tracking** | ⛔ DISCARD external + 🔵 BUILD-CLEAN native | External SwiftDeliver is unfinished, WooCommerce-derived, own SQLite identity (`swiftdeliver-main/src/db/database.ts`), currently off; two unreconciled paths (`OrderService::dispatchToDelivery` vs `POST /api/admin/deliveries/assign`). | Build a clean native assigner (§4) reusing native `deliveries`/`driver_wallets`, `isDriverBlocked`, `DeliveryPricingEngine`, and the `auto_assign.php` lock pattern. Add a driver PWA (net-new). |
| 16 | **Delivery pricing / quote** | ✅ REUSE-AS-IS + 🟧 | `DeliveryPricingEngine` Haversine, config-driven (`DeliveryPricingEngine.php:47`). | Add per-city zones/pricing (currently global, reads `platform_settings`). |
| 17 | **Delivery PHP shim** | 🟧 EDIT-TO-BEST | `heart/api/delivery/index.php`: quote/dispatch/status/webhook real; webhook sits **outside** the try/catch (`:113→116`). | Keep quote + inbound status structure; repoint "dispatch" at the native assigner; tidy control flow. |
| 18 | **Engine gateway / intent seam** | ✅ REUSE-AS-IS | `index.php` internal-only + 403 direct (`:69`); `config/engines.php` registry present. Clean plug for a separate team. | Wire missing intents (row 9); keep the seam stable. |
| 19 | **Own schema / self-documentation** | 🔵 BUILD-CLEAN | README references `database/schema_shopping.sql` but **no `database/` dir in v2**. Schema lives only in the shared dump. | Give commerce its own dated migrations + schema-of-record + `COMMERCE/` docs; §7. |
| 20 | **Wallet collision safety** | ✅ REUSE-AS-IS (done) | Migration 004 + `LedgerEngine` write/read `reference_type/reference_id` end-to-end (`LedgerEngine.php:103-129,200,297`). audit-08's pre-shopping landmine is **closed in code**. | Staging-verify only. |

---

## 2. MOTHERBOARD ALIGNMENT — what must connect + schema gaps

The V2 foundation provides: `cities`+`city_id`, canonical `vendor_categories`, unified `media`, `wallet_transactions.reference_type`, RBAC (`V2-READINESS §0`). Commerce alignment:

| Motherboard pillar | Commerce today | Gap → action |
|---|---|---|
| **`users`/`vendors` identity** | Shopping shares fully (`orders.user_id→users.id`, `order_items.vendor_id→vendors.id`). Drivers: native `deliveries.driver_id→users.id` exists but real drivers live in external SwiftDeliver. | ✅ Shopping ready. **Delivery: adopt native `users.role='delivery'` identity** (drops external identity — §4). |
| **`city_id`** | ⛔ **Absent on every commerce table.** Migration 001 added it only to `users/vendors/bookings/services` (`001:117-131`); the blueprint *intended* `products/orders/deliveries` (`V2-BUILD-BLUEPRINT §1.1`) but that was deferred with shopping. | 🔵 **New migration: `city_id` FK on `products`, `orders`, `order_items`, `inventory`, `warehouses`, `cart`, `deliveries`** (+ backfill Haldwani, mirror migration 001's guarded pattern). Then write `city_id` in OrderService/ProductController inserts. **The single biggest alignment task.** |
| **Multi-engine vendor** | Single `vendors.type` enum can't model a vendor doing service *and* shopping. `vendor_categories` gives multi-category only. | 🔵 **`vendor_engines` junction** (vendor↔engine M2M) — the blueprint already named this (`§1.3`); mirror the `vendor_categories` pattern. |
| **Unified `media`** | Products use `images`(JSON)+`images_json`(text) dual columns; not on `media`. | 🟧 Migrate product images → `media` (owner_type='product'); drop `images_json`. One CDN for service photos + product images + future video. |
| **Wallet `reference_type`** | ✅ Done and wired for orders (row 20). | Staging-verify with real order+booking data. |
| **RBAC** | Roles `vendor_shopping`, `delivery` exist (`config.js`); admin shopping endpoints behind `requireRole(ADMIN)` but per-endpoint `Permission::enforce` gates not confirmed. | 🔵 Add a **`commerce_*` permission group** + roles (commerce admin, catalog manager, dispatch ops, driver) so the separate team is scoped; gate every commerce endpoint. |
| **Delivery assignment infra** | `auto_assignments.entity_type` is already `enum('order','job')` (dump) and `cron/auto_assign.php` has an `'order'` branch (`:72,155`) — assignment infra is order-aware and lock-safe (flock + `FOR UPDATE SKIP LOCKED`, `:13,70`). | 🔵 Reuse this exact pattern for **driver** assignment (new `delivery_assignments` offer table, or extend the existing junction); add driver geo/tracking columns to `deliveries`. |

**Schema gaps to add (one clean migration set, dependency-ordered):**
1. `city_id` FK on `products`, `orders`, `order_items`, `inventory`, `warehouses`, `cart`, `deliveries` (+ backfill).
2. `vendor_engines` junction (retire single `vendors.type`).
3. Product images → `media`; drop `products.images_json`.
4. **Dual-store:** `stores` table (type company|vendor, city_id, owner vendor_id nullable, geo, active) + `products.store_id`/`inventory.store_id` (§5).
5. **Delivery:** `delivery_assignments` (offer→accept/timeout, lock-safe), tracking columns on `deliveries` (pickup/drop lat-lng, tracking_token, rider live-location or last-ping), per-city delivery-zone/pricing config.
6. `commerce_*` RBAC permissions + roles seed.

---

## 3. PAYMENT — how commerce payment should work (money safe with both engines live)

**Principle:** one gateway, one ledger, two reference types. This is *already the architecture* — protect it, finish the wiring.

- **Keep the shared `lib/PaymentService` Cashfree path** (REUSE-AS-IS). Both engines create Cashfree orders via `createOnlinePaymentOrder`; the webhook reconciles **order-first, then booking** by unique Cashfree ref in `payment_id` (`PaymentService.php:351`), tagging a synthetic `reference_type`. Unique refs mean no cross-engine lookup collision.
- **COD first, online second** (mirror how service launched). Shopping ships COD-only (`ALLOWED_PAYMENT_METHODS=['cod']`) until a ₹1 live Cashfree round-trip passes; then unlock `online` (the branch already exists, `OrderService.php:191`).
- **Wire order settlement** (BUILD-CLEAN, small): trigger `LedgerEngine::processOrderCompletion` on the native delivery "delivered" event. It books vendor share (on `subtotal` × commission), platform commission, rider share, and COD `cash_in_hand` — all `reference_type='order'`, collision-proof against bookings (`LedgerEngine.php:200-221`).
- **Refunds:** `processRefund` is order-scoped (`walletRefMatch('order',…)`, `:424`) and triggers a Cashfree refund for online-paid orders (`:476`). REUSE-AS-IS; surface it in the admin room (no UI today).
- **Fix the online-dispatch header bug** (EDIT-TO-BEST): once native delivery lands, the F12 auto-dispatch in the webhook (`PaymentService.php:248-300`) points at the native assigner, not SwiftDeliver — removing the `X-SwiftDeliver-Secret` vs `Bearer` mismatch entirely.
- **Money-safety invariant to preserve:** every wallet write goes through `LedgerEngine::insertWalletTx` (writes `order_id` + `reference_type` + `reference_id`); every read scopes by `walletRefMatch`. No commerce code should write `wallet_transactions` directly.

**Net:** the dangerous cross-engine collision is already fixed and wired; commerce payment is a **finish-the-wiring + live-verify** task, not a redesign. Service and shopping can be live simultaneously with money safe **once `processOrderCompletion` is triggered and staging-verified**.

---

## 4. DELIVERY — honest salvage vs build-clean plan

**Verdict: DISCARD dependence on external SwiftDeliver; BUILD-CLEAN a native WTG delivery engine. The native build is bounded because the motherboard already has the parts.**

### What SwiftDeliver is (and why not to depend on it)
`swiftdeliver-main/` = SwiftDeliver v6.0.0: Node/Express + **own SQLite DB** + React (admin/rider/vendor/tracking) + WebSockets, on Railway. It has its **own** riders/vendors/orders/earnings schema (`src/db/database.ts`), was **built for WooCommerce** (`woocommerce_order_id`, `WC_WEBHOOK_SECRET`), shares **none** of the motherboard's identity/DB/money, and is the founder's unfinished test build, currently off. Depending on it violates the no-jugaad standard and the "shared identity" motherboard rule.

### What's genuinely worth salvaging (small)
- **Conceptual model / UX reference:** SwiftDeliver's rider dashboard, tracking page, and offer/accept flow are a good **spec** for the native driver PWA. Salvage the *design*, not the code.
- **The webhook contract shape** (dispatch → status callback) is a clean pattern to keep between the native assigner and the driver app.
- Everything else (SQLite DB, WooCommerce bridge, its auth, its Railway deploy) → **DISCARD.**

### What WTG already has for a proper native build (REUSE-AS-IS)
- **Native tables:** `deliveries` (order_id, driver_id→users.id, status enum pending→assigned→picked→delivered→cancelled, assigned_at, delivered_at) and `driver_wallets` (cash_in_hand, earnings, collection_limit) — clean skeletons already in the dump.
- **Driver identity:** `users.role='delivery'` (role enum + `config.js` ROLE_DELIVERY) — shared motherboard identity, no external accounts.
- **Cash-limit guard:** `LedgerEngine::isDriverBlocked` (`:507`) already enforces `cash_in_hand ≥ collection_limit`.
- **Pricing:** `DeliveryPricingEngine` (Haversine, config) — REUSE-AS-IS, add per-city zones.
- **Proven lock-safe assignment pattern:** `cron/auto_assign.php` (flock + `FOR UPDATE SKIP LOCKED`, offer→`auto_assignments`→accept/timeout, `:13,70,176`) — already order-aware. This is the template for **driver** assignment; don't reinvent it.
- **Money wiring:** `processOrderCompletion` already books rider share + COD `cash_in_hand` (`LedgerEngine.php:204-217`) — just needs its trigger.

### The clean native delivery build (compose, don't greenfield)
1. **`delivery_assignments`** offer table (driver_id, delivery_id, status pending|accepted|rejected|timeout) + a **delivery-assign cron** cloned from `auto_assign.php`'s lock pattern: nearest available driver by `city_id`/geo, offer → accept/timeout → next driver.
2. **Tracking columns** on `deliveries` (pickup/drop lat-lng, tracking_token, last rider ping) + a customer tracking view (reuse the service tracking UI split).
3. **Driver PWA** (net-new, small): login (shared OTP auth), see offers, accept, mark picked/delivered, see `driver_wallets` earnings/cash. Spec'd from SwiftDeliver's rider UI.
4. **Repoint dispatch:** `OrderService::dispatchToDelivery` and the payment webhook F12 call the **native** assigner (an internal service call, not an external cURL). Delivery "delivered" → `processOrderCompletion`.
5. **Retire** the external cURL paths + the duplicate admin-assign vs auto-dispatch ambiguity — one native assignment path, admin can override.

**Effort:** ~4–6 sessions for the assigner + tracking + money wiring (composition of existing parts) + ~3–5 sessions for the driver PWA. Far cheaper and cleaner than integrating/finishing SwiftDeliver, and fully motherboard-native.

---

## 5. DUAL-STORE MODEL — schema + admin (company dark-store + local vendor, city-scoped)

The founder's model: per area, the customer sees **company dark-store** products (own inventory, better discounts, more control) where feasible, else **local vendor** products (partner shopkeepers) — all `city_id` scoped.

### Schema (BUILD-CLEAN)
- **`stores`** table: `id`, `type enum('company','vendor')`, `city_id` FK, `owner_vendor_id` (NULL for company), `name`, `geo` (lat-lng/area), `is_active`, `priority`. A company dark-store is a `stores` row with `type='company'`; a local vendor store is `type='vendor'` linked to a `vendors` row.
- **`products.store_id`** + **`inventory.store_id`** (or warehouse↔store link): stock lives per store, so the same catalog item can be dark-store-fulfilled in one city and vendor-fulfilled in another.
- Reuse `warehouses` for company dark-store stock locations (currently unused table).
- Everything **`city_id`-scoped** so discovery shows the right supply per area.

### Routing logic (BUILD-CLEAN)
- Product discovery (`ProductService::list`) filters by `city_id` and prefers `type='company'` stores where present (better discounts/control), falling back to `type='vendor'` stores where no dark-store serves the area (rural). This is the "customer sees options for their area" rule.
- Checkout already splits by vendor (`OrderService`); extend the split to route company-store sub-orders vs vendor-store sub-orders (same mechanism, new grouping key = `store_id`). **Reuse the split, don't rewrite it.**

### Admin (BUILD-CLEAN, in the commerce control-room)
- Create/manage **company dark-stores** per city (stock-in, inventory adjust, discounts).
- Onboard/approve **local vendor stores** per city (reuse vendor moderation patterns).
- Per-area supply config: which categories are dark-store vs vendor-fulfilled.
- One delivery team serves **both** store types (native delivery engine, §4) — orders from either store dispatch through the same assigner.

**Cross-engine future (design-aware, DON'T build now):** keep `products`/`orders` on shared identity + `city_id` + `media` + the intent seam so the later unified feed can mix product/service/video, and flows like video→long-press→buy or service-search→offer-service can be added without reshaping the schema. The V2 foundation + these commerce additions already leave that door open (`V2-VISION §8`, audit-08 feed model). **No cross-engine code in this build — just don't box the schema out of it.**

---

## 6. BUILD SEQUENCE — phased, dependency-ordered

> Golden rule (inherited from `V2-BUILD-BLUEPRINT §2`): **data model → reuse/clean existing → customer UI → admin → delivery → testing → security → plug-in.** No later phase starts before its dependency lands. "Session" = one focused build+review block (~half a day), per the service-engine convention.

### PHASE C0 — Commerce data-model foundation (heaviest schema phase)
Everything else reads this.

| Chunk | Delivers | Files/Tables | Depends |
|---|---|---|---|
| C0.1 — `city_id` across commerce | FK + backfill on products/orders/order_items/inventory/warehouses/cart/deliveries; writers set it | new migration; OrderService/ProductController inserts | motherboard cities (done) |
| C0.2 — `vendor_engines` junction | one vendor spans service+shopping | migration; resolver reads | C0.1 |
| C0.3 — Product images → `media` | move to unified media; drop `images_json` | migration; ProductService read path | motherboard media (done) |
| C0.4 — `stores` + `store_id` (dual-store) | company/vendor stores, city-scoped stock | migration; `stores`, products/inventory | C0.1 |
| C0.5 — Delivery + assignment schema | `delivery_assignments`, tracking cols, zone/pricing config | migration | C0.1 |
| C0.6 — `commerce_*` RBAC | permission group + roles seed | migration (mirror `08_13_001`) | motherboard RBAC (done) |
| C0.7 — Own schema-of-record | commerce `database/` + migrations dir | docs/migrations | — |

**Exit:** one migration set runs clean on a copy of the prod dump; every commerce query can be city-scoped; images resolve from `media`; wallet reference_type verified with real data. **Est: 5–7 sessions · ~2 weeks.**

### PHASE C1 — Reuse/clean the existing engine (finish the backend)
| Chunk | Delivers | Disposition |
|---|---|---|
| C1.1 — Wire gateway intents | `list_orders`/`order_status`/`checkout`/`recommendations` reach `OrderService::list/detail` | EDIT-TO-BEST |
| C1.2 — Wire order settlement | `processOrderCompletion` trigger on delivery-delivered + staging verify | BUILD-CLEAN (small) |
| C1.3 — Config consolidation + shim tidy | `platform_settings`→`app_settings`; webhook try/catch | EDIT-TO-BEST |
| C1.4 — Dual-store routing in split | route company vs vendor sub-orders by `store_id` | EDIT (reuse split) |

**Exit:** an order placed via the pipeline lists, tracks, settles money, and routes to the right store type. **Est: 3–4 sessions · ~1 week.**

### PHASE C2 — Customer shopping storefront (largest build)
| Chunk | Delivers |
|---|---|
| C2.1 — Catalog + product detail | city-scoped grid, search/sort, detail page (reuse service UI shell) |
| C2.2 — Cart + checkout | cart page, address, COD checkout → `OrderService` |
| C2.3 — Order tracking | real shopping statuses (pending→confirmed→…→delivered), rebuild `orders.js` off service-shaped stub |
| C2.4 — Dual-store surfacing | "options in your area": company vs local-vendor choice, city-scoped |

**Exit:** a customer browses → adds to cart → checks out COD → tracks delivery, city-correct. **Est: 8–11 sessions · ~3–4 weeks.**

### PHASE C3 — Commerce admin control-room (own panel)
| Chunk | Delivers |
|---|---|
| C3.1 — Company product + inventory mgmt | create/edit products for dark-stores, stock-in/adjust, warehouses per city |
| C3.2 — Order ops | list/detail/status/cancel/refunds, per-vendor sub-order ops |
| C3.3 — Dual-store admin | manage company dark-stores + onboard local vendor stores, per-area supply config |
| C3.4 — Commerce revenue room | GMV, per-vendor payouts, delivery economics, COD float, dark-store margin (own report, reuse `PaymentReport` patterns) |

**Exit:** a commerce team runs catalog, inventory, orders, stores, and revenue from its own RBAC-gated panel. **Est: 6–8 sessions · ~2–3 weeks.**

### PHASE C4 — Native delivery engine
| Chunk | Delivers |
|---|---|
| C4.1 — Native assigner | delivery-assign cron cloned from `auto_assign.php` lock pattern; nearest driver by city/geo; offer→accept/timeout; `isDriverBlocked` guard |
| C4.2 — Tracking | pickup/drop geo, tracking_token, customer tracking view |
| C4.3 — Driver PWA | offers, accept, picked/delivered, earnings/cash (shared OTP auth; spec from SwiftDeliver rider UI) |
| C4.4 — Repoint + retire external | dispatch → native; delivery "delivered" → settlement; remove SwiftDeliver cURL + duplicate admin-assign ambiguity |

**Exit:** one delivery team serves both store types via a native, motherboard-identity assigner; SwiftDeliver dependency gone. **Est: 6–9 sessions · ~2–3 weeks.**

### PHASE C5 — Testing, security, plug-in
| Chunk | Delivers |
|---|---|
| C5.1 — End-to-end staging | full commerce loop on a prod-dump copy: browse→cart→COD→settle→deliver; both store types; both cities test |
| C5.2 — Money verification | order+booking live simultaneously, ledger reference_type correct, no collision; ₹1 Cashfree online round-trip |
| C5.3 — Security pass | every commerce endpoint RBAC-gated; auth-bypass check; injection spot-check (reuse `V2-SECURITY-AUDIT` method) |
| C5.4 — Plug-in verify | commerce activates via flag flip; engine registry/intent seam clean; V2 service engine unaffected |

**Exit:** "tested + one-click pluggable." **Est: 3–5 sessions · ~1–1.5 weeks.**

### Total
| Phase | Sessions | Calendar |
|---|---|---|
| C0 data model | 5–7 | ~2 wk |
| C1 finish backend | 3–4 | ~1 wk |
| C2 customer UI | 8–11 | ~3–4 wk |
| C3 admin room | 6–8 | ~2–3 wk |
| C4 native delivery | 6–9 | ~2–3 wk |
| C5 test/security/plug | 3–5 | ~1–1.5 wk |
| **Total** | **≈ 28–40 sessions** | **≈ 9–13 weeks** |

**Wildcards:** (1) Cashfree online live-verify (external, can slip independently); (2) driver PWA scope (offer/accept/track only vs. richer); (3) dual-store routing depends on the founder's company-vendor decision (Open Q1). COD-only + vendor-stores-only would cut C2/C4/C5 scope and land nearer the low end.

---

## 7. SELF-DOCUMENTATION NOTE

Commerce will be **self-documented so a separate team/CTO manages it without re-explaining** — this is a build requirement, not an afterthought:

- **`WTG-SYSTEM-BRAIN/COMMERCE/` is the engine's doc home.** `commerce-audit-01-inventory.md` (current state) and this `commerce-02-gap-map.md` (plan) are its first two records; the build adds `commerce-03-*` as phases land (schema-of-record, API/intent map, admin runbook, delivery ops guide).
- **The engine ships its own `database/` migrations + schema-of-record** (Phase C0.7) — closing the gap that the v2 shopping engine currently has no `database/` dir (README references a missing `schema_shopping.sql`).
- **The engine's own README** documents the intent contract (`shopping:*` + `delivery:*`), the store model, the money flow (reference_type invariant), and the RBAC group — the same self-contained standard the service engine's `V2-READINESS`/blueprint set.
- **The plug is explicit and documented:** shared `users`/`vendors`/`cities`/`media`/RBAC/ledger + the `{intent,data}` Heart seam — nothing else — so the commerce team owns its backend/control-room independently while the motherboard stays the single source of identity and money.

---

## OPEN QUESTIONS FOR THE FOUNDER (decide before/early in the build)

1. **Company-vendor concept:** is a "company dark-store" a special `vendors` row or a first-class `stores.type='company'` (recommended)? Determines the dual-store schema.
2. **Delivery confirmation:** who fires order settlement — driver "delivered", admin, or auto-on-status? (Recommend native delivery "delivered" webhook.)
3. **Launch payment:** COD-only first (as service launched) or online from day one (needs Cashfree live-verify)?
4. **Delivery driver supply at launch:** enough WTG-native drivers to run the native engine in Haldwani, or a COD-vendor-self-delivery interim while drivers onboard?
5. **Commerce team boundary:** confirm the plug is *only* shared identity/city/media/RBAC/ledger + intent pipeline, and the commerce team owns everything else in its own panel.

---

*Planning document only — no feature code written, no live files touched, never-touch engines read for planning and left unchanged. Every reuse/edit/build/discard call is traceable to cited code or a prior commerce/V2 audit. This is the build base for the Commerce engine, applying the founder's no-jugaad standard: keep what's production-grade, improve what's close, build clean what's missing, and cut the unfinished external delivery dependency.*
