# Commerce Engine Audit 01 — Shopping + Delivery Current-State Inventory (READ-ONLY)

> **Purpose:** The founder wants to plan the **Commerce engine (Shopping + Delivery)** as a *separate* engine that later plugs into the same V2 motherboard (shared user identity, `city_id`, media, RBAC, wallet/ledger) with its own admin control-room and its own team. This report is the planning base for that build — the commerce equivalent of what `V2-READINESS.md` was for the service engine. Approach = **audit what exists → reuse what's good → treat (clean up) what's broken → plug into V2 cleanly. NOT a rewrite.**
> **Method:** READ-ONLY deep read of the actual `v2` branch source (worktree `wtg-v2/`), the external `swiftdeliver-main/` Node system, the prod DB dump (`u904615152_worktogo_main (4).sql`), and the V2 migrations. Every claim below cites real code (`file:line`). **Nothing was edited.** `shopping-engine`, `video-engine`, and `app/pages/orders.js` were read to assess readiness only — not one line changed (never-touch rule honored).
> **Builds on:** `audit-08-dormant-engines.md` (dormant-engine readiness), `V2-READINESS.md` (motherboard state), `audit-05-payments.md`, `audit-07-multi-city-gaps.md`, `V2-VISION.md`, `V2-GAP-MAP.md`.
> **Date:** 2026-08-19 · Branch `v2`, working tree clean · **No PHP/Node runtime in this workspace** — every "must verify live" item is flagged.

---

## HEADLINE

**Commerce readiness for "tested and one-click pluggable" ≈ 55–60%.** The number is a barbell, not an average:

- **Shopping's backend is genuinely production-grade (~85%)** — `OrderService` v3.0, `CartService`, `ProductService` are careful, transaction-safe, well-tested-looking commerce code. This is real inventory, real multi-vendor split, real row-locking.
- **Everything *around* that backend is 30–50%** — the customer shopping UI (catalog/cart/checkout) is essentially **not built** (only a thin order-history list exists), order→ledger settlement is **unwired**, the engine gateway routes only **7 of 11 declared intents**, and the commerce tables never got the **`city_id`** the motherboard now runs on.
- **Delivery is the biggest structural risk (~35%)** — the "engine" in this repo is a **thin PHP shim** over a **completely separate product (SwiftDeliver v6.0.0: Node + Express + its own SQLite DB + React + WebSockets on Railway)** that was originally built for WooCommerce. It does **not** share the motherboard's identity, DB, or money — it's bridged by two webhook calls. There are also **two competing delivery models** (external SwiftDeliver auto-dispatch vs. a local admin "assign a WTG driver" path) that don't reconcile.

**Biggest strengths:** the OrderService/Cart/Product core; the wallet collision fix (`reference_type`) is already done *and already wired into the ledger for orders*; shared `users`/`vendors` identity for shopping is real.
**Biggest gaps:** no customer shopping UI; no `city_id` on commerce tables; order commission never settles; delivery depends on an external, separately-owned Node/SQLite system with no shared identity; no unified commerce admin/control-room (product mgmt is vendor-only — no company/dark-store product creation).
**Rough effort to "one-click pluggable":** **~7–11 focused engineering-weeks** (detailed in §7), the majority in *frontend + delivery decision*, not in the shopping backend.

---

## 0. How commerce is *meant* to plug in (the seam already exists)

The multi-engine architecture is real at the routing layer. `config/engines.php` maps engines to either a local file or an overridable service URL:

```php
// wtg-v2/config/engines.php
'shopping' => getenv('ENGINE_SHOPPING_URL') ?: __DIR__ . '/../body/shopping-engine/index.php',
'service'  => getenv('ENGINE_SERVICE_URL')  ?: __DIR__ . '/../body/service-engine/index.php',
'delivery' => getenv('ENGINE_DELIVERY_URL') ?: '',   // empty → external/unmounted
'video'    => getenv('ENGINE_VIDEO_URL')    ?: '',
```

- **Shopping** is an in-process PHP engine dispatched by the Heart `{intent,data}` pipeline. Its entry `body/shopping-engine/index.php` **only** accepts internal Heart dispatch (`HEART_INTERNAL_INC`) and **403s on direct access** (`index.php:69-72`) — clean gateway isolation, exactly the seam a separate team can own.
- **Delivery** defaults to empty and is designed to be an **external service URL** — and in reality already is (SwiftDeliver, §2).
- **Takeaway for the commerce build:** the `{intent,data}` → engine contract is the plug. Keep it stable and the commerce engine can be split into its own backend/control-room without touching Heart. This is the founder's "separate backend behind one app," and it already exists.

