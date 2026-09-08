# Phase 10 — Infrastructure Assumption Map

## Traced Infrastructure Assumptions

| Assumption | Where Traced | Risk |
|---|---|---|
| `.env` exists or host injects env | `heart/bootstrap.php` loads root `.env` | missing secrets fail prod JWT or payment |
| MySQL is primary shared state | `Database::getInstance()` | single DB bottleneck/SPOF |
| APCu may exist | Heart rate limiter/Brain circuit | controls degrade if absent |
| Redis optional | Reflex cache driver | file/APCu fallback not cluster-shared |
| Local filesystem available | logs/cache/uploads/cron lock | horizontal scaling and disaster recovery risk |
| Cashfree reachable | payment service/webhook | payment lifecycle external dependency |
| SwiftDeliver reachable | order/payment dispatch + delivery webhook | fulfillment dependency |
| `APP_URL` accurately matches origin | CORS headers | CORS/cookie behavior uncertainty |
| Proxy trust configured correctly | `TRUST_PROXY` in rate limiter | IP limiting correctness |

## Hidden Production Assumptions

### I1 — Environment secrets are mandatory but fragmented

- WHERE: JWT, Cashfree, SwiftDeliver, Brain, app URL, database config all depend on environment variables.
- WHY: Deployment separates secrets from code.
- ROOT CAUSE: Multiple config files/helpers read env directly.
- CONNECTED SYSTEMS: auth, payment, delivery, Brain, DB, CORS.
- SECURITY IMPACT: Misconfigured secrets can disable payments, auth, or allow webhook failures.
- PERFORMANCE IMPACT: Placeholder/missing external URLs skip or fail dispatch/payment.
- SCALE IMPACT: Environment drift across nodes causes inconsistent behavior.
- FUTURE RISK: Multi-environment deployment needs strict config validation.
- Evidence: `wtg  live/heart/bootstrap.php:25-81`, `wtg  live/config/app.php:6-35`, `wtg  live/lib/PaymentService.php:41-50`, `wtg  live/body/shopping-engine/api/orders/OrderService.php:965-975`.
- Confidence: High.

### I2 — Local filesystem is used as operational substrate

- WHERE: logs, Reflex file cache, story uploads, cron lock.
- WHY: Shared-hosting-compatible deployment assumptions.
- ROOT CAUSE: No traced central log/cache/object-storage/distributed-lock layer.
- CONNECTED SYSTEMS: observability, cache, content, assignment cron.
- SECURITY IMPACT: Local files may contain operational data/media and require server permissions.
- PERFORMANCE IMPACT: Disk I/O bottlenecks.
- SCALE IMPACT: Horizontal scaling breaks shared state assumptions.
- FUTURE RISK: disaster recovery incomplete if filesystem not backed up/synchronized.
- Evidence: `wtg  live/core/helpers/Logger.php:27-50`, `wtg  live/heart/pipeline/ReflexEngine.php:113-117`, `wtg  live/body/content-engine/api/StoryController.php:24-64`, `wtg  live/cron/auto_assign.php:11-15`.
- Confidence: High.

### I3 — Deployment/proxy assumptions affect security and abuse controls

- WHERE: CORS exact origin uses `APP_URL`; rate limiter only trusts `X-Forwarded-For` if `TRUST_PROXY`; logger always considers several proxy IP headers.
- WHY: Hosting/proxy layer may vary.
- ROOT CAUSE: Mixed direct server and proxy-aware logic.
- CONNECTED SYSTEMS: Heart pipeline, REST CORS, logging, abuse controls.
- SECURITY IMPACT: Incorrect origin/proxy config can break legitimate clients or weaken IP attribution.
- PERFORMANCE IMPACT: Misattributed IPs can rate-limit wrong clients or fail to limit abuse.
- SCALE IMPACT: Load balancer/CDN introduction requires consistent proxy config.
- FUTURE RISK: Multi-node/CDN rollout without config audit creates hidden behavior changes.
- Evidence: `wtg  live/heart/index.php:129-147`, `wtg  live/heart/router.php:42-56`, `wtg  live/heart/router.php:335-343`, `wtg  live/core/helpers/Logger.php:60-70`.
- Confidence: High.

## Infrastructure Uncertainty Register

- Actual production PHP extensions: APCu, Redis, curl, fileinfo, mbstring, opcache.
- Actual web server upload/static execution restrictions.
- Actual DB backups, read replicas, connection limits, index health.
- Actual cron scheduling host and whether only one node runs cron.
- Actual CDN/object storage usage for uploaded media.
- Actual centralized log retention/alerting for payment and dispatch failures.

