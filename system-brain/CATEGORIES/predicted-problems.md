# WorkToGo — Predicted Problems (the founder's "predict 95%" list)

> **What this is:** An honest, ranked catalogue of the real-world problems that *will* arise once the launch categories go live — per category, in the money flow, and in vendor/customer behaviour. The founder's principle: **predict 95%, adjust the 5% — never get stuck and shut down.** So every item is written *before* launch, with (a) the symptom, (b) **whether the built system handles it today** (grounded in real code), and (c) **where it'd be fixed** (cited to system-map / real files) for the single fix-round later.
> **This doc does NOT fix anything.** Identify only, as instructed.
> **Companions:** `category-job-flows.md`, `money-and-earnings-flow.md`, `structure-lock.md`. **Date:** 2026-09-09.

**Legend — Handled today:** ✅ fully · 🟡 partly / manual workaround exists · ❌ not handled. **Severity:** 🔴 launch-critical · 🟠 important · 🟡 minor.

---

## 0. The ranked register (scan this first)

| # | Problem | Area | Sev | Handled today | Fix location (short) |
|---|---|---|---|---|---|
| **P-1** | **Cash job commission not collected / no vendor "owes" balance** | Money | 🔴 | ❌ | `LedgerEngine` + `vendor_wallets` — add vendor cash_in_hand/owed (money doc §5) |
| **P-2** | **Cashfree prod order fails → no online payment** (blocker B1) | Money | 🔴 | ❌ | `lib/PaymentService`/`CashfreeClient` + prod creds (V2-VISION §9 Step-0) |
| **P-3** | Extra material/parts mid-job priced off-platform | Money/Ops | 🟠 | 🟡 (informal cash) | commerce/material deferred; interim: quote field on job |
| **P-4** | **No vendor available in area** (esp. off-peak / hill localities) | Ops | 🔴 | 🟡 | `auto_assign.php:186–201` cancels + alerts; admin manual fallback |
| **P-5** | Vendor accepts then doesn't show / stalls | Vendor | 🟠 | 🟡 (flag only) | `VendorAccountability` flags; throttle not enforced on admin path |
| **P-6** | Wrong route: a project booked as a ₹149 direct call (or vice-versa) | Ops | 🟠 | 🟡 | admin re-route; UX guardrails in `home.js` intake |
| **P-7** | Wrong-skill / multi-category vendor sent | Vendor | 🟠 | ✅-ish | `vendor_categories` canonical + `ServiceVendorEligibility` |
| **P-8** | Price dispute — final bill ≠ estimate (painting sqft, prep, coats) | Money/Cust | 🟠 | 🟡 | `estimate_amount` vs `final_quote_amount` captured; no dispute flow |
| **P-9** | Project scope-creep / no milestone or progress payment | Money/Ops | 🟠 | ❌ | Company Projects module (structure-lock GAP-4) |
| **P-10** | Expert-visit abuse — customer wants free diagnosis only | Money/Cust | 🟠 | 🟡 | paid pay-to-confirm deters; fee 100% company covers cost |
| **P-11** | Bypass after first job (repeat customer goes direct) | Bypass | 🟠 | 🟡 (by design) | value-first + accountability; online-pay is the real lever |
| **P-12** | Flagged/paused vendor still gets manual assignment | Bypass/Vendor | 🟠 | ❌ | wire `work_throttle`/flag into admin assign (money doc §6) |
| **P-13** | No warranty / rework tracking after completion | Ops/Cust | 🟠 | ❌ | new field on `bookings` + re-open flow |
| **P-14** | AMC / recurring service (RO, pest) not modelled | Ops | 🟡 | ❌ | recurrence + reminder (reuse `cron/agent_reminders.php`) |
| **P-15** | Customer no-pay / disappears (cash job) | Money/Cust | 🟠 | 🟡 | outcome tracked; no enforcement (cash off-platform) |
| **P-16** | Hourly categories (maid) can't be billed | Money | 🟡 | ❌ | hourly/units field (structure-lock GAP-1/2) |
| **P-17** | Booking refund has no first-class path | Money | 🟠 | 🟡 | `processRefund` is order-shaped; add booking refund (V2-VISION §9 Step-1) |
| **P-18** | Settlement is manual + not a real bank payout | Money | 🟠 | 🟡 | `runSettlement` internal-only; add payout gateway |
| **P-19** | Duplicate / accidental double booking | Ops | 🟡 | ✅-ish | `client_request_id` idempotency on create |
| **P-20** | Locality mismatch (same-named area, hill geography) | Ops | 🟠 | 🟡 | `localityCovered()` text-match; city-scoped, not geo-fenced |
| **P-21** | Vendor offline / low supply at peak (winter geyser, summer AC) | Ops | 🟠 | 🟡 | `is_online` gating; Area Intelligence read-only |
| **P-22** | Fake/low-quality vendor onboarded | Vendor/Trust | 🟠 | 🟡 | `is_verified`, rating; no hard verification gate |
| **P-23** | Notification not delivered (vendor misses offer) | Ops | 🟠 | 🟡 | in-app `alerts` written; FCM push gap in `auto_assign.php` |
| **P-24** | COD reconciliation for staff/field cash collection | Money | 🟠 | ❌ (service) | staff cash reporting (V2-VISION §6.1) |

