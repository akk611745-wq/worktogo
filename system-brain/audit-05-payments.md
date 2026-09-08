# WTG System Audit 05 — Payment & Revenue Path (READ-ONLY)

> **Purpose:** Trace money end-to-end: Cashfree order → checkout → webhook → status; the LedgerEngine commission engine; payment_status states; and where money can be lost, double-counted, or left inconsistent.
> **Method:** Static code trace. No live execution. Files under `wtg  live/`.
> **Cross-refs:** Audit 02 (schema — commission column duplicates, polymorphic refs), Audit 03 (assignment broken links), Audit 04 (admin engines).
> **Date:** 2026-07-03 · READ-ONLY.

---

## 0. Where money actually enters the system

**Only one real inflow exists in the service pilot: the ₹299 inspection fee.**
- `free_lead` and `direct_vendor` bookings are created with **`total = 0`** and `payment_method='cod'` (service-engine `services/index.php` L1250: `$bookingTotal = inspection ? inspection_price : 0.0`). The real service work is paid vendor↔customer **off-platform** (cash/COD) — the platform never captures it.
- `inspection` bookings are created with **`total = servicePublicSetting('inspection_price', 299)`**, `payment_method='online'` → routed to Cashfree.
- Shopping `orders` (dormant under `SERVICE_ONLY_MODE`) are the other Cashfree path via `PaymentService::createOnlinePaymentOrder`.

So the platform's *only* live revenue touchpoint is the ₹299 inspection charge.

---

## 1. Cashfree integration trace

### Config — `config/payment.config.php`
- Loads `CASHFREE_APP_ID/SECRET_KEY/ENV` from `.env`. `CASHFREE_API_BASE` = `api.cashfree.com/pg` (PRODUCTION) or `sandbox.cashfree.com/pg`. API version pinned `2023-08-01`.
- Missing keys **log a warning, don't die** — payment calls degrade to 503 instead of crashing non-payment routes.

### Low-level client — `lib/CashfreeClient.php`
- Signs every request with `x-client-id` / `x-client-secret` / `x-api-version`. cURL with SSL verify on, 30s timeout. Returns `['success','data','error','http_code']`. Logs errors server-side without leaking secrets.

### ⚠ TWO separate order-creation code paths (drift risk)
1. **Inspection bookings** — created **inline** in `body/service-engine/api/services/index.php` L1373-1455 (`new CashfreeClient(); $cfClient->post('/orders', …)`). Hardcodes `customer_email='support@worktogo.com'`, builds `return_url=/app/#home?payment_return=inspection&booking_id=…`, `notify_url=/api/payment/webhook`. Order id = `WTG_B{bookingId}_{time}`.
2. **Shopping orders** — `lib/PaymentService::createOnlinePaymentOrder()` L39-121. Order id = `WTG_{orderId}_{time}`. Server-side amount check against `orders.total`.

Both post to `/orders` and both are reconciled by the same webhook (which checks orders **then** bookings). The duplication means fixes/guards applied to one path (e.g. the credential guard, the TEMP DEBUG logging) don't automatically apply to the other.

### Checkout → return → status
- Customer completes on Cashfree hosted page → redirected to **`api/payment/return.php`**. This file **explicitly does NOT trust return params** — it only reads `payment_status` from the DB and shows a status screen (comment L9-11). ✅ Correct posture.
- SPA then polls **`api/payment/status.php?booking_id=`** — read-only, ownership-checked (`row.user_id === currentUser`), maps `unpaid→pending`. Never writes. ✅
- **Authoritative "mark paid" happens in exactly one place: the webhook.**

### Webhook — `api/payment/webhook.php` → `PaymentService::processWebhook`
- `webhook.php`: rejects non-POST (405), verifies signature via `WebhookVerifier::verify($rawBody, signature, timestamp)` and **401s on failure before any DB op** (L43-48). ✅
- `processWebhook` (PaymentService L169-318):
  - Extracts `order_id/order_status/order_amount/payment_status/cf_payment_id`.
  - `fetchPaymentTargetForUpdate` locates the target by `payment_id` (orders first, then bookings) `FOR UPDATE`.
  - **Idempotent:** if already `paid`, re-ensures the `transactions` row + re-runs booking propagation, then returns "Already processed."
  - **Server-side amount check** (L210): if webhook amount ≠ DB total → sets `payment_status='failed'`, **commits**, returns "flagged for review."
  - `mapCashfreeStatus`: `SUCCESS/PAID→paid`, `FAILED/USER_DROPPED/CANCELLED→failed`, `FLAGGED→unpaid` (manual review), default `unpaid`.
  - On `paid`: `ensureSuccessfulTransaction` inserts a `transactions` row (idempotent, `status='success'`, `gateway='cashfree'`); for bookings, `syncPaidBookingPropagation`.
  - On `failed`: inserts a `payment_failure` alert.
  - `order` + paid also fires SwiftDeliver dispatch (delivery — dormant).

