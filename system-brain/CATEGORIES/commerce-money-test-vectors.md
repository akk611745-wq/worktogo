# WorkToGo Commerce — Money Test-Vector Sheet (S-c)

**Purpose:** the authoritative expected-money table for S1 (margin model). The S1 proof harness `tests/commerce/s1_margin_ledger_vectors.php` asserts exactly these. "DONE" for S1 = every vector PASS on the local DB.
**Model:** MARGIN, not commission. `customer_price = seller_base + margin`; seller paid base in full; WorkToGo keeps the spread; company store = full margin; rider gets delivery payout; COD cash owed goes to the rider's cash-in-hand.
**Settlement under test:** `LedgerEngine::processCommerceOrderSettlement($parentOrderId, $db)`. Pricing helper: `MarginEngine`.

---

## A. Pricing vectors — `MarginEngine` (policy: platform 8%, flat 0, min-floor ₹2, round nearest ₹1)

| # | seller_base | product margin | category margin | → margin | → customer_price |
|---|---|---|---|---|---|
| E1 | 200 | — | — | 16.00 | 216 |
| E2 | 10  | — | — | 2.00 (min floor) | 12 |
| E3 | 200 | pct 15 | — | 30.00 | 230 |
| E4 | 100 | — | pct 12 | 12.00 | 112 |

**Loss-proof floor** (floor = 0): `lossProofOk(margin 16, platform exposure 30) = FALSE` (would lose ₹14 → reject); `lossProofOk(margin 40, exposure 30) = TRUE`.

## B. Settlement vectors — `processCommerceOrderSettlement` → `wallet_transactions`

Each row is a parent order. `sub = (seller_base, customer_subtotal)`. Delivery = `(price, rider_payout, platform_fee)`.

| # | scenario | sub-orders | delivery | pay | vendor credit (pending) | platform credit (settled) | rider credit (settled) | driver cash_in_hand |
|---|---|---|---|---|---|---|---|---|
| A | vendor, prepaid | V1(200, 240) | (30, 24, 6) | online | **200.00** | (240−200)+6 = **46.00** | **24.00** | 0 |
| B | company store, COD | CO(—, 150) | (40, 32, 8) | cod | — (none) | 150 (full) + 8 = **158.00** | **32.00** | 150+40−32 = **158.00** |
| C | multi-vendor, prepaid | V1(100,120), V2(50,65) | (30, 24, 6) | online | 100 + 50 = **150.00** | 20+15+6 = **41.00** | **24.00** | 0 |
| D | legacy (no seller_base), COD, no rider | V1(NULL, 200), vendor rate 10% | none | cod | 200−20 = **180.00** | commission **20.00** | 0 | 0 (no rider) |

**Rules proven:** (A) margin split; (B) company = full margin + COD cash to rider; (C) multi-vendor each paid base in full; (D) back-compat commission when no seller_base captured. Idempotency: a second settlement call on the same parent is a no-op (ledger_status='processed').

Evidence is captured in the S1 commit's test run (PASS/FAIL per vector). Related: [commerce-system-blueprint.md](commerce-system-blueprint.md) §E, [commerce-v1-build-spec.md](commerce-v1-build-spec.md) §5/§8.
