# Phase 11 — Stabilization Priority Matrix

This is an intelligence matrix, not an implementation plan. It identifies priority order for future safe stabilization based on traced fragility, operational risk, maintainability impact, and rewrite pressure.

## Priority Matrix

| Priority | Zone | Why First | Evidence |
|---:|---|---|---|
| 1 | API/intent contract map | Prevents feature breakage and velocity collapse | `wtg  live/vendor/shared/api.js:51-99`, `wtg  live/heart/router.php:195-247` |
| 2 | Payment/refund/ledger domain map | Financial correctness and reconciliation risk | `wtg  live/lib/PaymentService.php:169-357`, `wtg  live/core/helpers/LedgerEngine.php:183-267` |
| 3 | Service booking/job/slot lifecycle | Duplicate booking paths and state drift | `wtg  live/body/service-engine/api/BookingController.php:9-78`, `wtg  live/body/service-engine/api/services/index.php:204-465` |
| 4 | Admin API decomposition readiness | Monolithic operational risk | `wtg  live/heart/api/admin/index.php:1-901` |
| 5 | Config/deployment consistency map | Hidden production fragility | `wtg  live/heart/bootstrap.php:25-81`, `wtg  live/migrate.php:4-35` |
| 6 | Runtime side-effect recovery | Dispatch/payment/delivery failure recovery | `wtg  live/body/shopping-engine/api/orders/OrderService.php:965-1030`, `wtg  live/lib/PaymentService.php:287-340` |
| 7 | DB ownership/schema contract | Shared table coupling | `wtg  live/body/shopping-engine/api/orders/OrderService.php:168-287`, `wtg  live/heart/api/admin/index.php:634-892` |

## Stabilization Priority Findings

### Priority 1 — API/Intent Contract

- WHERE: Customer/vendor frontend clients, Heart router, Body engine switches.
- WHY: Contract drift directly breaks user/vendor workflows.
- ROOT CAUSE: String-based routing without shared source of truth.
- CONNECTED SYSTEMS: all frontend panels, Heart, engines.
- OPERATIONAL IMPACT: Broken workflows produce support incidents.
- MAINTAINABILITY IMPACT: Every feature requires multi-layer route audit.
- SCALE IMPACT: More feature surfaces multiply drift.
- FUTURE RISK: Engineering velocity collapse.
- Confidence: High.

### Priority 2 — Financial Domain Intelligence

- WHERE: Payment/refund/ledger helpers and controllers.
- WHY: Financial correctness is high-stakes and currently fragmented.
- ROOT CAUSE: Multiple overlapping payment/refund paths.
- CONNECTED SYSTEMS: orders, bookings, transactions, wallets, Cashfree.
- OPERATIONAL IMPACT: Reconciliation incidents can be severe.
- MAINTAINABILITY IMPACT: Changes are risky without a full domain map.
- SCALE IMPACT: Volume amplifies mismatch cost.
- FUTURE RISK: Rewrite pressure becomes unavoidable.
- Confidence: High.

### Priority 3 — Service Booking Lifecycle

- WHERE: service request/job path and slot reservation path.
- WHY: Duplicate booking flows create state ambiguity.
- ROOT CAUSE: Legacy and current booking systems coexist.
- CONNECTED SYSTEMS: bookings, jobs, slots, vendors, addresses.
- OPERATIONAL IMPACT: Customers/vendors may see divergent booking behavior.
- MAINTAINABILITY IMPACT: Fixes may miss a second path.
- SCALE IMPACT: Scheduling complexity grows with vendors/cities.
- FUTURE RISK: Rewrite likely if services grow.
- Confidence: High.

## Stabilization Constraints

- Stabilization should not begin with code rewrites until contracts and ownership maps are complete.
- Financial and booking domains should be mapped as workflows before module decomposition.
- Admin decomposition must preserve current operational controls and audit existing endpoints.
- DB/schema stabilization must account for every direct table user.

