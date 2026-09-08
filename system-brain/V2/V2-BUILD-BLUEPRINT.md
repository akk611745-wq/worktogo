# WorkToGo V2 — Build Blueprint + Effort Estimate

> **Purpose:** The complete pre-build plan for WorkToGo V2 (service engine first, company-grade, scale-ready), built in a **separate local workspace** without touching live V1 on Hostinger, to launch later as **one clean final package**. This document exists so nothing is forgotten at launch — no "forgot a column / upload path broke" surprises.
> **Inputs:** Audits 01–08, V2-VISION.md, V2-GAP-MAP.md (all in `WTG-SYSTEM-BRAIN/`).
> **Status:** READ-ONLY planning. **No feature code written, no live files touched.** Never-touch engines (shopping, video, orders.js) were read for planning only.
> **Date:** 2026-07-04.

**Verified workspace facts (checked live, read-only):**
- The git repo is the **`wtg  live/`** folder (note the double space), remote `origin = github.com/akk611745-wq/worktogo.git`, current branch `main`, working tree clean.
- **13 in-flight `fix/*` and `feat/*` branches** already exist — they map directly to the Audit-06 blockers (`debug/cashfree-prod-order-failure`, `fix/vendor-assign-visibility`, `fix/vendor-category-scope-and-service-id`, `fix/category-resolution-pdo-duplicate-param`, `fix/booking-mode-routing`, etc.). **These are Phase-0 work already begun** and should be harvested, not re-done.
- The desktop root (`mastar wtg live/`) is **not** a git repo; it only holds `wtg  live/` (the product), `WTG-SYSTEM-BRAIN/` (docs), `WTG-HARDENING/`, `swiftdeliver-main/` (dead prototype — Audit 01 §5.2), and the SQL dump.
- Live V1 on Hostinger deploys from this repo. **V2 must not disturb `main` until the single final cutover.**

---

## 1. V2 Workspace Strategy (where V2 lives, and why)

### Recommendation: a dedicated `v2` branch checked out as a **separate physical folder via `git worktree`**

**One git history, two physical folders.** Create a long-lived `v2` branch and check it out into a sibling directory so the live-tracking `main` checkout is never touched:

```
# from inside  wtg  live/
git worktree add ../wtg-v2 -b v2 main
```

Resulting local layout:

```
mastar wtg live/
├─ wtg  live/        ← git branch: main   (mirrors live V1 on Hostinger — DO NOT build here)
├─ wtg-v2/           ← git branch: v2     (ALL V2 work happens here; same repo, isolated files)
├─ WTG-SYSTEM-BRAIN/ ← docs (audits + V2 plans) — shared, not branch-specific
└─ …
```

**Why this over the alternatives:**
- **vs a plain branch in the same folder:** a worktree gives a *physically separate* directory, so there is zero chance of an editor/save/deploy hitting the live `main` checkout. You can have both open at once.
- **vs a fresh unrelated folder/clone:** keeping **one git history** means the 13 in-flight fix branches and every Phase-0/1 fix are cherry-pickable between `main` and `v2`. If the founder later wants to hot-fix a revenue blocker on live V1 *before* the full V2 cutover (e.g. B1 Cashfree), the same commit can land on both. A detached copy loses that.
- **vs building on `main`:** launch discipline — V1 stays deployable and rollback-safe throughout the multi-week V2 build.

### Architect-grade internal structure (three engines, one identity, one feed)

V2 keeps the existing **"engines behind one Heart"** seam (already real — `config/engines.php`, Audit 08 §0) and hardens it:

```
wtg-v2/
├─ heart/            ← ONE gateway + ONE shared auth/identity (users/vendors) + intent router
├─ body/
│   ├─ service-engine/   ← Phase 2 focus: rebuilt company-grade
│   ├─ shopping-engine/  ← reused as-is (substantial), activated in a LATER phase
│   └─ video-engine/     ← scaffold, built LATER natively on V2 foundation
├─ brain/            ← feed/ranking over ALL engines (fix to real city columns)
├─ core/ lib/        ← shared: JWT, PaymentService, LedgerEngine, media/upload, CDN client
├─ config/           ← engines.php registry + per-city config store (not frozen JS)
├─ migrations/       ← every V2 schema change, dated & ordered (the launch spine)
└─ app/ vendor/ admin/ ← one customer feed-app, vendor panel, admin control room
```

