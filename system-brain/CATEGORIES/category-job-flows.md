# WorkToGo — Category Job-Flows (end-to-end, grounded in code)

> **What this is:** For each **launch-first category**, a concrete, real-example walkthrough of *how the job actually works end-to-end in our built system* — request → route → assignment → accept → work → completion → payment → vendor earnings → close — with the exact `booking_mode` / pricing-state / code path cited for every step. Plus, in plain words, **how the system removes the pain a customer normally faces** (finding a good worker, price uncertainty, trust) — the marketing "why this is better."
> **Companions:** `money-and-earnings-flow.md` (the precise money mechanics per shape) and `predicted-problems.md` (what can go wrong, ranked). Structure basis: `category-research.md` + `structure-lock.md`.
> **Grounded in** the real `wtg-v2` code: `body/service-engine/api/services/index.php`, `body/service-engine/api/vendors/index.php`, `core/helpers/LedgerEngine.php`, `core/helpers/ServiceVendorEligibility.php`, `cron/auto_assign.php`, `heart/api/admin/index.php`. **READ-ONLY research — nothing built, edited, or deployed.**
> **Date:** 2026-09-09. **Launch:** service-only, Haldwani, customers + karigars.

---

## 0. The shared spine (read once, applies to every category)

Every category below reuses the **same five-step spine**. Only the *route* and the *pricing state* differ per category — the plumbing underneath is identical (this is the whole point of the one-engine design).

```
  REQUEST                ROUTE                 ASSIGN                WORK                 CLOSE
  customer app  ─▶  booking_mode set  ─▶  vendor_id set  ─▶  accept→start→done  ─▶  complete→ledger→earnings
  (home.js)         (services/index)      (direct | admin)    (vendor panel)        (LedgerEngine)
```

**The three routes, in code (from `bookings.booking_mode` + `bookings.vendor_route`):**

| Route | `booking_mode` | How the vendor is chosen | Payment shape |
|---|---|---|---|
| **Quick / Direct** | `direct_vendor` | Customer picks the nearby karigar themselves → `vendor_id` set at creation, `vendor_route='direct_vendor'` (`services/index.php:1509`) | Cash on job (launch); fixed price |
| **Expert Visit** | `inspection` (`visit_type='expert_visit'`) | Admin (or auto) assigns after the visit fee is **paid online** (pay-to-confirm) | ₹fee online → then quote |
| **Managed / Company** | `free_lead` | Lands in **admin queue** (`vendor_route='admin_queue'`) → admin assigns from the same nearby pool → `admin_assigned` | No forced payment; cash/quote later |

**Two assignment engines, same vendor pool** (`vendors` table, `type='service'`, filtered by `vendor_categories`):
- **Auto-assign cron** (`cron/auto_assign.php`) — nearest **online, active** vendor by haversine distance (`:lat/:lng`, line 156–171), throttle-aware. **Scope caveat (verified):** it only picks up jobs with `booking_id IS NULL` and a paid/COD gate (line 67–69) — so in practice it serves booking-less/commerce flows; **normal booking→job assignment is admin-driven today.**
- **Admin assign** (`heart/api/admin/index.php`) — admin picks from the **same nearby vendors**, scored by `ServiceVendorEligibility::evaluate(vendor, booking, availability)` (category match + `localityCovered()` + availability). The B2 fix keeps `jobs.vendor_id` in sync with `bookings.vendor_id` on assign (`admin/index.php:83`), so the assigned vendor can actually accept.

**Contact-hidden-until-start (bypass prevention, verified built):** the vendor sees the customer's phone as `NULL` until the job is `in_progress` or `completed` (`vendors/index.php:284–286, 323–325`). The public vendor list never exposes vendor phone/email (`vendors/index.php:184–188`). Full detail in `money-and-earnings-flow.md §7`.

**Completion → earnings (verified built):** when a job is set `completed`, `LedgerEngine::processBookingCompletion(booking_id)` fires (`services/index.php:2460–2462`, and the admin path `admin/index.php:2960`). It splits money (or books an expert-visit fee 100% to company) into `vendor_wallets.pending_balance` + `wallet_transactions`. Details in the money doc.

