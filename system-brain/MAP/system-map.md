# WorkToGo — System Map & Folder Blueprint (the "House Blueprint")

> **What this is:** One document that answers two questions any founder or new engineer will ask —
> **(1)** "What is every folder on my desktop, and which ones are still real?"
> **(2)** "When something breaks, where exactly do I go to fix it — without opening the whole system?"
>
> **How to read it:** Section A is the desktop cleanup (what to keep/archive/delete). Section B is the house blueprint of the *real* system (`wtg-v2`). Section C is the **"where do I fix X?"** quick table — start there in an emergency. Sections D–F are for growth (adding a new business), naming, and finding the docs.
>
> **Status:** READ-ONLY audit. Nothing was moved, renamed, or deleted. Every folder rename or cleanup below is a **recommendation for the founder to approve** — not an action taken. Grounded in the real files as they exist on 2026-09-02.
> **The real system is `wtg-v2/` (git branch `v2`).** Everything else on the desktop is docs, old versions, or discarded experiments.

---

## A. WORKSPACE INVENTORY — what every folder on the desktop is

The desktop folder `mastar wtg live/` is **not** a code project itself — it is a container holding several things that grew over many sessions. Here is every top-level item, what it is, and what to do with it.

| # | Item | What it actually is | Current or obsolete? | Recommendation |
|---|------|---------------------|----------------------|----------------|
| 1 | **`wtg-v2/`** | **THE REAL SYSTEM.** The V2 build — the app you are actually launching. Git branch `v2`, ~1 build's worth of real code. | ✅ **CURRENT — this is the product** | **KEEP.** This is the house. Everything in Section B maps this folder. |
| 2 | **`WTG-SYSTEM-BRAIN/`** | All the documentation — audits, V2 plans, commerce plans, agent plans, risk analyses (~149 docs). The "brain" that remembers every decision. | ✅ **CURRENT — the doc library** | **KEEP.** This is where *this* map now lives (`MAP/system-map.md`). See Section F for its index. |
| 3 | **`wtg  live/`** (note the double space) | **OLD V1.** The original live system that currently runs on Hostinger. Same folder shape as V2 (admin/api/app/body/brain/…). Git branch `main`. 392 files. | 🟡 **OBSOLETE once V2 launches** — but still the *live production site today* | **KEEP until V2 cutover, then ARCHIVE.** Do not delete yet — it is what customers use right now and the rollback safety net. After V2 goes live and is stable, archive it (zip + move off the desktop). Never build here. |
| 4 | **`swiftdeliver-main/`** | A **discarded** standalone delivery app (TypeScript/Vite/Railway — a totally different tech stack: `server.ts`, `nginx.conf`, `railway.toml`). Was evaluated as the delivery solution and dropped. 27 files. | ⛔ **OBSOLETE — dead prototype** | **ARCHIVE or DELETE.** Delivery is being built *inside* V2 (see Section B), not as this separate app. Recommend: zip it, move off desktop. Nothing in V2 depends on it. |
| 5 | **`WTG-HARDENING/`** | An **older planning archive** — 118 markdown docs from the "service activation" hardening push on V1 (service-only-mode plan, customer/admin flow tests, UX cleanup plans). Pre-dates the V2 build. | 🟡 **OBSOLETE as a plan** — historical value only | **ARCHIVE into the doc library.** These are superseded by the `WTG-SYSTEM-BRAIN/V2/` plans. Recommend moving the whole folder to `WTG-SYSTEM-BRAIN/_archive/HARDENING-V1/` so all docs live in one place. |
| 6 | **`u904615152_worktogo_main (4).sql`** (146 KB) | A **database dump** (backup export) of the live production database, taken 2026-06-27. | 🟡 **Stale backup** (a snapshot, ages by the day) | **ARCHIVE — do not leave loose on the desktop.** It contains real customer data; a loose SQL file is a privacy risk. Move to a secure backups location (`WTG-SYSTEM-BRAIN/_archive/db-dumps/` or, better, off-machine). Keep it — it's the reference for the V2 migration dry-run — but store it deliberately. |
| 7 | **`.claude/`** | Claude Code's own working files (memory, session data). Not part of your product. | ✅ Tooling | **KEEP, ignore.** Housekeeping folder for the AI assistant. |

### A.1 The one confusing thing to fix first

