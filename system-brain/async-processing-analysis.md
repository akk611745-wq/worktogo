# Phase 09 — Async Processing Analysis

## Async/Background Mechanisms Found

| Mechanism | Type | Trigger | State durability | Evidence |
|---|---|---|---|---|
| PHP auto assignment | cron script | external scheduler | DB writes | `wtg  live/cron/auto_assign.php:1` |
| Alert cleanup | cron script | external scheduler | deletes seen alerts | `wtg  live/api/alerts/cleanup.php:5` |
| Brain nightly job | CLI/cron | external scheduler | purges/decays Brain data | `wtg  live/brain/scripts/NightlyJob.php:8` |
| Story expiry | cron route/script | external scheduler | content cleanup | `wtg  live/heart/cron/expire_stories.php` |
| SwiftDeliver background intervals | Node `setInterval` | process runtime | SQLite/notifications | `swiftdeliver-main/server.ts:352`, `swiftdeliver-main/server.ts:383` |
| Frontend polling | browser intervals | active page | local memory | `wtg  live/app/js/router.js:113`, `wtg  live/vendor/shared/realtime.js:302` |
| Webhooks | external async HTTP | Cashfree/SwiftDeliver/Brain integrations | DB/logs | `wtg  live/api/payment/webhook.php:1`, `wtg  live/heart/api/delivery/index.php:115` |

## Queue Assumptions

No real queue worker, queue table, durable outbox, or message broker was traced in WorkToGo PHP. Background processing is implemented as cron scripts, external webhooks, frontend polling, or Node timers.

Operational impact: async reliability depends on schedulers, external retries, active browser tabs, and process uptime.
Scale implications: there is no backpressure or worker scaling model for PHP side effects.
Maintainability impact: developers must not assume eventual retry unless a specific cron/webhook/poll path exists.
Confidence: High.

## Idempotency Protections Found

| Flow | Idempotency mechanism | Evidence | Confidence |
|---|---|---|---|
| Payment webhook paid order | skip if order already paid | `wtg  live/lib/PaymentService.php:209` | High |
| Payment transaction insert | check existing successful transaction | `wtg  live/lib/PaymentService.php:244` | High |
| SwiftDeliver dispatch | existing order by `woocommerce_order_id` | `swiftdeliver-main/server.ts:933` | High |
| Alert creation | duplicate check within time window | `wtg  live/api/alerts/AlertEngine.php:75` | High |
| Ledger completion | `ledger_status` and wallet transaction check | `wtg  live/core/helpers/LedgerEngine.php:62`, `wtg  live/core/helpers/LedgerEngine.php:68` | High |
| Assignment acceptance | transaction + accepted-assignment guard | `wtg  live/api/assignments/accept.php:85` | High |

## Missing/Weak Idempotency

| Flow | Weakness | Evidence | Confidence |
|---|---|---|---|
| Delivery status webhook | no traced event ID or transition ordering | `wtg  live/heart/api/delivery/index.php:141` | High |
| Admin direct status updates | direct overwrites without transition guards | `wtg  live/heart/api/admin/index.php:634` | High |
| Node order status route | no current-state transition graph enforced | `swiftdeliver-main/server.ts:1125` | High |
| Brain external webhook | no durable retry/idempotency table | `wtg  live/brain/engine/IntegrationLayer.php:251` | High |
| Order post-commit delivery dispatch | logs failure, no retry marker | `wtg  live/body/shopping-engine/api/orders/OrderService.php:1023` | High |

## Async Consistency Risks

### No durable outbox for post-commit side effects
WHERE: order creation emits event and dispatches delivery after DB commit.
WHY: side effects are direct method/cURL calls.
ROOT CAUSE: no outbox table/worker was traced.
CONNECTED SYSTEMS: orders, inventory, delivery, alerts, logs.
OPERATIONAL IMPACT: successful orders can miss downstream dispatch/events.
SCALE IMPACT: retry storms or outage recovery must be manual.
MAINTAINABILITY IMPACT: adding more side effects increases inconsistency.
FUTURE RISK: eventually-consistent architecture cannot evolve safely without durable event records.
Evidence: post-commit hooks (`wtg  live/body/shopping-engine/api/orders/OrderService.php:303`, `wtg  live/body/shopping-engine/api/orders/OrderService.php:305`, `wtg  live/body/shopping-engine/api/orders/OrderService.php:308`).
Confidence: High.

### Scheduler dependency uncertainty
WHERE: PHP cron scripts.
WHY: repo contains scripts but not hosting scheduler configuration.
ROOT CAUSE: cron cadence is external operational configuration.
CONNECTED SYSTEMS: assignment, alerts cleanup, stories, Brain retention.
OPERATIONAL IMPACT: background tasks may not run in some deployments.
SCALE IMPACT: missed cron increases stale locks, stale alerts, expired stories, and Brain table growth.
MAINTAINABILITY IMPACT: future developers must verify deployment scheduler separately.
Confidence: Medium-High.

