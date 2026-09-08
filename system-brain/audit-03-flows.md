# WTG System Audit 03 — Booking Flow Trace (READ-ONLY)

> **Purpose:** Trace all 3 service booking flows end-to-end at the code level — customer "book" → API → backend → DB → admin → vendor → completion — and flag every broken or unreachable link.
> **Method:** Static code trace only. **No live execution.** Files under `wtg  live/`, branch `main`.
> **Cross-refs:** Audit 01 (system map), Audit 02 (schema — vendor-category conflict, jobs-vs-booking_id, phone-duplicate crash).
> **Date:** 2026-07-03 · READ-ONLY — nothing modified.

---

## 0. Shared spine (all 3 flows go through this)

**Customer submit:** `app/pages/home.js` → `confirmBooking()` (line 657) builds an `operationalPayload` and calls `API.createBooking(payload)`.
**API layer:** `app/js/api.js` → `createBooking()` → `_intent("service:create_booking", payload)` → `POST` to API root `{intent,data}`.
**Router:** `heart/router.php` (`service:create_booking` → engine `service`, `brain:true`) → `body/service-engine/index.php` sets `REQUEST_URI=/api/service/request`, `POST` → `body/service-engine/api/services/index.php`.
**Handler:** `POST /api/service/request` (line 1101). One handler serves **all three modes**, branching on `booking_mode`.

**What the handler always does (lines 1226–1511):**
1. `AuthMiddleware::require()` — must be logged in.
2. Resolve a `services` row (mode-specific, see below) → sets `bookings.service_id` (NOT NULL FK).
3. Duplicate gate via `client_request_id` (lines 1254–1305) — returns existing active booking with `duplicate_prevented:true` unless `force_new_booking`.
4. `INSERT INTO bookings` (line 1346) — `status='pending'`, `vendor_id = $initialVendorId` (line 1314: the vendor **only** for `direct_vendor`, else **NULL**).
5. `FcmNotifier::notifyRole(ROLE_ADMIN, 'New Booking Created')` (line 1351) — **admin always notified.**
6. If `direct_vendor` with a vendor → also `FcmNotifier::notifyUser(vendorUserId, …)`.
7. **Auto-create a linked `jobs` row** (line 1467): `status='open'`, `vendor_id = $initialVendorId`, `booking_id = bookingId`. So **every booking has exactly one job**, joined by `jobs.booking_id = bookings.id`.
8. `COMMIT`, return `booking_id`, `job_number`, `payment_data`.

**Vendor visibility (all flows):** `GET /api/service/bookings` (line 1515) scopes by role — admin sees all; `vendor_service` sees `WHERE b.vendor_id = :vid`; user sees own. The row includes `j.id AS job_id`, `j.status AS job_status` (LEFT JOIN jobs on booking_id, line 1587).

**Vendor actions (all flows):** vendor panel `vendor/bookings.php` → `API.Bookings.accept(job_id)/reject(job_id)/updateStatus(job_id,…)` → intent `vendor:update_job_status` → `PATCH /api/jobs/{id}/status` (service-engine line 2041). Ownership check (line 2062-2066): for `vendor_service`, **`resolveVendorId(user)` must equal `jobs.vendor_id`** else `403 Forbidden`. Accept → job `assigned` + booking `confirmed`; in_progress → `started_at`; completed → `completed_at` + booking `completed`; reject → job `vendor_id=NULL,status=open` + booking `pending,vendor_route=admin_queue` (requeue, lines 2069-2149).

---

## 1. FLOW A — Free Booking (`free_lead`)

| Step | Where | Result |
|---|---|---|
| Customer picks "Free booking" | `home.js` `openBooking` mode=`free_lead` | `total=0`, `payment_method=cod`, no vendor chosen |
| Submit (logged out) | `confirmBooking` 661-677 | Saves pending intent, routes to `login` → **AuthController** (see §5 known issue) |
| Create | `POST /api/service/request` | Category-routed resolution (1176-1201): picks representative active service by `c.slug`/`category_id`, **lowest `s.id`**. `vendor_id=NULL`. Job created `open`, `vendor_id=NULL`. |
| Admin sees | `admin/services.html` ops board via `GET /service/bookings` | Booking appears as unassigned, `vendor_route=admin_queue` |
| Assign | admin `PATCH /api/admin/service/bookings/{id}/assign` (admin index 1779) | Sets **`bookings.vendor_id`** only + FCM to vendor. ⛔ **does NOT set `jobs.vendor_id`** |
| Vendor accepts | `PATCH /api/jobs/{id}/status` | ⛔ **403 — see Broken Link #1** |
| Complete | job→booking status mirror | Only reachable if #1 resolved |

**Status:** 🟡 Creates & shows correctly; **acceptance chain broken after manual assign.**

---

## 2. FLOW B — ₹299 Inspection (`inspection`)

