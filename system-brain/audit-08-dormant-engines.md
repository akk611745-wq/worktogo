# WTG System Audit 08 — Dormant Engines Readiness Map (READ-ONLY)

> **Purpose:** Document exactly how ready each dormant engine (Shopping, Video, Delivery, AI Brain) is *right now*, so the V2 database foundation can be designed to support all of them later **without a rewrite** — without touching their code.
> **Method:** Read-only inspection of the actual engine source (not just prior audits). Files under `wtg  live/`. The prior audits (01–07) deliberately did *not* deep-read shopping/video/brain; this phase does — **read only, nothing modified.**
> **Never-touch rule honored:** `shopping-engine`, `video-engine`, and `app/pages/orders.js` were **read to assess readiness only**; not one line changed.
> **Cross-refs:** Audit 01 (system map), Audit 02 (schema), Audit 05 (payments/ledger), Audit 07 (multi-city), V2-VISION.md, V2-GAP-MAP.md.
> **Date:** 2026-07-04 · READ-ONLY.

**Founder's target architecture (the frame for this audit):** ONE app / ONE feed for the user, but **THREE separate backends + control rooms** behind it — Service (active), Shopping, Video — plus a **Delivery** engine and an **AI Brain** on top. Build order: Service (end-to-end) → Shopping → Video, Brain layered over all. The key question this audit answers for each engine: **does it already share the core `users`/`vendors` identity, and what in its current shape would block a shared-identity, multi-city, unified-feed foundation.**

**The single most important finding up front:** the "many engines behind one heart" architecture is **already real at the routing layer** (`config/engines.php` maps `service`/`shopping` to local files and `delivery`/`video` to overridable URLs — i.e. designed to be split into microservices), and **the unified cross-engine feed the founder wants already exists in code** (`brain/FeedRankingEngine.php` + `BrainCore::buildUserFeed()` mixes products + services into one ranked feed). But that feed **queries `users.city`, `vendors.city`, and `vendors.total_orders` — none of which exist** (Audit 02, Audit 07). So the unified feed is *pre-built but dead*, blocked on the exact `city_id` foundation Audit 07 flagged. The dormant engines are far more shared-identity-ready than they are city-ready.

---

## 0. Engine registry (how the pieces are meant to plug in)

`config/engines.php` (read in full):

```php
'shopping' => getenv('ENGINE_SHOPPING_URL') ?: __DIR__.'/../body/shopping-engine/index.php',
'service'  => getenv('ENGINE_SERVICE_URL')  ?: __DIR__.'/../body/service-engine/index.php',
'delivery' => getenv('ENGINE_DELIVERY_URL') ?: '',   // empty → not locally mounted
'video'    => getenv('ENGINE_VIDEO_URL')    ?: '',   // empty → not locally mounted
```

- **Service + Shopping** are in-process PHP engines dispatched by `heart/router.php` via the `{intent,data}` pipeline (Audit 01 §0). Shopping's entry (`body/shopping-engine/index.php`) only accepts internal Heart dispatch (`HEART_INTERNAL_INC`) and **403s on direct access** — clean gateway isolation.
- **Delivery + Video** default to empty and read a `*_URL` env — the registry is explicitly built to let them become **external microservices** (Delivery already is one — a Node/Railway "SwiftDeliver" system, §Delivery below). This is the founder's "separate backends" model, already scaffolded.
- **Takeaway for V2:** the intent-pipeline contract (`{intent,data}` → engine) is the seam that keeps engines separable. Keep it stable and any engine can be split out or swapped for a service URL without touching Heart.

---

## Engine A — Shopping Engine · **READINESS: SUBSTANTIAL**

### 1. Readiness — what's actually built vs stubbed
**Substantially built, production-grade — not a stub.** `body/shopping-engine/` is a full commerce stack: `products/`, `cart/` (CartController + CartService), `orders/` (OrderController + `OrderService` **v3.0**), `VendorAnalyticsController`, `helpers/shopping.helpers.php`. `OrderService` (read in full, ~1030 lines) implements real, careful commerce logic:
- **Multi-vendor order split** (parent order + per-vendor sub-orders, `order_items` snapshots).
- **Inventory integrity** — `SELECT … FOR UPDATE` row locks inside a transaction; `reserved += qty` on checkout, physical `quantity` deducted only on delivery, released on cancel.
- **Checkout-time product validation** — JOINs `products` and requires `status='active' AND deleted_at IS NULL` inside the lock.
- **Rate limiting** — max 5 parent orders / user / 60s (DB COUNT, fail-open).
- **Vendor status state machine** — pending→confirmed→processing→shipped→delivered with guarded transitions and stock effects.
- **Auto-dispatch to the delivery network** on order creation (`dispatchToDelivery()` → external SwiftDeliver, skipped if URL unconfigured).
- **Payment: hard-locked to COD.** `ALLOWED_PAYMENT_METHODS = ['cod']` with a comment "Removed 'online' until Cashfree is fully configured" — an `online` branch exists and calls the **same `lib/PaymentService::createOnlinePaymentOrder`** the service engine uses, but is currently unreachable by the COD lock.

