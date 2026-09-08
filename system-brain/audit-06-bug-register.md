# WTG System Audit 06 — Consolidated Bug & Risk Register (READ-ONLY)

> **Purpose:** One prioritized list of every bug, broken link, dead code, and risk found in Audits 01–05, to plan V2 fixes from.
> **Method:** Consolidation only — no new investigation. Each item cites its source audit + file(s) + a fix *direction* (not the fix).
> **Date:** 2026-07-03 · READ-ONLY — nothing modified.

**Priority legend:**
- 🔴 **LAUNCH-BLOCKER** — stops real bookings/payments today
- 🟠 **HIGH** — breaks a core flow but has a workaround (usually "admin hand-drives it")
- 🟡 **MEDIUM** — misleading/incomplete, not blocking
- ⚪ **LOW / V2** — missing features, cleanup, dead code

**Headline:** 3 launch-blockers, 5 high. All 3 blockers cluster on the **assignment→acceptance→payment** spine: (1) the live Cashfree prod failure, (2) admin-assign never sets `jobs.vendor_id` so vendors get 403, (3) the recurring `users.phone` duplicate-key auth crash at the pre-booking login gate.

---

## 🔴 LAUNCH-BLOCKERS

### B1 — Inspection payment fails at Cashfree order creation in production
- **What:** `POST /orders` to Cashfree returns non-success in PRODUCTION; booking saves `payment_status='failed'`, no `payment_session_id`, customer cannot pay. The **only live revenue stream is blocked**.
- **Where:** `body/service-engine/api/services/index.php` (~L1373-1455, `CASHFREE_ORDER_DEBUG` at L1416); branch `debug/cashfree-prod-order-failure`. (Audit 03 §2, Audit 05 §2)
- **Fix direction:** Capture the exact Cashfree error from the debug log; verify prod credentials/`CASHFREE_ENV`/API base and the order payload (hardcoded `customer_email`, phone fallback). Tighten the credential guard beyond the literal `placeholder`/`your_` check.