**Two folders look almost identical: `wtg  live/` and `wtg-v2/`.** They have the *same internal shape* (both have admin/, api/, app/, body/, brain/, core/, heart/, lib/…). This is by design — V2 was branched from V1 — but it is the single biggest source of "which folder am I in?" confusion.

- **Rule of thumb:** `wtg-v2` = the new house you're building. `wtg  live` = the old house people still live in. **All new work happens in `wtg-v2`.**
- The double-space in `wtg  live` is easy to mistype. Once V1 is archived, that ambiguity disappears.

### A.2 Recommended clean final desktop structure (RECOMMEND ONLY — nothing moved)

```
mastar wtg live/
├─ wtg-v2/                    ← THE SYSTEM (rename candidate: "worktogo" — see Section E)
├─ WTG-SYSTEM-BRAIN/          ← ALL docs (the map, audits, plans)
│  ├─ MAP/                    ← this blueprint + future maps
│  ├─ V2/  COMMERCE/  AGENT/  ← the canonical build plans
│  └─ _archive/               ← NEW: retire old stuff into here
│     ├─ HARDENING-V1/        ← was WTG-HARDENING/
│     ├─ swiftdeliver/        ← was swiftdeliver-main/ (or delete)
│     └─ db-dumps/            ← the .sql backup (secured)
└─ _archive-v1/               ← was "wtg  live/", moved here AFTER V2 cutover
```

Net effect: **two live things on the desktop (the system + the docs), everything else tucked into `_archive`.** A newcomer sees exactly two folders that matter.

---

## B. THE SYSTEM MAP — the house blueprint of `wtg-v2`

WorkToGo is built on a **"body" metaphor**, and it's worth learning because the folder names *are* the architecture:

> **Heart** = the front door + nervous system (every request enters here).
> **Brain** = the thinking/personalization layer (optional, can be switched off).
> **Body** = the engines (the actual businesses: service, shopping, video…).
> **Core + Lib** = the shared foundation every part stands on (identity, payment, media, permissions).

Think of it as: **a request comes in the front door (Heart), the Heart decides which room (engine) handles it, the engine does the work using shared utilities (Core/Lib), and an answer goes back out the same door.**

### B.1 The spine — how one request flows (the "nervous system")

This is the single most important thing to understand. **Every action in the app is one POST to the Heart carrying an `intent`** (a word like `service:create_booking` or `shopping:add_to_cart`).

```
  Customer app / Vendor app / Admin panel
                │  (POST { intent, data, user, location })
                ▼
  ┌─────────────────────────────────────────────┐
  │  HEART  (wtg-v2/heart/)                       │  ← the front door + traffic controller
  │  index.php → router.php pipeline:             │
  │   1. Rate-limit + validate                    │
  │   2. ContextBuilder  (who/where/what)         │
  │   3. ReflexEngine    (fast cache — early out) │
  │   4. BrainConnector  (optional thinking)      │
  │   5. EngineLoader    (calls the right engine) │
  │   6. PriorityResolver → ResponseBuilder       │
  └─────────────────────────────────────────────┘
                │  AppRouter maps intent → engine(s)
                ▼
  ┌───────────┬────────────┬───────────┬─────────┐
  │  SERVICE  │  SHOPPING   │  DELIVERY │  VIDEO  │  ← BODY engines (the businesses)
  │  engine   │  engine     │  engine   │  engine │
  └───────────┴────────────┴───────────┴─────────┘
                │  every engine stands on…
                ▼
  ┌─────────────────────────────────────────────┐
  │  CORE + LIB  (the shared foundation)          │  ← identity, city, media, RBAC,
  │  JWT · CityResolver · Media · Permission ·    │     payment, ledger, auth channels
  │  PaymentService · LedgerEngine · lib/auth ·   │
  │  lib/payment                                  │
  └─────────────────────────────────────────────┘
                │
                ▼
              Database (MySQL/MariaDB)
```

**Two things make this powerful:**
1. **Engines are pluggable.** The Heart doesn't know *how* an engine works — it just calls it. Engines are registered in one file (`config/engines.php`) and can be a **local folder** *or* a **remote URL** (an external microservice). Swapping one for the other is a config change, no code rewrite. This is the foundation of the founder's "add a new business" vision (Section D).
2. **The Brain and the AI Agent sit *in front of* this and are optional.** If the Brain or the AI is removed, the Heart "continues normally with safe defaults" — the app degrades to buttons, it does not break.