24 predicted problems. **Handled today: ✅/✅-ish = 3 · 🟡 partial = 14 · ❌ not handled = 7.** Launch-critical (🔴) = 3 (P-1, P-2, P-4), all already known in the audits/V2-VISION.

---

## 1. Money problems (most important — detail)

### P-1 — Cash job commission not collected 🔴 ❌
- **Symptom:** customer pays karigar ₹499 cash; platform's (say 10%) ₹49.90 commission is *booked as settled platform revenue* but is physically in the vendor's pocket, with **no balance recording that the vendor owes it** and no cap forcing remittance.
- **Handled today:** ❌. Service vendors have **no `cash_in_hand`/`collection_limit`** — only *drivers* do (`LedgerEngine.php:213–217, 623–634`).
- **Where fixed:** add a vendor cash/owed ledger mirroring the driver model in `core/helpers/LedgerEngine.php` + `vendor_wallets`; enforce via the existing `work_throttle` hook (`auto_assign.php:90–98`). Long-term: online job payment through `lib/PaymentService.php` (gateway already built). See money doc §5. **The #1 fix-round item.**

### P-2 — Cashfree prod order fails 🔴 ❌
- **Symptom:** the one online revenue stream (expert-visit fee, pay-to-confirm) fails at order creation in prod → no Company-Service confirmation.
- **Handled today:** ❌ — open blocker **B1** (V2-VISION §9 Step-0).
- **Where fixed:** `lib/PaymentService.php` / `lib/CashfreeClient.php` + prod credentials; validate on staging with a real ₹1 order (`LAUNCH/server-day-checklist.md`). Note: Wave-1 direct fixed-price (cash) is unaffected — only the paid expert-visit path is gated.

### P-3 — Extra material/parts priced off-platform 🟠 🟡
- **Symptom:** AC gas refill, geyser element, RO membrane, paint — real cost the customer pays beyond the service price.
- **Handled today:** 🟡 informal cash at launch (material deferred by design, `category-research.md §6`).
- **Where fixed:** future Commerce Engine (material); interim, a "parts/extra" amount field on the job so it enters `total` and the split. Identify only.

### P-8 — Price dispute (bill ≠ estimate) 🟠 🟡
- **Symptom:** painting estimate ₹9,000 (500 sqft × ₹18) but real bill ₹11,000 (prep, extra coat) → customer feels cheated.
- **Handled today:** 🟡 — both numbers are captured (`estimate_amount` vs `final_quote_amount`, `migrations/2026_07_04_006/008`), so the gap is *visible*, but there's **no in-app dispute/approval step**.
- **Where fixed:** a customer "approve revised amount" action on the booking (service-engine); admin followup already exists for escalation (`admin/followup.html`).

