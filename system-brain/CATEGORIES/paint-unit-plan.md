# WorkToGo — PAINT UNIT: PLAN (P0 output) — PLAN ONLY, NO CODE YET

**From:** Claude Code (acting CTO partner) · **To:** CEO (Aakash) / WORQ · **Date:** 2026-10-10
**Branch:** `feat/commerce-core` (v2 untouched). **Reads with:** [paint-unit-complete-pack.md](paint-unit-complete-pack.md) (the brief), [commerce-build-handoff.md](commerce-build-handoff.md) (core state), CHANGELOG.
**Core state:** S1–S11 complete + proven, **261 local tests, 0 fail**. Service/OrderService/DeliveryEngine/LedgerEngine never edited.

> ⚠️ **UPDATE 2026-10-10 (CEO correction) — this Unit is DEFERRED; build the CORE first.**
> The model has **no own/company stock** — WorkToGo is seller-based from day one (external shops only; founder connects sellers, never stocks). So **any "company-stock auto-fulfil" idea in this doc is void**, and the **seller app is a CORE foundation**, not a Unit step. The active plan is now **[core-completion-plan.md](core-completion-plan.md)** (S12–S17: pricing tweaks + Customer-ID + seller app → staging founder-free proof). Paint Unit (P1…) begins only after the core is complete. The simulations, architecture test (PASS), reuse/gap maps and confirmed answers below remain valid; **§6's build order is superseded** by core-completion-plan.md, and the first seller is an **external shop**, not own stock.

## 0. HEADLINE VERDICT (the architecture test — the most valuable thing to know)

**PASS — the Paint Unit needs NO paint-specific code inside any `body/` engine or the core.** Verified against the actual schema + code, not assumed:
- `products.attributes`, `products.tags`, `products.meta` are **already JSON columns** → every paint attribute (brand, finish, coverage, warranty, shade_mode, base_type, shade_code…) fits with **zero new engine columns**.
- `orders.payment_status` **already has `partially_paid`** and `PaymentService::createOnlinePaymentOrder($orderId, $amount, …)` takes an **arbitrary amount** → the custom-tint **advance** is a thin rule, not a payment-engine rebuild.
- `OrderabilityResolver` already carries a **layer-4 unit-rule hook** ("passes until unit_config §M") → paint's unit rules slot in there, in the `lib/commerce` layer.
- `CommerceReturns::riskClass()` already supports **`no_return`** → custom-tint non-returnable is config, not new code.
- `MarginEngine`, `RoutingEngine` etc. are `lib/commerce/*` classes (NOT `body/` engines) → slab margin, delivery-absorb, repeat→same-seller are **extensions of our own seam**, allowed by doctrine.

So a Unit = **additive migrations + `lib/commerce` rules + config + new UI surfaces**. No core surgery. **Three honest flags** (Section 7) — the biggest is that a **seller-facing app/endpoints don't exist yet**; it is genuinely new work, and I recommend a sequencing change so it never blocks first revenue.

---

## 1. P0 PAPER SIMULATION A — READY PRODUCT (first money path)

*Order: a Haldwani customer buys **Berger Easy Clean 4L** (sealed, ready).* Each stage → the core piece that serves it.

| # | Stage | What happens | Served by | New? |
|---|-------|--------------|-----------|------|
| 1 | Catalogue | Product row, `match_key='berger-easy-clean-4l'`; `attributes` JSON = {brand, line, tier:mid, finish:sheen, pack:4L, coverage:125 sqft/L@2coats, warranty:6y, shade_mode:ready, returnable:true}; a seller offer = that store's inventory+base price | products.attributes (exists) + S10 + `match_key` (finding #4) | attribute **convention** only |
| 2 | Discovery | App → `/api/shop/meta` (city auto = Haldwani) → browse "interior-emulsion" → S2 orderability gate + **S10 seller-hidden compose** → shows "⚡ Fulfilled by WorkToGo", no shop name | S2 + S10 | config (paint=hidden) |
| 3 | Calculator | Customer enters room → "Estimated ~4L → 1×4L pack" (always labelled *estimated*) → adds 4L | **NEW (P4)** customer app | ✅ new |
| 4 | Cart | Single-shop basket (nearest eligible source); soft-split only if needed | S3 `planBasket` | reuse |
| 5 | Price | seller base → **slab margin** (P1) → **delivery-absorb** (P2) → ONE final server price, "FREE delivery" if high-margin; **loss-proof floor** enforced | MarginEngine ext (P1/P2) + S1/S8 | ext |
| 6 | Payment | Ready product → COD or online (S5 multi-gateway, webhook sole authority) | S5 | reuse |
| 7 | Routing | `onOrderPlaced` → **company-first** → nearest → offer | S3 | reuse |
| 8 | Accept / stock-out | External seller taps *accept* or *stock not available* → reroute (no own stock in the model → the **seller app is required**, built in core S15–S17) | S3 reroute + **seller app (core S15)** | ⚠️ (Section 7.1) |
| 9 | Delivery quote + handoff | `DeliveryEngine::quoteServerSide` (before cart, P2) → `createForOrder` → **S6 enrichHandoff** (4L≈5kg → bike) + **issueOtp** | S6 (DeliveryEngine called, not edited) | reuse |
| 10 | Deliver (OTP) | Rider PWA → OTP verified → `riderDeliver` → ledger settlement | S6 + S1 | reuse |
| 11 | Settlement | Seller base paid in full; WorkToGo keeps the margin spread; own-vs-facilitated split | S9 settle/payout | reuse |
| 12 | Return | Sealed + `returnable:true` + within window → S9 return/refund | S9 | reuse (config) |
| 13 | Customer ID history | Order recorded under the ID; seller later sees "this ID bought this, N times" | **NEW (P5/C.4)** | ✅ new |