*Genuinely stubbed within it:* commission on shopping orders never runs — `LedgerEngine::processOrderCompletion` has **no caller** (Audit 05 §3, Audit 06 L10). So orders can be placed and fulfilled but **never settle commission**.

### 2. Tables + shared identity
- **Own-domain tables:** `products`, `product_variations`, `cart`, `orders`, `order_items`, `inventory`, `warehouses` (Audit 02 catalog/shopping groups).
- **SHARES CORE IDENTITY — YES, fully.** `orders.user_id` → `users.id`; `orders.vendor_id`/`order_items.vendor_id` → `vendors.id`; `OrderService` reads `users` directly for name/email/phone at checkout. **No separate shopping-identity tables.** A shopping vendor is a `vendors` row with `type='shopping'` and `users.role='vendor_shopping'` (Audit 02). Customers are the same `users` rows as service.

### 3. Frontend state
- Customer UI exists (`app/pages/orders.js`) but is **hidden by `SERVICE_ONLY_MODE`** (in `SERVICE_ONLY_HIDDEN_PAGES`; router shopping routes gated — Audit 01 §2). Vendor shopping panels (`vendor/dashboard.php`, `orders.php`, `products.php`, `dashboard-shopping.html`) and admin Orders/Products/Delivery pages are all `hiddenInServiceOnly` (Audit 01 §3/§4, Audit 04 context). So: **built, wired, and hidden by one flag** — not missing.

### 4. Integration points with service / shared code
- Same **Heart intent pipeline** (`shopping:*` intents in `heart/router.php`, Audit 01 §0).
- Same **`lib/PaymentService` + Cashfree webhook** — the webhook reconciles **orders first, then bookings** (Audit 05 §1); order and booking payments run through one gateway path.
- Same **`core/helpers/LedgerEngine`** (though the order path is unwired) and **`wallet_transactions`/`vendor_wallets`**.
- **Auto-dispatch → Delivery engine** (`dispatchToDelivery`) and **BrainCore event emit** (`emitOrderCreated` → EventEngine).

### 5. Scale / arch risk
- **(a) Shared identity — mostly ready, one real limit:** identity is already shared, but a **vendor has a single `vendors.type` (service|shopping) and a single `category_id`** (Audit 02 §4a). A vendor who does *both* service and shopping (and later video/creator) cannot be modeled cleanly — the one-app vision wants one vendor across engines, the schema forces one type per vendor row.
- **(b) Multi-city — not ready:** `orders` has **no `city_id`**; the shipping address is free-text JSON (`{city: "..."}`), same city-blindness as bookings (Audit 07 §2). No city scoping on `products`/`inventory`/`warehouses`.
- **(c) Unified feed — ready in principle:** products already carry `vendor_id`, `category_id`, `rating`, `total_sold` — enough for the feed engine to rank them (and `buildUserFeed` already tries, §Brain). 
- **🔴 Cross-engine money collision (activation-gated landmine):** `wallet_transactions.order_id` is **overloaded to hold both order_id and booking_id** (Audit 05 §5.2 / Audit 06 H3). Today this is masked *only because shopping is dormant*. **The moment Shopping goes live alongside Service, booking #N and order #N collide in the ledger.** This must be fixed *before* Shopping is un-dormanted, not after.

---

## Engine B — Video Engine · **READINESS: SCAFFOLD ONLY**

### 1. Readiness
**Pure placeholder — honestly labeled as such.** `body/video-engine/` = `VideoController.php`, `video_feed_api.php`, `README.md`. Every method (`getFeed`, `uploadVideo`, `likeVideo`) returns `{status:'coming_soon'}` with **zero DB queries, zero file operations** (the file header says so explicitly). `README.md` marks it `RESERVED — Not yet active` and lists activation pre-reqs: 100+ active vendors, a contracted **video CDN** (Cloudflare Stream / Bunny / S3), a creator verification flow, and a `video_content` table that **exists only as a comment block in `master_schema.sql`, not as a real table**.