### B.2 The motherboard — the shared foundation (what EVERY engine plugs into)

These live in `core/`, `lib/`, and `config/`. They are the "motherboard": build a new engine and you wire into *these*, you don't rebuild them.

| Shared piece | What it does | Where it lives (key files) |
|---|---|---|
| **Identity / Auth** | One login for the whole platform (customers + vendors). OTP (SMS/WhatsApp), Google, email. JWT tokens. | `heart/api/auth/` (endpoints), `lib/auth/` (OTP channels + failover: `OtpChannelManager.php`, `Msg91SmsChannel.php`, `WhatsAppOtpChannel.php`, `LoginMethodResolver.php`), `core/helpers/JWT.php`, `heart/middleware/AuthMiddleware.php` |
| **City / Location** | Multi-city foundation. Resolves which city a request belongs to; scopes data by `city_id`. | `core/helpers/CityResolver.php`, `core/helpers/GeoHelper.php`, migration `2026_07_04_001_cities_and_city_id_foundation.sql` |
| **Media** | One shared photo/video store for vendor profiles, products, stories. One table, one upload path. | `core/helpers/Media.php`, `core/helpers/UploadHelper.php`, migration `2026_07_04_003_create_media_table.sql` |
| **RBAC / Permissions** | Who is allowed to do what (admin roles, staff permissions). | `core/helpers/Permission.php`, `admin/roles.html`, migration `2026_08_13_001_rbac_foundation.sql` |
| **Payment layer** | Taking money, verifying it, refunds — provider-independent (Cashfree today, Razorpay-ready). | `lib/payment/` (the abstraction: `PaymentGateway.php`, `PaymentGatewayManager.php`, `CashfreeAdapter.php`, `RazorpayAdapter.php`), `lib/PaymentService.php` (orchestrator), `lib/CashfreeClient.php`, `lib/WebhookVerifier.php`, `config/payment.gateways.php`. **Webhook entry:** `api/payment/webhook.php` |
| **Money ledger** | The in-to-out accounting: commission, vendor payout, settlement. | `core/helpers/LedgerEngine.php`, `core/helpers/PaymentReport.php`, `core/controllers/RefundController.php` |
| **Response / Validation / Logging** | The plumbing every endpoint uses to answer, validate input, and record what happened. | `core/helpers/Response.php`, `core/helpers/Validator.php`, `core/helpers/Logger.php`, `core/helpers/RateLimiter.php`, `core/helpers/Database.php` |
| **Engine registry** | The one list that says which engines exist and where to reach them. | `config/engines.php` |

> **Why this matters to the founder:** these boxes are the expensive, already-built parts. A new business (a new engine) **reuses all of them**. That is the whole "environment" idea — you built the motherboard once.

### B.3 The engines — the actual businesses (the "rooms of the house")

| Engine | What it does | Where it lives | Status today |
|---|---|---|---|
| **Service engine** | The launch product: book a service (electrician, plumber, painter…), expert visits, packages, vendor jobs. | `body/service-engine/` (`api/services/`, `api/vendors/`, `SlotController.php`, `VendorAnalyticsController.php`) | ✅ **Primary focus** — being built company-grade for V2 |
| **Shopping engine** | E-commerce: products, cart, orders, multi-vendor split. Production-grade `OrderService`. | `body/shopping-engine/` (`api/cart/`, `api/orders/`, `api/products/`) | 🟡 **Built but dormant** (hidden behind `SERVICE_ONLY_MODE`); **READ-ONLY / never-touch** |
| **Delivery engine** | Rider assignment, delivery lifecycle, dispatch. **Note: not under `body/`** — it lives in core + heart + cron. | `core/delivery/DeliveryEngine.php`, `core/helpers/DeliveryPricingEngine.php`, `heart/api/delivery/`, `heart/api/rider/`, `api/assignments/accept.php`, `cron/delivery_lifecycle.php`, `rider/` (rider UI) | 🟡 Partial — engine + lifecycle exist; registered as empty URL in `engines.php` |
| **Video engine** | Short-video feed (Instagram/Reels style) for vendor work. | `body/video-engine/` (`VideoController.php`, `video_feed_api.php`) | ⛔ **Scaffold only / dormant**; routes commented out in `heart/router.php`; **READ-ONLY** |
| **Content engine** | Stories / content feed (expiring stories). | `body/content-engine/` (`StoryController.php`), `heart/cron/expire_stories.php` | 🟡 Minimal — one controller |
| **AI Agent** ("dost") | The natural-language front door — understands text/voice, turns it into intents, does things across engines. The AI is a *translator only*; all data/decisions stay inside WorkToGo. | `core/agent/` (`AgentOrchestrator.php`, `ToolPlanner.php`, `PiiWall.php`, `ai/` adapters for Anthropic/OpenAI/Gemini, `tools/` for each engine), `app/agent/` (chat UI), `heart/api/agent/` | 🟢 **Newest layer** — foundation built (see `AGENT/` docs) |
| **Brain** | Personalization / decisions / ranking / alerts — the "thinking" layer. Optional & circuit-broken. | `brain/` (`BrainCore.php`, `FeedRankingEngine.php`, `engine/RuleEngine.php`, `engine/SuggestionEngine.php`, `Controllers/`, `models/`), connected via `heart/pipeline/BrainConnector.php` | 🟡 Scaffold + feed ranking; leans on fallbacks |