### P-15 — Customer no-pay (cash) 🟠 🟡 · P-17 — booking refund 🟠 🟡 · P-18 — settlement manual/no payout 🟠 🟡 · P-24 — staff cash reporting 🟠 ❌
- See money doc §4 table. All are money-path items already flagged in V2-VISION §9 Step-1 / §6.1. Refund reversal exists for **orders** (`LedgerEngine::processRefund`) but is order-shaped; bookings need a first-class refund. Settlement (`runSettlement`) is an internal pending→available move, **not** a bank payout. Fix location: `core/helpers/LedgerEngine.php` + Payment Room.

---

## 2. Operational / assignment problems

### P-4 — No vendor in area 🔴 🟡
- **Symptom:** off-peak, or a thin hill locality, has no online karigar for the category.
- **Handled today:** 🟡 — auto-assign cancels the job and alerts the customer "no vendors available" (`auto_assign.php:186–201`); admin can manually chase. For direct-vendor the customer simply sees few/no nearby vendors.
- **Where fixed:** supply ops (recruit per area — Area Intelligence, V2-VISION §6.2, currently read-only); a "notify me / callback" fallback instead of hard-cancel. **Launch-critical because empty results = churn.**

### P-6 — Wrong route chosen 🟠 🟡
- **Symptom:** a full-wiring project booked as a ₹149 direct call, or a simple tap-fix sent through expert-visit.
- **Handled today:** 🟡 — admin can re-route; `booking_mode`/`vendor_route` are mutable (`services/index.php:2050–2261`).
- **Where fixed:** intake guardrails in `app/pages/home.js` (nudge big jobs to Company/Expert-Visit); category config could mark "min job size."

### P-7 — Wrong-skill vendor 🟠 ✅-ish
- **Symptom:** a plumber shown for an electrical job (the old "vendor in every category" bug).
- **Handled today:** ✅-ish — fixed by the canonical `vendor_categories` junction (`migrations/2026_07_04_002`) + `ServiceVendorEligibility`. Residual risk: legacy vendors with no junction rows are unrestricted (noted in that migration's verification).
- **Where fixed:** ensure every vendor has curated `vendor_categories` rows at onboarding.

### P-20 — Locality mismatch 🟠 🟡 · P-21 — supply at peak 🟠 🟡 · P-23 — notification miss 🟠 🟡
- **P-20:** `localityCovered()` is **text-match**, city-scoped, not geo-fenced → same-named areas can mis-match; fix = tighter geo (`core/helpers/GeoHelper.php`).
- **P-21:** `is_online` gates supply; peaks (winter geyser, summer AC) will expose shortages → Area Intelligence should become actionable (V2-VISION §6.2).
- **P-23:** auto-assign writes an in-app `alerts` row but **there's a known FCM push gap** — `cron/auto_assign.php` doesn't push (only manual assign does; CHANGELOG 2026-09-06). Vendor may miss a time-boxed offer (45s timeout). Fix = add `FcmNotifier::notifyUser` in `auto_assign.php`.

### P-19 — double booking 🟡 ✅-ish
- Idempotency via `client_request_id` on create (`services/index.php`); low risk.

---

## 3. Vendor-behaviour problems

### P-5 — Accepts then no-show / stalls 🟠 🟡
- **Symptom:** vendor accepts, never starts; job stalls.
- **Handled today:** 🟡 — `VendorAccountability` computes stalled jobs (assigned-but-not-completed past `vendor_flag_stall_hours=72`) and flags the vendor (`VendorAccountability.php`, thresholds in `migrations/2026_08_15_001`). **Detection only** — no auto-reassign on stall.
- **Where fixed:** a stall→auto-reassign/return-to-queue in the assignment path; enforce `work_throttle`.

### P-12 — Flagged vendor still manually assignable 🟠 ❌
- **Symptom:** admin assigns a `paused`/flagged vendor because the throttle isn't checked on the admin path.
- **Handled today:** ❌ on admin path (auto-assign *does* honour throttle, `auto_assign.php:90–98`).
- **Where fixed:** read `work_throttle`/`manual_flag` in the admin assign (`heart/api/admin/index.php`) and warn/block. Money doc §6.

### P-22 — fake/low-quality vendor 🟠 🟡
- `is_verified` + rating exist; no hard verification gate at onboarding. Fix = verification step in vendor onboarding + admin approval (partly there via moderation flow).

---

## 4. Customer-behaviour & trust problems

### P-10 — Expert-visit abuse (free-diagnosis hunters) 🟠 🟡
- **Symptom:** customer books expert-visit only to get a free professional opinion, then does the work elsewhere.
- **Handled today:** 🟡 — a **paid, pay-to-confirm** visit fee deters this, and the fee is **100% company** so the company is compensated for the visit even if the job doesn't convert (`LedgerEngine.php:290–300`). Setting the fee FREE removes the deterrent (admin's choice per area×category).
- **Where fixed:** keep the fee paid in low-trust areas; track visit→job conversion (data is there: `visit_type` + subsequent booking) — surface in admin.

