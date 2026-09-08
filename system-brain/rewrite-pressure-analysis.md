# Phase 11 — Rewrite Pressure Analysis

## Rewrite Pressure Ranking

| Rank | Zone | Rewrite Pressure | Reason |
|---:|---|---|---|
| 1 | Payment/refund/ledger | Very High | fragmented financial domain and reconciliation risk |
| 2 | Service bookings/jobs/slots | High | duplicate booking paths and state model drift |
| 3 | Admin API | High | monolithic operational controller |
| 4 | Vendor API contract | High | intent drift and frontend/backend mismatch |
| 5 | Runtime router architecture | High | direct REST + intent pipeline duality |
| 6 | Delivery synchronization | Medium-high | multiple writers, external callback, no event ledger |
| 7 | Config/deployment layer | Medium-high | duplicated env parsing and local filesystem assumptions |
| 8 | Checkout/order orchestration | Medium-high | large service with many responsibilities |

## Rewrite-Worthy Zones

### Financial Domain

- WHERE: Product payment service, core payment helper, refund controller, ledger engine, webhook.
- WHY: Money flows cross several helpers and table states.
- ROOT CAUSE: Payment/refund/ledger responsibilities are distributed by feature, not domain.
- CONNECTED SYSTEMS: Cashfree, transactions, orders, bookings, ledgers, wallets, refunds.
- OPERATIONAL IMPACT: Manual reconciliation and error tracing are difficult.
- MAINTAINABILITY IMPACT: Payment changes are high-risk and cross-file.
- SCALE IMPACT: Financial complexity grows nonlinearly with order volume.
- FUTURE RISK: Future rewrite likely necessary for settlement, audit, and reconciliation safety.
- Evidence: `wtg  live/api/payment/webhook.php:43-98`, `wtg  live/lib/PaymentService.php:169-357`, `wtg  live/core/helpers/Payment.php:11-260`, `wtg  live/core/controllers/RefundController.php:176-307`, `wtg  live/core/helpers/LedgerEngine.php:39-267`.
- Confidence: High.

### Service Booking Domain

- WHERE: `BookingController`, `SlotController`, `services/index.php`, service engine intent mapper.
- WHY: There are two booking creation models: slot reservation path and service request/job path.
- ROOT CAUSE: Legacy and current flows coexist.
- CONNECTED SYSTEMS: bookings, jobs, slots, reservations, vendors, addresses, payment.
- OPERATIONAL IMPACT: Different entrypoints can produce different booking states.
- MAINTAINABILITY IMPACT: Feature changes can miss one path.
- SCALE IMPACT: Slot and job state synchronization complexity grows with usage.
- FUTURE RISK: Rewrite pressure rises when scheduling, payments, and vendor dispatch need consistency.
- Evidence: `wtg  live/body/service-engine/api/BookingController.php:9-78`, `wtg  live/body/service-engine/api/SlotController.php:20-160`, `wtg  live/body/service-engine/api/services/index.php:204-465`, `wtg  live/body/service-engine/index.php:29-72`.
- Confidence: High.

### Admin Operations Domain

- WHERE: One large admin file handles many unrelated operational controls.
- WHY: Admin functionality accumulated in one endpoint include.
- ROOT CAUSE: Lack of domain controllers/services for admin.
- CONNECTED SYSTEMS: users, vendors, services, orders, deliveries, payments, riders, merchants, config, logs.
- OPERATIONAL IMPACT: Admin changes can destabilize unrelated operations.
- MAINTAINABILITY IMPACT: High merge conflict/regression risk.
- SCALE IMPACT: Admin live queries become operational bottlenecks.
- FUTURE RISK: Admin modularization or rewrite likely as operations grow.
- Evidence: `wtg  live/heart/api/admin/index.php:1-901`.
- Confidence: High.

## Systems Not Yet Rewrite-Worthy

- JWT encode/decode helper: stable and focused. Evidence: `wtg  live/core/helpers/JWT.php:7-98`.
- Product read service: moderate complexity but cohesive. Evidence: `wtg  live/body/shopping-engine/api/products/ProductService.php:25-299`.
- Cart service: bounded responsibility. Evidence: `wtg  live/body/shopping-engine/api/cart/CartService.php:18-335`.

## Safe Stabilization Before Rewrite

This phase does not prescribe implementation changes, but the trace indicates future stabilization should start with documentation/contracts around APIs, payments, service bookings, and admin operations before code rewrites. Rewrite without contract mapping would be risky because DB tables are shared integration boundaries.