---

## 1. SHOPPING ENGINE — full readiness

Files: `wtg-v2/body/shopping-engine/` (`index.php`, `api/products/`, `api/cart/`, `api/orders/`, `helpers/`), `core/helpers/LedgerEngine.php`, `heart/router.php`, `heart/api/admin/index.php`, `app/pages/orders.js`, `app/js/{api,config,router}.js`.

### Component readiness matrix

| Component | State | Honest assessment (code-grounded) |
|---|---|---|
| **Product catalog (read)** | ✅ **BUILT — production-grade** | `ProductService::list/detail` (`ProductService.php`): FULLTEXT search (`MATCH … AGAINST`, `:74`), 5 sort modes, pagination, availability = `quantity - reserved`, PII-safe (vendor phone stripped, `:227`). **No `city_id` filter anywhere.** |
| **Product write (CRUD)** | 🟡 **PARTIAL — vendor-only** | `ProductController::create/update` (`ProductController.php:51,170`): real `INSERT/UPDATE products`, vendor-scoped (`vendor_id => $vendorId`, `:98`), price/sale validation. **Admin can only list + soft-delete/archive** (`heart/api/admin/index.php:2168`, `UPDATE … status='archived'`). **No company/admin product creation** → the dark-store model (§6) has no code path. No `city_id` set on insert. |
| **Cart** | ✅ **BUILT — production-grade** | `CartService` (`CartService.php`): atomic `INSERT … ON DUPLICATE KEY UPDATE quantity=LEAST(...)` (`:207`) backed by `UNIQUE(user_id,product_id)`; stock-capped qty; 30-day TTL; per-user item cap. Race-safe. |
| **Checkout / OrderService** | ✅ **BUILT — production-grade** | `OrderService::create` (`OrderService.php:67`): rate-limit (5 orders/user/60s, `:763`), address validation, **multi-vendor split** (parent + per-vendor sub-orders + `order_items` snapshots, `:216-298`), **`SELECT … FOR UPDATE`** inventory locks with checkout-time product-active re-validation (`getInventoryForUpdate`, `:799`), `reserved += qty` on checkout / physical `quantity` deducted only on delivery / released on cancel. This is the crown jewel. |
| **Inventory integrity** | ✅ **BUILT** | Row-locked reserve/deduct/release across create/cancel/deliver (`OrderService.php:265,382,494`). `inventory` table w/ `quantity`, `reserved`, `allow_backorder`, `low_stock_alert`. |
| **Order lifecycle / vendor status machine** | ✅ **BUILT** | `updateVendorOrderStatus` (`:429`): guarded transitions pending→confirmed→processing→shipped→delivered with stock effects; delivered deducts physical stock. |
| **Payment path** | 🟡 **PARTIAL — COD-locked; online exists but unreachable** | `ALLOWED_PAYMENT_METHODS = ['cod']` (`OrderService.php:49`). An `online` branch (`:191`) calls the **same shared `lib/PaymentService::createOnlinePaymentOrder`** service uses, and the **webhook already reconciles paid orders** (§4) — but it's hard-locked off in code. So: online rails are 90% present, gated by one constant. |
| **Order → ledger / commission settlement** | ⛔ **MISSING (unwired)** | `LedgerEngine::processOrderCompletion` exists and is now **collision-safe** (`LedgerEngine.php:136`, writes `reference_type='order'`) — but **has NO caller.** `grep` confirms only `processBookingCompletion` (service) and `processRefund` are invoked. Marking an order `delivered` (`OrderService.php:490`) deducts stock but **never books vendor earnings or platform commission.** Orders can be placed, fulfilled, and delivered and **never settle money.** |
| **Order tracking / history (customer)** | 🟡 **PARTIAL — declared, not wired** | Router declares `shopping:list_orders`, `shopping:order_status` (`router.php:200,205`), but the engine gateway `index.php` **does not handle them** (only 7 intents: list/search/view product, add_to_cart, create_order, vendor_orders, vendor_update_order). `API.getOrders()` calls `shopping:list_orders` (`api.js:193`) → falls to the gateway `default` → returns **empty**. `OrderService::list/detail` methods *exist* (`:555,611`) but are unreachable via the pipeline. |
| **Customer shopping UI (catalog/cart/checkout/detail)** | ⛔ **MISSING** | `app/pages/` contains only `orders.js` — a **thin 139-line read-only history list** whose status vocab is actually service-oriented (`pending/accepted/in_progress`, `orders.js:45`). **No product-grid page, no cart page, no checkout page, no product-detail page.** `home.js` has shopping hooks but `SHOPPING_UI:false` (`config.js:73`). The customer cannot browse, add to cart, or check out from the app today. |
| **Vendor shopping panel** | 🟡 **PARTIAL — present, hidden** | `vendor/dashboard-shopping.html` (1426 lines), `vendor/products.php` (252), `vendor/orders.php` (264) exist and are substantial, hidden by service-only mode. Depth not fully verified live. |
| **Admin shopping surfaces** | 🟡 **PARTIAL** | Backend endpoints are real: `GET /api/admin/orders`, `GET /api/admin/orders/{id}`, `PUT …/status`, `POST …/cancel`, `GET /api/admin/products`, `DELETE …/products/{id}`, `GET /api/admin/deliveries`, `POST /api/admin/deliveries/assign` (`heart/api/admin/index.php:1574-2307`). HTML pages `admin/{orders,products,delivery}.html` exist (169–236 lines), `hiddenInServiceOnly`. **No admin product create/edit, no inventory management UI, no purchase/stock-in.** |
| **Engine gateway isolation** | ✅ **BUILT** | `index.php` internal-only + 403 direct (`:69`). Good boundary for a separate team. |
| **Self-documentation (own schema)** | ⛔ **MISSING in v2** | `README.md` references `database/schema_shopping.sql` but **no `database/` dir exists** in `wtg-v2/body/shopping-engine/`. The schema lives only in the shared dump. For a "separate team/CTO manages without re-explaining" goal, the engine has no self-contained schema/migrations of its own. |