**Non-negotiable architecture rules for V2 (from Audit 08's foundation implications):**
1. **One shared user/vendor identity** across all three engines — no per-engine identity tables (already true; keep it).
2. **Every engine dispatched through the `{intent,data}` Heart pipeline** — no engine-specific coupling that bypasses the gateway (keeps engines separable/externalizable).
3. **`city_id` on every domain table** from day one (the unified feed already assumes it).
4. **One unified `media` table + one CDN** shared by vendor profiles, products, and video.

---

## 2. Phased V2 Build Plan (dependency-ordered)

> Golden rule (Vision §9, Gap Map §2): **Foundation → money-safety → data-model unification → new modules.** No Phase-2 feature starts before Phase 0 and its Phase-1 dependencies are done.

### PHASE 0 — Foundation fixes (the 3 launch-blockers, built INTO V2)

These stop real bookings/payments today (Audit 06). They must exist in V2. Much is already started on the 13 branches — harvest those first.

| Chunk | Delivers | Files / Tables | Depends on |
|---|---|---|---|
| **0.1 — B1 Cashfree prod** | Inspection/expert-visit payment actually completes in production | `body/service-engine/api/services/index.php` (inline order path), `lib/PaymentService.php`, `config/payment.config.php`; branch `debug/cashfree-prod-order-failure` | External: verified Cashfree **prod** creds/env/payload |
| **0.2 — B2 assign→jobs.vendor_id** | Admin/ops assignment writes `jobs.vendor_id` in one canonical path so vendors can accept/start/complete | `heart/api/admin/index.php` (assign + ops), `body/service-engine/api/services/index.php` (ownership check), `api/assignments/accept.php`; branch `fix/vendor-assign-visibility` | — |
| **0.3 — B3 phone auth crash** | Upsert-safe login/register; phone normalized; Google/guest keep `phone=NULL` not `''` | `heart/api/auth/AuthController.php`, `otps`/`users` | — |

**Exit criteria:** all 3 booking flows create → assign → vendor-accept → complete end-to-end on a test DB; a ₹1 live Cashfree round-trip succeeds; repeat-phone login never 500s.

### PHASE 1 — Data-model foundation + money-safety

The heaviest phase. Everything in Phase 2 reads this. (Gap Map Phase B+C.)

| Chunk | Delivers | Files / Tables | Depends on |
|---|---|---|---|
| **1.1 — Cities & `city_id`** | `cities` table; `city_id` FK on `users`, `vendors`, `services`, `products`, `orders`, `bookings`, `deliveries`; backfill all to Haldwani; persist booking's dropped `selected_city` | new migration; schema-wide | — |
| **1.2 — Vendor↔category unification** | ONE canonical source (`vendor_categories`); migrate resolver/eligibility/admin reads+writes off `vendors.category_id` and `services.category_id` proxy | `body/service-engine/api/services/index.php`, `heart/api/admin/index.php`, `core/helpers/ServiceVendorEligibility.php`, `vendor_categories` | 1.1 |
| **1.3 — Vendor spans engines** | vendor↔engine many-to-many so one vendor can do service + shopping + video (replaces single `vendors.type`) | `vendors`, new `vendor_engines`/junction | 1.2 |
| **1.4 — Unified media table + CDN** | one `media` table (owner_type/owner_id, photo\|video, url, city_id, geo tag); wired `UploadHelper`→CDN; enable real upload (retire `SERVICE_PROOF_UPLOAD:false`, URL-paste) | new `media`, `core` upload helper, storage/CDN client | 1.1; **external CDN decision** |
| **1.5 — Wallet polymorphism fix (pre-shopping landmine)** | add `reference_type` (order\|booking) to `wallet_transactions` so booking/order ledger can't collide | `core/helpers/LedgerEngine.php`, `wallet_transactions` | — |
| **1.6 — Money-safety wiring** | wire `runSettlement` (cron/admin); booking-level refund path; amount-mismatch → review not `failed` | `core/helpers/LedgerEngine.php`, `lib/PaymentService.php`, `core/controllers/RefundController.php` | 1.5 |
| **1.7 — Canonical booking state machine** | one state model; derive/retire `lifecycle_state`/`assignment_state`/`vendor_response_status`/`vendor_route` | `bookings`, service-engine + admin handlers | 0.2 |
| **1.8 — Column hygiene** | collapse duplicate pairs: `commission_rate`/`commission_pct`, `categories.status`/`is_active`, `products.images`/`images_json`; widen `notifications.user_id` to bigint + FK | schema migrations | — |

**Exit criteria:** a single migration set runs clean on a copy of the prod dump; every location query is city-scoped; one vendor-category source; media uploads land in the CDN; settlement moves `pending→available`.

### PHASE 2 — Service Engine V2 (the launch product)

Built on the clean Phase-1 model. (Gap Map F1–F4, vendor side, admin.)

| Chunk | Delivers | Files / Tables | Depends on |
|---|---|---|---|
| **2.1 — Company View** | Full company surface (not a form): category banner, in-view category switch, general "All" view, two paths (Expert Visit / Packages) | new `app/` customer surface + `service:*` intents; reuse category banner plumbing | 1.1, 1.2 |
| **2.2 — Packages** | Standard/Premium/Luxury per category; **live sqft→cost estimate**; optional tick; request WITH/WITHOUT package; also selectable inside existing booking form | new `packages` (+ per-tier sqft rate, city_id) tables; `home.js` intake | 1.1, 1.2 |
| **2.3 — Expert Visit pay-flow** | Rename inspection→Expert Visit; **per-area × per-category free/paid + price** config; pay-to-confirm; **auto-assign after payment** | expert-visit config table (city×area×category), `services/index.php`, payment return/webhook | 0.1, 0.2, 1.1 |
| **2.4 — Tracking split** | Rich Company track (request→confirm→expert→visit→estimate→work→complete) vs badge Direct track (accepted→started→completed); show internal progress only post-payment | `app/pages/bookings.js`; new `estimate` stage/data | 0.2, 1.7 |
| **2.5 — Vendor profile + real upload** | Instagram-style photos **and** videos, location-tagged, bio, multi-area; consolidate to ONE vendor panel (retire legacy PHP vs HTML split) | `vendor/` (pick HTML dashboards), `media` table | 1.4 |
| **2.6 — Admin engines completion** | Build Company Projects (Engine 5, net-new); **Payment Room/Revenue Control** (per-category P&L, in-to-out, cash-vs-online, vendor revenue view); Area/Category **controls** (not read-only); Vendor scorecard leaderboard + bypass flags; fix Morning Brief dead cards; **per-city selector filtering every admin list**; per-area×cat config UI | `admin/*.html`, `heart/api/admin/index.php` | 1.x complete |
| **2.7 — `home.js` de-monolith** | Split the 3647-line monolith into per-flow modules (maintainability + prevents Phase-2 regressions) | `app/pages/home.js` | parallel-safe |
| **2.8 — Bypass prevention** | Hide customer contact until job started; per-deal outcome ledger; **auto-flag + auto-throttle** bad vendors | service-engine, `alerts`, vendor scoring | 0.2, 1.7 |

**Exit criteria:** a customer can enter a Company View, pick a package or request an expert visit, pay (or free), be tracked on the right track, and be served by a vendor who accepted through the system; admin sees full revenue in-to-out; a new city can be added and filtered from one screen.

### LATER PHASES (noted, not detailed here)

- **Phase 3 — Shopping activation.** Un-hide `SERVICE_ONLY_MODE`; the engine is **substantial** (Audit 08 — production-grade `OrderService v3.0`), so this is mostly *integration + money wiring*, not a rebuild: wire `processOrderCompletion` commission, unlock `online` payment (post-B1), apply `city_id` to orders, build the admin Orders room. **Gated on 1.5 (wallet fix) being done.**
- **Phase 4 — Video.** Build from the honest scaffold *natively* on the V2 foundation: create `video_content`, contract a video CDN, creator verification flow, feed integration. Highest external cost (CDN + transcoding).
- **Phase 5 — Brain / Unified Feed.** Fix `buildUserFeed`/`getTrendingItems` against the now-real `city`/counter columns (Audit 08 headline), replace `// dummy` signals with real ones, reconcile `client_id` vs `city_id`, add the admin Brain surface and the one-feed customer screen.

---

## 3. Effort + Time Estimate

**Unit:** a "session" = one focused Claude-Code working block (~half a day of build + review). Day/week figures assume a solo founder reviewing between sessions. Ranges reflect honest uncertainty; the two biggest wildcards are called out.

| Phase | Chunks | Sessions | Calendar (solo) | Confidence |
|---|---|---|---|---|
| **Phase 0** — foundation fixes | 0.1–0.3 | **2–4** | 3–5 days | Medium — **B1 is externally gated** (Cashfree could be a 30-min cred fix or a multi-day support ticket) |
| **Phase 1** — data-model + money-safety | 1.1–1.8 | **8–11** | 2–3 weeks | Medium-High — well-understood, but touches every table |
| **Phase 2** — service engine V2 | 2.1–2.8 | **15–22** | 4–6 weeks | Medium — new surfaces; **1.4/CDN decision** gates 2.5 |
| **Total to launch-ready V2 service engine** | Phases 0–2 | **≈ 25–37 sessions** | **≈ 8–12 weeks** | — |

**Later phases (rough, for roadmap only):** Shopping activation ≈ 1–2 weeks (reuse-heavy); Video ≈ 3–4 weeks + CDN contract lead time; Brain/Feed ≈ 2–3 weeks. **Full three-engine + feed platform ≈ 4–6 months** beyond the service-engine launch.

**Two wildcards that can move the total:**
1. **B1 / Cashfree prod** — if it needs Cashfree merchant-support back-and-forth, Phase 0 can stretch by days independent of build effort.
2. **Media CDN choice (1.4)** — provider selection + account/keys is a founder decision with its own lead time; it blocks 2.5 (vendor upload). Decide early.

---

## 4. Launch Checklist (V1 → V2 clean cutover — "nothing forgotten")

### A. Database migrations (run in order, on a backup first)
- [ ] **Full prod DB backup** taken and restore-tested (from `u904615152_worktogo_main`).
- [ ] `cities` table created; `city_id` added + backfilled Haldwani on `users`, `vendors`, `services`, `products`, `orders`, `bookings`, `deliveries` (1.1).
- [ ] `bookings.city_id` populated from previously-dropped `selected_city` (1.1).
- [ ] `vendor_categories` set as canonical; data reconciled from `vendors.category_id` + `services.category_id` (1.2); `vendor_engines` junction (1.3).
- [ ] `media` table created; existing `products.images`/`stories.media_url`/`logo_url` migrated (1.4).
- [ ] `wallet_transactions.reference_type` added + backfilled (1.5) — **before any shopping activation.**
- [ ] Duplicate columns collapsed; `notifications.user_id`→bigint + FK (1.8).
- [ ] `packages` + expert-visit config (city×area×category free/paid/price) tables (2.2/2.3).
- [ ] `booking` state columns rationalized (1.7).
- [ ] **`migrations_log` verified** — every migration recorded; dry-run on a copy of the dump produces zero errors.

### B. Credentials & external services (verify each is PROD, not sandbox/placeholder)
- [ ] **Cashfree PRODUCTION** — `CASHFREE_APP_ID`, `CASHFREE_SECRET_KEY`, `CASHFREE_ENV=PRODUCTION`, API base `api.cashfree.com/pg`; **guard rejects wrong-but-real keys**, not just literal `placeholder`.
- [ ] **Cashfree webhook + return URLs** re-pointed to the V2 domain (`/api/payment/webhook`, `return_url`); ₹1 live round-trip verified.
- [ ] **MSG91** — OTP widget token + SMS fallback creds live; `WTG_OTP_METHOD` set.
- [ ] **Google Sign-In** — `WTG_GOOGLE_CLIENT_ID` valid for the V2 origin.
- [ ] **FCM** — server key + `firebase-sw.js` config; test push delivered.
- [ ] **Media storage / CDN** — bucket + keys; **upload directory writable / CDN reachable**; a real photo AND video upload verified end-to-end (this is the founder's "upload errors" worry — test it explicitly).
- [ ] **SwiftDeliver** (only if Delivery in scope) — `SWIFTDELIVER_URL` + `SWIFTDELIVER_SECRET`.

### C. Config
- [ ] Per-city config store live (enabled categories, expert-visit-required categories, price, hero/support copy, WhatsApp) — **not** the frozen `app/js/config.js` constant or single `pilot_public_config` blob (Audit 07).
- [ ] `SERVICE_ONLY_MODE` set correctly for launch (service engine only; shopping/video stay hidden until their phases).
- [ ] Brain `client_id` moved off hardcoded `'haldwani'` (or consciously deferred with Brain to Phase 5).
- [ ] SEO/PWA (`app/index.php`, `manifest.json`) de-hardcoded or set for launch city.

### D. Cutover sequence
1. [ ] Freeze V1 writes (or accept a short read-only window).
2. [ ] Backup prod DB (again, immediately pre-cutover).
3. [ ] Merge `v2` → `main` (or deploy `v2` build artifact) to Hostinger.
4. [ ] Run migration set against prod DB; verify `migrations_log`.
5. [ ] Point domain / document root to V2; clear caches.
6. [ ] **Smoke test each flow live:** login (all 4 methods) → Company View → package request → expert-visit pay (₹1) → admin assign → vendor accept → complete → settlement; upload a vendor photo+video; add a test city and confirm the admin city-filter.
7. [ ] Confirm webhook fires and marks paid; confirm no `phone` 500s on a repeat login.
8. [ ] **Rollback plan ready:** keep V1 artifact + pre-cutover DB backup; documented one-command revert.

---

## 5. Recommendation — Upgrade-in-place vs Fresh scaffold

**Recommendation: a "clean upgrade on a fresh V2 branch" — reuse the substantial engines, rebuild only the thin/broken surfaces. Do NOT greenfield-rewrite; do NOT patch live in place.**

The audits make this clear-cut: too much is genuinely good to throw away, and too much is broken to patch on the live branch.

**Reuse as-is (proven / substantial — rewriting these would destroy real value):**
- **Auth stack** — the strongest layer; OTP-widget + SMS + Google + email all work (Audit 01 §1).
- **Shopping `OrderService v3.0`** — production-grade (multi-vendor split, `FOR UPDATE` locking, rate-limit) (Audit 08).
- **Cashfree gateway half** — signed client, signature-verified idempotent webhook, amount checks (Audit 05 §6).
- **`LedgerEngine::processBookingCompletion`** — genuinely wired, commission correct (Audit 05 §3).
- **Admin dashboard / live pipeline / category control room / follow-up / auto-assign cron** — real and capable (Audit 04).
- **Brain scaffold + `FeedRankingEngine`/`buildUserFeed`** — the unified feed is pre-written (Audit 08).
- **Delivery shim + engine registry** — the microservice seam already exists (Audit 08 §0).

**Rebuild / replace (thin, broken, or confusing — cheaper to redo than to untangle):**
- **Vendor panel** — two competing generations; pick the richer HTML dashboards, retire legacy PHP (Audit 01 §3).
- **`home.js` 3647-line monolith** — split into modules (Audit 06 L3).
- **3-way vendor↔category storage** and the **6-column booking state sprawl** — collapse to one each (Audit 02 §4a, §3).
- **Inspection flow → Expert Visit**, and **net-new**: Company View, Packages, Payment Room, per-city foundation.

**Why the fresh `v2` branch (not in-place on live):** the foundation fixes and data-model migrations are invasive (every table gets `city_id`, vendor-category is re-canonicalized, wallet columns change). Doing that on the branch Hostinger serves risks the live pilot. The worktree strategy (§1) gives isolation **and** keeps one history, so if the founder wants to unblock live revenue early, Phase-0 fixes (B1/B2/B3) can be cherry-picked back to `main` independently of the full V2 launch.

**One-line verdict:** *V2 is an upgrade, not a rewrite — assembled cleanly on a fresh `v2` branch: keep the engines that are substantial, rebuild the surfaces that are broken, lay the city/media/wallet foundation once, and cut over as a single package.*

---

*Planning document only. No feature code was written; no live files were modified; the never-touch engines were read for planning and left untouched. Every reuse/rebuild call is traceable to a cited audit finding.*