### B.4 The front-ends — the screens people actually see

| Surface | Who uses it | Where it lives | Key files |
|---|---|---|---|
| **Customer app** | Customers | `app/` | `pages/home.js` (the big booking flow), `pages/bookings.js` (tracking), `pages/account.js`, `pages/login.js`, `pages/orders.js` (commerce — never-touch), `js/` (api, auth, router, ui, map, push), `commerce/` (shop) |
| **Vendor panel** | Vendors | `vendor/` | `dashboard-service.html`, `dashboard-shopping.html`, `bookings.php`, `orders.php`, `products.php`, `profile.php`, `onboarding.html` |
| **Admin control room** | You / ops staff | `admin/` | ~25 pages: `dashboard.html`, `orders.html`, `payments.html`, `refunds.html`, `vendors.html`, `delivery-control.html`, `cities.html`, `roles.html`, `agent.html`, `commerce-*`, `pricing.html`, `followup.html`, `morning.html`, `settings.html`, `shared/` (shell, auth, api) |
| **Rider app** | Delivery riders | `rider/` | `index.html`, `rider.js` |

### B.5 Support directories

| Folder | Role |
|---|---|
| `config/` | All backend config (app, database, payment, auth channels, AI providers, engines registry). |
| `migrations/` | Every database change, dated & ordered — the launch spine. Run in filename order by `migrate.php`, tracked in `migrations_log`. |
| `cron/` | Scheduled jobs: `auto_assign.php` (assign vendors), `delivery_lifecycle.php`, `agent_reminders.php`, `agent_demand_detect.php`. |
| `api/` (root) | Direct edges that bypass the intent pipeline: `payment/` (Cashfree webhook/return — gateways POST here directly), `alerts/`, `assignments/`. |
| `uploads/` | Where uploaded files land (vendor media). |
| `docs/` | A few in-repo technical notes (`alerts.md`, `agent-engine-idor.md`). |
| `plans/` | In-repo planning docs. |

---

## C. "WHERE DO I FIX X?" — the emergency quick-reference

**Start here when something breaks.** Find the symptom, go to the folder. (All paths are inside `wtg-v2/`.)