### Verdict on Shopping
**The transactional core is production-grade and honestly the strongest single piece of dormant code in the whole platform.** But "shopping" as a *shippable customer experience* is far from ready: **no customer storefront UI, order history unwired, commission never settles.** audit-08's "SUBSTANTIAL" is correct about `OrderService`; it *overstated* the frontend ("customer UI exists" is really just a thin history stub). **Backend ~85% · Customer frontend ~15% · Money-settlement ~40%.**

Own-domain tables (all present in the dump): `products`, `product_variations`, `cart`, `orders`, `order_items`, `inventory`, `warehouses`. The `orders` table is well-modeled — `parent_order_id`, `ledger_status` (idempotency), per-stage timestamps, JSON `shipping_address` — but **has no `city_id`** (dump `orders` schema, line 891).

---

## 2. DELIVERY ENGINE — full readiness

Files: `wtg-v2/heart/api/delivery/index.php`, `core/helpers/DeliveryPricingEngine.php`, `lib/PaymentService.php` (dispatch), `body/shopping-engine/.../OrderService.php` (dispatch), and the external `swiftdeliver-main/` (Node).

### 2.1 What's in *this* repo (the PHP shim) — real but thin

`heart/api/delivery/index.php` (176 lines) is a genuine but shallow bridge:
- `GET /api/delivery/quote` → `DeliveryPricingEngine::calculatePricing` — real Haversine distance pricing (`DeliveryPricingEngine.php:47`), reads `platform_settings` for base fare / per-km / rider-share / margin. **City-agnostic** (pure lat/lng).
- `POST /api/delivery/dispatch` → creates a `deliveries` row, then **cURLs external `SWIFTDELIVER_URL/api/dispatch`** with `Authorization: Bearer` (`:64-88`).
- `PATCH /api/delivery/{id}/status` → driver updates status, mirrors to `orders` (`:92-110`).
- `POST /api/delivery/status-update` → **inbound webhook from the Node system**, secret-verified via `hash_equals` on `X-Webhook-Secret` (`:116-173`), maps rider status → order status.

**Code-quality note (treat, not throw):** the `status-update` webhook handler sits **outside** the `try/catch` that wraps the other three handlers (the `catch` closes at `:113`, the webhook starts at `:116`). Functional but sloppy control flow — worth tidying.

### 2.2 The real system: SwiftDeliver (external, separately-owned) — the structural risk

