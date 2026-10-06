# WorkToGo — Money & Earnings Flow (the precise one)

> **What this is:** Exactly *when the system takes money, when and how much the vendor earns, and how each job shape settles* — grounded line-by-line in `core/helpers/LedgerEngine.php` and the booking/assignment code. Plus **bypass prevention** (how a vendor/customer is stopped from going off-platform) and **project-to-existing-vendor** assignment. This is the doc to get right; the founder said money matters most.
> **Companions:** `category-job-flows.md` (the walkthroughs), `predicted-problems.md` (what breaks). **READ-ONLY — nothing built or changed. Identify only.**
> **Grounded in:** `core/helpers/LedgerEngine.php`, `body/service-engine/api/services/index.php`, `body/service-engine/api/vendors/index.php`, `cron/auto_assign.php`, `core/helpers/ExpertVisitPricing.php`, `core/helpers/PaymentReport.php`, `migrations/2026_08_15_001_vendor_accountability.sql`, `lib/PaymentService.php`, `api/payment/webhook.php`.
> **Date:** 2026-09-09.

---

## 0. The money model in one screen

**Two kinds of money move through WorkToGo at launch:**

| Money | Channel at launch | Who ends up with it | Booked in ledger as |
|---|---|---|---|
| **Expert-visit / inspection fee** (e.g. ₹299, or FREE) | **ONLINE** (Cashfree, pay-to-confirm) | **100% company** — vendor gets 0% | one `platform` row, `settled` (`LedgerEngine.php:290–300`) |
| **Actual job payment** (repair, painting, etc.) | **CASH** customer→karigar (online is future) | vendor keeps cash; **owes platform its commission** | `vendor` credit `pending` + `platform` commission `settled` (`LedgerEngine.php:302–325`) |

**The wallet (verified schema in use):** `vendor_wallets(pending_balance, available_balance)`; every movement is a `wallet_transactions` row tagged `reference_type` + `reference_id` (`'booking'` vs `'order'`) so a service booking #N can never collide with a commerce order #N (the H3 fix, `LedgerEngine.php:34–129`).

**The lifecycle of a vendor rupee:**
```
  job completed ──▶ pending_balance (credit, status='pending')
        │ admin runs settlement (never automatic)
        ▼
  available_balance (status='settled')   ── "ready to pay out" (internal state only)
        │ actual bank payout
        ▼
  [NOT BUILT] — no payout-gateway step; settlement is a ledger state change, not a bank transfer
```
(`LedgerEngine::runSettlement` docblock, lines 336–354, says this explicitly.)

---

## 1. Commission split — the exact formula (normal job revenue)

From `LedgerEngine::processBookingCompletion()` (lines 302–325), fired when a job is marked `completed` (`services/index.php:2460`, admin `admin/index.php:2960`):

```
commission_rate = category.commission_rate   (if set, even if 0)
                ↳ else vendor.commission_rate (if set)
                ↳ else 10.0   (hard default)
platform_commission = total × commission_rate / 100
vendor_earning      = total − platform_commission
```
- `vendor_wallets.pending_balance += vendor_earning`; a `wallet_transactions` **vendor credit** row, `status='pending'` (line 319–322).
- `platform_commission` → a **platform credit** row, `status='settled'` (line 325).
- **Idempotent + transactional:** `FOR UPDATE` lock + "any non-refunded ledger row for this booking already exists?" check (lines 280–286) → a re-run is a safe no-op.
- **Commission precedence is per-category-first** — so a category-head can set painting at (say) 15% and AC at 8% via `categories.commission_rate`, and it wins over the vendor's own rate. This is the per-category economics lever, already wired.

> **When the vendor "earns":** the credit is booked **at job completion**, into **pending**. It becomes **available** only when **admin runs settlement** (`runSettlement`, manual, `admin/index.php:2464`). Both pending and available are visible to the vendor via `GET /api/vendor/earnings` → `PaymentReport::vendorRevenue` (`vendors/index.php:339`). So the vendor *sees* the earning immediately as "pending," and "available" after settlement.