### P-11 — Bypass after first job 🟠 🟡 (by design)
- **Symptom:** once the phone is revealed at job start, a repeat customer can call the karigar directly next time.
- **Handled today:** 🟡 **by strategy, not by lock** — contact-hidden-until-start delays the first handoff (`vendors/index.php:284`); after that, the defence is *value-first* (CRM, ratings, priority) + accountability flags (V2-VISION §4.3/§5). No hard lock (intended).
- **Where fixed:** the real long-term lever is **online job payment** (money doc §5) so the platform stays in the money flow; plus the value hooks. Accepted risk for launch.

### P-13 — No warranty / rework 🟠 ❌
- **Symptom:** work fails a week later; no in-app "re-open under warranty."
- **Handled today:** ❌ — booking just sits `completed`.
- **Where fixed:** a warranty window field on `bookings` + a re-open/rework flow (service-engine). Identify only.

### P-15 — Customer no-pay (cash) — see §1.

---

## 5. Structural / pricing problems (from structure-lock)

### P-9 — Project scope-creep, no milestones 🟠 ❌
- Big jobs run as one visit + one `final_quote_amount`, one credit at completion — no staged scope/payment. Fix = Company Projects module (structure-lock GAP-4, V2-VISION §6 Engine 5 = MISSING).

### P-14 — AMC / recurring 🟡 ❌
- RO/pest AMC is a flat package today, not a recurring schedule. Fix = recurrence + reminders (reuse `cron/agent_reminders.php`).

### P-16 — Hourly billing 🟡 ❌
- Maid-by-hour can't be metered (structure-lock GAP-1). Launch workaround: fixed packages. Fix = `(unit_label, rate_per_unit)` generalisation (GAP-2, solves hourly too).

---

## 6. The 95/5 reading (for the founder)

- **The 5% that could "get you stuck" are the 3 🔴 items — and none is a surprise:** P-2 (Cashfree prod) and P-1 (cash-commission collection) are money-path items already in the audits; P-4 (no vendor in area) is a *supply* problem solved by recruiting karigars per locality, not by code. **Launch the cash, direct, fixed-price Wave-1 and these three are contained:** cash jobs don't need Cashfree (P-2 deferred to the paid showcase), and the commission-collection gap (P-1) is tolerable at pilot volume while it's the top fix-round item.
- **The other 21 are 🟠/🟡 — knowable, mostly already partly-handled, and none blocks a small honest launch.** They are exactly the "adjust the 5%" list: watch them in the field, fix in one pass.
- **Nothing here is fixed in this doc.** It is the ranked input to the single fix-round the founder planned.

*Companion to `category-job-flows.md` and `money-and-earnings-flow.md`. Grounded in real `wtg-v2` code. Documentation only — no code changed, nothing deployed.*
