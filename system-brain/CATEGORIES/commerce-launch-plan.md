# WorkToGo Commerce — Master Launch Plan

> **Source of truth for system DESIGN is now [commerce-system-blueprint.md](commerce-system-blueprint.md)** (one core + per-Unit rules, founder-free operation, all actors/states/events/exceptions); execution = [commerce-v1-build-spec.md](commerce-v1-build-spec.md). Where the two overlap on architecture, the blueprint wins.
> **⚠️ OVERRIDDEN (CEO FINAL scope, 2026-10-06):** the **"FIRST MONEY / parallel manual own-stock COD bootstrap"** track in this doc is **REMOVED** — no manual bootstrap; build the full core + payment + delivery **founder-free before launch** (see the blueprint's *FINAL SCOPE LOCK*). This doc is retained for reference only; its first-money section no longer applies.

**From:** Claude Code · **To:** WORQ (CTO) / CEO · **Type:** PLANNING document — no code written, nothing built.
**Grounds on:** [commerce-launch-audit.md](commerce-launch-audit.md) (evidence, file+line). **Reads with:** [money-and-earnings-flow.md](money-and-earnings-flow.md), [predicted-problems.md](predicted-problems.md), [painting-launch-audit.md](painting-launch-audit.md).
**Status of this doc:** a decision document the CEO reads and edits. Business/legal values in §3 of the brief are left OPEN with recommendations — not locked.

> **Amendment absorbed (CEO correction to brief §2.3 / §10):** Seller visibility is **not** a global rule. It is a **per-category config** — `seller_visibility ∈ {hidden, shown, situational}` — read by the UI composer. Engine code must never hardcode either behaviour. Default *hidden* for commodity/ready goods (paint, hardware, veg); *shown* for seller-locked categories (food/restaurant); *situational* allows a per-category / per-situation override. See §4 below.

---

## TL;DR for the CEO (read this, then the first-money section)

1. The hard engineering (orders, cart, inventory locks, dual-store schema, native delivery, ledger, realtime, RBAC) **already exists and is production-grade** (~70% generic plumbing). You are not starting from zero.
2. The gap is **your specific model**, not the plumbing (~35–40%): the code takes **commission from sellers**; you want **margin on top**. That one difference drives most of the build.
3. **Fastest path to your first rupee does NOT require the margin engine or online payments.** You can earn from your **own dark-store stock, full margin, COD, seller shown or hidden by config** using code that already runs today — see **§FIRST MONEY**. The margin engine is what you need to earn from *other* shops' stock and to scale hands-free; build it in parallel, not as a blocker.
4. The two decisions that most change the build, and that only you can make, are at the end (§17). Everything else I've recommended with trade-offs.

---

## 1. CORE-FLOW ARCHITECTURE — the never-changing spine

The spine is fixed code, built once, identical for every category. Mapping each step to **reuse / modify / build** with audit refs:

| # | Step | Today | Action | Evidence |
|---|---|---|---|---|
| 1 | Customer need → **location resolve** | City resolved invisibly (Haldwani) | **MODIFY** → add lat/lng + distance | `heart/api/shop/index.php:48-53` (`CityResolver`) |
| 2 | **Nearby catalogue** (seller per config, margin applied) | City-scoped, company-first, seller EXPOSED, no margin | **MODIFY** (distance + margin + visibility composer) | `heart/api/shop/index.php:110-142` |
| 3 | **Cart** (single-shop preference) | Works; raw price; no single-shop logic | **MODIFY** (margin at read-time; basket-shop grouping) | `body/shopping-engine/api/cart/CartService.php:34-130` |
| 4 | **Checkout** (online-primary / COD) | COD-only, total=subtotal | **MODIFY** (enable online; total = base+margin) | `OrderService.php:49,316`; `heart/api/shop/index.php:220-255` |
| 5 | **Order created** (multi-vendor split, FOR UPDATE) | Solid, race-safe | **REUSE** | `OrderService.php:67-330` |
| 6 | **Seller stock-confirm popup** (accept/reject; timeout→reroute) | Accept/Reject + realtime exist; **no timeout/auto-reroute** | **MODIFY/BUILD** (timeout + reroute) | `vendor/orders.php:171`; `OrderService::updateVendorOrderStatus:429` |
| 7 | **Handoff to delivery** (pickup/drop/weight/custom flag) | Native handoff exists | **MODIFY** (richer contract — §7) | `heart/api/shop/index.php:249` → `DeliveryEngine::createForOrder` |
| 8 | Delivery → customer confirm | Native delivery, distance-priced, rider auto-assign | **REUSE** (delivery is the next, separate task) | `core/delivery/DeliveryEngine.php` |
| 9 | **Settlement** (margin→platform, base→seller, fee→rider) | Commission split | **REWORK** → margin split (§3) | `LedgerEngine::processOrderCompletion:150-247` |
| 10 | **Return/refund** (source-aware) | Only cancel + inventory release | **BUILD** (§8) | `OrderService.php:337` |

**Architecture test (keep honest):** if adding a category later forces a change to steps 1,3,4,5,7,9 code, the architecture has leaked — categories must be config (§2).

---

## 2. CATEGORY-AS-CONFIG MODEL

**Core (code, fixed):** catalogue read, cart, order, margin engine, payment, stock-confirm, delivery handoff, return/refund, settlement.

**Per-category (config/data only, no code):**

| Config axis | Lives in | Paint example |
|---|---|---|
| Attribute set | `store_types` + per-category schema (JSON) | shade code, base type, pack litres, coverage |
| `seller_visibility` | category config (new) | `hidden` (ready paint) |
| Substitution rule | category config (new): `exact / equivalent / fungible` | `exact` for sealed paint; `locked` for custom tint |
| Margin policy | `app_settings` + category/product margin (new, §3) | thin margin, per-pack |
| Fulfilment constraints | category config (new): fragile/heavy/perishable/custom | heavy (20L≈25kg), custom=no-return |

**Reuse already present:** `store_types` is admin-addable, pure data (`migrations/2026_08_22_001...:81-117`); `categories` is hierarchical + data-driven (`database-structure.sql:384`); `/api/shop/meta` already serves store-types + categories to the UI (`heart/api/shop/index.php:76-86`). So "add a category = add config + onboard sellers" is **architecturally within reach** — the missing piece is a **category-config table/JSON** that the composer, margin engine, and risk logic all read. **BUILD** that config layer; it is the spine of the whole config-not-code promise.

**First worked example:** Paint (§9). **Next unit:** hardware/plumbing — same ready-product path, SKU-based, no custom/tint complexity → the cheapest possible second category precisely because it reuses Paint's ready path and adds no new code.

---

## 3. MARGIN ENGINE (3-tier) + MIGRATION FROM COMMISSION

**Target price formula (customer sees final only):**
```
customer_unit_price = seller_base_price
                    + platform_margin            (global default)
                    + category_margin            (per-category override)
                    + product_margin             (per-product override)
```
**Resolution/stacking rule (RECOMMEND, CEO to confirm):** use **override, not additive stacking**, to stay predictable and thin:
`effective_margin = product_margin ?? category_margin ?? platform_margin_default`, expressed as **percent** with an optional **flat floor** per category (so ₹2 packs don't yield ₹0.10 margin). Keep ONE effective margin per line — easy to explain, easy to cap. (Additive stacking tends to balloon prices and violates the "margin must be thin" CTO warning.)

**Where margin is defined (BUILD):**
- `app_settings` row `commerce_margin_policy` (JSON) — the live runtime-config pattern already used for payment/OTP routing (`lib/payment/PaymentGatewayManager.php:133-138`). Holds `platform_margin_pct`, per-category map, flat floors, rounding rule.
- `categories.margin_pct` + `categories.margin_flat` (new nullable columns) — admin-editable.
- `products.margin_pct` + `products.margin_flat` (new nullable) — per-product override.

**Where margin is applied (MODIFY):** at the **read/pricing seam**, not inside the never-touch engine. Two clean options:
- **(A, RECOMMEND)** Apply margin in the **`/api/shop` adapter + CartService read path** so the engine stays untouched and the customer only ever sees marked-up prices. The order is then stored at the marked-up price, and we also persist the **seller base** (see below).
- (B) Apply margin inside the engine — rejected; violates the never-touch rule and tangles service pricing.

**The one schema change the ledger migration hinges on:** today `orders.subtotal`/`order_items.unit_price` = seller price (no margin). To split money correctly we must store **both** numbers. **BUILD:** add `order_items.seller_base_price` (and `orders.seller_subtotal`) captured at checkout. Then:
- Customer pays `total = Σ customer_unit_price` (base+margin) [+ delivery, §6].
- `platform_margin_earned = seller_subtotal_markup = total_items − seller_subtotal`.

**Ledger rework (REWORK `LedgerEngine::processOrderCompletion:150-247`) — without breaking service:**
- Today (`:193-196`): `platform_commission = subtotal*rate/100; vendor_share = subtotal − commission`.
- Target: `vendor_share = seller_subtotal` (seller gets their base in full); `platform_earnings = total_items − seller_subtotal` (the margin spread); `rider_share = delivery_fee` (unchanged, `:199`).
- **Safety:** the order branch and the booking branch are already separate functions and rows are tagged `reference_type='order'` vs `'booking'` (`:179`, `walletRefMatch`). Change ONLY `processOrderCompletion`; leave `processBookingCompletion` and its `category_commission_rate` logic untouched. The service ledger keeps commission; commerce moves to margin. **This preserves the shared-engine discipline.**
- **Company dark-store (`ownership='company'`):** no seller to pay → `vendor_share = 0`, `platform_earnings = full item total − nothing`. The booking branch already has a "company = 100% margin" precedent (`processBookingCompletion`, the company self-fulfil branch) — mirror that pattern for orders.
- `products.cost_price` (`database-structure.sql:639`, currently unused): **RECOMMEND** snapshot `cost_price` into `order_items` at checkout so true cost-basis profit per order is available for the Brain later. Cheap now, invaluable later.

**CTO warning encoded:** local customers often know the shop price. Margin must be **thin**; the sell is **convenience** (no 10-shop run, coverage calculator, delivery, one place). Flag margin-% as a pricing-policy risk (§12), and keep the admin able to set it near-zero for launch to buy adoption.

---

## 4. SELLER-VISIBILITY PRESENTATION (per-category config — amended)

- **Config, not hardcode:** `seller_visibility ∈ {hidden, shown, situational}` per category, read by the UI composer. Engine/adapter must support all three; the UI decides by config.
  - `hidden` (default, commodity/ready — paint, hardware, veg): one unified WorkToGo result, WorkToGo packaging/branding; **strip `vendor_name`, `store_name`, store object** from `/api/shop/*` responses and UI.
  - `shown` (food/restaurant, seller-locked): seller identity IS the choice; surface it.
  - `situational`: per-category/per-situation override flag (e.g. show seller for a high-value custom order).
- **Fields to strip when `hidden` (MODIFY `heart/api/shop/index.php`):** `vendor_name` (`:124`), `store_name`/`store.*` (`:136,158`), `ProductService::formatProduct` `vendor_name`/`vendor_id` (`ProductService.php:263`). Internally the order STILL stores source seller (settlement, return, evidence) — hide at the composer, never at the data layer.
- **Admin toggle:** yes — surface `seller_visibility` in the category admin so ops can flip per category without a deploy.

---

## 5. LOCATION / DISTANCE DISCOVERY + RANGE

- Today: city-scoped only; `stores.lat/lng` + `service_radius_km` unused in browse (audit §3).
- **BUILD distance-first discovery:** resolve customer lat/lng on open (UI already requests geolocation in `app/commerce/shop.js:293-296`); rank products **nearest-first** using a bounding-box pre-filter + haversine (reuse the distance math already in `DeliveryEngine`), scoped to stores whose `service_radius_km` covers the customer.
- **Expandable range:** start tight (e.g. default radius from `app_settings`), auto-expand when too few results; expose an **optional customer range filter**. Far items shown only with the correct delivery charge attached (ties to §7).
- **Server-authoritative:** keep the fee server-computed (store→drop) as it already is (`heart/api/shop/index.php:171-173`) so shown estimate = billed fee; discovery distance can use client coords, but **pricing must not**.

---

## 6. PAYMENT PLAN

- **Online primary (Cashfree) — ENABLE + WIRE.** The online branch exists but is dead code behind `ALLOWED_PAYMENT_METHODS=['cod']` (`OrderService.php:49,191-211`); `PaymentService` + the multi-gateway abstraction are built (`lib/payment/`, audit + payment memory). Plan: add `'online'` to the commerce path **via the adapter** (so the engine constant can stay, or is widened deliberately as a surfaced decision), drive the Cashfree JS SDK on the storefront, and keep the **webhook as the sole authority** for marking paid (never the return page).
- **COD secondary** — ready/sealed goods only; blocked for custom (§9).
- **Advance/partial (BUILD)** for made-to-order (e.g. 50% advance on custom tint). Not supported today. Plan: a partial-capture order state (`payment_status='partially_paid'` already in the enum, `database-structure.sql:546`) + a balance-due capture at/near delivery.
- Route everything through the gateway where possible so ops don't depend on manual cash — this is the lever that makes the business self-running rather than people-dependent.

---

## 7. DELIVERY HANDOFF CONTRACT (commerce side only — do NOT design the rider system)

Commerce hands delivery a clean object and owns none of the routing. **Contract to formalize:**
```
{ order_id, pickup:{store_id, lat, lng, address}, drop:{lat, lng, address},
  items:[{sku, qty, weight_kg, volume}], total_weight_kg, total_volume,
  ready_vs_custom, cod_vs_prepaid, cod_amount, fragile|heavy|perishable flags }
```
- Today's native handoff already creates + prices + auto-assigns (`heart/api/shop/index.php:249` → `DeliveryEngine::createForOrder`); **MODIFY** it to carry **weight/volume + ready/custom + prepaid/COD** so delivery can pick scooter vs e-rickshaw/loader (20L paint ≈ 25kg).
- **Confirm which path is live:** native `DeliveryEngine` vs the legacy external `SWIFTDELIVER_URL` (`OrderService::dispatchToDelivery:965`). **RECOMMEND:** native is the intended path; disable/retire the external dispatch to avoid double-dispatch. (Flag for CEO; the rider system is the next task.)

---

## 8. RETURN / REFUND (source-aware, risk-class exceptions)

- Today: only cancel + inventory release (`OrderService.php:337`); `refunded` enum present, no flow; `RefundController` is service-side.
- **BUILD return-to-source:** the item goes back to the **seller it came from** (order already stores source seller even when hidden); rider "received-back" confirmation popup; money adjusts/refunds via the gateway (online) or adjusts settlement (COD).
- **Per-category risk classes (config, §2):**
  - `returnable_if_sealed` — ready paint, hardware (default).
  - `no_return` — custom/produced goods (advance protects the seller) and perishables (no resale).
- Refund authority = webhook/ops confirmed, never client. Reuse `wallet_transactions` `refunded` status + `reference_type='order'` for a clean reversal row.

---

## 9. PAINT UNIT — first fully worked example

**The fork the unit hinges on:**

| | Ready paint (sealed) | Custom tinted (made-to-order) |
|---|---|---|
| Price | instant | base + colorant; estimate then confirm |
| Payment | COD or online | **advance required, no COD** |
| Return | returnable if sealed | **no return** (dead stock) |
| Source | any shop with exact SKU in stock | **locked** to producing seller |
| Ship | **V1** | **V2 (after ready works)** |

**Paint data to add (all MISSING today — only the service engine has sqft; audit §9). Model as category-config + `product_variations`:**
- **Brand set cards** (Asian, Berger, Indigo…): product lines (interior/exterior emulsion, enamel, distemper), base types, pack sizes (1/4/10/20L), shade cards with codes.
- **Shade/colour code** on the offer; a **colour section** where the customer picks by code (show code + official name; label "screen colours are indicative").
- **Base–shade compatibility** encoded (deep shade can't be made on a white base) → prevent invalid combos before the seller has to reject.
- **Coverage calculator** (sq-ft/rooms → litres) — the single biggest paint value-add. Store coverage as a field on the pack (reuse the service engine's sqft math as a reference, don't import it). Fewer wrong-quantity orders, fewer disputes, system feels smart.
- **Tinting price:** default to a **universal shade-value estimate** (digitize the brand dealer per-shade addition table, scale by litres); **fallback** = seller-confirm popup for rare/deep shades or large-value orders.
- **"The code is the contract":** wrong **code** shipped = seller's fault; correct code but "looks different on screen" = not the seller's fault. Encode this in the dispute rule.
- **Heavy delivery:** pass weight in the handoff (§7).

**Pilot:** founder as a seller + 2–3 known shops, manual oversight on every order. **Success target: ~50 real paid material orders where margin > delivery cost.**

---

## 10. REUSE MAP (from the audit)

**Reuse as-is (do not touch):**
- `OrderService` multi-vendor split, FOR UPDATE inventory, rate-limit (`body/shopping-engine/api/orders/OrderService.php`).
- `CartService` race-safe cart (`.../cart/CartService.php`).
- `store_types` + `stores` dual-model schema (`migrations/2026_08_22_001...`).
- Native `DeliveryEngine` distance pricing + `delivery_rate_cards` + `free_above_subtotal`.
- Realtime/push for incoming orders (`vendor/orders.php`, `shared/realtime.js`).
- RBAC commerce roles (`migrations/2026_08_22_001...:425-473`).

**Modify (clean seams, mostly the adapter):**
- `/api/shop/*` adapter — margin apply, distance discovery, seller-visibility composer, online payment, richer handoff (`heart/api/shop/index.php`).
- `LedgerEngine::processOrderCompletion` — commission→margin (`:150-247`).
- `heart/api/admin/commerce.php` — margin fields, category-config, seller-visibility toggle.

**Build new:**
- Category-config layer (attributes, visibility, substitution, risk class).
- Margin config (`app_settings` + `categories`/`products` columns) + `order_items.seller_base_price`.
- Distance discovery + range.
- Advance/partial payment state.
- Return-to-source flow.
- Paint data model (brand/shade/pack/coverage/compat) + coverage calculator + tint estimate table.

---

## 11. BUILD SEQUENCE (with dependencies)

```
Phase 0  Decisions (§17) + category-config table design                [blocks all]
Phase 1  CORE: margin engine + seller_base capture + ledger rework      [→ money correct]
         + seller-visibility composer (config)                          [reuses adapter]
Phase 2  Online payment (Cashfree) enable + webhook; keep COD           [parallel w/ P1]
Phase 3  PAINT (ready): brand/shade/pack/coverage data + coverage calc  [needs P1]
         + distance discovery + range                                   [needs P1]
Phase 4  Delivery handoff contract enrich (weight/custom/prepaid)       [needs P3 data]
Phase 5  PAINT (custom tint): advance/partial + no-return risk class    [needs P2,P3]
Phase 6  Return-to-source flow                                          [needs P3]
Phase 7  Hardware/plumbing unit (config only, reuses ready path)        [needs P3]
---- later ----
Rider system (separate task) · The Brain (last layer, §13)
```
Dark-store-only COD selling (§FIRST MONEY) can run **before Phase 1 finishes**, because it needs neither margin math (full margin = whole price) nor online pay.

---

## FIRST MONEY — the leanest path to rupee #1 (founder's explicit ask)

**Principle: earn before you build.** Do not wait for the margin engine or online payments to make the first money. Use what already runs today.

**Stage 0 — "Own dark store, COD, manual" (0 new code, days not weeks):**
- Register WorkToGo's own stock as a `company` store (`stores.ownership='company'` already exists; admin can create it via `heart/api/admin/commerce.php`).
- Load ~30–50 fast-moving **ready paint + plumbing** SKUs you already deal in (founder's own shops). Set the **customer price directly** (full margin baked into the price — no margin engine needed when you ARE the seller).
- Keep `seller_visibility=hidden` via config (or simply: it's your own store, nothing to hide).
- Take orders on the existing COD flow; fulfil from your stock; deliver via the native delivery engine (or even manually at first).
- **This is real revenue on day one.** Every order is 100% your margin; the only cost is delivery, which you already price by distance.

**Stage 1 — "Add 2–3 known shops as supply" (needs Phase 1 margin engine):**
- Now the margin engine matters: their price + thin margin = customer price; they get their base, you keep the spread.
- Still mostly COD; online enabled as it comes (Phase 2).

**Stage 2 — "Hands-free" (needs online payment live):**
- Online-primary payment removes the manual-cash dependency → the platform starts running without a person on every order.

**How small can you start / how much money:**
- **Capital:** only your own **starter stock** (paint/plumbing you already buy) + packaging + petrol/one rider. No dark-store lease required to begin — your existing shop IS the dark store.
- **People:** you + 1 helper can run the pilot (manual oversight on every order is a FEATURE at this size, not a flaw — it's how you learn the real failure modes).
- **Target:** the pilot's success metric (§9) — **~50 paid orders where margin > delivery cost.** That proves the unit economics before you spend on a real dark store or more sellers.

**Why this order:** it de-risks capital (sell before you stock heavily), de-risks tech (earn before the margin engine lands), and gives the Brain (§13) real order data from day one.

---

## 12. PROBLEMS / RISKS — Haldwani-specific (expanded from brief §18)

- **Stock truth:** "store ON" ≠ item in stock. Seller stock-confirm is **mandatory** with a **timeout → auto-reroute** (today's accept/reject has no timeout — §1 step 6). Your own dark store is the always-complete fallback.
- **Informal sellers, no SKU/barcode:** canonical matching is hard → multi-offer works for **branded** goods (paint, fittings), not no-brand local goods. Start branded; that's another reason Paint is first.
- **Unit economics:** small baskets bleed (delivery > margin). Mitigate with **single-shop basket** (one trip), a **free-above threshold**, and paint/hardware first (bigger ticket) — veg/food last.
- **Seller price-gaming:** ask the seller only their **selling price + your margin**, never their purchase rate — keeps it simple and non-adversarial.
- **Margin visibility:** local customers know shop prices → **thin margin**, sell convenience. Admin must be able to set margin near-zero to buy launch adoption.
- **Dark store = capital + spoilage + seller-of-record liability:** keep **separate accounts** for own vs facilitated sales from day one (§15, §19).
- **Packaging** is a real operational line (WorkToGo-branded) — cost it in.
- **Heavy delivery:** 20L ≈ 25kg → vehicle selection must come from the handoff weight (§7).
- **Cold-start personalization:** new customer = no history → rule-based first (§13).

---

## 13. THE BRAIN / ALGORITHM — plan it, build it LAST

**Rule-based now; AI never a dependency for basic operation.** Later phase handles: demand sensing per area, supply/source selection (reliability + distance + time + economics), rider routing, dynamic delivery pricing (free above threshold), low-stock nudges to sellers, and — for the **own dark store** — pushing products to customers by relationship/reliability scoring. Keep "rule-based now" and "Brain later" explicitly separated. The `cost_price` snapshot (§3) and order history from §FIRST-MONEY are the Brain's future fuel — start collecting now even though you won't use it yet.

---

## 14. 10-YEAR SCALE PREDICTION

**If it works:** Paint → hardware/plumbing → electrical → FMCG/kirana → (much later) food/veg; one city (Haldwani) → tier-2/3 cluster rollout where Amazon/Blinkit don't bother going deep; a growing **own dark-store network** on the high-margin, always-complete SKUs; the **Brain** turning order history into demand prediction + private-label own-stock. **Defensibility vs Amazon/Blinkit:** they optimize metros + big catalogues; WorkToGo owns **local tradesperson supply** (the 10-shop-run problem) that national players ignore — relationships, local SKUs, and same-hour material delivery for jobs.

**What breaks it:** greedy margin (customer defects to the shop next door), stock-truth failures (wrong/absent item kills trust), unit economics on small baskets, capital sunk into dark stock that doesn't move, and seller adoption when they're hidden (why join if invisible? — answer: you bring them demand they'd never get, and they keep their base price).

---

## 15. CUSTOMER PSYCHOLOGY (2026+ audience)

- **Paint/hardware buyer (contractor or homeowner):** pain = running to 10 shops to assemble one job's material. WorkToGo wins on **"one place, delivered, coverage-calculated, correct quantity"** — time + certainty, not price. They'll accept a thin margin for not losing half a day.
- **vs the shop next door:** the shop can't deliver, can't calculate coverage, can't assemble across suppliers. WorkToGo's value is **orchestration**, not being cheapest.
- **vs Amazon/Blinkit:** national players don't carry local paint shades, local fittings, or same-hour job material. WorkToGo is **local-first and job-first**.
- **2026 psychology:** this audience now expects app ordering, live tracking, online pay, and "it just arrives" — COD-only feels dated and signals a small operator. Online payment + tracking + WorkToGo packaging **signals legitimacy**, which matters more than ever for trust with a new local brand. For food (later), the opposite instinct rules — they want to *choose the seller* (hence `seller_visibility=shown`).

---

## 16. ROUGH TIME ESTIMATES + readiness restatement

*(engineering-time only; excludes business/legal/CA and seller onboarding; one focused dev)*

| Piece | Est. |
|---|---|
| Category-config layer | 3–5 d |
| Margin engine + seller_base + ledger rework | 5–8 d |
| Seller-visibility composer | 2–3 d |
| Online payment (Cashfree) enable + webhook | 3–5 d |
| Distance discovery + range | 3–4 d |
| Paint data (brand/shade/pack/coverage) + coverage calc + tint estimate | 6–9 d |
| Delivery handoff enrich | 2–3 d |
| Advance/partial payment | 3–5 d |
| Return-to-source | 4–6 d |
| **Core → Paint (ready) sellable online** | **~4–6 weeks** |
| Stage-0 dark-store COD selling (§FIRST MONEY) | **days** (no new code) |

**Readiness restatement (from audit):** ~**70%** generic commerce plumbing is built and production-grade; ~**35–40%** of *this specific margin/hidden-seller/distance vision* exists. The path is **rework + build on a solid base, ~4–6 focused weeks to a sellable Paint-ready unit online** — not a rewrite. And you can be **earning in days** via Stage 0.

---

## 17. OPEN QUESTIONS / ASSUMPTIONS (decide before Phase 1)

**Must decide (change the build):**
1. **Model shape (brief §16):** (A) seller self-serve / Amazon-style vs **(B) managed hybrid** (WorkToGo controls demand+routing+margin+packaging+own dark store; sellers = supply nodes). **My recommendation: (B)**, which your whole plan already assumes — it maximizes control, margin, customer ownership, and Brain data. Honest counter-pressure: (B) carries more ops load + dark-store capital, and sellers have weaker incentive to join when hidden — mitigate by bringing them demand and paying their base in full. A blend (B-core + optional shown sellers for seller-locked categories) is already baked in via `seller_visibility`.
2. **Margin stacking rule:** override (recommended) vs additive — confirm.
3. **Which delivery path is live:** native `DeliveryEngine` (recommended) vs external SwiftDeliver — confirm so one can be retired.

**Recommend-only (OPEN, do not finalize — brief §3; CA for legal §19):** exact margin %, settlement frequency, legal posture (facilitator vs inventory vs **hybrid split** — track own vs facilitated sales separately from day one), refund/custom-cancellation policy, delivery pricing table + free-delivery threshold value, category risk rules, seller↔customer contact policy.

**Assumptions I made (flag if wrong):**
- The never-touch rule still holds — all margin/visibility logic goes in the **adapter + ledger + config**, never inside `shopping-engine`.
- `app_settings` is the canonical runtime-config store (confirmed: `lib/payment/PaymentGatewayManager.php:133-138`).
- Native `DeliveryEngine` is the intended delivery path and the rider system is a separate, later task.
- No local PHP runtime → everything here is static-code-grounded; runtime proof is a staging step.

---

*End of plan. Nothing built. Awaiting CEO decisions on §17 (1–3) before any Phase-1 code.*
