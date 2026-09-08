# WorkToGo V2 — Product Vision (the "What to Build")

> **Purpose:** Capture the founder's complete product vision for WorkToGo V2 in one place, so any developer or AI can read this and know exactly what to build — without the vision being re-explained.
> **Relationship to the audits:** The seven `audit-0X` files in `WTG-SYSTEM-BRAIN/` describe **what currently exists** (the real, working system and its bugs). This document describes **what V2 should become**. Where V2 depends on current reality, this file references the audits by number rather than repeating them.
> **Status of this file:** Vision/spec, not an implementation. It sets direction and ordering, not code.
> **Date:** 2026-07-03
> **Read-order note:** If you have not read `audit-01` (system map) and `audit-06` (bug register), read those first — Section 9 of this document (Build Sequence) is binding and depends on them.

---

## 1. Vision Summary

**WorkToGo is a local-service Operating System — not a directory, not a marketplace listing app.**

- It is **not Urban Company** (which owns the vendor and commoditizes them) and **not JustDial** (which is a lead directory that hands off the phone number and walks away).
- WorkToGo's edge is that it **controls the three things a listing app never touches: trust, payment, and quality.** The customer trusts WorkToGo (not a stranger); money can flow through WorkToGo (not just off-platform cash); and WorkToGo can see and grade the outcome of every job. That control is the product.

**Two speed modes — this is the core mental model of the whole app:**

1. **Quick Service (Blinkit-style, direct vendor).** The customer wants a nearby vendor *now*. They see local vendors, pick one, and the job goes **directly** to that vendor. Fast, lightweight, self-serve. This is today's `direct_vendor` flow (Audit 03 Flow C — the only fully-working end-to-end flow).
2. **Company Service (contractor-style, managed).** The customer wants WorkToGo to **run the job like a contracting company**: an expert assesses the problem, a budget/material estimate is given, packages are offered, and WorkToGo manages the vendor and the outcome. This is premium and managed. Today this is only partially represented (inspection intake, Audit 03 Flow B) and must be built out into a full experience in V2.

Everything below hangs off this two-mode split. The customer should always feel they are choosing between **"get it fast, direct"** and **"let the company handle it properly."**

---

## 2. Customer Experience

### 2.1 Home screen

- **Location auto-detect** on entry (with manual override — reuse the existing locality header/selector in `home.js`).
- **Category chips** across the top (electrician, plumber, painting, waterproofing, CCTV today; must be data-driven per city — see Section 7).
- **Two clear, visually distinct zones** on the home screen:
  - **"Get Company Service"** — the premium, managed path (Company Service mode).
  - **"Near Me Vendors"** — the direct, fast path (Quick Service mode).

The two zones must read as two different *products*, not two buttons. The customer decides "do I want the company, or do I want a nearby vendor" at the top of the funnel.

### 2.2 "Get Company Service" → a FULL COMPANY VIEW (not a form)

This is the single most important new customer surface in V2. **Clicking "Get Company Service" must open what feels like walking into a contractor's company — not filling out an app form.**

- The user should feel: *"I just entered [Painting Co. / a professional contractor], and they're going to handle my job."* Not: *"I'm on step 1 of a booking form."*
- **Category banner on top** of the company view.
- **Category is switchable from inside** the company view — the user can change category without backing out to home.
- **Whichever category was selected opens that category's company view.** If the user picked "Painting," they enter the Painting company view. If they picked **"All,"** they enter a **general company view**.

Inside the company view there are **two paths** the customer can take:

#### Path A — EXPERT VISIT

