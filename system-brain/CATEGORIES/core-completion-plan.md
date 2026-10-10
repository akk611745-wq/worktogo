# WorkToGo — CORE COMPLETION PLAN (seller app + pricing tweaks) — PLAN ONLY, NO CODE

**From:** Claude Code (CTO partner) · **To:** CEO (Aakash) / WORQ · **Date:** 2026-10-10
**Branch:** `feat/commerce-core` (v2 untouched). **Supersedes** the build order in [paint-unit-plan.md](paint-unit-plan.md) §6 (Paint Unit is DEFERRED until this core is complete + staging-proven).

## 0. MODEL CORRECTION (locked by the CEO — nothing drifts)
**No own/company stock.** WorkToGo is **seller-based from day one** — the founder *connects external shops* and scales the system, never stocks inventory or becomes a shopkeeper. Own stock is a far-future option (10–15 city scale) and even then plugs in **through the same seller app, as just another seller**. So:
- The **company-stock auto-fulfil** path is **removed** from every plan.
- An external shop **cannot join without the SELLER APP** → the seller app is the **biggest missing CORE piece**, not Unit work. It is the neen̄v (foundation); it is not skippable.
- **First seller at launch = an external paint shop (a friend's shop), onboarded via the seller app.**

**Sequence the CEO set:** (1) finish the CORE 100% — seller app + the two pricing tweaks; (2) prove it **founder-free on staging**; (3) *only then* return to the Paint Unit (plan already saved). Unit work does not start until core is done.

## 1. WHAT "CORE COMPLETE" STILL NEEDS (verified against the code, not assumed)
S1–S11 built the money/orderability/routing/events/payment/delivery+rider/ops/admin/settlement/returns/customer-app/RBAC spine (261 tests, 0 fail). The genuine gaps that stop an **external seller transacting founder-free**:
1. **Seller availability toggle** — `vendors.is_online` / `busy_until` columns exist (S2) but there is **no commerce endpoint** for a seller to flip ON/OFF/BUSY. *(gap)*
2. **Seller order accept/reject** — `RoutingEngine` has `onOrderPlaced` / `rerouteOnFailure` / `expireStaleOffers` but **no `sellerAccept` / `sellerReject`** — nothing closes the `offered → accepted` loop from the seller side. *(gap — the core cannot currently let a seller take an order)*
3. **"Stock not available" → reroute** — reroute machinery exists (`rerouteOnFailure` + signals, S3) but no seller-triggered entry point. *(gap — thin)*
4. **Seller catalogue entry** — `ProductService` (reads) + `/api/vendor/products` exist but are **service/shopping-vendor** surfaces; no clean commerce-seller "list an offer + base price + stock + submit-for-verification" path. *(gap — reuse-first)*
5. **Seller earnings / settlement view** — S9 computes payouts + own-vs-facilitated, but no seller-facing read. *(gap — thin read over S9)*
6. **Customer-ID history (C.4)** — opaque ID + per-seller own-history; new thin layer the seller app shows. *(gap)*
7. **Seller app UI** — no seller front-end exists (rider PWA exists; seller does not). *(gap — the surface)*
8. **Slab margin (C.1/C.2)** + **delivery quote/absorb/no-flicker (C.3)** — the two pricing tweaks. *(ext)*

**Guarantee preserved:** every item above is **additive migrations + `lib/commerce` methods + new `/api/...` adapter + new UI + config** — **no edit** to `body/shopping-engine`, `body/service-engine`, `OrderService`, `DeliveryEngine`, `LedgerEngine`, `orders.js`.

## 2. NEW BUILD ORDER — CORE COMPLETION (S12 → S17)
Same rhythm as S1–S11: one step = one commit, local test proof, PASS/FAIL + hash + push, CEO PASS before next.

```
S12  Slab margin + growth knob (+ loss-proof preserved)                     [C.1,C.2]   core-config ext        ~1 step / M
S13  Delivery quote + absorb + no-flicker server price + area rate cache    [C.3]       adapter+app+cfg        ~1 step / M
S14  Customer-ID layer (opaque ID, no PII, seller-facing own-history API)   [C.4]       thin table + API       ~1 step / M
S15  Seller backend API: availability ON/OFF/BUSY · offered-orders inbox ·  [seller]    new /api/commerce-seller ~1 step / M-L
     accept/reject (closes the S3 loop) · stock-not-available→reroute ·
     earnings/settlement view · Customer-ID history
S16  Seller catalogue API: add/edit offer · base price · stock qty ·        [seller]    reuse-first + thin      ~1 step / M
     submit-for-verification (S11 approve flow)                                          adapter  (may merge → S15)
S17  Seller APP UI — installable PWA, one-page glance-and-act               [seller]    new surface            ~1 step / M-L
     (availability · incoming orders accept/reject/stock-out · catalogue ·
      earnings · Customer-ID history). Design = mine: clean, glance-and-act.
GATE (CORE DONE): staging + MariaDB ≥10.5 + Cashfree — a real external shop onboards,
     lists a paint-agnostic product, toggles availability, ACCEPTS an order; a full
     order runs FOUNDER-FREE end-to-end (customer → seller accept → rider → deliver(OTP)
     → settlement → payout), ~20 orders, every exception handled by the ops seats (S7).
     Close the 6 carry-over findings. → Core frozen. → Paint Unit (P1…) begins.
```
**Count: 6 steps (S12–S17); S15+S16 may collapse to one if catalogue reuse is high → possibly 5.** Dependencies: S14 (Customer-ID) before S15 (seller app shows history); S12 (slab) before S15 (earnings view reflects real margin); S13 independent. The seller app (S15–S17) sits last, on finished pricing + Customer-ID.

## 3. WHY THIS ORDER (CTO reasoning)
- **Pricing first (S12/S13):** small, independent, and they decide the money a real seller sees — de-risk them before any live seller transacts. Both are extensions of our own `lib/commerce` seam + the `/api/shop` adapter; neither touches an engine.
- **Customer-ID (S14) before the seller app:** the seller app's history view needs it; it's also a small, foundational, no-PII layer reused by the customer app.
- **Seller app last (S15–S17):** the biggest surface, built on completed pricing + Customer-ID so its earnings and history are real, not stubbed. Split backend → catalogue → UI so each is one provable commit; catalogue may fold into the backend step if the existing vendor-product endpoints cover enough.

## 4. THE ONE LIFECYCLE DECISION (needs your call at S15)
Today, checkout (the `/api/shop` adapter) marks sub-orders `offered` **and** creates the delivery + auto-assigns a rider **immediately** (S3+S6). With external sellers, two models:
- **(A) Accept-gates-dispatch (my recommendation):** rider is dispatched **after** the seller accepts → no rider sent to a shop that rejects/stocks-out. Cleaner, no wasted trips. Implemented in the **adapter + `lib/commerce`** (new `sellerAccept`/`sellerReject`), **no engine edit**; needs a careful re-test of the S3/S6 flow.
- **(B) Dispatch-at-checkout, reroute-on-reject (lower change):** keep today's flow; a seller reject/timeout reroutes the sub-order (S3) + reassigns the rider (`DeliveryEngine::adminReassign`, already exists). Less restructuring, but a rider may be sent before the seller confirms.
For paint (not instant food — a short accept window is fine), **(A)** is the right model. I'll build (A) unless you prefer (B). Either way: adapter + lib only, full regression before PASS.

## 5. SELLER APP — design intent (mine to decide, per your call)
Installable **PWA** like the rider app (reuse the S6 manifest/service-worker pattern). **One page, glance-and-act** for a busy shopkeeper:
- Top: a big **ON / BUSY / OFF** toggle (availability in one tap).
- **Incoming orders** card list: each shows item + qty + the opaque **Customer ID** (never name/phone/address) → **Accept** / **Stock not available** (one tap each); a short countdown (the S3 offer timeout).
- **Catalogue** tab: add/edit an offer (product, pack, **base price**, **stock qty**), "submit for verification" → goes live after admin approve (S11).
- **Earnings** tab: today / week / pending payout (read over S9).
- **Customer-ID history**: tap an order → "this ID bought from you: this, N times" (repeat-match + motivation), **their own orders only**, no cross-seller leak, no PII.
Everything the seller sees is their own; the platform stays the face to the customer (S10 seller-hidden).

## 6. CONFIRMED ANSWERS (recorded; the *(unit)* ones apply when Paint P1 begins)
- 7 paint families = **7 categories** — YES *(at Unit time)*.
- First seller = **external shop (friend's paint shop)**, via the seller app — **no own stock**.
- Advance: custom-tint **50%**, small custom orders **full** *(Unit time)*.
- Confirm-call: **always on custom** + **₹5000+** orders *(Unit time)*.
- Growth knob: **thin fixed slab above loss-proof**, near-zero later *(S12 default)*.

## 7. CARRY-OVER FINDINGS (close at the core staging gate)
1. MariaDB **≥10.5** · 2. collation consistency · 3. fresh-DB seed (`2026_06_14_001`) · 4. `products.sku` UNIQUE → `match_key` · 5. Cashfree sandbox ₹1 round-trip · 6. ledger namespace shim. (Hosting + MariaDB ≥10.5 + Cashfree creds are the gate's infra, from you.)

---

**Status: PLAN ONLY — no code.** Awaiting **"PASS, start S12"** (and your pick on the §4 lifecycle A/B), then one step at a time: one commit, local proof, PASS/FAIL + hash + push, your PASS before each next.
