# WorkToGo V2 — Gap Map (Vision ↔ Audited Reality)

> **Purpose:** Connect every V2 vision capability to the current audited reality, so for each feature it is crystal clear: what exists, what's broken, what's missing, what can be reused, what must be built new, and what external dependencies it needs. This is the **planning base** from which V2 build tasks are later derived.
> **Inputs:** `V2/V2-VISION.md` (target) cross-mapped against `audit-01` … `audit-07` (current reality).
> **Lens:** Senior-architect view of a system that must scale **nationally** later — scale risks are flagged per feature, not just today's bugs.
> **Status:** Planning map only. No code was written or modified.
> **Date:** 2026-07-03 · READ-ONLY.

**Legend**
- **CURRENT STATE:** `EXISTS` (built & working) · `PARTIAL` (some pieces, broken/hidden/thin) · `ZERO` (nothing).
- **FOUNDATION DEP:** must a blocker be fixed first? `B1`=Cashfree prod (Audit 06), `B2`=admin-assign 403, `B3`=phone-crash; `DM`=data-model fix; `—`=none.
- **SCALE RISK:** what breaks at multi-city / national scale.

---

## 0. One-screen readiness matrix

| # | Feature | Current | Reuse vs New | Foundation Dep | External Dep | Scale Risk |
|---|---|---|---|---|---|---|
| F1 | Company View | ZERO | Reuse home shell/category chips; NEW surface | DM (vendor↔category) | Storage (banners) | Category/city scoping |
| F2 | Packages (sqft estimate) | ZERO | Reuse Category Control Room; NEW package model | DM | — | Per-city pricing |
| F3 | Expert Visit pay-flow | PARTIAL | Reuse inspection flow | **B1**, B2 | **Cashfree prod** | Per-area×cat pricing |
| F4 | Tracking split | PARTIAL | Reuse `bookings.js`; NEW company track | B2 | Push (FCM) | State-machine sprawl |
| F5 | Vendor Instagram Profile | PARTIAL | Reuse `stories`/portfolio UI; NEW video store | — | **Video/image storage + CDN** | Media storage cost/CDN |
| F6 | Vendor Self-Job Ledger | ZERO | NEW tables + reminder cron | — | Push/SMS reminders | Reminder scheduling volume |
| F7 | Vendor Priority Logic | ZERO | Reuse eligibility engine; NEW scoring | DM, B2 | — | Fairness across cities |
| F8 | Bypass Auto-Flag | PARTIAL | Reuse outcome tracking; NEW flag engine | B2 | — | Requires clean outcomes |
| F9 | Payment Room / Revenue Control | PARTIAL | Reuse Cashfree/ledger; NEW reporting | **B1**, DM (H2/H3) | Cashfree prod | Cash-leg invisibility |
| F10 | Area/Category Intelligence | PARTIAL | Reuse dashboard analytics; NEW controls | DM | — | Same-name area merge (A07) |
| F11 | Personalization | PARTIAL | Reuse `brain_*` + habits; NEW UI | — | — | Per-city Brain client |
| F12 | Multi-City | ZERO | NEW `cities`/`city_id` everywhere | DM (gates all) | — | **Is the scale foundation** |

Detailed entries follow.

---

## F1 — Company View (the flagship customer surface)

- **CURRENT STATE:** **ZERO.** No "company view" exists. Closest analog is Engine 5 **Company Projects = ⛔ MISSING** (Audit 01 §4, Audit 04 Engine 5: "no file/route/table anywhere"). Home today only offers the free-request / inspection / direct-vendor modes inside the `home.js` monolith (Audit 01 §2).
- **REUSE vs NEW:**
  - *Reuse:* the home shell, category chips/ecosystem, locality header/selector, and the category banner-upload plumbing already built in Category Control Room (Audit 04 Engine 7 — real `POST /categories/{id}/banner` via `UploadHelper`).
  - *New:* the entire "full company view" surface (category banner on top, in-view category switching, general "All" view, the two-path Expert Visit / Packages layout). This is net-new UI + a new intent/route.