`swiftdeliver-main/` is **SwiftDeliver v6.0.0** — a full standalone product, NOT a WTG engine:
- **Stack:** Node + Express + **its own SQLite DB (`better-sqlite3`)** + React (Vite) admin/rider/vendor/tracking dashboards + **WebSockets** (`ws`) for realtime, deployed to **Railway** (`railway.toml`, PM2 `ecosystem.config.cjs`). `server.ts` is 1512 lines.
- **Its own complete schema** (`src/db/database.ts`): `admins`, `riders`, `vendors`, `vendor_registrations`, `orders` (with **`woocommerce_order_id`**), `order_items`, `delivery_assignments`, `notifications`, `rider_earnings_log`, `settings`, `token_blacklist`. **It has its own riders, vendors, orders, and earnings ledger.**
- **Originally a WooCommerce delivery app.** It ingests WTG orders via the `woocommerce_order_id` column (`server.ts:933`, `SELECT … FROM orders WHERE woocommerce_order_id=?`) and still carries `WC_WEBHOOK_SECRET` (`:18`). WTG is bolted onto a WooCommerce-shaped system.
- **Integration contract:** WTG cURLs `POST /api/dispatch` (auth `Bearer SWIFTDELIVER_SECRET`, `server.ts:910`); SwiftDeliver geocodes the address, creates its own order + rider notification, and calls back WTG's `/api/delivery/status-update` with `X-Webhook-Secret` (`notifyPhpWebhook`, `:149-174`).

> **Correction to audit-08:** audit-08 called `swiftdeliver-main/` "an old superseded prototype — not the live Node system this shim calls." That is **wrong**: this v6.0.0 code implements *exactly* the contract the shim calls (`/api/dispatch` in, `/api/delivery/status-update` out, same `SWIFTDELIVER_SECRET`). This folder **is** the SwiftDeliver codebase (or a faithful copy of the deployed one). Treat it as the real dependency.

### 2.3 Two competing delivery models (unreconciled)

There are **two different ways an order reaches a driver**, and they don't agree:
1. **External auto-dispatch** → SwiftDeliver riders (SQLite identity). Driver = a SwiftDeliver `riders` row; WTG only stores an opaque `deliveries.rider_id` integer back from the webhook.
2. **Local admin manual assign** → `POST /api/admin/deliveries/assign` (`heart/api/admin/index.php:2094`) inserts `deliveries(driver_id, status='assigned')` and checks `LedgerEngine::isDriverBlocked` — i.e. a **WTG driver** (`users.role='delivery'` + `driver_wallets`), fully inside the motherboard.

These are parallel, incompatible fulfilment paths. The COD/online auto-dispatch feeds SwiftDeliver; the admin-assign feeds a WTG-native driver with a `driver_wallets` balance. **Nothing decides which is authoritative**, and driver earnings/COD only settle for the *local* model (`LedgerEngine::processOrderCompletion`, itself unwired).

### 2.4 Delivery readiness matrix

| Component | State | Assessment |
|---|---|---|
| Distance pricing / quote | ✅ BUILT | Haversine, config-driven; **no per-city zones/pricing**; reads `platform_settings` (config fragmentation, §3). |
| Dispatch plumbing (PHP→Node) | ✅ BUILT | cURL + Bearer secret; delivery row created; non-fatal on failure. |
| Inbound status webhook | ✅ BUILT | Secret-verified, status→order mirror. Sits outside try/catch (tidy). |
| Actual rider assignment + tracking | 🟡 EXTERNAL | Lives entirely in SwiftDeliver (SQLite + WS + React). Not in this repo's control. |
| Driver identity | ⛔ NOT SHARED | Real drivers are SwiftDeliver `riders` (SQLite), **not** `users.role='delivery'`. The motherboard's driver identity + `driver_wallets` are used only by the *local* assign path. |
| Order→delivery on COD | ✅ BUILT | `OrderService::dispatchToDelivery` auto-calls on create (`:309,965`). |
| Order→delivery on online-paid | 🟡 BUILT-BUT-BUGGED | `PaymentService` webhook F12 dispatch (`:248-300`) sends header **`X-SwiftDeliver-Secret`**, but SwiftDeliver `/api/dispatch` requires **`Authorization: Bearer`** (`server.ts:910-918`) → **401, dispatch fails.** Latent (online is COD-locked + SwiftDeliver unconfigured today), but a real bug for go-live. |
| Delivery → ledger (driver earnings/COD) | ⛔ MISSING | Only `processOrderCompletion` books rider share + COD `cash_in_hand` — and it's unwired. Driver money doesn't settle. |
| `deliveries` table city awareness | ⛔ MISSING | No `city_id`; cross-city dispatch/zones unmodeled. |
| Admin delivery surface | 🟡 PARTIAL | `GET /api/admin/deliveries`, `POST …/assign`; `admin/delivery.html` (171 lines), hidden. |