### 2. Tables + shared identity
- **Own tables:** effectively **none yet** — `video_content` is not created. The nearest existing tables are `stories` (vendor_id, media_url, media_type, expires_at) and `followers` (Audit 02 content/social group), which are dormant content-engine tables.
- **SHARES CORE IDENTITY — YES (by design intent).** Planned creators are `vendors`/`users`; `stories.vendor_id`→`vendors.id`, `followers.follower_user_id`→`users.id`. No separate video-identity table is planned. Uploads are meant to feed `brain_events` (`video_view/like/complete`) keyed by the same `user_id`.

### 3. Frontend state
- No customer video UI. App router has a commented-out `// Phase 3: creator` route (Audit 01 §2). Nothing to hide because nothing is built.

### 4. Integration points
- **Designed** to connect to the Brain: README step 5–6 says feed `brain_events` with video events and activate `brain/rules/video_ranking_placeholder.php` (which currently just `return 0` — Audit 01 §5.5). The Brain's `FeedRankingEngine` already has a `type` dimension ready to accept `video` items.
- Registry slot `'video' => ENGINE_VIDEO_URL` — meant to be an external CDN-backed service.

### 5. Scale / arch risk
- **(a) Shared identity — ready** (planned on `users`/`vendors`).
- **(b) Multi-city — undefined** (no schema yet → design it city-aware from the start, unlike the others which have to be retrofitted).
- **(c) Unified feed — this is where video *must* slot in:** the feed engine already models items by `type`; video is the third type. **Biggest new dependency: a media store + CDN** (same one the vendor Instagram profile F5 needs — Gap Map F5). Video with no CDN/transcoding does not scale; the README already names this.
- **Advantage of being a scaffold:** because nothing is built, video is the one engine that can be designed *natively* on the V2 foundation (shared identity + city_id + unified media table) instead of retrofitted.

---

## Engine C — Delivery Engine · **READINESS: PARTIAL (thin PHP shim over an external system)**

### 1. Readiness
**Partial and hybrid.** The in-repo PHP side (`heart/api/delivery/index.php`, read in full) is a real but thin **shim** to an **external "SwiftDeliver" Node/Railway system**:
- `GET /api/delivery/quote` — real Haversine pricing via `core/helpers/DeliveryPricingEngine` (city-agnostic distance math).
- `POST /api/delivery/dispatch` — creates a `deliveries` row and **cURLs an external `SWIFTDELIVER_URL/api/dispatch`** with a bearer secret.
- `PATCH /api/delivery/{id}/status` — driver updates status, mirrors to `orders`.
- `POST /api/delivery/status-update` — **inbound webhook from the Node system**, secret-verified (`hash_equals` on `X-Webhook-Secret`), maps rider status → order status.

So the **routing, pricing, dispatch, and webhook plumbing exist**, but the **actual rider assignment/tracking lives outside this repo** (the Railway Node app). Registry `'delivery' => ENGINE_DELIVERY_URL` is empty locally — delivery is intended to run as that external service, not an in-process engine. *(Note: the dead `swiftdeliver-main/` React/TS prototype in the repo root, Audit 01 §5.2, is an old superseded prototype — not the live Node system this shim calls.)*

### 2. Tables + shared identity
- **Own tables:** `deliveries` (order_id, driver_id, status), `driver_wallets` (driver_id, cash_in_hand, earnings, collection_limit).
- **SHARES CORE IDENTITY — YES.** `deliveries.driver_id` → `users.id` (SET NULL) and `driver_wallets.driver_id` → `users.id` (Audit 02 FK map); a driver is a `users` row with `role='delivery'` (the role enum already includes `delivery`). `deliveries.order_id` → `orders.id`. **No separate driver-identity table** — drivers are users. External rider IDs from the Node webhook (`rider_id`) are written into `deliveries.rider_id`, i.e. the external system maps onto the shared identity.

### 3. Frontend state
- No in-repo customer/driver delivery UI in the service pilot; admin `delivery.html` exists but is `hiddenInServiceOnly` (Audit 01 §4, Audit 04 context). Driver-facing UI is the external Node app.