| If the problem is… | Go straight to… | The specific file(s) |
|---|---|---|
| 💳 **Payment not completing / Cashfree error** | `lib/payment/` + `lib/PaymentService.php` | `lib/PaymentService.php`, `lib/CashfreeClient.php`, `config/payment.gateways.php`; service ₹299 order is created **inline** in `body/service-engine/api/services/index.php` |
| 💳 **Payment "paid" but not recorded / webhook** | `api/payment/webhook.php` | → `lib/PaymentService.php::processWebhook`, `lib/WebhookVerifier.php`. **The webhook is the sole source of truth** (return/status pages never self-authorize) |
| 💰 **Commission / payout / settlement wrong** | `core/helpers/LedgerEngine.php` | + `core/helpers/PaymentReport.php`, admin `payments.html` |
| 💸 **Refund** | `core/controllers/RefundController.php` | uses `core/helpers/Payment.php::initiateRefund` |
| 🔑 **OTP not arriving / login fails** | `lib/auth/` | `OtpChannelManager.php` (channel failover), `Msg91SmsChannel.php`, `WhatsAppOtpChannel.php`; endpoints in `heart/api/auth/` (`send-otp.php`, `verify-otp.php`, `AuthController.php`) |
| 🔑 **Token invalid / "not authorized"** | `core/helpers/JWT.php` + `heart/middleware/AuthMiddleware.php` | |
| 👤 **Admin/staff can't access a screen (permissions)** | `core/helpers/Permission.php` | + `admin/roles.html`, RBAC migration |
| 📅 **Booking not created / booking flow bug** | `body/service-engine/api/services/index.php` | customer side: `app/pages/home.js` |
| 📅 **Booking tracking / status wrong** | `app/pages/bookings.js` | server: `body/service-engine/` status handlers |
| 🧑‍🔧 **Vendor assigned but can't accept the job** | assignment write path | `heart/api/admin/index.php` (assign), `api/assignments/accept.php`, `cron/auto_assign.php` |
| 🗺️ **Wrong city / location / area matching** | `core/helpers/CityResolver.php` | + `core/helpers/GeoHelper.php`, `core/helpers/ServiceVendorEligibility.php` |
| 📸 **Photo/video upload broken** | `core/helpers/UploadHelper.php` + `core/helpers/Media.php` | files land in `uploads/` |
| 🛒 **Product / cart / order (commerce)** | `body/shopping-engine/` **(READ-ONLY)** | `api/cart/`, `api/orders/OrderService.php`, `api/products/`. Do not edit — use adapters |
| 🚚 **Delivery / rider** | `core/delivery/DeliveryEngine.php` | + `heart/api/delivery/`, `heart/api/rider/`, `cron/delivery_lifecycle.php` |
| 🤖 **AI assistant says something wrong / leaks data** | `core/agent/PiiWall.php` (the privacy wall) | + `core/agent/AgentOrchestrator.php`, `core/agent/ai/` (provider adapters), `core/agent/tools/` |
| 🧠 **Recommendations / feed ranking / brain** | `brain/FeedRankingEngine.php` | + `brain/engine/`, connected via `heart/pipeline/BrainConnector.php` |
| 🚦 **A whole intent returns "unknown intent" / routing** | `heart/router.php` | the `AppRouter::$routes` table (intent → engine map) |
| ➕ **Adding/wiring a new engine** | `config/engines.php` + `heart/router.php` | see Section D |
| 🔔 **Alerts / notifications** | `api/alerts/` + `core/lib/FcmNotifier.php` | `heart/api/auth/notifications.php` |
| 🗄️ **Database column / schema change** | `migrations/` | add a dated file, run via `migrate.php` |
| ⚙️ **A setting / config value** | `config/` | app-level settings also in DB `app_settings` table |

> **The one-line rule:** *money* → `lib/payment` + `core/helpers/LedgerEngine`; *login/OTP* → `lib/auth` + `heart/api/auth`; *booking* → `body/service-engine`; *AI safety* → `core/agent/PiiWall`; *routing* → `heart/router.php`; *shared identity/city/media/permissions* → `core/`.

---

## D. ENGINE PLUG POINTS — how a NEW business plugs into the motherboard

This is the founder's "environment" vision made concrete. To add a **new engine** (say a "tuition" engine, or "car rental"), you do **not** rebuild identity, payment, city, or media — you **wire into the existing motherboard** at these exact points:

**The 6 wires to connect a new engine:**

1. **Register the engine** — add one line to `config/engines.php`:
   ```php
   'tuition' => getenv('ENGINE_TUITION_URL') ?: __DIR__ . '/../body/tuition-engine/index.php',
   ```
   (A local folder, or an external URL for a microservice — your choice, same wire.)

2. **Add its intents to the router** — in `heart/router.php`, add rows to `AppRouter::$routes`:
   ```php
   'tuition:book_class' => ['engines' => ['tuition'], 'brain' => true, 'cache_ttl' => 0],
   ```
   Now `POST { intent: "tuition:book_class" }` reaches your engine. Nothing else in the Heart changes.

3. **Build the engine folder** — `body/tuition-engine/index.php` reads the Heart's JSON payload and returns JSON. Mirror the shape of `body/service-engine/`.

4. **Reuse identity & permissions** — the user already arrives authenticated (JWT via `AuthMiddleware`). Use `core/helpers/JWT.php` and `core/helpers/Permission.php`. **Do not build a new login.**

