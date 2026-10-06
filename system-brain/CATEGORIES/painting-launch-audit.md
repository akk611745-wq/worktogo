# WorkToGo — Painting + Waterproofing: Launch-Readiness Audit (single category, end-to-end)

> **What this is:** A founding-CTO audit of **one category — Painting (+ Waterproofing, which rides the same flow)** — from the customer's first tap to the money landing, checked against the **real `wtg-v2` code**, not the docs. It answers three questions: *Can Painting go to staging → real world now? What blocks it? Is the structure reusable for the next category?*
> **Method:** read `system-brain/` (MAP, CATEGORIES ×5, LAUNCH/server-day-checklist) as **leads**, then verified every claim in code. Where code and docs disagree, **code wins** and the drift is listed in §12.
> **Scope:** Painting/Waterproofing launch path + only the shared core it depends on (auth, booking lifecycle, assignment, payment, admin control). Other categories/engines not audited.
> **Status labels:** ✅ VERIFIED · 🟡 PARTIALLY WORKING · ❌ BROKEN · ⛔ MISSING · ❔ NOT VERIFIED (needs a live DB/server) · ⚠️ RISK.
> **READ-ONLY** — nothing built, edited, deployed or pushed. **Date:** 2026-10-04. Branch `v2` (worktree `wtg-v2`, tip `c4a89ea`).

---

## 0. THE ONE-SCREEN ANSWER (read this if nothing else)

| Question | Honest answer |
|---|---|
| **Launch-readiness (Painting + Waterproofing)** | **≈ 60 %.** The *front half* (customer finds painting → picks package / expert visit → request saved → admin sees it → admin assigns → painter does it → marked complete) **runs**. The *back half* (what the job cost, who earned what, what the painter sees, admin's force-buttons) **does not hold up yet**. |
| **Which model works?** | **(b) Company-managed (WorkToGo → painter → customer)** works **up to the site visit**: Company View, packages + live sqft estimate, paid/free Expert Visit with online pay-to-confirm, admin queue, manual assignment. **The "quote → customer accepts → work → project price" half is MISSING.** **(a) Local provider → customer (direct)** works mechanically (customer picks a painter card) with the same money gap. |
| **Biggest launch blockers** | **(1)** The job's price is **never recorded** — every Painting job closes at **₹0** (no commission, no vendor earning, admin sees ₹0). **(2)** The vendor panel that login lands painters on has a **dead "Bookings" tab** (calls an API that doesn't exist). **(3)** Admin "Force Complete / Cancel" + Kanban "Complete" target **the wrong job id** (uses booking id as job id). **(4)** The staging DB plan in the checklist **can't bootstrap** (migrations assume legacy data). |
| **Staging ready?** | **NO for a Painting sign-off — YES to start standing up staging infra in parallel.** 4 small code fixes (≈ 2–3 dev-days) + 1 ops fix first; otherwise the staging run will "pass" while recording ₹0 money and painters can't find their jobs. |
| **Real-world ready?** | **Not yet.** After the blockers + 5 "high" items (≈ 4–5 dev-days total) it is fit for a **small, manually-coordinated Haldwani pilot** (cash on completion, admin phones painters). |
| **Structure reusable for the next category?** | **Mostly yes (data model ✅), with 2 hidden per-category dependencies** that break the "zero-code next category" promise: a new category needs an **"anchor" `services` row admin can't create** (Company requests 404 without it), and the customer-facing category content (issue chips, hero copy, search words) is **hardcoded in `home.js`**. Fix both now — cheap now, costly at category #5. |
| **Good news** | Every blocker is a **shared-spine** fix. Fixing them for Painting fixes them for **every** category. Nothing needs a rewrite. |

---

## 1. CURRENT STATUS — area scorecard

| Area | Score | One-line verdict |
|---|---|---|
| Customer discovery → request (Painting/Waterproofing) | **80 %** 🟡 | Clear 3-choice Company View, live sqft estimate, keyboard-less issue chips. 4 confusion points (§3). |
| Expert-visit payment (online) | **75 %** 🟡❔ | Server-authoritative, signature-verified, idempotent. Live Cashfree round-trip **not yet proven**. |
| Vendor (painter) job handling | **45 %** ❌ | Landing dashboard's Bookings tab is dead; working page (`bookings.php`) is unlinked, shows **no issue/package/sqft**, **₹0**, and the customer's phone from minute one. |
| Admin/team control | **55 %** 🟡❌ | Sees everything incl. package & expert-visit card; assigns. But no eligibility check, no vendor notification, wrong-job force buttons, no amount/quote entry, no refund. |
| Job money (value → commission → earnings) | **10 %** ⛔ | `bookings.total = 0` for every non-visit job and **nothing ever writes it**. Ledger runs, on ₹0. |
| Model (b) Project/quote half | **30 %** ⛔ | Visit is tracked; quote capture, accept-quote, project price, milestones all missing. |
| Shared core (auth, booking create, payment infra, ledger engine) | **85 %** ✅ | Solid and safe; the gaps are in the *wiring* above it. |
| **Overall Painting launch-readiness** | **≈ 60 %** | Front half runs; back half (money + vendor + admin buttons) must be wired. |

---

## 2. THE FULL PAINTING FLOW — stage by stage (grounded)

### 2.1 Painting — Company Package path (`booking_mode = free_lead` + package)

