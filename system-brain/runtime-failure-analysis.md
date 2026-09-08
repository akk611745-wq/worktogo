# Phase 09 — Runtime Failure Analysis

## Failure Handling Surfaces

| Surface | Failure behavior | Evidence | Confidence |
|---|---|---|---|
| Global Heart exception | logs to Logger and `logs`, inserts system alert, returns 500 | `wtg  live/heart/index.php:18`, `wtg  live/heart/index.php:28` | High |
| Heart pipeline exception | logs hidden details and returns generic 500 | `wtg  live/heart/router.php:79` | High |
| Brain call failure | fallback result, circuit breaker if APCu | `wtg  live/heart/pipeline/BrainConnector.php:74`, `wtg  live/heart/pipeline/BrainConnector.php:105` | High |
| Engine failure | result `ok=false`, skipped by priority | `wtg  live/heart/pipeline/EngineLoader.php:284`, `wtg  live/heart/pipeline/PriorityResolver.php:80` | High |
| Customer API network failure | returns structured error, no retry except polling pages | `wtg  live/app/js/api.js:65` | High |
| Router polling failure | silently ignored until next interval | `wtg  live/app/js/router.js:119` | High |
| Vendor realtime poll failure | silently ignored | `wtg  live/vendor/shared/realtime.js:259` | High |
| Payment webhook process failure | returns 200 to prevent retry loops after logging | `wtg  live/api/payment/webhook.php:88` | High |
| DB transaction failure | rollback in service/order/payment/assignment/ledger flows | `wtg  live/body/service-engine/api/services/index.php:309`, `wtg  live/api/assignments/accept.php:143`, `wtg  live/core/helpers/LedgerEngine.php:133` | High |

## Runtime Retry/Recovery Behavior

### Traced retry/recovery
- Customer polling retries on next interval for orders/bookings.
- Vendor realtime polling retries on next interval silently.
- Brain circuit breaker prevents repeated Brain calls after failure threshold when APCu exists.
- Auto assignment cron retries timed-out vendor assignments and recovers old locks.
- SwiftDeliver background job retries broadcasting pending local orders.
- Cashfree retries only when webhook returns non-2xx; WorkToGo returns 500 on DB unavailable but 200 on known processing failure.

Evidence:
- Router polling retry by interval (`wtg  live/app/js/router.js:113`).
- Brain circuit (`wtg  live/heart/pipeline/BrainConnector.php:117`).
- PHP auto assignment timeout/recovery (`wtg  live/cron/auto_assign.php:34`, `wtg  live/cron/auto_assign.php:57`).
- SwiftDeliver background retry (`swiftdeliver-main/server.ts:352`).
- Webhook DB unavailable 500 (`wtg  live/api/payment/webhook.php:77`); known processing failure 200 (`wtg  live/api/payment/webhook.php:88`).

### Missing/weak retry behavior
- No traced retry for failed `OrderService` SwiftDeliver dispatch after checkout.
- No durable retry for failed Node-to-PHP delivery webhook.
- No retry queue for failed Brain IntegrationLayer webhooks.
- No durable replay for missed WebSocket events.
- No centralized failed-event table.

Confidence: High.

## Runtime Deadlock/Lock Risks

### Cron transaction scope
WHERE: PHP auto assignment begins one transaction, handles timed-out assignments, lock recovery, selects entities `FOR UPDATE SKIP LOCKED`, computes vendors, inserts alerts, then commits.
Evidence: transaction start/commit (`wtg  live/cron/auto_assign.php:32`, `wtg  live/cron/auto_assign.php:182`); locked queries (`wtg  live/cron/auto_assign.php:35`, `wtg  live/cron/auto_assign.php:61`).
WHY/root cause: multiple operational tasks in one transaction.
CONNECTED SYSTEMS: jobs, orders, auto_assignments, alerts, vendors.
OPERATIONAL IMPACT: slow vendor search or large batches can hold locks.
SCALE IMPACT: high order/job volume can increase contention.
MAINTAINABILITY IMPACT: adding work inside cron transaction increases deadlock risk.
FUTURE RISK: multi-city assignment workload will amplify transaction time.
Confidence: High.

### Ledger transaction lock risk
WHERE: ledger processing uses `FOR UPDATE` and wallet transaction checks.
Evidence: LedgerEngine transaction and `FOR UPDATE` (`wtg  live/core/helpers/LedgerEngine.php:42`, `wtg  live/core/helpers/LedgerEngine.php:45`, `wtg  live/core/helpers/LedgerEngine.php:68`).
OPERATIONAL IMPACT: duplicate completion/refund attempts are protected but can block.
SCALE IMPACT: financial writes serialize around order/wallet rows.
Confidence: High.

## Bottlenecks

1. PHP workers can block on cURL calls to Brain, engines, Cashfree, SwiftDeliver, and integration webhooks.
2. Reflex file cache fallback uses filesystem under `logs/reflex_cache`.
3. Customer and vendor polling creates repeated read load.
4. Alert polling uses DB queries per active user/vendor interval.
5. SwiftDeliver WebSocket broadcasts iterate in-memory clients.
6. SQLite SwiftDeliver database is single-node.
7. Admin monolithic route file performs many direct queries without a shared service layer.

## Major Failure Finding — Logs replace durable recovery

WHERE: dispatch failures, webhook failures, integration webhook failures, realtime missed events.
WHY: no failed-events/outbox/retry queue was traced.
ROOT CAUSE: synchronous HTTP plus logging design.
CONNECTED SYSTEMS: payment, delivery, Brain, alerts, admin operations.
OPERATIONAL IMPACT: support teams must inspect logs to discover lost side effects.
SCALE IMPACT: log-only recovery becomes impossible at high volume.
MAINTAINABILITY IMPACT: future changes must not assume retries exist.
FUTURE RISK: operational synchronization will become dangerous as async integrations grow.
Confidence: High.