### B2 — Admin assign never sets `jobs.vendor_id` → vendor gets 403 on accept/start/complete
- **What:** Free + Inspection bookings are created with `jobs.vendor_id=NULL`. Admin assign updates **`bookings.vendor_id` only**; the vendor's `PATCH /api/jobs/{id}/status` authorizes on `jobs.vendor_id` → **403**. Vendors can only progress Direct-Vendor jobs; everything else needs an admin to hand-drive status.
- **Where:** admin assign `heart/api/admin/index.php` L1788-1793 + ops `assign_vendor`/`reassign_vendor` L1833-1840; ownership check `body/service-engine/api/services/index.php` L2064. (Audit 03 Broken Link #1, Audit 04 Engine 3, Audit 02 §4b)
- **Fix direction:** Make admin assignment write `jobs.vendor_id` (+ status) in the same transaction as `bookings.vendor_id` — i.e. one canonical assignment write path shared by admin-assign, ops, and `accept.php`.

### B3 — `users.phone` duplicate-key crash at the pre-booking login gate
- **What:** Recurring `SQLSTATE[23000] 1062 Duplicate entry '…' for key 'phone'` (and `phone cannot be null`) from `AuthController`. Every logged-out booking submit routes through login first, so a colliding phone **fails auth before booking creation** — top-of-funnel blocker for repeat/edge users.
- **Where:** `heart/api/auth/AuthController.php` (~L44/66/267); evidenced by dozens of rows in the prod `logs` table. (Audit 02 §4c, Audit 03 §5)
- **Fix direction:** Normalize phone to a single canonical form; make register/login upsert-safe (treat existing phone as login, never a blind INSERT); ensure Google/guest keep `phone=NULL` (never `''`).

---

## 🟠 HIGH

### H1 — Auto-assign cron excludes every real booking (the correct accept path is unreachable)
- **What:** `cron/auto_assign.php` selects jobs `WHERE j.booking_id IS NULL`, but every booking's job has `booking_id` set → cron never assigns a booking. The consistent, `jobs.vendor_id`-syncing acceptance endpoint (`api/assignments/accept.php` + `auto_assignments`) is therefore dead for services; the system falls back to the broken manual path (B2).
- **Where:** `cron/auto_assign.php` L67; `api/assignments/accept.php` L104-121. (Audit 03 Broken Link #2, Audit 04 Engine 3)
- **Fix direction:** Decide one assignment model. Either fix the cron filter to include booking-linked open jobs and route all acceptance through `accept.php`, or retire auto-assign for services and make manual-assign write `jobs.vendor_id` (see B2).

### H2 — Settlement never runs; vendor earnings frozen in `pending_balance`
- **What:** `LedgerEngine::runSettlement()` has **no caller** anywhere. Completed bookings credit `vendor_wallets.pending_balance` but it's never moved to `available_balance`; "Pending Vendor Payout" grows unbounded and vendors can't be paid out through the system.
- **Where:** `core/helpers/LedgerEngine.php` L221 (definition, unreferenced). (Audit 05 §3, §5.1)
- **Fix direction:** Wire `runSettlement` to a cron and/or an admin "settle payouts" action; define the settlement cadence.

### H3 — `wallet_transactions.order_id` conflates booking_id and order_id
- **What:** `processBookingCompletion` writes the **booking id** into `wallet_transactions.order_id`; `processRefund(order_id)` selects by the same column. Booking #N and Order #N share ledger rows → cross-domain collision / wrong-reversal / double-count risk (masked today only because shopping orders are dormant).
- **Where:** `core/helpers/LedgerEngine.php` L201-206 (write), L267 (refund select). (Audit 05 §5.2)
- **Fix direction:** Add an explicit `reference_type` (or separate columns) to `wallet_transactions` so booking and order ledger entries never collide.

### H4 — Amount-mismatch webhook marks `failed` on already-captured money
- **What:** If Cashfree reports `paid` but `order_amount ≠ bookings.total`, the webhook sets `payment_status='failed'` and commits with **no auto-refund** — money captured at the gateway, booking reads failed, left for manual review.
- **Where:** `lib/PaymentService.php` L210-214. (Audit 05 §5.3)
- **Fix direction:** On mismatch, hold in a `flagged`/review state (not `failed`) and trigger a reconciliation/refund path rather than silently diverging from captured funds.

### H5 — Paid-but-never-completed inspection has no booking refund path
- **What:** On `paid`, a `transactions` row (₹299) is created but `wallet_transactions` only appear at completion. A paid inspection cancelled before completion has captured money and no ledger row to reverse; `processRefund`/`RefundController` are order/`wallet_transactions`-oriented.
- **Where:** `lib/PaymentService.php` (webhook paid path), `core/controllers/RefundController.php`. (Audit 05 §5.4)
- **Fix direction:** Give bookings a first-class refund path keyed off the `transactions` row (not just `wallet_transactions`), reachable from admin.

---

## 🟡 MEDIUM (misleading / incomplete, not blocking)

### M1 — Admin "Assign" reports success while stranding the vendor
- Same root as B2, but the *UX* issue: Smart Assignment / Follow-up "Assign" shows "Vendor assigned ✓" with no signal the vendor can't act. **Where:** `admin/services.html`, `admin/followup.html`. (Audit 04 §Actions #1) · **Fix direction:** surface true assignment state once B2 is fixed.

### M2 — Morning Brief: two permanently-zero cards
- "Vendors Rejected" queries `jobs.status='rejected'` (no such enum value) and "Reviews Pending" queries `bookings.review_status` (no such column) → both always 0, never clickable. **Where:** `heart/api/admin/index.php` L103, L108; `admin/morning.html`. (Audit 04 Engine 1) · **Fix direction:** correct the queries (rejections live as requeued/`vendor_response_status`; add a real review field or drop the card).

### M3 — Morning Brief card mislabeled "Payments Pending"
- `followup_urgent` counts unassigned bookings >15 min but is labeled "Payments Pending." **Where:** `admin/morning.html` L205 + admin index L118. (Audit 04 Engine 1) · **Fix direction:** relabel or repoint the query.

### M4 — Follow-up "Payment Pending" false-positives on Direct-Vendor
- `renderPaymentPending()` excludes only `free_lead`, so `direct_vendor` (total 0, `unpaid`) wrongly shows as owing payment. **Where:** `admin/followup.html`. (Audit 05 §4) · **Fix direction:** exclude direct_vendor / gate on `total > 0`.

### M5 — `payment_status='unpaid'` is overloaded
- Means "no charge exists" (Free/Direct) vs "awaiting payment" (Inspection); consumers can't distinguish from the column. **Where:** bookings schema + all readers. (Audit 05 §4) · **Fix direction:** add a `payment_required`/`na` distinction or a dedicated state.

### M6 — ₹299 inspection fee split 90/10 to the vendor
- Completion runs the inspection fee through the normal commission split, crediting ~₹269 to the vendor wallet — may be unintended for a platform diagnostic fee. **Where:** `core/helpers/LedgerEngine.php` `processBookingCompletion`. (Audit 05 §3) · **Fix direction:** confirm business intent; special-case inspection revenue if it should be 100% platform.

### M7 — Vendor↔Category stored three inconsistent ways
- `services.category_id`+`services.vendor_id` (used by resolver) vs `vendors.category_id` (loose `int(11)`, no FK, type-mismatched) vs `vendor_categories` junction (dual-written mirror, barely read). Creation is worked around, but reporting/eligibility can disagree. **Where:** `heart/api/admin/index.php`, `body/service-engine/api/vendors/index.php`, schema. (Audit 02 §4a, Audit 03 §5) · **Fix direction:** pick one canonical source (recommend `vendor_categories`) and migrate reads/writes to it.

### M8 — `bookings.service_id` can point to another vendor's service row
- For inspection/free_lead the resolver binds `service_id` to the lowest-id active service in the category; only `vendor_id` is authoritative. Reporting on `service_id` is misleading. **Where:** `body/service-engine/api/services/index.php` (category-routed resolution). (Audit 03 §5) · **Fix direction:** store category context explicitly; don't overload `service_id` as the category proxy.

### M9 — Parallel booking state columns with divergent vocabularies
- `status` + `booking_mode` + `lifecycle_state` + `assignment_state` + `vendor_response_status` + `vendor_route`, written by different handlers using different words (`worker_assigned` vs `assigned` vs `vendor_accepted`); no single coherent state machine. **Where:** bookings schema; service-engine + admin handlers. (Audit 02 §3, Audit 03 §6) · **Fix direction:** define one canonical state machine; derive the rest or drop them.

### M10 — Two order-creation code paths can drift
- Inline inspection path (`services/index.php`) vs `PaymentService::createOnlinePaymentOrder`; the inline one lacks the stricter amount-echo and hardcodes customer email/phone. **Where:** `body/service-engine/api/services/index.php`, `lib/PaymentService.php`. (Audit 05 §1) · **Fix direction:** route both through `PaymentService`.

### M11 — Duplicate-prevention relies on a column with a fragile fallback
- Gate uses `bookings.client_request_id` (UNIQUE); if absent, falls back to a `notes LIKE '%Client request ID:%'` scan. **Where:** `body/service-engine/api/services/index.php` L1265-1268. (Audit 03 §5) · **Fix direction:** make the column a hard dependency; remove the LIKE fallback.

### M12 — `notifications.user_id int(10)` vs `users.id bigint(20)` width mismatch
- No FK; large user IDs could mis-map. **Where:** schema. (Audit 02 §4c) · **Fix direction:** widen to bigint + add FK.

### M13 — Admin analytics ships stub/random data
- `admin/analytics.html` tries a fetch then falls back to random stub ("Sample data — connect API for live data"); no admin backend route exists → effectively always fake. **Where:** `admin/analytics.html`. (Audit 01 §5.4, Audit 04 §Actions #6) · **Fix direction:** build the endpoints or remove the page.

### M14 — Duplicate `categories` columns (commission + active flags) can disagree
- `commission_rate` vs `commission_pct` (ledger uses `commission_rate`, matches the UI — but `commission_pct` lingers), and `status` vs `is_active`. Display drift risk. **Where:** schema; category handlers. (Audit 02 §3, Audit 05 §3) · **Fix direction:** collapse each pair to one column.

### M15 — Vendor detail shows dead GST/Bank rows; per-locality coverage hardcoded "—"
- `admin/vendors.html` references `v.gst_number`/`v.bank_account` (no such columns → never render); Category Control Room per-locality coverage cell is a hardcoded `—`. **Where:** `admin/vendors.html` L311-312, `admin/services.html` L1245-1247. (Audit 04 Engines 7 & 9) · **Fix direction:** remove dead fields or add the backing data.

---

## ⚪ LOW / V2 (features, cleanup, thin areas)

- **L1 — Vendor Panel is two competing generations.** Login lands on legacy PHP (`bookings.php`/`dashboard.php`); the richer HTML dashboards (availability/portfolio/reviews/performance) are built but unreached. Pick one. (Audit 01 §3, §5.1)
- **L2 — Thin vendor profile model.** No real photo upload (URL-paste only), no bio, single free-text service area + km radius, KYC/cert upload pilot-disabled. (Audit 01 §3)
- **L3 — `home.js` is a 3647-line monolith** carrying every customer flow — top maintainability risk. (Audit 01 §2)
- **L4 — Missing admin engines:** Company Projects (nonexistent), Global Search (no admin UI; only per-page local filters), Business Brain (backend wired to pipeline but no admin surface, leans on fallbacks/placeholder rules). (Audit 01 §4, Audit 04 Engines 5/11/12)
- **L5 — Area Intelligence & Vendor Scorecard are read-only** analytics, not control surfaces; no ranked scorecard/leaderboard. (Audit 01 §4, Audit 04 Engines 8/9)
- **L6 — Payment Control hidden from pilot nav** (`hiddenInServiceOnly`); `refunds.html` not in nav at all. Reachable only by URL. (Audit 01 §4, Audit 04 Engine 10)
- **L7 — Guest login backend has no UI entry** on any surface. (Audit 01 §1a, §5.6)
- **L8 — Three overlapping alert systems:** `api/alerts/*`, `assets/js/alerts.js`, `brain/engine/AlertEngine.php`. (Audit 01 §5.7)
- **L9 — Two settings tables:** `platform_settings` (read by cron) vs `app_settings` (public settings) — split-brain config. (Audit 02 §3)
- **L10 — `processOrderCompletion` (shopping commission) has no caller** — genuinely unwired (dormant with shopping). (Audit 05 §3)

---

## Dead / unused files & orphan DB tables (own section, per brief)

### Dead / legacy / orphan files
| Path | Why | Source |
|---|---|---|
| `swiftdeliver-main/` (entire React/TS app) | Old prototype (Rider/Vendor/Admin dashboards, `server.ts`, `db/database.ts`); not the live PHP system | A01 §5.2 |
| `admin/analytics.html` | Stub/random data, no backend route, not in nav | A01 §5.3-5.4, A04 |
| `admin/banners.html` | Superseded — merged into Category Control Room | A01 §5.3 |
| `admin/refunds.html` | Orphan — not in nav (backend controller is real) | A01 §4, A04 |
| `api/assignments/accept.php` + `auto_assignments` acceptance | Unreachable for services (cron filter H1); only shopping orders reach it | A03 §6 |
| `cron/auto_assign.php` job branch | Runs but `j.booking_id IS NULL` matches no booking; payment-gated JOIN clause dead | A03 §6, H1 |
| Vendor panel Accept/Reject/Complete buttons | Coded/wired but 403 for admin-assigned Free/Inspection (B2) | A03 §6 |
| `brain/rules/video_ranking_placeholder.php` | Placeholder rule; Brain leans on `core/FallbackResponse.php` | A01 §5.5 |
| Newer vendor HTML dashboards (`dashboard-service.html` etc.) | Built but not the login target (L1) | A01 §3 |
| Root `.md` clutter in `wtg  live/` (`FIXES_APPLIED.md`, `ROOT_CAUSE_AUDIT.md`, `FIX_VERIFICATION_REPORT.md`, `PHASE_0.2_PLAN.md`, `audit-report.md`, `AUTHENTICATED_FLOW_TEST_GUIDE.md`) | Historical, likely stale (May 2026) | A01 §5.9 |
| `LedgerEngine::runSettlement` / `processOrderCompletion` | Defined, never called (H2, L10) | A05 §3 |

### Orphan / dormant DB tables
| Table | Status | Source |
|---|---|---|
| `cms_banners` | 🔴 Orphaned — banners now write to `categories`; 0 code refs | A02 §2 |
| `heart_request_log` | 🔴 Orphaned — idempotency log never wired; 0 refs | A02 §2 |
| `warehouses` | 🟠 Orphaned for pilot (0 refs; shopping infra) | A02 §2 |
| `orders`, `order_items`, `cart`, `inventory`, `product_variations`, `deliveries`, `products` | 🟠 Dormant — shopping/delivery hidden by `SERVICE_ONLY_MODE` | A02 §2 |
| `stories`, `followers` | 🟠 Dormant — content/video engines not in pilot | A02 §2 |
| `booking_slot_reservations` | 🟡 Barely used (thin slot feature) | A02 §2 |
| `brain_*` (12 tables) | 🟡 Live-but-underused (Brain wired, leans on fallbacks) | A02 §2 |

---

## V2 fix-planning order (suggested)
1. **Unblock revenue & the core flow:** B1 (Cashfree prod), B2 (assign→jobs.vendor_id), B3 (phone auth crash).
2. **Make money safe to run:** H2 (settlement), H3 (ledger id collision), H4 (mismatch handling), H5 (booking refunds).
3. **Unify the model:** H1 + M7 + M8 + M9 (one assignment path, one vendor-category source, one booking state machine).
4. **Truth in UI:** M1–M6, M10–M15 (stop misleading admins/customers).
5. **Cleanup & features:** the LOW/V2 list + dead-file/orphan-table removal.

---
*Consolidated from Audits 01–05. No new investigation; no files modified.*
