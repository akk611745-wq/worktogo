# Phase 08 — Delivery Operational Risks

## Risk Register

### R1 — Assignment Inconsistency Between Vendor Assignment and Rider Assignment

WHERE: PHP `auto_assignments` assigns vendors/providers; SwiftDeliver assigns riders.
WHY: both are called assignment but govern different operational layers.
ROOT CAUSE: no unified assignment domain model.
CONNECTED SYSTEMS: `auto_assignments`, `orders.vendor_id`, `jobs.vendor_id`, SwiftDeliver `delivery_assignments`, riders.
OPERATIONAL IMPACT: teams may confuse vendor/provider acceptance with delivery rider acceptance.
SCALE IMPACT: escalation dashboards can misclassify unassigned orders.
MAINTAINABILITY IMPACT: assignment code changes can affect the wrong layer.
FUTURE RISK: automated dispatch evolution may accidentally merge incompatible assignment models.
Evidence: PHP cron (`wtg  live/cron/auto_assign.php:61`); SwiftDeliver rider assignment (`swiftdeliver-main/server.ts:805`).
Confidence: High.

### R2 — Rider/Driver Drift

WHERE: MySQL schema and PHP code differ between `driver_id` and `rider_id`.
WHY: terminology drift across legacy delivery/admin/SwiftDeliver integrations.
ROOT CAUSE: schema contract not canonicalized.
CONNECTED SYSTEMS: delivery status updates, admin delivery list, ledger, driver wallets, SwiftDeliver rider IDs.
OPERATIONAL IMPACT: delivery updates or admin views can fail or display null riders.
SCALE IMPACT: driver financial tracking and rider operational tracking may diverge.
MAINTAINABILITY IMPACT: migration and code changes are high-risk.
FUTURE RISK: irreversible data confusion if both columns exist with different IDs.
Evidence: schema `driver_id` (`wtg  live/database-structure.sql:420`); webhook `rider_id` (`wtg  live/heart/api/delivery/index.php:141`).
Confidence: High.

### R3 — Delivery/Order Divergence

WHERE: PHP order status, PHP delivery status, SwiftDeliver order status can progress separately.
WHY: no transactional state sync or guaranteed bidirectional webhook on every transition.
ROOT CAUSE: HTTP side effects and non-fatal dispatch after commit.
CONNECTED SYSTEMS: MySQL orders, MySQL deliveries, SQLite orders, SQLite assignments, customer/admin/vendor/rider UI.
OPERATIONAL IMPACT: customer sees one state; rider/admin sees another.
SCALE IMPACT: SLA and reconciliation errors grow with volume.
MAINTAINABILITY IMPACT: bug triage requires cross-database inspection.
FUTURE RISK: refunds/ledger/completion can trigger from wrong state source.
Evidence: dispatch non-fatal (`wtg  live/body/shopping-engine/api/orders/OrderService.php:1023`); Node status route no traced PHP callback (`swiftdeliver-main/server.ts:1125`).
Confidence: High.

### R4 — Tracking Inconsistency

WHERE: SwiftDeliver tracking uses SQLite order/rider coordinates; WorkToGo customer app has no traced direct tracking integration.
WHY: tracking token belongs to SwiftDeliver local order.
ROOT CAUSE: WorkToGo dispatch response logs/returns tracking ID only from explicit delivery dispatch route, while `OrderService::dispatchToDelivery()` does not store returned tracking token.
CONNECTED SYSTEMS: SwiftDeliver `orders.tracking_token`, WorkToGo orders/deliveries, customer UI.
OPERATIONAL IMPACT: customer may not receive usable tracking link from WorkToGo checkout.
SCALE IMPACT: support burden grows when delivery network has tracking but WorkToGo order page does not surface it.
MAINTAINABILITY IMPACT: tracking link propagation must bridge systems.
Evidence: SwiftDeliver tracking token (`swiftdeliver-main/src/db/database.ts:120`); dispatch response includes tracking ID (`swiftdeliver-main/server.ts:1024`); PHP dispatch logs success but does not persist tracking token (`wtg  live/body/shopping-engine/api/orders/OrderService.php:1011`).
Confidence: High.

### R5 — Retry/Recovery Weakness

WHERE: dispatch failures are logged; Node retries broadcasts, not WorkToGo dispatch creation.
WHY: no durable outbox/reconciliation was traced.
ROOT CAUSE: delivery dispatch is a best-effort HTTP side effect.
CONNECTED SYSTEMS: order creation, logs, SwiftDeliver API, WebSocket.
OPERATIONAL IMPACT: lost dispatches require manual detection.
SCALE IMPACT: backlog grows silently under external outage.
MAINTAINABILITY IMPACT: support tools need cross-system reconciliation.
Evidence: PHP logs dispatch failure (`wtg  live/body/shopping-engine/api/orders/OrderService.php:1017`); Node retries pending local orders (`swiftdeliver-main/server.ts:352`).
Confidence: High.

### R6 — Cancellation Inconsistency

WHERE: customer/admin/SwiftDeliver cancellations are separate.
WHY: cancellation side effects are implemented per subsystem.
ROOT CAUSE: no canonical cancellation workflow or webhook in every direction.
CONNECTED SYSTEMS: inventory, orders, deliveries, assignments, riders, notifications.
OPERATIONAL IMPACT: rider can continue with an order cancelled in WorkToGo, or WorkToGo can show active while Node cancelled.
SCALE IMPACT: COD/cash handling and customer trust degrade.
MAINTAINABILITY IMPACT: future cancellation rules must patch multiple paths.
Evidence: customer cancel releases stock only (`wtg  live/body/shopping-engine/api/orders/OrderService.php:337`); admin direct cancel (`wtg  live/heart/api/admin/index.php:648`); Node cancel frees rider (`swiftdeliver-main/server.ts:1178`).
Confidence: High.

### R7 — Fulfillment Synchronization Risk

WHERE: vendor order status and rider delivery status are not strongly coupled.
WHY: vendor readiness/shipping and rider pickup/delivery are separate systems.
ROOT CAUSE: WorkToGo vendor sub-order flow and SwiftDeliver delivery flow are not coordinated by a shared state machine.
CONNECTED SYSTEMS: vendor sub-orders, parent order, delivery network, inventory, ledger.
OPERATIONAL IMPACT: vendor can mark delivered while rider state differs; rider can deliver while vendor sub-order state differs.
SCALE IMPACT: multi-vendor orders become especially risky.
Evidence: vendor delivered updates inventory (`wtg  live/body/shopping-engine/api/orders/OrderService.php:490`); Node delivered updates rider earnings (`swiftdeliver-main/server.ts:1152`).
Confidence: High.

## Bottlenecks

1. WebSocket broadcast to online riders is in-process and role-filtered by iterating clients.
2. SQLite is single-node/local to SwiftDeliver deployment.
3. PHP auto-assignment wraps timeout recovery and new assignments in one transaction.
4. No city/zone indexing or partitioning was traced.
5. Admin operational visibility depends on drift-prone queries.

