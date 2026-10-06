# WorkToGo Commerce — System Blueprint (Source of Truth)

**From:** Claude Code · **To:** CEO / WORQ (CTO) · **Type:** SYSTEM BLUEPRINT — planning only, no code written.
**Status:** source of truth for commerce system **DESIGN**. Phase-1 execution (what we build first, UI/UX, tests, order, my recommendations) = [commerce-v1-build-spec.md](commerce-v1-build-spec.md); GTM/first-money = [commerce-launch-plan.md](commerce-launch-plan.md); code evidence = [commerce-launch-audit.md](commerce-launch-audit.md).
**Founding principle (CEO, 2026-10-06):** *Independent experiences, shared core capabilities, separate lifecycles, configurable Unit rules, future integration without forced coupling.* Commerce runs standalone; Service runs standalone; both on ONE shared WorkToGo Core. **Phase-1 does NOT merge Painting Service with Painting Commerce — and Commerce must never modify the Service Engine** (see §N + the architecture map below).
**SCOPE LOCKED (CEO FINAL, 2026-10-06):** build the FULL core + payment + delivery — **production-complete and founder-free — BEFORE launch**, on the existing code (rewrite a piece only if it genuinely can't serve the vision); then **freeze the core** and grow by **Units via config + small rules, never core rework.** Read the **FINAL SCOPE LOCK** section below before A–W.
**Grounds on existing code — reuse-first.** Every "EXISTS" ties to a file+line from the audit. We are **adding into the existing `worktogo` system, not building a parallel one.**
**Reads with:** [structure-lock.md](structure-lock.md) (category = data, not engine), [money-and-earnings-flow.md](money-and-earnings-flow.md), [predicted-problems.md](predicted-problems.md), [category-job-flows.md](category-job-flows.md).

---

## 0. How to read this · Decision-log rule (§25)

- **Doctrine:** One Core System + Unit-specific Business Rules. The core executes; the **Unit** defines behaviour. Nothing about a Unit is hardcoded in core.
- **The one test this whole doc serves (Founderless Operation, §S):** *if the founder is unreachable tomorrow, can a normal order complete Customer → Seller → Delivery → Payment → Settlement → Completion with no founder phone call?* Every design choice is judged by this.
- **Reuse legend:** 🟢 EXISTS (reuse as-is) · 🟡 EDIT (extend existing) · 🔴 BUILD (new) · ⚪ CONFIG (data/rule, no code).
- **Decision-log rule:** when a rule is set or changed, record `Problem → Analysis → Decision → Rule`, and on change `Old Rule → New Rule → Reason → Impact`. Seed log at the end (§Decision Log).
- **Grammar for every major flow (§27):** `Actors → Roles → States → Events → Rules → Data → Actions → Notifications → Exceptions → Fallbacks → Escalations → Financial Effects`, each with **Normal / Failure / Recovery** paths (see §A and §O).

---

## Actors & system map (the nervous system, §13)

| Actor | Is | Primary surface | Touches |
|---|---|---|---|
| Customer | demand | `app/commerce` storefront | order, pay, track, return |
| Seller | supply node (NOT employee) | seller dashboard (`vendor/*`) | catalogue, stock, ON/OFF/BUSY, accept/prepare, settlement |
| Delivery provider | fulfilment (pluggable) | rider/partner app (next task) | pickup, deliver, proof, earnings |
| Ops team (seats, not people) | run normal + exceptions | ops console (🔴 build) | routing exceptions, seller/delivery/customer issues |
| Finance | money integrity | admin finance (🟡) | settlement, refunds, reconciliation |
| Admin / System control | rules & config | `heart/api/admin/*` | units, rules, RBAC, audit |
| Founder | strategy + rare exceptions only | admin | high-impact overrides only |

**Nervous system principle:** an order/event fires → related subsystems react automatically → state updates → notifications go out → next action is triggered. Not disconnected modules. Today's seed of this is `EventEngine::emit('order_created')` (`OrderService.php:938-962`) + `FcmNotifier` + `notifications`/`alerts` tables — 🟡 EXTEND into a real event bus (§G).

---

## Core/Brain vs Engines — architecture & reuse map (CEO §17–18)

```
                         WORKTOGO CORE / BRAIN
   identity · auth · RBAC · payment infra · ledger/finance ·
   notification/event · delivery capability · cities · admin · audit
                                 │
         ┌───────────────────────┼───────────────────────┐
         │                       │                       │
   SERVICE ENGINE          COMMERCE ENGINE           DELIVERY
   (do NOT touch)          (Phase-1 focus)        (own task; contract here)
         │                       │                       │
     Service UI             WorkToGo Commerce          Rider UI
   (existing app)         (own clean experience)     (next task)
         └───────────────────────┼───────────────────────┘
         independent lifecycles · shared core · future link = project layer (docs only)
```

**Component → where it lives → classification** (🟢 reuse / 🟡 edit·activate / 🔴 build · evidence from audit):

| Capability | Lives in | Class | Note |
|---|---|---|---|
| Identity / accounts | `users`, `vendors`, JWT (`lib/auth/`, shopping README) | 🟢 shared | one account, two experiences (§H) |
| Auth / OTP | `lib/auth/OtpChannelManager.php` | 🟢 shared | — |
| RBAC | `roles`/`permissions`/`role_permissions`, `Permission::enforce` | 🟡 **activate** | seeded but DORMANT (only legacy `role='admin'` gates) |
| Payment infra | `lib/PaymentService.php`, `lib/payment/`, `config/payment.gateways.php`, webhook `api/payment/webhook.php` | 🟢 shared | enable online for commerce (§P) |
| Ledger / finance | `core/helpers/LedgerEngine.php`, `wallet_transactions`/`vendor_wallets`/`driver_wallets` | 🟢 shared / 🟡 edit | rework **order branch only**; booking branch = service, untouched; `reference_type` split preserved |
| Notification / event | `core/helpers/EventEngine.php`, `FcmNotifier`, `notifications`/`alerts` | 🟡 edit | extend to event bus (§G) |
| Delivery capability | `core/delivery/DeliveryEngine.php`, `delivery_rate_cards`, `deliveries` | 🟢 shared / 🟡 edit | enrich handoff + modes (§F) |
| Cities / location | `cities`, `core/helpers/CityResolver.php` | 🟢 shared | distance/range on top (§C/§D) |
| Admin / control | `heart/api/admin/*` | 🟢 shared / 🟡 edit | add commerce config + unit rules |
| Audit / logging | `logs`, `Logger` | 🟡 edit | uniform audit trail (§V) |
| **Commerce catalogue/cart/order** | `body/shopping-engine/*` (never-touch engine) | 🟢 engine | reused via adapter, not forked |
| Stores / dual-model | `stores`/`store_types` (`migrations/2026_08_22_001...`) | 🟢 | company + vendor supply nodes |
| **Commerce adapter** | `heart/api/shop/*` | 🟡 edit | the seam: margin, visibility, routing, online pay |
| **Commerce UI** | `app/commerce/*` | 🟡 edit | becomes the standalone *WorkToGo Commerce* app |
| Supply routing | — | 🔴 build | none today |
| Orderability resolver (§C) | — | 🔴 build | 4-layer gate |
| Unit config / rules (§M) | — | 🔴 build | Unit = config, not engine |
| Margin config + seller_base (§E) | `app_settings` + new cols | 🔴 build | commission→margin |
| **Service engine (boundary)** | `body/service-engine/*`, `bookings`, `processBookingCompletion` | ⛔ reference-only | **DO NOT modify for commerce** |

**Coupling rule:** Commerce ↔ Service share **only** through the Core (ledger `reference_type`, delivery, payment, notifications, RBAC, cities). Commerce must **never** call the service booking flow. The only Commerce↔Service link is a **future `project` layer — documented (§N), not built in Phase-1.**

---

## FINAL SCOPE LOCK (2026-10-06, CEO authoritative) — read before A–W

*Recorded from the CEO's FINAL Scope note (2026-10-06). Supersedes earlier "lean MVP / phased / parallel-bootstrap" framing. Execution detail: [commerce-v1-build-spec.md](commerce-v1-build-spec.md).*

**Decision:** build the FULL commerce **core + payment + delivery — production-complete and founder-free — BEFORE launch**, on the existing ~70% code (rewrite a piece only if it genuinely can't serve the vision, never for taste). Then **freeze the core**; growth = new Units via **config + small per-unit rules, never core rework**. Start ONE Unit (paint), few shops, grow shops, then next Unit when it profits. *CEO reasons (recorded):* service business funds the runway (commerce isn't sole income); founder controls demand onboarding (no "demand before ready" risk); 2026 customers won't tolerate a janky MVP; a later rewrite of a half-built system is riskier than building once.

**Overrides (struck from earlier docs):**
- Founder as an ops-seat (my earlier C5) → **REMOVED.** Founder is never operational labour, at any point.
- Parallel manual own-stock COD bootstrap for first revenue (my earlier Q-A / launch-plan §FIRST MONEY) → **REMOVED.** No manual bootstrap.
- "Founder-touches/order trends down over pilot" → **replaced:** founder-touches = **0 is a day-1 design requirement.** Normal = automatic; non-normal → ops/support console; only the rare few → founder.

**Scope additions to A–W (design level):**
- **Delivery = full production-grade PWA** (installable from browser; native app later) — authoritatively upgrades §F/§J from "minimal surface." Rider: ON/OFF · jobs · accept · pickup(proof) · route · deliver(OTP/proof) · earnings. **Robust auto-handling (no stuck states):** no-accept-in-timeout → auto-reassign to next; item missing at pickup → reroute/soft-split/ops; delivery fail → reassign. Delivery is the **shared network for all future Units, built once.** Native `DeliveryEngine` is the path; **retire external SwiftDeliver.**
- **Admin Control Room (zero-code operating system)** — extends §L:
  1. every rule = **live-editable configured value** (timeouts, radius, margins, free-delivery threshold, rider pay, settlement freq, offer retries, soft-split) — no deploy.
  2. **Pause/Kill switch** at every level: whole commerce · Unit · zone · seller · rider.
  3. **Serviceable radius per zone** (expandable per situation).
  4. **Loss-proof price floor** — never accept an order where margin < cost + delivery (admin-set); every price/profit auto-calculated.
  5. **"Nothing available near you" honest state** — defined (no false promise, no blank screen).
  6. **Full manual override on any order** (reassign/cancel/refund/force-complete/change seller|rider).
  7. **Config-change audit log** (who changed which value, when).
  8. **All money auto-calculated** everywhere (customer price, seller base, margin, delivery, rider pay, who-we-owe, profit 24h/weekly/monthly).
- **Support & Human-Decision layer** — extends §K/§O (full UI later, but **hooks built now**; **live tracking + an exception ticket per stuck order from day one**):
  1. **Support windows** (tickets from any order): rider-not-arrived/stuck · not-delivered/wrong/damaged · complaint/help · payment issue. Each ticket = {order ref, type, owner, SLA, action, escalate}.
  2. **Human-decision window** (system flags → human approves): large/high-value > admin threshold → hold; unusual/risky → flag pre-dispatch; unsure → park (never auto-force).
  3. **Live tracking on EVERY order** — customer sees rider location/stage; ops sees full timeline + state + current owner. No order invisible/stuck.
  4. **Escalation ladder:** system → ops seat → manager/founder (only the rare few).
  5. **Communication tied to order:** customer↔support, ops↔seller, ops↔rider.
  6. **Owner view** (one screen): live orders, open exceptions, money in/out, profit (24h/wk/mo), per-area/unit/seller/rider performance. Owner watches, doesn't operate.
  Every item above is a **window into the same system — not a separate app.**
- **Related/suggested products** — in scope, **simple rule-based** (same category / same brand / frequently-bought-together / also-available-near-you). NOT ML (Brain later).
- **Multi-device responsive** — seller + customer, any browser (phone/laptop/tablet), zero-friction, no mandatory app install.
- **Seller self-seed** — a company store and a vendor shop are treated **identically**; which seeds the first Unit (own stock vs a known shop) is a **go-live choice, not a build dependency.** Support both; hard-code neither.

**Routing now vs Brain later (distinct):** NOW = deterministic if-else (seller: ON+range+stock+company-first→nearest→offer/timeout→next; rider: nearest available→timeout→next) + **signal capture** on every order. LATER (Brain) = reliability ranking/demand/dynamic pricing, swapped behind the **same routing interface, no core rewrite.**

**STILL NOT NOW — hard boundary (rejected from this build):** Brain/ranking/ML · demand prediction/heatmaps · custom-tint made-to-order (paint V2) · true multi-seller basket optimization · advanced dark-store network · service↔commerce project link · multi-city · native mobile apps · full support UI. **Build the DATA HOOKS now** (signals, event bus, order timeline, flags) so these turn on later — **build none of the features now.**

**DONE (not "code written"):** the **full loop runs founder-free on staging with ~20 real test orders** (catalogue → order → route → seller accept → rider auto-assign → deliver → pay → settle), every exception handled by system/ops. Guardrails against the real risk (scope-creep / never finishing): hard Still-Not-Now boundary · build on existing ~70% (no rewrite-for-taste) · config-driven Units so the core is finishable.

**Open values (CEO tunes; defaults never block the build):** margin % (per category/zone) · delivery fee (base/per-km/free-above threshold) · rider pay/delivery · settlement freq (24h/3-day/weekly) · auto-reassign timeout (**default 2 min**) · large-order approval threshold · serviceable radius/zone · loss-proof price floor. Delivery pending (configurable, defaults noted): (1) fee shown separately + free-above **(recommended)** vs baked-in; (2) first riders = shop's own delivery person / one hired rider → then own network; (3) auto-reassign timeout default **2 min**.

---

## A. CORE COMMERCE FLOW — the state machine (never changes per Unit)

**Design rule:** keep the *commercial* state on `orders.status`; keep *fulfilment* sub-state on the free-form `orders.delivery_status` (varchar today, `database-structure.sql:550`) + `deliveries.status` — so we do NOT over-expand the strict enum. Payment on `orders.payment_status`.

**Canonical order states** (existing enum `pending/confirmed/processing/shipped/delivered/cancelled/refunded`, `database-structure.sql:544` — mapped, not rewritten):

| Blueprint state | Stored as | Meaning | Owner |
|---|---|---|---|
| CREATED | `pending` | order placed, not yet routed | system |
| ROUTING | `pending` + `delivery_status='routing'` | selecting eligible seller(s) | system (§D) |
| OFFERED | `pending` + `delivery_status='offered'` | sent to a seller, awaiting accept | seller |
| ACCEPTED | `confirmed` | seller committed stock | seller |
| PREPARING | `processing` | seller packing | seller |
| READY | `processing` + `delivery_status='ready'` | ready for pickup → delivery handoff | seller→system |
| PICKED / OUT | `shipped` + `delivery_status='out_for_delivery'` | with rider | delivery |
| DELIVERED | `delivered` | customer received | delivery |
| COMPLETED | `delivered` + `ledger_status='processed'` | settled | system |
| REJECTED/REROUTE | back to ROUTING | seller declined/timed out → next seller | system |
| CANCELLED | `cancelled` | by customer/seller/ops per rules | varies |
| RETURN→REFUND | `refunded` (+ return sub-states §H/§O) | source-aware return | ops/system |
| FAILED | `cancelled` + reason | unrecoverable | system→ops |

**Normal path:** CREATED → ROUTING → OFFERED → ACCEPTED → PREPARING → READY → OUT → DELIVERED → COMPLETED(settle).
**Failure path (examples):** OFFERED→(no response/timeout)→REROUTE; ACCEPTED→(stock gone)→REROUTE/CANCEL; OUT→(delivery failed)→recovery (§F/§O).
**Recovery path:** every non-terminal failure routes to either auto-reroute (system) or an Ops queue with a defined owner — never to a founder phone call.

**Reuse:** order creation + multi-vendor split + FOR UPDATE inventory locks 🟢 (`OrderService.php:67-330`). Accept/Reject transitions 🟢 (`updateVendorOrderStatus:429`) but **no timeout/auto-reroute → 🔴 BUILD** the ROUTING/OFFERED/timeout layer (§D). A background **lifecycle cron** (pattern already exists for delivery, `cron/`) drives timeouts/escalation.

---

## B. SELLER OPERATING MODEL

Seller runs their own business; WorkToGo connects them to a digital supply network. Dashboard stays simple: **ON / OFF / BUSY · Products · Stock · Incoming orders · Accept/Reject · Mark Ready · Status · Settlement · Notifications · Basic info.**

**Seller availability states (🟡 EDIT — today only `vendors.is_online` 0/1, `database-structure.sql:822`; `stores.status`):**

| State | Meaning | Orderability effect |
|---|---|---|
| ON | "I can receive WorkToGo orders now" | eligible for routing |
| OFF | "don't send new orders" | excluded from routing (existing orders continue) |
| BUSY | temporary unavailable (recommend **time-boxed**, auto-clears) | excluded from NEW routing until clear |

**Rule:** ON/OFF/BUSY is **not a UI toggle only** — it gates backend routing + orderability (§C, §D). 🔴 BUILD: add `busy_until` + make the routing query read availability.

**Onboarding (the scalable catalogue model, §2):** a WorkToGo field member visits the shop and digitizes products / brands / pack sizes / base prices / stock / images into the catalogue. Seller is NOT forced to operate complex software — their daily job is only *"stock aaya / badla → update karo."* 🔴 BUILD: a lightweight field-onboarding capture + a dead-simple seller stock screen.

EXISTS: catalogue CRUD 🟢 (`vendor/products.php`), accept/reject + realtime 🟢 (`vendor/orders.php:171`, `shared/realtime.js`). BUILD: BUSY, settlement self-view, onboarding capture.

---

## C. PRODUCT AVAILABILITY — the four-layer rule (critical)

**Catalogue Exists ≠ Seller Active ≠ Stock Available ≠ Orderable.** A product in the catalogue must NOT blindly show as buyable.

| Layer | Source | Check |
|---|---|---|
| Catalogue exists | `products.status='active'`, not deleted | 🟢 `heart/api/shop/index.php:113` |
| Seller active | seller ON + `stores.status='active'` + in city | 🟡 today checks store status/city, **not ON/OFF/BUSY** |
| Stock available | `inventory.quantity - reserved > 0` (or backorder) | 🟢 `:114` |
| **Orderable** | all above **AND the Unit's rule** (visibility, substitution, min-order, risk class) | 🔴 BUILD **orderability resolver** |

**Decision:** introduce a single **`isOrderable(product, seller, unit, customer)`** resolver in the `/api/shop` adapter that composes all four layers + Unit rules. Browse, cart-add, and checkout all call it (today checkout re-validates city via `shopAssertCity` — extend that seam). This is the gatekeeper that stops "catalogue me hai isliye dikhado."

---

## D. SUPPLY ROUTING — eligible-seller selection + fallback (biggest BUILD)

Founder must **never** manually decide "kis seller ko phone karun." The core picks.

**Today:** none — products are single-seller rows; there is no routing engine (audit §1,§3). The schema to support it EXISTS (`stores` city/geo/priority, `inventory`). So routing is 🔴 BUILD on an existing data foundation.

**Flow (grammar):**
```
Customer Order → Unit/Product → Eligible Sellers
  → filter: ON? · stock? · in range? · delivery feasible? · risk-class ok?
  → rank: reliability · distance · acceptance history · commercial terms · priority (company-first)
  → SINGLE-SHOP-BASKET preference (whole basket from one seller = one pickup) 
  → offer → accept/prepare ; else fallback to next ; company dark-store = always-complete fallback
```

**Offer mechanic (RECOMMEND):** **sequential offer with timeout** (offer to top-ranked seller → `accept_window` seconds → no/decline → next). Cleaner accounting + less seller spam than parallel broadcast. Parallel first-accept-wins is a later optimization. *(Decision #4.)*

**Substitutability is per-Unit config (⚪, §5 of brief):** `exact` (sealed paint, fittings) · `equivalent` (needs customer approval, never silent swap) · `fungible` (veg) · `locked` (custom tint → the producing seller only). States **EXACT / EQUIVALENT / SIMILAR** carried on the offer.

**Single-shop basket rule (⚪+🔴):** try to fulfil the whole basket from ONE seller; only split/fall back if incomplete; own dark-store completes the remainder.

BUILD: routing service (filter+rank+offer+timeout+reroute), reliability/acceptance scoring (seed rule-based; Brain later §13 of plan), basket-grouping.

---

## E. COMMERCIAL ENGINE — flexible, per-Unit (not hardcoded)

System must support (configurable per Unit): **Seller Base → WorkToGo margin/commercial rule → Customer Price**, and separately delivery charge, discount, commission/fee where applicable, seller settlement, delivery-partner earning, WorkToGo contribution, refund/adjustment.

**Core change (from audit):** code today **deducts commission from the seller** (`LedgerEngine::processOrderCompletion:150-247`, `:193-196`). Target = **margin/reseller**: seller gets base in full, WorkToGo keeps the spread. Per-Unit the commercial model can differ (margin for paint; commission could still apply for a future seller-locked Unit like food) — so the engine must be **rule-driven, not single-model.**

| Piece | Where | Reuse |
|---|---|---|
| Margin policy (platform/category/product, per-Unit) | `app_settings` JSON + new `categories.margin_*`/`products.margin_*` | 🔴 BUILD (pattern 🟢 `lib/payment/PaymentGatewayManager.php:133-138`) |
| Capture seller base vs customer price | new `order_items.seller_base_price`, `orders.seller_subtotal` | 🔴 BUILD |
| Apply margin | in `/api/shop` adapter + cart read path (engine untouched) | 🟡 EDIT |
| Settlement split | rework **order branch only** of `LedgerEngine`; leave `processBookingCompletion` (service commission) intact; preserve `reference_type='order'`/`'booking'` (`:179`) | 🟡 EDIT |
| Company dark-store | `vendor_share=0`, full margin (mirror the service "company self-fulfil 100%" branch) | 🟡 EDIT |
| True cost/profit | snapshot `products.cost_price` (`:639`, unused today) into order_items | 🔴 BUILD (for Brain) |

**Stacking rule (RECOMMEND):** override `product ?? category ?? platform` + optional flat floor per category. *(Decision #2.)* **Margin must be thin** — local customers know shop prices; sell convenience, not markup (risk §W).

---

## F. DELIVERY ENGINE — independent, multi-mode capability

Delivery is **not** "order aaya, rider ko phone karo." It is an independent, scalable capability. Commerce only hands off; delivery owns routing. **Full rider-system design is the NEXT task — here we lock only commerce's contract + the mode abstraction.**

**Today:** native `DeliveryEngine` 🟢 — distance pricing via `delivery_rate_cards` (base/per-km/min/`free_above_subtotal`/rider-share), `createForOrder/quoteServerSide/cancelForOrder`, rider auto-assign, `deliveries` states `pending/assigned/picked/delivered/cancelled`. Handoff 🟢 `heart/api/shop/index.php:249`. A legacy external SwiftDeliver path also exists (`OrderService::dispatchToDelivery:965`).

**Handoff contract (commerce → delivery) — 🟡 enrich:**
```
{ order_id, pickup{store_id,lat,lng,addr}, drop{lat,lng,addr},
  items[{sku,qty,weight_kg,volume}], total_weight_kg, total_volume,
  ready_vs_custom, cod_vs_prepaid, cod_amount, flags[fragile|heavy|perishable] }
```
Weight/volume drives vehicle (20L paint ≈ 25kg → loader, not scooter).

**Multi-mode (🔴 abstraction, 1 mode now):** architecture must allow WorkToGo rider / seller-delivery / local partner / 3P network behind one `delivery_mode` — today start with native rider; **do not block tomorrow's modes.** Own rider is NOT compulsory in the architecture.

**Rider experience (clean, no internal commerce data):** `Assigned → Pickup → Route → Customer → Delivery → Proof → Earnings`.

**Decision #3:** confirm native `DeliveryEngine` is the live path and **retire external SwiftDeliver** (avoid double-dispatch).

---

## G. NOTIFICATION / EVENT ARCHITECTURE — part of core, not a UI feature

**Rule:** important events fire → system notifies the right party → drives the next action. Model as an **event bus**, not scattered calls.

**Today (🟡 EXTEND):** `EventEngine::emit('order_created')` (`OrderService.php:938`), `FcmNotifier`, `notifications` + `alerts` tables. In-app works with no keys; push needs FCM (post-launch); one known gap — `cron/auto_assign.php` writes an alert but no push (per `LAUNCH/alert-notification-flow.md`).

**Event → audience map (seed):**
| Event | Seller | Customer | Delivery | Ops |
|---|---|---|---|---|
| order_created | new order (accept) | confirmed | — | live board |
| seller_accepted | — | preparing | — | — |
| order_ready | — | — | pickup assigned | — |
| out_for_delivery | — | out for delivery | route | — |
| delivered | — | delivered + rate | earnings | — |
| timeout/reject/stock_zero/payment_fail/delivery_fail | as relevant | "action required" | — | **exception queue** |

🔴 BUILD: a typed event registry + subscriber routing + per-role notification preferences.

---

## H. CUSTOMER EXPERIENCE — simple, backend hidden

`Product/Service → Price → Availability → Delivery → Order → Payment → Tracking → Completion`. Customer never solves the supply network's internal problems; sees one WorkToGo result (per Unit visibility, §M). Seller-network complexity is invisible.
- **Normal:** browse (nearby, orderable only) → cart (single-shop preference) → pay (online primary / COD) → track states → delivered → (rate/return).
- **Failure the customer sees:** only clean messages ("item not available in your area," "action required," "refund initiated") — never raw routing failures.
- Reuse 🟢 `app/commerce/shop.js`; 🟡 strip seller fields per Unit (§M), add distance/range, add online pay + tracking.

---

## I. SELLER EXPERIENCE — onboarding → settlement

`Onboard (field-digitized) → set catalogue/stock → go ON → receive order → accept/reject → prepare → mark ready → handoff → settlement visible`. Dashboard = §B list. Seller sees **their** orders, stock, money — nothing of other sellers or internal routing. 🟡 today's `vendor/*` panel covers catalogue+orders; 🔴 BUILD BUSY, settlement self-view, onboarding capture.

---

## J. DELIVERY EXPERIENCE — (contract only; full design = next task)

`Assigned → Pickup (proof) → Route → Customer → Delivery (proof) → Earnings`. Partner sees pickup/drop/mode/fee/earning only. Modes pluggable (§F). Detailed rider flows deferred to the delivery blueprint.

---

## K. OPERATIONS TEAM EXPERIENCE — run normal + exceptions (🔴 BUILD ops console)

Ops is **seats, not people** — founder may fill a seat temporarily, but the seat exists in the system from day one (§S). Console shows: live orders, exception queue (owner + SLA), seller issues, delivery issues, customer issues, escalations. Each exception has a defined **owner → action → fallback → escalation** (§O). This console is what makes operation founder-independent.

---

## L. ADMIN / SYSTEM CONTROL

Units · sellers · products · orders · delivery · finance · **rules/config** · controls · **audit trail**. Today 🟡 `heart/api/admin/commerce.php` (stores/products CRUD). 🔴 BUILD: Unit-config UI (§M), margin/rule config, RBAC activation (§Q), audit log view (§V).

---

## M. UNIT CONFIGURATION — Unit = business operating definition (not category/UI)

A Unit is a **business operating definition**, not a screen or a DB category. Core is fixed; the Unit decides behaviour.

**Unit config schema (🔴 BUILD `unit_config`, ⚪ data per Unit):**
| Config key | Controls |
|---|---|
| `seller_visibility` | hidden / shown / situational (§brief correction) |
| `experience` | product-first / seller-first |
| `substitution` | exact / equivalent / fungible / locked |
| `routing_rule` | eligibility + ranking weights |
| `pricing_model` | margin / commission / hybrid + stacking |
| `delivery_rule` | modes allowed, vehicle constraints |
| `return_warranty` | returnable_if_sealed / no_return / perishable |
| `payment_rule` | online-only / COD-allowed / advance-required |
| `attributes` | per-Unit product fields (paint→shade/base/pack/coverage) |
| `service_link` | connected service Unit (e.g. Painting) |
| `comms_behaviour` | customer messaging tone/steps |

**Lock (from [structure-lock.md](structure-lock.md)):** a Unit is **data + rules + product/service definitions + workflow**, keyed off existing `categories`/`store_types` — **never a new `body/` engine.** Architecture test: adding a Unit must need **zero core-code change**. Today category = data 🟢; the **rules/config layer is the missing piece → 🔴 BUILD** (this is the backbone of "add Unit = config, not code").

---

## N. SERVICE + COMMERCE — DECOUPLED in Phase-1 (future link documented only)

**CEO decision (2026-10-06), overriding the earlier "connect via project layer" plan:** Phase-1 does **NOT** merge Painting Service with Painting Commerce. Commerce proves its own operating loop **standalone**; Service keeps running its existing flow untouched.

- **Independent:** Commerce has its own frontend, customer/seller experience, workflow, order, catalogue, stock, routing, delivery flow, payment/settlement. Service has its own. **No shared transaction, no shared lifecycle, no cross-dependency.**
- **But independent ≠ separate brain:** both reuse the ONE shared Core (identity/auth, RBAC, payment infra, ledger, notification/event, delivery capability, admin, cities, audit) — see the architecture map above. We do **not** duplicate Core.
- **Hard boundary:** Commerce planning/work must **not** modify the Service Engine — not its booking flow, states, assignment, pricing, or completion. Service Engine is reference-only (⛔ in the map).
- **Future possibility (documented, NOT a Phase-1 dependency):** later, if real data shows a combined journey helps, a **`project` layer** could sit ABOVE both — linking a `booking_id` (service) and `order_id`(s) (commerce) for a unified customer view while keeping two independent money lifecycles (service commission branch vs commerce margin branch). Example cross-links (future only): in Commerce "Painting Service bhi chahiye?"; in Service "Required paint/material order?". **Design this connection separately when the need is real — do not pre-build it.**

**Why decoupled now:** lower complexity, faster real revenue, and a clean proof that the Commerce core runs founder-independent on its own — before adding any service coupling.

---

## O. EXCEPTION & RECOVERY — design the unhappy paths first

For **every** important state, a failure case with: `Detection → Owner → Action → Fallback → Escalation → Customer comms → Financial impact → Final state`. Seed register (🔴 build handlers; many are gaps per [predicted-problems.md](predicted-problems.md)):

| Exception | Detection | Owner | Fallback | Escalation | Final |
|---|---|---|---|---|---|
| Seller OFF/BUSY at order | routing | system | next seller / dark-store | ops if none | rerouted |
| Seller no-response (timeout) | lifecycle cron | system | reroute | ops | rerouted/cancel |
| Seller reject | event | system | reroute | ops | rerouted |
| Stock suddenly 0 / mismatch | checkout lock (🟢 FOR UPDATE) / confirm | system | reroute / partial | ops | adjusted |
| Price change mid-order | pricing | system | honor quoted price | ops | honored |
| Wrong product/qty delivered | customer/rider proof | ops | return-to-source + redeliver | finance | refund/replace |
| Duplicate order | rate-limit (🟢 `OrderService:763`) | system | block | — | prevented |
| Payment fail | webhook (sole authority) | system | retry / COD / cancel | ops | resolved |
| Customer/seller cancel | event | per rule | inventory release (🟢 `:337`) | ops | cancelled |
| Rider unavailable / pickup fail / late / delivery fail | delivery | delivery ops | reassign (🟢 `cancelForOrder`/reassign) | ops | redelivered |
| Customer unavailable | rider | delivery ops | retry / return | ops | resolved |
| Damaged / return / refund / warranty | event | ops+finance | source-aware return (§brief) | finance | refunded |
| Service+commerce coordination fail | project layer | Unit operator | decouple lifecycles | founder (rare) | resolved |

**Rule:** no exception terminates at "call the founder." It terminates at a **system action, an ops owner, or a defined escalation** — founder only for strategic/high-impact.

---

## P. FINANCE & SETTLEMENT

- **Customer payment:** online primary (Cashfree, 🟡 enable — dead code `OrderService:49,191-211`; webhook = sole paid authority), COD secondary (ready goods), advance/partial for made-to-order (`payment_status='partially_paid'` enum 🟢 `:546`).
- **Seller settlement:** seller gets **base price in full**; margin spread → platform. `vendor_wallets` pending→available; `runSettlement` is **manual pending→available, NOT a bank payout today** (per [money-and-earnings-flow.md](money-and-earnings-flow.md)) → 🔴 decide payout mechanism + frequency *(Decision #7, business value — recommend weekly).*
- **Delivery earning:** `delivery_fee` → rider share (rate card). COD cash tracked `driver_wallets.cash_in_hand` 🟢.
- **Refund/adjustment:** reversal row `wallet_transactions.status='refunded'`, `reference_type='order'`.
- **Own vs facilitated sales = separate accounting tracks from day one** (§legal, §15 of brief) — 🔴 tag every order track.

---

## Q. PERMISSIONS & ROLES — least privilege (schema exists, enforcement dormant)

Per role define: **Can View / Act / Approve / Override / Escalate.** No one gets full admin.

**Today:** RBAC tables + seeded roles (`super_admin`, `commerce_admin`, `catalog_manager`, `dispatch_ops` — migration `2026_08_22_001...:425-473`) 🟢, **BUT enforcement is DORMANT** — only legacy `role='admin'` gates the panel and bypasses `Permission::enforce` (per [launch-readiness audits]). So: 🟡 **activate** RBAC enforcement + add the ops/finance/unit-operator/seller-ops/delivery-ops/support seats (§Actors). This directly enables founder-independence (team can act without founder's login).

---

## R. SCALE MODEL — more orders ≠ more founder labour

Target: `More Sellers → More Supply · More Products → More Choice · More Orders → More Transactions` — **without more founder decisions.** Scale test (§21): 100 sellers / 1,000 products / 1,000 orders must NOT require founder to make 1,000 manual decisions. The levers that make this true: routing engine (§D, no manual seller pick), orderability resolver (§C, no manual availability), event bus (§G, no manual status chase), ops console + RBAC (§K,§Q, team acts), rule/config per Unit (§M, new Unit = config). `1 Unit → 10 → 50` reuses the same core.

---

## S. FOUNDER DEPENDENCY AUDIT — and removal plan

Current founder/manual dependencies and how each is removed:

| Dependency today | Why | Removal |
|---|---|---|
| Manual seller selection per order | no routing engine | §D routing (system) |
| "Is it really in stock?" | catalogue≠stock not enforced on availability | §C orderability + seller-confirm + stock state |
| Manual status chasing | no event bus | §G events |
| Founder is the only admin | RBAC dormant | §Q activate + ops seats |
| Manual cash/settlement | COD-only, manual settlement | §P online pay + payout |
| Founder as seller/packer/stock | inventory-heavy own-store default | network supply (§2) + dark-store only where data proves (§9 of brief) |
| Exception handling by founder | no ops console/SOP | §K + §O + §T |

**Founderless test verdict (today):** ❌ — a normal order cannot complete without founder/manual touch (no routing, no online pay, dormant RBAC). **This doc's build set flips it to ✅.**

---

## T. OPERATIONAL SOP — where humans are needed, exact steps

For each human-touch point, a written SOP: **seller onboarding** (field digitize → verify → go live), **stock dispute** (seller-reported vs actual), **exception triage** (queue → owner → action → escalate), **refund approval**, **settlement run**. Rule (§22): any task the founder repeats → `Manual → SOP → System Workflow → Team/Partner → Automation`. Founder's early manual work = **learning + process discovery**, not permanent operation.

---

## U. DATA & STATE OWNERSHIP

| State/data | Owner (who may change) |
|---|---|
| Catalogue (product info) | Seller (+ WorkToGo field onboarding) |
| Live stock | Seller only |
| Seller ON/OFF/BUSY | Seller only |
| Order commercial state | System (via defined transitions) |
| Routing decision | System |
| Delivery state | Delivery provider / system |
| Payment state | Payment webhook (sole authority) |
| Margin / Unit rules | Admin only |
| Settlement | Finance (system-computed, finance-released) |
| RBAC / config | Admin only |

Single-owner-per-state prevents drift. Enforced via RBAC (§Q).

---

## V. AUDIT / EVIDENCE

Every important action recorded: who/what/when/old→new. Today `logs` table 🟢 + `Logger` calls across engines; order/ledger have timestamps + `ledger_status`. 🔴 BUILD: a uniform audit trail for state changes, margin/rule edits, overrides, refunds — queryable in admin (§L). This is also the evidence base for disputes (§9 "code is the contract").

---

## W. RISK REGISTER (technical / operational / financial / business)

| # | Risk | Type | Mitigation |
|---|---|---|---|
| R1 | Margin visible to price-aware local customer → defection | business | thin margin, sell convenience; admin can set near-zero at launch |
| R2 | Stock-truth ("ON"≠in stock) → wrong/absent item kills trust | operational | mandatory seller-confirm + timeout/reroute (§D), stock state (§C) |
| R3 | Overselling / concurrency on stock | technical | 🟢 FOR UPDATE reserve (`OrderService`); extend to seller-reported updates |
| R4 | Informal sellers, no SKU/barcode | operational | branded-first (paint/fittings); canonical match only where branded |
| R5 | Small-basket unit economics bleed | financial | single-shop basket, free-above threshold, bigger-ticket Units first |
| R6 | Seller adoption when hidden | business | bring them demand they'd never get; pay base in full |
| R7 | Dark-store capital / spoilage / liability | financial | demand-first (warehouse after data, §9); separate accounts |
| R8 | Dormant RBAC → unsafe team access | technical | activate RBAC (§Q) before adding seats |
| R9 | Double-dispatch (native + SwiftDeliver) | technical | retire one path (Decision #3) |
| R10 | Service+commerce lifecycle entanglement | technical | project layer, separate money lifecycles (§N) |
| R11 | Payment integrity | financial | webhook sole authority, idempotent ledger (🟢 patterns exist) |
| R12 | Founder single-point-of-failure | business | the entire §S removal plan |

---

## Decisions needed from CEO (non-blocking — I proceed on *recommended* unless you change)

| # | Decision | Recommended | Alt |
|---|---|---|---|
| 1 | Model shape | **(B) managed hybrid** (WorkToGo routes demand; sellers = supply nodes) | (A) seller self-serve |
| 2 | Margin stacking | **override** `product ?? category ?? platform` + flat floor | additive |
| 3 | Live delivery path | **native `DeliveryEngine`**, retire SwiftDeliver | keep external |
| 4 | Routing offer mechanic | **sequential offer + timeout** | parallel first-accept |
| 5 | BUSY semantics | **time-boxed auto-clear** + manual | manual only |
| 6 | Ops as seats from day 1 (founder fills temporarily) | **yes** | founder-only until later |
| 7 | Settlement frequency (business value, OPEN) | weekly, online-primary | — (CEO/CA) |
| 8 | Service+Commerce coupling | **separate lifecycles, linked by `project_id`** | single transaction |

**Still OPEN / needs-CA (do not finalize — brief §3/§19):** exact margin %, legal posture (facilitator vs inventory vs hybrid-split; GST ECO/TCS, FDI), refund + custom-cancellation policy, delivery price table + free-delivery value, seller↔customer contact policy.

**Clarifying questions where your intent genuinely forks the build (answer when you can):**
- **Q1 (first-money vs this blueprint):** the leanest first-revenue path (own dark-store COD, §9 of launch-plan) works *before* this full blueprint is built. Do you want to **run that in parallel to earn/learn** while the founder-independent system is built, or hold revenue until the system can run founder-free? *(Recommend: run in parallel — it's learning + cash, as long as we treat it as process-discovery, §22.)*
- **Q2 (Painting first Unit scope):** V1 = **service (painting job) + ready material commerce** connected at the project layer? Or commerce-only ready paint first, service-link second? *(Recommend: connected project from the start, but ship ready-material commerce before custom-tint.)*
- **Q3 (seller stock trust):** at launch, do we trust seller-reported stock + seller-confirm, or require a stricter stock proof for high-value items? *(Recommend: seller-confirm + timeout now; stricter proof is a later risk control, R2.)*

---

## Decision Log (§25 — seed; append on every change)

- 2026-10-05 · **Doc structure** — *Problem:* system blueprint vs existing launch plan risk scatter. *Decision:* new `commerce-system-blueprint.md` = source-of-truth system design; `commerce-launch-plan.md` = sequencing companion; `commerce-launch-audit.md` = evidence. Cross-linked. *Rule:* architecture/behaviour decisions live HERE.
- 2026-10-05 · **Seller visibility** — *Old:* "seller always hidden" (launch-plan §4 earlier). *New:* per-Unit `seller_visibility` {hidden/shown/situational}. *Reason:* CEO correction — food is seller-first, paint is product-first. *Impact:* §C/§M/§H composer reads Unit config; engine never hardcodes.
- 2026-10-05 · **Money model** — *Old:* commission (code today). *New:* margin/reseller, per-Unit rule-driven. *Reason:* founder vision + scalability. *Impact:* §E ledger rework (order branch only), new seller_base capture.
- 2026-10-06 · **Service+Commerce coupling** — *Old:* connect Painting Service + Commerce via a `project` layer in this build. *New:* **fully decoupled in Phase-1**; future link documented only (§N). *Reason:* CEO — lower complexity, faster revenue, prove Commerce core standalone + founder-independent first. *Impact:* no `project` build now; Service Engine is reference-only/⛔ never-modify; §N rewritten; added the Core/Brain-vs-Engines architecture map.
- 2026-10-06 · **Commerce experience** — *Old:* commerce as a storefront on the service app. *New:* standalone *WorkToGo Commerce* experience, one account / separate optimized surfaces; dynamic, Unit-config-driven UI (not hardcoded category screens). *Reason:* CEO — avoid customer confusion, enable config-driven Unit expansion. *Impact:* `app/commerce` becomes its own app shell; see [commerce-v1-build-spec.md](commerce-v1-build-spec.md) §UI.
- 2026-10-06 · **Build strategy (FINAL)** — *Old:* lean production-capable MVP, phased, founder optional ops-seat weeks 1–3, parallel manual COD bootstrap for first revenue. *New:* **FULL core+payment+delivery, production-complete + founder-free BEFORE launch**, then freeze core; no manual bootstrap; founder-free = day-1 requirement. *Reason:* CEO FINAL scope (service funds runway · founder controls demand · customers won't tolerate janky MVP · rewrite riskier than build-once). *Impact:* struck C5/Q-A; added FINAL SCOPE LOCK; build-spec reframed to a complete build plan.
- 2026-10-06 · **Delivery scope** — *Old:* minimal rider surface (my C3). *New:* full production PWA, shared delivery network for all Units, built once, robust auto-handling. *Reason:* CEO FINAL. *Impact:* §F/§J upgraded; rider PWA in this build; retire SwiftDeliver confirmed.
- 2026-10-06 · **Admin + Support scope** — *Old:* admin CRUD + ops console (basic). *New:* zero-code **Admin Control Room** (8 items: live config, kill switches, radius, loss-proof floor, honest empty state, full override, config-audit, money auto-calc) + **Support/Human-Decision layer** (tickets, human-approval window, live tracking every order, escalation, order-tied comms, owner view). *Reason:* CEO FINAL. *Impact:* extends §K/§L/§O; hooks built now, full support UI later.

---

*End of blueprint. Nothing built. This is the planning source of truth; implementation references it only after the §Decisions are confirmed. Where intent is ambiguous (Q1–Q3), I have flagged rather than assumed.*