### Verdict on Delivery
**PARTIAL and structurally fragile (~35%).** The plumbing is real but the *substance* — riders, assignment, tracking, driver auth, earnings — lives in a **separate, WooCommerce-derived Node/SQLite product on Railway that shares none of the motherboard's identity, database, or money.** This is the **least motherboard-aligned engine.** See §7 risks and §3/§5 for the decision the founder must make (adopt-and-integrate SwiftDeliver vs. build a native WTG delivery engine on shared identity).

---

## 3. SHARED-MOTHERBOARD FIT — how commerce connects (or conflicts) with V2

The V2 foundation (per `V2-READINESS.md` §0, migrations `2026_07_04_001..004`) now provides: `cities` + `city_id`, canonical `vendor_categories`, unified `media` table, `wallet_transactions.reference_type/reference_id`, and RBAC. How does commerce line up?

| Motherboard pillar | Shopping | Delivery | Must align before plug-in |
|---|---|---|---|
| **`users` / `vendors` identity** | ✅ **Shares fully.** `orders.user_id→users.id`, `orders.vendor_id`/`order_items.vendor_id→vendors.id`; OrderService reads `users` directly (`:195`). Shopping vendor = `vendors.type='shopping'` + `users.role='vendor_shopping'`. No separate identity tables. | ⛔ **Does NOT share** (real drivers are SwiftDeliver SQLite riders). Local-assign path *does* use `users.role='delivery'` + `driver_wallets`. | Decide delivery identity model (§5). Shopping is ready. |
| **`city_id`** | ⛔ **MISSING on all commerce tables.** Migration 001 added `city_id` only to `users/vendors/bookings/services` (`001:117-131`) — **not** `orders`, `order_items`, `products`, `inventory`, `warehouses`. Product/order queries have zero city scope. Dump grep: **no `city_id` anywhere** in base schema. | ⛔ MISSING on `deliveries`. | **Add `city_id` to the commerce tables** — the single biggest schema-alignment task. Without it the unified feed (audit-08) and multi-city both stay blocked for products, and city-scoped product discovery is impossible. |
| **Multi-engine vendor** | 🟡 **Blocked by single `vendors.type` enum** (`enum('service','shopping')`, dump `:1373`). `vendor_categories` junction gives multi-*category* but a vendor is still ONE engine-type. A vendor who does service *and* shopping can't be modeled. | n/a | Evolve `vendors.type` → a **vendor↔engine** relation (mirror the `vendor_categories` pattern the V2 foundation already established). |
| **Unified `media` table** | 🟡 **Not adopted.** Products use `products.images` (JSON) **+ duplicate `products.images_json` (text)** (`ProductService.php:225`, `parseImages` merges both). Not on the V2 `media` table. | n/a | Migrate product images onto the unified `media` model so one CDN serves service photos, product images, and future video. |
| **Wallet / ledger (`reference_type` fix)** | ✅ **DONE and wired for orders.** Migration 004 added `reference_type/reference_id`; `LedgerEngine::insertWalletTx` writes both, and `processOrderCompletion` tags `reference_type='order'` while `processBookingCompletion` tags `'booking'` (`LedgerEngine.php:200,297`). Reads are scoped via `walletRefMatch` (`:124`). The booking/order id-space collision (audit-06 H3) is **structurally closed** — the ledger is safe for both engines to be live *if* the order path is ever invoked. | 🟡 Driver wallet rows use the same helper (`entity_type='driver'`) but only the unwired `processOrderCompletion` writes them. | The dangerous part is **already fixed.** Remaining: actually *call* `processOrderCompletion` (see §4), and verify the fix on staging with real data. |
| **RBAC** | 🟡 Admin shopping endpoints sit behind `AuthMiddleware::requireRole(ROLE_ADMIN)`; whether each has a `Permission::enforce` gate (like the 18 service endpoints) needs a per-endpoint pass. Roles `vendor_shopping`, `delivery` exist (`config.js:63-66`). | 🟡 Delivery endpoints require `ROLE_DELIVERY, ROLE_ADMIN`. | Add explicit permission gates + a `commerce_*` permission group when the separate control-room is built. |

**Net:** Shopping is **identity-ready but city-blind and media-fragmented**; the money-collision landmine is **already defused**. Delivery is the misfit — its real identity lives outside the motherboard entirely.

