# Phase 09 — API Contract Drift

## Drift Categories

1. Direct REST route vs frontend endpoint drift.
2. Intent name vs engine implementation drift.
3. Schema vocabulary vs API response/query drift.
4. Status vocabulary drift across UI/backend/external systems.
5. Auth token key and role/status drift across client surfaces.

## Traced Drift Register

| Drift | Where | Evidence | Operational impact | Confidence |
|---|---|---|---|---|
| Direct REST and intent pipeline both expose business domains | customer API uses intents; `heart/index.php` has direct REST | `wtg  live/app/js/api.js:81`, `wtg  live/heart/index.php:207` | route confusion and different side effects | High |
| Customer `getProfile()` intent not in AppRouter registry | frontend calls `user:get_profile`; registry lacks it in inspected routes | `wtg  live/app/js/api.js:135`, `wtg  live/heart/router.php:195` | profile intent likely unknown | High |
| Delivery engine configured in intent routes but `config/engines.php` delivery default empty | AppRouter delivery routes; engine config empty fallback | `wtg  live/heart/router.php:225`, `wtg  live/config/engines.php:9` | delivery intents can produce no engine result/default | High |
| `driver_id` vs `rider_id` | schema/API mismatch | `wtg  live/database-structure.sql:420`, `wtg  live/heart/api/delivery/index.php:141` | SQL/listing failures | High |
| `customer_id` vs `user_id` in admin order joins | admin order details/list use `customer_id`; baseline orders has `user_id` | `wtg  live/heart/api/admin/index.php:663`, `wtg  live/database-structure.sql:541` | admin data gaps/errors | High |
| `payments` vs `transactions` | admin routes refer payments; payment service uses transactions | `wtg  live/heart/api/admin/index.php:688`, `wtg  live/lib/PaymentService.php:244` | payment admin drift | High |
| Vendor realtime polling is client-local while alerts system is server-stored | separate modules | `wtg  live/vendor/shared/realtime.js:219`, `wtg  live/api/alerts/AlertEngine.php:50` | notification inconsistencies | High |
| Alert frontend auth token key uses `token`, while app auth stores configured token key | AlertSystem header | `wtg  live/assets/js/alerts.js:279`, `wtg  live/app/js/auth.js:7` | alert polling auth mismatch | Medium-High |
| Payment webhook says idempotent but returns 200 for process failures | webhook and service | `wtg  live/api/payment/webhook.php:88`, `wtg  live/lib/PaymentService.php:209` | external retries suppressed for some failures | High |

## Important Drift Finding — Contract is embedded, not centralized

WHERE: frontend JS, `heart/index.php`, `heart/router.php`, engine switch statements, schema SQL, and external Node runtime.
WHY: no OpenAPI/schema/shared type contract was traced.
ROOT CAUSE: routes and statuses are defined as string literals across modules.
CONNECTED SYSTEMS: customer app, vendor panel, admin panel, Heart, Brain, shopping, service, delivery, SwiftDeliver.
OPERATIONAL IMPACT: route changes can silently break one client surface while another continues.
SCALE IMPACT: team velocity decreases as surface area grows.
MAINTAINABILITY IMPACT: every API change requires repo-wide search and live validation.
FUTURE RISK: AI-generated patches can worsen drift by editing only one layer.
Confidence: High.

## High-Risk Contract Areas

### Intent-to-engine contract
Routes in `AppRouter` define intent-to-engine behavior, but engines must also implement the same intent. A missing engine case produces failed/default pipeline behavior.

Evidence: `AppRouter` registry (`wtg  live/heart/router.php:195`); service engine reads `$GLOBALS['HEART_PAYLOAD']` and switches intent (`wtg  live/body/service-engine/index.php:25`).

### Schema-to-admin contract
Admin APIs reference legacy fields and tables not guaranteed by baseline schema. This affects operational dashboards and recovery routes.

Evidence: admin order detail joins customer by `customer_id` (`wtg  live/heart/api/admin/index.php:663`); baseline order has `user_id` (`wtg  live/database-structure.sql:541`).

### Auth-token contract
Customer, vendor, admin, and alerts modules each depend on localStorage keys and auth header construction.

Evidence: customer token read (`wtg  live/app/js/api.js:10`); admin token read (`wtg  live/admin/shared/api.js:13`); vendor token set (`wtg  live/vendor/shared/auth.js:8`); alert token hardcoded (`wtg  live/assets/js/alerts.js:279`).