> **The customer only ever sees three things:** a price ("₹499"), a rate ("₹18/sqft → ₹9,000"), or "expert aayega." Everything below is how each category maps onto that.

---

## 1. WAVE-1 DIRECT FIXED-PRICE CATEGORIES

These seven share **one shape: Quick/Direct + Fixed price**. I give each a real example; the mechanics are identical, so the shared flow is written once (1.1) and each category notes only what's specific.

### 1.1 The shared Direct-Fixed flow (the fastest path to first rupee)

**`booking_mode = direct_vendor` · pricing state = Fixed (`services.base_price`) · route = Quick.**

1. **Request.** Customer opens the app → "Near Me Vendors" → category chip → sees nearby karigars (name, rating, distance — **no phone**). Picks one, picks a slot. `POST service:create_booking` (`heart/router.php:212`).
2. **Route + create.** `booking_mode='direct_vendor'`; `vendor_id` = the chosen karigar (set at creation, `services/index.php:1493–1509`); `vendor_route='direct_vendor'`; a `bookings` row + a `jobs` row are created in one transaction; `total` = the service's `base_price`; `booking_number` issued; `city_id` resolved (default Haldwani).
3. **Assign.** Already assigned — the customer chose the vendor. No admin, no cron. The karigar gets a "New Request" alert.
4. **Accept → start.** Karigar opens the vendor panel, sees the job (customer name, locality, service — **phone still hidden**), taps Accept → status `assigned`/`vendor_accepted`; on arrival taps Start → `in_progress`, **at which point the customer's phone is revealed** (`vendors/index.php:284`).
5. **Work → complete.** Karigar does the job, taps Complete → job `completed` → booking `completed` → `LedgerEngine::processBookingCompletion` fires (`services/index.php:2460`).
6. **Payment + earnings.** At launch the customer **pays the karigar in cash**; the ledger records the split — platform commission (category rate → vendor rate → 10% default) as platform revenue, the rest to the vendor's `pending_balance` (see money doc §1). It appears in the vendor's **Earnings** view (`GET /api/vendor/earnings`, `vendors/index.php:339`).
7. **Close / warranty.** Booking sits `completed`; ratings/repeat live on top. (No structured warranty field yet — noted in predicted-problems P-13.)

**How it removes the customer's pain:**
- **Finding a good worker:** the app shows *rated, verified, nearby* karigars instead of the customer ringing three unknown numbers off a poster.
- **Price certainty:** a fixed "₹499" is shown *before* booking — no "dekh ke bataayenge" surprise.
- **Trust:** the booking is with **WorkToGo**, not a stranger; the outcome is tracked and rateable; the karigar's contact is only exchanged once work actually starts.

---

### 1.2 Electrician — "fan not working / switchboard sparking"
- **Example:** Customer in Kathgodam-road area: "bedroom ka fan aur switch band hai."
- **Route/pricing:** Quick/Direct, Fixed call-out (e.g. `base_price = 149` visit + repair). `booking_mode='direct_vendor'`.
- **Flow:** exactly §1.1. Picks nearest electrician → accept → start (phone revealed) → fixes fan + switch → complete → cash ₹149 (+ any parts, informal at launch) → commission split booked.
- **Complexity solved:** power-cut town, everyone needs a reliable electrician *now*; the app gives a rated one nearby in minutes with a known visit price.
- **Watch (see predicted-problems):** extra parts/scope beyond the fixed price is informal cash today (P-3); full rewiring should go **Expert-Visit**, not direct (P-6).

### 1.3 Plumber — "kitchen tap leaking"
- **Example:** "RO ke neeche wala pipe leak kar raha hai, paani bhar raha hai."
- **Route/pricing:** Quick/Direct, Fixed (e.g. `149`). `direct_vendor`.
- **Flow:** §1.1. Hard-water town → tap/RO plumbing is everyday volume.
- **Complexity solved:** urgent leaks can't wait for quotes; a nearby plumber at a known price is the product.
- **Watch:** a "bathroom banwana" (full redo) is a project → Expert-Visit, not a ₹149 direct call (P-6).