**Result:** stages 1,2,4,5,6,7,9,10,11,12 = existing core (config/attributes/ext). New = calculator (P4), Customer ID (P5), and the seller-accept surface for external sellers (P6/seller-app). First money can flow on **company/own stock** with none of the new seller UI.

## 2. P0 PAPER SIMULATION B — CUSTOM TINT (structure now, data later)

*Order: Berger shade code **"2003"**, 4L, **repaint-match**.*

| # | Stage | What happens | Served by | New? |
|---|-------|--------------|-----------|------|
| 1 | Catalogue | Tintable product; `attributes`={shade_mode:tintable, custom_tintable:true, base options, returnable:false}; shade/base/code table **data-empty until Prisma** | products.attributes + **NEW shade table (P7)**, data P8 | ✅ new structure |
| 2 | Discovery | Same seller-hidden browse | S2 + S10 | reuse |
| 3 | Tint flow | brand → product → pack → **sees only the bucket** → chooses shade → enters code "2003" → **brand-code guard** (code ∈ Berger only) → **new-vs-repaint toggle** = repaint → *exact only, same base, same seller* | **NEW (P7)** customer app + rule | ✅ new |
| 4 | Cart | Flagged non-returnable; advance required; checkbox "I understand this is a custom shade" | P7 rule | ✅ new |
| 5 | Price | slab margin + delivery-absorb (same as A) | P1/P2 | reuse |
| 6 | Payment | **Advance** 50% (configurable) → `createOnlinePaymentOrder(amount = 50% of total)` → `payment_status='partially_paid'`; balance = COD on delivery | PaymentService + `partially_paid` (both exist) + **NEW advance rule (P7)** | ⚠️ (Section 7.2) |
| 7 | Routing | **Repeat → same seller**: Customer-ID history says "2003 for this ID was made by seller X" → route X first; else another seller with "slight difference possible" note | S3 ext + Customer ID (P5) | ✅ new rule |
| 8 | Accept | Seller gets the full instruction = **base + litres + code** (not "blue"); tints; wrong code shipped = seller remake, correct code "looks different on screen" = not seller's fault | seller app (gap) | ⚠️ |
| 9–11 | Delivery / deliver / settlement | Same as A | S6/S1/S9 | reuse |
| 12 | Return | **Blocked** — custom-tint category `risk_class='no_return'` → S9 refuses the return | S9 `riskClass` (exists) | reuse (config) |
| 13 | Customer ID history | Records "ID → 2003 → base → seller X → qty" for future exact repeat | P5/P7 | ✅ new |

**Result:** custom-tint reuses the entire money/delivery/settlement/return spine; the genuinely new parts are the **tint flow UI + brand-code guard + advance rule + repeat-routing**, all `lib/commerce` + customer app + one shade/base/code table. No engine surgery.

---

## 3. REUSE MAP (what the Unit stands on — S1–S11)
- **S1 MarginEngine / LedgerEngine margin split** → pricing + settlement (extended to slab in P1).
- **S2 OrderabilityResolver** → 4-layer gate, seller ON/OFF/BUSY, **layer-4 unit hook**, stock state, "nothing near you".
- **S3 RoutingEngine + signals** → company-first routing, single-shop/soft-split, offer/timeout/reroute, **stock-out reroute**, signal capture (repeat→same-seller feeds off this + Customer ID).
- **S4 EventBus / flags** → timeline, high-value hold, needs-ops.
- **S5 CommercePayment** → online + COD; **advance = arbitrary amount** through the same path.
- **S6 CommerceDelivery + rider PWA** → weight→vehicle, quote, OTP, reassign.
- **S7 OpsConsole** → exception queue, holds, confirm-call logging, cancels.
- **S8 AdminControlRoom** → live config (slab, absorb, advance %, growth knob), kill-switches, money dashboard, rate cache.
- **S9 Settlement/Returns** → payouts, own-vs-facilitated, **`risk_class` (no_return for custom-tint)**.
- **S10 Customer app + StorefrontComposer** → seller-hidden ("Fulfilled by WorkToGo"), Unit-UI config, related products, reorder, online/COD chooser.
- **S11 RBAC seats + /api/commerce-admin + audit** → verification approvals, seller/unit operator seats, uniform audit.