### 4. Integration points
- **Tightly coupled to Shopping:** `OrderService::dispatchToDelivery()` auto-calls the same external system on order creation; delivery status mirrors back into `orders.status`. Delivery is essentially **Shopping's fulfilment arm** — it has no relationship to service `bookings` today.
- Shares `orders`, `users` (drivers), `Logger`, `Response`, `AuthMiddleware`.

### 5. Scale / arch risk
- **(a) Shared identity — ready**, but note the **`notifications.user_id int(10)` vs `users.id bigint(20)` width mismatch** (Audit 02 §4c) and that driver wallets share the same **unwired settlement** problem as vendor wallets (`runSettlement` never runs — Audit 05 §3, Audit 06 H2). Driver cash/earnings won't settle through the system either.
- **(b) Multi-city — partially better-positioned:** delivery is **geo/lat-lng based** (Haversine), which *would* scale across cities — but `deliveries` has **no `city_id`**, and there is no per-city delivery pricing/zone config. Cross-city dispatch is unmodeled.
- **(c) Unified feed — N/A** (delivery is fulfilment, not a feed surface).
- **External dependency risk:** delivery correctness depends on the external Node/Railway system + shared secret being live; a second market means standing up / configuring that external system per region.

---

## Engine D — AI Brain · **READINESS: PARTIAL (rich scaffold, wired, leans on fallbacks & missing columns)**

### 1. Readiness
**The most elaborate scaffold — genuinely wired, but not proven "smart," and partly broken on missing schema.** `brain/` is a full self-contained subsystem: `BrainCore`, `FeedRankingEngine`, `engine/` (Rule/Suggestion/Action/Alert/Integration), 11 `models/`, `core/` utilities (Router, RateLimiter, DecisionLogger, TimezoneResolver, DeviceDetector, FallbackResponse), `Controllers/`, its **own route table** (`brain/routes.php`: `/api/decision`, `/api/event`, `/api/feedback`, plus `/admin/*` rules/logs/suggestions/habits/dashboard), and a `NightlyJob` script. It is wired into the **customer** request pipeline via `heart/pipeline/BrainConnector.php` (Audit 01 §4 Engine 12).

**What's real:** event logging (`BrainCore::observe` → `brain_events`), a context builder (timezone/device/user-preference injection), a rule/decision/suggestion/habit model layer, and — critically — **`BrainCore::buildUserFeed()`**, which implements the founder's unified feed in three phases (cold-start → learning → personalized), **mixing ~60% shopping / 40% service** and boosting followed vendors.

**What's stubbed / broken (honest):**
- The feed logic is littered with `// dummy logic` / `// dummy struct` and placeholder relevance scores (`$relevance = 10`).
- 🔴 **It queries columns that don't exist.** `buildUserFeed` does `SELECT city FROM users` and `FeedRankingEngine::getTrendingItems` does `SELECT … total_orders, city FROM vendors … WHERE city = ?`. Per Audit 02, **`users` has no `city`**, and **`vendors` has neither `city` nor `total_orders`** (and Audit 07 confirms no city anywhere). These queries throw → the feed falls back / returns little. **The unified feed is pre-written but non-functional against the real schema.**
- Brain output broadly leans on `core/FallbackResponse.php` and `brain/rules/video_ranking_placeholder.php` (Audit 01 §5.5).
- **Zero admin-panel surface** — no `admin/*.html` references the Brain (Audit 04 Engine 12). Its own `/brain/admin/*` routes exist but aren't mounted in the main Admin control room.

### 2. Tables + shared identity
- **Own tables:** 12 `brain_*` tables — `brain_clients`, `brain_rules`, `brain_decisions`, `brain_actions`, `brain_events`, `brain_feedback`, `brain_habits`, `brain_suggestions`, `brain_improvements`, `brain_user_profiles`, `brain_alerts`, `brain_rate_limits` (Audit 02 Brain group).
- **SHARES CORE IDENTITY — YES, but adds its own tenancy + a type inconsistency:**
  - It reads `users`/`vendors`/`followers` directly (feed, trending) and logs `brain_events.user_id` as the same user id.
  - **But it layers a second identity dimension: `client_id` (varchar)** — a multi-tenant "Brain client," hardcoded `'haldwani'` in rules/suggestions/habits (Audit 07 §1). This is a **tenancy concept parallel to city** (see risk c).
  - **Type inconsistency:** `BrainCore::observe()` types `user_id` as `int` and `brain_events.user_id` is int-ish, but `ContextBuilder`/`brain_user_profiles.user_id` treat it as **varchar** (Audit 02 lists `brain_user_profiles.user_id` as varchar). Same user, two storage types across the Brain — a join hazard.
