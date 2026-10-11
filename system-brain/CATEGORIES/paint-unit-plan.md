# WorkToGo — PAINT UNIT: PLAN (refreshed P0) — PLAN + P0 RESULT

**From:** Claude Code (acting CTO partner) · **To:** CEO (Aakash) / WORQ · **Refreshed:** 2026-10-11 (supersedes the 2026-10-10 pre-core draft).
**Branch:** `feat/commerce-core` (v2 untouched). **Reads with:** [paint-unit-complete-pack.md](paint-unit-complete-pack.md) (the brief), [commerce-build-handoff.md](commerce-build-handoff.md) (core state), CHANGELOG.
**Core state (now):** **ENGINE COMPLETE + VERIFIED** — S1–S18 + F1, **594 local tests PASS / 0 FAIL on MariaDB 10.11.19** (regression 478 + e2e 22 + scale 10 + full-journey 53 + kyc-ui 31). `body/*` engines + OrderService + DeliveryEngine + LedgerEngine never edited (one allowed LedgerEngine settlement rework in S1). Seller app + rider onboarding + KYC all live.

> **Why refreshed:** the 2026-10-10 P0 was written against the pre-completion core (S1–S11, 261 tests). Since then **slab margin (S12), delivery-absorb (S13), Customer-ID (S14), the whole seller app — ON/OFF/BUSY + accept/reject + stock-out→reroute + KYC (S15–S18) — and routing source-preference (F1)** have all been BUILT and verified. So the old "biggest new surface" (seller app) and most old gaps are **done**. The gap list below is the real, current one, checked against actual code.

## 0. HEADLINE VERDICT (architecture test) — **PASS**
**No paint-specific code is needed inside any `body/` engine or the frozen core.** A paint Unit = **additive migration + `lib/commerce` rules + config + attribute convention + new UI surfaces**. Verified against schema + code (not assumed):
- `products.attributes / tags / meta` are **JSON columns** → every paint attribute fits with **zero new engine columns**. ([database-structure.sql])
- `orders.payment_status` already has **`partially_paid`** and `PaymentService::createOnlinePaymentOrder($orderId, $amount, …)` takes an **arbitrary amount** → the custom-tint advance is a thin rule — *but see Gap #1, a confirmed webhook limitation.* ([PaymentService.php:46])
- `OrderabilityResolver` has a real **layer-4 unit-rule hook** ("passes until unit-config built") → paint rules slot in there, in `lib/commerce`. ([OrderabilityResolver.php:118])
- `CommerceReturns::riskClass()` already returns **`no_return`** and `requestReturn` refuses it → custom-tint non-returnable is config. ([CommerceReturns.php:70])
- `MarginEngine::slabMargin(..., categoryId)` reads **`by_category` bands** → per-paint-category margin is config (S12). ([MarginEngine.php:144])

---

## 1. P0 SIMULATION A — READY PRODUCT (Berger Easy Clean 4L) — first money
| Stage | Served by (today) | New? |
|---|---|---|
| Catalogue / attributes (`attributes` JSON + `match_key`) | core + S10 | attribute **convention** only |
| Discovery, seller-hidden ("Fulfilled by WorkToGo") | S2 + S10 | config |
| Price = seller base → **slab margin** → **delivery-absorb** → one server price, loss-proof floor | S12 + S13 + S1/S8 | **config** |
| Payment (COD or **full** online) | S5 + webhook | reuse |
| Routing → seller **accept / stock-out→reroute** | S3 + **seller app S15** | reuse |
| Delivery (weight→vehicle) → **OTP** → settle founder-free | S6 + S1/S9 | reuse |
| Return (sealed, `returnable:true`, in window) | S9 | config |
| Customer-ID history (seller sees "this ID, N times") | S14 | reuse |
| **Coverage calculator** | customer app | 🟢 **new UI** |
**Result:** READY paint runs **end-to-end on the frozen core today**; new = the calculator UI + paint attribute/category config. **No payment-seam change needed.**

