# Phase 11 — Technical Debt Map

Scope: documentation-only production stability and technical debt intelligence for the active WorkToGo project under `wtg  live/`. No fixes, refactors, rewrites, optimizations, API changes, runtime changes, or deployment changes were performed.

## Executive Technical Debt Reality

WorkToGo is functional but carries layered debt from multiple runtime generations: direct REST dispatch, Heart intent pipeline, Body engines, Brain subsystem, admin direct APIs, vendor/customer SPAs, payment helpers, cron workflows, and external SwiftDeliver coupling. The dominant debt is not one broken file; it is duplicated concepts implemented in different places with different contracts, response shapes, naming, auth patterns, and operational assumptions.

## Technical Debt Heat Map

| Zone | Stability | Debt Type | Rewrite Pressure | Evidence |
|---|---:|---|---:|---|
| Auth/JWT middleware | Medium-high | DB dependency + stale role claim model | Medium | `wtg  live/heart/middleware/AuthMiddleware.php:57-97` |
| Heart dispatcher + pipeline split | Medium | dual routing models and intent drift | High | `wtg  live/heart/index.php:149-379`, `wtg  live/heart/router.php:195-247` |
| Shopping orders | Medium | large transaction service + side effects | Medium-high | `wtg  live/body/shopping-engine/api/orders/OrderService.php:67-330` |
| Service bookings | Medium-low | duplicate booking paths and slot model drift | High | `wtg  live/body/service-engine/api/BookingController.php:9-78`, `wtg  live/body/service-engine/api/services/index.php:204-324` |
| Vendor frontend/API contract | Low-medium | intent names do not fully match engine/router | High | `wtg  live/vendor/shared/api.js:51-99`, `wtg  live/body/service-engine/index.php:29-72` |
| Admin API | Medium | monolithic operational controller | High | `wtg  live/heart/api/admin/index.php:1-901` |
| Payment/refund/ledger | Medium-low | multiple payment helpers and financial coupling | High | `wtg  live/lib/PaymentService.php:39-120`, `wtg  live/core/helpers/Payment.php:11-67`, `wtg  live/core/controllers/RefundController.php:176-307` |
| Brain subsystem | Medium-low | parallel router/auth/CORS model | Medium-high | `wtg  live/brain/index.php:24-71`, `wtg  live/brain/routes.php:22-55` |
| Infrastructure/config | Medium-low | env/config sprawl + local filesystem assumptions | High | `wtg  live/heart/bootstrap.php:25-81`, `wtg  live/config/engines.php:6-11`, `wtg  live/.htaccess:19-80` |

## Major Debt Findings

### 1. Dual runtime architecture creates contract drift

- WHERE: `heart/index.php` dispatches direct REST routes before falling back to `heart/router.php`; frontend customer/vendor APIs call the Heart intent pipeline for many business operations, while admin uses direct REST.
- WHY: Direct REST modules and intent pipeline coexist.
- ROOT CAUSE: Incremental architecture evolution without one canonical API contract.
- CONNECTED SYSTEMS: customer SPA, vendor SPA, admin, Heart, Body engines, Brain.
- OPERATIONAL IMPACT: Runtime behavior differs by route path; support must know which route model is active.
- MAINTAINABILITY IMPACT: Developers must update multiple route registries and engine switches for one feature.
- SCALE IMPACT: Direct REST bypasses Heart cache/rate/Brain orchestration; pipeline routes add synchronous fanout.
- FUTURE RISK: Feature additions will increasingly break one surface while working on another.
- Evidence: `wtg  live/heart/index.php:149-379`, `wtg  live/heart/router.php:195-247`, `wtg  live/app/js/api.js:77-139`, `wtg  live/vendor/shared/api.js:48-99`.
- Runtime dependency: PHP dispatcher order, frontend URL construction, `ENGINE_URLS`.
- Confidence: High.

### 2. Configuration loading is duplicated and fragmented

- WHERE: `heart/bootstrap.php` parses `.env`; `heart/index.php` also parses `.env`; `deploy-hook.php` and `migrate.php` parse `.env` independently; config files read both `getenv()` and `$_ENV`.
- WHY: Different entrypoints need secrets without Composer/env library.
- ROOT CAUSE: No single environment/config service.
- CONNECTED SYSTEMS: auth, DB, migrations, deployment hook, payment, Brain, engines.
- OPERATIONAL IMPACT: Env parsing differences can create production-only drift.
- MAINTAINABILITY IMPACT: Any config behavior change must be replicated across entrypoints.
- SCALE IMPACT: Multi-node deployments risk inconsistent env handling.
- FUTURE RISK: Deployment debugging slows as configuration sources multiply.
- Evidence: `wtg  live/heart/bootstrap.php:25-81`, `wtg  live/heart/index.php:62-84`, `wtg  live/deploy-hook.php:4-32`, `wtg  live/migrate.php:4-35`, `wtg  live/config/database.php:3-11`.
- Runtime dependency: `.env`, host env, PHP `getenv()`.
- Confidence: High.