### `syncPaidBookingPropagation` (PaymentService L480-513)
On a paid inspection: sets booking `lifecycle_state='inspection_paid'`, recomputes `assignment_state`/`vendor_response_status` from whether `vendor_id` is set. **Only sets `jobs.vendor_id` if the booking already has a vendor** (L509: `!empty($booking['vendor_id'])`). In the normal inspection timeline (pay first, admin assigns later) `vendor_id` is still NULL at webhook time, so this does **not** repair Audit-03 Broken Link #1 — it only helps if admin pre-assigned before payment cleared.

---

## 2. The active CASHFREE_ORDER_DEBUG prod failure

- **Where:** inline inspection order creation, `services/index.php` L1415-1425 — a `Logger::error('CASHFREE_ORDER_DEBUG', […])` dumps `env, api_base, http_code, success, error, data` from `$cfClient->post('/orders')`. Branch `debug/cashfree-prod-order-failure` (and `debug/cashfree-prod-order-failure` in git list) is investigating.
- **What fails:** the `POST /orders` call to Cashfree returns non-success in **PRODUCTION** (`CASHFREE_ENV=PRODUCTION`, base `api.cashfree.com/pg`). The code path on failure (L1448-1453): catches, sets `payment_data=['success'=>false,…]`, `UPDATE bookings SET payment_status='failed'`, `$paymentStatus='failed'`.
- **Effect on money flow:** the booking row **is still saved** (created before the payment attempt), but with `payment_status='failed'` and no `payment_session_id`. The customer **cannot check out** → no inspection revenue. The most likely root causes visible in code are credential/env mismatch (the guard at L1381-1389 only blocks literal `placeholder`/`your_` app-ids, not a wrong-but-real key) or a payload rejection (e.g., `customer_email` hardcoded, phone fallback `9999999999`). The debug logging exists precisely because the exact Cashfree error wasn't yet captured — **this is an open, unresolved production failure blocking all inspection payments.**

---

## 3. LedgerEngine (commission engine) — IS it wired?

**Yes — for bookings it is genuinely wired; for orders and settlement it is not.**

`core/helpers/LedgerEngine.php` exposes: `processOrderCompletion`, `processBookingCompletion`, `runSettlement`, `processRefund`, `isDriverBlocked`.

| Method | Wired into a live trigger? | Where |
|---|---|---|
| `processBookingCompletion` | ✅ **Yes** | Fires when a booking's job hits `completed`: service-engine `PATCH /api/jobs/{id}/status` (L2233-2235) **and** admin `PATCH /api/admin/jobs/{id}/status` (L1903-1904). |
| `processRefund` | ✅ Yes | `core/controllers/RefundController.php` L262. |
| `processOrderCompletion` | 🔴 **No call site** anywhere (grep: only defined, never invoked). Shopping-order commission never runs (dormant, but genuinely unwired). |
| `runSettlement` | 🔴 **No call site** anywhere. **Nothing ever moves `pending_balance → available_balance`.** No cron, no admin button. |
| `isDriverBlocked` | referenced in delivery paths (dormant). |

### What triggers a commission calculation (bookings)
Only **booking completion** (`bookingStatus==='completed'`). `processBookingCompletion` (L145-215):
- Reads `bookings.total`, `v.commission_rate`, and `categories.commission_rate` (via `services.category_id`).
- **Rate priority:** `category.commission_rate` (explicit non-null) → `vendor.commission_rate` → `10%` default.
- `platform_commission = total * rate/100`; `vendor_earning = total − commission`.
- Credits `vendor_wallets.pending_balance += vendor_earning`; inserts `wallet_transactions` (`vendor`, status `pending`) + (`platform`, status `settled`).
- **Idempotent** via a `wallet_transactions WHERE order_id=? AND entity_type='vendor'` check.

### Commission-column check (was flagged as risk in Audit 02)
Audit-02 worried the admin UI wrote `commission_pct` while the ledger reads `commission_rate`. **Verified here: no mismatch in the money path.** The Category Control Room editor (`admin/services.html` L1680-1725) sends **`commission_rate`**, and `processBookingCompletion` reads `categories.commission_rate` — they agree. `commission_pct` (default 10.00) is a **dormant duplicate column** (the admin PATCH handler accepts both keys, L893) that is *not* used by the ledger; risk is display drift elsewhere, not miscalculation.

### ⚠ Semantic concern: the ₹299 inspection fee is split like vendor revenue
When an inspection booking completes, `total=299` runs through the same commission split → **vendor is credited ~₹269 (90%)** and platform ₹30 (10%). If the ₹299 is meant as a *platform diagnostic fee* (not vendor earnings), this silently hands most of it to the vendor's wallet. Flagged as a business-logic mismatch to confirm, not a crash.

