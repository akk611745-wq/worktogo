# Role Audit 05 — DELIVERY/RIDER + SYSTEM PERFORMANCE / TRAFFIC / RESILIENCE

> **Scope:** (A) the **delivery/rider role** (readiness for when commerce/delivery goes live — it is hidden at launch), and (B) the founder's system-wide concern: **how the system behaves under real traffic and when a component fails.** Grounded in real `wtg-v2` code (branch `v2`) as of **2026-09-02**.
> **Method:** Read the delivery stack (`core/delivery/DeliveryEngine.php`, `core/helpers/DeliveryPricingEngine.php`, `core/helpers/LedgerEngine.php`, `heart/api/rider/index.php`, `heart/api/delivery/index.php`, `rider/*`, `cron/delivery_lifecycle.php`), the auth failover (`lib/auth/OtpChannelManager.php`), the payment abstraction, the DB layer + **all 52 migrations** for index coverage, and the app shell for first-load weight. Cross-read `role-01`–`role-04`.
> **Read-only.** Nothing edited/moved/renamed/deleted. `shopping-engine`, `video-engine`, `orders.js` read only. "Where to fix X" cites `MAP/system-map.md` §C.
> **Verdict up front:** **Delivery is a genuinely well-engineered engine (~80% ready for when it launches)** — lock-safe nearest-rider assignment, a full stuck/no-rider/reassign/unsettled lifecycle cron, IDOR-tight rider scope, and idempotent COD cash reconciliation. **On resilience the news is good: failures are ISOLATED, not cascading** — a payment, OTP, or AI outage degrades only its own feature; there is **no cross-feature domino**. **The two real risks are (1) single-provider dependence** (payment *and* OTP each have the failover machinery built but only ONE real provider wired, so an outage of that one provider takes down *that feature* until it recovers), and **(2) unverifiable hot-table indexes** (the oldest, hottest tables — `bookings`, `otps`, `jobs`, `auto_assignments` — are inherited from the V1 schema, not the repo's migrations, so their index coverage on the hottest queries must be `EXPLAIN`-checked on the live DB before real traffic). Plus the heavy first-load shell from role-03/04.

---

# PART A — DELIVERY / RIDER ROLE

## A0. Identity & scope
A rider is a `users.role='delivery'` account; operational state lives in `delivery_riders` (online, lat/lng, `max_active`, `active_count`) and money in `driver_wallets` (`cash_in_hand`, `earnings`, `collection_limit`). Every rider route is `AuthMiddleware::requireRole(ROLE_DELIVERY)` and **resolves the rider from the JWT `user_id`** — never a client id (`heart/api/rider/index.php:24-25`). **How a rider gets an account is admin-side** (set `users.role='delivery'`); there is **no self-serve rider onboarding UI** — a gap for scale, fine for a controlled launch.

## A1. RIDER JOURNEY — **BUILT, end-to-end, IDOR-safe**
- **Login / first use:** `DeliveryEngine::ensureRider` `INSERT IGNORE`s a `delivery_riders` row on first call — no separate rider registration step needed once the role is set.
- **Go online / location:** `POST /api/rider/online {online}` and `POST /api/rider/location {lat,lng}` — the ping **validates coordinate ranges** (L4 fix) and is **rate-limited** (120 pings/min, 90 writes/min per rider).
- **My deliveries:** `GET /api/rider/deliveries` returns only `WHERE d.driver_id = <me> AND status IN ('assigned','picked')` — **own scope only**; the customer's drop address + phone are surfaced **only for the rider's own active jobs** (operationally necessary for delivery; note it is *not* hidden-until-pickup the way service masks phone).
- **Receive → run:** `assigned → accept → pickup → deliver`, each a guarded state transition through `DeliveryEngine::riderAccept/Pickup/Deliver`, all gated by `ownedDelivery()` (403-equivalent "not assigned to you"). **Reject** frees capacity and **auto-reassigns to the nearest DIFFERENT rider** (`riderReject → assignNearest(excludeSelf)`).
- **Earnings / cash-in-hand:** shown via `GET /api/rider/me` (`cash_in_hand`, `earnings`, `collection_limit`). On **deliver**, `LedgerEngine::processCommerceOrderSettlement` books rider payout + platform margin + **COD cash owed** into `driver_wallets`, **idempotently** and **non-fatally** (a settlement hiccup does not un-deliver; the lifecycle cron retries) (`DeliveryEngine.php:368-382`, `LedgerEngine.php:582-600`).
- **Tracking:** `DeliveryEngine::track(orderId)` gives the customer a live snapshot (status, ETA, rider name/phone/lat/lng, `tracking_token`).

## A2. DELIVERY LIFECYCLE — **BUILT, resilient**
- **Order → delivery handoff:** `createForOrder` is **idempotent per order** (one delivery row), prices via the rate card, sets ETA (`10 + dist·4`).
- **Pricing:** `DeliveryPricingEngine`/`DeliveryEngine::quote` — Haversine distance × the **city rate card** (`delivery_rate_cards`, admin-managed in delivery-control, role-01 §1-10): base + per-km + min-fee, `free_above_subtotal`, `max_distance_km` (→ "Outside delivery range"), and a `rider_share_pct` / `platform_margin_pct` split. City-specific card preferred, platform default fallback.
- **Assignment — lock-safe nearest rider:** `assignNearest` opens a txn, `SELECT … FOR UPDATE` on the delivery, ranks online riders **with spare capacity** by Haversine distance, and claims a candidate with **`LIMIT 5 FOR UPDATE SKIP LOCKED`** — the same concurrency-safe pattern as the vendor `auto_assign` cron. No rider available ⇒ **stays `pending`** for retry/manual (no crash).
- **No-rider / stuck / reassign / late (the commerce-fix edge cases):** `cron/delivery_lifecycle.php` handles all of them, config-driven from `platform_settings`: **(A)** pending/no-rider → retry `assignNearest` every N s, alert admin if stale; **(B)** assigned-not-accepted → flag `stuck` + alert; **(C)** picked-not-delivered → flag `stuck` + alert (needs backup); **(E)** delivered-but-unsettled → **retry settlement** (belt-and-braces); **late** past ETA → flag `late`. Admin can **`adminReassign`** to a backup or the nearest different rider.
- **Cancel:** `cancelForOrder` (M3 fix) cancels the active delivery **and frees the rider's `active_count`** — no orphaned job / leaked capacity.
- **COD cash reconciliation:** cash accumulates in `driver_wallets.cash_in_hand`; **`isDriverBlocked` blocks a rider from new assignments once `cash_in_hand >= collection_limit`** (checked in `assignSpecific` + admin accept). *Gap:* **clearing** that cash (rider deposits to the company) is **external/manual** — there is no in-app "rider deposited cash" reconciliation endpoint, and COD refunds intentionally leave `cash_in_hand` (documented). Fine for a small fleet; needs a deposit-reconciliation flow before scale.

## A3. DELIVERY EDGE CASES
- **Rider offline / no rider:** delivery stays `pending`; the cron retries + alerts admin. Handled. *Fix locus:* `cron/delivery_lifecycle.php` + `DeliveryEngine::assignNearest` (system-map §C "delivery/rider → core/delivery").
- **Stuck (not accepted / not picked / not delivered):** flagged `stuck` + admin alert + reassign path. Handled.
- **Cancel mid-flight:** `cancelForOrder` frees capacity. Handled.
- **IDOR (rider sees others' data):** **strong** — every read/action is `WHERE driver_id = <jwt>` / `ownedDelivery()`; a rider cannot see or act on another rider's delivery. *One caveat:* the customer **`tracking_token`** — confirm the public track-by-token endpoint validates the token (not a guessable order id) so a stranger can't watch a delivery.
- **Concurrent assignment (two riders / cron races):** `FOR UPDATE` + `SKIP LOCKED` on both the delivery and rider rows makes assignment first-writer-wins; the order-accept path (`api/assignments/accept.php`, role-02) is likewise `FOR UPDATE`-guarded with an "already accepted by another" 409. Handled.
- **Cash overflow:** `isDriverBlocked` stops new assignments at the limit. Handled (minus the manual deposit-clear gap, A2).

**Delivery role readiness (for when it launches): ~80%.** The engine, lifecycle, pricing, concurrency, and COD accounting are genuinely production-grade. The gaps are **operational, not structural**: no self-serve rider onboarding, no in-app cash-deposit reconciliation, rider app UI is thin (`rider/rider.js` ~9 KB), tracking-token validation to confirm, and the same staging-config + index-verification concerns as everything else.

---

# PART B — SYSTEM PERFORMANCE / TRAFFIC / RESILIENCE

## B4. TRAFFIC / SCALE — what slows or falls over first

**What's solid:**
- **The V2 tables are well-indexed.** Migrations add composite/geo/covering indexes exactly where the hot V2 queries need them: `idx_jobs_vendor_status_created (vendor_id,status,created_at)`, `idx_aa_vendor_type_status`, `idx_deliveries_status_city`, `idx_deliveries_driver_status`, `idx_deliveries_lifecycle`, `idx_rider_avail (is_online,city_id)`, `idx_rider_geo`, `idx_ratecard_city`, `idx_stores_city_own_pri`, `idx_media_owner_status`, `idx_packages_category`, `idx_evp_scope`, RBAC + agent indexes. This is careful work.
- **Concurrency-safe hot writes:** booking dedup (`client_request_id`), payment webhook idempotency, `FOR UPDATE (SKIP LOCKED)` on both vendor and rider assignment, single-use confirm tokens. Under a spike, the *money/assignment* paths won't double-fire.
- **Prepared statements everywhere** (`EMULATE_PREPARES=false`) — good plan reuse + injection safety.

**What will slow or fall over first (ranked):**
1. **Unverifiable indexes on the OLDEST, HOTTEST tables.** `bookings`, `jobs`, `otps`, `auto_assignments`, `users`, `orders` are **created in the inherited V1 schema, NOT in the repo migrations** (the V1 dump referenced by the map is not present on disk). So the index coverage of the hottest queries is **unknown from the repo** and must be `EXPLAIN`-checked on the live/staging DB:
   - `bookings WHERE user_id = ?` (every customer "My Requests"), `WHERE vendor_id = ?`, `ORDER BY created_at` (admin), and **`WHERE client_request_id = ? AND user_id = ?`** — the dedup lookup that runs on **every booking create**. If `client_request_id`/`user_id` aren't indexed, every booking is a full scan.
   - **`otps WHERE phone = ? AND used = 0`** — runs on **every OTP send and verify**. Un-indexed `phone` = a full scan on the busiest auth path.
   - `jobs.booking_id` (join on every booking view), `auto_assignments (entity_type, entity_id)` (the accept `FOR UPDATE`).
   *This is the single biggest "falls over first" risk and it is a 30-minute verification, not a rewrite.*
2. **No server-side response caching at launch.** The Heart's cache layer (`ReflexEngine`/`BrainConnector`, APCu) rides the **Brain, which is OFF** (`BRAIN_LAYOUT:false`). And the catalog GETs are **client cache-busted** (`?_t=`, role-03). So under a spike, **every catalog/services/packages hit goes to MySQL** — the read path has no shield. A single city is fine; a viral moment is not.
3. **One DB connection per request, no pooling/persistent.** `Database` is a per-request PDO singleton (no `ATTR_PERSISTENT`). PHP is shared-nothing, so concurrency is bounded by **MySQL `max_connections`** and the web tier's worker count — the classic shared-hosting ceiling.
4. **A few admin-side N+1s** (lower concurrency, so lower priority): the admin bookings list attaches `serviceBookingEligibility` **per booking** for admins (role-01), and the payment room aggregates per-vendor. Fine for one ops user; would bite a large admin table.
5. **Client-side parse cost:** `home.js` is **215 KB** to parse on a ₹6-8k phone (role-03) — a per-user CPU cost, not a server one, but it shapes "feels slow."
6. **Crons scan by status:** `auto_assign` and `delivery_lifecycle` scan `WHERE status='pending'` — delivery has `idx_deliveries_lifecycle`; confirm `jobs`/`auto_assignments` have the matching status index (ties back to #1). At pilot cadence (every N min, tens of rows) this is trivial; at scale it needs the index.

**What scales it (hosting/infra):** move off shared hosting to a small **VPS/managed app tier** with a **managed MySQL** (dedicated connections, tunable `max_connections`, slow-query log), put a **CDN in front of `/app` static + uploads**, and add a small **cache** (APCu is already a dependency; Redis later) for the catalog. None of this is code — it's a tier choice (B8).

## B5. FAILURE RESILIENCE — isolated or cascading?

**The nervous-system isolation HOLDS — no cross-feature cascade.** Each external dependency degrades only its own feature:

| If this fails… | What happens | Isolated? |
|---|---|---|
| **Payment gateway (Cashfree)** | `PaymentGatewayManager` has primary→secondary **failover built**, but every engine's `secondary=null` today (Razorpay is a stub) → **no live failover**. Booking still creates and is marked `payment_status='failed'` with a friendly "gateway not configured/again" (role-03 §1C, credential guard). **Only paid Expert-Visit is affected; free requests, browsing, tracking all keep working.** | ✅ Isolated to paid flow |
| **OTP/SMS provider (MSG91)** | `OtpChannelManager` failover is **built and correct** (primary→secondary on retryable-only, B6), but the only real provider is MSG91 and the secondary is the dev-`log` channel → **no real diversion today**. MSG91 down = **OTP login down** until it recovers (Google/email login still work). | ✅ Isolated to OTP login |
| **AI provider (Anthropic)** | Degrades to Mock → **deterministic-only** planning; common asks still served (role-04). | ✅ Isolated, graceful |
| **A cron doesn't run** | Deliveries stay `pending`/unsettled; vendor auto-assign pauses — **work queues up, nothing crashes**; the next run catches up (settlement retry is idempotent). | ✅ Isolated, self-healing |
| **Firebase (push)** | Notifications off at launch anyway; no functional impact. | ✅ Isolated |
| **Google Maps** | Address autocomplete/geocode degrade to manual text — **but the Maps script is render-BLOCKING** (B7), so a slow/blocked Maps CDN hurts *first paint*, not correctness. | ⚠️ Isolated but slows first load |

**Single points of failure:** the **DB (one MySQL)** and the **single PHP host + the Heart front-controller** — i.e. the normal monolith infra SPOFs. If MySQL is down, everything is down (expected for this architecture/tier). **There is no application-level cascade** — a payment/OTP/AI/cron failure never brings down the rest. That is exactly the founder's "if one thing fails the whole system stays up" goal, **at the application layer**. The remaining exposure is **single-provider** (payment, OTP) and **single-infra** (DB/host) — addressed by wiring a real failover partner (config) and a better tier (hosting), not by re-architecting.

## B6. LOGIN / OTP RESILIENCE (the founder's specific question)

**"If OTP isn't sending, does it divert to another provider automatically?"** — **The engine does exactly that, correctly, today** (`OtpChannelManager::sendWithFailover`, `:167-216`): it tries the primary; on a **retryable (provider-down) failure only** it tries the secondary and reports which channel delivered; a *legitimate* reject (bad number) does **not** fail over (correct). Plus per-phone + per-IP throttles and a **fail-loud-in-prod** guarantee (role-02 §1A).

**BUT the diversion has nowhere to go yet.** The only real, configured SMS channel is **MSG91**; the routing secondary is the **dev-`log`** channel (not usable in prod), and `sms2`/`whatsapp` are `enabled=false` stubs (`config/auth.channels.php`, role-01). So **automatic diversion will start working the moment a second real channel is configured and set as `routing.sms.secondary`** — a **config + credentials change, no code**. Until then, an MSG91 outage means OTP login is unavailable (customers can still use Google/email) until MSG91 recovers. *Precise answer: the smart-auth failover is real and end-to-end; it just needs a second real provider wired in.* Same story, same fix shape, as the payment layer.

## B7. FIRST-IMPRESSION / SPEED (weak internet, cheap phone) — quantified

The customer/agent SPA (`app/index.php`) blocks the first meaningful paint on a stack of external scripts:
- **Google Maps JS is a *synchronous, render-blocking* `<script>`** (no `async`/`defer`, `app/index.php:94`) — on 3G this stalls the whole page until Maps loads or times out. **Highest-impact single fix.**
- **Firebase app + messaging** (two `gstatic` files, ~100 KB+) load **even though `NOTIFICATIONS:false`** — pure waste at launch.
- Plus **Google GSI**, **Cashfree SDK**, **Google Fonts**, two stylesheets, then `config/ui/api/auth/router/push` and the page — with **`home.js` at 215 KB** to parse.
- Pinch-zoom is disabled (accessibility, role-03).

**Concrete first-open fix list (the "don't let the customer leave" list):**
1. **`defer` + lazy-load Google Maps** — load it only when the address step is reached, not on boot. *(biggest win)*
2. **Drop Firebase entirely while `NOTIFICATIONS:false`** — don't ship ~100 KB for a disabled feature.
3. **Code-split `home.js`** — the booking flow shouldn't be one 215 KB blob; split the paid-visit/commerce/gift paths behind interaction.
4. **Self-host + subset the fonts** (or `font-display:swap`); **preload** the critical CSS.
5. **Async the GSI + Cashfree SDKs** (Cashfree only needed at checkout).
6. **Service-worker precache** the shell (a manifest + `sw.js` already exist) so repeat opens are instant.
7. Re-enable pinch-zoom.

Done, first paint on a cheap phone goes from "wait on a blocking map script + 300 KB of JS" to "shell paints immediately, heavy bits load on demand."

## B8. PERFORMANCE MUST-FIXES (ordered) — code vs hosting

**Code / config (do before real traffic):**
1. **VERIFY hot-table indexes on live/staging** (`EXPLAIN` the five queries in B4-#1: `bookings.user_id`, `bookings.client_request_id`, `otps.phone`, `jobs.booking_id`, `auto_assignments(entity_type,entity_id)`); add any missing as a new dated migration. *(system-map §C "schema → migrations/")* — **the #1 traffic-survival item.**
2. **Wire a real OTP secondary + payment secondary** (B5/B6): a 2nd SMS/WhatsApp channel in `config/auth.channels.php` + `routing.sms.secondary`, and enable Razorpay in `config/payment.gateways.php` + `secondary=>'razorpay'`. *(system-map §C "OTP → lib/auth", "payment → lib/payment")* — turns "single provider" into real failover.
3. **First-open slimming (B7 list)** — defer/lazy Maps, drop Firebase-when-off, split `home.js`. *(system-map §C "customer surfaces → app/")*
4. **Turn on a read cache for the catalog** (APCu is already present) or stop cache-busting the public catalog so the browser/CDN can cache it.
5. **Rider: add a cash-deposit reconciliation endpoint + self-serve rider onboarding** before the fleet grows (A2/A1).

**Hosting / infra (buy, don't build):**
- **App tier:** move off shared hosting to a small **VPS or managed PHP app platform** (predictable CPU + worker count).
- **Database:** a **managed MySQL/MariaDB** with tunable `max_connections`, automated backups, and the **slow-query log on** (so #1 is easy to monitor). Consider `PDO::ATTR_PERSISTENT` **only** with a connection limit you control.
- **CDN:** front `/app` static assets + `/uploads` media with a CDN (also the natural home for the "provider-agnostic media" switch the code already anticipates).
- **Cron runner:** a real scheduler (not a fragile web-cron) for `auto_assign` + `delivery_lifecycle` + agent crons, with alerting if a run is missed.

---

## HONEST VERDICT

### Delivery/rider role (for when it launches): **~80% ready.**
The engine is real, not a demo — lock-safe assignment, a complete stuck/no-rider/reassign/unsettled lifecycle, distance-based admin-rate-card pricing, IDOR-tight rider scope, and idempotent COD accounting with a cash-limit block. It is correctly **hidden at launch**; when commerce/delivery goes live, the code is close. **Must-fix before delivery launch:** verify the delivery indexes under load, add **rider onboarding** + **cash-deposit reconciliation**, confirm the **tracking-token** is unguessable, and slim the rider app UI. **Post-launch:** live rider maps, richer earnings view.

### System performance / resilience: **will it survive real traffic and a component failure without going down?**
- **Component failure: YES — isolation holds.** No application-level cascade: payment, OTP, AI, cron, Firebase, and Maps failures each degrade only their own feature; the rest of the platform stays up. This is the founder's core requirement, and the architecture genuinely delivers it.
- **The caveat is single-provider, not single-system:** because only one real payment gateway and one real SMS provider are wired, an outage of *that provider* takes down *that feature* (paid booking, or OTP login) until it recovers — even though the **failover machinery is already built and correct**. Fix = wire a second provider (config), not re-architect.
- **Real traffic: YES at pilot scale, VERIFY before a spike.** One city / hundreds of users: comfortable. The three things standing between "pilot-fine" and "spike-proof" are (1) **unverified legacy indexes** (the top risk, a quick `EXPLAIN` away), (2) **no read caching at launch**, and (3) **the connection-per-request ceiling** — all addressable with the B8 list + a better hosting tier. Nothing here is a rewrite.

### Biggest risks (ranked)
1. **Unverified indexes on `bookings`/`otps`/`jobs`/`auto_assignments`** (inherited V1 schema, not in-repo) — the first thing to fall over under load (B4-#1).
2. **Single provider for payment AND OTP** — the failover is built but has no partner wired (B5/B6).
3. **Render-blocking first-load shell** — the "customer leaves before it opens" risk on weak internet (B7).
4. **No server-side caching at launch** + connection-per-request — the spike ceiling (B4-#2/#3).
5. **Delivery operational gaps** (rider onboarding, cash deposit) — only matter when delivery launches.

### Must-fix-before-launch vs post-launch vs hosting-choice
- **Must-fix (code/config, before real traffic):** verify + add hot-table indexes (B8-1); slim the first-open shell (B8-3); wire a real OTP secondary (B8-2, at least) so login has a fallback.
- **Strongly recommended before scale:** enable a payment secondary (B8-2); turn on catalog caching (B8-4).
- **Hosting-choice (buy):** VPS/managed app tier + managed MySQL (slow-query log on) + CDN + a real cron runner (B8 infra).
- **Post-launch:** delivery rider onboarding + cash reconciliation; N+1 cleanups; Redis cache; live rider tracking.

**One-line verdict:** *Delivery is a well-built engine that's ~80% ready for its eventual launch; and the system as a whole already meets the founder's "one failure doesn't take everything down" bar — failures are cleanly isolated. It will carry the pilot comfortably. Before real scale, do three cheap things: `EXPLAIN`-verify the legacy indexes, wire a second OTP (and payment) provider so the built-in failover has somewhere to go, and stop the app shell from blocking first paint. Those are config/tuning + a hosting-tier upgrade — not a rewrite.*

---
*Grounded in `wtg-v2` @ 2026-09-02. Read-only audit — nothing modified. Final companion to `MAP/system-map.md`, `role-01-admin.md`, `role-02-vendor.md`, `role-03-customer.md`, `role-04-agent.md`. This completes the 5-role launch-readiness stress-test.*