- **FOUNDATION DEP:** **DM.** The company view is category-driven; it must know unambiguously "which vendors serve this category." Today that is stored 3 conflicting ways (Audit 02 §4a, Audit 06 M7). Build on the unified `vendor_categories` source, not before.
- **EXTERNAL DEP:** Image storage for category/company banners (UploadHelper already writes to `categories` — extend, don't reinvent).
- **SCALE RISK:** A company view is per-category **and must become per-city** (a "Painting company" in city A ≠ city B). With no `city_id` (Audit 07), a national build would show one global company per category. Must be designed city-scoped from day one even if launched single-city.

---

## F2 — Packages (Standard/Premium/Luxury + live sqft estimate)

- **CURRENT STATE:** **ZERO.** No package concept exists in schema or code. `services` has `base_price` only (Audit 02 catalog); there is no tiered-package or sqft-pricing model anywhere.
- **REUSE vs NEW:**
  - *Reuse:* Category Control Room as the admin home for packages (Audit 04 Engine 7 is already the per-category control surface); `categories` + `services` tables as the anchor.
  - *New:* a **packages** data model (package tiers per category, per-sqft rate, optional-tick semantics), the live-estimate calculator (area → cost), and the "request WITH or WITHOUT a ticked package" branching. Also new: making packages selectable from **within the existing booking form** (Vision §2.4).
- **FOUNDATION DEP:** **DM.** Packages hang off category; needs the unified vendor↔category model (M7) and clean `bookings.service_id`/category context (M8 — today `service_id` can point to another vendor's row).
- **EXTERNAL DEP:** None (pure internal calc).
- **SCALE RISK:** Package **pricing must be per-city** (sqft rates differ by market). Today all pricing is global (Audit 07 §3). A single global package price set nationally would misprice every non-pilot city. Model price as city×category×tier from the start.

---

## F3 — Expert Visit pay-flow (pay-to-confirm; admin free/paid per area×category)

- **CURRENT STATE:** **PARTIAL.** The bones exist as the **₹299 inspection** flow: intake → Cashfree checkout → webhook → paid (Audit 03 Flow B, Audit 05 §1). Admin can set an inspection price and inspection-required categories (Audit 04 Engine 4). But: the flow's **payment is failing in prod** (Audit 05 §2, Audit 06 **B1**), price is a **single global value** not per-area×category, and **post-payment there is no auto-assignment** (Audit 05 §1, funnels into B2).
- **REUSE vs NEW:**
  - *Reuse:* the whole inspection payment state machine (`_handleInspectionCheckout`, return/status/webhook — Audit 03 Flow B), Cashfree client, webhook verifier, `transactions` table.
  - *New:* per-**area × category** free/paid + price config (today `inspection_price` is one setting / one `categories.inspection_price`, Audit 04 Engine 4); the "FREE" mode; auto-assign-after-payment trigger; renaming/re-modeling "inspection" → "Expert Visit."
- **FOUNDATION DEP:** **B1 (hard) + B2.** No expert visit can *confirm* until Cashfree prod works (Vision §2.2 = "confirmed only after payment"). And once paid, assignment hits B2 (admin-assign never sets `jobs.vendor_id`, Audit 03 §4.1). Both must be fixed first.
- **EXTERNAL DEP:** **Cashfree production** (credentials + working `/orders` — the open failure in Audit 05 §2). Push/FCM for "expert assigned/on the way."
- **SCALE RISK:** Free/paid and price are **per-area × per-category × per-city** in the vision. The current single global `inspection_price` (Audit 07 §3) collapses all of that into one number — cannot express "free for CCTV in city A, ₹299 for painting in city B." Needs the per-city config store (Audit 07 §5) underneath.

---

## F4 — Tracking split (rich Company track vs badge-style Direct track)

- **CURRENT STATE:** **PARTIAL.** One unified tracker exists: `bookings.js` "My Requests" with statuses pending→confirmed→assigned→in_progress→completed and 12s auto-refresh (Audit 01 §2). There is **no visual split** between a managed Company track and a simple Direct track, and no `estimate` stage.
- **REUSE vs NEW:**
  - *Reuse:* `bookings.js`, the status polling, and `GET /api/service/bookings` (role-scoped, already returns job_id/job_status — Audit 03 §0).
  - *New:* the two distinct visual tracks — Company (`request → confirm → expert assigned → visit → estimate → work → complete`) vs Direct badge (`accepted → started → completed`, Vision §3). The **estimate** stage is new (no such state/data today). Plus the "show internal progress only after payment" gate.
- **FOUNDATION DEP:** **B2.** The Company track's middle stages (assigned → visit → work) are exactly the stages that don't advance today because vendors get 403 after admin-assign (Audit 03 §4.1). Tracking would display a pipeline that can't actually move until B2 is fixed.
- **EXTERNAL DEP:** Push/FCM for stage-change notifications.
- **SCALE RISK:** Today's booking state is spread across **6 non-agreeing columns** with divergent vocabularies (`status`/`lifecycle_state`/`assignment_state`/`vendor_response_status`/`vendor_route`/`booking_mode` — Audit 02 §3, Audit 06 M9). Two richer tracks reading that sprawl will diverge across markets. Requires the single canonical state machine (M9) first.

---

## F5 — Vendor Instagram-style Profile (photos + videos, location-tagged)

- **CURRENT STATE:** **PARTIAL.** A portfolio/media notion exists but is thin: the newer `dashboard-service.html` has a **Portfolio** section (built but not the login target — Audit 01 §3), a `stories` table exists (vendor_id, media_url, media_type — Audit 02, but dormant/content-engine, Audit 02 §2), and today's profile photo is **URL-paste only, no real upload** with `SERVICE_PROOF_UPLOAD:false` (Audit 01 §3, Audit 06 L2).
- **REUSE vs NEW:**
  - *Reuse:* the `stories` table shape (media_url/media_type) and the Portfolio UI in `dashboard-service.html`; `UploadHelper` (proven for banner image upload, Audit 04 Engine 7).
  - *New:* real photo **upload** (not URL-paste), **video** upload + playback, **location tagging** on media, and making this the trust-facing customer view. Vendor profile model must gain bio/media (today has no bio, single service area — Audit 02 §3, Audit 06 L2).
- **FOUNDATION DEP:** **—** (independent of B1/B2/B3). Can proceed on the vendor side once storage is chosen. Benefits from the vendor-panel consolidation (Audit 06 L1 — two competing panels).
- **EXTERNAL DEP:** **Media storage + CDN** — this is the biggest new external dependency in V2. Photos and especially **videos** need object storage (S3/GCS/Bunny/Cloudinary-class) and a CDN; `UploadHelper` today writes to local `categories` uploads, which will not scale to vendor video libraries.
- **SCALE RISK:** Local-disk uploads do not scale nationally (storage size, bandwidth, backup). Video transcoding/thumbnailing and per-region CDN delivery must be planned up front; retrofitting storage after vendors have uploaded is painful. Also location-tags need the multi-city geo model (Audit 07).

---

## F6 — Vendor Self-Job Ledger (external jobs + future-dated reminders)

- **CURRENT STATE:** **ZERO.** No concept of a vendor logging their *own* external jobs. `bookings`/`jobs` only model platform-originated work (Audit 02, Audit 03). No reminder/scheduler for future-dated customer intent exists.
- **REUSE vs NEW:**
  - *Reuse:* the `jobs` table *pattern* and vendor-auth; the existing cron harness (`cron/auto_assign.php` proves a lock-safe cron pattern exists — Audit 01 §0, Audit 03 §4.2) as a model for a reminder cron.
  - *New:* a **self-job / CRM** data model (vendor-owned jobs, customer, scheduled date, status), the reminder engine (fire on future date), and the CRM UI. Entirely net-new.
- **FOUNDATION DEP:** **—.** Independent of the booking-spine blockers; this is a parallel vendor-value module. (It *feeds* F7 priority logic, so sequence it before F7.)
- **EXTERNAL DEP:** Push/FCM and/or SMS (MSG91) to deliver reminders. Reuse existing FCM (`fcm_tokens`, `FcmNotifier`) and MSG91 (already integrated for OTP — Audit 01 §1a).
- **SCALE RISK:** Reminder scheduling at national vendor volume becomes a large time-based job queue — a naive "scan all future jobs every night" cron will not scale. Design the reminder store to be queryable by due-date/window from the start. Low money-risk, high retention-value.

---

## F7 — Vendor Priority Logic (bring-your-own-work → higher assignment priority)

- **CURRENT STATE:** **ZERO** as designed. An **eligibility** engine exists (`core/helpers/ServiceVendorEligibility.php`, by category/area) and the auto-assign cron *ranks* candidates (Audit 01 §0, Audit 04 Engine 3) — but there is **no priority tier** based on how much work a vendor routes through the platform. `vendors` has `rating`, `commission_rate`, but no "work-contributed" score.
- **REUSE vs NEW:**
  - *Reuse:* `ServiceVendorEligibility` and the assignment ranking hook; `vendor_wallets`/completion stats as inputs.
  - *New:* a **priority score** derived from F6 self-job volume (+ ratings, close rate), and injecting it into the assignment ranking.
- **FOUNDATION DEP:** **B2 + DM.** Priority only matters if assignment actually works end-to-end — today it doesn't (B2, Audit 03 §4.1). And it reads vendor↔category (DM/M7). Depends on **F6** existing (self-job data is the score's main input).
- **EXTERNAL DEP:** None.
- **SCALE RISK:** A priority formula tuned for one city can be unfair or gameable across many (different supply/demand per market). Must be city-scoped and auditable. Also depends on clean outcome data (F8) to avoid rewarding vendors who take jobs but don't close.

---

## F8 — Bypass Prevention / Auto-Flag

- **CURRENT STATE:** **PARTIAL.** Contact-hiding partially achievable (bookings carry `customer_mobile`; today it's exposed to the assigned vendor — no "hide until started" gate exists). Outcome tracking is **incomplete**: only Direct-Vendor completes end-to-end (Audit 03 §7); Free/Inspection strand at B2, so "did the deal close" is not reliably captured. No auto-flag/auto-throttle engine exists; the "Vendors Rejected" signal is itself broken (Audit 04 Engine 1 — queries a non-existent enum value).
- **REUSE vs NEW:**
  - *Reuse:* booking/job status, vendor completion_rate (Audit 04 Engine 9), alerts infrastructure (`alerts` table, `AlertEngine` — Audit 02).
  - *New:* the "hide customer contact until job started" rule; per-deal outcome ledger; the **auto-flag + auto-reduce-work** engine (system throttles bad vendors without admin hunting — Vision §5).
- **FOUNDATION DEP:** **B2 (hard).** Bypass detection needs trustworthy outcomes; while admin-assigned jobs strand at 403 (B2), "failed to close" is indistinguishable from "system wouldn't let the vendor act." Fix B2 (and unify the state machine, M9) before building flags on top, or the engine will punish vendors for the platform's own bug.
- **EXTERNAL DEP:** None (WhatsApp/`wa.me` deep-links already exist for ops contact — Audit 04 Engine 6).
- **SCALE RISK:** Auto-throttling nationally amplifies any false-positive: a mis-scoped rule could silently starve good vendors in a market. Needs clean, city-scoped outcome data and an admin override/appeal path.

---

## F9 — Payment Room / Revenue Control (the revenue brain)

- **CURRENT STATE:** **PARTIAL.** Gateway half is solid (signed Cashfree client, signature-verified idempotent webhook, amount checks, row locking — Audit 05 §6). But: Payments page is **hidden in pilot nav** (Audit 04 Engine 10), **settlement never runs** (`runSettlement` has no caller — earnings frozen in `pending_balance`, Audit 06 H2), `wallet_transactions.order_id` **conflates booking & order ids** (H3), per-category P&L / cash-vs-online / in-to-out reporting **does not exist**, and the only live inflow (₹299) is **failing in prod** (B1) and split 90/10 to the vendor (M6).
- **REUSE vs NEW:**
  - *Reuse:* `transactions`, `wallet_transactions`, `vendor_wallets`, `LedgerEngine::processBookingCompletion` (genuinely wired & correct — Audit 05 §3), Cashfree stack, existing `admin/payments.html` list + CSV export.
  - *New:* per-category P&L, online-vs-cash split capture (**cash outcomes aren't recorded today** — real service work is off-platform cash, Audit 05 §0), company-commission-vs-vendor-payout in-to-out view, staff cash-vs-online collection reporting, and the vendor-facing "system gave me X, I earned Y" view.
- **FOUNDATION DEP:** **B1 + DM (H2/H3).** Cannot report trustworthy revenue on a ledger that can't settle (H2) and can collide booking/order rows (H3); cannot show inflow if Cashfree prod is down (B1). These are hard pre-reqs — money-safety (Vision §9 Step 1) gates this feature.
- **EXTERNAL DEP:** **Cashfree production** (and eventually payout rails for real settlement).
- **SCALE RISK:** The **cash leg is invisible** today — nationally, most service payment is cash/COD and the platform captures none of it (Audit 05 §0). Without a way to record cash outcomes, "per-category P&L" is only measuring the ₹299 sliver. Also per-city revenue rollups need `city_id` (Audit 07). Reporting must be city-partitioned from the start.

---

## F10 — Area + Category Intelligence

- **CURRENT STATE:** **PARTIAL.** Read-only analytics exist: Dashboard "Area Demand" (bookings/completed/vendors per area with shortage flags) and category demand ordering (Audit 01 §4 Engine 8, Audit 04 Engine 8) — but **no controls** (can't act on a shortage), the per-locality coverage cell is a hardcoded `—` (Audit 04 Engine 7), and area/category banners are only partially controllable.
- **REUSE vs NEW:**
  - *Reuse:* the `area_demand` dashboard queries, category stats endpoints, banner upload.
  - *New:* turning read-only tables into **control** surfaces (act on shortage, set per-area config, per-area & per-category banners — Vision §6.2), plus the real per-locality vendor-coverage data the current cell fakes.
- **FOUNDATION DEP:** **DM.** Coverage needs the unified vendor↔category source (M7) and `vendors.service_localities` surfaced properly.
- **EXTERNAL DEP:** None.
- **SCALE RISK:** **Highest-signal Audit-07 risk.** Area demand groups by **area text with no city dimension** — same-named areas in different cities **merge into one row** (Audit 07 §2). At national scale this silently corrupts every demand/shortage metric. City-scoping (`city_id`) is mandatory before this becomes multi-city.

---

## F11 — Personalization (YouTube-algorithm-style service ordering)

- **CURRENT STATE:** **PARTIAL (backend only).** The Business Brain subsystem exists and is wired into the customer pipeline (`BrainConnector`, `brain_habits`, `brain_user_profiles`, `brain_suggestions` — Audit 01 §4 Engine 12, Audit 02 Brain tables) — but it **leans on fallbacks/placeholder rules** and has **zero admin/customer-facing surface** (Audit 04 Engine 12). No "most-used services surface first" UI exists.
- **REUSE vs NEW:**
  - *Reuse:* `brain_habits`, `brain_user_profiles`, `brain_suggestions`, the pipeline wiring — the scaffolding is genuinely there.
  - *New:* the ranking that pushes a customer's most-used/searched services first, and the home-screen ordering UI. Replace placeholder rules with real signals.
- **FOUNDATION DEP:** **—** (not gated by B1/B2/B3), but low-value until there's enough real booking/search history — so sequence it late.
- **EXTERNAL DEP:** None.
- **SCALE RISK:** Brain `client_id` is hardcoded `'haldwani'` in rules/suggestions/habits (Audit 07 §1). Personalization must move to a **per-city Brain client/tenant** (Audit 07 §3) or one city's behavior will pollute another's ranking.

---

## F12 — Multi-City (the scale foundation itself)

- **CURRENT STATE:** **ZERO.** No `cities` table, no `city_id` anywhere; "Haldwani" hardcoded across ~10 files + DB seed; categories/pricing/config all global; no add/switch-city admin UI (Audit 07 §2/§4/§6). The system is "single-city by construction, not by config."
- **REUSE vs NEW:**
  - *Reuse:* almost nothing structurally — location is free text end-to-end.
  - *New:* `cities` table; `city_id` FK on `vendors`/`bookings`/`services` (+ optional users home city); persist the booking's dropped `selected_city`; per-city config store; city-scoped matching; Cities CRUD + a city selector filtering every admin list (Audit 07 §5).
- **FOUNDATION DEP:** **DM — and it partly IS the DM foundation.** Multi-city touches the same tables as the vendor↔category and state-machine unification, so it should be laid in the **same data-model pass** (Vision §9 Step 2). It gates the scale-safe version of F1, F2, F3, F9, F10, F11.
- **EXTERNAL DEP:** None new (geo already uses Haversine on `vendors.lat/lng` — Audit 07 §2), though per-city Maps/geocoding may be wanted later.
- **SCALE RISK:** This **is** the scale risk for the whole platform. Every other feature's "scale risk" line above ultimately points back here. Building F1–F11 without city scoping means re-touching all of them later.

---

## 1. MUST FIX BEFORE ANY V2 WORK (in order)

These are the Audit-06 launch-blockers — they stop real bookings/payments **today**. Nothing in Section F is safely buildable until these are done.

1. **B1 — Cashfree prod order failure.** `POST /orders` fails in production; the only live revenue stream (inspection/expert-visit) can't collect. Gates **F3, F9** (and every "pay-to-confirm" promise). *(Audit 05 §2, Audit 06 B1.)*
2. **B2 — Admin-assign never sets `jobs.vendor_id` → vendor 403.** Admin assigns, vendor can't accept/start/complete; only Direct-Vendor works end-to-end. Gates **F3, F4, F7, F8** and any managed flow. *(Audit 03 §4.1, Audit 06 B2.)*
3. **B3 — AuthController `users.phone` duplicate-key crash.** Recurring auth crash at the pre-booking login gate; repeat/edge customers can't get in to book. Gates the top of the funnel for **everything**. *(Audit 02 §4c, Audit 06 B3.)*

---

## 2. BUILD READINESS ORDER (phased)

Mirrors Vision §9. A feature may not start until its phase's predecessors are done.

### Phase A — Fix foundation (launch-blockers)
- **B1** Cashfree prod · **B2** assign→`jobs.vendor_id` · **B3** phone auth crash.
- *No Section-F feature starts here.* This is pure unblock.

### Phase B — Money-safety
- **H2** wire settlement (`runSettlement`) · **H3** split `wallet_transactions` booking/order ids · **H4** amount-mismatch handling · **H5** booking refund path. *(Audit 06 H2–H5.)*
- **Unblocks/precedes:** **F9 (Payment Room)** — cannot be trusted until this is done. Also de-risks **F3**'s money leg.

### Phase C — Data-model unification
- **M7** one canonical vendor↔category source (recommend `vendor_categories`) · **M8** stop overloading `bookings.service_id` as category proxy · **M9** one canonical booking state machine · **H1** one canonical assignment path · **Multi-city foundation (F12)** — `cities` + `city_id`, same pass.
- **Unblocks/precedes:** **F1 (Company View), F2 (Packages), F4 (Tracking split), F7 (Priority), F10 (Area/Category Intelligence)** — all read the model this phase cleans.

### Phase D — New V2 modules
Build in dependency order:
1. **F1 Company View + F2 Packages** (flagship customer surface).
2. **F3 Expert Visit** rebuild (per-area×category free/paid, auto-assign after pay).
3. **F9 Payment Room / Revenue Control** (on the now-safe ledger).
4. **F5 Vendor Instagram Profile → F6 Self-Job Ledger → F7 Priority Logic** (value-first vendor side; F6 before F7).
5. **F8 Bypass Auto-Flag, F10 Area/Category controls, F11 Personalization, F12 admin city-management UI.**

> **Cross-cutting external dependency to procure early (Phase C/D boundary):** **media storage + CDN** for F5 vendor photos/videos — the one heavy new third-party that local `UploadHelper` disk storage cannot cover. Decide the provider before F5 starts.

---

## 3. OPEN QUESTIONS FOR FOUNDER (Aakash)

Business decisions required before the affected feature can be built. **Not answered here — these need Aakash's call.**

**Money & commission**
1. **Inspection / Expert-Visit fee split:** should the visit fee be **100% platform** (a diagnostic fee) or split with the vendor? Today it runs through the normal commission split, crediting ~90% (~₹269 of ₹299) to the vendor (Audit 05 §3, Audit 06 M6). Which is intended? *(Blocks F3, F9.)*
2. **Vendor self-job commission (F6):** the vision says commission on self-added jobs "can be set later." What is the model — 0% initially then flip on? A flat fee? Never charged? And on what event (logged / closed / paid)? *(Blocks F6 economics, F9 reporting.)*
3. **Cash-leg capture (F9):** most real service payment is off-platform cash (Audit 05 §0). Should V2 attempt to **record/settle cash outcomes** (staff collection, vendor cash reporting), or stay online-only for now? This decides whether "per-category P&L" is real or partial. *(Blocks F9 scope.)*
4. **Commission by city (F12):** should commission rates be **per-city** (different market economics) or stay global/per-category? *(Blocks F12 config model, F9.)*

**Expert Visit configuration (F3)**
5. **Free vs paid matrix:** confirm the intended granularity — is it truly **per-area × per-category × per-city**, or is per-category enough at launch? This sizes the config store. *(Blocks F3, per-city config store.)*
6. **What "estimate" means in tracking (F4):** after the expert visit, is the budget/material estimate a **formal quote the customer approves** (a new approval step + payment?), or just an informational stage? *(Blocks F4 Company track, possibly a new pay step.)*

**Packages (F2)**
7. **Package pricing formula:** is cost strictly `area(sqft) × per-tier rate`, or are there base fees / minimums / material add-ons per tier? And are tiers (Standard/Premium/Luxury) **fixed platform-wide** or definable per category/city? *(Blocks F2 model.)*
8. **Request-without-package behavior:** when a customer requests WITHOUT ticking a package, what does the company do next — send an expert, call them, or hand to a vendor? Define the downstream. *(Blocks F2 → F3/assignment handoff.)*

**Vendor priority & bypass (F7/F8)**
9. **Priority formula weighting:** what should raise a vendor's assignment priority, and in what proportion — self-job volume, rating, close rate, response speed? *(Blocks F7.)*
10. **Auto-throttle thresholds:** how many bypasses / failed closes before the system reduces or stops giving a vendor work — and is there an appeal/override path? *(Blocks F8.)*
11. **Contact-hiding timing:** confirm "hide customer contact until job started" — is "started" the right trigger, or should it be "accepted"? *(Blocks F8 rule.)*

**Vendor media (F5)**
12. **Media limits & moderation:** any caps on photo/video count or size per vendor, and is uploaded media moderated before it shows to customers? This affects storage cost and the CDN/provider choice. *(Blocks F5 storage sizing.)*

**Multi-city rollout (F12)**
13. **Second-city target & sequencing:** which city is next, and should multi-city foundation be laid **now** (Phase C) or deferred until a specific city is committed? The audits recommend laying the foundation in the data-model pass regardless (Audit 07 §5), but the timing is a business call. *(Affects Phase C scope.)*

---

*Companion to Audits 01–07 and V2-VISION.md. Planning map only — no code was written or modified. Every "current state" claim is traceable to a cited audit finding; nothing new was investigated.*
