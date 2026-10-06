# WorkToGo Commerce — Complete Build Plan (production-complete · founder-free)

**From:** Claude Code (my independent technical + business recommendation — not a repeat of the brief) · **To:** CEO
**Type:** EXECUTION / implementation plan — planning only, NO code until the CEO approves this complete paper plan. Approved version = implementation source of truth.
**Scope authority:** CEO FINAL Scope (2026-10-06), recorded as *FINAL SCOPE LOCK* in [commerce-system-blueprint.md](commerce-system-blueprint.md). Evidence: [commerce-launch-audit.md](commerce-launch-audit.md).
**Frame (LOCKED):** build the **FULL** commerce core + payment + delivery — **production-complete and founder-free — BEFORE launch**, on the existing ~70% code. Then **freeze the core**; grow by Units (config). One Unit first = **Paint/Material**. No lean/throwaway MVP. No manual bootstrap. Founder is never operational labour.

> **DONE = the full loop runs founder-free on staging with ~20 real test orders** (catalogue → order → route → seller accept → rider auto-assign → deliver → pay → settle), every exception handled by system/ops. "Code likh diya" ≠ done.

---

## 0. HONEST READINESS AGAINST THIS (bigger) SCOPE — and the reuse-max rule you asked for

The audit's "~70% ready" is **generic commerce plumbing.** Against *this* scope (full, founder-free, with routing + online pay + delivery PWA + admin control room + support/exception layer + margin ledger), honest readiness is **~40–50%** — because the founder-free machinery (routing, event bus, ops/support, RBAC enforcement, margin ledger) is the hard, mostly-unbuilt half. **This is a real build, not a finish.** But per your instruction (max reuse, don't rebuild, scale fast), the plan below **reuses the existing engine/ledger/delivery/payment/auth as the spine and builds only the missing founder-free layer on top** — no rewrite-for-taste.

**Reuse verdict per big piece (evidence in audit):**
- 🟢 **Reuse as spine:** order core + inventory locks (`body/shopping-engine`), cart, dual-store schema (`stores`), native delivery pricing + nearest-rider (`core/delivery/DeliveryEngine.php`), payment infra + webhook (`lib/PaymentService.php`, `lib/payment/`, `api/payment/webhook.php`), auth/JWT, cities (`CityResolver`), `wallet_transactions`/wallets, `logs`.
- 🟡 **Edit/activate:** `/api/shop` adapter (margin, visibility, routing, online pay), `LedgerEngine` **order branch only** (commission→margin), event layer (`EventEngine`/`FcmNotifier`) → event bus, admin (`heart/api/admin/commerce.php`) → control room, **RBAC enforcement (seeded but dormant)**, vendor UI → single-page console, `app/commerce` → standalone responsive app, delivery handoff + rider surface → PWA.
- 🔴 **Build new (the founder-free layer):** routing engine, orderability resolver, `unit_config`, margin config + `order_items.seller_base_price`, ops/support console + exception tickets + live tracking, human-decision window, admin kill-switches + loss-proof floor + money dashboards, related-products rules, rider PWA.

---

## 1. WHAT THIS BUILD INCLUDES — and the hard boundary

**IN (built once, then frozen as core):** catalogue · team-assisted seller onboarding (one-time catalogue; seller adds stock as goods arrive; stock = real state) · seller ON/OFF/BUSY gating visibility+routing · 4-layer orderability · deterministic routing + signal capture · single-shop basket + soft-split + own-stock completion · 3-tier margin pricing · order + checkout · online payment (Cashfree) + COD fallback · settlement (seller base full, margin to platform, fee to rider; own-stock = full margin; earnings visible the moment delivery completes; who-we-owe + profit view) · payout trigger · **full delivery PWA** · live order tracking · customer account · simple related/suggested products · seller single-page console · ops/support console + exception tickets + human-decision window · notifications/event bus · audit/evidence · RBAC activation · source-aware return/refund (sealed goods) · **Admin Control Room** (zero-code) · multi-device responsive.

**STILL NOT NOW (hard boundary — rejected from this build; data-hooks only):** Brain/ranking/ML · demand prediction/heatmaps · custom-tint (paint V2) · true multi-seller basket optimization · advanced dark-store network · service↔commerce project link · multi-city · native mobile apps · full support UI.

