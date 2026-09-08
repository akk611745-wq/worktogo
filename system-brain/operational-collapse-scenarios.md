# Phase 11 — Operational Collapse Scenarios

## Scenario 1 — Vendor workflow collapse from intent drift

- Trigger: Vendor panel calls unsupported intent names.
- WHERE: Vendor client sends `vendor:update_order_status`, `vendor:get_job`, `vendor:update_job_status`; engines support different names.
- ROOT CAUSE: Contract drift between frontend and Heart/engine switches.
- CONNECTED SYSTEMS: vendor dashboard, orders, jobs, Heart pipeline, shopping/service engines.
- OPERATIONAL IMPACT: Vendors cannot reliably manage orders/jobs.
- MAINTAINABILITY IMPACT: Debug requires tracing JS intent, AppRouter, engine switch, controller.
- SCALE IMPACT: More vendors create support load.
- FUTURE RISK: Vendor operations become rewrite pressure.
- Evidence: `wtg  live/vendor/shared/api.js:82-97`, `wtg  live/heart/router.php:218-224`, `wtg  live/body/shopping-engine/index.php:46-57`, `wtg  live/body/service-engine/index.php:52-64`.
- Confidence: High.

## Scenario 2 — Payment/refund incident overwhelms engineering

- Trigger: Cashfree refund/payment mismatch or webhook processing failure.
- WHERE: Payment webhook, PaymentService, Payment helper, RefundController, LedgerEngine all participate.
- ROOT CAUSE: Fragmented finance logic and partial event ledger.
- CONNECTED SYSTEMS: Cashfree, transactions, orders, refunds, ledgers, admin.
- OPERATIONAL IMPACT: Manual reconciliation and customer support spike.
- MAINTAINABILITY IMPACT: Multiple files must be understood before safe change.
- SCALE IMPACT: Volume increases unresolved mismatch backlog.
- FUTURE RISK: Finance rewrite needed for auditability.
- Evidence: `wtg  live/api/payment/webhook.php:88-98`, `wtg  live/lib/PaymentService.php:169-357`, `wtg  live/core/helpers/Payment.php:157-260`, `wtg  live/core/controllers/RefundController.php:176-307`, `wtg  live/core/helpers/LedgerEngine.php:183-267`.
- Confidence: High.

## Scenario 3 — Admin operational overload during production spike

- Trigger: Orders/payments/deliveries spike while admin dashboard is used for incident management.
- WHERE: Admin dashboard computes live aggregates and lists from production tables.
- ROOT CAUSE: No separate operational read model.
- CONNECTED SYSTEMS: orders, payments, deliveries, logs, wallets.
- OPERATIONAL IMPACT: Admin visibility slows or fails.
- MAINTAINABILITY IMPACT: Engineers patch emergency SQL in monolithic admin file.
- SCALE IMPACT: Admin reads compete with customer/vendor traffic.
- FUTURE RISK: Incident response depends on fragile admin runtime.
- Evidence: `wtg  live/heart/api/admin/index.php:17-203`, `wtg  live/heart/api/admin/index.php:698-801`.
- Confidence: High.

## Scenario 4 — Deployment/schema drift incident

- Trigger: Migration applied through web hook or manually in wrong order/environment.
- WHERE: `migrate.php` scans SQL and inserts into `migrations_log` after execution.
- ROOT CAUSE: Raw SQL migration execution with environment-secret access and no traced dry-run/rollback.
- CONNECTED SYSTEMS: DB schema, all modules, deployment.
- OPERATIONAL IMPACT: Runtime errors across modules using changed tables.
- MAINTAINABILITY IMPACT: Manual DB recovery requires full schema knowledge.
- SCALE IMPACT: Larger data volumes make rollback harder.
- FUTURE RISK: Deployment confidence degrades.
- Evidence: `wtg  live/migrate.php:44-102`, `wtg  live/migrate.php:104-114`.
- Confidence: High.

