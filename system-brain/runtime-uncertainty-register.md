# Phase 09 — Runtime Uncertainty Register

This file explicitly documents uncertain or partially traced runtime/API/event behavior.

## Partially Traced Runtime Flows

| Area | Traced | Uncertain | Confidence |
|---|---|---|---|
| Shopping engine internal dispatcher | Heart engine payload and route registry traced | exact shopping internal switch cases not fully re-read in Phase 09 | Medium |
| Service engine internal dispatcher | payload handling and prior Phase 07 findings traced | all intent mappings across pages not revalidated line-by-line | Medium |
| Admin frontend runtime | shared API/auth and backend admin routes traced in prior phases | every page call pattern not revalidated in Phase 09 | Medium |
| SwiftDeliver React clients | Node server REST/WS traced | exact reconnect logic in React hooks/pages not fully traced this phase | Medium |
| Alert endpoints routing | files exist and frontend calls direct `/api/alerts` | whether `heart/index.php` routes `/api/alerts` in production via server rewrite outside traced file | Medium |
| Cron execution | scripts exist | actual scheduler/cadence in hosting environment | Medium |

## Hidden Orchestration Dependencies

- Server clock accuracy affects Cashfree webhook replay checks and timestamp polling.
- Writable filesystem affects Reflex file cache and logs.
- APCu affects Heart rate limiting and Brain circuit breaker.
- Deployment rewrite rules affect whether `/api/alerts/*.php` direct files are accessible outside `heart/index.php`.
- Direct REST route order in `heart/index.php` can shadow more specific routes.
- Internal include engines share PHP process globals and `$_SERVER` mutations.
- External engine URLs can be local filesystem paths or HTTP endpoints, changing failure and auth behavior.

## Synchronization Ambiguity

- Whether Node later status updates are expected to call PHP webhook is uncertain beyond traced absence in `PUT /api/orders/:id/status`.
- Whether all WorkToGo order creation paths dispatch to SwiftDeliver identically is uncertain.
- Whether admin delivery/status operations are used in production alongside SwiftDeliver is uncertain.
- Whether Brain decisions are expected to override engine results for operational intents is uncertain.

## Realtime Uncertainty

- Vendor realtime is polling, but exact page lifecycle stop/start behavior across all vendor pages is not fully traced.
- Customer mobile browser throttling impact on polling intervals is not measured.
- SwiftDeliver frontend reconnect/backoff behavior needs deeper inspection.
- Multi-instance WebSocket deployment behavior was not traced.

## Areas Needing Future Inspection

1. Full shopping engine internal intent dispatcher.
2. Full service engine internal intent dispatcher after route registry changes.
3. All admin page API calls versus backend route availability.
4. Production web server rewrite rules for direct `/api/*` files.
5. Live scheduler configuration for cron jobs.
6. SwiftDeliver React hooks for reconnect/location/status sync.
7. Live environment variables for Brain, engine URLs, SwiftDeliver, Cashfree.
8. Production schema drift for admin/delivery/runtime referenced columns.