### 1.4 AC service & repair — "AC not cooling"
- **Example:** "AC chal raha hai par thandi hawa nahi." → book **AC Service ₹499**.
- **Route/pricing:** Quick/Direct, **Fixed** `base_price=499` (the cleanest fixed-price demo). `direct_vendor`.
- **Flow:** §1.1 verbatim — this is worked example A in `category-research.md §2.1`.
- **Complexity solved:** the customer doesn't know if it's gas, filter or PCB; the flat service price removes that fear — diagnosis is included, and if it's a bigger repair (gas/PCB) the karigar quotes on the spot.
- **Watch:** gas-refill / PCB is *extra* over the ₹499 → today handled as informal cash add-on (P-3); the estimate-vs-fixed mismatch is the thing to script for staff.

### 1.5 Appliance repair — "fridge not cooling / washing machine dead"
- **Example:** "fridge ka freezer kaam nahi kar raha."
- **Route/pricing:** Quick/Direct, **Fixed** visit-charge (e.g. `299`) + parts extra. `direct_vendor`.
- **Complexity solved:** brand-agnostic — the customer needn't chase the brand service centre (slow, out-of-warranty expensive); a nearby generalist comes fast at a known visit fee.
- **Watch:** parts pricing is off-platform at launch (material deferred, P-3); warranty on repair is not tracked (P-13).

### 1.6 Geyser / water heater — "no hot water" (winter spike)
- **Example (Oct–Feb):** "geyser se garam paani nahi aa raha."
- **Route/pricing:** Quick/Direct, **Fixed** service (e.g. `399`); install = estimate. `direct_vendor`.
- **Complexity solved:** seasonal urgency in a cold hill town; instant nearby booking beats waiting days for an electrician-cum-plumber.
- **Watch:** element replacement = parts (informal, P-3); a **new geyser install** is better as Expert-Visit/estimate.

### 1.7 RO / water purifier — "RO not working / filter change"
- **Example:** "RO ka paani band, service karwana hai."
- **Route/pricing:** Quick/Direct, **Fixed** service (e.g. `349`) + filter/membrane extra; AMC as a flat **package**. `direct_vendor`.
- **Complexity solved:** hard-water town → RO is near-universal and needs recurring service; a known service price + optional AMC package is exactly the ask.
- **Watch:** consumables (filter/membrane) pricing is off-platform now (P-3); AMC recurrence/reminders not modelled (P-14).

### 1.8 Carpenter (small jobs) — "door not closing / hinge broken"
- **Example:** "almari ka door latak gaya, hinge kharab hai."
- **Route/pricing:** Quick/Direct, **Fixed** per-task (e.g. `199`); furniture-making = estimate/project. `direct_vendor`.
- **Complexity solved:** small repairs are high-frequency and annoying; a nearby carpenter at a fixed task price is a clean win.
- **Watch:** "almari banwana" (modular/new furniture) is a **project** → Expert-Visit + quote, not direct (P-6); wood material is off-platform (P-3).

---

## 2. WAVE-1 COMPANY-SERVICE SHOWCASES

These two prove the premium, managed path. Different route and pricing from Wave-1.

### 2.1 Painting — "3 rooms repaint" — `Package per-sqft` + Company View
- **Example:** "3 kamre paint karane hain, halka blue."
- **Route/pricing:** **Company / Package**, **Package per-sqft** (`packages.rate_per_sqft`). Optionally Expert-Visit to measure.
- **Flow (grounded):**
  1. Customer taps **"Get Company Service"** → **Painting** company view (banner, packages Standard/Premium/Luxury from the `packages` table — admin CRUD `POST /api/admin/categories/{id}/packages`).
  2. Ticks **Premium** (`rate_per_sqft=18`), enters `area_sqft=500` → **live estimate** `estimate_amount = 9,000`, **server-recomputed** (not client-trusted, `migrations/2026_07_04_006`). Ticking is optional; submitting **without** a package = a general `free_lead` request, **no forced payment** (`category-research.md §3`).
  3. Submit → `bookings` row with `package_id`, `package_name` (snapshot), `area_sqft`, `estimate_amount`; `booking_mode='free_lead'` → `vendor_route='admin_queue'`.
  4. **Assign:** admin assigns a nearby painter from the same pool (`ServiceVendorEligibility`), `jobs.vendor_id` synced (B2 fix).
  5. Work → complete → ledger split on the final `total` (money doc §2).
