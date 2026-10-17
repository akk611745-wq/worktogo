# PAINT UNIT — FINAL BUILD PLAN (PLAN ONLY — not built yet)

**Date:** 2026-10-17 · **By:** Claude Code (CTO partner) · **For:** CEO (Aakash)
**Base:** backend proven 785/0 (MariaDB 10.11, commit `b6976a0`). Finishes the Paint Unit **completely — READY + CUSTOM-TINT** — so nothing is left after. **This doc is the plan; no code written. Build happens one approved step at a time.**
**Reads with:** final-readiness-walkthrough.md · final-product-readiness-and-missing-note.md · ready-paint-golive-verification.md · paint-unit-plan.md · commerce-build-handoff.md.

**Guardrails (every step):** read before edit · one step = one commit · never touch shopping-engine / video-engine / orders.js / service engine / OrderService / DeliveryEngine / LedgerEngine (order-branch read-only) / PaymentService booking branch · the live Haldwani **service pilot keeps working** (paint shop turns on via an **admin module/city switch**, never by flipping `SERVICE_ONLY_MODE` globally) · admin controls everything from a screen · every number is admin config, not hardcoded · no unrecorded money path.

---
## PART A — Current state (exists / HALF / missing, with evidence)
| Item | State | Evidence |
|---|---|---|
| Catalogue data columns (images, attributes, weight, meta JSON) | EXISTS | products: `images`/`attributes`/`weight`/`meta` ([database-structure.sql:14,16,18,25]); `product_variations` table ([:663]); `inventory` per product |
| Seller add-product screen | HALF | seller.js:171-199 — name/base/stock only; no brand/category/variant/pack/photo |
| Product photo upload path | EXISTS (reusable) | `/api/media/upload` with `purpose` + vendor owner ([heart/api/media/index.php:43-52]) |
| Seller catalogue backend | EXISTS | `SellerCatalogue::createOffer` accepts category_id/attributes ([SellerCatalogue.php:46]); `setStock` ([:100]) |
| Product verification (draft→approve) | EXISTS (backend) | createOffer→draft; approve flips active (p3); no admin screen |
| Seller/rider KYC backend | EXISTS | `KycService` (s18 22/0); no admin approve screen |
| Admin shell + 28 legacy screens | EXISTS | admin/*.html; `admin/shared/shell.js`; Commerce group = 2 screens hidden ([shell.js:19-21]) |
| Admin commerce APIs | EXISTS (API-only) | `/api/commerce-admin` (s11 35/0); no screens call them (grep zero) |
| Customer commerce storefront (#shop) | HALF (OFF) | shop.js views ([shop.js:66-71]); hidden by `SERVICE_ONLY_MODE` ([config.js:78,81], [router.js:40]) |
| One server-side price (margin+absorb) | EXISTS | `DeliveryPricing::composeFinalPrice` ([DeliveryPricing.php:92]); s13/p3 |
| Payment: prepaid redirect + COD + webhook authority | EXISTS | shop.js:363; webhook exact-amount ([PaymentService.php:260]) |
| Advance / partial payment seam | EXISTS | `CommercePayment` + webhook branch (p4 19/0) |
| Rider cash-to-collect display | MISSING | payload ([heart/api/rider/index.php:77]) + rider.js have none |
| Vehicle weight→rule | EXISTS; weight not set at offer | `requiredVehicle` ([CommerceDelivery.php:31]); `enrichHandoff` Σweight ([:53]); createOffer sets no weight |
| Seller-accept / quote timeout reroute | EXISTS (not scheduled) | `RoutingEngine::expireStaleOffers` ([RoutingEngine.php:255]); no cron |
| Custom-tint spine (per-brand, guard, repeat-seller, consent, confirm-call) | EXISTS (dormant) | `CustomTint` ([CustomTint.php:18-152]); per-brand `commerce_custom_tint_brands`; p7 20/0 |
| Custom-tint PRICING (quote/estimate/ceiling/band/lock) | MISSING | no quote endpoint, no ceiling, no price-band table, no lock-per-litre |
| MarginEngine (slab, loss-proof) | EXISTS | `priceFromBase` ([MarginEngine.php:171]); `slabMargin` ([:144]); `lossProofOk` ([:97]) |
| Ledger order-branch revenue capture | EXISTS | `processCommerceOrderSettlement` margin+delivery ([LedgerEngine.php:613,639,656]) |
| Ops console (queue/claim/escalate/resolve/holds/comms) | EXISTS (API) | `OpsConsole` ([OpsConsole.php:34-99]); no commerce ops screen |
| Event bus flags + audiences | EXISTS (in-app only) | `audienceMap` ([CommerceEventBus.php:20-28]); `flagRules` ([:42]) |
| Order-lifecycle SMS/WhatsApp/push | MISSING (infra exists) | commerce events write in-app rows only; MSG91/WhatsApp/FCM exist for AUTH only ([lib/auth/*Channel.php], app/js/push.js) |
| Distinct alert sound/vibration per event | MISSING | no `vibrate`/`Audio` in client |
| Seller bill/report + customer invoice/GST | MISSING | earnings shows totals (seller.js:203); no export/invoice |
| Rate limiter / upload validation | EXISTS | `HeartRateLimiter` ([heart/router.php:117]); media ≤5MB+mime ([media/index.php:23-29]) |
| COD-abuse controls / abandoned-payment cleanup | MISSING | none found |
| Pre-delivery cancellation + cost rule | MISSING | returns exist post-delivery (s9); no pre-delivery cancel |

---
## PART B/C — The full ordered step list (each independently testable)
Format per step: **goal · screens · files · reuses · tests · acceptance (what you'll see) · money · severity · size.**

### PB1 — Catalogue model: Product → Variant → Pack SKU (+ weight + photo) [data layer]
- **Goal:** one brand+line **Product** (one photo) → **Variants** (base / ready shade / finish) → **Pack SKUs** (1L/4L/10L/20L: seller price + stock + **weight**). Decision #1.
- **Screens:** none (data+API). **Files:** new `lib/commerce/PaintCatalogue.php` (or extend `SellerCatalogue`) `createProduct/addVariant/addPackSku`; migration (additive) mapping to `products` + `product_variations` ([:663]) + `inventory`; weight on the SKU row. **Reuses:** createOffer pricing ([SellerCatalogue.php:46]), products.images/attributes/weight.
- **Tests:** new `p8_catalogue_hierarchy_vectors.php` — create product→variant→3 pack SKUs, each priced by slab, weight stored, draft status.
- **Acceptance:** harness shows a product with 3 pack SKUs, distinct prices/stock/weight; weight flows to `enrichHandoff`.
- **Money:** none. **Severity:** BLOCKER. **Size:** M.

### PB2 — Seller ADD-PRODUCT screen (brand, category, variant, packs, price, stock, weight, PHOTO)
- **Goal:** Amazon-style add-product form; edit quantity anytime; submit for verification (new product always needs approval; qty edits don't). Decisions #1,#3,#4.
- **Screens:** rebuild seller.js catalogue tab (add-product form + offer list + restock). **Files:** seller/seller.js, seller/index.html; backend already PB1. **Reuses:** `/api/media/upload` `purpose=product` ([media/index.php:43]), PaintCatalogue (PB1), draft→verify.
- **Tests:** `kyc_ui_structure`-style structural check (form fields incl. file input + brand/category/variant/pack + wiring to PB1 routes).
- **Acceptance:** founder-as-seller adds "Berger Easy Clean" with a photo + 4L/10L/20L packs → appears as draft "pending verification".
- **Money:** none. **Severity:** BLOCKER. **Size:** L.

### PB3a — Admin: KYC approve + Product verification queue
- **Goal:** approve sellers/riders and new products from a screen (else nothing goes live). Decision #4.
- **Screens:** new `admin/commerce-kyc.html` + `admin/commerce-verify.html` on the admin shell. **Files:** admin pages + `admin/shared/*`; **Reuses:** `/api/commerce-admin/kyc/*` (s18), product approve API, `Permission` gate.
- **Tests:** structural + an API smoke (pending→approve round-trip against 10.11).
- **Acceptance:** you see a KYC queue → Approve → vendor active; a product queue → Approve → product live.
- **Includes PB17 (match_key dedup governance):** at verification, the reviewer attaches a new product to the correct canonical `match_key` (two sellers → ONE customer listing with multiple sources), not a duplicate listing — see PB17.
- **Money:** none. **Severity:** BLOCKER. **Size:** M.

### PB5 — Rider cash-to-collect + heavy-order vehicle flag  *(walkthrough #2)*
- **Goal:** rider sees exactly what to collect (prepaid ₹0 / COD full / advance balance) + a vehicle/weight flag.
- **Screens:** rider.js job card (one line). **Files:** heart/api/rider/index.php (payload add `cod_amount`,`is_prepaid`,`balanceDue`,`required_vehicle`), rider/rider.js (render). **Reuses:** `enrichHandoff` ([CommerceDelivery.php:71]), `CommercePayment::balanceDue`. **NOT** touching DeliveryEngine.
- **Tests:** extend a rider harness — payload includes the 4 fields; COD shows full, prepaid 0, advance = balance.
- **Acceptance:** on a COD job the rider card shows "Collect ₹X"; prepaid shows "Prepaid — collect ₹0".
- **Money:** display of existing figures (no new path). **Severity:** BLOCKER (COD). **Size:** S.

### PB6 — Cron: expireStaleOffers + quote timeout  *(walkthrough #1)*
- **Goal:** ignored orders auto-reroute; custom-tint quote timeout reroutes. Decisions #5c.
- **Screens:** none. **Files:** new `cron/commerce_lifecycle.php` calling `RoutingEngine::expireStaleOffers($db)` (+ later the quote-timeout sweep); NOT editing cron/delivery_lifecycle. **Reuses:** RoutingEngine ([:255]); `commerce_offer_timeout_sec` ([:29]).
- **Tests:** run the cron against seeded stale offers → rerouted (reuse s3 R8 logic).
- **Acceptance:** a seeded offer past timeout flips to the next seller when the cron runs.
- **Money:** none. **Severity:** BLOCKER. **Size:** S.

### PB4 — Customer storefront paint-first, behind an ADMIN MODULE/CITY SWITCH
- **Goal:** turn the paint shop on for a city WITHOUT touching the service pilot; paint-first landing; product page with **variant + pack selector + photo**; coverage-calculator link; cart; checkout (prepaid + COD; advance for tint); my-account; reorder. Decisions, guardrail.
- **Screens:** app/commerce/shop.js (exists), app/js/router.js + config gate. **Files:** add `commerce_shop_enabled` (+ per-city) admin config; client reads THAT flag for `#shop` instead of the global `SERVICE_ONLY_MODE` ([router.js:40]) — service app untouched; shop.js product page gains variant/pack selector (PB1 data). **Reuses:** StorefrontComposer, composeFinalPrice, payment chooser (shop.js:294).
- **Tests:** structural + meta/compose API; variant/pack selection picks the right SKU price.
- **Acceptance:** with the admin switch ON you reach a paint storefront, pick 4L vs 10L, see one price + photo, checkout COD/prepaid; with it OFF the service app is unchanged.
- **Money:** order placement → existing settle. **Severity:** BLOCKER. **Size:** L.

### PB7 — Alerts "nervous system" (SMS/WhatsApp/push + sound/vibration)
- **Goal:** every event reaches the right person on the right channel; **seller new-order alert is loud, own sound + vibration, repeats until accepted**; each event its own tone. Decision #8.
- **Screens:** seller/rider PWAs (audio+vibration); **Files:** new `lib/commerce/CommerceNotifier.php` mapping `CommerceEventBus` events → MSG91/WhatsApp/FCM (reuse `lib/auth/*Channel.php`, app/js/push.js); seller.js/rider.js audio+`navigator.vibrate`. **Reuses:** audienceMap ([CommerceEventBus.php:20-28]).
- **Tests:** harness asserts each event resolves to channel+audience; (actual SMS/push delivery is a hosting check).
- **Acceptance:** placing an order → seller phone gets a push/SMS + the seller PWA rings & vibrates until Accept; rider gets a job alert; customer gets order updates.
- **Money:** none. **Severity:** BLOCKER (seller new-order + rider job min) / PRE-SCALE (full tone set + all channels). **Size:** M.

### PB8 — Vehicle doubt → seller suggestion → admin override
- **Goal:** unclear/big/multi-item order → mark "unsure" → ask seller to suggest a vehicle from options → admin can override. Decision #6.
- **Screens:** seller job (suggest), admin ops (override). **Files:** `CommerceDelivery`/`UnitConfig` "unsure" rule (multi-item/high-value/near-threshold, admin config), handoff suggestion field, new `vehicle_doubt`→needs_ops event; admin override action. **Reuses:** requiredVehicle, OpsConsole, EventBus.
- **Tests:** rule marks unsure on a mixed big order; override persists.
- **Acceptance:** a ₹15k multi-item order shows "vehicle?" to the seller + an override control to admin.
- **Money:** none. **Severity:** PRE-SCALE. **Size:** M.

### PB9 — Human-support escalation queue (ops console)
- **Goal:** ONE queue; each item tells the human exactly what to decide (vehicle doubt, high-value, colour-price outlier, no-seller-accepted, payment stuck, complaint). Decision #7.
- **Screens:** new `admin/commerce-ops.html`. **Files:** admin page over `OpsConsole` ([OpsConsole.php:34]); add `price_outlier`/`confirm_call`/`vehicle_doubt` to `flagRules` ([CommerceEventBus.php:42]). **Reuses:** OpsConsole queue/claim/escalate/resolve.
- **Tests:** each flag type surfaces with its decision text; claim/resolve works.
- **Acceptance:** the ops queue lists live exceptions with a clear "decide: X" and action buttons.
- **Money:** none. **Severity:** PRE-SCALE. **Size:** M.

### PB3b — Admin config screens (margin/slab, routing pref, brand enable, payouts/settlement, money dashboard)
- **Goal:** every number tunable from a screen; brand enable (ready + tint); payouts/settlement + money dashboard view. Guardrail.
- **Screens:** `admin/commerce-settings.html`, `admin/commerce-brands.html`, `admin/commerce-money.html`. **Files:** admin pages over `AdminControlRoom::setConfig` allowlist ([AdminControlRoom.php:19-70]), `CustomTint::enableBrand`, `CommerceSettlement`. **Reuses:** all existing config keys.
- **Tests:** structural + one setConfig round-trip (rejects non-allowlisted key).
- **Acceptance:** you flip a margin slab / enable "Berger" / see who-we-owe — all from screens.
- **Money:** read-only views; config affects pricing. **Severity:** PRE-SCALE. **Size:** L.

### PB10 — CUSTOM-TINT full flow (5a–5g)  [build last; dormant until a brand is enabled]
- **PB10a — estimate + quote box + accept-timeout:** customer picks brand→product→shade code (guard exists); system shows instant **estimate**; order lands on seller with a **quote box** (accept + enter colour price within N min, admin `commerce_quote_timeout_sec` default 3m); no answer → reroute (PB6 cron). **Files:** new quote endpoints + `CustomTint` estimate; **reuses** RoutingEngine offer/timeout, CustomTint guard/modeRule.
- **PB10b — final price + ceiling + price-band + outlier:** seller quote → `MarginEngine` on the total → **ceiling** (`commerce_tint_margin_ceiling`) → ONE final-price popup → accept → **advance 50%** (CommercePayment p4) + non-returnable consent. **Price band** per (brand,shade_code) records every quote; above-band quotes hidden from customer → next seller; band only rejects after `commerce_band_min_samples`; until then admin sees outliers (PB9). **Files:** new `commerce_shade_price_band` table + `CustomTint` band logic; **reuses** MarginEngine, advance seam, consent.
- **PB10c — lock-per-litre:** once a customer's shade is priced, repeat (any pack size) priced instantly from the locked per-litre, same seller, no re-ask. **Files:** `commerce_shade_price_lock` (or reuse tint log) + `CustomTint` price-from-lock. Base chosen by seller from his shade book (base_type stays blank — don't fake, decision #5g).
- **Screens:** customer tint flow (paint-tint/ exists), seller quote box, admin outlier review. **Tests:** `p9_custom_tint_pricing_vectors.php` — estimate, quote→margin→ceiling, band record+outlier hide, lock-per-litre repeat, advance capture, timeout reroute.
- **Acceptance:** a tint order gets an estimate instantly, seller quotes, you see one final price, pay 50% advance; a repeat of the same shade is priced instantly.
- **Money:** advance → `partially_paid` + balance COD (p4); margin on the quoted total (recorded on order branch). **Severity:** **BLOCKER** — the paint unit is NOT complete without custom tint (built after PB12, before PB13; goes live per brand via the enable list). **Size:** L.

### PB11 — Seller bill/report download + customer invoice/GST view
- **Goal:** seller earnings/orders export; customer order invoice with GST + the two-entity (own vs facilitated) split. Walkthrough gap.
- **Screens:** seller earnings (export), customer order page (invoice). **Files:** new export/invoice endpoints; **reuses** `CommerceSettlement::accountingSplit` (s9 own-vs-facilitated), earnings.
- **Tests:** invoice totals reconcile to the ledger; GST line present.
- **Acceptance:** seller downloads a report; customer views/downloads a GST invoice.
- **Money:** reflects captured ledger (no new path). **Severity:** PRE-SCALE. **Size:** M.

### PB12 — Security hardening (from walkthrough)
- **Goal:** confirm upload validation + no-exec uploads dir; rate-limit on commerce routes; price tampering (server-price, exists); **COD-abuse controls** (cap/verified-phone/blocklist, admin config); **abandoned online-payment cleanup** (expire unpaid online orders). Walkthrough Part 9.
- **Files:** small guards + a cleanup cron entry (PB6 host); config keys. **Reuses:** HeartRateLimiter, media validation, webhook.
- **Tests:** COD cap blocks over-limit; stale unpaid online order expires.
- **Acceptance:** an abusive COD pattern is capped; an abandoned payment doesn't leave a stuck order.
- **Money:** protects revenue. **Severity:** PRE-SCALE. **Size:** M.

### PB13 — Hosting steps (LAST)
- **Goal:** Cashfree live creds + a **₹1 online round-trip**; **ledger namespace alias** in prod bootstrap (finding #6); **MariaDB ≥10.5** on host; **cron on host** for PB6/PB12; **media → object storage/CDN + DB & media backup/restore runbook (PB18, folded in here).**
- **Prereq:** **PB3b must be done before PB13** (admin margin/slab, routing, brand-enable, payouts/money screens live before go-live).
- **Files:** prod `.env`/bootstrap + ops; no engine edit. **Tests:** ₹1 round-trip succeeds; cron fires.
- **Acceptance:** a real ₹1 payment settles; crons run on schedule.
- **Money:** enables real online capture. **Severity:** BLOCKER (real money, hosting). **Size:** S-M.

---
## PART E — Additional NUMBERED steps (PB14–PB18)
Each is a real step with the same fields; positions given per decision.

### PB14 — Pincode / area serviceability (what a customer sees by address)   [position: BEFORE PB4]
- **Goal:** a customer sees/orders only products deliverable to THEIR address; out-of-area caught at browse/checkout, not at dispatch. Today only a radius gate exists ([OrderabilityResolver.php] `out_of_area`).
- **Screens:** admin serviceability (per-city/pincode); customer browse/checkout honor it. **Files:** new `commerce_serviceability` (city/pincode/radius) + `OrderabilityResolver` consult (our lib) + admin screen. **Reuses:** orderability layer-2, stores.city_id/service_radius_km.
- **Tests:** in-area pincode → orderable; out-of-area → blocked at browse+checkout.
- **Acceptance:** a Haldwani pincode sees paint; a non-served pincode sees "not available in your area".
- **Money:** none. **Severity:** PRE-SCALE (radius covers a single-city launch; precision before multi-area). **Size:** M.

### PB15 — Customer saved-address book   [position: WITH / right after PB4]
- **Goal:** customer saves multiple addresses and picks one at checkout (today checkout takes a single typed address, shop.js:353).
- **Screens:** my-account addresses + checkout picker. **Files:** `user_addresses` table (or reuse an existing one if present) + shop.js checkout; **Reuses:** checkout address form, account page.
- **Tests:** add/select/default address; checkout uses the selected one.
- **Acceptance:** customer saves Home/Work and chooses at checkout.
- **Money:** none. **Severity:** PRE-SCALE. **Size:** M.

### PB16 — Pre-delivery cancellation + cost-bearer rule   [position: BEFORE PB7]
- **Goal:** customer/seller can cancel BEFORE dispatch with a clear cost-bearer rule per stage (placed / accepted / picked); today only post-delivery returns exist (s9). Decision #9 (no unrecorded money).
- **Screens:** customer order (cancel), seller order (cancel), admin override. **Files:** new `CommerceCancellation` lib (stage→who-bears-cost, admin config) + events/flags; **Reuses:** CommerceEventBus, LedgerEngine refund (order-branch read/reverse only), CommerceReturns patterns.
- **Tests:** cancel at each stage → correct status + correct refund/charge; advance orders handled.
- **Acceptance:** customer cancels a not-yet-accepted order → auto-refund; a picked-up order → rule-based.
- **Money:** refund/again recorded on the order branch (no new unrecorded path). **Severity:** PRE-SCALE. **Size:** M.

### PB17 — match_key dedup governance   [position: WITH PB3a]
- **Goal:** two sellers listing the same product attach to ONE canonical `match_key` → customer sees one listing with multiple sources; prevents duplicate listings. Built into the verification queue (PB3a).
- **Screens:** product-verification queue (match / create-canonical). **Files:** `SellerCatalogue`/PaintCatalogue match_key resolve + admin merge control; **Reuses:** `products.match_key` ([SellerCatalogue.php:63]), RoutingEngine multi-source.
- **Tests:** two sellers, same match_key → one listing, two sources (reuse scale multi-seller).
- **Acceptance:** reviewer merges a new product into the existing listing; customer sees one card.
- **Money:** none. **Severity:** PRE-SCALE. **Size:** M.

### PB18 — Media → object storage/CDN + DB & media backup/restore runbook   [position: INSIDE PB13]
- **Goal:** move product/KYC media off local disk to object storage/CDN; a documented DB + media backup/restore runbook before scale. Today media is local disk ([heart/api/media/index.php:35]).
- **Screens:** none (ops). **Files:** media `storage_provider` already a column — add an S3/CDN provider + config; backup scripts/runbook doc. **Reuses:** media table `storage_provider`/`public_url`.
- **Tests:** upload lands in object storage, URL serves; restore drill documented.
- **Acceptance:** a product photo serves from CDN; a restore runbook exists.
- **Money:** none. **Severity:** PRE-SCALE (hosting; folded into PB13). **Size:** M.

---
## PART D — Build order (each testable alone; nothing waits on a later step)
**READY paint fully live first, then alerts, then custom-tint, then hosting (NEW steps woven in per decision):**
PB1 → PB2 → **PB3a (incl PB17)** → PB5 → PB6 → **PB14** → PB4 → **PB15** → **PB16** → PB7 → PB8 → PB9 → **PB3b** → PB11 → PB12 → **PB10(a→b→c)** → **PB13 (incl PB18)**.
Rationale: PB1 is the data spine; PB2/PB3a(+PB17 dedup)/PB5/PB6 make a READY order operable + approvable + rideable; **PB14 serviceability before PB4** so the storefront shows address-correct products; **PB15 saved-address with PB4**; **PB16 pre-delivery cancellation before PB7**; PB7 alerts; PB8/PB9 ops; **PB3b config screens (required before PB13)**; PB11/PB12 hardening; **PB10 custom-tint is a BLOCKER** (paint unit incomplete without it) built after PB12; **PB13 hosting last, with PB18 media/CDN+backup folded in**. PB5/PB6 are small independent blockers and can be slotted earliest for quick wins.

## PART F — End-to-end test plan (after the last step) + separate live phase
1. **One full READY order, all roles, all alerts:** founder-seller adds a paint product+photo+packs → admin approves → admin switches the city shop ON → customer browses paint, picks 10L, checks out (run once COD, once prepaid) → seller gets loud alert, accepts within timeout → rider gets job, sees Collect ₹X, OTP-delivers → settle founder-free (margin+delivery recorded) → customer invoice; verify every alert fired on the right channel.
2. **One full CUSTOM-TINT order (a brand enabled):** customer picks brand+shade code → estimate → seller quote within timeout (and once let it timeout → reroute) → margin+ceiling → final price → 50% advance + consent → make → deliver, rider collects balance → settle; repeat the same shade → instant lock-per-litre price; confirm a far-above-band quote is hidden + shown to admin.
3. **Full suite green on 10.11** after every step (regression gate).
4. **THEN the separate live phase (not this plan):** multi-device testing + a **500–1,000-order local run** — that run produces the real capacity numbers and surfaces field issues.

---
## Severity roll-up
- **BLOCKER (paint unit not complete without these):** PB1, PB2, PB3a (incl PB17), PB4, PB5, PB6, PB7(min), **PB10 (custom-tint, per enabled brand)**, PB13 (hosting).
- **PRE-SCALE:** **PB3b (must precede PB13)**, PB8, PB9, PB11, PB12, PB14, PB15, PB16, PB18.
- **LATER:** none standalone — residual nice-to-haves (full per-event tone set, multi-address polish) live inside their parent steps.

**Protocol for each build step (from now):** read first → build only that step → add its tests → run FULL suite on MariaDB 10.11 → report STATUS / CHANGED(file+lines) / COMMIT / BLOCKED / PASS-FAIL + what you'll see → push → STOP for approval.