### 3. Naming inconsistency increases maintenance risk

- WHERE: Vendor frontend uses `vendor:update_order_status`, `vendor:get_profile`, `vendor:update_job_status`; AppRouter defines `vendor_update_order` as `shopping:vendor_update_order` equivalent and service engine accepts `service:vendor_update_booking`, not those vendor names.
- WHY: Frontend intent vocabulary and backend engine intent vocabulary evolved separately.
- ROOT CAUSE: No generated/open API contract or shared constant registry.
- CONNECTED SYSTEMS: vendor dashboard, shopping engine, service engine, Heart router.
- OPERATIONAL IMPACT: Vendor features can silently return generic engine fallback responses.
- MAINTAINABILITY IMPACT: Engineers must manually correlate names across JS, Heart routes, engine switches, direct REST.
- SCALE IMPACT: More vendor workflows multiply drift risk.
- FUTURE RISK: Development velocity collapses around debugging “API works here but not there” issues.
- Evidence: `wtg  live/vendor/shared/api.js:56-97`, `wtg  live/heart/router.php:218-224`, `wtg  live/body/shopping-engine/index.php:20-65`, `wtg  live/body/service-engine/index.php:29-72`.
- Runtime dependency: intent string exact matches.
- Confidence: High.

### 4. Financial/payment code exists in multiple partially overlapping layers

- WHERE: Product payment uses `lib/PaymentService.php`; service online payment uses `core/helpers/Payment.php`; refunds use `RefundController` and `LedgerEngine`, which can also invoke `Payment::refundOrder()`.
- WHY: Payment support was added across product/service/refund contexts over time.
- ROOT CAUSE: No single payment domain boundary or settlement orchestration layer.
- CONNECTED SYSTEMS: orders, bookings, transactions, refunds, ledgers, Cashfree, SwiftDeliver.
- OPERATIONAL IMPACT: Payment failures and refund flows require tracing across several helpers.
- MAINTAINABILITY IMPACT: Fixing Cashfree behavior requires knowing which helper path is used.
- SCALE IMPACT: Financial reconciliation complexity grows with volume.
- FUTURE RISK: This is a rewrite-pressure zone for finance domain consolidation.
- Evidence: `wtg  live/lib/PaymentService.php:39-120`, `wtg  live/core/helpers/Payment.php:11-67`, `wtg  live/body/service-engine/api/services/index.php:282-288`, `wtg  live/core/controllers/RefundController.php:176-307`, `wtg  live/core/helpers/LedgerEngine.php:183-267`.
- Runtime dependency: Cashfree env, transactions table, orders/bookings payment fields.
- Confidence: High.

### 5. Error and response models differ across subsystems

- WHERE: `Response` returns `success/message/data/error`; shopping helpers return `success/data/error`; Brain router has its own `Response::error` assumption; webhook endpoints echo raw `status`/`message` JSON; admin may throw JS errors from API client.
- WHY: Multiple subsystems developed with local response helpers.
- ROOT CAUSE: No single response envelope contract across all APIs.
- CONNECTED SYSTEMS: customer SPA, vendor SPA, admin SPA, webhook consumers, Heart pipeline.
- OPERATIONAL IMPACT: Frontends must handle multiple response shapes.
- MAINTAINABILITY IMPACT: New page/API work requires defensive parsing and special cases.
- SCALE IMPACT: More clients amplify compatibility burden.
- FUTURE RISK: Breaking changes become hard to detect without contract tests.
- Evidence: `wtg  live/core/helpers/Response.php:11-45`, `wtg  live/body/shopping-engine/helpers/shopping.helpers.php:7-41`, `wtg  live/api/payment/webhook.php:21-98`, `wtg  live/admin/shared/api.js:71-84`.
- Runtime dependency: frontend error interpretation.
- Confidence: High.

## Stable Areas

- JWT signing/verification helper is compact and deterministic, though broader auth lifecycle has drift risk. Evidence: `wtg  live/core/helpers/JWT.php:10-67`.
- Product listing/detail service is relatively cohesive and separated from controller. Evidence: `wtg  live/body/shopping-engine/api/products/ProductService.php:25-299`.
- Shopping cart service is encapsulated and handles core cart constraints in one class. Evidence: `wtg  live/body/shopping-engine/api/cart/CartService.php:18-335`.
- Reflex cache abstraction has clear driver interface, though file fallback has scale debt. Evidence: `wtg  live/heart/pipeline/ReflexEngine.php:162-168`.

## Uncertainty

- Schema constraints and indexes were not fully revalidated in Phase 11.
- Some legacy auth/OTP and vendor page scripts were not fully reread in this pass.
- Actual production module usage may differ from source if rewrites or hosting rules route around files.

