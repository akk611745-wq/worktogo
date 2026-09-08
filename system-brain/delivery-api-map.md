# Phase 08 — Delivery API Map

## WorkToGo PHP Delivery APIs

| API | Auth | Request | Runtime behavior | Evidence | Confidence |
|---|---|---|---|---|---|
| `GET /api/delivery/quote` | `ROLE_DELIVERY` or `ROLE_ADMIN` | `pickup_lat`, `pickup_lng`, `drop_lat`, `drop_lng` | Validates coordinate ranges, calculates price | `wtg  live/heart/api/delivery/index.php:12` | High |
| `POST /api/delivery/dispatch` | `ROLE_DELIVERY` or `ROLE_ADMIN` | `order_id` | Updates order processing, creates delivery row, calls SwiftDeliver | `wtg  live/heart/api/delivery/index.php:36` | High |
| `PATCH /api/delivery/{id}/status` | `ROLE_DELIVERY` or `ROLE_ADMIN` | `status` in `picked/delivered/cancelled` | Updates delivery by `driver_id`; mirrors order to shipped/delivered | `wtg  live/heart/api/delivery/index.php:91` | High |
| `POST /api/delivery/status-update` | webhook secret, no user role | `delivery_id`, `order_id`, `status`, `rider_id` | Updates delivery and order mirror | `wtg  live/heart/api/delivery/index.php:115` | High |

Important issue: `status-update` code sits after a `catch` block rather than inside the main `try`, but still executes because route matching continues after the try. This is unusual and maintainability-risky.
Evidence: catch ends before webhook block (`wtg  live/heart/api/delivery/index.php:111`, `wtg  live/heart/api/delivery/index.php:115`).

## WorkToGo Admin Delivery APIs

| API | Auth | Runtime behavior | Evidence | Risk |
|---|---|---|---|---|
| `POST /api/admin/deliveries/assign` | admin | Direct MySQL delivery row insert; order processing | `wtg  live/heart/api/admin/index.php:552` | Bypasses SwiftDeliver |
| `GET /api/admin/deliveries` | admin | Lists deliveries with order/customer/rider joins | `wtg  live/heart/api/admin/index.php:695` | Uses `rider_id` drift |
| `GET /api/admin/riders` | admin | Lists `riders` table and completed counts | `wtg  live/heart/api/admin/index.php:804` | Baseline MySQL lacks `riders` table in traced schema |
| `GET /api/admin/orders/{id}` | admin | Loads order, delivery, payment details | `wtg  live/heart/api/admin/index.php:657` | Uses `customer_id`, `rider_id`, `payments` drift |

## SwiftDeliver APIs

| API | Auth | Runtime behavior | Evidence | Confidence |
|---|---|---|---|---|
| `POST /api/dispatch` | bearer `SWIFTDELIVER_SECRET` | Creates local order and broadcasts | `swiftdeliver-main/server.ts:910` | High |
| `GET /api/track/:token` | public if enabled | Returns order, items, rider location | `swiftdeliver-main/server.ts:479` | High |
| `POST /api/riders/status` | rider JWT | Sets rider status/location; sends pending orders when online | `swiftdeliver-main/server.ts:769` | High |
| `POST /api/orders/:id/rider-accept` | rider JWT | Self-accept assignment | `swiftdeliver-main/server.ts:805` | High |
| `PUT /api/orders/:id/status` | JWT; rider ownership check only for riders | Updates order and assignment lifecycle | `swiftdeliver-main/server.ts:1125` | High |
| `POST /api/orders/:id/reassign` | admin JWT | Deletes old assignment and assigns specific rider | `swiftdeliver-main/server.ts:1219` | High |
| `POST /api/orders/:id/ready` | vendor JWT | Marks vendor ready and notifies assigned rider | `swiftdeliver-main/server.ts:650` | High |
| `POST /api/vendors/toggle-online` | vendor JWT | Vendor online/offline | `swiftdeliver-main/server.ts:627` | High |

## Permission Model Findings

### WorkToGo PHP

Delivery route requires delivery/admin except webhook.
Evidence: role gate (`wtg  live/heart/api/delivery/index.php:7`).

Risk: PATCH status uses `driver_id = auth.user_id`, but webhook uses `rider_id`, admin assignment uses `driver_id`, and admin listing uses `rider_id`. This creates permission/data mismatch.

### SwiftDeliver

Node roles are independent (`admin`, `vendor`, `rider`) and JWT-scoped within SwiftDeliver.
Evidence: role type and auth (`swiftdeliver-main/server.ts:27`, `swiftdeliver-main/server.ts:46`, `swiftdeliver-main/server.ts:57`).

Risk: WorkToGo users and SwiftDeliver riders/vendors are not proven to share identity IDs. A WorkToGo `rider_id` passed in webhook is a SwiftDeliver rider ID, not necessarily a WorkToGo `users.id`.

## API Drift and Inconsistency Register

| Issue | Where | Impact | Confidence |
|---|---|---|---|
| WorkToGo admin config references `DELIVERY_STATUS(id)` but traced admin backend has no matching `/api/admin/deliveries/{id}` route in inspected segment | `wtg  live/admin/config.js:44` | frontend/backend drift | Medium |
| PHP webhook writes `rider_id` but baseline delivery table uses `driver_id` | `wtg  live/heart/api/delivery/index.php:141`, `wtg  live/database-structure.sql:420` | potential SQL failure | High |
| PHP dispatch sends only `order_id`/`delivery_id`, while SwiftDeliver can accept rich payload | `wtg  live/body/shopping-engine/api/orders/OrderService.php:1002`, `swiftdeliver-main/server.ts:941` | default address/customer fields in Node | High |
| Node status transitions do not visibly call PHP webhook | `swiftdeliver-main/server.ts:1125` | PHP order status can lag | High |
| WorkToGo quote path is role-gated to delivery/admin, not customer checkout | `wtg  live/heart/api/delivery/index.php:7` | quote may not be customer-facing | High |