5. **Reuse the shared foundation** —
   - Money? → call `lib/PaymentService.php` (never talk to Cashfree directly).
   - City scoping? → `core/helpers/CityResolver.php`, put `city_id` on your tables.
   - Photos/video? → `core/helpers/Media.php` + the one `media` table.
   - Accounting? → `core/helpers/LedgerEngine.php`.

6. **Add a database migration** — dated file in `migrations/`, with `city_id` on every domain table from day one.

**That's it.** A new business = one registry line + a few route rows + one engine folder that reuses the motherboard. The customer app, vendor identity, payment, refunds, city logic, media, and admin auth all come for free.

> **Non-negotiable rules that keep this working** (from the V2 blueprint):
> (1) **One shared identity** — never a per-engine user table.
> (2) **Every engine goes through the `{intent, data}` Heart pipeline** — no engine that reaches around the gateway.
> (3) **`city_id` on every domain table** from day one.
> (4) **One `media` table + one payment layer** — shared, never re-implemented per engine.

---

## E. NAMING — clearer names where current ones confuse (RECOMMEND ONLY)

The body metaphor (heart/brain/body) is **good and memorable — keep it.** These are the spots where names actively cause confusion:

| Current name | Why it's confusing | Suggested name | Note |
|---|---|---|---|
| `wtg  live/` (double space) | Looks like the live system but is the *old* V1; the double-space is a typo trap | `_archive-v1/` (after cutover) | Rename only **after** V2 launches; it's live today |
| `wtg-v2/` | "v2" ages badly — in a year it's just "the system" | `worktogo/` | Optional; do at cutover. Until then `wtg-v2` clearly signals "the new build" |
| `core/` **vs** `lib/` | Two "shared foundation" folders with an unclear split (payment sits in *both* `lib/` and `core/helpers/Payment.php`) | Keep both, but **document the rule**: `lib/` = self-contained service libraries (payment, auth channels), `core/` = platform helpers used everywhere. **Consolidate the duplicate payment helper** (`core/helpers/Payment.php` overlaps `lib/PaymentService.php`) | This is a real duplication flagged in the audits — pick one owner |
| `body/` | Cute but a newcomer won't guess "engines" | Add a one-line README in `body/` saying "each subfolder is a business engine". Keep the name. | Low effort, high clarity |
| `heart/` `brain/` | Actually fine once you know the metaphor | Keep. Add the metaphor legend (Section B intro) to the repo README | |
| Delivery lives in `core/` + `heart/`, not `body/` | Breaks the "engines live in body/" expectation | When delivery is finished, consider moving it to `body/delivery-engine/` for consistency | Structural, do later |
| `WTG-HARDENING/`, `swiftdeliver-main/` | Loose top-level folders that look active | Move under `WTG-SYSTEM-BRAIN/_archive/` (Section A.2) | |

> **Guiding principle:** the metaphor is an asset — a non-technical founder *remembers* "the heart is the front door." Don't rename the anatomy; just archive the dead folders, kill the double-space, and write down the `core` vs `lib` rule.

---

## F. DOC INDEX — a table of contents for `WTG-SYSTEM-BRAIN/`

~149 documents. They fall into four canonical folders (read these first) plus a large flat set of analysis/audit files. Here's how to find anything.

### F.1 The canonical build plans (start here)

| Folder | Read these first |
|---|---|
| **`V2/`** — the V2 vision & build | `V2-VISION.md` (what to build), `V2-BUILD-BLUEPRINT.md` (how/phases/effort), `V2-GAP-MAP.md`, `V2-READINESS.md`, `V2-SECURITY-AUDIT.md`, `V2-PAYMENT-ABSTRACTION.md`, `V2-AUTH-ABSTRACTION.md` |
| **`AGENT/`** — the AI assistant | `agent-01-blueprint.md` → `agent-11-readiness-audit.md` (foundation, actions, chat UI, memory, commerce tools, vendor agent, control room, connections, proactive), `agent-status-report.md` |
| **`COMMERCE/`** — the shopping engine | `commerce-audit-01-inventory.md`, `commerce-02-gap-map` → `commerce-10-security-fixes` (data model, order alignment, admin, storefront, delivery, settlement) |
| **`MAP/`** — this blueprint | `system-map.md` (you are here) |

