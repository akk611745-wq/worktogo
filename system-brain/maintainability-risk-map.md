# Phase 11 — Maintainability Risk Map

## Maintainability Risk Matrix

| Risk | Where | Maintainability Impact | Future Risk | Confidence |
|---|---|---|---|---|
| Monolithic admin API | `heart/api/admin/index.php` | hard to review/test/change | admin rewrite pressure | High |
| Mixed response envelopes | `Response`, `se_response`, raw webhooks | client special cases | contract drift | High |
| Duplicate env parsing | bootstrap/index/migrate/deploy | config bugs | deployment slowdown | High |
| Multiple payment helpers | `PaymentService`, `Payment`, `LedgerEngine` | financial tracing complexity | finance rewrite | High |
| Intent drift | JS/Heart/engine switch | feature breakage | velocity collapse | High |
| Superglobal mutation | Engine internal dispatch | hidden side effects | testing difficulty | High |
| Shared DB table ownership | orders/bookings/transactions | schema change risk | migration paralysis | High |

## Maintainability Findings

### 1. Response contract drift slows frontend/backend development

- WHERE: Core `Response`, shopping `se_response`, webhooks, admin API client, and Brain router do not share one envelope.
- WHY: Subsystems have local response helpers.
- ROOT CAUSE: No global API contract standard.
- CONNECTED SYSTEMS: all frontends and API consumers.
- OPERATIONAL IMPACT: UI error handling can behave differently by endpoint.
- MAINTAINABILITY IMPACT: Developers must inspect each endpoint's envelope.
- SCALE IMPACT: More clients and integrations increase compatibility burden.
- FUTURE RISK: Contract tests or API gateway become necessary.
- Evidence: `wtg  live/core/helpers/Response.php:11-45`, `wtg  live/body/shopping-engine/helpers/shopping.helpers.php:7-41`, `wtg  live/api/payment/webhook.php:21-98`, `wtg  live/admin/shared/api.js:71-84`.
- Runtime dependency: frontend parsing logic.
- Confidence: High.

### 2. Validation is inconsistent and partly local to controllers

- WHERE: Some admin/refund routes use `Validator`; product/order/service controllers manually validate; booking controller directly checks fields; delivery routes inline validate.
- WHY: Validator exists but is not universally adopted.
- ROOT CAUSE: Mixed code generations and feature-specific validation.
- CONNECTED SYSTEMS: admin, refund, shopping, service, delivery.
- OPERATIONAL IMPACT: Different validation behavior per route.
- MAINTAINABILITY IMPACT: Hard to enforce platform-wide validation changes.
- SCALE IMPACT: More endpoints increase inconsistent edge cases.
- FUTURE RISK: Bugs recur because validation patterns are copied manually.
- Evidence: `wtg  live/core/helpers/Validator.php:8-143`, `wtg  live/core/controllers/RefundController.php:26-34`, `wtg  live/body/shopping-engine/api/products/ProductController.php:58-95`, `wtg  live/body/service-engine/api/BookingController.php:12-19`, `wtg  live/heart/api/delivery/index.php:129-139`.
- Runtime dependency: per-controller code paths.
- Confidence: High.

### 3. Frontend API clients encode different route assumptions

- WHERE: Customer client posts intents to `CONFIG.BASE_URL`; vendor client posts intents; admin client builds direct admin URLs with prefix normalization.
- WHY: Separate panels evolved independently.
- ROOT CAUSE: No shared frontend API SDK.
- CONNECTED SYSTEMS: customer SPA, vendor SPA, admin panel.
- OPERATIONAL IMPACT: A backend route change can fix one panel and break another.
- MAINTAINABILITY IMPACT: Duplicate auth/error/timeout behavior.
- SCALE IMPACT: More panels multiply maintenance cost.
- FUTURE RISK: Client drift will slow release velocity.
- Evidence: `wtg  live/app/js/api.js:28-83`, `wtg  live/vendor/shared/api.js:7-33`, `wtg  live/admin/shared/api.js:6-94`.
- Runtime dependency: `CONFIG` objects and token keys.
- Confidence: High.

## Human Dependency Risks

- Developers must know which routes are direct REST, which are Heart intents, and which engine switches support them.
- Operators must inspect logs/manual admin screens for payment/dispatch failures because durable recovery queues are not traced.
- Financial reconciliation requires knowledge of `orders`, `transactions`, `wallet_transactions`, `PaymentService`, `Payment`, `RefundController`, and `LedgerEngine` together.
- Deployment requires knowing `.htaccess`, migration secrets, env parsing, route exposure, and host PHP modules.

## Maintainability Ambiguity

- Unknown test coverage makes risk level of future changes uncertain.
- Exact active frontend pages and deployment routing can alter which debt is most urgent.
- Database migrations history suggests active schema patching, but full schema drift was not revalidated here.

