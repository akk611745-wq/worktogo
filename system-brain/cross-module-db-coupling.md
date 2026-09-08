# Phase 06 — Cross-Module DB Coupling

Generated: 2026-05-12T16:20:00Z

## Coupling Map

| Table | Modules Coupled | Coupling Type | Risk |
|---|---|---|---|
| `users` | Auth, admin, vendor, customer, delivery, alerts, wallet | Identity + role + status | Role/status changes ripple across all modules. |
| `vendors` | Vendor onboarding, admin, products, services, orders, bookings, jobs, stories, analytics, assignment | Ownership + type/status + geo | Column drift and status/role split. |
| `orders` | Customer, vendor, admin, payment, delivery, inventory, alerts, assignment | Lifecycle state + financial total | Direct writes bypass lifecycle. |
| `inventory` | Product, cart, checkout, delivery, admin | Stock availability + reservation | Counter corruption/hot rows. |
| `bookings` | Customer service, vendor jobs, payment, assignment, analytics | Service lifecycle | Slot/job/payment coupling split. |
| `jobs` | Service fulfillment, assignment, vendor, admin | Work execution state | Status mirrors booking only in one endpoint. |
| `transactions` | Payment, refund, analytics, vendor dashboards | Financial source | Polymorphic joins no FK. |
| `deliveries` | Order dispatch, admin assignment, delivery API, SwiftDeliver | Fulfillment logistics | driver/rider drift. |
| `alerts` | Assignment, orders, payments, system errors, frontend notification | Operational messaging | Polymorphic stale refs. |

## Hidden Coupling Chains

### Vendor Approval Chain

`vendors.status/type` -> `users.role` -> JWT role -> vendor panel access -> vendor product/service/order APIs.

Impact: changing vendor type/status without token refresh and role sync creates stale access.

### Checkout Chain

`cart` -> `products` -> `inventory` -> `orders parent` -> `orders children` -> `order_items` -> `inventory.reserved` -> `cart delete` -> `deliveries`.

Impact: schema change anywhere in this chain can break checkout or create partial state.

### Service Chain

`services` -> `bookings` -> `jobs` -> `auto_assignments` -> `alerts` -> `bookings.status` mirror.

Impact: service schema/booking status changes affect jobs and assignment.

### Delivery Chain

`orders` -> `deliveries.driver_id` -> `users.role=delivery` -> `driver_wallets` -> admin delivery APIs -> SwiftDeliver external dispatch.

Impact: driver/rider naming drift and external dispatch failures are hidden behind order status.

## Maintainability Impact

- Direct SQL exists in many controllers; no central repository isolates schema changes.
- Admin router combines current and legacy schema vocabulary.
- Frontend compatibility aliases (`type AS vendor_type`) hide backend schema evolution from API consumers but not from migrations.
- Polymorphic tables require code-level enforcement everywhere.

## Uncertainty Reporting

- Not every frontend page request was replayed; coupling is inferred from backend route/query traces and existing Phase 03-05 intelligence.
- External SwiftDeliver DB behavior is outside this PHP schema trace.