## 4. GAP LIST (genuinely new — none require core surgery)
1. **Slab margin** — extend `MarginEngine::policy/effectiveMargin` to read a per-unit/area slab (flat ≤band1, % mid, decreasing % high) + growth knob. [P1]
2. **Delivery-absorb + no-flicker + rate cache** — one server-composed final price (base+margin+absorbed delivery); "free/part" display config; cache area rates. [P2]
3. **Paint attribute convention + unit_config(paint)** — the JSON attribute schema + the 7 families + verification gate wiring. [P3]
4. **Coverage calculator + customer guide** — customer-app feature; math in F.2, always labelled *estimated*. [P4]
5. **Customer ID layer** — a thin `commerce_customer_ids` map (user → opaque ID) + a seller-facing **own-history** view (no PII, no cross-seller leak). [P5]
6. **Seller-facing app/endpoints** — ON/OFF/BUSY (fields exist via S2 `vendors.is_online/busy_until`) + **accept/reject offered order** + **"stock not available"→reroute** + Customer-ID history. **This is the biggest new surface and does not exist today.** [recommended P6a]
7. **Custom-tint structure** — shade/base/code table (data-empty), brand-code guard, advance rule, new-vs-repaint, repeat→same-seller, confirm-call. [P7]
8. **Prisma import** — load real shade/base/code → enable custom-tint. [P8, waits on CEO export]

---

## 5. ENGINE TWEAKS (Section C) — approach, each its own step
- **C.1 Slab margin** → new `commerce_margin_slabs` config (JSON per unit/area): `[{max:500,flat:20},{max:5000,pct:10},{min:5000,pct_curve:[{to:20000,pct:5},{to:100000,pct:3},{pct:2}]}]`. `MarginEngine::effectiveMargin` picks the band by seller base/price; loss-proof floor unchanged; high-value → S4 hold. Back-compat: if no slab configured, fall back to today's 3-tier flat.
- **C.2 Growth knob** → `commerce_margin_multiplier` (0–1+, live from admin) applied after slab; start thin, raise gradually. No deploy.
- **C.3 Delivery absorb** → adapter composes ONE price: `final = base + margin − absorbed_delivery_share`, `shown_delivery = total_delivery − absorbed`; absorb policy per unit/area in `app_settings`; **customer app must render the final price once** (no on-screen assembly). Rider pay stays distance-based (S6), value-blind. Cache area rates in S8 config; live-quote edge cases.
- **C.4 Customer ID** → opaque ID per user; seller APIs return only `customer_ref` + that seller's own orders for it; builds on S10 seller-hidden + S3 signals + S9 per-seller settlement. **No PII ever leaves to the seller.**
- **C.5 Learning-now vs Brain-later** → keep counters/signals (S3) flowing; **do not** build prediction on zero data. Interfaces stay stable for a later Brain.