| # | Stage | What the customer SEES / DOES | API → handler | DB record / state | Status |
|---|---|---|---|---|---|
| 1 | Find painting | Home: category chip **Painting 🎨** (chips from `/api/service/categories`, order hint `CATEGORY_ORDER` `app/pages/home.js:2279`); search "paint/wall" maps to painting (`home.js:3424`). Hero "Trusted services for Haldwani — Book in 60 seconds" + **Get Company Service** (`home.js:2575–2595`). | GET `/api/service/categories` | — | ✅ |
| 2 | Enter the "company" | Full-page **WorkToGo Painting** view: banner, category switcher, **two cards** — *Expert Visit* (live fee) and *Choose a Package* — plus "Or just tell us" mini-form (`_companyViewHTML`, `home.js:2419–2493`). | GET `/api/service/expert-visit-fee?category=painting` → `services/index.php:1054–1078` → `ExpertVisitPricing::resolve` | — | ✅ (copy bug: package card says **"Packages arriving soon →"**, `home.js:2469`) |
| 3 | See packages + estimate | Package cards (photo, features, "₹18 / sq ft"), **"Your area (sq ft)"** number box → live "≈ ₹9,000" per card (`home.js:2496–2573`, `updatePackageEstimates` `:529`). Ticking is optional, "no charge". | GET `/api/service/packages?category=painting&city=…` → `services/index.php` (~`:985–1048`): city-specific first, else global | `packages` (admin-created) | ✅ (needs admin to create packages; none seeded) |
| 4 | Send request | "Send request" → booking sheet (`submitPackageRequest` `home.js:549–575` → `openBooking` `:721` → `_intakeRequestHTML` `:1188`): name, mobile, **Service chips, Issue chips** (Room painting / Wall putty / Exterior / Colour consultation), address, optional note. **The chosen package/sqft/estimate is NOT restated on this sheet.** | — | — | 🟡 |
| 5 | OTP (if logged out) | "Verify mobile once" → login page → resumes the same sheet incl. package (`_savePendingBookingIntent` `home.js:3860`, `_resumePendingBooking` `:3868`). | `/api/auth/otp/send`, `/otp/verify` | `otps`, `users`, JWT 30 d | ✅ |
| 6 | Booking created | Success card "Request saved — **Nearby worker matching started**" (`_showRequestSuccess` `home.js:1378–1427`). | intent `service:create_booking` → `POST /api/service/request` (`services/index.php:1230`) | `bookings`: `booking_mode=free_lead`, `vendor_route=admin_queue`, **`total = 0`** (`:1394–1396`), `package_id/package_name/area_sqft/estimate_amount` **server-recomputed** (`:1524–1550`), `city_id`, `client_request_id` (idempotency `:1426–1476`). `jobs` row `status=open`, `vendor_id NULL` (`:1694–1710`). Admin bell "New Booking Created" (`:1565`). | ✅ create · ⛔ price |
| 7 | Admin sees it | `admin/services.html` Operations Board: row with FREE badge, customer, category, locality, SLA timer (red >30 min unassigned); **Details** shows **📦 Package selected — Premium · 500 sq ft · estimate ₹9,000** (`services.html:779–789`). | GET `/api/admin/service/bookings` (`heart/api/admin/index.php:2596`) | read | ✅ |
| 8 | Admin assigns painter | Details → vendor dropdown → **Assign** (`simulateOpsAction('assign_vendor')` `services.html:816`). | PATCH `/api/admin/service/bookings/{id}/ops` (`admin/index.php:2862–2927`) | `bookings.vendor_id`, `assignment_state=lifecycle_state=worker_assigned`; `jobs.vendor_id`, `jobs.status='open'` (B2 sync `:83–86`). **`bookings.status` stays `pending`. No eligibility check. No vendor notification.** | 🟡 |
| 9 | Painter gets it | **Login lands on `dashboard-service.html`; its Bookings tab is dead** (§2.4). Working page `/vendor/bookings.php` lists it with **Accept/Reject**, customer name + **phone + WhatsApp + Call already visible**, Amount ₹0, "No notes from customer". | intent `vendor:list_jobs` → GET `/api/service/bookings` (vendor-scoped `b.vendor_id`) (`services/index.php:1742–1848`) | read | ❌ landing / 🟡 workaround |
| 10 | Accept → start → complete | Accept / Update-status modal (confirmed / in_progress / completed / **cancelled**). | intent `vendor:update_job_status` → PATCH `/api/jobs/{id}/status` (`services/index.php:2268–2469`) | job `assigned→in_progress→completed`; booking `confirmed→in_progress→completed`; lifecycle + timeline. **No transition guard** (any → any). | ✅ mechanics |
| 11 | Payment for the work | **Nothing in-app.** Customer pays painter cash, amount agreed off-app. | — | `bookings.total` still **0** | ⛔ |
| 12 | Completion → money | On `completed` → `LedgerEngine::processBookingCompletion` (`services/index.php:2460`, `LedgerEngine.php:240–334`). Split = `total × rate` → **₹0 vendor credit, ₹0 commission**. | — | `wallet_transactions` ₹0 rows; `vendor_wallets.pending_balance += 0` | ⛔ (engine ✅, input ₹0) |
| 13 | Customer tracking | My Requests (12 s refresh): Company track *Requested → Request confirmed → Expert assigned → In progress → Completed*, **📦 Premium · 500 sq ft · est ₹9,000**, vendor name (`bookings.js:408–443`). No cancel, no rating, no quote shown (none recorded). | intent `service:list_bookings` | read | 🟡 |

### 2.2 Waterproofing — Expert Visit path (paid: `booking_mode = inspection`, `visit_type = expert_visit`)

| # | Stage | Customer sees / does | API → handler | DB / state | Status |
|---|---|---|---|---|---|
| 1 | Expert Visit card | "Expert visit — ₹299" (or **Free expert visit**) resolved live per category × city (`_refreshExpertVisitFee` `home.js:451–477`). | GET `/api/service/expert-visit-fee` | `expert_visit_pricing` → `app_settings.expert_visit_fee` default 299 | ✅ |
| 2 | Intake | "Waterproofing Expert Visit" sheet: issue chips (Roof seepage / Wall dampness / Bathroom leakage / Crack sealing) — **keyboard-less** — address, preferred time. | — | — | ✅ |
| 3 | Create + pay | Cashfree checkout modal (UPI/card/netbanking) (`_handleInspectionCheckout` `home.js:1797–1848`). | `POST /api/service/request`: fee **re-resolved server-side** (`services/index.php:1403–1422`), inline Cashfree order `WTG_B{id}_{ts}` (`:1589–1681`) | `total = fee`, `payment_method=online`, `payment_status=unpaid`, lifecycle `inspection_pending`; on gateway error → `payment_status=failed` | ✅ ❔(live) |
| 4 | Verify | "Payment verification in progress" → polls `/api/payment/status` (DB-only, owner-checked) up to 8×1.5 s; survives app close/return (`home.js:1868–1903`). | Webhook `POST /api/payment/webhook` → `PaymentService::processWebhookEvent` (`lib/PaymentService.php:208–373`): signature, **amount = DB total**, idempotent | `payment_status=paid`, `status=confirmed`, lifecycle `inspection_paid` (`:547+`) | ✅ ❔(live) |
| 5 | Admin schedules expert | Details: 🧑‍🔧 Expert Visit ₹299 (paid), buttons *Inspection queued / Coordinator assigned / Scheduled / Inspection done*, assign expert. | `/ops` actions | lifecycle updates only | ✅ (gate: see PH-2) |
| 6 | Expert visits, quotes | **No place to enter the quote.** `final_quote_amount` is **read in 4 places, written by none** (grep: `bookings.js:433`, `admin/services.html:792–797`, `admin/index.php:2642/2742`, `PaymentReport.php:90`). | — | stays NULL | ⛔ |
| 7 | Customer accepts / work happens | No accept-quote, no "convert visit → job", no new project booking. Admin can't create a booking for the customer. | — | — | ⛔ |
| 8 | Completion → money | Completing the visit booking books the **₹299 as 100 % company** (`LedgerEngine.php:91–95, 296–300`). The waterproofing job itself is never valued. | — | 1 platform row ₹299 | ✅ fee / ⛔ job |

