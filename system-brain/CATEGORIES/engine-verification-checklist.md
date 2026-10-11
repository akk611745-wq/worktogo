# WorkToGo Commerce — ENGINE VERIFICATION CHECKLIST

**Purpose:** prove the engine (S1–S17) is sound before any Unit. Two parts: **A = checkable LOCALLY now** (MariaDB 10.4), **B = only on HOSTING (MariaDB ≥10.5)**.
**Branch:** `feat/commerce-core`. **Verify only — no code changed.** **Run date:** 2026-10-10 · local XAMPP PHP 8.2 + MariaDB 10.4.
**How to re-run everything:** `for t in tests/commerce/s*_vectors.php tests/commerce/verify_engine_e2e.php; do php "$t"; done`

---

## PART A — LOCAL (done now) ✅ ALL PASS

### A1. Every step's core function, end-to-end (its proof harness against the live DB)
| Step | Core function verified | How | Result |
|---|---|---|---|
| S1  | Margin money + ledger split (`processCommerceOrderSettlement`) | `s1_margin_ledger_vectors.php` | **25/25 PASS** |
| S2  | 4-layer orderability + ON/OFF/BUSY + kill-switch | `s2_orderability_vectors.php` | **14/14 PASS** |
| S3  | Deterministic routing + signals + reroute/timeout | `s3_routing_vectors.php` | **21/21 PASS** |
| S4  | Event bus + timeline + flags + notifications | `s4_events_vectors.php` | **16/16 PASS** |
| S5  | Multi-gateway payment (online+COD, failover, idempotent webhook) | `s5_payment_vectors.php` | **23/23 PASS** |
| S6  | Delivery handoff (weight→vehicle) + OTP + reassign | `s6_delivery_vectors.php` | **23/23 PASS** |
| S7  | Ops console (queue/claim/escalate/hold/cancel/comms) | `s7_ops_vectors.php` | **24/24 PASS** |
| S8  | Admin control room (live config + audit + kill-switches + money) | `s8_admin_vectors.php` | **27/27 PASS** |
| S9  | Settlement/payouts + own-vs-facilitated + returns/refund | `s9_settlement_returns_vectors.php` | **25/25 PASS** |
| S10 | Customer app composer (seller-hidden/unit-UI/related/reorder) | `s10_customer_app_vectors.php` | **28/28 PASS** |
| S11 | RBAC seats + gate + uniform audit | `s11_rbac_seats_vectors.php` | **35/35 PASS** |
| S12 | Slab margin + growth knob + loss-proof | `s12_slab_margin_vectors.php` | **30/30 PASS** |
| S13 | Delivery absorb + no-flicker price + area rate cache | `s13_delivery_pricing_vectors.php` | **40/40 PASS** |
| S14 | Customer-ID (opaque, no PII, seller-isolated history) | `s14_customer_id_vectors.php` | **22/22 PASS** |
| S15 | Seller backend + accept/reject + accept-gates-dispatch | `s15_seller_console_vectors.php` | **31/31 PASS** |
| S16 | Seller catalogue + seller-base → `seller_subtotal` earning | `s16_seller_catalogue_vectors.php` | **22/22 PASS** |
| S17 | Seller app PWA (installable, wired, no-PII) | `s17_seller_app_vectors.php` | **43/43 PASS** |

### A2. Full regression (all steps together)
- **How:** run all 17 harnesses in one pass. **Result: 449 PASS, 0 FAIL.** ✅

### A3. One simulated order, start → settle (`verify_engine_e2e.php` — 22/22 PASS) ✅
| # | Check | What it proves | How | Result |
|---|---|---|---|---|
| 1 | Offer priced by slab (`base 1000 → ₹1080`) + **seller_base recorded** | catalogue + S12 pricing | `SellerCatalogue::createOffer` / `sellerBaseOf` | **PASS** |
| 2 | **Verification gate**: draft NOT orderable → approve → orderable | status gate | `OrderabilityResolver::check` before/after `approve` | **PASS** |
| 3 | **Kill-switch**: `commerce_enabled=0` → `commerce_paused` → restore → ok | global stop | toggle `app_settings` + `check` | **PASS** |
| 4 | **Loss-proof floor**: margin≥exposure accepts, below rejects | never sell at a loss | `MarginEngine::lossProofOk(160,30,0)=true` / `(20,30,0)=false` | **PASS** |
| 5 | **Seller base recorded** on the line (`seller_base_price=1000`) + order (`seller_subtotal=2000`) | the earning anchor | `SellerCatalogue::persistSellerBase` | **PASS** |
| 6 | **MARGIN EARNS to ledger**: platform credit = `subtotal − seller_subtotal = ₹160` | money actually kept | `\Core\Helpers\LedgerEngine::processCommerceOrderSettlement` → `wallet_transactions` platform sum | **PASS** |
| 7 | **Accept-gates-dispatch (A)**: offered → seller accept → accepted (rider dispatch gated) | no wasted trips | `SellerConsole::accept` (flag on) | **PASS** (dispatch deferred by SKIP LOCKED on 10.4 — see B1; accept stands) |
| 8 | **Customer-ID isolation + no PII**: seller sees own history, other seller sees nothing, no name leak | privacy + repeat-match | `SellerConsole::customerHistory` for 2 vendors + JSON scan | **PASS** |
| 9 | **Return → approve → received → refund** | reverse path | `CommerceReturns` full chain | **PASS** |

