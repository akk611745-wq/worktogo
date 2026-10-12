# READY-PAINT go-live — full system verification (VERIFY ONLY)

**Date:** 2026-10-12 · **By:** Claude Code (CTO partner) · **For:** CEO (Aakash)
**Scope:** readiness audit of the whole built commerce engine + Paint unit for the **READY-paint-first** launch, local **MariaDB 10.11.19 (port 3307)**. Custom tint stays dormant. **No engine/business code changed — evidence only. Bugs recorded, not fixed.**

## STEP 0 — Environment + full suite
- **DB (live query via the app's pinned connection):** `VERSION=10.11.19-MariaDB port=3307 charset=utf8mb4 collation=utf8mb4_unicode_ci DB=worktogo`. `.env` → `DB_HOST=127.0.0.1 DB_PORT=3307 DB_NAME=worktogo`. Connection pin confirmed (B3).
- **Full suite (fresh run, this audit):** **785 PASS / 0 FAIL.** Per file:
  `s1 25 · s2 14 · s3 21 · s4 16 · s5 23 · s6 23 · s7 24 · s8 27 · s9 25 · s10 28 · s11 35 · s12 30 · s13 40 · s14 22 · s15 31 · s16 22 · s17 43 · s18 22 · sf1 7 · e2e 22 · scale 10 · full-journey 53 · p1 24 · p2 39 · p3 24 · p4 19 · p5 37 · p6 28 · p7 20 · kyc-ui 31` = **785 / 0**.
- **Live go-live state:** `commerce_custom_tint_brands = []` (no custom brand live) · `commerce_paint_shades = 21,115` (loaded, dormant).

## SEAT 1 — CUSTOMER
| Flow | Evidence | Verdict |
|---|---|---|
| ONE server-side price (base + slab margin + delivery-absorb), no client recompute | `DeliveryPricing::composeFinalPrice` ([DeliveryPricing.php:92]) composes margin+absorb server-side; proven s13 40/0, p3 (base 1000→**1050**) | ✅ |
| Location capture | `app/index.php` geolocation present (browser-pane walkthrough rendered "Haldwani"; pane denied geo = env, not a code fault) | ✅ (code-verified) |
| Place order — prepaid | webhook `paid`→settle (s5 23/0, e2e 22/0) | ✅ |
| Place order — COD | COD order → settle, cash-owed (full-journey 53/0, p3 COD) | ✅ |
| Empty-state honesty (not-available → clean message) | `OrderabilityResolver::message()` returns friendly text for every non-orderable reason (s2 14/0, p1 message test) | ✅ |
| Custom-tint must be BLOCKED (list empty) — cannot take money | p7 20/0 + live `[]` → any custom-tint product → `custom_tint_off`; order-intent → `brand_not_enabled` | ✅ (correctly blocked) |

## SEAT 2 — SELLER
| Flow | Evidence | Verdict |
|---|---|---|
| Order lands on correct seller | routing + sub-order vendor scoping (s3 21/0, s15 31/0, p3) | ✅ |
| accept-gates-dispatch (rider NOT dispatched before accept) | `SellerConsole::accept`→gated dispatch (s15, full-journey, p3 `accept gated`) | ✅ |
| Seller sees own Customer-ID history only, NO PII | `SellerConsole::inbox`/`customerHistory` + `CustomerId` opaque ref (s14 22/0, full-journey "no PII") | ✅ |
| Seller can change quantity up/down anytime | `SellerCatalogue::setStock` ([SellerCatalogue.php:100]) seller-scoped `UPDATE inventory SET quantity=max(0,qty)`, no gating (s16 22/0) | ✅ |
| New seller/product not live until verified (draft→review→approve) | `createOffer` lands `draft` → `approve`; p3 (`draft NOT orderable`→approve→`ok`), KYC s18 22/0 | ✅ |
| No-response timeout → reassign to next seller | logic EXISTS (`RoutingEngine::expireStaleOffers` reroutes offered subs → next → company, [RoutingEngine.php:255], proven s3 R8) **but is not scheduled by any cron** | ⚠️ **PROBLEM #1** |

## SEAT 3 — ADMIN
| Flow | Evidence | Verdict |
|---|---|---|
| Order in live pipeline / ops console | OpsConsole exception queue + flags (s7 24/0) | ✅ |
| Price control + multi-seller routing per source-preference | AdminControlRoom live config + `RoutingEngine::rankBy` (s8 27/0, sf1 7/0) | ✅ |
| KYC: seller submit→review→approve; rider self-register→approve | s18 22/0, full-journey | ✅ |
| Brand enable/disable (`commerce_custom_tint_brands`); empty = nothing custom live | p7 20/0 (enable Berger→live, disable→blocked); live `[]` | ✅ |
| What admin CANNOT do / over-permission | `AdminControlRoom::setConfig` refuses any key not in `editableKeys()` ([AdminControlRoom.php:70]); allowlist is **commerce_* app_settings only** — cannot touch engines, OrderService, LedgerEngine, PaymentService, DB creds, or arbitrary keys. `/api/commerce-admin` gated by `Permission` (live 401 without a seat). **No over-permission found.** | ✅ |

## SEAT 4 — RIDER
| Flow | Evidence | Verdict |
|---|---|---|
| Dispatched only AFTER seller accept | accept-gates (s15, full-journey, p3) | ✅ |
| Pickup + drop detail | `/api/rider/deliveries` returns store_name/locality + drop_address ([rider/index.php:77-90]); rider.js shows Pickup/Drop/Nav ([rider.js:122-126]) | ✅ |
| **BALANCE DISPLAY on the rider SCREEN** (prepaid ₹0 / COD full / advance balance) | `/api/rider/deliveries` payload has **no** `cod_amount`/`is_prepaid`/`balanceDue`; `rider.js` job card shows **no "Collect ₹X" line**. Data exists on `orders` (via `enrichHandoff`, [CommerceDelivery.php:71]) + `CommercePayment::balanceDue`, but the screen never surfaces it | ❌ **PROBLEM #2** |
| Heavy paint (20L) → correct vehicle/weight flag | `enrichHandoff` sums `qty × COALESCE(p.weight,0)` ([CommerceDelivery.php:53]); engine weight→vehicle correct (s6: 25kg→e_rickshaw). BUT `SellerCatalogue::createOffer` sets no `products.weight` (DEFAULT NULL) → paint weight = 0 → always `bike` | ⚠️ **PROBLEM #3** |

## CROSS-CUTTING
| Check | Evidence | Verdict |
|---|---|---|
| Multi-seller routing + source-preference | sf1 7/0, scale 10/0 (multi-seller source, reroute) | ✅ |
| Concurrency: last unit, no oversell (SKIP LOCKED) | scale 10/0 (last-stock gate); SKIP LOCKED works on 10.11 (finding #1 satisfied). *Real parallel-process oversell is a staging load-test, not harness-simulatable here.* | ✅ (staging load-test noted) |
| Margin slab (flat/%/decreasing) + loss-proof floor | s12 30/0, e2e `loss-proof` (160 vs 30 ok; 20 vs 30 reject), p3 paint slab | ✅ |
| Delivery quote + absorb → single server price | s13 40/0, p3 | ✅ |
| Settlement/returns — margin split on order-branch only; return-to-source refund | s9 25/0, e2e (platform 160 spread), p3/p5 (return-to-source; custom-tint no_return) | ✅ |
| Event bus fires lifecycle events | s4 16/0, full-journey (order_created/offered/accepted/...); RoutingEngine emits order_rerouted/timeout | ✅ |

## PROBLEMS (found, NOT fixed — separate fix task)
### #1 — Seller-accept timeout reroute is not scheduled — MEDIUM
- **Where:** `lib/commerce/RoutingEngine.php:255` (`expireStaleOffers`). Only caller in the repo is the test `tests/commerce/s3_routing_vectors.php:119`. `cron/delivery_lifecycle.php` runs the **rider** lifecycle (DeliveryEngine/LedgerEngine) and does **not** call it; no other cron does.
- **Evidence:** `grep -rn expireStaleOffers` → only RoutingEngine (def) + s3 (test). cron list: `agent_demand_detect, agent_reminders, auto_assign, delivery_lifecycle` — none call it.
- **Impact:** a seller who ignores an order leaves the sub-order `offered` indefinitely; it won't auto-reroute to the next seller/company. Ops can reassign manually (S7), so degraded, not dead.
- **Smallest fix:** add one line `RoutingEngine::expireStaleOffers($db);` to `cron/delivery_lifecycle.php` (or a scheduled commerce cron) so it runs every minute on hosting.

### #2 — Rider screen does not show the cash-to-collect / balance — HIGH (for COD)
- **Where:** `heart/api/rider/index.php:77` (the `/api/rider/deliveries` SELECT omits `cod_amount`/`is_prepaid`/balance) and `rider/rider.js:118-126` (job card renders Pickup/Drop/Nav, no collect figure).
- **Evidence:** payload SELECT = `d.id,order_id,status,price,rider_payout,distance_km,order_number,shipping_address,store_name,store_locality`; `grep collect|cod|balance rider/rider.js` → only a `money()` helper, never used for a collect amount. `enrichHandoff` DOES set `orders.cod_amount`/`is_prepaid` ([CommerceDelivery.php:71]) and `CommercePayment::balanceDue` exists — the data is there, just not surfaced.
- **Impact:** on a **COD** ready-paint delivery the rider cannot see how much cash to collect from the screen (and for a future advance order, the balance). Prepaid is unaffected (collect ₹0).
- **Smallest fix:** add `cod_amount`, `is_prepaid` (and `CommercePayment::balanceDue(order)`) to the `/api/rider/deliveries` payload, and render a "Collect ₹X / Prepaid — collect ₹0" line in `rider.js`. No engine change (read-only surfacing of existing fields).

### #3 — Ready-paint products carry no weight → vehicle flag always "bike" — MEDIUM
- **Where:** `lib/commerce/SellerCatalogue.php:46` (`createOffer` never sets `products.weight`); `products.weight DEFAULT NULL` (database-structure.sql:644); `enrichHandoff` sums `qty × COALESCE(p.weight,0)` ([CommerceDelivery.php:53]).
- **Evidence:** `grep weight SellerCatalogue.php` → none in createOffer. Engine weight→vehicle itself is correct (s6 proves 25kg→e_rickshaw) — the gap is only that the paint catalogue leaves weight NULL, so `requiredVehicle(0)`='bike' even for a 20L (~24 kg) pack.
- **Impact:** heavy paint may dispatch on a bike; logistically wrong (rider/ops can still deliver). Low correctness risk for light packs, grows with 10/20L.
- **Smallest fix:** `createOffer` set `products.weight` from `pack_size × density` (paint ≈ 1.25–1.4 kg/L) or store a per-pack weight in the paint attributes and have `enrichHandoff` read it.

### Minor notes (not blockers)
- **S10 cart-line seller label** uses the global visibility default (browse/detail exact) — cosmetic.
- **Hosting-only carry-overs** (unchanged): Cashfree real ₹1 + advance gateway round-trip, ledger-namespace bootstrap alias (finding #6), ~20 founder-free staging orders, MariaDB ≥10.5 (satisfied on 10.11).

## FINAL VERDICT
**Ready-paint end-to-end: NO (conditional) — one HIGH blocker for COD.**
- The money/catalogue/pricing/routing/accept-gates/delivery/settlement/returns/admin/KYC spine is **green (785/0)**, custom tint is correctly **dormant**, admin is scoped (no over-permission), and **PREPAID** ready paint is real-world ready.
- **Blocking for a clean COD go-live:** **PROBLEM #2** — the rider screen must show the cash-to-collect; without it a COD delivery is operationally unsafe.
- **Strongly recommended before launch:** **PROBLEM #1** (schedule `expireStaleOffers` so ignored orders auto-reroute instead of stalling).
- **Before heavy-paint scale:** **PROBLEM #3** (populate paint weight for the correct vehicle).
- None require engine surgery; all three are additive, read-only-surfacing or one-line scheduling/config fixes, to be done as a separate fix task.
