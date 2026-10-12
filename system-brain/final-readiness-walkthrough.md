# READY-paint — FINAL readiness walkthrough + full gap document (PLAN/VERIFY ONLY)

**Date:** 2026-10-12 · **By:** Claude Code (architect + each role) · **For:** CEO (Aakash)
**Base:** backend 785/0 on MariaDB 10.11.19 (commit `07e516c`). This closes the **front-end / screen blind spot** the prior audits never covered. **No code changed, nothing fixed, no UI built.** Each finding is **VERIFIED** (ran/read it) or **PREDICTED** (reasoned risk). Citations: `file:line` / route.

> **HEADLINE (VERIFIED):** The whole customer **commerce storefront is turned OFF** in the live app — `CONFIG.FEATURES.SERVICE_ONLY_MODE=true` + `SHOPPING_UI=false` ([app/js/config.js:78,81]) and `shop` is in `SERVICE_ONLY_HIDDEN_PAGES` ([app/js/router.js:40]); visiting `#shop` shows the **service** home ("hidden during the Haldwani service pilot", [config.js:103]). The admin **commerce** menu is 2 screens, both `hiddenInServiceOnly` ([admin/shared/shell.js:19-21]). So the paint product system is built at the backend but **not reachable from the running front-end**. This is the central launch gap.

---
## PART 0 — SCREEN INVENTORY (exists / HALF / missing)
**CUSTOMER** — `app/` SPA (hash router [app/js/router.js:30]), pages in `app/pages/`:
| Screen/route | Exists | Evidence |
|---|---|---|
| Home (`#home`) — SERVICE categories | YES (service) | app/pages/home.js; browser: Haldwani + Painting/Plumber… |
| Login/OTP (`#login`) | YES | app/pages/login.js |
| Account (`#account`) | YES | app/pages/account.js |
| Orders/Bookings (`#orders`,`#bookings`) | YES (service) | app/pages/orders.js, bookings.js |
| Commerce storefront (`#shop`: browse/product/cart/checkout/orders/order) | **HALF (built, HIDDEN)** | app/commerce/shop.js:66-71 views; hidden by service-only ([router.js:40]) |
| Paint coverage calculator | YES (standalone, not linked) | paint-calculator/index.html (p2) |
| Paint custom-tint flow | YES (standalone, dormant) | paint-tint/index.html (p7) |

**SELLER** — `seller/` PWA (tabs in seller.js:go()):
| Screen | Exists | Evidence |
|---|---|---|
| Login gate | YES | seller/index.html; browser "Please log in" |
| Orders inbox (accept/reject/stock-out, opaque customer) | YES | seller.js:157-171 |
| Catalogue / **Add product** | **HALF** | seller.js:171-199 — only name/base/stock; **no brand/category/pack/photo** |
| Earnings | YES | seller.js:203-215 |
| KYC (form + doc upload) | YES | seller.js:95-140 (media upload) |
| Rider-onboard (separate) | — | rider-onboard/ (rider, not seller) |

**RIDER** — `rider/` PWA:
| Screen | Exists | Evidence |
|---|---|---|
| Login | YES | rider.js:29 |
| Dashboard + online toggle + location ping | YES | rider.js:72-93 |
| Deliveries queue + accept/pickup/deliver + nav | YES | rider.js:104-134 |
| **Cash-to-collect display** | **MISSING** | /api/rider/deliveries ([heart/api/rider/index.php:77]) omits cod/balance; rider.js has no collect line |
| Rider onboarding (register→OTP→KYC) | YES (standalone) | rider-onboard/index.html |