**Free expert visit** (admin sets `is_free`): booked as `free_lead` + `visit_type=expert_visit`, ₹0, no payment — flows like §2.1 (sheet title says "Book Waterproofing … we'll match a nearby worker", not "Expert Visit" — copy mismatch).

### 2.3 Waterproofing per-sqft package
Identical to §2.1 with slug `waterproofing` — works as soon as admin creates waterproofing packages. ✅ (same ₹0 gap).

### 2.4 Why the painter can't find the job from his landing page (verified)
- Login/OTP/onboarding all redirect a service vendor to **`dashboard-service.html`** (`vendor/index.php:185, 314, 506`, introduced by commit `32abf62`, 2026-09-05).
- Its **Bookings** section calls `GET /api/vendor/bookings?status=incoming`, `PATCH /api/vendor/bookings/{id}/status`, `GET /api/vendor/bookings/{id}` (`dashboard-service.html:824, 950, 1002, 1038`).
- **No PHP route serves `/api/vendor/bookings`** (heart router only maps `/api/vendor/jobs|profile|earnings|…`, `heart/index.php:253`); a GET falls through to the pipeline's *"Method not allowed. Use POST"* (405). Result: **"Failed to load bookings."**
- The **working** job page `vendor/bookings.php` (intents `vendor:list_jobs` / `vendor:update_job_status`, `vendor/shared/api.js:98–103`, `body/service-engine/index.php:61–78`) is **not linked** from `dashboard-service.html` and has **no Earnings link** — the panel is split again: *jobs on one page, earnings on another, neither linking to the other.*

---

## 3. CUSTOMER FRONTEND — clarity & psychology (Painting only)

**What's genuinely good (keep):** one obvious entry (*Get Company Service*); a 3-choice company page that matches how people think ("not sure → expert", "I know → package", "just call me"); live per-sqft estimate with *"just an idea of cost, no charge"*; issue **chips** instead of typing; OTP only at submit; request survives OTP and payment-app interruptions; Hindi status pills ("Karigar assign hua", "Kaam chal raha hai"); tracking repeats the package + estimate.

**Confusion points (each is cheap to fix):**

| # | Where | What happens | Psychology cost | Fix (no code here) |
|---|---|---|---|---|
| C-1 | Package card | Says **"Packages arriving soon →"** although packages are live (`home.js:2469`). | 3-second test fails: "not ready, skip". | Change CTA copy. |
| C-2 | "Or just tell us" mini-form | Name/phone/address typed here are saved to `wtg_user_*` keys that the booking sheet **never reads** (`home.js:583–585` vs `UI.customerProfile` reads `wtg_customer_profile`, `app/js/ui.js:143–154`) → the next sheet asks **again**. | Double typing = "system doesn't listen". | Save to the same profile key, or skip the mini-form. |
| C-3 | Booking sheet after package | Customer chose *Premium · 500 sqft · ≈₹9,000*; sheet shows only "Painting request" + generic issue chips (first chip pre-selected: "Room painting"). | Breaks "system understands me" at the commit moment. | Show a package summary strip on the sheet + success card. |
| C-4 | "Your area (sq ft)" | Requires a number most Haldwani homeowners don't know; keyboard input. | Effort + guesswork on the trust moment. | Add room/BHK preset chips (1 room / 2BHK / 3BHK → admin-set sqft) — data, not code per category. |
| C-5 | Success copy | Package/company request says **"Nearby worker matching started"**; free expert visit sheet says "we'll match a nearby worker". | Company promise ("WorkToGo handles it") reads like a marketplace. | Mode-aware copy: "Our painting team will call to schedule measurement". |
| C-6 | Tracking | Steps derive from `bookings.status`, which admin assignment doesn't change → card shows **"Karigar assign hua"** pill but the step line still says **Requested** (`bookings.js:261–295` vs `:411`). | Mixed signals. | Drive steps from `lifecycle_state`. |
| C-7 | After visit | No quote, no "approve price", no cancel, no rating in app. | Price discussed on phone = old-world painter experience. | See PB-1 / PH-4. |

---

## 4. BOTH MODELS — what the current code actually supports for Painting

| Model | Pieces | Status |
|---|---|---|
| **(a) Local Provider → Customer** (direct) | Vendor card → `booking_mode=direct_vendor`, `vendor_id` set at create, vendor FCM "New Booking Assigned" (`services/index.php:1572–1585`), vendor accepts/starts/completes. | 🟡 **Works mechanically.** Same ₹0 job value (direct `total` is also forced to 0, `:1394`), same dead landing tab, painter must have a `services` row/card in Painting. Not the showcase path for painting. |
| **(b) WorkToGo/Company → Project → Contractor → Customer** | Company View ✅ · package request ✅ · paid/free expert visit + pay-to-confirm ✅ · admin queue ✅ · manual assign 🟡 · inspection lifecycle (queued/coordinator/scheduled/done) ✅ · **quote capture ⛔ · customer accept-quote ⛔ · visit→project conversion ⛔ · project price/total ⛔ · milestones/progress payments ⛔ · contractor completion proof (photos) ⛔** | 🟡 **Half built.** Runs *up to and including the site visit*. The project itself is managed by phone. |
| **Site / expert visit today** | Customer pays (or free) → admin marks queued → coordinator → scheduled → assigns expert → expert does visit → "Inspection done" → **stops**. Expert earns ₹0 from the fee (100 % company by design). | 🟡 |
| **Does the system distinguish local vs company jobs for the vendor?** | `bookings.php` shows no mode/badge; the note-filter hides "Booking mode" and everything else. Admin list does badge FREE / ₹299 / DIRECT. | ⛔ for vendor · ✅ for admin |

