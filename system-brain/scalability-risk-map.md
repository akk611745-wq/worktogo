# Phase 10 — Scalability Risk Map

Scope: trace-based scale boundaries and growth failure points. No optimization or infrastructure changes performed.

## Primary Scale Boundaries

| Boundary | Evidence | Growth Failure | Confidence |
|---|---|---|---|
| MySQL as shared authority | Most auth/business/polling routes use DB directly | read/write saturation, lock waits | High |
| PHP synchronous workers | Heart/REST/webhooks run blocking code | worker exhaustion under slow IO | High |
| APCu/local memory controls | rate limiter/circuit breaker use APCu | per-node/no-op controls | High |
| Local filesystem | logs, file cache, uploads, cron lock | non-shared state in horizontal scaling | High |
| Polling realtime | orders/bookings/vendor/alerts polling | linear DB read amplification | High |
| External delivery/payment | cURL to SwiftDeliver/Cashfree | downstream latency and retry storms | High |

## Multi-City Scaling Limits

- WHERE: Vendor assignment uses nearest online vendor from `vendors.lat/lng`; city/zone partitioning was not traced. Admin lists and feeds appear global by default.
- WHY: Current code queries globally unless filters are provided.
- ROOT CAUSE: No traced tenant/city sharding or partition boundary.
- CONNECTED SYSTEMS: vendors, orders, jobs, auto_assignments, feed stories, admin views.
- SECURITY IMPACT: Incorrect scoping could expose cross-city operational data if future city admin roles are added without query boundaries.
- PERFORMANCE IMPACT: Global distance/order queries grow with all cities.
- SCALE IMPACT: One city spike affects global DB tables.
- FUTURE RISK: Multi-city launch requires operational partitioning decisions.
- Evidence: `wtg  live/cron/auto_assign.php:134-149`, `wtg  live/heart/api/admin/index.php:235-300`, `wtg  live/body/content-engine/api/StoryController.php:121-138`.
- Confidence: High.

## Horizontal Scaling Limitations

### Local file lock cron

- WHERE: `cron/auto_assign.php` uses `cron/auto_assign.lock` with `flock()`.
- WHY: Prevent concurrent cron execution on one filesystem.
- ROOT CAUSE: Local file lock, not DB/distributed lock.
- CONNECTED SYSTEMS: jobs, orders, auto_assignments, alerts.
- SECURITY IMPACT: Duplicate cron runners on different nodes can race if filesystem is not shared.
- PERFORMANCE IMPACT: Large assignment cycle holds DB transaction while processing many entities.
- SCALE IMPACT: Horizontal PHP/cron nodes can duplicate assignment work.
- FUTURE RISK: Multi-node cron requires distributed locking semantics.
- Evidence: `wtg  live/cron/auto_assign.php:11-15`, `wtg  live/cron/auto_assign.php:32-182`.
- Confidence: High.

### Local logs and file cache

- WHERE: Logger writes to `logs/YYYY-MM-DD.log`; Reflex file cache uses `logs/reflex_cache`; story uploads use local `uploads/stories`.
- WHY: Shared hosting-compatible local filesystem.
- ROOT CAUSE: No traced object storage/centralized log/cache.
- CONNECTED SYSTEMS: observability, cache, media feed.
- SECURITY IMPACT: Logs/media cached locally may not be centrally governed.
- PERFORMANCE IMPACT: Disk contention under writes.
- SCALE IMPACT: Multiple web nodes diverge in cache/media/log state.
- FUTURE RISK: disaster recovery and horizontal deployment inconsistency.
- Evidence: `wtg  live/core/helpers/Logger.php:27-50`, `wtg  live/heart/pipeline/ReflexEngine.php:113-117`, `wtg  live/body/content-engine/api/StoryController.php:24-64`.
- Confidence: High.

## High-Write Contention Zones

| Zone | Write Pattern | Lock/Contention Risk | Evidence |
|---|---|---|---|
| Inventory checkout | `SELECT ... FOR UPDATE`, update reserved/quantity | popular product locks | `wtg  live/body/shopping-engine/api/orders/OrderService.php:144-163`, `wtg  live/body/shopping-engine/api/orders/OrderService.php:494-513` |
| Booking slots | slot and reservation counts inside transaction | hot vendor/time slot contention | `wtg  live/body/service-engine/api/BookingController.php:41-71` |
| Payment webhook | order `FOR UPDATE`, transaction checks | duplicate provider events lock same order | `wtg  live/lib/PaymentService.php:183-285` |
| Auto assignment | `FOR UPDATE SKIP LOCKED`, assignment lock updates | large batches hold transaction | `wtg  live/cron/auto_assign.php:32-78` |
| Alerts | inserts + polling unread counts | notification fanout/read amplification | `wtg  live/api/alerts/AlertEngine.php:80-98`, `wtg  live/api/alerts/AlertEngine.php:150-184` |

## Operational Scaling Boundaries

- Admin dashboards compute live operational metrics instead of reading precomputed snapshots.
- Alert system is polling/delta-based, not push-based in PHP runtime.
- Delivery dispatch is best-effort cURL and not durable queued.
- Payment success can trigger SwiftDeliver cURL after DB commit; failure is logged but not replayed by a durable outbox in traced code.
- Recovery is log/cron/manual-based, not event-sourced.

## Uncertain Scaling Assumptions

- Exact deployed MySQL indexes and isolation configuration need verification.
- PHP-FPM worker counts, memory limits, upload limits, opcache/APCu availability, and Redis availability are not traced from deployment config.
- Whether `SWIFTDELIVER_URL` points to one Railway instance or scaled cluster is outside PHP source.
- Whether `APP_URL`, `TRUST_PROXY`, and CORS/proxy behavior are production-correct is environment-dependent.