- **No separate customer/vendor identity table** — the Brain observes the shared users/vendors; `brain_user_profiles` is a *derived* profile keyed by user_id, not an alternate account.

### 3. Frontend state
- No customer-facing Brain UI (it influences ranking behind the scenes). No admin UI (Audit 04 Engine 12). `buildUserFeed` is the intended engine behind a future unified feed screen that isn't built.

### 4. Integration points
- **Wired into the customer pipeline** (`BrainConnector`, `PriorityResolver`, `EngineLoader` — Audit 01 §4) and **reads every engine's shared tables** (`users`, `vendors`, `followers`, and implicitly products/services via trending).
- **Designed as the layer *over* all engines:** it already tries to rank products + services together, and the Video README routes video events into `brain_events`. This is the "Brain on top" of the founder's model — present in skeleton.

### 5. Scale / arch risk
- **(a) Shared identity — ready, but reconcile the `client_id` tenancy and the int/varchar `user_id` split** before building on it. Two identity dimensions (user_id + client_id) plus a type mismatch is a foundation smell.
- **(b) Multi-city — actively blocking:** the Brain **already assumes a `city` per user and per vendor** that the schema lacks. This is the clearest proof that **`city_id` is the gating foundation for the unified feed** — the feed code is *waiting for* a city model that was never built. Also `client_id='haldwani'` conflates "Brain tenant" with "city."
- **(c) Unified feed — the scaffold exists (this is a head start), but it needs:** a canonical **cross-engine "feed item" abstraction** (today it fakes `type` from `vendors.type`), real per-user category signals (not `// dummy`), and the missing city columns. The founder's "one feed" is closer than it looks — but only after the city + item-model foundation lands.

---

## Summary table

| Engine | Readiness | Shares core `users`/`vendors` identity? | Own tables | Frontend | Blocking risks for V2 foundation |
|---|---|---|---|---|---|
| **Shopping** | **SUBSTANTIAL** (production-grade OrderService v3.0; COD-locked; commission-settle unwired) | **YES — fully** (no separate identity tables) | products, product_variations, cart, orders, order_items, inventory, warehouses | Built + wired, **hidden by `SERVICE_ONLY_MODE`** | 🔴 `wallet_transactions.order_id` booking/order collision **goes live when un-dormanted** (H3); no `city_id`; single `vendors.type` can't model multi-engine vendor; order commission unwired |
| **Video** | **SCAFFOLD ONLY** (all `coming_soon`, zero DB) | **YES — by design** (creators = vendors/users) | none yet (`video_content` is a schema comment; `stories`/`followers` dormant) | None built | Needs **media store + CDN**; no schema yet (design city-aware now); slots into feed as 3rd `type` |
| **Delivery** | **PARTIAL** (real PHP shim → external Node/Railway "SwiftDeliver"; quote/dispatch/webhook real) | **YES** (drivers = `users.role='delivery'`) | deliveries, driver_wallets | External Node app; admin `delivery.html` hidden | External-system dependency per region; no `city_id`/zone config; driver wallet settlement unwired (H2) |
| **AI Brain** | **PARTIAL** (rich wired scaffold; leans on fallbacks; **feed queries non-existent columns**) | **YES**, but adds a parallel **`client_id` tenancy** + int/varchar `user_id` split | 12 `brain_*` tables | No admin UI; no customer UI | 🔴 `buildUserFeed` needs `users.city`/`vendors.city`/`vendors.total_orders` that **don't exist** → **`city_id` gates the unified feed**; reconcile client_id vs city; unify user_id type |

---

## FOUNDATION IMPLICATIONS — what the V2 database MUST get right now

So all three future engines (Shopping, Video, Brain-over-all) plug in cleanly **without a rewrite**, the V2 data-model pass (Gap Map Phase C) must bake in the following. Every item is grounded in what the dormant code already assumes or already does.

1. **Keep the shared `users`/`vendors` identity — it's the biggest thing already right.** Every dormant engine references the same `users`/`vendors` (customers, shopping/service vendors, delivery drivers, future creators). **Do not fork identity per engine.** *But* evolve the vendor model: replace the single **`vendors.type` (service|shopping) + single `category_id`** with a **vendor↔engine and vendor↔category many-to-many**, so one vendor can be active in service *and* shopping *and* video/creator. The one-app vision requires one vendor across engines; today's schema forces one type per vendor row. (Ties to Audit 02 §4a / Gap Map M7.)