### F.2 The audits (the "what exists today" ground truth)

`audit-01-full-inventory.md`, `audit-02-database.md`, `audit-03-flows.md`, `audit-04-admin-engines.md`, `audit-05-payments.md`, `audit-06-bug-register.md`, `audit-07-multi-city-gaps.md`, `audit-08-dormant-engines.md`. Plus top-level masters: `master-system-brain.md`, `WTG_SYSTEM_BRAIN.md`, `architecture-overview.md`, `architecture-dna.md`, `folder-intelligence.md`, `WTG_BUSINESS_READINESS_AUDIT.md`, `final-production-readiness-score.md`.

### F.3 The ~120 analysis docs, grouped so they're findable

The remaining root-level docs are deep-dives. They cluster by theme — find your topic, then the file:

- **Auth & identity:** `auth-system-map`, `auth-api-map`, `auth-runtime-flow`, `auth-db-relations`, `auth-dependency-map`, `auth-security-risks`, `session-token-analysis`
- **Payments & money:** `checkout-lifecycle`, `commerce-09-settlement-lifecycle`, `webhook-runtime-analysis`, `provider-assignment-analysis`
- **Service & booking:** `booking-lifecycle-map`, `service-engine-map`, `service-api-map`, `service-runtime-flow`, `service-dependency-map`, `dispatch-lifecycle-map`, `provider-assignment-analysis`
- **Delivery:** `delivery-system-map`, `delivery-api-map`, `delivery-runtime-flow`, `delivery-state-analysis`, `delivery-operational-risks`, `delivery-mobile-analysis`
- **Commerce/fulfillment:** `commerce-fulfillment-map`, `commerce-dependency-map`, `commerce-risk-analysis`
- **Customer & admin surfaces:** `customer-flow-map`, `customer-runtime-flow`, `customer-state-map`, `customer-api-map`, `admin-api-map`, `admin-operations-map`, `admin-permission-map`, `admin-risk-analysis`
- **Database & schema:** `database-schema-map`, `table-relationship-map`, `db-dependency-map`, `schema-drift-analysis`, `schema-contract-analysis`, `cross-module-db-coupling`, `data-integrity-risks`
- **Multi-city:** `audit-07-multi-city-gaps`, `multi-city-scaling-risks`, `location-logic-analysis`
- **Runtime & risk:** the `runtime-*`, `operational-*`, `scalability-*`, `*-risk*`, `production-*`, `phase-10/11` families — architecture stress-tests and failure scenarios.
- **RBAC:** `role-permission-map`, `security-boundary-map`, `attack-surface-analysis`
- **Growth:** `future-expansion-guide`, `scaling-evolution-roadmap`, `engineering-operating-manual`

> **Recommendation:** the flat root of `WTG-SYSTEM-BRAIN/` (~130 loose files) is itself hard to scan. Consider grouping the analysis docs into subfolders (`AUDITS/`, `AUTH/`, `PAYMENTS/`, `DELIVERY/`, `RUNTIME-RISKS/`, `DATABASE/`) the way `V2/`, `COMMERCE/`, `AGENT/` already are. Recommend only — no files moved.

---

## Appendix — verified facts this map is grounded in

- The real system is **`wtg-v2/`**, git branch **`v2`**, remote `github.com/akk611745-wq/worktogo.git`. Working tree clean at audit time.
- Entry point: root `index.php` → `heart/index.php` → `heart/router.php`. **Every request is a POST with an `intent`.**
- Engine registry `config/engines.php` maps `shopping`/`service`/`delivery`/`video` to a local folder **or** an env URL — the pluggable seam.
- Payment: `lib/payment/` abstraction (Cashfree live, Razorpay stub) + `lib/PaymentService.php` orchestrator; webhook at `api/payment/webhook.php` is the sole payment authority. A second, duplicate `core/helpers/Payment.php` exists (used only by refunds) — flagged for consolidation.
- Never-touch (READ-only) per project rules: `body/shopping-engine`, `body/video-engine`, `app/pages/orders.js`; treat `body/service-engine` as an engine (edit carefully).
- **This audit moved/renamed/deleted nothing.** All of Section A.2, E, and F's grouping suggestions are recommendations awaiting founder approval.

*End of system map. Companion to the `V2/`, `AGENT/`, and `COMMERCE/` plans in `WTG-SYSTEM-BRAIN/`.*
