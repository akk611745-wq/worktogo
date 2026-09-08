# Commerce 07 — Native Delivery Engine (Phase 5)

> **Purpose:** A clean, native delivery engine — order → priced-by-distance → nearest-rider assignment → rider updates → customer tracking — built by **composing the motherboard's own native pieces** and **discarding** the external SwiftDeliver dependency (the founder's unfinished early build). Delivery is a **separate engine with its own scope** (its own core, API, rider app, and control-room), connected to the motherboard but not mixed into commerce/service code.
> **Standard:** World-class, no jugaad. Reuse the native pieces; build clean; keep each part to its own job.
> **Status:** PHP structure + JS syntax verified (no runtime here); migration for staging (not run). Committed on `v2`. Builds on `f2faf55` (data), `5d8fb9f` (alignment), `5ab5daa` (admin), `230eb0d` (storefront).
> **Date:** 2026-08-22.

## 1. What it reuses (composition, not from scratch)

| Native piece | Reused for |
|---|---|
| `deliveries` table (+ `store_id`/`city_id` from Phase 1) | one delivery row per order; status flow pending→assigned→picked→delivered |
| `driver_wallets` + `LedgerEngine::isDriverBlocked` | rider cash-in-hand limit guard during assignment |
| `users.role='delivery'` identity | riders are motherboard users — no external accounts |
| `GeoHelper` Haversine | distance store → customer |
| lock-safe assign pattern (`auto_assign` cron: `FOR UPDATE SKIP LOCKED`) | nearest-rider assignment without double-assigning |
| `cities` / `city_id` | per-city rate cards + city-scoped rider pools |

New delivery-owned schema (migration `2026_08_22_003`): `delivery_rate_cards` (admin pricing per city), `delivery_riders` (rider live availability/geo/capacity), and pricing/geo/tracking columns on `deliveries`.

## 2. Flow (order → delivery → rider → tracking)

1. **Order placed** in the storefront → the `/api/shop/checkout` adapter (commerce scope) calls `DeliveryEngine::createForOrder(orderId, dropLat, dropLng, subtotal)` — a clean cross-engine call, no engine edit.
2. **Priced by distance:** `createForOrder` reads the pickup store's geo + the customer drop, and the city's rate card, and computes the fee (`base + per_km × distance`, floored at `min_fee`, free above a threshold). It writes the price/splits/geo/tracking token on the `deliveries` row and records the fee on `orders.delivery_fee` (the single column the ledger reads for rider payout — item totals untouched).
3. **Nearest-rider assignment** (`assignNearest`, lock-safe): the nearest **online** rider in the city, under capacity, not cash-blocked, is assigned (`FOR UPDATE SKIP LOCKED`); their `active_count` increments. No rider available → stays `pending` for retry / manual reassign.
4. **Rider updates** (rider app): accept → pickup → deliver. Each transition mirrors the delivery status onto `orders.status` (processing/shipped/delivered) so the customer's order timeline advances.
5. **Tracking:** the customer sees rider + fee + status in the storefront order view (`/api/delivery/track/{orderId}`); the admin sees the live board.

## 3. Pricing (admin-configurable, computed by distance)

`delivery_rate_cards` is the admin-editable rate card **per city** (base fare, per-km, min fee, free-above, rider/platform split, max distance). The **system computes** the fee from distance — admins set the rates in the control-room, never code. The storefront shows the fee at checkout via `/api/shop/delivery-quote` (real distance from the cart's store to the customer's geolocation); the exact fee is confirmed at assignment.

## 4. Surfaces (each in its own scope — no khichdi)

| Surface | Scope | Files |
|---|---|---|
| Delivery brain | `core/delivery/` | `DeliveryEngine.php` |
| Customer quote/track + admin control-room API | `heart/api/delivery/` (rewritten native) | `index.php` |
| Rider API | `heart/api/rider/` | `index.php` |
| Rider app (own login, `role='delivery'`) | `rider/` | `index.html`, `rider.js` |
| Admin control-room (rate cards, live board, riders) | `admin/` | `delivery-control.html` |
| Storefront integration (fee + tracking) | `app/commerce/` | `shop.js`, `api.js` (small, API-boundary calls) |