---

## 5. VENDOR (PAINTER) FLOW — verified

| Need | Today | Status |
|---|---|---|
| How a painter gets the job | Admin assigns from Operations Board (auto-assign cron **never** touches booking jobs — `cron/auto_assign.php:62–69` filters `j.booking_id IS NULL`). | 🟡 manual only |
| Knows a job arrived | Admin-assign path writes **no FCM/bell/alert** (`admin/index.php:2862–2927`; the notifying `/assign` route `:2827–2859` is used by no UI — `vendorAssignControl` in `services.html:568` is dead code). Painter learns by phone or by refreshing `bookings.php`. | ⛔ |
| Sees what the job is | Name, phone, locality, address, "Painting Service", date, **Amount ₹0**, **"No notes from customer."** — `cleanNotes()` returns `null` whenever notes contain any internal tag, and every booking's notes do (`vendor/bookings.php:495–527`). Package / sqft / estimate / issue chips / customer note: **not shown**. | ❌ |
| Accept / reject | Accept → job `assigned`, booking `confirmed`; Reject → returned to admin queue (`services/index.php:2296–2377`). | ✅ |
| Work status | Update modal: confirmed / in_progress / completed / **cancelled** (`vendor/config.js:47`) — vendor can cancel a customer booking or jump straight to completed. | 🟡 ⚠️ |
| Completion proof / photos | None per job (portfolio photos exist separately). | ⛔ |
| Earnings visibility | `/api/vendor/earnings` works (`vendors/index.php:339`) but shows ₹0 for painting jobs; Earnings lives on `dashboard-service.html`, jobs on `bookings.php`. | 🟡 |
| Customer contact hidden until start (bypass guard) | Implemented only on `GET /api/vendor/jobs` (`vendors/index.php:282–289`), which **no UI calls** (and which selects `b.address/b.locality`, columns no migration creates). The used path `/api/service/bookings` returns `u.phone` + `customer_mobile` unmasked (`services/index.php:1805–1838`). | ❌ (guard not in effect) |

---

## 6. PAYMENT — launch scope

| Check | Finding | Status |
|---|---|---|
| Amount source | Expert-visit fee resolved **server-side** by category × city (`services/index.php:1403–1422`); client number only drives display. Package estimate **recomputed server-side** (`:1524–1550`). | ✅ |
| Customer view | Fee on card + on sheet; Cashfree modal shows the real amount. | ✅ |
| Initiation | Inline Cashfree order, absolute return/notify URLs fallback to request host (B1 fix in code, `:1608–1620`). Credential guard → friendly "gateway not configured". | ✅ ❔ live |
| Backend verify | Webhook signature on raw body, gateway-match, amount = DB total, idempotent, FOR UPDATE (`PaymentService.php:208–298`). Return page never self-authorises. | ✅ |
| Success → state | `paid` + `status=confirmed` + lifecycle `inspection_paid`; customer poll sees it. | ✅ |
| Failure / pending | `failed` + `alerts` row; client shows "safely saved / new booking". **No server-side reconciliation** if a webhook never arrives (stays `unpaid`; admin must verify on Cashfree dashboard). | 🟡 |
| Duplicate / wrong state | `client_request_id` dedup ✅. ⚠️ Retry after a **failed** payment within 30 min with the same cached id → passes the dedup gate but hits the UNIQUE index → "Booking could not be created" (`services/index.php:1441–1476` + `migrations/2026_05_28_001` unique index). | ⚠️ |
| Fee-fetch fallback | If the fee call fails, client assumes FREE and never opens checkout, but server makes it a **paid** inspection → customer told "Request saved", booking sits "Payment pending" (`home.js:486` vs `services/index.php:1416–1421`). | ⚠️ |
| Ledger vs payment | Completing an inspection books the fee as platform revenue **without checking `payment_status`** (`LedgerEngine.php:256–300`) → unpaid-but-completed visits inflate revenue. | ⚠️ |
| Job payment (painting work) | **None in-app.** Cash to painter, amount never recorded → commission never computed (`total=0`). | ⛔ |
| COD / cash / cheque / manual | No "mark cash received / amount" action anywhere. | ⛔ |
| Refund (paid visit cancelled) | No booking refund path (`RefundController` is order-only). Manual Cashfree refund; DB stays `paid`. | ⛔ |
| Live Cashfree round-trip | Not provable from code. | ❔ |

---

## 7. ADMIN / TEAM CONTROL — can the team run Painting without a developer?

