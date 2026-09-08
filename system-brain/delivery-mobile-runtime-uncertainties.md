# Phase 08 — Delivery Uncertainty Register

This file explicitly records uncertain or partially traced delivery/location behavior. It prevents false certainty in future AI/developer work.

## Partially Traced Flows

| Flow | What is traced | What remains uncertain | Confidence |
|---|---|---|---|
| WorkToGo checkout dispatch | PHP creates delivery row and calls SwiftDeliver | Whether live payload includes richer customer/vendor/address data through another wrapper | Medium |
| SwiftDeliver status sync to PHP | Initial dispatch webhook traced | Later pickup/delivered/cancelled/failed callbacks not traced in status route | Medium-High |
| Rider mobile reconnect | Backend WS ping and message handling traced | Client reconnect/backoff/offline queue behavior | Medium |
| WorkToGo admin delivery UI | Backend admin delivery endpoints partly traced | Exact page calls and whether drifted routes are used in production | Medium |
| Production schema | Baseline SQL and runtime code traced | Whether live DB has later `rider_id` or `riders` table migrations | Medium |
| Cron scheduling | Cron file traced | Actual hosting scheduler cadence and logs | Medium |

## Hidden Operational Dependencies

1. `SWIFTDELIVER_URL` must be non-placeholder for checkout dispatch.
2. `SWIFTDELIVER_SECRET` must match both PHP->Node and Node->PHP.
3. `PHP_BASE_URL`/`APP_URL` must be configured in SwiftDeliver for callbacks.
4. Google Maps key affects geocoding; without it, city-center fallback is used.
5. SQLite `/data` persistence controls SwiftDeliver continuity across redeploys.
6. Rider WebSocket connectivity controls live location freshness.
7. MySQL schema must match code vocabulary (`driver_id`/`rider_id`).

## Runtime Assumptions Needing Future Inspection

- Whether WorkToGo customer order pages surface SwiftDeliver tracking links.
- Whether Node frontend persists location updates in mobile background.
- Whether admin operations use PHP delivery management, SwiftDeliver admin, or both.
- Whether rider IDs in SwiftDeliver are mapped to WorkToGo `users.id` anywhere.
- Whether delivery payout is meant to use WorkToGo `driver_wallets` or SwiftDeliver `rider_earnings_log`.
- Whether multi-vendor orders should create one delivery or multiple delivery tasks.

## Tracking Ambiguity

Tracking is SwiftDeliver-local in the traced system. WorkToGo dispatch does not visibly store the returned tracking token after automatic checkout dispatch. This means future systems must not assume every WorkToGo order has a customer-visible tracking URL.

Evidence: SwiftDeliver tracking token generation (`swiftdeliver-main/src/db/database.ts:38`); dispatch response (`swiftdeliver-main/server.ts:1024`); PHP dispatch logging without persistence (`wtg  live/body/shopping-engine/api/orders/OrderService.php:1011`).

Confidence: High.

## Scaling Uncertainty

No traced delivery system enforces city/zone partitions. Before multi-city launch, inspect live data and migrations for any untraced city/zone features. If none exist, multi-city rollout is operationally unsafe.

Confidence: High.