The **rider app is fully separate** — own login, own view, own CSS, talks only to `/api/rider/*`. The admin control-room is RBAC-gated (`delivery.view`/`delivery.manage`, `dispatch_ops` role — all seeded in Phase 1).

## 5. NEVER-TOUCH + money-safety

**No never-touch engine file was edited.** The order→delivery hand-off is a clean call from the commerce `/api/shop` adapter to `DeliveryEngine`. The delivery layer writes only its own `deliveries` rows, plus two minimal cross-engine records on the shared `orders` table: `orders.delivery_fee` (where the ledger reads rider payout) and `orders.status` mirroring (so the customer timeline reflects delivery) — single-column updates, not code edits, and they never change item totals or the `OrderService` logic. Money settlement itself (rider payout + COD cash) is booked by `LedgerEngine::processOrderCompletion` (order-settlement wiring, gap-map Phase C1) — not here; the `reference_type='order'` collision fix is untouched.

**SwiftDeliver is discarded:** the old `heart/api/delivery/index.php` shim (external cURL/webhook) was rewritten to the native API; no external service, secret, or Railway dependency remains. `OrderService::dispatchToDelivery` (never-touch) is a harmless no-op in production (its `SWIFTDELIVER_URL` is unset), so the native hand-off in the storefront adapter is the single delivery path.

## 6. REUSE vs EDIT vs NEW

- **REUSE AS-IS:** `deliveries`/`driver_wallets` tables, `GeoHelper`, `LedgerEngine::isDriverBlocked`, `CityResolver`, the lock-safe assign pattern, admin shell + design system.
- **EDIT (permitted):** `heart/index.php` (+`/api/rider` dispatch), `heart/api/delivery/index.php` (rewritten native, SwiftDeliver removed), `heart/api/shop/index.php` (+delivery hand-off + quote), `app/commerce/{shop,api}.js` (fee + tracking), `admin/shared/shell.js` (+nav).
- **NEW:** `core/delivery/DeliveryEngine.php`, `heart/api/rider/index.php`, `rider/{index.html,rider.js}`, `admin/delivery-control.html`, migration `2026_08_22_003_delivery_engine.sql`.

---

## HOW TO VERIFY (staging, after all commerce migrations; Commerce/Delivery nav hidden in service-only — open pages directly or flip the flag)
1. **Rate card:** admin → Delivery Control → edit the Haldwani card (base/per-km/min/free-above). 
2. **Rider online:** open `/rider/` → log in as a `role='delivery'` user → go online (grants location) → shows in the admin Riders board with a live dot.
3. **Order → delivery:** place a storefront COD order (checkout shows the distance-based fee) → a `deliveries` row is created, priced, and auto-assigned to the nearest online rider → appears on the rider's queue and the admin live board.
4. **Rider flow:** rider Accept → Picked up → Delivered → the customer's order timeline advances (processing → out-for-delivery → delivered) and the storefront order view shows the rider + fee.
5. **No rider:** with all riders offline, the delivery stays `pending`; admin **Reassign** re-runs nearest assignment.

## OPEN QUESTIONS
1. **Rider onboarding** — riders are `users.role='delivery'`; who creates rider accounts (admin users page, a rider self-signup, or an invite)? Not built here.
2. **Old SwiftDeliver admin page** — `admin/delivery.html` (SwiftDeliver) is now superseded by `delivery-control.html`; remove it from the repo/nav?
3. **Delivery fee in the order total** — the fee is recorded on `orders.delivery_fee` (for rider payout) but item `orders.total` stays items-only; the rider collects items+fee for COD. Confirm this split, or should the fee fold into the order total?
4. **Multi-store orders** — one delivery per order today, pickup = the primary store. Split delivery per vendor store is a later extension. OK for launch?
5. **Live map tracking** — rider lat/lng is captured; a live map for the customer (vs the status timeline + rider card) is a later enhancement. Confirm scope.