**ADMIN** — `admin/` (~30 HTML screens, legacy service/shopping shell):
| Screen | Exists | Evidence |
|---|---|---|
| Login + dashboard + ~28 screens (orders, pricing, payments, refunds, vendors, roles, users, delivery-control, services/ops board, cities, banners, analytics, settings, logs…) | YES (legacy) | admin/*.html; orders.html uses `CONFIG.ENDPOINTS.ORDERS` (legacy /api/admin) |
| Commerce: Stores & Shop Types, Catalog & Stock | **HALF + hidden** | admin/shared/shell.js:19-21 (`hiddenInServiceOnly:true`) |
| Commerce KYC review / brand enable / margin slab / routing source-pref / ops exception queue / payouts / loss-proof | **MISSING** | grep `/api/commerce-admin\|kyc\|custom_tint_brands\|margin_slab\|enableBrand` in `admin/` = **zero**; these are API-only (S11) |

---
## PART 1 — CUSTOMER journey (VERIFIED by code + browser)
| area | screen/route | exists | evidence | predicted problems | smallest fix | severity | human? |
|---|---|---|---|---|---|---|---|
| Entry to shop | `#shop` | HALF (off) | config.js:78,81; router.js:40 | customer cannot reach paint products at all | flip SERVICE_ONLY for shop + add a paint entry in nav | **LAUNCH-BLOCKER** | N |
| Account/OTP login | `#login` | YES | login.js; OTP via MSG91/WhatsApp (lib/auth/*Channel.php) | — | — | — | N |
| Location/address | checkout | YES | shop.js:353 (addr form); geolocation `#drop` | geo denial → manual pincode only; no saved-address book in shop | add address book later | PRE-SCALE | N |
| Browse (seller-hidden) | `#shop` browse | YES (built) | shop.js:94-125; StorefrontComposer | top chips are grocery/veg (meta store_types), **not paint-first** | a paint landing / category filter | PRE-SCALE | N |
| Product detail + pack variation | renderProduct | **HALF** | shop.js:192 — shows img/price/unit/desc/related; **no pack-size variant selector** | 4L/10L/20L are separate products, not variants → confusing | variation UI (LATER) or convention "one pack = one product" | PRE-SCALE | N |
| Product image | renderProduct | HALF | shop.js uses `p.images[0]` but seller has no photo-upload → no image | blank product cards | add seller product-photo upload (see Part 2) | PRE-SCALE | N |
| One server-side price (no flicker) | detail/cart | YES | DeliveryPricing::composeFinalPrice ([DeliveryPricing.php:92]); s13/p3 | — | — | — | N |
| Payment chooser (prepaid + COD) | checkout | YES | shop.js:294-366 (online→gateway redirect; COD) | online needs live Cashfree creds (B2) | hosting creds | **LAUNCH-BLOCKER** (prepaid) | N |
| Coverage calculator in buy flow | — | HALF | standalone paint-calculator/ not linked from shop | customer won't find it | link from product/browse | PRE-SCALE | N |
| Repeat order / reorder | orders view | YES | shop.js reorder (S10) | — | — | LATER | N |
| Empty states | shop.js | YES | friendly empty/error states (shop.js:7) | — | — | — | N |

## PART 2 — SELLER journey
| area | screen | exists | evidence | predicted problems | smallest fix | severity | human? |
|---|---|---|---|---|---|---|---|
| Register / KYC + doc upload | KYC tab | YES | seller.js:95-140 (/api/media/upload) | — | — | — | Y (admin KYC approve) |
| **Add product** | catalogue | **HALF** | seller.js:171-199 — name/base/stock only | **no product PHOTO, no brand/category/pack-size, no paint attributes** → catalogue is bare, no image for customers, attributes (coverage/finish/warranty) never captured from UI | add photo-upload (reuse /api/media) + brand/category/pack fields → `createOffer` already accepts category_id/attributes | **LAUNCH-BLOCKER** (photo) / PRE-SCALE (attrs) | N |
| Receive order → accept | orders tab | YES | seller.js:157-168; accept-gates proven s15/p3 | — | — | — | N |
| Enter/confirm price | — | N/A ready | ready paint price = base+slab (no per-order quote); quote flow is custom-tint only (dormant) | — | — | LATER | N |
| Change quantity anytime | restock | YES | seller.js:201 `restock`→setStock ([SellerCatalogue.php:100]) | field label "New stock" only; no +/- or per-order qty edit | cosmetic | LATER | N |
| Bill/report download | — | **MISSING** | earnings tab shows totals (seller.js:203-215) but **no invoice/report download** | seller can't export earnings/GST | add CSV/PDF export endpoint | PRE-SCALE | N |
| New product not live until verified | — | YES | offer lands draft → admin approve (p3) | admin has **no commerce approve screen** (Part 4) | — | **LAUNCH-BLOCKER** (admin approve UI) | Y |

## PART 3 — RIDER journey
| area | screen | exists | evidence | predicted problems | smallest fix | severity | human? |
|---|---|---|---|---|---|---|---|
| Receive job only after seller accept | deliveries | YES | accept-gates (s15/p3); rider.js:104 | — | — | — | N |
| Address + navigation | job card | YES | rider.js:122-126 (Pickup/Drop + nav links) | drop_address parsed from shipping_address JSON ([rider/index.php:90]) — malformed JSON → blank | validate address at checkout | PRE-SCALE | N |
| Full trip to doorstep (pickup proof → OTP deliver) | act() | YES | rider.js:118,134; CommerceDelivery pickup/deliver (s6) | — | — | — | N |
| **Cash-to-collect (prepaid 0 / COD full / advance balance)** | job card | **MISSING** | payload ([rider/index.php:77]) + rider.js have no cod/balance; data exists (enrichHandoff [CommerceDelivery.php:71], balanceDue) | **COD rider doesn't know how much to collect → cash errors/disputes** | add cod_amount/is_prepaid/balanceDue to payload + a "Collect ₹X" line | **LAUNCH-BLOCKER** (COD) | N |
| Heavy paint → vehicle flag | — | HALF | requiredVehicle correct (s6) but paint weight not set at offer → always bike ([SellerCatalogue.php:46]) | 20L on a bike | set weight at offer | PRE-SCALE | Y (vehicle doubt) |

## PART 4 — ADMIN journey
| area | screen | exists | evidence | predicted problems | smallest fix | severity | human? |
|---|---|---|---|---|---|---|---|
| Manage orders (live pipeline) | orders.html | YES (legacy) | uses CONFIG.ENDPOINTS.ORDERS | may not show commerce sub-orders/flags | verify/point at commerce order view | PRE-SCALE | N |
| **KYC approve (commerce seller/rider)** | — | **MISSING** | no admin screen calls /api/commerce-admin/kyc | can't approve sellers/riders from UI → onboarding blocked | small admin KYC-queue screen on the S18 APIs | **LAUNCH-BLOCKER** | Y |
| **Brand enable/disable (custom tint)** | — | MISSING | no UI for commerce_custom_tint_brands | only needed when a custom brand goes live | small toggle screen | LATER | Y |
| Price/margin control (slab, multiplier, loss-proof) | — | MISSING (UI) | editableKeys exist ([AdminControlRoom.php:19-35]) but no screen | admin edits config blind (raw API/DB) | a settings screen over the allowlist | PRE-SCALE | Y |
| Routing source-preference | — | MISSING (UI) | config key only | — | same settings screen | PRE-SCALE | N |
| **Ops exception queue / tickets** | services.html ops board (legacy) | HALF | admin/services.html ops board is for SERVICE bookings, not commerce flags | commerce needs_ops/high_value flags have no console | screen over OpsConsole (s7) | PRE-SCALE | Y |
| Reports/downloads | analytics/payments/refunds.html | YES (legacy) | legacy endpoints | commerce money dashboard (AdminControlRoom) not surfaced | screen over the dashboard API | PRE-SCALE | N |
| What admin CANNOT do / over-permission | — | OK | `setConfig` refuses non-allowlisted keys ([AdminControlRoom.php:70]); /api/commerce-admin gated by Permission (401 live); allowlist = commerce_* only | none found — admin is config-only, scoped | — | — | — |

## PART 5 — PAYMENT & MONEY end-to-end
- **Margin + delivery captured** on the order branch → one platform credit ([LedgerEngine.php:613,639,656]); seller base in full ([:612,627]); rider payout + COD cash owed ([:646-651]). VERIFIED (e2e/p3).
- **Prepaid online:** checkout → gateway redirect ([shop.js:363]); **webhook is sole paid authority** with exact-amount verify ([PaymentService.php:260]). Needs live Cashfree creds (B2). PREDICTED: **abandoned/failed payment** → order stays unpaid (webhook never fires) — is there a cleanup/expiry of unpaid online orders? Not found → stale-unpaid orders accumulate (PRE-SCALE).
- **Double-charge:** webhook idempotent (skip if already paid, [PaymentService.php:251]) → low risk. VERIFIED.
- **COD:** settles, rider cash_in_hand owed ([:649]). Rider can't see amount (Part 3) → operational risk.
- **Advance/partial:** p4 seam proven; **not wired into the customer checkout UI** (no advance option on screen) — custom-tint only, dormant. LATER.
- **Refund/return:** source-aware, risk-class, `LedgerEngine::processRefund` reversal (s9/p5). Refund **timing** depends on gateway (online) / store-credit (COD) — PREDICTED: no customer-facing refund status screen (PRE-SCALE).

## PART 6 — NOTIFICATION / ALERT "nervous system"
- **Infra EXISTS but only for AUTH OTP:** MSG91 SMS ([lib/auth/Msg91SmsChannel.php]), WhatsApp ([WhatsAppOtpChannel.php]), FCM push (app/js/push.js, firebase-sw.js).
- **Commerce order events write IN-APP rows ONLY** — `CommerceEventBus::audienceMap` ([CommerceEventBus.php:20-28]): order_created→customer+seller, accepted→customer, out_for_delivery→customer, delivered→customer+seller, rejected→customer. **No SMS/WhatsApp/push is fired for order lifecycle.** PREDICTED **LAUNCH-BLOCKER**: a seller not sitting in the app will miss a new order (→ accept-timeout), a rider will miss a job, a customer gets no SMS. In-app-only is insufficient for a real marketplace.
- **No distinct sound/vibration per event** — grep `vibrate|Audio|ringtone` = none (the "tone" hits are CSS colors). PREDICTED: seller needs a loud repeating "new order" alert; rider a distinct "job" tone. MISSING. PRE-SCALE.
- **Smallest fix:** route the commerce events (esp. new-order→seller, job→rider) through the existing MSG91/WhatsApp/FCM channels + add a persistent audible alert in the seller/rider PWAs.

## PART 7 — DEVICE & DOWNLOADS
- **Responsive:** seller/rider/calculator/tint PWAs are mobile-first (`max-width:520px`, viewport metas) — phone-first, acceptable on tablet/desktop (centered column). Admin is a desktop sidebar shell (shell.js) — fine on desktop, cramped on phone. VERIFIED (CSS).
- **Downloads:** **no invoice/report download** for customer or seller anywhere (Parts 2/4). Customer gets no order invoice/GST PDF. MISSING. PRE-SCALE.
- **Slow network / low-end phone (Haldwani 3G):** PWAs are light; but **media served from local disk, no CDN**, product images could be heavy; FCM/service-worker caching partial. PREDICTED: image-heavy browse slow on 3G. PRE-SCALE.

## PART 8 — LOAD / SCALE + HOSTING (honest, no "unlimited")
- Stack: PHP + MariaDB 10.11, media on **local disk** (`uploads/`), no queue, no CDN. Dev used PHP built-in server (prod must be nginx/php-fpm).
- **Realistic one-city launch capacity (single VPS, 4 vCPU / 8 GB, php-fpm + MariaDB + nginx):** comfortably **low-hundreds concurrent users, ~20-40 concurrent checkouts, ~1,000-3,000 orders/day**. Stock oversell is safe (SKIP-LOCKED on 10.11, scale harness 10/0).
- **First choke points (PREDICTED):** (1) **media uploads on local disk** — storage + backup + no CDN → move to object storage/CDN before scale; (2) **notifications synchronous** (no queue) — blocking on SMS/push at volume; (3) single DB — read load on browse (add indexes/cache); (4) no autoscale.
- **Recommended launch tier:** one 4 vCPU/8 GB VPS (or managed app + managed MariaDB ≥10.5) for one city; object storage for media; a cron worker host. This is a code/architecture estimate — the real number comes from the **separate** 500-1,000-order live run.

## PART 9 — SECURITY / ABUSE
- **Auth:** JWT HS256 ([core/helpers/JWT.php]) + bcrypt + logout blacklist + RBAC (`Permission`); AuthMiddleware on protected routes. VERIFIED.
- **Price/payment tampering:** price computed server-side ([DeliveryPricing.php:92]); webhook sole authority + exact-amount verify ([PaymentService.php:260]) → customer can't set price or self-confirm payment. VERIFIED, strong.
- **PII:** seller sees only opaque Customer-ID, no PII (s14). VERIFIED.
- **SQL injection:** prepared statements throughout the commerce libs (spot-checked). VERIFIED (sampled).
- **File-upload abuse:** media endpoint validates mime+≤5 MB + random name ([heart/api/media/index.php:23-41]). ⚠️ stored under web-served `uploads/` — ensure no script execution in that dir on host (PREDICTED hardening).
- **Rate-limit:** `HeartRateLimiter` IP+user ([heart/router.php:117-119]). VERIFIED.
- **COD non-payment / fake-order abuse:** **no COD deposit, no order-limit, no blocklist** → PREDICTED abuse vector (fake COD orders, refusal at door). MISSING. PRE-SCALE (add COD caps / verified-phone gate).

## PART 10 — LOCATION LOGIC
- Orderability gates on store city + service radius ([OrderabilityResolver.php] layer-2: out_of_area) and `sellerAvailableSql`; checkout captures drop lat/lng + pincode (shop.js:353). VERIFIED the gate exists.
- PREDICTED: **delivery-area precision** — radius-based, not polygon/pincode-serviceability; a drop just outside radius or a wrong pincode may still checkout then fail at dispatch. PRE-SCALE (pincode serviceability table).

## PART 11 — MASTER CATCH (every MISSING/HALF) + edges
**Consolidated MISSING / HALF (front-end + wiring):**
1. Customer commerce storefront **hidden** (service-only) — LAUNCH-BLOCKER.
2. Rider cash-to-collect not shown — LAUNCH-BLOCKER (COD).
3. Admin **commerce KYC-approve** screen missing — LAUNCH-BLOCKER (onboarding).
4. Order-lifecycle **SMS/WhatsApp/push** not fired (in-app only) — LAUNCH-BLOCKER (sellers/riders miss orders).
5. Prepaid online needs **Cashfree live creds** — LAUNCH-BLOCKER (hosting).
6. Seller **add-product: no photo / no brand-category-pack / no attributes** — LAUNCH-BLOCKER (photo) / PRE-SCALE (attrs).
7. `expireStaleOffers` **not scheduled** (prior #1) — LAUNCH-BLOCKER-light.
8. Admin UI for margin/routing/ops-queue/brand/loss-proof — PRE-SCALE (ops run blind otherwise).
9. Paint **weight at offer** → vehicle (prior #3) — PRE-SCALE.
10. Invoice/report **downloads** (customer + seller) — PRE-SCALE.
11. Distinct **alert sound/vibration** per event — PRE-SCALE.
12. Abandoned/failed online payment **cleanup** — PRE-SCALE.
13. COD-abuse controls (deposit/cap/blocklist) — PRE-SCALE.
14. Media → **object storage/CDN** + backup — PRE-SCALE.
15. Pincode serviceability precision — PRE-SCALE.
16. Pack-size variation UX + calculator link in shop — PRE-SCALE.
17. Custom-tint pricing (quote/ceiling/band/lock) + confirm-call/outlier escalations — LATER.

**Edge cases (checked / predicted):**
- **Order cancellation at each stage / who bears cost:** no customer- or seller-facing cancel flow found in the commerce UIs; returns exist post-delivery (s9) but pre-delivery cancel + cost-bearer rule is **MISSING** → PRE-SCALE (define + wire).
- **Stock-out / seller-offline mid-browse:** orderability gates at cart/checkout (s2) → clean block; but a product shown then going OOS before checkout relies on the checkout re-check (present). OK, VERIFIED gate; PREDICT a "just sold out" message polish later.
- **Data backup & recovery:** MariaDB dumps + media on local disk — **no documented backup/restore runbook** for prod → PRE-SCALE (define backups, esp. media).
- **GST/invoice + two-account legal structure:** VAT/GST % in config (`PrismaProCommon` Rs./VAT 18 is the paint-shop's; commerce has no invoice generator) — **no GST invoice generation** and the **two-legal-entity (own vs facilitated) split** is in settlement accounting (s9 own-vs-facilitated) but **not surfaced as compliant invoices** → PRE-SCALE (tax/invoice).
- **Duplicate account / multiple address:** phone-unique users; multiple saved addresses not in the shop checkout (single address entry) → LATER.

## PART 12 — FINAL VERDICT + BUILD LIST
**How far from a full final product:** the **backend is ~95% done and proven (785/0)**, but the **front-end/wiring layer for the paint product is materially incomplete** — the customer shop is switched off, the seller can't add a product photo, the rider can't see cash-to-collect, there's no admin screen to approve sellers, and order alerts don't leave the app. So as a *usable* end-to-end READY-paint product, we are **~70% — functional core, missing the last-mile surfaces that make it operable by real people.** Custom tint is further out (LATER).

**Prioritized build list:**
- **LAUNCH-BLOCKER (must, before any real order):**
  1. Un-hide + wire the customer paint shop (config + nav) · 2. Rider cash-to-collect (payload+line) · 3. Admin commerce KYC-approve screen · 4. Order-lifecycle SMS/WhatsApp/push (seller new-order + rider job at minimum) · 5. Seller add-product **photo** upload · 6. Schedule `expireStaleOffers` · 7. Cashfree live creds + ₹1 round-trip (hosting) · 8. Ledger namespace alias + MariaDB ≥10.5 (hosting).
- **PRE-SCALE (before the 500-1,000-order run):** admin screens for margin/routing/ops-queue/brand · paint weight→vehicle + vehicle-doubt escalation · seller add-product attributes · invoice/report downloads · abandoned-payment cleanup · COD-abuse controls · media→object storage/CDN + backup runbook · pincode serviceability · pre-delivery cancellation + cost rule · GST invoice + two-entity invoicing · distinct alert sounds · ~20 founder-free staging orders.
- **LATER:** custom-tint pricing + enable · pack-size variation UX · multiple addresses · reorder polish.

**Next phase (explicit, separate):** after the build list above is cleared, do **live multi-device testing + a 500-1,000-order live-ish run**. That run — not this document — produces the real capacity numbers and surfaces field issues. This task was code-level discovery + prediction only.