| Step | Where | Result |
|---|---|---|
| Customer picks "₹299 inspection" | `home.js` mode=`inspection` | `payment_required=true`, `payment_method=online` |
| Create | `POST /api/service/request` | Category-routed service resolution (same as Flow A). `total = servicePublicSetting('inspection_price', 299)` (line 1250) — **not** the service's base_price (explicit fix, 1243-1252). `vendor_id=NULL`, job `open`. |
| Payment session | lines 1373-1455 | Credential guard; `CashfreeClient->post('/orders')`; on success stores `bookings.payment_id`, `payment_status='unpaid'`; returns `payment_data.payment_session_id`. On failure → `payment_status='failed'`. **Booking is saved either way.** |
| Customer pays | `home.js` `_handleInspectionCheckout` → Cashfree checkout → return to `#home?payment_return=inspection&booking_id=` | Client verification state machine (`_restoreInspectionPaymentReturn`, `_checkInspectionPaymentStatus`) |
| Confirm paid | webhook `POST /api/payment/webhook` + `api/payment/return.php` + `GET /api/payment/status` → `lib/PaymentService` | Sets `bookings.payment_status='paid'` + inserts `transactions` (reference_type='booking'). ⛔ **Does NOT auto-assign a vendor or set jobs.vendor_id.** |
| Admin queues/assigns | ops actions `inspection_queued`/`coordinator_assigned`/manual `assign` (admin index 1846-1858, 1779) | Updates `bookings.lifecycle_state`/`vendor_id` only — again **no `jobs` write** |
| Vendor accepts | `PATCH /api/jobs/{id}/status` | ⛔ **403 — Broken Link #1** |

**Status:** 🟡 Creation + payment machine are the most elaborately built path; **but a live Cashfree prod failure is under active debugging** (TEMP DEBUG `Logger::error('CASHFREE_ORDER_DEBUG')` at line 1416; branch `debug/cashfree-prod-order-failure`). Post-payment assignment hits the same broken accept link.

---

## 3. FLOW C — Direct Vendor (`direct_vendor`)

| Step | Where | Result |
|---|---|---|
| Customer taps a worker card "BOOK NOW" | `home.js` `_vendorDirectFormHTML`; `resolvedServiceId` from `service_map[category.label]` or vendor's real service id; vendor-only cards send `service_id=null`+`vendor_id` | mode=`direct_vendor` |
| Create | `POST /api/service/request` 1148-1230 | Loads vendor (`status='active'`), resolves service: vendor's own active service, else **borrows** a representative service from `directVendorRow['category_id']` just to fill `service_id`, then **overrides** `service['vendor_id']=directVendorRow['id']` (line 1229). `$initialVendorId = vendor` (1314). Job created `assigned-ready`: `status='open'`, **`vendor_id = vendor`**. |
| Vendor notified | FcmNotifier::notifyUser at create (1363) | Vendor gets "New Booking Assigned" immediately |
| Vendor accepts | `PATCH /api/jobs/{id}/status` | ✅ **Works** — `jobs.vendor_id == resolveVendorId(user)`, ownership passes |
| Progress/complete | job↔booking mirror (2168-2215) | ✅ in_progress → completed propagate to booking |

**Status:** ✅ **The only fully-working end-to-end flow.** Because `jobs.vendor_id` is populated at creation, the vendor accept/execute chain authorizes correctly.

---

## 4. Broken / unreachable links (the core findings)

### 🔴 Broken Link #1 — Admin manual assign never populates `jobs.vendor_id` → vendor can't accept
- **Free + Inspection** bookings are created with `jobs.vendor_id = NULL`.
- Admin assignment (`PATCH /api/admin/service/bookings/{id}/assign`, admin index **1788-1793**, and the ops `assign_vendor`/`reassign_vendor` actions, **1833-1840**) update **`bookings.vendor_id` only** — no `UPDATE jobs`.
- The vendor then sees the booking (scoped by `bookings.vendor_id`) and taps Accept → `PATCH /api/jobs/{id}/status` → ownership check `((int)$jobRow['vendor_id'] !== $vendorId)` (line **2064**) with `jobs.vendor_id = NULL` (→0) `!== vendorId` → **403 Forbidden**.
- **Consequence:** admin-assigned service bookings cannot be accepted, started, or completed **by the vendor**. Only an admin (who bypasses the ownership check, role=admin) can move their status via `PATCH /api/admin/jobs/{id}/status`. This is exactly the Audit-02 §4b "jobs needs job_id not booking_id" fault biting the live path. **Direct Vendor escapes it** (jobs.vendor_id set at creation).