2. **Add `city_id` everywhere — it is the gate for the unified feed, not just multi-city.** The Brain's `buildUserFeed`/`getTrendingItems` **already query `users.city` and `vendors.city`** (and `vendors.total_orders`) that don't exist. Add `cities` + `city_id` to `users` (home city), `vendors`, `services`, `products`, `orders`, `bookings`, `deliveries` — and add the trending counters the feed expects (or a view that supplies them). Without this, the pre-built feed stays dead. (Audit 07 + this audit's Brain finding.)

3. **Introduce ONE unified media table (photo + video) shared across engines.** Media is currently fragmented — `products.images`(json) + `products.images_json`(text) (Audit 02 §3 duplicate), `stories.media_url`, `users.avatar_url`, URL-paste vendor logos (Audit 01 §3), and a not-yet-created `video_content`. The V2 vision needs the **same media model** for vendor Instagram photos/videos (Gap Map F5), product images, and video-engine content — one `media` table (owner_type/owner_id, type photo|video, url, city_id, location tag) behind **one CDN**. Decide the CDN now (Video README + F5 both demand it).

4. **Define a canonical cross-engine "feed item" abstraction.** `FeedRankingEngine` fakes item `type` from `vendors.type` and mixes product/service by hardcoded ratios. For a real one-feed, model a normalized feed item (type = product|service|video, vendor_id, category_id, city_id, score inputs) so all three engines surface through one ranked feed. The Brain is ready to consume it.

5. **Fix `wallet_transactions` polymorphism BEFORE Shopping is un-dormanted.** The `order_id` column holds both order_id and booking_id (Audit 05 §5.2 / Audit 06 H3); it is safe today *only because Shopping is off*. Add a `reference_type` (order|booking) — or separate columns — as part of the money-safety pass, **before** activating Shopping, or service bookings and shopping orders will collide in the ledger on day one. This is the one dormant-engine risk that becomes a live bug purely by turning an engine on.

6. **Reconcile the Brain's `client_id` tenancy with `city_id`, and unify `user_id` type.** Decide whether the multi-tenant dimension is *city* (recommended, matches the rest of V2) and retire the hardcoded `client_id='haldwani'` (Audit 07 §3), rather than shipping two overlapping tenancy models. Make `brain_*.user_id` consistently match `users.id`'s type (Audit 02 lists `brain_user_profiles.user_id` as varchar vs int elsewhere).

7. **Preserve the engine-registry seam so engines stay separable.** `config/engines.php` already lets Delivery/Video run as external service URLs and keeps Shopping/Service behind the `{intent,data}` Heart pipeline. Keep that intent contract stable and engine-agnostic in V2 so each backend can scale (or be externalized) independently — this is the founder's "three separate backends behind one app," and it already exists at the routing layer. Don't let V2 features bypass the pipeline with engine-specific coupling.

8. **Wire settlement once — it serves all engines.** `runSettlement` (vendor wallets) and `processOrderCompletion` (shopping commission) both have no caller (Audit 05 §3, Audit 06 H2/L10), and driver wallets share the same freeze. Building settlement in the money-safety pass unblocks vendor, shopping, and delivery payouts together — design it engine-agnostic (keyed by `reference_type` from #5).

---

## Verdict
The dormant engines are **far more identity-ready than they are city-ready.** All four already share the core `users`/`vendors` model with **no separate identity tables** — Shopping and Delivery reference them directly, Video plans to, and the Brain observes them (while adding a `client_id` tenant layer to reconcile). **Shopping is substantially built** (a careful, transaction-safe commerce engine hidden behind one flag), **Delivery is a working shim over an external Node system**, **Video is an honest empty scaffold**, and the **Brain is a rich, wired scaffold that already contains the founder's unified cross-engine feed** — which is dead only because it queries a `city` model that was never built. The V2 foundation therefore has a clear mandate: **keep the shared identity, evolve the vendor to span engines, add `city_id` and a unified media/feed model, and fix the `wallet_transactions` collision before flipping Shopping on.** Get those right in the data-model pass and all three engines plug in without a rewrite; skip them and each engine becomes its own retrofit.

---
*Read-only readiness pass over `body/shopping-engine`, `body/video-engine`, `heart/api/delivery`, `core/helpers/DeliveryPricingEngine`, and `brain/`. Never-touch engines were read only. No files were modified.*