| Need | Today | Status |
|---|---|---|
| See all Painting bookings, filter by category/lifecycle/payment, SLA for unassigned | ✅ (`admin/services.html:455–703`) | ✅ |
| See package / sqft / estimate / expert-visit fee | ✅ detail cards (`services.html:779–801`) | ✅ |
| Configure category, packages (rate/sqft/min/photos/features), expert-visit fee per category×city, banner | ✅ zero-code (`admin/index.php:636, 934, 989–1110, 1180–1250`) | ✅ |
| Assign the right painter | Dropdown **category filter is a no-op** — it matches on `v.category/category_slug/service_category`, but `/api/admin/vendors` returns only `category_id` (`admin/index.php:523–527`), so `''` matches everything (`services.html:727–741`). Rich eligibility (category ✓/area ✓/paused) is computed by the list API (`admin/index.php:2758–2813`) but rendered only by dead code. Server ops-assign checks only "active service vendor". | 🟡 ⚠️ |
| Payment gate on assigning a paid visit | Admin path ignores payment state and the "Confirm payment verified" checkbox (`admin/index.php:2862–2927`). | ⚠️ |
| Notify painter on assign | No (see §5). | ⛔ |
| Mark started | Kanban "Mark Started" only sets `worker_confirmed` (`services.html:1185–1186`); no admin "in progress". | 🟡 |
| Force complete / cancel | **Uses `job_id = bookings.id`** (list SQL `isset($bc['job_id']) ? b.job_id : b.id AS job_id`, `admin/index.php:2621`; bookings has no `job_id` column) → `/api/admin/jobs/{bookingId}/status` updates **whichever job has that id** and fires the ledger on **its** booking (`admin/index.php:2930–2964`). Correct only while booking and job id sequences happen to align. | ❌ (❔ how far apart on live DB) |
| Escalate | Writes `status='escalated'` (`admin/index.php:2905–2908`, `:2933`) — not in the `bookings.status` / `jobs.status` enums (`database-structure.sql:125, 506`; no migration widens them) → error under strict SQL mode or silent blank. | ❌ likely (❔ sql_mode) |
| Enter price / quote / cash received | Nowhere. | ⛔ |
| Refund a visit | Nowhere (manual Cashfree). | ⛔ |
| Book on behalf of a phone/WhatsApp customer | No admin create-booking. | ⛔ |
| Recover a stuck booking | Reassign/unassign ✅; force-status ❌ (above); no "stuck assigned > X h" flag (vendor accountability flags vendors, not bookings). | 🟡 |
| Dev dependency | Force-status bug, quote entry, refunds, phone-in bookings, new-category anchor service (§11) currently need a developer/DB access. | ⚠️ |

---

## 8. FULL CONNECTION TRACE — one real Painting request

```
CUSTOMER  home.js openCompanyView('painting') ─ GET /api/service/packages ─▶ services/index.php (packages) ─▶ packages
          submitPackageRequest → openBooking → confirmBooking
          POST heart {intent:"service:create_booking"} ─▶ heart/router.php:212 ─▶ body/service-engine/index.php:46
          ─▶ services/index.php:1230 POST /api/service/request
             • resolve anchor service: first ACTIVE services row in category 'painting' (:1335–1344)  ← DATA DEPENDENCY
             • total = 0 (:1394)  • package recompute (:1524)  • INSERT bookings + jobs (one txn)
             • FcmNotifier admin "New Booking Created" (:1565)
ADMIN     GET /api/admin/service/bookings (admin/index.php:2596)  — job_id := booking id  ← WRONG KEY
          PATCH /api/admin/service/bookings/{id}/ops {assign_vendor}  (admin/index.php:2862)
             • bookings.vendor_id, lifecycle=worker_assigned (status stays 'pending')  ← STATUS MISMATCH
             • jobs.vendor_id, jobs.status='open'   • no eligibility • no notification  ← SILENT
VENDOR    lands on dashboard-service.html → GET /api/vendor/bookings → 405                ← BROKEN CONNECTION
          (workaround) /vendor/bookings.php → intent vendor:list_jobs → GET /api/service/bookings (vendor scope)
             • phone unmasked  • notes filtered to nothing  • amount ₹0                  ← INFO GAP
          Accept → intent vendor:update_job_status → PATCH /api/jobs/{jobId}/status (real job id ✓)
             job assigned / booking confirmed → in_progress → completed
LEDGER    LedgerEngine::processBookingCompletion(booking) → split of total(=0) → ₹0 rows    ← MONEY GAP
CUSTOMER  My Requests (service:list_bookings) → status/lifecycle/package shown; no price, no quote
ADMIN     Force Complete → /api/admin/jobs/{bookingId}/status → may complete ANOTHER booking ← WRONG TARGET
```

**Connections found broken / wrong:** vendor landing → bookings API (missing route); admin force-status → job id (wrong key); booking `total` never set (missing write); admin assign → booking `status` (not updated, customer steps stale); admin assign → vendor (no notification). **No duplicate-request bug found** on the happy path (client_request_id dedup works), except the failed-payment retry edge (§6).

---

## 9. SHARED CORE — the minimum Painting depends on (spot-verified, not a full engine audit)

| Core piece | Verdict | Note |
|---|---|---|
| OTP login (MSG91, failover machinery) | ✅ | Prior audits; endpoints `heart/api/auth/router.php:16–29`; 30-day JWT (`verify-otp.php:165`). 2nd SMS provider still unwired (known T3). |
| Logout | 🟡 | Customer logout is **client-side only** (`app/js/auth.js:27–33`); server blacklist `/api/auth/logout` exists but customer app never calls it → token valid 30 d on a shared phone. Low. |
| Booking create + idempotency + rate-limit | ✅ | `services/index.php:1230–1739`. |
| Booking lifecycle (status/lifecycle/timeline) | 🟡 | Two vocabularies (`status` vs `lifecycle_state`) written by different paths; admin path updates only lifecycle. |
| Assignment | 🟡 | Manual only for bookings; two parallel admin APIs (engine `/api/service/bookings/{id}/assign|ops` with eligibility + payment gate, vs admin `/api/admin/service/...` without) — **the UI uses the weaker one.** |
| Payment (gateway, webhook, status) | ✅ ❔ | Safe design; live proof pending. |
| Ledger engine | ✅ | Correct, idempotent — but fed ₹0. |
| Admin auth/RBAC | ✅ | Legacy admin role (RBAC dormant, known). |
| Schema bootstrap | ⚠️ | Base tables only in `database-structure.sql`; seed migration `2026_06_14_001` hardcodes vendor **#11** + categories **1–5** → on a fresh DB it fails FK and `migrate.php` **stops at that file** (`migrate.php` try/catch exits) → packages/expert-visit/cities/RBAC never apply. |

---

## 10. STAGING READINESS

**Verdict: NO for a Painting staging sign-off today. YES to start infra prep now in parallel.**

