# READY-paint — final-product readiness map + master missing-note (PLAN/VERIFY ONLY)

**Date:** 2026-10-12 · **By:** Claude Code (CTO partner) · **For:** CEO (Aakash)
**Base:** commerce engine + Paint unit code-complete, **785/0 on MariaDB 10.11.19** (audit commit `0b47bf4`). READY paint launches first; custom tint dormant (`commerce_custom_tint_brands=[]`). **This doc is thinking + documentation only — no code changed, no UI touched. Nothing fixed, only recorded.** Citations are `file:line`.

---
## PART A — What is COMPLETE (status map)
| Feature | Status | Proof |
|---|---|---|
| Margin model (customer_price = seller_base + slab) | complete+proven | `MarginEngine::priceFromBase` ([MarginEngine.php:171]), `slabMargin` ([:144]); s12 30/0, p3 |
| Loss-proof floor | complete+proven | `MarginEngine::lossProofOk` ([MarginEngine.php:97]); e2e (160 ok / 20 reject) |
| Orderability 4-layer gate + kill-switches | complete+proven | `OrderabilityResolver::check` ([OrderabilityResolver.php:65]); s2 14/0, p1 |
| Routing (company-first→nearest, source-pref) | complete+proven | `RoutingEngine::rankBy`, `onOrderPlaced` ([RoutingEngine.php:194]); s3 21/0, sf1 7/0 |
| Accept-gates-dispatch (rider after seller accept) | complete+proven | `SellerConsole::accept`; s15 31/0, p3, full-journey 53/0 |
| Seller console (ON/OFF/BUSY, inbox, accept/reject/stock-out, earnings) | complete+proven | `SellerConsole` (s15 31/0) |
| Seller catalogue (offer, base, stock, verify gate) | complete+proven | `SellerCatalogue::createOffer`/`setStock` ([SellerCatalogue.php:46,100]); s16 22/0, p3 |
| Customer-ID (opaque, seller-isolated, no PII) | complete+proven | `CustomerId`; s14 22/0, full-journey |
| Delivery handoff + OTP + pickup/deliver | complete+proven | `CommerceDelivery` ([CommerceDelivery.php:46,89,101]); s6 23/0 |
| Delivery quote + absorb → one server price | complete+proven | `DeliveryPricing::composeFinalPrice` ([DeliveryPricing.php:92]); s13 40/0, p3 |
| Settlement — margin spread + delivery margin (order branch) | complete+proven | `LedgerEngine::processCommerceOrderSettlement` ([LedgerEngine.php:555]); s1 25/0, e2e, p3 |
| Returns — source-aware, risk-class, refund | complete+proven | `CommerceReturns`; s9 25/0, p5 |
| Advance/partial payment seam (commerce-scoped) | complete+proven | `CommercePayment` + webhook branch; p4 19/0 (bookings untouched) |
| Ops console (exception queue, claim/escalate/resolve, holds, comms) | complete+proven | `OpsConsole` ([OpsConsole.php:34,53,60,71,82]); s7 24/0 |
| Admin control room (live config allowlist, kill-switches, money dashboard) | complete+proven | `AdminControlRoom::setConfig` guard ([AdminControlRoom.php:70]); s8 27/0 |
| RBAC seats + audit | complete+proven | s11 35/0; `/api/commerce-admin` 401 without seat |
| KYC seller+rider (submit→review→approve) | complete+proven | `KycService`; s18 22/0, full-journey |
| Event bus + flags | complete+proven | `CommerceEventBus::emit`/`flagRules` ([CommerceEventBus.php:42,67]); s4 16/0 |
| Paint attributes + 7 categories + unit hook | complete+proven | `PaintUnit` + `UnitConfig`; p1 24/0 |
| Coverage calculator (estimated, no 2-coat double) | complete+proven | `CoverageCalculator`; p2 39/0 |
| Ready-paint founder-free order e2e | complete+proven | p3 24/0 |
| Custom-tint structure (per-brand, dormant) | complete+proven | `CustomTint` + p7 20/0; live `[]` |
| Prisma fandeck loaded (21,115 shades) | complete | `commerce_paint_shades`=21,115 |
| **Rider cash-to-collect on screen** | **MISSING** | `/api/rider/deliveries` payload ([rider/index.php:77]) + `rider.js` ([rider.js:118]) — see #2 |
| **Seller-accept timeout auto-reroute (scheduled)** | **PARTIAL** | `RoutingEngine::expireStaleOffers` ([RoutingEngine.php:255]) exists, no cron — see #1 |
| **Paint product weight at offer** | **MISSING** | `SellerCatalogue::createOffer` sets no `products.weight` (DEFAULT NULL) — see #3 |
| Custom-tint PRICING (quote/ceiling/band/lock) | MISSING (design in Part C1) | no code yet; MarginEngine reusable |