### 🔴 Broken Link #2 — Auto-assign cron excludes every booking, so the *correct* accept path is unreachable
- There IS a consistent acceptance endpoint: `api/assignments/accept.php` → sets `jobs.vendor_id` **and** mirrors `bookings.vendor_id`, `vendor_route='auto_assigned'` (lines 104-121). This path would satisfy the ownership check.
- But it only fires for offers in `auto_assignments`, which are created by `cron/auto_assign.php`. The cron's entity selection (line **67**) filters `WHERE j.status IN ('open','pending') AND j.booking_id IS NULL`. **Every service booking's job has `booking_id` set**, so **no booking job ever enters auto-assign.** The `LEFT JOIN bookings … payment_status='paid'` condition below it (line 69) is dead given that filter.
- **Consequence:** the reliable, jobs-syncing acceptance flow (`auto_assignments` + `accept.php`) is **unreachable for all 3 booking flows**; the system falls back to the broken manual path (#1). `accept.php` and the whole `auto_assignments` acceptance UI are effectively orphaned for services (only reachable by shopping `orders`).

### 🟠 Two parallel, non-agreeing acceptance systems
- **System X (reachable, broken):** vendor panel → `vendor:update_job_status` → `/api/jobs/{id}/status` → authorizes on `jobs.vendor_id`.
- **System Y (correct, unreachable):** cron `auto_assignments` → `api/assignments/accept.php` → *sets* `jobs.vendor_id`.
- Admin assign writes to neither's expected field on `jobs`. The three don't share a single assignment write path.

### 🟠 Post-inspection-payment has no assignment trigger
`PaymentService` flips `payment_status='paid'` + records the transaction but does not create an `auto_assignments` offer or set any vendor. A paid inspection therefore sits unassigned until an admin manually assigns — landing back on Broken Link #1.

---

## 5. Where the other known issues bite

- **AuthController phone-duplicate crash (Audit 02 §4c):** Every logged-out booking submit routes through `ROUTER.go("login")` (`home.js` 675) → OTP/register → `heart/api/auth/AuthController.php`. The recurring `SQLSTATE[23000] 1062 Duplicate entry '…' for key 'phone'` (seen dozens of times in the prod `logs` table, latest line ~66) means a returning phone that collides can **fail auth at the exact gate that precedes booking submit** — the customer never reaches booking creation. This is a top-of-funnel blocker for repeat/edge users.
- **Vendor-category conflict (Audit 02 §4a):** Actively *worked around* inside `POST /api/service/request`. Category-routed resolution deliberately ignores `vendors.category_id`/`vendor_categories` and resolves a service via `services.category_id` (lowest id) — a comment (1183-1200) notes this exact SQL was previously throwing an uncaught PDOException (duplicate named param) behind "every inspection/free_lead booking's silent 500." Direct-vendor borrows a category-representative service via `directVendorRow['category_id']`. So the conflict doesn't crash creation anymore, but the resolver's "pick lowest service id in category" can bind `bookings.service_id` to a **different vendor's** service row (only `vendor_id` is authoritative), which is fine for routing but makes `bookings.service_id` misleading for reporting.
- **Duplicate-prevention gate:** relies on `bookings.client_request_id` (UNIQUE). If that column were absent it falls back to a fragile `notes LIKE '%Client request ID:%'` scan (1265-1268) — works today because the column exists.

---

## 6. Coded-but-unreachable / referenced-but-inert (this trace)
- `api/assignments/accept.php` + `auto_assignments` acceptance — **unreachable for services** (Broken Link #2); only shopping orders can reach it.
- `cron/auto_assign.php` job branch — runs, but its `j.booking_id IS NULL` filter matches no real booking job; the payment-gated `LEFT JOIN bookings` clause is dead.
- Vendor panel Accept/Reject/Complete buttons — **coded and wired**, but return 403 for admin-assigned Free/Inspection bookings (Broken Link #1).
- Booking `assignment_state`/`lifecycle_state`/`vendor_response_status` columns are written in several spots but by **different** handlers with **different** vocabularies (`worker_assigned` vs `assigned` vs `vendor_accepted`), so no single consumer reads a coherent state machine (Audit 02 §3 "parallel state columns").

---

## 7. Verdict
Booking **creation** is robust and shared cleanly across all three modes, with the vendor-category crash already patched at the resolver. The failure is downstream, in **assignment→acceptance**: only **Direct Vendor** completes end-to-end, because it's the only mode that sets `jobs.vendor_id` at creation. **Free** and **₹299 Inspection** both create fine and appear to admins, but the **manual admin-assign path never writes `jobs.vendor_id`**, so the vendor's accept/execute calls are rejected 403 — and the one path that *would* set it (auto-assign cron + `accept.php`) is filtered out for every booking. Add the live Cashfree prod-order failure (inspection) and the recurring phone-duplicate auth crash (all flows, at the login gate), and the practical result is: **customers can book, admins can see, but vendors can only self-serve Direct-Vendor jobs — everything else needs an admin to hand-drive status.**

---
*Static code trace; no live requests issued; no files modified.*
