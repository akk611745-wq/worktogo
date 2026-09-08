# Phase 10 — Operational Failure Scenarios

## Scenario 1 — Database degradation breaks auth and operations first

- Trigger: MySQL latency or outage.
- WHERE: Auth blacklist query, order/cart/service/admin/alerts all depend on DB.
- Runtime dependency: `getDB()` / PDO singleton.
- Operational impact: Protected APIs return 503/500; admin dashboard cannot observe; webhooks may fail or be acknowledged depending path.
- Security implications: Auth fails secure for blacklist failure, but public unauthenticated endpoints may still respond until DB use.
- Performance implications: PHP workers wait on DB connection/query latency.
- Scale implications: Every active poller and protected request increases pressure.
- Evidence: `wtg  live/core/helpers/Database.php:10-40`, `wtg  live/heart/middleware/AuthMiddleware.php:77-95`, `wtg  live/api/alerts/fetch.php:55-70`.
- Confidence: High.

## Scenario 2 — SwiftDeliver unavailable after successful order/payment

- Trigger: `SWIFTDELIVER_URL` down, slow, placeholder, or returns non-2xx.
- WHERE: Order creation `dispatchToDelivery()` and payment webhook dispatch.
- Runtime dependency: `SWIFTDELIVER_URL`, `SWIFTDELIVER_SECRET`, external Node/Railway service.
- Operational impact: Order remains committed/paid but not dispatched; logs contain failure.
- Security implications: Manual intervention may bypass normal flow.
- Performance implications: cURL timeout consumes request worker.
- Scale implications: Backlog is not queue-managed in traced PHP code.
- Evidence: `wtg  live/body/shopping-engine/api/orders/OrderService.php:965-1030`, `wtg  live/lib/PaymentService.php:287-340`.
- Confidence: High.

## Scenario 3 — Admin dashboard fails during incident

- Trigger: Large orders/payments/logs tables plus incident traffic.
- WHERE: Admin dashboard live aggregates and list endpoints.
- Runtime dependency: MySQL query performance.
- Operational impact: Operators lose visibility into stuck orders, refunds, failed payments, driver state.
- Security implications: Blind manual changes increase error risk.
- Performance implications: Repeated refreshes worsen DB load.
- Scale implications: Operational tooling shares DB with production traffic.
- Evidence: `wtg  live/heart/api/admin/index.php:17-203`, `wtg  live/heart/api/admin/index.php:577-604`.
- Confidence: High.

## Scenario 4 — Cron assignment runs on multiple nodes

- Trigger: Horizontal scaling with multiple cron executors and local filesystem locks.
- WHERE: `cron/auto_assign.php` uses local file lock then DB transaction.
- Runtime dependency: local file path `cron/auto_assign.lock`, MySQL row locks.
- Operational impact: Duplicate assignment attempts or inconsistent vendor alerts if file lock is not shared.
- Security implications: Vendors may receive conflicting assignments.
- Performance implications: Cron holds transaction while scanning/updating assignments.
- Scale implications: Local lock is not distributed.
- Evidence: `wtg  live/cron/auto_assign.php:11-15`, `wtg  live/cron/auto_assign.php:32-182`.
- Confidence: High.

## Scenario 5 — Payment webhook processing failure silently needs manual review

- Trigger: Cashfree sends valid event but internal processing returns failure after verification.
- WHERE: webhook returns HTTP 200 for processing failure to avoid retry loops.
- Runtime dependency: logs and manual investigation.
- Operational impact: Payment/order mismatch may persist.
- Security implications: Fraud/reconciliation gaps if logs are not monitored.
- Performance implications: Provider does not retry, reducing load but increasing manual burden.
- Scale implications: More payments mean more potential silent reconciliation backlog.
- Evidence: `wtg  live/api/payment/webhook.php:88-94`.
- Confidence: High.

## Scenario 6 — Upload/media storage fills local disk

- Trigger: High vendor story uploads.
- WHERE: `uploads/stories` local directory receives files up to 5 MB images / 10 MB video.
- Runtime dependency: local filesystem capacity and write permissions.
- Operational impact: Upload failures, cache/log write failures, possible app instability.
- Security implications: Lack of traced scanning/MIME validation raises content risk.
- Performance implications: Disk I/O contention.
- Scale implications: Not horizontally shared.
- Evidence: `wtg  live/body/content-engine/api/StoryController.php:24-64`.
- Confidence: Medium-high.

