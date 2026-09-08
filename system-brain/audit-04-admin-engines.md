# WTG System Audit 04 — Admin Engine Status Check (READ-ONLY)

> **Purpose:** For each of the 12 admin engines, pin down exactly what is *clickable/functional* vs *purely visual*, with more precision than Audit 01 — including any admin action that silently fails or succeeds-but-does-nothing-downstream.
> **Method:** Static read of `admin/*.html` inline JS + the endpoints they hit in `heart/api/admin/index.php` and `body/service-engine/`. No live execution.
> **Cross-refs:** Audit 03 (assignment→accept broken link), Audit 02 (schema).
> **Date:** 2026-07-03 · READ-ONLY.

**Context that colors everything:** the admin sidebar (`admin/shared/shell.js`) runs in `SERVICE_ONLY_MODE=true`, so several pages exist but are **not reachable from the nav** (`hiddenInServiceOnly`): Products, Orders, Delivery, **Payments**, System Control, Logs. Reachable in the live pilot nav: Dashboard, Morning Brief, Users, Vendors, Services, Price Control, Follow-up Center.

---

## Engine 1 — Morning Brief 🟡 PARTIAL
- **File:** `admin/morning.html` (reads `GET /api/admin/dashboard` → `data.service_stats`).
- **Works:** Greeting/date (client clock). 6 action cards render from real dashboard counts. A card is **clickable only when its count > 0** (`onclick=location.href=...`); at 0 it renders a non-clickable "✓ All clear" tile.
- **Real, live counts:** `pending_assignment` ("Unassigned Bookings"), `inspections_today`, `followup_urgent`, `completed_today` — all backed by real queries (admin index 82-120).
- **Stubbed-by-broken-query (permanently 0, never clickable):**
  - **"Vendors Rejected — Reassign Needed"** (`vendor_rejections_today`) → query `SELECT COUNT(*) FROM jobs WHERE status='rejected'` (admin index 103). **`jobs.status` enum has no `'rejected'`** (audit-02) — a vendor rejection sets status back to `'open'` (audit-03). So this always returns 0.
  - **"Reviews Pending"** (`reviews_pending`) → query references `bookings.review_status` (admin index 108). **No such column exists** (audit-02) → throws → `catch` → 0. Always 0.
- **Label bug:** the `followup_urgent` card is labeled **"Payments Pending"** but its query counts *unassigned bookings older than 15 min* (admin index 118), not payments. Misleading but functional.
- **Silent-do-nothing:** the two dead cards never become clickable — no error, just permanent "All clear."

---

## Engine 2 — Live Booking Pipeline ✅ BUILT
- **File:** `admin/services.html` → **Kanban** tab (`renderKanban`, `buildKanbanCard`, `moveKanbanCard`).
- **Works:** Columns from `GET /service/bookings`. Cards are **drag-and-drop** (`_initKanbanDragDrop`) and button-driven; `moveKanbanCard` (line 1139) issues **real** `API.patch` to `SERVICE_BOOKING_OPS` and/or `SERVICE_JOB_STATUS`. Overdue/SLA coloring computed client-side. Stage resolution shared with Follow-up via `resolveBookingStage()` so columns can't disagree.
- **Caveat:** moving a card to an assigned/vendor stage writes booking/ops state fine, but the vendor-side acceptance it implies is still subject to Audit-03 Broken Link #1 (admin writes succeed; vendor can't act).

---