---

## 4. PAYMENT & MONEY — reconciling two live engines

**Shared gateway, two targets.** There is ONE Cashfree integration (`lib/PaymentService.php`) used by *both* engines:
- `createOnlinePaymentOrder(orderId, amount, user)` (`:39`) builds a Cashfree order; the same method is called by the service inspection/expert-visit flow and (in the locked-off branch) by `OrderService` (`:206`).
- **Webhook disambiguation is order-first, then booking** (`fetchPaymentTargetForUpdate`, `:351`): match `orders.payment_id` by the Cashfree ref; if none, match `bookings.payment_id`. Each row carries a synthetic `reference_type` (`'order'`/`'booking'`). The Cashfree order id is unique per call and stored in `payment_id`, so the two engines can't collide on lookup.
- On `paid`: orders get `status='confirmed'` and (F12) an auto-dispatch to SwiftDeliver; bookings get `syncPaidBookingPropagation` (`:238-248`). Amount is server-verified against DB `total` (`:210`); idempotent by `payment_status='paid'` (`:199`).

**What's safe already:**
- The **wallet id-space collision is closed** at the ledger (§3) — `reference_type/reference_id` end-to-end, order path tags `'order'`, booking path tags `'booking'`, reads are scoped. This was audit-08's "fix before Shopping goes live" — **it is done in code** (needs staging verification).
- Refund path (`processRefund`, `LedgerEngine.php:416`) is order-scoped via `walletRefMatch('order', …)` so it can't reverse a same-numbered booking.

**What must be reconciled before both engines are live:**
1. **🔴 Order commission never settles (unwired).** `processOrderCompletion` has no caller. A delivered order books **no vendor earning, no platform commission, no driver payout, no COD `cash_in_hand`.** Service settles (`processBookingCompletion` is called from the service engine + admin), shopping does not. **This is the #1 money gap** — decide the trigger (vendor "delivered", SwiftDeliver "delivered" webhook, or admin) and wire it.
2. **Online payment is locked off** (`ALLOWED_PAYMENT_METHODS=['cod']`). Enabling it needs the same ₹1 live Cashfree round-trip the service engine still owes, **plus** fixing the dispatch header bug (§2.4) so paid orders actually reach delivery.
3. **Two ledgers for delivery.** SwiftDeliver keeps its own `rider_earnings_log` (SQLite); WTG has `driver_wallets`. If SwiftDeliver is the real fulfilment, driver money reconciliation is **outside** the motherboard ledger entirely — a reporting/settlement blind spot.
4. **Commission model divergence.** Orders commission on `subtotal` at `vendors.commission_rate` (default 10%, `LedgerEngine.php:180`); bookings use category-override→vendor→10% priority (`:306`). Delivery fee is assumed 100% to rider (`:184`). Decide whether shopping should also honor category commission overrides for consistency.

**Bottom line:** money is *structurally* safe for coexistence (the collision fix is real and wired), but shopping money is **inert** until `processOrderCompletion` is triggered and verified. The scary part is done; the missing part is a wiring + test task, not a redesign.

---

## 5. WHAT TO REUSE vs REBUILD vs TREAT