## 6. BUILD ORDER — ⚠️ SUPERSEDED by core-completion-plan.md
> **Correction (2026-10-10):** slab margin, delivery-absorb, Customer-ID and the seller app are now **CORE steps S12–S17** (not Unit steps), and there is **no company/own-stock path**. The real Unit-only order after core is proven: **P1** paint attributes+unit_config+verify → **P2** coverage calculator+guide → **P3** ready-products flow via an **external seller** (core seller app) → **P4** custom-tint structure (advance 50%/full, confirm-call custom+₹5000+, no-return) → **P5** Prisma import. The block below is the *original* draft, kept for history only — ignore its P1/P2/P5/P6a/P6b (those moved to core).
```
P1  Slab margin + growth knob + loss-proof            [C.1,C.2]  core-config ext      ~1 step / M
P2  Delivery quote+absorb + no-flicker + rate cache   [C.3]      adapter+app+cfg      ~1 step / M
P3  Paint attributes + unit_config(paint) + verify    [D.1,D.4]  config+attr+approve  ~1 step / M
P4  Coverage calculator + customer guide              [D.3]      customer app         ~1 step / M
P5  Customer ID layer (seller-facing history, no PII) [C.4]      thin table+API       ~1 step / M
P6a Seller app/endpoints (ON/OFF/BUSY, accept/reject, [D.2/D.4]  NEW surface          ~1–2 steps / L
    stock-out→reroute, Customer-ID history)           <-- my inserted step
P6b Ready-products first-money path end-to-end        [D.2 A]    integration          ~1 step / M
P7  Custom-tint structure (data-empty, off)           [D.2 B]    rules+table+app      ~1–2 steps / L
P8  Prisma import → fill shade/base/code → enable      [G]       data wiring          ~1 step / M (after export)
GATE  staging — real paint orders (ready+custom) founder-free.
```
**Why the one change:** the pack's P6 ("ready-products full sell flow end-to-end") silently assumes a seller can accept an order — but **no seller accept surface exists**. Two clean options: **(a)** run first money on **WorkToGo's own/company stock (auto-fulfil, no seller app)** — fastest to revenue, matches "own stock at go-live"; **(b)** build the seller app first for external sellers (CEO's shop). I recommend splitting: **P6b first-money on company stock** (prove revenue immediately) and **P6a seller app** as its own step just before onboarding the CEO's shop as an external seller. This de-risks revenue and makes the biggest new surface explicit instead of hidden inside "P6".

**Honest readiness:** core ~**100%** ready to carry paint (verified). The Unit itself is **~55–65%** reuse: pricing/payment/delivery/settlement/returns/discovery = done; the ~35–45% new = calculator, Customer ID, **seller app**, custom-tint structure, and the attribute/config wiring. Estimate **~8–10 build steps** at the S1–S11 rhythm (one commit + local proof each). P8 is data-gated on the CEO's Prisma export; everything else can proceed now.

## 7. THREE HONEST FLAGS (CTO partner — predict the problems)
1. **Seller surface is net-new** (biggest item). Fields for ON/OFF/BUSY exist (S2), but accept/reject/stock-out/history endpoints + a seller UI do **not**. *Mitigation:* first money on company stock; build P6a before external sellers. Not a core-surgery risk — pure new `lib/commerce` + `/api/...` + a small app surface.
2. **Advance / partial payment reconciliation.** Schema supports it (`partially_paid` + arbitrary amount), but I must confirm the existing **webhook** marks an order `partially_paid` (not `paid`) when the captured amount < total, and that the **balance-on-delivery** is tracked so COD-at-door collects only the remainder. If the webhook hard-sets `paid`, that's a small, additive fix in the commerce payment seam (not OrderService). I'll verify in P7 before building.
3. **Coverage calculator = trust/liability surface.** It must *always* say "Estimated requirement — varies with surface/shade/application", never a hard promise, and never double-apply a 2-coat coverage (F.2). Wrong numbers erode trust fast. Low technical risk, high product risk — worth a review checkpoint.

Plus a standing reminder: keep every new migration **10.4-safe** (no COMMENT-after-CHECK) like S1–S11, even though prod must be ≥10.5 (finding #1). `products.attributes` already carries `CHECK(json_valid())` — we only *write* it, so no migration risk there.

## 8. OPEN QUESTIONS FOR THE CEO
1. **7 families = 7 categories, or 1 "Paint" category + family attribute?** I recommend **7 category rows** (so seller-visibility, related-products and unit-config work per family), all added to the seller-hidden list and mapped to the "paint" unit. Confirm.
2. **First seller at go-live = company/own stock, or the CEO's shop as external seller?** This decides whether P6a (seller app) is needed before first money. I recommend **company stock first** (revenue now), CEO's shop as the first external seller right after P6a.
3. **Advance default** — 50% or full for custom-tint? And a minimum order value below which advance isn't required?
4. **Confirm-call** — on for custom + orders above ₹\_\_\_? (ops touch, removable later.)
5. **Growth knob start** — near-zero margin on ready paint to win transactions, or a thin fixed slab from day one?

## 9. CARRY-OVER ENGINE FINDINGS (close at the staging gate)
1. MariaDB **≥10.5** · 2. collation consistency · 3. fresh-DB seed (`2026_06_14_001`) · 4. `products.sku` UNIQUE → `match_key` · 5. Cashfree sandbox ₹1 round-trip · 6. ledger namespace shim. All six also gate the Paint Unit's staging proof (hosting + MariaDB ≥10.5 + Cashfree creds, Section G).

---

**Status: PLAN ONLY — no code written.** Awaiting the CEO's review → **"PASS, start P1"** (or re-sequencing / answers to Section 8), then build one step at a time, one commit, local proof, PASS/FAIL + hash + push, CEO PASS before each next.
