# Phase 10 — Recovery + Disaster Analysis

## Recovery Reality

Recovery is mostly implicit: logs, admin manual controls, cron lock cleanup, payment provider retries for 500 responses, and polling refresh. No traced durable event replay, outbox, dead-letter queue, backup verification, point-in-time recovery workflow, or disaster runbook exists in the active source paths inspected in this phase.

## Traced Recovery Mechanisms

| Mechanism | Where | What It Recovers | Boundary |
|---|---|---|---|
| Auth fail secure | `AuthMiddleware` blacklist catch | denies auth when blacklist DB check fails | also causes outage for protected APIs |
| Brain fallback/circuit | `BrainConnector` | safe defaults when Brain fails | APCu-dependent circuit |
| Auto assignment timeout | `cron/auto_assign.php` | stale pending assignments | cron/local lock dependent |
| Assignment lock cleanup | `cron/auto_assign.php` | old `assignment_lock_time` | DB-only heuristic |
| Payment provider retry | webhook returns 500 on DB unavailable | Cashfree may retry | returns 200 for processing failures |
| Admin manual controls | `/api/admin/*` | status/user/vendor/log/config changes | broad authority, manual risk |

## Disaster Weaknesses

### D1 — No durable outbox for critical side effects

- WHERE: Order dispatch and payment dispatch are post-commit cURL/log paths.
- WHY: Non-fatal downstream failures do not roll back order/payment.
- ROOT CAUSE: No traced outbox table or retry worker.
- CONNECTED SYSTEMS: orders, payments, delivery, alerts.
- SECURITY IMPACT: Manual recovery can bypass controlled state transitions.
- PERFORMANCE IMPACT: repeated manual checks and polling.
- SCALE IMPACT: Failure backlog has no durable queue.
- FUTURE RISK: Disaster recovery requires reconstructing intended side effects from DB/logs.
- Evidence: `wtg  live/body/shopping-engine/api/orders/OrderService.php:303-309`, `wtg  live/body/shopping-engine/api/orders/OrderService.php:965-1030`, `wtg  live/lib/PaymentService.php:287-340`.
- Confidence: High.

### D2 — Local logs/files/cache/media create backup ambiguity

- WHERE: logs directory, reflex cache, uploads/stories, cron lock.
- WHY: Local filesystem used for operational artifacts.
- ROOT CAUSE: No traced centralized storage.
- CONNECTED SYSTEMS: observability, media, cache, cron.
- SECURITY IMPACT: forensic evidence may be node-local.
- PERFORMANCE IMPACT: disk failure affects logging/uploads/cache.
- SCALE IMPACT: multi-node restore inconsistency.
- FUTURE RISK: Losing server filesystem loses media/log evidence unless backed up externally.
- Evidence: `wtg  live/core/helpers/Logger.php:27-50`, `wtg  live/body/content-engine/api/StoryController.php:24-64`, `wtg  live/heart/pipeline/ReflexEngine.php:261-350`.
- Confidence: High.

### D3 — Payment reconciliation relies on transaction/order state, not full webhook history

- WHERE: Payment webhook updates order and creates/checks transaction; no event ledger traced.
- WHY: Simpler idempotency model.
- ROOT CAUSE: Outcome-level persistence only.
- CONNECTED SYSTEMS: Cashfree, orders, transactions, refunds/admin payments.
- SECURITY IMPACT: Harder to audit every provider event.
- PERFORMANCE IMPACT: duplicate webhooks still hit DB.
- SCALE IMPACT: reconciliation grows manual as payment volume grows.
- FUTURE RISK: Provider disputes/refunds require external portal/log correlation.
- Evidence: `wtg  live/lib/PaymentService.php:169-285`, `wtg  live/api/payment/webhook.php:59-98`.
- Confidence: High.

## Areas Needing Future Inspection

- Database backup/PITR policy.
- Log retention and alerting pipeline.
- Upload backup/restore and malware scanning.
- Cron scheduler topology.
- Cashfree reconciliation reports vs local transactions.
- SwiftDeliver event replay/reconciliation capacity.
- Admin incident runbooks and audit review workflows.