| Area | Verdict | Why / what to do |
|---|---|---|
| `OrderService` v3.0 (split, locking, rate-limit, reserve/deduct) | ✅ **REUSE as-is** | Production-grade, transaction-safe. Keep. Only add `city_id` writes + wire settlement trigger. |
| `CartService` (atomic upsert, TTL) | ✅ **REUSE as-is** | Race-safe, clean. Keep. |
| `ProductService` (search/detail/list) | ✅ **REUSE, light treat** | Keep; add `city_id` filter; migrate images to unified `media`; drop duplicate `images_json`. |
| `LedgerEngine` order/booking split + `reference_type` | ✅ **REUSE — already fixed** | The collision fix is done and wired. **Treat:** add the missing *caller* for `processOrderCompletion`. |
| Shared `lib/PaymentService` (Cashfree) | ✅ **REUSE** | One gateway, order-first webhook reconciliation. **Treat:** fix the `X-SwiftDeliver-Secret` vs `Bearer` header bug (`PaymentService.php:285`). |
| Engine gateway `index.php` intent routing | 🟡 **TREAT** | Wire the 4 declared-but-unhandled intents (`list_orders`, `checkout`, `order_status`, `get_recommendations`) so `OrderService::list/detail` become reachable. |
| Commerce tables (`orders/order_items/products/inventory/warehouses/cart/deliveries`) | 🟡 **TREAT (schema)** | Add `city_id` (+ FK + backfill) — the biggest alignment migration. Evolve `vendors.type` → vendor↔engine. |
| Customer shopping UI (catalog/cart/checkout/detail) | ⛔ **BUILD** | Essentially missing. This is the largest single build. Reuse the polished service UI patterns (empty/loading/error states, home.js Company View shell). |
| Order settlement wiring | ⛔ **BUILD (small)** | Add the `processOrderCompletion` trigger + verify on staging. |
| Delivery — external SwiftDeliver | ⚠️ **DECIDE (treat or rebuild)** | Either (a) **adopt-and-integrate** SwiftDeliver as the sanctioned external delivery service (accept it as a separate system, harden the bridge, formalize the contract, own its Railway deploy), or (b) **build a native WTG delivery engine** on shared `users`/`driver_wallets`/`city_id` and retire SwiftDeliver. **Do not leave both the external auto-dispatch and the local admin-assign paths live and unreconciled.** |
| SwiftDeliver's WooCommerce lineage (`woocommerce_order_id`, `WC_WEBHOOK_SECRET`) | 🟡 **TREAT if adopted** | Rename/clean the WooCommerce-shaped bridge fields so a WTG team isn't reverse-engineering a WooCommerce app. |
| Config fragmentation (`platform_settings` vs `app_settings`) | 🟡 **TREAT** | Delivery pricing reads `platform_settings` (`DeliveryPricingEngine.php:25`); the rest reads `app_settings` (11 refs). Consolidate. |
| Own engine schema / self-documentation | ⛔ **BUILD** | No `schema_shopping.sql` in v2. A separate team needs the engine's own schema + migrations + README-of-record. |
| Admin/company product creation (dark-store) | ⛔ **BUILD** | Product write is vendor-only; §6. |

---

## 6. ADMIN / CONTROL-ROOM GAP

The founder's model = **two supply types behind one storefront**: (1) **company dark-stores** (WTG-owned inventory, company as vendor) and (2) **local vendor stores** (independent shopping vendors). A separate commerce control-room must serve both. What exists vs. what's needed:

**Exists (backend, hidden):** `GET /api/admin/orders` + detail + status + cancel; `GET /api/admin/products` + archive; `GET /api/admin/deliveries` + `POST …/assign`. HTML shells `admin/{orders,products,delivery}.html`.

**Missing for a real commerce control-room:**
- **Product management (create/edit) for the company/dark-store** — today product writes are **vendor-scoped only** (`ProductController.create` binds `vendor_id`). No admin/company product creation, no bulk catalog, no company-as-vendor concept. The dark-store model has **no code path**.
- **Inventory / stock-in management** — `inventory`/`warehouses` tables exist but there's no admin UI to receive stock, adjust `quantity`, set `allow_backorder`/`low_stock_alert`, or manage warehouses per city.
- **Dual supply routing** — no way to mark a category/product as company-fulfilled (dark-store) vs. vendor-fulfilled, and no per-city dark-store → local-vendor fallback logic.
- **Order operations** — status/cancel exist; missing: refunds UI (RefundController exists but no surface), returns, per-vendor sub-order ops, SLA/exception handling.
- **Delivery control** — no unified view reconciling SwiftDeliver riders + local drivers; no per-city zone/pricing config UI (pricing is global `platform_settings`); no COD reconciliation / `cash_in_hand` limit management surface.
- **Commerce revenue room** — the service `PaymentReport.php` Payment Room is service-shaped; a commerce P&L (GMV, per-vendor payouts, delivery economics, COD float, dark-store margin) needs its own build.
- **RBAC group** — a `commerce_*` permission set + roles (commerce admin, catalog manager, dispatch ops) for the separate team.

**Reuse:** the service admin control-room's *patterns* (RBAC-gated endpoints, `admin/roles.html`, `admin/cities.html`, computed-from-structured-columns reporting) are an excellent template — the commerce room should be built the same way, in its own panel, over the shared RBAC + cities.

---

## 7. HONEST VERDICT

### How far from "tested and one-click pluggable"?
**~55–60% overall**, but read it as two very different tracks:

- **Shopping backend: ~85%.** Real, careful, transaction-safe. The plug (intent pipeline + shared identity + fixed ledger) exists. What's left is *wiring and alignment*, not building.
- **Shopping as a shippable product: ~40%.** No customer storefront UI, unwired order history + settlement, gateway intents half-wired.
- **Delivery: ~35%.** A thin shim over a separate, WooCommerce-derived, non-motherboard Node/SQLite system, plus a competing local path. Needs a founder-level *decision* before it can be "pluggable."

### Rough effort to one-click pluggable (single focused engineer, staging available)
| Workstream | Est. |
|---|---|
| `city_id` migration across commerce tables (+ FK, backfill, writer updates) | ~0.5 wk |
| Wire `processOrderCompletion` trigger + staging money verification | ~0.5 wk |
| Wire the 4 unhandled intents (`list_orders`/`order_status`/`checkout`/`recommendations`) | ~0.5 wk |
| Fix dispatch header bug + config consolidation + webhook try/catch tidy | ~0.5 wk |
| Evolve `vendors.type` → vendor↔engine; migrate product images → `media` | ~1 wk |
| **Customer shopping storefront UI** (catalog, product detail, cart, checkout, real order tracking) | **~3–4 wk** |
| Commerce admin control-room (product/company + inventory + dual-supply + delivery + revenue) | **~2–3 wk** |
| Delivery decision execution — *whichever path*: harden SwiftDeliver bridge **or** native delivery engine | ~1–2 wk (bridge) / much more (native rebuild) |
| Own engine schema + self-documentation for handoff | ~0.5 wk |
| **Total** | **~7–11 engineering-weeks** (excluding a full native-delivery rebuild) |

### Biggest risks (ranked)
1. **🔴 External delivery dependency you don't own.** Real fulfilment (riders, assignment, tracking, driver auth, earnings) lives in SwiftDeliver — a separate WooCommerce-derived Node/SQLite/Railway product with its own database. A second city means standing up/configuring that external system, and driver money never touches the motherboard ledger. This is the single largest structural risk and needs a founder decision (adopt vs. rebuild).
2. **🔴 Shopping money is inert.** `processOrderCompletion` is unwired — delivered orders settle nothing. Turning shopping "on" without wiring this means fulfilled orders with no vendor payout or commission booked.
3. **🟠 No customer storefront.** "Shopping is 97% done" is untrue at the UI layer — the catalog/cart/checkout the customer needs is not built. This is the biggest *build* (not fix) item.
4. **🟠 City-blind commerce.** No `city_id` on any commerce table blocks multi-city, city-scoped discovery, and the unified feed for products.
5. **🟡 Latent go-live bugs** already in code: online-dispatch header mismatch (401), two unreconciled delivery paths, config-table fragmentation, duplicate product-image columns, gateway intents declared-but-unhandled.

### The reassuring part
The **hard, dangerous, easy-to-get-wrong** things are already right: transaction-safe inventory, multi-vendor split, the wallet collision fix (wired into the ledger for both engines), shared identity for shopping, a clean engine seam. The remaining work is disproportionately **frontend + one delivery decision + wiring**, which is exactly the shape you *want* going into a planned build. This is a strong reuse base — **not a rewrite.**

---

## OPEN QUESTIONS FOR THE FOUNDER (decide before the commerce build)

1. **Delivery: adopt SwiftDeliver as the sanctioned external service, or build a native WTG delivery engine on shared identity?** (Determines whether drivers/earnings live inside or outside the motherboard.)
2. **Dual supply model:** how do company dark-stores and local vendor stores coexist per city — priority, fallback, and is "company" a special `vendors` row or a first-class concept?
3. **Order settlement trigger:** vendor "delivered", delivery-webhook "delivered", or admin confirmation?
4. **Shopping payments at launch:** COD-only first (as service launched), or online from day one (needs the Cashfree live round-trip + header fix)?
5. **Separate control-room + team boundary:** own admin panel + own `commerce_*` RBAC over the shared motherboard — confirm the plug is the `{intent,data}` pipeline and shared `users/vendors/cities/media/RBAC/ledger`, nothing else.

---

*Read-only inventory pass over `wtg-v2/body/shopping-engine/**`, `heart/api/delivery/index.php`, `core/helpers/{LedgerEngine,DeliveryPricingEngine}.php`, `lib/PaymentService.php`, `heart/{router,api/admin}.php`, `app/{pages/orders.js, js/*}`, migrations `2026_07_04_001/004`, the prod dump, and the external `swiftdeliver-main/` Node system. Never-touch engines were read only; no files were modified. This is the planning base for the Commerce engine build.*
