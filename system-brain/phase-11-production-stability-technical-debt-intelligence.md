# Phase 11 — Production Stability + Technical Debt Intelligence Synthesis

## Final Intelligence

WorkToGo's production stability is strongest in smaller, cohesive helpers/services and weakest where multiple generations of architecture overlap. Stable zones include JWT encoding/decoding, product catalog read service, and cart service. Dangerous zones include payment/refund/ledger, service booking/job/slot flows, admin monolith, vendor intent contract, direct REST versus Heart pipeline split, local filesystem infrastructure, and shared DB table coupling.

## Stable Parts

- JWT helper: compact and deterministic. Evidence: `wtg  live/core/helpers/JWT.php:7-98`.
- Product catalog read path: clearer service/controller split. Evidence: `wtg  live/body/shopping-engine/api/products/ProductService.php:25-299`.
- Cart service: bounded cart responsibilities. Evidence: `wtg  live/body/shopping-engine/api/cart/CartService.php:18-335`.
- Reflex cache interface: clear abstraction despite risky file fallback. Evidence: `wtg  live/heart/pipeline/ReflexEngine.php:162-168`.

## Dangerous Parts

- Payment/refund/ledger: financial logic split across several helpers/controllers. Evidence: `wtg  live/lib/PaymentService.php:169-357`, `wtg  live/core/helpers/Payment.php:11-260`, `wtg  live/core/controllers/RefundController.php:176-307`, `wtg  live/core/helpers/LedgerEngine.php:39-267`.
- Service bookings: duplicate booking flows with different contracts. Evidence: `wtg  live/body/service-engine/api/BookingController.php:9-78`, `wtg  live/body/service-engine/api/services/index.php:204-324`.
- Admin API: monolithic high-authority controller. Evidence: `wtg  live/heart/api/admin/index.php:1-901`.
- Vendor API contract: string intent drift. Evidence: `wtg  live/vendor/shared/api.js:51-99`, `wtg  live/body/service-engine/index.php:29-72`.
- Runtime integration: superglobal mutation through internal includes. Evidence: `wtg  live/heart/pipeline/EngineLoader.php:82-143`.

## Where Engineering Velocity Will Degrade

1. Adding vendor features due to intent/router/engine drift.
2. Changing finance due to fragmented payment/refund/ledger code.
3. Changing schema due to shared DB table ownership.
4. Changing admin operations due to monolithic file and direct table queries.
5. Deploying across environments due to duplicated env parsing and local filesystem assumptions.

## What Systems May Require Future Rewrites

1. Financial domain: payment/refund/ledger/reconciliation.
2. Service scheduling domain: bookings/jobs/slots.
3. Admin operations API.
4. Vendor API contract/runtime layer.
5. Event/side-effect recovery model.

## What Fails First Under Production Pressure

- DB-centered operations fail first, followed by PHP worker saturation and human operational overload.
- Under engineering pressure, API contract drift and shared-schema coupling fail first.
- Under multi-node scaling, local logs/cache/uploads/cron locks fail first.

## Mandatory Uncertainty Report

- Partially traced fragile areas: not every legacy auth/vendor/admin page script was reread in Phase 11.
- Uncertain runtime dependencies: PHP module availability, hosting resource limits, cron topology, production env values.
- Hidden debt assumptions: schema indexes/constraints and real production data volumes were not benchmarked.
- Maintainability ambiguity: test coverage and team knowledge are unknown.
- Deployment uncertainty: migration execution workflow, backup/rollback process, and monitoring are not traced.
- Long-term uncertainty: roadmap for multi-city, online payments, and delivery providers can change priorities.

## Stabilization Priority Order

1. API/intent contract intelligence and drift register.
2. Payment/refund/ledger domain ownership map.
3. Service booking/job/slot lifecycle map.
4. Admin API decomposition readiness map.
5. Config/deployment consistency map.
6. Side-effect recovery/outbox readiness map.
7. DB schema ownership map.

