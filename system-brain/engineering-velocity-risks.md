# Phase 11 — Engineering Velocity Risks

## Velocity Collapse Drivers

1. String-based intent drift across frontend/Heart/engines.
2. Shared DB tables used as cross-module APIs.
3. Monolithic admin and large checkout/payment services.
4. Multiple response and validation conventions.
5. Duplicated configuration/deployment parsing.
6. Weak observable recovery for post-commit side effects.

## Findings

### V1 — Feature work requires editing multiple untyped routing layers

- WHERE: Customer/vendor API clients, AppRouter, engine entrypoints, direct REST routers.
- WHY: Requests are routed by path or string intent depending on surface.
- ROOT CAUSE: No single source of truth for API route/intents.
- CONNECTED SYSTEMS: frontend panels, Heart, Body engines.
- OPERATIONAL IMPACT: Releases can ship broken pathways unnoticed.
- MAINTAINABILITY IMPACT: Engineers spend time tracing route stacks instead of features.
- SCALE IMPACT: More features increase combinatorial contract drift.
- FUTURE RISK: Velocity slows sharply as feature count grows.
- Evidence: `wtg  live/app/js/api.js:87-139`, `wtg  live/vendor/shared/api.js:51-99`, `wtg  live/heart/router.php:195-247`, `wtg  live/body/shopping-engine/index.php:20-65`, `wtg  live/body/service-engine/index.php:29-72`.
- Confidence: High.

### V2 — Debugging production issues requires cross-domain knowledge

- WHERE: Order/payment/delivery/refund/ledger flows cross modules and external services.
- WHY: Business workflows are not bounded by one module.
- ROOT CAUSE: Side effects and state transitions are distributed.
- CONNECTED SYSTEMS: orders, deliveries, transactions, wallet ledgers, Cashfree, SwiftDeliver, admin.
- OPERATIONAL IMPACT: Incidents require senior/full-system knowledge.
- MAINTAINABILITY IMPACT: New developers face high onboarding cost.
- SCALE IMPACT: Support burden grows with transaction volume.
- FUTURE RISK: Human operational dependency becomes bottleneck.
- Evidence: `wtg  live/body/shopping-engine/api/orders/OrderService.php:965-1030`, `wtg  live/lib/PaymentService.php:287-340`, `wtg  live/heart/api/delivery/index.php:115-173`, `wtg  live/core/controllers/RefundController.php:176-307`, `wtg  live/core/helpers/LedgerEngine.php:183-267`.
- Confidence: High.

### V3 — Schema changes are high-friction

- WHERE: The same tables are directly referenced by many modules; migrations are raw SQL files executed by filename order.
- WHY: DB schema is the shared contract.
- ROOT CAUSE: No data access boundary or schema contract enforcement layer.
- CONNECTED SYSTEMS: admin, shopping, service, delivery, payment, refund, cron.
- OPERATIONAL IMPACT: Schema mistakes can break many runtime paths.
- MAINTAINABILITY IMPACT: Developers must globally search before changing columns.
- SCALE IMPACT: Large tables make schema migration operationally risky.
- FUTURE RISK: Schema evolution will slow or freeze.
- Evidence: `wtg  live/migrate.php:44-102`, `wtg  live/body/shopping-engine/api/orders/OrderService.php:168-287`, `wtg  live/heart/api/admin/index.php:17-203`, `wtg  live/core/helpers/LedgerEngine.php:39-267`.
- Confidence: High.

## Velocity Risk Summary

| Driver | Immediate Effect | Long-Term Effect |
|---|---|---|
| API drift | bug triage time | release slowdown |
| Monolith files | review friction | merge conflicts |
| Shared DB API | cautious schema changes | migration paralysis |
| Fragmented payments | reconciliation effort | finance rewrite |
| Local infra assumptions | deployment tribal knowledge | ops bottleneck |