### 10.1 Exact blockers before a meaningful Painting staging run
| ID | Blocker | Why it blocks | Minimum fix (not coded here) | Effort |
|---|---|---|---|---|
| **PB-1** | Job value never recorded (`total=0`, no writer for `final_quote_amount`) | Staging can't validate commission, vendor earnings, Payment Room — every Painting job closes at ₹0. | One admin action (and optionally vendor) **"Set final amount"** that writes `final_quote_amount` + `total` before completion (guard: required before `completed` for non-visit jobs); ledger already reads `total`; customer tracking already renders `final_quote_amount`. Decide rule: **visit booking ≠ job booking** (create/convert a job booking after quote). | ~1–1.5 d |
| **PB-2** | Vendor landing Bookings tab dead | Painter can't see/accept jobs from where login puts him. | Point the Bookings tab at the working intents (or link/redirect to `bookings.php` and add Earnings link there). | ~0.5 d |
| **PB-3** | Admin force-complete/cancel/Kanban use booking id as job id | Admin "fix a stuck booking" can complete/cancel the **wrong customer's** job and fire the ledger on it. | Return the real `j.id` (LEFT JOIN jobs) from `/api/admin/service/bookings`. ⚠️ A fresh staging DB will **hide** this bug (ids align) — test on a prod-dump copy. | ~1–2 h |
| **PB-4** | Staging DB bootstrap + checklist | `server-day-checklist` Phase 3 runs migrations on an **empty** DB (no base schema step) and seed migrations assume legacy rows; Phase 4 "green" expects `auto_assign` to assign a test booking — **it never touches booking jobs**. | Build staging from a **fresh prod dump** (has categories 1–5 + vendor 11), then `migrate.php`; correct Phase 3/4 text. Ops, not code. | ~0.5 d |

### 10.2 Staging test sequence (after PB-1…4) — what "success" looks like
1. **Admin config:** create/verify categories `painting`, `waterproofing` (exact slugs); 3 painting packages (Standard/Premium/Luxury, rate/sqft, min_sqft, photo); expert-visit override Waterproofing ₹299 (Haldwani), Painting FREE. ✅ = customer Company View shows packages + correct fee per category.
2. **Vendor:** onboard 2 painters (Painting only) + 1 waterproofer, set localities, approve. ✅ = each logs in and sees an empty but **working** job list + Earnings.
3. **Happy path A (package):** customer → Painting → Premium, 500 sqft → request. ✅ = booking with `package_id`, `area_sqft=500`, `estimate_amount` = server value; admin sees 📦 card.
4. Admin assigns painter A. ✅ = painter A sees it (and gets notified, once PH-2 lands); painter B does **not**; customer card moves off "Requested".
5. Painter accepts → starts → admin sets final amount ₹11,000 → painter completes. ✅ = customer sees quote ₹11,000; ledger rows = 11,000 × rate split; painter Earnings shows pending; Payment Room GMV & commission agree.
6. **Happy path B (paid visit):** Waterproofing expert visit, **₹1 live** + sandbox. ✅ = webhook flips `paid`; admin schedules; visit completes; ₹1/₹299 = 100 % company row only when `paid`.
7. **Negative:** cancel on Cashfree → `failed`, retry works (no "could not be created"); second customer opens first customer's booking id → 403; painter B PATCHes painter A's job → 403.
8. **Admin recovery:** force-complete & force-cancel a booking on the **prod-dump** DB → only that booking changes.
9. **Run every §11 scenario once.** Sign-off = all ✅, zero ₹0 completions, zero wrong-target updates.

---

## 11. REAL-WORLD SCENARIOS (launch-affecting only)

| Scenario | Current behaviour | Risk | Recovery today | Recommended |
|---|---|---|---|---|
| **Happy path (package)** | Runs end-to-end; job valued ₹0; price agreed on phone. | No revenue record, no commission. | None. | PB-1. |
| **Painter doesn't respond** | No notification; no timeout/escalation on admin-assigned jobs; admin SLA timer stops once a vendor is set. | Booking silently stalls. | Admin phones, reassigns. | Notify on assign (PH-2) + "assigned, not accepted in N h" flag. |
| **Painter rejects** | Returned to admin queue, `vendor_response_status=vendor_rejected` visible. | Low. | Admin reassigns. | ✅ keep. |
| **No painter in area** | Admin sees "No eligible vendors" only in dead code; the dropdown lists everyone. | Wrong-area/wrong-trade assignment. | Admin judgement. | Render eligibility marks (PH-2). |
| **Payment fails / pending** | `failed` + alert; client offers new booking; webhook-never-arrives = stuck `unpaid`. Retry-within-30-min edge can error. | Lost visit bookings; confused customer. | Admin checks Cashfree dashboard; assigns anyway (no gate on admin path). | Reconcile-on-demand (fetch order status) + fix retry id (PM-2). |
| **Customer cancels** | No cancel button; WhatsApp support → admin force-cancel (**wrong-target bug**). Paid visit → no refund path. | Wrong booking cancelled; refund off-system. | Manual. | PB-3 + customer cancel before assignment + booking refund record (PH-4). |
| **Stuck booking (assigned, never started)** | Vendor accountability flags the **vendor** after 72 h; booking not flagged. | Customer waits days. | Admin reassign. | Booking-level stale flag on the board. |
| **Price dispute (estimate ₹9,000 vs bill ₹11,000)** | Estimate stored; real bill never stored. | "WorkToGo cheated me" with no record to resolve. | Phone. | PB-1 + customer "approve revised amount" (later). |
| **Painter cancels the customer's job himself** | Allowed from the Update modal (`cancelled`), no reason, no admin alert. | Silent loss. | None. | Vendor may only *reject* (requeue), not cancel (PH-6). |
| **Bypass (painter keeps the customer)** | Phone visible from assignment, before accept. | High-ticket painting is the most bypass-prone category. | None. | PH-1 mask until `in_progress` (4-line change on the used endpoint). |

---

## 12. BROKEN · MISSING · NOT VERIFIED · RISK — the register

Format: **Problem → Evidence → Impact → Priority → Recommended solution** (none coded).