## Engine 3 — Smart Assignment 🟡 PARTIAL (admin UI works; downstream stranded)
- **Files:** `admin/services.html` ops board (`vendorAssignControl`, `assignBookingVendor` L705, `simulateOpsAction` L793) + `admin/followup.html` `quickAssign` + `cron/auto_assign.php`.
- **Works (clickable, real writes):** vendor dropdown + **Assign** → `PATCH /api/admin/service/bookings/{id}/assign`; ops actions (`assign_vendor`, `reassign_vendor`, `unassign_vendor`, `mark_worker_contacted`, `mark_worker_confirmed`, `mark_escalated`, inspection steps) → `PATCH .../ops`. All return success toasts and update `bookings`.
- **Broken downstream (the important part):** these writes update **`bookings.vendor_id` only, never `jobs.vendor_id`** (Audit 03 §4 #1). The admin sees "Vendor assigned ✓", but the vendor's Accept/Start/Complete (`PATCH /api/jobs/{id}/status`) is authorized against `jobs.vendor_id` (NULL) → **403**. So the assign button **succeeds but silently strands the vendor** — the most consequential "looks-like-it-worked" action in the whole panel.
- **`cron/auto_assign.php`:** real, lock-safe, but its `WHERE j.booking_id IS NULL` filter matches no booking job → **dead for all service bookings** (Audit 03 §4 #2). Auto-assignment effectively never fires in the pilot.
- **Naming note:** `simulateOpsAction()` is not a simulation — it makes a real API call despite the name.

---

## Engine 4 — Premium Inspection Management 🟡 PARTIAL
- **Files:** `admin/pricing.html` + `admin/settings.html` (inspection price), `admin/services.html` (inspection counts, ops `inspection_queued`/`coordinator_assigned`/`inspection_scheduled`/`inspection_completed`), `admin/morning.html` (`inspections_today`).
- **Works:** Inspection price is real — `PATCH /services/{id}/price` and the `inspection_price` public setting (categories.inspection_price / app_settings). Category Control Room shows a real `₹299 INSPECTION` count per category (`modes.inspection`). Ops lifecycle buttons write real `lifecycle_state`.
- **Weak/at-risk:** the customer-side inspection *payment* (Cashfree order creation) has a **live production failure under active debug** (`CASHFREE_ORDER_DEBUG` logging in service-engine L1416; branch `debug/cashfree-prod-order-failure`). Admin has no button to retry/settle a stuck inspection payment — only the generic ops actions. Post-payment there's no auto-assign, so it funnels into Engine 3's broken assign.

---

## Engine 5 — Company Projects ⛔ MISSING
- **File:** none. No HTML page, no nav entry, no `heart/api/admin` route, no DB table. Confirmed absent (grep across `admin/`, `heart/`, code: zero hits). Nothing to click.

---

## Engine 6 — Follow-up Center ✅ BUILT
- **File:** `admin/followup.html` (in the live sidebar).
- **Works:** Loads `GET /service/bookings?limit=200` + `GET /vendors`. Three real sections computed client-side: **Unassigned** (`resolveBookingStage==='new'`), **Payment Pending** (non-paid, non-free), **Vendor Not Responded** (assigned >120 min). `quickAssign` → real `PATCH SERVICE_BOOKING_ASSIGN` with `{vendor_id, status:'assigned'}`.
- **Clickable actions:** Assign button (real); **WhatsApp buttons** are `wa.me/` deep-links (open WhatsApp — functional, not backend). 
- **Caveat:** same assign-downstream break as Engine 3 (admin assign ≠ vendor can accept).

---

## Engine 7 — Category Control Room ✅ BUILT (one stubbed cell)
- **File:** `admin/services.html` → **Categories** tab + `openCategoryControlRoom()` (L1189) overlay. Backend `GET /categories/{id}/stats`, `/categories/{id}/vendor/{id}/stats`, `POST /categories/{id}/banner`, `PATCH /categories/{id}`.
- **Works (all clickable/real):** stat cards (total/inspection/free/direct/pending/completed/active vendors); **Activate/Deactivate** (`_toggleCategoryStatusInline`); **Banner upload** (real `<input type=file>` → `uploadCategoryBanner` → `POST /categories/{id}/banner` via `UploadHelper`); vendor table with per-vendor accept/reject/completion stats (lazy-loaded per vendor); filter chips; Pending Approval list; Call/WhatsApp on vendor rows; commission % edit.
- **Stubbed (visual only):** in "Top Areas by Demand", the **per-locality vendor-coverage column is hardcoded `—`** with an explicit TODO (L1245-1247): *"vendor coverage per locality needs vendors.service_localities, which GET /categories/{id}/stats does not return."* The bar/count are real; the coverage cell is a placeholder.

---

## Engine 8 — Area Intelligence 🟡 PARTIAL (read-only display, no controls)
- **Files:** `admin/dashboard.html` "Area Demand" table (`area_demand` from `/dashboard`) + Category Control Room "Top Areas by Demand". No standalone page.
- **Works:** Dashboard table shows per-area bookings/completed/active-vendors with computed status flags (`⚠ No vendor` / `⚠ Shortage` / `✓ OK`) — real data, purely informational.
- **Visual-only / stub:** no actions of any kind (nothing clickable — it's a read-only table). The Category Control Room area section adds the hardcoded `—` coverage cell (see Engine 7). There is **no area-level control surface** (can't assign coverage, set radius, or act on a shortage from here).

---

## Engine 9 — Vendor Scorecard ✅ BUILT (per-vendor; no leaderboard)
- **File:** `admin/vendors.html` (in the live sidebar).
- **Works (all real):** `loadVendors` (`GET /vendors`), `viewVendor` detail (`GET /vendors/{id}`) showing **PERFORMANCE** block — `total_assigned`, `completed`, `active`, `completion_rate%` (color-graded) — plus rating, online status, service areas. Moderation actions all wired: **Approve** (`/vendors/{id}/approve`), **Reject** (`/reject`), **toggle Status** (`/status`), **toggle Verified** (`/verify`), **category reassign** (`PATCH /vendors/{id}/category`).
- **Stubbed/dead template branches:** the detail modal references `v.gst_number` and `v.bank_account` (L311-312) — **no such columns exist** (audit-02), so those rows never render. `experience_years`/`service_localities` render only if present.
- **Gap vs name:** there's no ranked "scorecard" leaderboard — it's per-vendor detail + moderation. The only ranking lives in Dashboard's `top_vendors` insight list.

---

## Engine 10 — Payment Control 🟡 PARTIAL (real, but hidden in pilot)
- **Files:** `admin/payments.html` (list) + `admin/refunds.html` (orphan). Backend `GET /api/admin/payments`; refunds via `RefundController`.
- **Works:** `loadPayments` → real `GET /payments` with status filter; **Export CSV** button is wired (`exportCSV(allPayments, …)`). `refunds.html` calls `/refunds?status=` (real controller).
- **Visual/unreachable:** the **Payments page is `hiddenInServiceOnly`** → **not in the live sidebar**; `refunds.html` is not in the nav at all. So in the current pilot config the whole engine is reachable only by typing the URL. No refund-issuing action exists inside `payments.html` itself (the "Refunded" dropdown is just a filter).

---

## Engine 11 — Global Search ⛔ MISSING (as an admin engine)
- **Files:** none dedicated. Backend `heart/api/search/index.php` exists but is **customer-facing** (`app` uses `API.search`).
- **Reality:** every admin page has an `id="search"` input (vendors, users, orders, delivery, products, payments, services) — but these are **local client-side table filters**, not a global search, and none call `/api/search`. There is **no admin omni-search UI**. Nothing global to click.

---

## Engine 12 — Business Brain ⛔ MISSING (as an admin engine)
- **Files:** `brain/` subsystem exists (BrainCore, engines, 12 `brain_*` tables) and is wired into the **customer** request pipeline via `heart/pipeline/BrainConnector.php`.
- **Reality:** **zero references in any `admin/*.html`** (grep: no `brain`, `suggestion`, `decision`, `BrainCore`). There is no admin page, widget, or button that surfaces Brain decisions/suggestions/alerts. From the admin panel's perspective it does not exist — nothing to click.

---

## Summary scoreboard

| # | Engine | Status | One-line precision |
|---|---|---|---|
| 1 | Morning Brief | 🟡 PARTIAL | 4/6 cards live; "Vendors Rejected" & "Reviews Pending" permanently 0 (bad enum / missing column); 1 mislabeled card |
| 2 | Live Booking Pipeline | ✅ BUILT | Drag-drop Kanban writes real ops/job status |
| 3 | Smart Assignment | 🟡 PARTIAL | Admin assign buttons succeed but never set `jobs.vendor_id` → vendor 403; auto-assign cron dead for bookings |
| 4 | Premium Inspection | 🟡 PARTIAL | Price control real; live Cashfree prod failure; no post-pay assignment |
| 5 | Company Projects | ⛔ MISSING | No file/route/table anywhere |
| 6 | Follow-up Center | ✅ BUILT | 3 real sections + assign + WhatsApp links (assign-downstream caveat) |
| 7 | Category Control Room | ✅ BUILT | Banner upload, activate, vendor stats, approvals all real; per-locality coverage cell hardcoded `—` |
| 8 | Area Intelligence | 🟡 PARTIAL | Read-only demand tables only; no controls; coverage placeholder |
| 9 | Vendor Scorecard | ✅ BUILT | Per-vendor perf + moderation real; dead gst/bank fields; no leaderboard |
| 10 | Payment Control | 🟡 PARTIAL | List/filter/CSV real, refunds controller real — but page hidden from live nav |
| 11 | Global Search | ⛔ MISSING | Only per-page local filters; backend is customer-only; no admin omni-search |
| 12 | Business Brain | ⛔ MISSING | Backend exists but zero admin UI |

### Actions that succeed-but-mislead or do nothing
1. **Smart Assignment / Follow-up "Assign"** — returns "Vendor assigned ✓" but strands the vendor (jobs.vendor_id never set) — the highest-impact misleading action.
2. **Morning Brief "Vendors Rejected" & "Reviews Pending"** — always render as non-clickable "All clear" (broken queries).
3. **Category Control Room per-locality coverage** — hardcoded `—`.
4. **Vendor detail GST / Bank rows** — never render (columns don't exist).
5. **Payments/Refunds** — fully coded but unreachable from the pilot sidebar.
6. **`admin/analytics.html`** (not one of the 12, noted): tries a real fetch then **falls back to random stub data** ("Sample data — connect API for live data"); its endpoints have no admin backend route, so it's effectively always fake.

---
*Static UI-vs-backend trace; no live requests; no files modified.*