---

## 2. Per-shape money mechanics (the founder's table)

For each job shape: **when money is taken · when/how-much the vendor earns · when it shows in Earnings · how cash is reconciled.**

### 2.1 Fixed (AC ₹499, plumber ₹149, appliance ₹299…)
- **Money taken:** at launch, **cash** from customer to karigar on completion (no online step wired for the job itself; `booking.total` = `services.base_price`, `payment_method`/`payment_status` default to the cash/pending path at creation, `services/index.php:1493`).
- **Vendor earns:** `total − commission` (§1), booked to **pending** at completion.
- **Shows in Earnings:** immediately as pending; available after admin settlement.
- **Cash reconciliation:** ⚠️ **this is the gap.** The vendor physically holds the whole ₹499. The ledger has booked the platform's commission as `settled` **platform revenue that the platform never actually received** — the vendor owes it. **There is no service-vendor `cash_in_hand` / amount-owed tracking** (only *driver* wallets have `cash_in_hand` + `collection_limit`, `LedgerEngine.php:213–217, 623–634`). See §5 and predicted-problems P-1.

### 2.2 Package per-sqft (painting ₹18/sqft × 500 = ₹9,000)
- **Money taken:** cash on completion at launch; `total` derives from the server-recomputed `estimate_amount` (or the vendor's final figure). Package selection is structured (`package_id`, `area_sqft`, `estimate_amount`, `migrations/2026_07_04_006`).
- **Vendor earns / shows / reconciles:** exactly as Fixed (§1) once `total` is set — same split, same pending→available, **same cash-reconciliation gap.**
- **Note:** the estimate is not a payment; nothing is charged when the customer ticks a package (ticking is optional, no forced payment).

### 2.3 Hourly / gig (maid ₹60/hr × 3) — ⛔ NOT SUPPORTED
- **Money mechanics:** none — there is **no hourly field** (`rate_per_hour`, `hours`/`units` absent; structure-lock GAP-1). Cannot be metered or split. **Launch workaround:** sell as a **fixed package** ("2BHK deep clean ₹1,499"), which then settles exactly as §2.1. True hourly settlement is deferred.

### 2.4 Expert-visit / inspection fee (₹299 or FREE)
- **Money taken:** **ONLINE, up front**, pay-to-confirm — the visit is **confirmed only after payment** (Cashfree via `lib/PaymentService.php`; webhook `api/payment/webhook.php` is the sole authority). Fee resolved by `ExpertVisitPricing::resolve(cat, city)` (can be FREE → no charge).
- **Vendor earns:** **₹0 from the fee.** `LedgerEngine::isExpertVisitFee()` (lines 91–95, detects `booking_mode='inspection'` OR `visit_type='expert_visit'` OR `expert_visit_fee>0`) routes the **entire fee to a single `platform` `settled` row, no vendor credit, no pending** (lines 290–300). This is the founder rule ("the diagnostic fee is the company's"), and the fix for the old 90%-to-vendor leak (Audit 05 §3).
- **Reconciliation:** clean — the company received it online; nothing owed by anyone.
- **The subsequent job** (if the customer proceeds after the quote) is a **separate normal split** on its own `total` (§1).

### 2.5 Project / quote (full wiring, waterproofing, packers)
- **How price locks:** runs **through the expert-visit path** — visit → the expert enters `final_quote_amount` (the real negotiated number). There is **no structured customer "accept quote" / milestone / staged-payment flow** (structure-lock GAP-4).
- **Money taken:** the visit fee online (as §2.4, 100% company); the **project amount itself is cash on completion** at launch, booked once at completion as a normal split on `total` (§1).
- **Vendor earns:** `final total − commission`, to pending at completion. **No progress payments** — it's one credit at the end, which for a multi-day project is a real limitation (P-9).
- **Reconciliation:** same cash gap as §2.1, and larger amounts → the owed-commission exposure is bigger (P-1 is worst here).

### 2.6 Company-managed / free-lead (general request, no package)
- **Money taken:** **nothing up front** (`free_lead` carries no payment — the "never fear extra charge" rule). Price is agreed after admin/vendor contact; job payment is cash on completion.
- **Vendor earns / reconciles:** as §1 once a `total` exists. If the request is abandoned before a job/total, no ledger entry is ever made (safe).

---

## 3. Where the money numbers surface (admin + vendor)

- **Vendor Earnings** — `GET /api/vendor/earnings` → `PaymentReport::vendorRevenue` (IDOR-safe: vendor id from JWT, `vendors/index.php:339–344`). Shows pending + available + effective commission rate.
- **Admin settlement** — `POST` settlement action → `LedgerEngine::runSettlement($vendorId|null)` (`admin/index.php:2464`), pending→available, `FOR UPDATE`, idempotent.
- **Admin revenue reporting** — `PaymentReport` / Payment Room (V2-VISION §6.1 — the per-category P&L and online-vs-cash split is a *target*, partially wired; the cash side depends on §5 being built).

---

## 4. What is SOLID vs SHAKY in the money path (honest verdict)

| Piece | Verdict | Evidence |
|---|---|---|
| Commission split (category>vendor>10%) | ✅ solid, idempotent, transactional | `LedgerEngine.php:302–325`, `FOR UPDATE` + dedup |
| Expert-visit fee = 100% company | ✅ solid (leak fixed) | `isExpertVisitFee` + platform-only row, 290–300 |
| Booking/order ledger collision | ✅ fixed (reference_type/id) | H3, lines 34–129 |
| pending→available settlement | ✅ built, but **manual-only**, and **not a bank payout** | `runSettlement` 336–410; no payout gateway |
| Refund reversal | ✅ for orders; 🟡 bookings lack a first-class refund path | `processRefund` is order-shaped (416–502); V2-VISION §9 Step-1 flags booking refunds |
| **Service cash reconciliation (COD)** | ❌ **not modelled** — no vendor `cash_in_hand`/owed | only `driver_wallets.cash_in_hand` exists (213–217, 623–634) |
| Online job payment (not just visit fee) | 🟡 gateway exists; **service job pay-online not wired** (cash at launch) | Audit 05 §0; only inspection fee is online today |
| Cashfree prod order (the online path) | ⚠️ **launch blocker B1 open** | V2-VISION §9 Step-0; gates all online (incl. expert-visit) |

> **One-line money verdict:** the **split logic and the online expert-visit fee are correct and safe**; the **cash job economics are booked but not reconciled** (the platform's commission on a cash job is recorded as revenue it hasn't actually collected, with no owed-balance to chase). That is the single most important money gap to close, and it is **identify-now, fix-later** per the founder's instruction.

---

## 5. The service-cash reconciliation gap (most important — identify only)

**Symptom:** on a cash job, the customer pays the karigar the full amount. `processBookingCompletion` books `platform_commission` as **settled platform revenue** and `vendor_earning` as **vendor pending** — but the vendor is holding *all* the cash, including the platform's commission. Nothing tracks that the vendor **owes** the platform that commission.

**Why it matters:** at scale, "platform revenue" in the ledger overstates real collected cash; there is no balance that says "vendor X owes ₹Y in uncollected commission," and no throttle when that owed amount grows — the exact discipline `driver_wallets` has (`cash_in_hand` vs `collection_limit`, `isDriverBlocked`) but **service vendors lack**.

**Where it'd be fixed (cite system-map):**
- A vendor-side cash/owed ledger mirroring the driver model — add `vendor_wallets.cash_in_hand` + `collection_limit` and, on a **cash** booking completion, credit the owed commission there (parallel to `LedgerEngine.php:213–217`). Money helpers live in `core/helpers/LedgerEngine.php` + `core/helpers/PaymentReport.php` (system-map §C "Commission / payout / settlement").
- Enforce it in assignment: the `work_throttle` hook already exists (`auto_assign.php:90–98`) — a future `isVendorBlocked()` (cash over limit) can de-prioritise/pause offers, same as drivers.
- Longer term, the founder's own answer is **online job payment** (route the job money through `lib/PaymentService.php` so the platform nets its commission automatically) — the gateway abstraction is already built; only the service-job online charge is unwired.

**Not fixing now** — this is the headline entry for the single fix-round.

---

## 6. Bypass prevention — exactly how off-platform is stopped (verified)

The V2-VISION §5 strategy is **value-first + guardrails**. What is actually built:

| Guardrail | Built? | Evidence |
|---|---|---|
| **Contact hidden until job starts** | ✅ **built** | vendor sees `customer_phone = NULL` unless job `in_progress`/`completed` (`vendors/index.php:284–286, 323–325`). |
| **Vendor phone never exposed publicly** | ✅ built | public vendor discovery omits `owner_phone/owner_email` (H1, `vendors/index.php:184–188`). |
| **Every outcome tracked** | ✅ built (derived) | `jobs` (completed/cancelled/stalled) + `auto_assignments` (accepted/rejected/timeout) are the outcome signals (`VendorAccountability.php:7–14`). |
| **Auto-flag repeat bypassers/non-closers** | ✅ **detection built**, tunable | `VendorAccountability::evaluate()` (rule engine, `.php:84+`); thresholds in `app_settings` (`migrations/2026_08_15_001`): completion-rate, decline-rate, stalled-count, windows — all admin-tunable, zero-hardcoded. |
| **Throttle/stop work to bad vendors** | 🟡 **hook only, not enforced** | `vendors.work_throttle` (`normal|reduced|paused`) is read by `auto_assign.php:90–98` (paused skipped, reduced de-prioritised) — **but** normal booking→job assignment is admin-driven, so throttle only bites the auto-assign path today. `manual_flag`/`flag_notes` exist for admin action. |

**Remaining bypass risk (identify, don't fix):**
1. **The reveal is necessary-but-permanent.** Once a job hits `in_progress`, the phone is shown — a vendor *can* still take the next job off-platform. Mitigation is the value-first hook + accountability flag, not a hard lock (by design). **Risk: real but strategically accepted.** (P-11)
2. **Cash job = the platform can't see the money.** Because the job is paid in cash off-platform (§5), a vendor can under-report or complete off-book. Contact-hiding delays the first handoff but doesn't stop a repeat customer going direct next time. **This is why §5 (online job payment / cash-owed tracking) is the real long-term anti-bypass lever, not just contact-hiding.** (P-1, P-11)
3. **Throttle not enforced on the admin-assign path.** A flagged/paused vendor can still be manually assigned. **Where:** wire the `work_throttle`/flag check into the admin assign path (`heart/api/admin/index.php` assign) the way `auto_assign.php` already does. (P-12)
4. **Direct-vendor route pre-reveals less, but customer picks the vendor** — the vendor's *business* is shown (rating, name) so a determined customer could search them out; number still withheld till start. Low risk. 

---

## 7. Project → same nearby vendors (money + assignment confirmation)

Confirmed (also in `category-job-flows.md §3`): a project/managed booking (`inspection`/`free_lead` → `admin_queue`) is assigned from the **same `vendors` pool** — category via `vendor_categories`, area via `ServiceVendorEligibility::localityCovered()`, scored by `evaluate()`; `jobs.vendor_id` synced by the B2 fix so the karigar can accept. The **same nearest-vendor engine** (`auto_assign.php:156–171`, haversine, online+active) backs the auto path. **No separate project-sourcing pipeline** — quick jobs and big projects draw from one registered karigar pool. Money then settles per §2.5.

---

*Companion to `category-job-flows.md` and `predicted-problems.md`. Every money claim traced to `LedgerEngine.php` and the booking/assignment code. Documentation only — nothing changed, nothing deployed.*