### My earlier challenges — resolution under FINAL scope
- **C1 (single-shop + soft-split)** → **ACCEPTED, in scope.** Good — don't strand customers.
- **C2 (deterministic routing now, signals for Brain later)** → **ACCEPTED, in scope.** Matches "routing now vs Brain later."
- **C3 (minimal rider surface)** → **OVERRIDDEN** by CEO → full delivery **PWA** with robust auto-handling. Agreed given "customers won't tolerate janky delivery."
- **C4 (COD bridge while Cashfree KYC)** → reframed as a **go-live sequencing note** (below), not a scope cut: build online-primary + COD-fallback (configurable); only the *enablement* of live online pay waits on Cashfree PROD KYC.
- **C5 (founder ops-seat)** → **OVERRIDDEN/REMOVED.** Founder-free is a day-1 design requirement; ops seats are non-founder roles.

---

## 2. THE EXPERIENCES — UI/UX recommendations (Recommendation · Why · Reuse · Risk)

**Dynamic rule (all surfaces):** UI is driven by `unit_config` (blueprint §M) — category presentation, product attributes, seller visibility, pricing display, availability, delivery options, substitution, customer actions. No hardcoded per-category screens. Everything **responsive** (phone/laptop/tablet, any browser, no mandatory install).

### 2.1 Customer — *WorkToGo Commerce* (standalone, responsive)
- **Rec:** location-aware home ("near you" + search + config-driven category tiles) → product list (card: image, name, pack/attribute, price, availability/ETA, add) → cart (single-shop grouped, soft-split prompt) → checkout (online-first, saved address/map pin) → **live tracking (rider location + stage)** → order history + reorder + **related/suggested** (rule-based). Seller shown/hidden per Unit (paint = hidden, one unified WorkToGo result).
- **Why:** 2026 customers expect app-grade ordering + live tracking + online pay = trust; product-first for commodities removes "choose a shop" confusion.
- **Reuse:** 🟡 `app/commerce/shop.js` + `api.js` (`/api/shop/*`) already browse/cart/checkout/track — extend; `addresses` + `CityResolver` 🟢.
- **Risk:** honest availability (never false "in stock"); honest empty state (admin-defined "nothing near you"); search quality with few SKUs (category+simple match, ranking later).