### A4. What else is confirmed locally
- Migrations `2026_10_06_001…015` apply cleanly on 10.4 (seeds idempotent; the two COMMENT-after-CHECK files are patched by the migrate loop — see B2). ✅
- `v2` / `body/shopping-engine` / `OrderService` / `DeliveryEngine` / `LedgerEngine` **never edited** (reuse-only; `git diff` confined to `lib/commerce`, adapters, `seller/`, migrations, tests, docs). ✅
- All rule values live in `app_settings` (admin-tunable), nothing hardcoded. ✅

**PART A verdict: the engine is internally sound and composes end-to-end on local MariaDB 10.4. ✅**

---

## PART B — HOSTING ONLY (MariaDB ≥10.5) — PENDING

Run these on staging once hosting is up. Each lists the PASS criterion.

| # | Item | What to check | How | PASS criterion |
|---|---|---|---|---|
| B1 | **`SKIP LOCKED` / rider re-pick** (finding #1) | `DeliveryEngine::assignNearest` + the gated dispatch actually assign a rider; A→B re-pick on reject | place an order, seller accept → confirm a `deliveries` row gets a rider; reject → next rider | A rider is assigned on accept; re-pick works — no `1064 SKIP LOCKED` error |
| B2 | **COMMENT-after-CHECK migrations** (finding #1) | migrations `005` + `009` apply WITHOUT the local perl patch | run `migrate.php` on a fresh ≥10.5 DB | Both apply clean, unpatched |
| B3 | **Collation consistency** (finding #2) | prod default vs artifact `utf8mb4_unicode_ci`; no `col=@var` collation clashes | load schema + run the 449 regression on staging | 449 PASS on staging; no "illegal mix of collations" |
| B4 | **Fresh-DB seed** (finding #3) | `2026_06_14_001_seed_initial_services` (hardcodes vendor 11/cats 1–5) | fresh install OR build from a prod dump; skip the bad seed | Migrate completes on a fresh DB with no FK error |
| B5 | **`products.sku` UNIQUE → `match_key`** (finding #4) | two sellers list the "same" product (same `match_key`, different/NULL `sku`) | create 2 offers that share a `match_key`; confirm routing treats them as equivalent | Both list; cross-seller routing/reroute matches by `match_key` |
| B6 | **Cashfree sandbox ₹1 round-trip** (finding #5) | real online payment init → webhook marks order paid | set `CASHFREE_APP_ID/SECRET_KEY`, `CASHFREE_ENV=SANDBOX`; place a ₹1 online order; complete in sandbox | `payment_status=paid` via webhook (the sole authority); idempotent on replay |
| B7 | **Ledger namespace shim** (finding #6) | `LedgerEngine` refs to `Core\Helpers\Database/Logger` resolve in the real bootstrap | add the `class_alias` (or `$db` param) in the app bootstrap; run a settlement + a refund via HTTP | Settlement + refund succeed through the live stack, no "class not found" |
| B8 | **Advance / partial payment** (for the Unit later) | webhook marks `partially_paid` (not `paid`) when captured < total; balance tracked for COD-at-door | custom-tint-style advance order in sandbox | `payment_status=partially_paid`; balance correct |
| B9 | **~20 orders FOUNDER-FREE on staging** (the DONE gate) | onboard 1 external seller → list via seller app → run ~20 end-to-end orders (online+COD, a return, a reroute, a hold) with the founder touching nothing | real devices + the seller/rider/customer apps; exceptions handled by the ops seats (S7) | ~20 complete start→settle with zero founder intervention |

**PART B infra needed from the CEO:** hosting with **MariaDB ≥10.5**, **Cashfree sandbox creds**, and one **external seller** (friend's paint shop) for B9.

---

**Status: PART A = ✅ ALL PASS (449 regression + 22 e2e, local 10.4). PART B = pending hosting.** No code changed — verification only.