- **Complexity solved:** painting price is a black box for customers; the **live per-sqft estimate** turns "kitna lagega?" into a number the customer computes themselves, and the "company" framing means WorkToGo owns quality — not a random painter.
- **Watch:** the estimate is an *estimate*; the real total can differ (surface prep, coats) → estimate-vs-actual is captured (`final_quote_amount`) but scope-creep disputes are a predicted problem (P-8). Per-sqft is the only per-unit today (P-2 in structure-lock).

### 2.2 Waterproofing — "bedroom wall seepage" — `Inspection → quote` (Expert Visit)
- **Example:** "baarish ke baad bedroom ki deewar se paani/seelan aa rahi hai." (Haldwani's signature pain.)
- **Route/pricing:** **Company / Expert-Visit**, **Inspection-required** → `final_quote_amount`. `booking_mode='inspection'`, `visit_type='expert_visit'`.
- **Flow (grounded):**
  1. Company view → **Waterproofing** → **Expert Visit** card expands: "expert aakar dekhega, phir exact rate."
  2. Fee resolved by `ExpertVisitPricing::resolve(category, city)` (precedence: category+city > category > city > global; can be **FREE**). Customer pays online (pay-to-confirm) → visit **confirmed only after payment** (`category-research.md §2.2 Path A`).
  3. `bookings` row: `booking_mode='inspection'`, `visit_type='expert_visit'`, `expert_visit_fee` = resolved fee.
  4. Expert visits, inspects the damp, enters the real number into `final_quote_amount`.
  5. Customer approves (informally today) → job proceeds → complete → ledger. **The expert-visit fee itself is 100% company** (`LedgerEngine::isExpertVisitFee`, `LedgerEngine.php:290–300`); the actual waterproofing job revenue splits normally.
- **Complexity solved:** seepage can't be priced blind — the customer normally fears being overcharged by a contractor who "eyeballs" it. The paid expert visit gives a *company-backed diagnosis and written quote* before any commitment; that is the trust product.
- **Watch:** the quote → accept → do-the-work step has **no structured accept/milestone flow** (runs as one visit + one number, structure-lock GAP-4, P-9); expert-visit-fee abuse (customer just wants a free diagnosis) is a predicted problem (P-10).

---

## 3. How a PROJECT reaches the SAME nearby vendors (not sourced separately)

The founder's explicit question. **Confirmed mechanism, grounded:**

- A project/managed job (full wiring, full-house paint, waterproofing) is created as `booking_mode='inspection'` or `'free_lead'` → `vendor_route='admin_queue'`.
- The admin assigns it from the **exact same `vendors` table** everyone else is drawn from — filtered to the category via `vendor_categories`, to the area via `ServiceVendorEligibility::localityCovered()`, and scored by `evaluate(vendor, booking, availability)`. The nearest/eligible registered karigars are the candidates.
- The B2 fix (`admin/index.php:83`) writes `jobs.vendor_id` so the assigned karigar can accept/start/complete.
- The **auto-assign cron** uses the identical pool (nearest online active `type='service'` vendor by haversine, `auto_assign.php:156`), so if project auto-assignment is later enabled for booking-linked jobs, it draws from the same karigars — **no separate sourcing pipeline exists or is needed.**

> **In one line:** projects are assigned to our *already-registered nearby karigars* via admin (today) or the same nearest-vendor engine (mechanism present) — one vendor pool for quick jobs and big projects alike.

---

*Companion to `money-and-earnings-flow.md` and `predicted-problems.md`. Every flow above is traced to real `wtg-v2` code. Documentation only — no code changed.*