- Presented as a card/section that **expands on click** to show detail: *an expert will come to your location, assess the problem in person, and give you a budget + material estimate.*
- Has a **PAY button**.
- **The expert visit is confirmed only after payment completes.** No payment, no confirmed visit. (This is the disciplined version of today's ₹299 inspection flow — Audit 03 Flow B, Audit 05 §0.)
- **The visit fee is admin-configurable**, and critically:
  - It can be set to **FREE**.
  - Free-vs-paid can be configured **per-area and per-category** (e.g. free for CCTV in one area, ₹299 for painting in another). Today `inspection_price` is a single global value (Audit 04 Engine 4, Audit 07 §3) — V2 must make this per-area × per-category.

#### Path B — PACKAGES

- Each category has **multiple packages** — e.g. **Standard / Premium / Luxury**.
- Each package has a **tick / checkbox, and ticking is OPTIONAL.**
- **Live estimate:** the user enters their **area in sqft**, and the system **auto-calculates the cost** for the ticked package(s).
- The user can submit a request in **two ways**:
  - **WITH a ticked package** — a concrete package request.
  - **WITHOUT any package ticked** — just a **general request**, with **no payment forced.**
- **Why ticking is optional (design intent):** the user must **never fear "extra charge."** A package is an *offer to consider*, not a commitment. The customer stays in control; the company feel is "here's what we can do, tell us what you want," not "pick a plan to continue."

### 2.3 After the request — minimal info collection

- Once a request is placed, the system collects the **minimum** needed:
  - **Phone** — auto-filled if logged in; asked only if not.
  - **Address.**
- **Keep it minimal on purpose** — a long form breaks the "company feel." The company already feels like it's taking care of things; it shouldn't interrogate the customer.

### 2.4 Packages inside the existing booking form

- Packages must **also be selectable from within the existing booking-form flow** (the current `home.js` intake), not only from the company view. A customer who arrives through the normal booking path should still be offered packages.

---

## 3. Tracking (Customer Side)

Two **visually distinct** tracking experiences, matching the two speed modes:

### 3.1 Company booking track (rich, staged)

A full multi-stage progress view for Company Service:

```
request → confirm → expert assigned → visit → estimate → work → complete
```

This is the "you're being managed by a company" view — it should feel substantial, like tracking a contractor's project.

### 3.2 Direct vendor track (simple, badge-style)

A **lighter, badge-style** view for Quick Service showing just the vendor's progress:

```
accepted → started → completed
```

Small, quick, glanceable — matching the lightweight nature of the direct mode.

### 3.3 Rule across both

- **Internal progress is shown only after payment is complete** (where a payment applies). Before payment, the customer sees "awaiting confirmation" — not the internal pipeline. (Ties to the Expert Visit "confirmed only after payment" rule in §2.2.)

---

## 4. Vendor Experience (V2)

The Vendor Panel is today the weakest, most confusing surface (Audit 01 §3, Audit 06 L1–L2: two competing generations, thin profile, URL-only photo, single service area). V2 rebuilds the vendor side around **giving the vendor value first.**

### 4.1 Instagram-style vendor profile

- Vendor uploads **real work photos AND videos** — e.g. a finished waterproofing job, a completed paint job.
- Media is **location-tagged.**
- Purpose: **build customer trust** by showing real, verifiable work — the visual proof a directory listing can never offer. (Replaces today's URL-paste-only photo and disabled proof upload — Audit 01 §3, Audit 06 L2.)

### 4.2 Vendor Self-Job Ledger (the retention hook)

- The vendor can **log their OWN external jobs** — work they won *outside* WorkToGo — into the platform as a **free management / CRM tool.**
- Includes **future-dated jobs with reminders**: e.g. *"customer says they'll do it in 6 months"* → the system **reminds the vendor on that date.**
- Why this matters two ways:
  - It gives the vendor a **genuinely useful free CRM** (job history, customer list, reminders) — value first.
  - It gives **WorkToGo visibility** into the vendor's real book of work, which powers priority logic (§4.3) and future revenue (§6).

### 4.3 Priority logic (value-first, anti-bypass)

- **Vendors who route more of their own work through the system get higher priority for system-assigned jobs.** Bringing work in earns better placement in WorkToGo's assignment queue.
- **Commission on self-added jobs can be set later** — it is *not* charged aggressively up front.
- **Design principle (this is the anti-bypass strategy — read it carefully):** win vendor trust by **giving VALUE first** — customer history, a CRM tool, ratings, repeat customers, better priority. When the vendor already depends on WorkToGo for managing and growing their business, **commission feels natural, not a threat.** This is **retention through value, not force.** WorkToGo does not fight bypass primarily with locks and penalties — it makes staying inside the system the obviously better deal. (Section 5 covers the enforcement backstop that complements this.)

---

## 5. Bypass Prevention

The complement to §4.3's value-first strategy — the guardrails that make bypass costly when value alone isn't enough:

- **Hide the customer's contact from the vendor until the job is started.** No phone number handoff before commitment — this removes the easy "take it off-platform" moment. (Contrast JustDial, which hands over the number and loses the relationship.)
- **Track every deal's outcome** — did it close, did it complete, did it get paid, did it get rated. WorkToGo needs the outcome of every job it touches.
- **Auto-flag vendors who repeatedly bypass or fail to close.** The system **automatically reduces or stops** giving work to vendors with a bad bypass/close pattern — the **admin should not have to hunt for them manually.** The system watches, flags, and throttles work on its own.

---

## 6. Admin Control Room (evolve the 12 engines)

V2 evolves today's 12 engines rather than replacing them. For each, the audit's current status (BUILT / PARTIAL / MISSING per Audit 01 §4 and Audit 04) and the V2 target:

| # | Engine | Today (per audit) | V2 target |
|---|---|---|---|
| 1 | Morning Brief | 🟡 PARTIAL — 4/6 cards live; 2 permanently-zero (bad query/missing column), 1 mislabeled | Fix the dead cards; make the brief city-scoped; drive it from real per-area/per-category signals. |
| 2 | Live Booking Pipeline | ✅ BUILT — drag-drop Kanban, real ops writes | Keep. Extend to show Company vs Quick mode lanes and the new Company Service stages (§3.1). |
| 3 | Smart Assignment | 🟡 PARTIAL — admin assign succeeds but never sets `jobs.vendor_id` → vendor 403 | **Fix first (B2/H1).** One canonical assignment write path; feed priority logic (§4.3) into it. |
| 4 | Premium Inspection → **Expert Visit** Management | 🟡 PARTIAL — price real, live Cashfree failure, no post-pay assign | Rebuild as **Expert Visit** control: per-area × per-category free/paid config; auto-assign after payment. |
| 5 | Company Projects | ⛔ MISSING — no file/route/table | **Build.** This is where **Company Service** (company view, packages, expert visits, estimates) is managed. Net-new. |
| 6 | Follow-up Center | ✅ BUILT — 3 real sections + assign | Keep; fix the direct-vendor "payment pending" false positive (M4); city-scope it. |
| 7 | Category Control Room | ✅ BUILT — banners, stats, approvals real | Extend: per-category **packages** (Standard/Premium/Luxury) + sqft pricing rules live here; per-city banners. |
| 8 | Area Intelligence | 🟡 PARTIAL — read-only demand tables, no controls | Turn into a **control** surface: act on shortages, set per-area config, per-area banners. See §6.2. |
| 9 | Vendor Scorecard | 🟡 PARTIAL — per-vendor stats, no leaderboard | Add ranked leaderboard; surface bypass/close flags (§5); show priority tier (§4.3). |
| 10 | Payment Control → **Payment Room / Revenue Control** | 🟡 PARTIAL — real backend, hidden in pilot nav | **Major focus — see §6.1.** Unhide, and build into the revenue brain of the platform. |
| 11 | Global Search | ⛔ MISSING as admin engine — only per-page filters | Build a real admin omni-search across bookings/vendors/customers/payments. |
| 12 | Business Brain | ⛔ MISSING as admin engine — backend wired, no UI, leans on fallbacks | Surface Brain decisions/suggestions/alerts in admin; power personalization (§6.3). |

### 6.1 Payment Room / Revenue Control (Engine 10 — the focus)

Today Payment Control is real at the gateway layer but hidden in pilot nav, and the **settlement/allocation half is unwired** (Audit 05 §6, Audit 06 H2/H3). V2 makes this the **revenue brain**:

- **Every payment traceable** — who paid, how much, which category, and the **online vs cash split.** (Today only the ₹299 inspection is captured online; real service payment is off-platform cash — Audit 05 §0. V2 must record cash outcomes too.)
- **Per-category P&L** — which category **earns** and which **loses.** Category-level profitability, not just booking counts.
- **Company commission vs vendor payout — full in-to-out.** For every rupee: what came in, what the platform kept, what the vendor was paid. (Fix the frozen-`pending_balance` settlement gap H2 and the `wallet_transactions.order_id` booking/order collision H3 first — money must be *settle-able* and *un-collidable* before this view can be trusted.)
- **Staff cash-vs-online collection reporting** — for field/ops staff, how much was collected in cash vs online, per person.
- **Vendor's own revenue history, visible to the vendor** — *"the system gave me X work, I earned Y."* This closes the loop with §4.3: the vendor *sees* the value WorkToGo delivered, which is what makes commission feel fair.

### 6.2 Area + Category Intelligence

- **Demand per area** — where requests are coming from.
- **Vendor shortage per area** — where there aren't enough vendors to serve demand. (Today Area Intelligence flags shortage read-only, Audit 04 Engine 8 — V2 makes it actionable.)
- **Category popularity ordering** — which categories are most in demand, ranked.
- **Area-wise and category-wise banners** — merchandising controlled per area and per category.

### 6.3 Personalization

- The customer's **most-used / most-searched services surface first** — a **YouTube-algorithm-style** ordering of what the customer sees. The home screen adapts to the individual. (This is where a real Business Brain, Engine 12, finally earns its place — Audit 04 Engine 12.)

---

## 7. Multi-City

Per Audit 07: WorkToGo is **single-city by construction, not by config** — there is **no `cities` table and no `city_id` anywhere**, "Haldwani" is hardcoded across ~10 files, and categories/pricing/config are global. Adding a second city today is a **schema project, not a config change.**

**What must change so a new city can be added and managed from one admin screen:**

- **Add a `cities` table** (id, name, slug, state, center lat/lng, boundary/pincodes, status).
- **Add `city_id` FK** to `vendors`, `bookings`, `services` (and optionally a home city on `users`); backfill everything to Haldwani. **Persist the booking's chosen city** to a real `bookings.city_id` (today `selected_city` is dropped — Audit 07 §2).
- **Per-city config store** — enabled categories, inspection/expert-visit required categories, **per-area × per-category free/paid + pricing** (§2.2), hero/support copy, support & WhatsApp contacts, and per-city Brain `client_id`. Retire the single global `pilot_public_config` blob and the frozen JS constant.
- **City-scoped matching** — scope `ServiceVendorEligibility::localityCovered()` by `city_id` before text-matching localities (today it is city-blind and can cross-match same-named areas — Audit 07 §2).
- **One admin city-management screen** — Cities CRUD + a **city selector** that filters every admin list (Dashboard, Follow-up, Services, Vendors, Payment Room, Area Intelligence) by `city_id`. Add and run a new city from one place.

(Full checklist in Audit 07 §5 — do not duplicate it here.)

---

## 8. Future Connect (placeholder)

- The **shopping-engine** and **video-engine** exist in the codebase but are dormant under `SERVICE_ONLY_MODE` (Audit 01 §6, Audit 02 §5). They will **connect later.**
- **Database decisions made for V2 must not block that future connection** — keep the schema and the intent pipeline able to re-admit shopping and video without a rewrite.
- **The founder will define these later.** This section is intentionally a placeholder — no shopping/video product decisions are made here.

---

## 9. Build Sequence (CTO ordering — this is binding)

**V2 cannot stand until the foundation is fixed. Do NOT present the V2 features above as buildable before the foundation is in place.** The exciting modules (Company View, Packages, Payment Room, Vendor Ledger) sit on top of a spine that is currently broken; building them first means building on sand.

### Step 0 — Fix the LAUNCH-BLOCKERS first (Audit 06)

Nothing new gets built until these three are resolved — they stop real bookings and payments **today**:

1. **B1 — Cashfree prod order failure.** The one live revenue stream (inspection/expert-visit payment) fails at order creation in production. No payment = no Company Service confirmation (§2.2 depends on this).
2. **B2 — Admin-assign never sets `jobs.vendor_id` → vendor 403.** Admin assigns, vendor can't accept/start/complete. Only Direct-Vendor works end-to-end today. Every managed flow is broken here.
3. **B3 — AuthController `users.phone` duplicate-key crash.** Recurring auth crash at the pre-booking login gate — customers can't even get in to book.

### Step 1 — Money-safety

Make money **safe to run** before building revenue features on top of it (Audit 06 H2–H5):

- Wire settlement (`runSettlement` has no caller — earnings frozen in `pending_balance`).
- Fix the `wallet_transactions.order_id` booking/order collision.
- Handle amount-mismatch webhooks without marking captured money `failed`.
- Give bookings a first-class refund path.

**The Payment Room (§6.1) cannot be trusted until this step is done** — you cannot report in-to-out revenue on a ledger that can't settle and can collide.

### Step 2 — Data-model unification

Unify the model before adding modules that read it (Audit 06 H1/M7/M8/M9):

- **The 3-way vendor↔category storage** (`services.category_id` vs `vendors.category_id` vs `vendor_categories`) — pick ONE canonical source (recommend `vendor_categories`) and migrate all reads/writes. This is called out explicitly because packages, company views, and assignment all depend on knowing "which category does this vendor serve" unambiguously.
- One canonical assignment write path.
- One canonical booking state machine (retire the parallel `lifecycle_state`/`assignment_state`/`vendor_response_status`/`vendor_route` vocabularies).
- Then lay the multi-city foundation (Section 7) — `cities` table + `city_id`, since it touches the same tables.

### Step 3 — Build the new V2 modules

**Only now** build the vision above, roughly in this order:

1. **Company View + Packages** (§2.2) — the flagship customer surface, on top of the now-clean category/assignment model.
2. **Expert Visit** rebuild (§2.2 Path A, Engine 4) — per-area × per-category free/paid, auto-assign after payment.
3. **Payment Room / Revenue Control** (§6.1) — on the now-safe ledger.
4. **Vendor Ledger + Instagram profile + priority logic** (§4) — the value-first vendor side.
5. **Bypass prevention** (§5), **Area/Category Intelligence controls** (§6.2), **Personalization** (§6.3), **admin city management** (Section 7).

### The one-line rule

**Foundation → money-safety → data-model unification → new V2 modules.** Anyone who tries to build Section 2–6 features before Steps 0–2 are done is building on the exact broken spine the audits documented. Don't.

---

*Companion to Audits 01–07. Vision/spec only — no code was written or modified in producing this document.*
