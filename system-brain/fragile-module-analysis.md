# Phase 11 — Fragile Module Analysis

## Module Stability Classification

| Module | Stability | Fragility Reason | Confidence |
|---|---|---|---|
| JWT helper | Stable | small, focused, deterministic | High |
| Auth middleware | Medium | DB blacklist dependency and stale claims | High |
| Heart REST dispatcher | Medium-low | large routing switch and direct/pipeline split | High |
| Heart pipeline | Medium | synchronous fanout and cache/Brain coupling | High |
| Shopping cart | Medium-high | cohesive service but DB-heavy | High |
| Shopping orders | Medium | large transactional service + delivery side effects | High |
| Product catalog | Medium-high | clearer controller/service split | High |
| Services/bookings | Medium-low | duplicate booking paths and inconsistent intent names | High |
| Admin API | Medium-low | monolithic high-authority controller | High |
| Payment/refund/ledger | Low-medium | multiple helpers and gateway/ledger coupling | High |
| Delivery integration | Low-medium | external callback and multiple writers | High |
| Brain | Medium-low | separate router/CORS/admin model | Medium-high |

## Fragile Modules

### Shopping Orders

- WHERE: `OrderService` handles rate limiting, address validation, payment-method gating, cart loading, stock locking, parent/child order insertion, item snapshots, inventory reservation, product counters, cart clear, event emission, and delivery dispatch.
- WHY: Checkout is central business logic.
- ROOT CAUSE: Too many responsibilities in one service.
- CONNECTED SYSTEMS: cart, products, inventory, orders, order_items, delivery, payment, EventEngine.
- OPERATIONAL IMPACT: A small checkout change can break inventory, vendor orders, delivery, or customer history.
- MAINTAINABILITY IMPACT: High cognitive load and regression risk.
- SCALE IMPACT: Larger carts and high concurrency increase lock duration.
- FUTURE RISK: Multi-vendor/online payment expansion may require rewrite of checkout orchestration.
- Evidence: `wtg  live/body/shopping-engine/api/orders/OrderService.php:67-330`, `wtg  live/body/shopping-engine/api/orders/OrderService.php:938-1030`.
- Runtime dependency: MySQL transaction semantics, cart state, inventory schema.
- Confidence: High.

### Services/Bookings

- WHERE: `BookingController::createBooking()` creates booking and slot reservation using request `user_id`; `services/index.php` creates service booking and linked job through a separate path; service engine maps only some service intents.
- WHY: Service workflow has legacy slot and newer job-based booking flows.
- ROOT CAUSE: Duplicate booking implementations with different request contracts.
- CONNECTED SYSTEMS: bookings, jobs, vendor availability slots, slot reservations, addresses, payment.
- OPERATIONAL IMPACT: Service booking behavior differs by route and can be difficult to support.
- MAINTAINABILITY IMPACT: Engineers may patch one booking path and miss the other.
- SCALE IMPACT: Slot contention and job state propagation are not unified.
- FUTURE RISK: Booking subsystem is a rewrite-pressure zone.
- Evidence: `wtg  live/body/service-engine/api/BookingController.php:9-78`, `wtg  live/body/service-engine/api/services/index.php:204-324`, `wtg  live/body/service-engine/index.php:45-64`.
- Runtime dependency: route exposure and DB schema for bookings/jobs/slots.
- Confidence: High.

### Admin API

- WHERE: `heart/api/admin/index.php` is a single file spanning dashboard, stats, vendors, services, orders, settings, users, deliveries, logs, products, payments, riders, merchants, and system config.
- WHY: Admin operations centralized in one include.
- ROOT CAUSE: Route/controller separation not applied to admin domain.
- CONNECTED SYSTEMS: nearly all DB tables and operational workflows.
- OPERATIONAL IMPACT: Admin fixes risk collateral damage across unrelated operational controls.
- MAINTAINABILITY IMPACT: Large monolithic file slows code review and increases merge conflicts.
- SCALE IMPACT: Admin queries can become production DB load during incidents.
- FUTURE RISK: Admin domain will require decomposition as operations grow.
- Evidence: `wtg  live/heart/api/admin/index.php:1-901`.
- Runtime dependency: admin JWT, MySQL, Logger, settings/refund helpers.
- Confidence: High.

### Payment/Refund/Ledger

- WHERE: `PaymentService`, `Payment`, `RefundController`, and `LedgerEngine` all participate in financial state.
- WHY: Product payment, service payment, refunds, wallet settlement, and Cashfree integration evolved in parallel.
- ROOT CAUSE: Financial domain lacks one orchestrator and event ledger.
- CONNECTED SYSTEMS: transactions, orders, bookings, wallet_transactions, vendor_wallets, driver_wallets, Cashfree.
- OPERATIONAL IMPACT: Reconciliation requires tracing several modules and logs.
- MAINTAINABILITY IMPACT: Payment provider change touches multiple files.
- SCALE IMPACT: More transactions increase reconciliation and support burden.
- FUTURE RISK: Finance module rewrite likely if online/COD/settlement features expand.
- Evidence: `wtg  live/lib/PaymentService.php:169-357`, `wtg  live/core/helpers/Payment.php:111-260`, `wtg  live/core/controllers/RefundController.php:19-307`, `wtg  live/core/helpers/LedgerEngine.php:39-267`.
- Runtime dependency: Cashfree APIs, DB transactions, ledger status fields.
- Confidence: High.

## Relatively Stable Modules

- Product catalog read path is cohesive, with list/detail/formatting in `ProductService`. Evidence: `wtg  live/body/shopping-engine/api/products/ProductService.php:25-299`.
- Cart service is encapsulated and has clear limits for item count/quantity/TTL. Evidence: `wtg  live/body/shopping-engine/api/cart/CartService.php:18-335`.
- JWT helper is self-contained. Evidence: `wtg  live/core/helpers/JWT.php:7-98`.