## 2. P0 SIMULATION B — CUSTOM TINT (shade "2003", repaint-match)
| Stage | Served by (today) | New? |
|---|---|---|
| Tintable catalogue (base = stock item; shade = attribute) | `attributes` + layer-3 stock on the base | 🟡 structure |
| Tint flow: brand→product→pack→bucket→shade code; **brand-code guard**; **new-vs-repaint** | customer app + rule | 🟡 new |
| Non-returnable flag + "I understand" consent | cart rule | 🟡 new |
| Price (slab + absorb, same as A) | S12 + S13 | reuse |
| **Advance 50% → `partially_paid`, balance COD** | ⛔ **webhook fails a part-capture today** | 🔴 **Gap #1** |
| **Repeat → same seller** (Customer-ID says "2003 ⇒ seller X") | S3 + S14 | 🟡 new rule |
| Seller instruction = **base + litres + code** (never "blue") | seller app S15 (via attributes) | reuse |
| Delivery / deliver / settle | S6 / S1 / S9 | reuse |
| Return **blocked** (`no_return`) | S9 riskClass | config ✅ |
| Customer-ID records "ID → 2003 → base → seller X" | S14 | reuse |
**Result:** custom-tint reuses the whole money/delivery/settlement/return spine; genuinely new = advance-payment seam (#1), repeat-routing rule (#2), tint structure/UI (#3). No engine surgery.

---

## 3. GAP LIST (current, verified — **none are `body/` engine or frozen-core surgery**)
1. **🔴 Advance / partial payment (custom-tint only).** CONFIRMED: webhook `PaymentService::processWebhookEvent` accepts only `{paid,failed,unpaid}` ([PaymentService.php:214]) and **fails any order whose captured amount ≠ full `orders.total`** ([:260-267]). A 50% advance is marked *failed* today. Fix = additive, **commerce-scoped** part-capture → `partially_paid` + balance tracked (balance then COD-at-door, which settlement's COD cash-owed already handles). `lib/PaymentService.php` is **shared with service/bookings** → must be flagged/scoped so bookings' exact-amount check is untouched. Its own build step. **Ready paint does not need it.**
2. **🟡 Repeat-exact → same-seller routing.** F1 preference = nearest/cheapest/reliable; "same seller who made this shade for this Customer-ID" is a **4th rule** in `RoutingEngine`, feeding off S14 + S3 signals. New rule + config.
3. **🟡 Custom-tint structure.** Shade/base/code table (data-empty until Prisma) + brand-code guard + new-vs-repaint toggle + non-returnable cart flag/consent + confirm-call (admin on/off). Migration + `lib/commerce` rules + customer-app UI. Stays **OFF in config** until Prisma import.
4. **🟢 Paint attribute convention + 7 categories + unit_config layer-4 hook.** Zero new columns. Documented attribute schema (D.1) + 7 category rows (interior-emulsion, exterior-emulsion, enamel, distemper, primer, putty, waterproofing) + wiring paint rules into the layer-4 hook. **← P1, this step.**
5. **🟢 Coverage calculator + guide.** Pure customer-app UI, math always labelled *estimated* (never double-apply 2-coat coverage).
6. **🟢 Customer-ID seller history for repeat.** Already built (S14) — custom-tint repeat just reads it.

**Already DONE in core (were "gaps" in the old P0):** slab margin by-category (S12) · delivery-absorb + no-flicker + rate cache (S13) · Customer-ID + seller history (S14) · seller app ON/OFF/BUSY + accept/reject + stock-out→reroute + KYC (S15–S18) · routing source-preference (F1).

## 4. BUILD ORDER (CEO-confirmed 2026-10-11) — one step, one commit, 10.11 proof, PASS/FAIL + hash + push
```
P1  Paint attribute convention + 7 paint categories + unit_config layer-4 hook   [Gap #4]  config/convention  ← THIS STEP
P2  Coverage calculator + customer guide (estimated-labelled)                      [Gap #5]  customer app
P3  Ready-paint end-to-end (external seller: catalogue → order → settle)           [Sim A]   integration + config
P4  Advance-payment seam (part-capture → partially_paid, balance COD, scoped)      [Gap #1]  commerce payment seam
P5  Custom-tint structure (code field, guard, advance, no-return, repeat-route; OFF) [Gaps #2,#3] rules+table+app
P6  Prisma import → fill shade/base/code → enable custom tint                       [data]    waits on CEO export
GATE  staging — real paint orders (ready + custom) founder-free (MariaDB ≥10.5 + Cashfree creds).
```

## 5. HONEST FLAGS
1. **Advance/partial payment** (Gap #1) is the one real seam change; `PaymentService` is shared — scope it to the commerce engine, prove bookings untouched.
2. **Coverage calculator = trust/liability.** Always "Estimated requirement — varies with surface/shade/application"; never double-apply 2-coat coverage (F.2). High product risk, low tech risk.
3. **Custom-tint stays OFF** until the Prisma shade/base/code data lands; ready paint carries first revenue meanwhile.

## 6. CARRY-OVER ENGINE FINDINGS (close at the staging gate — hosting)
MariaDB ≥10.5 (satisfied locally on 10.11) · collation consistency (closed, unicode_ci) · fresh-DB seed skip · `sku`→`match_key` · Cashfree sandbox ₹1 · ledger namespace bootstrap alias. All gate the staging proof (hosting + Cashfree creds).

---
**Status:** P0 **PASS** (CEO-confirmed). Building **P1** next, then the order in §4 — one commit, local 10.11 proof, PASS/FAIL + hash + push, CEO PASS before each next.