### 🔴 Launch blockers
- **PB-1 Job value never captured.** → `services/index.php:1394–1396` forces `total=0` for free_lead/direct; no code anywhere updates `bookings.total`; `final_quote_amount` has no writer. → Every Painting/Waterproofing job (and every category's non-visit job) completes at ₹0: no commission, ₹0 earnings, admin amount ₹0, Payment Room GMV (estimate-based, `PaymentReport.php:25`) disagrees with ledger. → **P0.** → "Set final amount" admin action (+ vendor-submitted amount for admin approval later) writing `final_quote_amount`+`total`; require it before `completed`; separate visit booking from job booking.
- **PB-2 Vendor landing Bookings tab calls a non-existent API.** → `dashboard-service.html:950/1002/1038` vs `heart/index.php:253`; regression from `32abf62`. → Painters can't see/accept jobs from their home screen. → **P0.** → Rewire tab to `vendor:list_jobs` / `vendor:update_job_status` intents or redirect to `bookings.php`; cross-link Jobs ↔ Earnings.
- **PB-3 Admin force-status uses booking id as job id.** → `admin/index.php:2621` + `services.html:835–845, 1194–1197` → `/api/admin/jobs/{id}/status` `:2930–2964`. → Wrong booking completed/cancelled + ledger fired on it once id sequences diverge (legacy DB). → **P0.** → Select real `j.id`. ❔ Verify divergence: `SELECT COUNT(*) FROM bookings b JOIN jobs j ON j.booking_id=b.id WHERE j.id<>b.id;`
- **PB-4 Staging DB bootstrap.** → `migrations/2026_06_14_001` (vendor 11, cats 1–5), `migrate.php` aborts on first failure, checklist Phase 3/4 (`LAUNCH/server-day-checklist.md`). → Fresh staging stops half-migrated; Phase 4 green never happens. → **P0 (ops).** → Staging = prod dump + migrate; fix checklist text.

### 🟠 High (before real customers)
- **PH-1 Contact-hidden-until-start not in effect.** → Guard only on unused `/api/vendor/jobs` (`vendors/index.php:282–289`); used `/api/service/bookings` unmasked (`services/index.php:1805–1838`); `bookings.php:339–341` shows phone/WhatsApp/Call on pending jobs. → Bypass on the highest-ticket category; docs claim it's built. → **P1.** → Apply the same mask for vendor role on `/api/service/bookings` (list + detail).
- **PH-2 Admin assignment is blind and silent.** → UI uses `/api/admin/service/bookings/{id}/ops` (no eligibility, no throttle, no payment gate, no FCM/bell); category filter no-op (`services.html:727–741`); eligibility UI dead (`:568–641`). → Wrong painter, unpaid visits assigned, painter not told. → **P1.** → Route the UI to the engine's `/assign` (has eligibility + payment gate) or port those checks; add `FcmNotifier::notifyUser`; render ✅/❌ marks; set `bookings.status=confirmed` on assign.
- **PH-3 Painter sees no job details.** → `cleanNotes` nulls all notes (`bookings.php:495–527`); package/sqft/estimate/issue not rendered. → Painter must call to learn the job; looks unprofessional. → **P1.** → Render structured fields (package, sqft, estimate, issue list, customer note) — they're already in the payload (`b.*`).
- **PH-4 No customer cancel; no booking refund.** → bookings.js has no cancel; `RefundController` order-only. → Support load; paid-visit refunds off-system; DB says `paid`. → **P1.** → Customer cancel while unassigned; admin "refund recorded" action (manual Cashfree refund + status `refunded`, ledger reversal).
- **PH-5 'escalated' not a valid status enum.** → `admin/index.php:2905–2908, 2933` vs enums. → Escalate / Force "Escalated" errors or blanks status. → **P1.** ❔ sql_mode. → Write lifecycle only, or widen enum by migration.
- **PH-6 Vendor can cancel/complete from any state.** → `services/index.php:2278–2294` (no transition guard), `vendor/config.js:47`. → Silent cancellations; completion without start. → **P1.** → Vendor statuses: accept/reject/start/complete in order; cancel = admin only.
- **PH-7 Company View copy & double entry** (C-1, C-2, C-3, C-5). → `home.js:2469, 583–585, 1188–1271, 1414–1427`. → 3-second clarity + "system understands me" broken at commit. → **P1 (cheap).**

### 🟡 Medium / Low
- **PM-1** Fee-fetch fallback opens free flow but server charges → "Payment pending" orphan (`home.js:486`). P2 — pass the server's `payment_data` decision to the client regardless of chosen mode.
- **PM-2** Failed-payment retry within 30 min can hit UNIQUE `client_request_id` → "could not be created". P2 — clear cached id on `failed`, or let server reuse the failed row.
- **PM-3** Ledger books visit fee without checking `paid` (`LedgerEngine.php:256–300`). P2.
- **PM-4** No webhook reconciliation (stuck `unpaid`). P2 — admin "re-check with gateway" button.
- **PM-5** Customer tracking steps from `status` while admin writes `lifecycle_state` (C-6). P2.
- **PM-6** No admin "create booking for customer" (phone/WhatsApp leads). P2 — Haldwani reality.
- **PM-7** No per-job completion photos/proof. P3.
- **PM-8** Customer logout client-only (30-day token survives). P3.
- **PM-9** `packages.inspection_fee` is configurable but never used by the resolver. P3 — hide or wire.
- **PM-10** Expert doing the visit earns ₹0 (100 % company by rule). Business decision — fine if the expert is staff; if it's a local painter, he works the visit for free. Decide before launch.
- **PM-11** Admin Kanban treats lifecycle `inspection_queued` as IN PROGRESS and `payment_pending` as COMPLETED (`admin/assets/app.js:32–50`). P3.

### ❔ NOT VERIFIED (needs server/live DB)
Live Cashfree order + webhook round-trip (₹1); job/booking id divergence on prod; MariaDB `sql_mode` (escalated enum); presence of legacy `bookings.address/locality` columns; FCM delivery (off at launch); 2nd SMS provider; index health on `bookings/jobs/otps` (known T4).

### ✅ VERIFIED (solid — keep)
Company View + packages read + live estimate (client & server agree, `max(area,min_sqft)`); server-side estimate recompute; expert-visit precedence resolver (category+city > category > city > global, free flag); pay-to-confirm with return/poll/verification UI; webhook security + idempotency; booking idempotency (`client_request_id`); OTP resume of the same request; admin package/expert-visit/category CRUD (zero-code); admin booking board with package & visit cards; vendor accept/reject/requeue via `bookings.php`; IDOR on booking detail and job status (owner/vendor/admin); ledger engine correctness (idempotent, FOR UPDATE, category > vendor > 10 % precedence, visit fee 100 % company); customer tracking with package context.

### 📝 Docs-vs-code drift (correct these in the CATEGORIES docs)
1. *"total = service base_price / estimate"* (`category-job-flows.md §1.1`, `money-and-earnings-flow.md §2.1–2.2`) — **false**: `total=0` for non-visit jobs.
2. *"Expert enters final_quote_amount"* — **no writer exists.**
3. *"Contact hidden until in_progress — CONFIRMED built"* — **only on an unused endpoint.**
4. *"Manual assign pushes to the vendor"* (CHANGELOG 2026-09-06) — **only the unused `/assign` route does.**
5. *"Admin path fires ledger at admin/index.php:2960"* — true, but via the wrong job id (PB-3).
6. *Server-day checklist Phase 4 green* — auto-assign never assigns booking jobs.

---

## 13. REUSABILITY — is Painting a template the next category can copy with zero code?

**What IS reusable (verified):** category = data row; packages, expert-visit pricing, vendor↔category junction, bookings all keyed by `category_id`; admin CRUD for all of it; the booking/payment/ledger spine is category-agnostic. The structure lock (`structure-lock.md §5`) holds **at the data layer**.

**What silently is NOT zero-code (fix before Painting launch — cheap now, costly later):**
| # | Hidden dependency | Evidence | Cost if left | Fix |
|---|---|---|---|---|
| R-1 | **Anchor `services` row required.** Category-routed bookings pick "first active service in category" and 404 without one. Admin has **no** create-service endpoint/UI (admin POST routes: categories, packages, expert-visit, banner, cities, roles… — no services). Painting/Waterproofing work only because a seed tied them to vendor **#11**. | `services/index.php:1320–1368`; `admin/index.php` POST list; `migrations/2026_06_14_001` | Every new category's Company requests fail ("Service not found") until a developer inserts a row; deleting vendor 11 cascades away the painting anchor. | Auto-create a company-owned anchor service when admin creates a category (or make category-routed bookings not need one). |
| R-2 | **Customer category content hardcoded.** Issue chips, hero text, search aliases, trust copy, icons live in `CATEGORY_META` (`home.js:4027+`); unknown slugs get generic chips "Expert Visit / Repair / Installation" (`home.js:2329–2334`). | `home.js` | Each new category either looks generic or needs a JS edit + deploy. | Move `CATEGORY_META` fields into `categories` (JSON column) + admin form. |
| R-3 | Slug coupling — content keys on exact slugs `painting` / `waterproofing`. | `home.js:2279, 4032–4033` | Rename in admin = content vanishes. | Same as R-2. |
| R-4 | Per-unit = sqft only (known GAP-2). | `packages.rate_per_sqft` | CCTV per camera, AC per unit mislabeled. | `(unit_label, rate_per_unit)` — already the top structural recommendation. |

**Verdict:** the *engine* is reusable; the *category onboarding* has 2 hidden code dependencies (R-1, R-2). Fix R-1 before launch (½ day), R-2 before category #3.

---

## 14. NEXT CATEGORY — is "one category first, then replicate" right?

**Yes — with one reframe.** You're not really validating *Painting*; you're validating the **Company-Service spine** (request → queue → assign → visit/quote → job value → complete → money). Every blocker found here (PB-1…3, PH-1…6) is **shared**: fix it once in Painting and Electrician/AC/Plumber inherit it for free.

Recommended sequence:
1. **Painting + Waterproofing together as one launch unit** (same flow; Waterproofing exercises the paid visit, Painting the package).
2. **Then one Direct fixed-price category** (Electrician) — it exercises the *other* route (customer picks vendor, fixed price) and will reuse the PB-1 amount fix (direct jobs are also ₹0 today).
3. Then replicate by config only — **after** R-1 (anchor service) and R-2 (category content as data) so "replicate" truly means "admin fills a form".

---

## 15. YOUR HONEST RECOMMENDATION (founding CTO)

**Do now (≈ 4–5 dev-days, one fix-round, all shared-spine):**
1. **PB-1** record the job amount (admin "Set final amount"; visit booking ≠ job booking). *Without this you are running a free lead-gen service with no books.*
2. **PB-2** make the painter's home screen show his jobs (+ cross-link Earnings).
3. **PB-3** admin force-status → real job id.
4. **PH-1** hide customer phone until job start on the endpoint actually used.
5. **PH-2** assignment: eligibility marks + payment gate + notify painter + set booking status.
6. **PH-3** show painter the package / sqft / issue / note.
7. **PH-7** four copy/UX fixes in Company View (½ day, biggest trust gain per hour).
8. **R-1** auto anchor-service on category create.
In parallel (ops, no code): staging = prod dump + migrations; fix checklist Phase 3/4; buy hosting; get Cashfree live ₹1 ready.

**Genuinely pending but not blocking a small pilot:** customer cancel + booking refund record (PH-4), escalate enum (PH-5), vendor status guard (PH-6), webhook reconciliation, phone-in bookings, completion photos, milestones/progress payments (Company Projects module), vendor cash-owed ledger (P-1 in predicted-problems — only meaningful *after* PB-1 gives it a number to owe).

**Can be ignored for launch:** hourly pricing, per-unit generalisation (painting/waterproofing are sqft-native), category-head/RBAC, auto-assign for bookings (manual is right at pilot volume), FCM push (in-app + phone is enough for 2–5 painters), per-package inspection fee.

**Cheap now, costly later:** PB-1 (every month without it is unrecoverable revenue/commission history), R-1 + R-2 (every new category multiplies the hack), PH-1 (painters learn the bypass habit in week one and never unlearn it), unifying the two admin assignment APIs (two code paths with different rules will keep producing bugs like PH-2).

**Bottom line:** Painting is **not** a rewrite and **not** far — it's one focused fix-round from a credible Haldwani pilot. But today it would launch looking finished while recording ₹0 for every job and leaving painters on a dead screen. Fix the spine once, here, and every category after Painting gets cheaper.

*Companion to `CATEGORIES/structure-lock.md`, `category-job-flows.md`, `money-and-earnings-flow.md`, `predicted-problems.md`, `LAUNCH/server-day-checklist.md`. Grounded in real `wtg-v2` code (branch `v2` @ `c4a89ea`). Documentation only — nothing changed, nothing deployed, nothing pushed.*