### 2.2 Seller — single-page console (your explicit ask)
- **Rec:** **ONE responsive page, no page reloads.** Big **ON / OFF / BUSY** toggle (green=ON), instant/optimistic, **writes real routing state** (not cosmetic). Same page (sections, skeleton loaders so loading isn't felt): Incoming orders (accept/reject/ready) · Products · Live stock (one-tap ± update) · Settlement (earnings the moment delivery completes) · Notifications. Works for a seller with **one product**; no 100-field form (team-assisted onboarding seeds the catalogue).
- **Why:** local shopkeeper, not an Amazon seller — glance-and-act beats features.
- **Reuse:** 🟡 consolidate `vendor/products.php` + `vendor/orders.php` into one shell reusing the same APIs + `shared/realtime.js`; `vendors.is_online` 🟢 (add BUSY + `busy_until`).
- **Risk:** re-shelling the vendor UI is the main front-end cost (reuse endpoints, only re-shell); stock-update must be one tap + offline-tolerant.

### 2.3 Rider — full delivery PWA (installable)
- **Rec:** installable PWA (browser "install"; native later). ON/OFF · job list · accept · pickup(proof) · route(map link) · deliver(OTP/proof) · earnings. **Robust auto-handling:** no-accept-in-timeout → auto-reassign; item missing at pickup → reroute/soft-split/ops; delivery fail → reassign. Clean — no internal commerce data.
- **Why:** delivery is the shared network for all future Units and the #1 trust risk; must not be janky.
- **Reuse:** 🟢 `DeliveryEngine` pricing + nearest-rider auto-assign + `deliveries` states; 🔴 the PWA surface + auto-reassign loop (lifecycle cron pattern exists in `cron/`).
- **Risk:** proof/fraud (photo+OTP); offline queueing; retire external SwiftDeliver to avoid double-dispatch.

### 2.4 Ops / Support — the console that keeps the founder out
- **Rec:** live orders board + **full order timeline + current owner** on every order + **exception tickets** (rider-not-arrived/stuck · not-delivered/wrong/damaged · complaint · payment issue), each {order ref, type, owner, SLA, action, escalate} + **human-decision window** (large/risky/unsure orders held for approval) + customer↔support / ops↔seller / ops↔rider messaging tied to the order.
- **Why:** this is what makes "founder-touches = 0" real — normal = system, non-normal = here, rare = escalate.
- **Reuse:** 🟡 feeds from the event bus + `alerts`/`notifications`; 🔴 console + tickets + timeline.
- **Risk:** depends on a reliable event bus + live state — build those first; the console must read state directly, not only react to push (so nothing is missed).

### 2.5 Admin Control Room (owner view) — zero-code operating system
- **Rec:** one screen for the owner: live orders · open exceptions · money in/out · profit (24h/wk/mo) · performance per area/unit/seller/rider. Plus the control levers: **live-editable rules** (all timeouts/radius/margins/threshold/rider-pay/settlement/retries/soft-split) · **kill switch** (commerce/Unit/zone/seller/rider) · **serviceable radius/zone** · **loss-proof price floor** · **full manual override** on any order · **config-change audit log** · **all-money auto-calc**.
- **Why:** owner watches + tunes the business without operating orders; "complete operating system from admin."
- **Reuse:** 🟡 `heart/api/admin/commerce.php` + 🟡 RBAC activation; 🔴 config store (`app_settings`-backed), kill switches, dashboards, audit view.
- **Risk:** activating dormant RBAC can lock admins out — stage it behind a flag, keep super_admin all-access.

---

## 3. ROUTING / DELIVERY / PAYMENT / EXCEPTIONS (V-this-build subset of the blueprint)
- **Routing (blueprint §D):** deterministic — eligible = ON + in-range + stock + risk-ok; order = company-first → nearest; **sequential offer + timeout (default 2 min) → next → ops**; single-shop-preferred + soft-split; own-stock completion. **Signal capture** on every order (accept/reject, response time, stockouts, distance, margin, complaints) = Brain fuel. No ML.
- **Delivery (blueprint §F + §Delivery PWA above):** enrich handoff (weight/volume, ready-vs-custom, COD-vs-prepaid, flags); native `DeliveryEngine`; `delivery_mode` abstraction (own rider / shop's own person / hired → own network — configurable); robust auto-reassign.
- **Payment (blueprint §E/§P):** online-first (Cashfree, webhook = sole paid authority) + COD fallback (configurable per Unit/risk); capture `seller_base_price` + 3-tier margin in the adapter; rework ledger **order branch only**; real **payout trigger** (today settlement is manual pending→available, not a payout); separate own-vs-facilitated accounting; **loss-proof floor** enforced pre-accept.
- **Exceptions (blueprint §O):** every failure → system auto-action or an ops ticket with an owner — never a founder call. Live tracking + a ticket per stuck order from day one.

---

## 4. MY INDEPENDENT RISK REGISTER (Prob · Impact · Prevention · Detection · Recovery)
*(my analysis, not a repeat of the brief)*

| # | Risk | P | I | Prevention | Detection | Recovery |
|---|---|---|---|---|---|---|
| X0 | **Scope-creep / never finishing** (the CTO's own stated #1 risk of "build it all") | High | High | hard Still-Not-Now boundary; DONE=20 founder-free staging orders; config-driven Units; build on existing ~70% | milestone burndown vs the 11 steps | cut to the frozen core; defer anything off-list |
| X1 | Stock mismatch ("in stock" isn't) | High | High | mandatory seller-confirm; one-tap stock; reserve-on-checkout (🟢 FOR UPDATE) | confirm-step stockout rate/seller | auto-reroute / soft-split / refund |
| X2 | COD fake/abuse orders | Med | Med | online-first; rate-limit (🟢 `OrderService:763`); phone/address verify; large-order human-approval | COD cancel spikes/user | block, require prepay |
| X3 | Online pay reconciliation drift (paid, not advanced) | Med | High | webhook sole authority + idempotent ledger | paid-without-advance query | webhook replay / finance fix |
| X4 | Seller bypass (deal direct) | High | High | seller hidden per Unit; contact not exposed; value = demand+delivery; thin margin | repeat off-platform patterns | enforce T&C |
| X5 | Concurrency (last unit, two orders) | Med | Med | FOR UPDATE reserve; unique keys | oversell logs | cancel/reroute one + apologize/refund |
| X6 | Ledger wrong after commission→margin | Med | High | seller_base captured; order branch only; keep `reference_type` split; test vectors | per-order split audit | reversal rows; freeze payout until verified |
| X7 | Event/notification failure → missed exception | Med | High | event bus w/ retries + dead-letter; ops reads state directly | unacked events / stuck states | ops board = source of truth |
| X8 | RBAC activation locks admins out | Med | Med | flag-gated; super_admin all-access; staged | lockout reports | flag rollback |
| X9 | Delivery economics negative (fee<cost) | High | Med | min-fee + free-above + loss-proof floor + bigger-ticket Unit first | per-order margin-vs-delivery | tune rate card / min basket |
| X10 | Delivery PWA complexity (bigger than it looks: proof, offline, reassign, maps) | High | High | reuse `DeliveryEngine`; thin PWA + robust auto-reassign; staging dress-rehearsal | rider failure rate in test orders | manual ops reassign fallback |
| X11 | Cashfree PROD KYC delays go-live | Med | Med | build online-ready; COD-configurable fallback so build/launch isn't blocked | KYC status | launch on COD, switch online on approval |
| X12 | Refund on COD (no prepaid to reverse) | Med | Med | define cash-refund SOP + wallet-credit option | return events w/o refund path | ops cash refund / UPI / store credit |
| X13 | Unit-config leaks into code (category hardcoded) | Med | High | config-driven UI + orderability; architecture test (§M) | diff touching per-category logic | refactor to config before next Unit |

## 5. EVIDENCE-LOG MODEL (compulsory; DONE = Implemented + Tested + Evidence + Operationally verified)

| Req | Existing evidence | Current | Gap | Recommendation | Impl | Test | Evidence | Status |
|---|---|---|---|---|---|---|---|---|
| Margin pricing | `LedgerEngine:193-196`, `OrderService:316` | commission; total=subtotal | no margin | 3-tier config + seller_base | — | split vectors + sample order | — | ⬜ |
| Seller ON/OFF/BUSY gates routing | `vendors.is_online:822` | cosmetic, no BUSY, routing ignores | add BUSY + gate | state read by routing | — | toggle→excluded | — | ⬜ |
| Orderability 4-layer | `heart/api/shop/index.php:113-114` | product+store+city+stock | no ON/OFF+unit rule | resolver | — | state matrix | — | ⬜ |
| Routing + signals | — | none | build | deterministic + capture | — | offer→timeout→reroute | — | ⬜ |
| Online payment | `OrderService:49,191-211`, `lib/PaymentService` | COD-locked, online dead | enable+webhook | adapter online path | — | ₹1 real pay + webhook | — | ⬜ |
| Delivery PWA + auto-reassign | `DeliveryEngine`, `deliveries` | handoff basic, no PWA | build PWA + auto-handling | reuse engine + thin PWA | — | no-accept→reassign | — | ⬜ |
| Admin control room | `heart/api/admin/commerce.php` | CRUD only | config/kill/floor/money | build on `app_settings` | — | live rule edit no deploy | — | ⬜ |
| Ops/support + tickets + tracking | `alerts`/`notifications`,`EventEngine` | partial | console + tickets + timeline | build | — | stuck order → ticket+owner | — | ⬜ |
| RBAC | migration `2026_08_22_001:425-473` | dormant | enforce | activate (flagged) | — | role can't cross-act | — | ⬜ |
| Return/refund (sealed) | `OrderService:337` (cancel) | no return flow | build source-aware | — | — | return→source→refund | — | ⬜ |
| Audit trail | `logs`,`Logger` | partial | uniform state+config log | build | — | change recorded | — | ⬜ |

## 6. TEST PLAN
- **Unit:** margin resolution (override + floor + loss-proof), ledger split vectors (base+margin+company-100%), orderability matrix, routing offer/timeout, auto-reassign.
- **Integration (staging):** full happy path + each exception (X1–X13 mapped), kill-switch + manual-override paths, human-decision hold.
- **Money:** ₹1 real online pay + webhook; COD cash reconciliation; payout dry-run; per-order profit auto-calc check.
- **Operational (the DONE gate):** **~20 real test orders run founder-free on staging**, every exception resolved by system/ops; measure founder-touches = 0.
- No local PHP → runtime proof is a staging step.

## 7. FOUNDER-DEPENDENCY = 0 (day-1 design requirement, not a trend)
Blueprint §S maps every dependency → removal. The build is not DONE until a normal order completes with **zero** founder touch and exceptions are handled by ops/support. Founder surfaces (Admin Control Room) are for **watching + tuning**, not operating.

## 8. QUESTIONS I STILL NEED (to lock the plan; defaults in brackets won't block the build)
Most earlier questions are now resolved by the FINAL scope. Remaining:
- **Q-D (seller payout):** mechanism + frequency? [bank/UPI; weekly] — needed so settlement's payout trigger is real, not manual-wallet.
- **Q-F (returns):** for sealed-goods returns, who bears return-delivery cost (customer / platform / seller) and what's the return window? [platform-bears within 48h, configurable]
- **Q-G (invoice/GST):** do we issue a customer invoice/receipt at launch? (touches legal/CA) [simple receipt now; GST invoice when CA confirms]
- **Q-H (first delivery mode):** launch riders = a shop's own person, one hired rider, or straight to own small pool? [one hired rider + shop-own fallback, configurable]
- Conflicts flagged to you: see §9.

## 9. FLAGS / CONFLICTS (per the FINAL scope's "flag before proceeding")
1. **Readiness honesty:** against this full founder-free scope, true readiness is **~40–50%, not 70%** (the 70% is generic plumbing; the founder-free half is the real build). Not a blocker — just set expectations on effort/time.
2. **"Build it all before launch" + the CTO's own scope-creep warning** are in tension. Mitigation is already yours: the hard Still-Not-Now boundary + the DONE gate. I've encoded both; I'll hold the line on them (X0).
3. **Online-pay founder-free depends on Cashfree PROD KYC** (external, calendar-time). Non-blocking via configurable COD fallback (X11) — but it means "online-first" at go-live may start as "COD-first, online switched on at approval." Flagging so it's a known go-live step, not a surprise.
4. **Delivery PWA is the single biggest new build** (X10) — reusing `DeliveryEngine` keeps pricing/assignment, but proof/offline/maps/auto-reassign/rider-earnings is substantial. It's correctly in scope; just the largest line item.

---

## 10. FINAL IMPLEMENTATION ORDER (after CEO approval — no code before that; core built once, then frozen)

```
STEP 0  Approve this plan + answer Q-D…Q-H. Design unit_config + the evidence tracker.            [gate]
STEP 1  CORE money: margin config (app_settings + category/product cols) + order_items.seller_base_price
        + rework LedgerEngine ORDER branch (service untouched) + loss-proof floor.  Test: split vectors. (X6)
STEP 2  Orderability resolver (4-layer) + seller ON/OFF/BUSY gating routing.
STEP 3  Deterministic routing (company-first→nearest→offer/timeout→next→ops) + single-shop+soft-split
        + signal capture.                                                                         [needs S2]
STEP 4  Event bus + notifications + order timeline/flags (the hooks everything else reads).        [needs S1-3]
STEP 5  Payment: enable Cashfree online + webhook; COD configurable fallback.                      [parallel S1-4]
STEP 6  Delivery PWA: enrich handoff + rider PWA + robust auto-reassign (reuse DeliveryEngine).    [needs S3-4]
STEP 7  Ops/Support console: live tracking every order + exception tickets + human-decision window
        + order-tied messaging.                                                                    [needs S4-6]
STEP 8  Admin Control Room: live config + kill switches + radius + loss-proof + full override
        + config-audit + money dashboards (who-we-owe/profit).                                     [needs S1-7]
STEP 9  Settlement payout trigger + own-vs-facilitated accounting + return/refund (sealed) + COD-refund SOP. [needs S1,5]
STEP 10 Customer app polish: standalone responsive WorkToGo Commerce, location/range, dynamic Unit-config UI,
        live tracking, history/reorder, related-products, seller-visibility composer.             [needs S1-7]
STEP 11 RBAC activation + ops/finance/seller-ops/delivery-ops seats + uniform audit trail.         [needs S7-8]
---- GATE: staging dress-rehearsal — ~20 real orders, founder-touches = 0, all exceptions by system/ops. ----
---- Core FROZEN. Paint Unit live (ready/sealed material). Then: grow shops → next Unit by config. ----
```
Steps 1–5 are the money/decision spine; 6–8 the founder-free operation; 9–11 money-integrity + experience + safety. Nothing off the **Still-Not-Now** list enters this build.

---

*End of build plan. Nothing built. Awaiting CEO approval + Q-D…Q-H. On approval, this + the blueprint are the implementation source of truth; coding starts only then, and the DONE gate (20 founder-free staging orders) decides completion.*
