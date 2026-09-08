# Commerce 10 — Security Fixes (Fix Phase 2)

> **Purpose:** Close the security findings from `commerce-08-readiness-audit.md` §4 so commerce is safe for real money + real data. One commit per issue; all in permitted adapter/engine files — the never-touch order engine is untouched.
> **Status:** PHP structure verified (no runtime here). Committed on `v2`.
> **Date:** 2026-08-22. Builds on the settlement/lifecycle fixes (`…61450d6`).

| # | Issue (audit §4) | Fix | Commit |
|---|---|---|---|
| **H1** | Delivery-track IDOR — any customer could enumerate any order's rider name/phone/live location | `GET /api/delivery/track/{orderId}` now authorises the caller against the order: admin, the order's own customer (`orders.user_id`), or the assigned rider (`deliveries.driver_id`). Anyone else → **404** (doesn't leak existence). | `05dcf2c` |
| **M1** | Delivery-fee tamper — client `drop_lat/lng` shaved the fee | New `DeliveryEngine::quoteServerSide` prices **store → the city's server-known point** (`cities.center_lat/lng`; documented seam for a future address geocoder). `createForOrder` + `/api/shop/delivery-quote` use it, so estimate == billed fee and both are server-authoritative. Client coords survive only as a rider **navigation hint**, never pricing. | `2b8473e` |
| **M2** | City guard — crafted request could cart/checkout from a store outside the customer's city | Server-side `$shopAssertCity` on **cart-add** (rejects cross-city, 422) and **checkout** (re-checks every cart item). Allows a store with no city set; rejects only explicit mismatch. | `7c85ee7` |
| **M3** | Order-cancel orphaned the delivery (rider kept an active job + leaked capacity) | New `DeliveryEngine::cancelForOrder` cancels the order's active delivery + frees the rider. Hooked into the storefront cancel for instant effect, plus **step G in the lifecycle cron** as the safety net for admin/vendor cancel paths. | `d772301` |
| **M5** | No rate-limiting on shop/rider endpoints | Reuses the existing `RateLimiter` (DB-backed, per action+IP): shop cart-add 60/min, quote 30/min, checkout 10/min; rider ping 120/min, writes 90/min; public delivery-quote 60/min per IP. 429 on trip. | `46deac3` |
| **L4** | Rider location coords unvalidated | `/api/rider/location` validates `lat[-90,90]` / `lng[-180,180]` before storing. | `46deac3` |
| **L1** | Public delivery-quote probing | Per-IP throttle (60/min) on `/api/delivery/quote`; M1 also removes its per-coord pricing signal. | `46deac3` |

**Already fixed earlier (audit M4):** rider reject → *different* rider — closed in Fix Phase 1 (`61450d6`).

## NEVER-TOUCH FLAG
Nothing in `shopping-engine`/`video-engine`/`orders.js` was edited. Every fix lives in permitted adapters (`heart/api/{shop,rider,delivery}`), the delivery engine (`core/delivery`), or the cron. Cart/checkout guards wrap the never-touch `CartService`/`OrderService` from outside; the cancel hook runs after `OrderService::cancel` returns.

## HOW TO VERIFY EACH (staging)
- **H1:** as customer A, `GET /api/delivery/track/{B's order id}` → 404; your own order → 200; the assigned rider on their order → 200; admin → 200.
- **M1:** at checkout, send `drop_lat/lng` next to the store → the shown + billed fee is unchanged (city-based); `orders.delivery_fee`/`deliveries.price` match `quoteServerSide`, not the client coords.
- **M2:** craft `POST /api/shop/cart` with a product from another city's store → 422; a normal same-city add → 200; checkout with a cross-city item in cart → 422.
- **M3:** place a COD order (delivery assigned) → cancel it → the delivery row is `cancelled` and the rider's `active_count` drops. Admin-cancel an order → next cron run cancels its delivery (step G).
- **M5/L4/L1:** hammer cart-add / quote / rider-ping past the limits → 429; `POST /api/rider/location` with `lat=999` → 400.

## REMAINING (deferred LOW + staging)
- **L2** (multi-store cart quote uses the first store) — a correctness nit, lessened by M1's city-based pricing; defer.
- **L3** (`worktogo-company` vendor slug race) — low-volume 23000 edge; defer.
- **Staging:** run the migrations, exercise each check with the PHP runtime, and load-test the rate limits. Online payment (Cashfree) remains a separate staging gate.