---

## 4. `payment_status` states across the 3 flows

**Schema enums:** `bookings.payment_status = enum('unpaid','paid','failed','refunded')`; `orders` adds `'partially_paid'`. `mapCashfreeStatus` only ever emits `paid/failed/unpaid` — within the bookings enum. ✅ No out-of-enum writes.

| Flow | payment_method | total | payment_status lifecycle |
|---|---|---|---|
| **Free (`free_lead`)** | cod | 0 | **`unpaid` forever** — never transitions (nothing to charge). |
| **Direct Vendor** | cod | 0 | **`unpaid` forever** — same. |
| **₹299 Inspection** | online | 299 | `unpaid` → (webhook) `paid` / `failed`; `refunded` on refund. **Only flow with real state transitions.** |

**Consistency problems from this:**
- `payment_status='unpaid'` is **overloaded** — for Free/Direct it means "no charge exists," for Inspection it means "awaiting payment." Consumers can't tell them apart from the column alone.
- **Follow-up "Payment Pending" false positives:** `admin/followup.html renderPaymentPending()` excludes only `free_lead` (`if (mode==='free_lead') return false`), then flags any non-paid booking. **`direct_vendor` bookings (total 0, `unpaid`) therefore appear as "Payment Pending"** even though nothing is owed. Cosmetic/ops-confusion, not lost money.

---

## 5. Where money can be lost, double-counted, or stranded

1. 🔴 **Settlement never runs.** `runSettlement()` has **no caller**. `vendor_wallets.pending_balance` accumulates on every completed booking and is **never** moved to `available_balance`. The dashboard "Pending Vendor Payout" grows without bound; vendors can never be marked paid out through the system. (Real payouts, if any, happen off-ledger.)
2. 🔴 **`wallet_transactions.order_id` is overloaded to also hold `booking_id`.** `processBookingCompletion` writes the **booking id** into the `order_id` column (L201-206), and `processRefund(order_id)` selects by `order_id`. **Booking #N and Order #N share the same `wallet_transactions` rows** → cross-domain collision: refunding order #5 could touch booking #5's ledger entries (and vice-versa), and the idempotency check keys on the same overloaded id. Real double-count / wrong-reversal risk if both id spaces are non-trivially populated. (Mitigated today only because shopping orders are dormant.)
3. 🟠 **Amount-mismatch webhook commits `failed` on captured money.** If Cashfree reports `paid` but `order_amount ≠ bookings.total`, the code sets `payment_status='failed'` and **commits** (L212-214) with no auto-refund — the customer's money was captured at Cashfree but the booking reads failed. Left for manual review; money sits captured-but-unreconciled.
4. 🟠 **Paid-but-never-completed inspection has no booking refund path.** On `paid`, a `transactions` (success, ₹299) row is created, but `wallet_transactions` are only written at **completion**. If a paid inspection is cancelled before completion, there is a captured `transactions` row and **no `wallet_transactions` to reverse**; `processRefund` is order/`wallet_transactions`-oriented and `RefundController` is the only refund entry — a paid inspection that never completes can strand the ₹299 with no automatic reversal.
5. 🔴 **Inspection revenue currently blocked in prod** (§2) — order creation fails, so the one real revenue stream isn't being collected at all right now.
6. 🟠 **₹299 mostly credited to vendor** (§3 semantic concern) — potential revenue leak depending on intent.
7. 🟠 **Two order-creation code paths** (§1) can drift — e.g. the inline inspection path lacks `PaymentService`'s stricter amount-echo and carries hardcoded customer email/phone fallbacks.
8. 🟢 **Good:** webhook signature verification, idempotency (both `transactions` and `wallet_transactions` guarded), `FOR UPDATE` locking, return/status pages that never self-authorize payment. The *verification* layer is solid; the *allocation/settlement* layer is where the gaps are.

---

## 6. Verdict
The **gateway-facing half is well built** — signed client, signature-verified idempotent webhook, no trust of return params, server-side amount checks, row locking. The **revenue/ledger half is half-wired**: `processBookingCompletion` genuinely fires on completion and computes commission correctly (commission_rate agrees end-to-end), but **`runSettlement` and `processOrderCompletion` have no callers**, vendor earnings are frozen in `pending_balance` forever, `wallet_transactions.order_id` dangerously conflates booking and order id-spaces, and the sole live revenue line (₹299 inspection) is **currently failing at order creation in production** and — when it does work — is split 90/10 to the vendor, which may not be intended. Net: money can be *collected* safely but cannot yet be *settled* cleanly, and today it largely isn't being collected at all.

---
*Static payment-path trace; no live requests; no files modified.*
