# Phase 10 — Security + Performance + Scalability Intelligence Synthesis

## Final Intelligence Summary

WorkToGo is a synchronous PHP/MySQL platform with fragmented but traceable security boundaries, DB-centered consistency, local filesystem assumptions, polling-based synchronization, and external payment/delivery integrations. The most important reality is that security, performance, and scalability risks share the same root: many critical boundaries converge on one database and one synchronous request lifecycle.

## Where WorkToGo Is Vulnerable

1. Broad admin token blast radius through global admin bypass and monolithic admin API.
2. Stale JWT role/status claims because protected requests validate token/blacklist but do not re-read current user role/status.
3. Inconsistent auth enforcement across legacy/current controllers, especially routes that rely on body user IDs or role-agnostic auth.
4. Delivery webhook replay/out-of-order ambiguity due to shared-secret-only callback with no traced event ledger.
5. Upload/media uncertainty from extension/size validation and local public storage.

## What Fails First at Scale

1. MySQL: auth blacklist reads, polling, admin aggregates, checkout locks, payment webhooks, and cron all converge on DB.
2. PHP workers: synchronous Brain/engine/payment/delivery cURL and long DB transactions hold request workers.
3. Polling: alert/customer/vendor/admin polling creates linear read amplification.
4. Local filesystem: logs/cache/uploads/cron locks do not scale horizontally.
5. Hot rows: inventory rows, booking slots, order/payment rows, and assignment rows contend under bursts.

## Where Operational Overload Occurs

- Admin dashboard live aggregates during incidents.
- Order checkout and inventory reservation during demand spikes.
- Alert polling with many active tabs.
- Auto assignment cron when many jobs/orders are pending.
- Payment and delivery callbacks during external retry storms.

## Where Hidden Infrastructure Assumptions Exist

- APCu availability for rate limiting/circuit breaking.
- Redis optionality for Reflex cache; file fallback otherwise.
- Local filesystem for cache/logs/uploads/cron lock.
- Correct `APP_URL`, proxy, `TRUST_PROXY`, and origin behavior.
- Single cron runner / shared filesystem assumption.
- External SwiftDeliver and Cashfree availability.

## Top Cross-Domain Risk Register

| Risk | Security | Performance | Scale | Confidence |
|---|---|---|---|---|
| DB-centered auth blacklist | fail-secure outage, DB dependency | per-request query | DB bottleneck | High |
| Admin superuser model | high token blast radius | expensive admin reads | global operational bottleneck | High |
| Polling realtime | token exposure/read amplification | periodic requests | linear growth | High |
| Checkout transaction | abuse write amplification | lock contention | hot product collapse | High |
| Payment webhook partial ledger | reconciliation gaps | duplicate DB work | payment retry storms | High |
| Delivery webhook direct mutation | replay/secret risk | repeated updates | out-of-order state collapse | High |
| Local filesystem substrate | node-local evidence/media | disk I/O | horizontal scaling boundary | High |

## Mandatory Uncertainty Report

- Partially traced security boundaries: product/vendor controllers not exhaustively re-read; legacy auth files not fully revalidated.
- Uncertain scaling assumptions: DB indexes, PHP worker counts, APCu/Redis availability, MySQL isolation settings.
- Hidden infrastructure dependencies: upload serving rules, CDN/static caching, backup/PITR, centralized logs.
- Runtime ambiguity: Heart direct REST vs intent pipeline split means not every route receives same cache/rate/Brain behavior.
- Deployment uncertainty: `APP_URL`, proxy headers, `TRUST_PROXY`, cron topology, SwiftDeliver deployment topology.
- Recovery uncertainty: no traced disaster runbook, outbox, dead-letter queue, or replayable event stream.

## Evidence Index

- Auth/JWT: `wtg  live/heart/middleware/AuthMiddleware.php:57-97`, `wtg  live/core/helpers/JWT.php:30-67`.
- REST dispatcher: `wtg  live/heart/index.php:149-379`.
- Heart pipeline: `wtg  live/heart/router.php:117-177`.
- Rate limiting: `wtg  live/heart/router.php:281-343`.
- Brain circuit: `wtg  live/heart/pipeline/BrainConnector.php:117-155`.
- Engine fanout: `wtg  live/heart/pipeline/EngineLoader.php:42-77`, `wtg  live/heart/pipeline/EngineLoader.php:164-215`.
- Admin API: `wtg  live/heart/api/admin/index.php:12-13`, `wtg  live/heart/api/admin/index.php:17-203`, `wtg  live/heart/api/admin/index.php:441-892`.
- Checkout/order: `wtg  live/body/shopping-engine/api/orders/OrderService.php:67-330`.
- Payment webhook: `wtg  live/api/payment/webhook.php:43-98`, `wtg  live/lib/PaymentService.php:169-357`.
- Delivery webhook: `wtg  live/heart/api/delivery/index.php:115-173`.
- Alerts polling: `wtg  live/assets/js/alerts.js:57-64`, `wtg  live/api/alerts/fetch.php:19-58`.
- Cron: `wtg  live/cron/auto_assign.php:11-182`.
- Uploads: `wtg  live/body/content-engine/api/StoryController.php:10-79`.