---
## PART B — MASTER MISSING-NOTE (everything to make READY paint real, UI polish EXCLUDED)
Severity: **LAUNCH-BLOCKER** (needed before any real order) · **PRE-SCALE** (before the 500-order run) · **LATER**.

| # | What (backend/logic/endpoint/data/cron/config) | Seat | Why it matters | Severity | Smallest path |
|---|---|---|---|---|---|
| B1 | **Rider payload missing cash-to-collect** — `/api/rider/deliveries` ([rider/index.php:77]) omits `cod_amount`/`is_prepaid`/`balanceDue`; data exists on `orders` (via `enrichHandoff` [CommerceDelivery.php:71]) + `CommercePayment::balanceDue`. (#2) | Rider | A COD delivery with no collect figure is operationally unsafe | **LAUNCH-BLOCKER** (COD) | Add those 3 fields to the SELECT/response (read-only; the on-screen line is the deferred-UI step) |
| B2 | **Cashfree live/sandbox creds + online ₹1 round-trip** — webhook path proven locally (s5), but real online capture needs `CASHFREE_*` on the host (finding #5) | Customer/Admin | Prepaid online can't actually take money without it | **LAUNCH-BLOCKER** (prepaid) | Hosting config + one ₹1 round-trip; no code |
| B3 | **`expireStaleOffers` not scheduled** — ([RoutingEngine.php:255]) only the s3 test calls it; `cron/delivery_lifecycle.php` runs rider lifecycle only. (#1) | Seller/Admin | A seller who ignores an order → sub-order stays `offered`, customer order hangs | **LAUNCH-BLOCKER (light)** | One line `RoutingEngine::expireStaleOffers($db);` in a commerce cron |
| B4 | **Ledger namespace bootstrap alias** (finding #6) — commerce refund path uses a `class_alias` shim; prod should alias `Core\Helpers\Database`/`Logger` in bootstrap | All | Refund/settlement path could fatal on host without it | PRE-SCALE | Add the alias in prod bootstrap (no engine edit) |
| B5 | **Paint weight at offer** — `createOffer` sets no `products.weight`; `enrichHandoff` sums `qty×COALESCE(p.weight,0)` ([CommerceDelivery.php:53]) → heavy paint always `bike`. (#3) | Seller/Rider | 10/20L paint dispatched on a bike | PRE-SCALE | `createOffer` set weight = pack_size × density (~1.3 kg/L), or a per-pack weight attribute |
| B6 | **Delivery-price + absorb ↔ ledger reconciliation (real numbers)** — `deliveries.price/rider_payout/platform_fee` are set by DeliveryEngine at dispatch; the ledger reads them ([LedgerEngine.php:634-658]). Not proven end-to-end with live absorb numbers (harnesses set them manually) | Admin/Rider | Delivery margin + rider pay + absorb net must be correct | PRE-SCALE (verify-point) | Validate in the ~20 staging orders; no code expected |
| B7 | **Vehicle-doubt / big-value escalation to ops** — `needsConfirmCall` ([CustomTint.php:139]) + high-value flag ([CommerceEventBus.php:55]) exist, but no event raises a flag for "vehicle unsure on a big/multi-item order" (Part C2) | Admin/Ops | Big ₹10-20k multi-item orders need a human vehicle call | PRE-SCALE | New event+flag `vehicle_doubt`→needs_ops, raised from the dispatch rule (Part C2) |
| B8 | **~20 founder-free staging orders** on the host (the money/absorb/COD gate) | All | Proves the whole chain with real money | PRE-SCALE | Run on host after B1-B3 |
| B9 | **Custom-tint PRICING pieces** — seller price quote, margin-ceiling, price-band-per-shade, lock-per-litre, confirm-call wiring (Part C1) | Seller/Customer/Ops | Only needed when a custom brand is switched on | LATER | Build per Part C1 when a brand goes live |
| B10 | **Confirm-call / custom-price-outlier escalations** not wired to ops flags (Part C3) | Ops | Human checkpoints for custom + outliers | LATER | New events→flags per Part C3 |
| B11 | **MariaDB ≥10.5 on host** (satisfied on local 10.11) | All | SKIP-LOCKED + COMMENT/CHECK + collation | LAUNCH (hosting) | Confirm host version |

*(UI screen polish for all four apps is intentionally EXCLUDED here — deferred to after functional completion.)*

---
## PART C — Design of the three open pieces (how they SHOULD work; exists vs missing; NOT built)

### C1 — COLOR / CUSTOM-TINT MARGIN (seller quotes → same engine + ceiling → price-band → lock-per-litre → accept/timeout/reroute)
**How it should work:**
1. Customer picks brand+shade-code (guard already blocks wrong-brand codes). The shade has no fixed price (made to order), so the **seller quotes a per-litre color price** for that base+code.
2. WorkToGo applies the **same `MarginEngine`** on the seller's quote (slab margin, loss-proof floor) — identical to ready paint, so pricing logic is reused, not reinvented.
3. A **margin ceiling** caps the final so a quote can't balloon: `final ≤ seller_quote × (1 + max_uplift%)` AND `final ≤ shade_price_band.p90` (see 4). Over the ceiling → flag for ops (Part C3), don't auto-sell.
4. A **price-band per shade-code** builds over time: each accepted quote appends to a band (min/median/p90) per (brand, shade_code, base, litre). New quotes outside the band → outlier flag. This catches overcharge without a fixed price list.
5. **Lock-per-litre:** once a (brand, shade_code, base) has a settled per-litre price, a **repeat bucket — and a different pack size of the same shade — is priced INSTANTLY** by `per_litre × litres`, no re-ask to the seller. Repeat also routes to the same seller (already built).
6. **Accept box with timeout:** the quote/order offer to the seller has an accept window; **no response → reroute to the next seller** (reuse the existing offer-timeout machinery).

**What EXISTS:** `MarginEngine::priceFromBase`/`slabMargin`/`lossProofOk` ([MarginEngine.php:171,144,97]) — reusable verbatim on a seller quote · `CustomTint::resolveShade`/`brandHasCode` ([CustomTint.php:57,70]) · repeat→same-seller `preferredSeller`/`routeFirstSeller` ([CustomTint.php:99,115]) · `recordMade` log ([:89]) · accept/reroute + **offer timeout** `RoutingEngine::expireStaleOffers`/`rerouteOnFailure` ([RoutingEngine.php:255,212]) + `commerce_offer_timeout_sec` ([:29]) · `needsConfirmCall` ([CustomTint.php:139]).
**What is MISSING:** (a) a **seller price-quote capture** endpoint/field for a shade (none today); (b) the **margin ceiling** rule (`max_uplift%` config + check); (c) a **price-band table** per shade-code + the append-on-accept + outlier check; (d) **lock-per-litre** store + instant-price-by-size lookup; (e) wiring the quote offer into the accept-box/timeout so a non-response reroutes (the timeout engine exists but isn't driven — B3); (f) confirm-call flag wiring (C3). All additive in `lib/commerce` + config; **no engine edit**. **Severity LATER** (custom tint dormant).

### C2 — VEHICLE DECISION (rule + doubt→seller-suggestion→human override)
**Rule (EXISTS):** `CommerceDelivery::requiredVehicle($weightKg)` ([CommerceDelivery.php:31]) maps Σweight → vehicle from `commerce_vehicle_thresholds` (bike ≤20 / e_rickshaw ≤80 / else loader); `enrichHandoff` computes Σ`qty×weight` ([CommerceDelivery.php:53]) and writes `required_vehicle` onto the order ([:71]). Proven s6 (25kg→e_rickshaw).
**Gap (#3 / B5):** paint offers carry no weight, so the rule sees 0 → bike. Fix = populate weight at offer.
**New: doubt → ask the seller → options → human override (should work):**
1. Compute Σweight/size as today. If confidence is low — **mixed/bulky multi-item** (e.g. putty + buckets + sundries), or order value in a **big-order band** (reuse `commerce_high_value_threshold` ~₹10-20k, [CommerceEventBus.php:55]), or weight near a threshold boundary — mark the vehicle **"unsure"**.
2. On "unsure", **ask the seller for a vehicle suggestion** (they can see the goods) and present the vehicle **options** (bike/e_rickshaw/loader) with the computed default pre-selected.
3. A **human (ops)** can override the final vehicle; the override is logged and used for dispatch.
**What EXISTS:** the weight→vehicle rule + thresholds config + `enrichHandoff`; ops override scaffold (`OpsConsole::reassignRider`/flags). **What is MISSING:** the **"unsure" trigger** (rule: multi-item/bulky/high-value/near-threshold), a **seller-suggestion field** on the handoff, a **vehicle-doubt ops flag** (new event→`needs_ops`), and the ops **override-vehicle** action. Additive; no engine edit. **Severity PRE-SCALE** (big/multi-item orders).

### C3 — HUMAN-SUPPORT POINTS ("system drives the human")
Every point the system should escalate, telling the human exactly what to decide:
| Trigger | Decision the human makes | Flag/event | Status |
|---|---|---|---|
| Seller-accept timeout / reroute exhausted | reassign or cancel | `order_timeout`/`reroute_failed`→`needs_ops` ([CommerceEventBus.php:45-47]) | EXISTS (but reroute not scheduled — B3) |
| Delivery failed / no rider | reassign rider / hold | `delivery_failed`→`needs_ops` ([:47]) | EXISTS |
| Payment failed | retry / cancel | `payment_failed`→`payment_issue` ([:48]) | EXISTS |
| High-value order | approve/reject hold | `high_value_hold` (threshold [:55]); `OpsConsole::approveHold`/`rejectHold` ([OpsConsole.php:82,91]) | EXISTS |
| **Vehicle doubt** (big/multi-item/near-threshold) | pick/override vehicle | `vehicle_doubt`→`needs_ops` | **MISSING** (C2) |
| **Custom-price outlier** (quote outside the band / over ceiling) | approve/adjust price | `price_outlier`→`needs_ops` | **MISSING** (C1) |
| **Confirm-call needed** (custom / big order) | call to confirm shade/qty, log note | `confirm_call`→`needs_ops` | **MISSING** — `needsConfirmCall` computes it ([CustomTint.php:139]) but nothing raises a flag |
**Scaffold that EXISTS:** `CommerceEventBus::emit`+`flagRules` ([CommerceEventBus.php:42,67]) raise/clear flags; `OpsConsole` exception queue + claim/escalate/resolve + holds + comms ([OpsConsole.php:34,53,60,71,82,91]); SLA ([:23]). **MISSING:** three new event→flag rules (vehicle_doubt, price_outlier, confirm_call) + the triggers that emit them. Additive to the event bus map; no engine edit.

---
## PART D — HOW WE MAKE MONEY (READY paint) — revenue trace, order branch
All revenue is captured in `LedgerEngine::processCommerceOrderSettlement` on the **order branch** (never the booking/engine branch):
1. **Item margin spread** = `subtotal − seller_subtotal` per sub-order → `$platformCommission` ([LedgerEngine.php:613,628]); the seller is paid their base in full ([:612,627]). Company store (no vendor) → whole subtotal is platform ([:604]).
2. **Delivery platform margin** = `deliveries.platform_fee` → `$platformDelivery` ([:639]); rider paid `rider_payout` ([:646]); COD cash owed = `items+delivery−rider_payout` into `cash_in_hand` ([:649-651]).
3. **One platform revenue row** = `platformCommission + platformDelivery` → single `wallet_transactions` platform credit ([:656-658]); order marked `ledger_status='processed'` ([:661]). Verified e2e (platform 160 spread) and p3 (spread 50 + delivery 10 = 60).
**Delivery absorb:** `DeliveryPricing::composeFinalPrice`/`absorb` ([DeliveryPricing.php:92,50]) blends the shown delivery into the one customer price; "absorbed" is paid out of margin ([:76]). The customer sees one price; `shown_delivery` is the only visible delivery line ([:78]).
**No other fee path** for ready paint (no separate platform fee, no commission-on-top — margin model only).
**Flag (not a leak, a verify-point) → B6:** the money is correctly *recorded* from `orders.subtotal/seller_subtotal` + `deliveries.*`; what is NOT yet proven end-to-end is that the **live DeliveryEngine dispatch populates `deliveries.price/rider_payout/platform_fee` consistent with the absorb policy** so the net (item spread − absorbed delivery + delivery margin) is right with real numbers. Mechanism exists + each part is unit-tested; reconcile with the ~20 staging orders. No leak found in the recorded paths.

---
## PART E — LAUNCH DISTANCE + BUILD ORDER
**How far:** READY paint is **functionally ~90% done**. The spine (catalogue→price→order→route→accept→deliver→settle→return, admin, KYC, events) is green at 785/0 and the money paths are recorded correctly. What stands between here and a real prepaid+COD ready-paint launch is a **small, mostly-additive set** — no engine surgery.

**Build order:**
1. **LAUNCH-BLOCKERS (do first):**
   - **B1** — add `cod_amount`/`is_prepaid`/`balanceDue` to the rider deliveries payload (then the rider can see the collect amount; on-screen render is the deferred-UI step).
   - **B3** — schedule `expireStaleOffers` (1 line in a commerce cron) so orders don't hang.
   - **B2 / B11** — Cashfree creds + a ₹1 online round-trip on the host; confirm MariaDB ≥10.5. (hosting)
   - **B4** — ledger namespace alias in prod bootstrap.
2. **PRE-SCALE (before the 500-order run):**
   - **B5** paint weight at offer → correct vehicle · **B7** vehicle-doubt escalation (C2) · **B6** reconcile delivery/absorb money on **B8** ~20 real staging orders.
3. **LATER:**
   - **B9** custom-tint pricing (C1) + **B10** confirm-call/price-outlier escalations (C3) — only when a custom brand is switched on.

**Explicitly deferred:** UI screen review for all four apps is deferred to **after functional completion**, then a **~1-week live-ish run with 500+ orders** before full go-live.

**Verdict:** Not launch-ready *today* for COD/online, but close — **3 launch-blockers (B1 small code, B2 hosting, B3 one line)** + B4/B11 hosting. Clear them and prepaid+COD ready paint is real-world runnable; PRE-SCALE items harden it; LATER items are custom-tint-only.
