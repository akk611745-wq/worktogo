# WorkToGo Core Foundation Map — Phase 01

Generated: 2026-05-12T03:44:00Z  
Scope: real active project under `wtg  live/` only.  
Mode: foundation intelligence extraction only; no product code changed.

---

## 1. Executive Core Map

WorkToGo is a PHP-first, shared-hosting-oriented platform with a biological architecture naming convention:

```text
Browser / Panels / API callers
        ↓
Root public entry
        ↓
Heart system = request ingress, routing, auth, pipeline orchestration
        ↓
Brain system = optional decision/ranking/intelligence layer
        ↓
Body systems = business engines: shopping, service, content, video placeholder
        ↓
Core/shared helpers = DB, response, JWT, logging, payment, validation, ledger
        ↓
MySQL + filesystem logs/cache + optional Redis/APCu + external Cashfree/SwiftDeliver
```

Primary runtime entry is `wtg  live/index.php`, which immediately includes `wtg  live/heart/index.php`. The heart layer performs environment loading, config loading, shared helper loading, REST dispatch, and POST intent-pipeline orchestration.

---

## 2. High-Level Responsibility Map

| Area | Where | Purpose | Connected Modules | Runtime Role | Scale Risk | Maintainability Risk | Future Impact |
|---|---|---|---|---|---|---|---|
| Public root | `wtg  live/index.php` | Single production entry, delegates to Heart | `heart/index.php` | Default fallback route target via `.htaccess` | Low by itself | Low, but hides all runtime behind Heart | All public request behavior depends on Heart staying stable |
| Heart ingress | `wtg  live/heart/index.php` | Global bootstrap, headers, CORS, REST routing, pipeline fallback | config, core helpers, auth/admin/body/payment/brain | Main request dispatcher | High: one large file handles many route families | High: route table mixed with bootstrap and business-controller instantiation | Future modules will likely keep expanding this file unless separated later |
| Heart pipeline | `wtg  live/heart/router.php` + `heart/pipeline/*` | Intent-based orchestration through context/reflex/brain/body/priority/response | BrainConnector, EngineLoader, body engines | POST JSON intent runtime | Medium-high: internal include mutates globals/`$_SERVER` | Medium-high: pipeline and route registry live together | Determines whether app/vendor APIs work via intents |
| Brain | `wtg  live/brain/` | Decision/rules/events/feedback/dashboard/feed ranking | Heart BrainConnector, Feed API, DB models | Optional AI/intelligence layer | Medium: optional circuit breaker helps, but local include can fail hard | Medium: separate router/autoload conventions differ from Heart | Future personalization depends on stabilizing this layer |
| Body shopping | `wtg  live/body/shopping-engine/` | Product/cart/order/vendor shopping operations | Heart direct REST and EngineLoader internal includes | Business engine for commerce | High: order/payment/delivery coupling | High: controllers/services plus direct routes + intent aliases | Scaling order workflow requires isolating payment/delivery side effects |
| Body service | `wtg  live/body/service-engine/` | Services, bookings, vendor availability/jobs | Heart direct REST and EngineLoader internal includes | Business engine for service marketplace | Medium-high: booking/payment/slot/job coupling | Medium-high: index mutates `$_SERVER` to simulate REST | Future booking workflow depends on clear contracts |
| Content engine | `wtg  live/body/content-engine/api/StoryController.php` | Vendor stories/feed stories/view tracking | Heart REST dispatch, auth, DB | Content/story feature | Medium: feed path touches Brain-ish concepts | Medium: controller assumes globally loaded helpers | Could become third major body module |
| Video engine | `wtg  live/body/video-engine/` | Placeholder video controller/feed | Heart route comments only, body placeholder | Not fully active | Low now | Medium: registered in routes/config but inactive | Future activation needs clear route contracts |
| Core helpers | `wtg  live/core/` | Shared DB, JWT, Response, Logger, Validator, Payment, Ledger | All backend modules | Cross-cutting runtime primitives | High: global singleton DB + global constants | High: tight shared dependency means changes ripple everywhere | Must be treated as stable platform API |
| Admin panel frontend | `wtg  live/admin/` | Static admin UI | Heart admin/auth REST | Operational console | Medium: many endpoints in single admin backend file | High: frontend endpoint naming not fully aligned with backend naming | Admin UX expansion likely stresses monolithic admin API |
| Customer SPA | `wtg  live/app/` | Hash-router web app | Heart auth REST + intent pipeline | Customer-facing app | Medium: config/API endpoint mismatch risks | Medium: globals and script order dependencies | Future app features must align intents with Heart AppRouter |
| Vendor panel | `wtg  live/vendor/` | Vendor UI for shopping/service vendors | Heart auth + intent pipeline | Vendor operations | Medium-high: many vendor intents are not mapped in Heart | High: separate API/auth/config from app/admin | Needs route-contract cleanup before scaling |
| Payment layer | `wtg  live/api/payment/`, `wtg  live/lib/`, `wtg  live/core/helpers/Payment.php` | Cashfree payment order/status/webhook/refund | Orders/bookings/refunds/payment config | External payment runtime | High: duplicated payment abstractions | High: two payment service styles (`lib` and `core/helper`) | Future payment changes risk inconsistent behavior |
| Deployment/security | `wtg  live/.htaccess`, `deploy-hook.php`, `migrate.php` | HTTPS, deny sensitive files, route fallback, deploy/migrate | Heart system endpoints | Hosting/runtime guardrail | Medium: assumes Apache/Hostinger behavior | Medium: direct access allow-list is complex | Moving hosts requires revalidating all rewrite/security assumptions |

---

## 3. Folder Intelligence Summary

| Folder | Runtime Ownership | Notes |
|---|---|---|
| `wtg  live/admin/` | Admin frontend | Static pages using `admin/config.js`, `admin/shared/api.js`, `admin/shared/auth.js`, `admin/shared/shell.js`. |
| `wtg  live/api/` | Direct specialized REST APIs | Payment, alerts, assignment acceptance. Some bypass Heart controller structure but are dispatched by Heart or direct allowed path. |
| `wtg  live/app/` | Customer SPA frontend | Hash router, global JS modules, dynamic page imports, same-origin/pipeline API calls. |
| `wtg  live/assets/` | Shared browser assets | Alerts JavaScript lives here; connected to alerts APIs. |
| `wtg  live/body/` | Business engines | Shopping/service/content/video. Heart treats shopping/service as body engines and direct REST targets. |
| `wtg  live/brain/` | Intelligence subsystem | Own entry, routes, autoload, controllers, models, engine, core classes. Also used by Heart feed endpoint. |
| `wtg  live/config/` | Global backend config | App, DB, engines, payment. Environment-driven, but duplicate env loaders exist. |
| `wtg  live/core/` | Shared PHP core | Cross-module helpers/controllers. Most backend depends on this. |
| `wtg  live/cron/` | Scheduled jobs | Auto assignment job; depends on DB and operational tables. |
| `wtg  live/docs/` | Existing docs | Alerts docs currently. |
| `wtg  live/heart/` | Runtime ingress/orchestrator | Bootstrap, REST dispatcher, middleware, auth/admin/delivery/search APIs, pipeline. |
| `wtg  live/lib/` | Payment library wrappers | Cashfree client/payment service/webhook verifier. |
| `wtg  live/migrations/` | SQL migrations | Incremental database changes; naming indicates many auth/vendor fixes. |
| `wtg  live/plans/` | Planning docs | Existing controlled auth/vendor fix readiness plan. |
| `wtg  live/vendor/` | Vendor frontend | Separate static/PHP shell pages with own shared API/auth/realtime/analytics. |

---

## 4. Frontend / Backend Separation

### Frontends

| Frontend | Where | Bootstrap | API Strategy | Risk |
|---|---|---|---|---|
| Customer app | `wtg  live/app/index.html`, `wtg  live/app/index.php` | Loads `env.js`, inline `WTG_BASE_URL`, `config.js`, `ui.js`, `api.js`, `auth.js`, `router.js`; calls `ROUTER.init()` | Direct auth REST and POST intent pipeline | Duplicate `index.html`/`index.php`; `index.html` contains PHP echo that will not execute if served as static HTML |
| Admin panel | `wtg  live/admin/*.html` | Static HTML pages plus shared JS | REST through `/heart/api` + `/admin` prefix logic | Prefix normalization is fragile; backend actually dispatches `/api/admin/*` after Heart normalization |
| Vendor panel | `wtg  live/vendor/*.php`, `.html` | PHP pages inject `WTG_BASE_URL`, shared JS modules | Intent pipeline and auth REST | Vendor intent names in frontend diverge from Heart route names in several places |

### Backend

| Backend Layer | Where | Notes |
|---|---|---|
| Main ingress | `heart/index.php` | Parses URI, maps REST routes, falls back to `heart/router.php` for POST pipeline. |
| Pipeline | `heart/router.php` and `heart/pipeline/*` | Validates JSON, builds context, cache check, optional Brain, engine dispatch, priority resolution, response build. |
| REST APIs | `heart/api/*`, `api/*`, `body/*/api/*`, `core/controllers/*` | Mixed direct file routing and controller/service patterns. |
| Business engines | `body/shopping-engine`, `body/service-engine` | Internal Heart dispatch plus direct REST endpoint support. |
| Intelligence | `brain/` | Optional in Heart pipeline, direct admin/public brain routes, feed ranking. |

---

## 5. Core Runtime Flow

### Browser GET `/`

1. Apache rewrite in `.htaccess` redirects GET root `/` to `/app/`.
2. Customer frontend loads from `app/`.
3. Browser JavaScript calls APIs against `CONFIG.BASE_URL`.

### Generic backend request

1. `.htaccess` routes non-file/non-directory requests to `index.php`.
2. `index.php` includes `heart/index.php`.
3. `heart/index.php` loads env, config, helpers, DB, logger, headers, CORS.
4. `heart/index.php` normalizes URI and dispatches to direct REST handlers.
5. If not matched and method is POST, request enters `heart/router.php` intent pipeline.

### Intent pipeline POST

1. `heart/router.php` requires pipeline classes.
2. Enforces POST + JSON + required `intent`.
3. `HeartRateLimiter::check()` uses APCu if available.
4. `ContextBuilder::build()` normalizes payload into immutable-ish array context.
5. `AppRouter::resolve()` maps `app:intent` or generic `intent` to engines/Brain/cache settings.
6. `ReflexEngine::check()` may fast-return cached response.
7. `BrainConnector::call()` optionally calls local/remote Brain with circuit breaker.
8. `EngineLoader::dispatch()` calls body engines internally or via HTTP.
9. `PriorityResolver::resolve()` chooses winner among cache/Brain/engines/default.
10. `ResponseBuilder::build()` emits uniform `ok`, `request_id`, `intent`, `blocks`, `meta` response.
11. `ReflexEngine::store()` caches cacheable response.

---

## 6. Important Couplings

| Coupling | Where | Description | Risk |
|---|---|---|---|
| Global constants as API | `heart/index.php`, `config/app.php` | Defines `SYSTEM_ROOT`, `HEART_ROOT`, `JWT_SECRET`, role constants, `ENGINE_URLS`, `CORE_PATH`, etc. | High: files assume constants exist depending on entry path. |
| `$_SERVER` mutation for internal dispatch | `EngineLoader`, `body/service-engine/index.php` | Internal engine calls simulate HTTP by setting `REQUEST_METHOD`, `REQUEST_URI`, headers. | High: hidden state may leak between included modules. |
| `GLOBALS['HEART_PAYLOAD']` as request bus | `BrainConnector`, `EngineLoader`, body helpers | Internal includes read JSON from global variable. | Medium-high: implicit contract not visible in function signatures. |
| `Response` throws on internal include | `core/helpers/Response.php` | `Response::send()` throws `InternalResponseException` if `HEART_INTERNAL_INC` is true. | Medium: clever but fragile; depends on catch blocks. |
| Single DB singleton | `core/helpers/Database.php` | All modules share one static PDO instance. | Medium: simple but limits multi-tenant/multi-db and testing. |
| Payment duplication | `core/helpers/Payment.php`, `lib/PaymentService.php`, `lib/CashfreeClient.php` | Multiple implementations around Cashfree. | High: inconsistent gateway behavior likely. |
| Route definitions split across layers | `heart/index.php`, `heart/router.php`, `body/*/api/index.php`, `brain/routes.php` | Different routers and conventions. | High: new endpoints can be added in wrong place or not reachable. |
| Frontend intent naming vs backend routes | `app/js/api.js`, `vendor/shared/api.js`, `heart/router.php`, body indexes | Some frontend intents are unmapped or differently named. | High: runtime 422 or default empty responses. |

---

## 7. Mandatory Final Section

### Assumptions made

- The active target project is `wtg  live/`, not `swiftdeliver-main/`, except SwiftDeliver is referenced as an external delivery integration.
- `WTG-SYSTEM-BRAIN/` was initially empty; generated docs are new intelligence artifacts rather than overwrites.
- The project is intended to run on Apache/Hostinger-style PHP hosting based on `.htaccess` comments and file-cache fallback wording.
- Database schema details are inferred from query/table names, not fully validated against SQL migrations.

### Uncertain mappings

- Whether `/app/index.html` or `/app/index.php` is served in production.
- Whether `/heart/api` frontend admin base URL is intentionally used or accidentally divergent from backend `/api` routes.
- Whether Brain is normally called via local path, remote URL, or not configured.
- Whether delivery is internal PHP only, SwiftDeliver external, or both depending on deployment.

### Areas not fully traceable

- Full database schema and all migration side effects.
- Full admin frontend page-to-endpoint coverage.
- Full vendor page behavior and all realtime/analytics scripts.
- Full payment webhook lifecycle across Cashfree, orders, transactions, wallet ledger, and external delivery dispatch.

### Possible hallucination zones

- Any table-level relationship not explicitly visible in queries should be treated as inferred.
- Any deployment behavior beyond Apache `.htaccess` should be treated as inferred.
- “Brain AI” behavior is mostly rule/model/controller based in current files; advanced intelligence claims require deeper inspection.

### Files requiring deeper inspection

- `wtg  live/body/shopping-engine/api/orders/OrderService.php`
- `wtg  live/body/service-engine/api/services/index.php`
- `wtg  live/heart/api/auth/AuthController.php`
- `wtg  live/brain/Controllers/DecisionController.php`
- `wtg  live/brain/engine/RuleEngine.php`
- `wtg  live/lib/PaymentService.php`
- `wtg  live/core/helpers/LedgerEngine.php`
- `wtg  live/vendor/shared/auth.js`
- `wtg  live/admin/assets/app.js`
- `wtg  live/database-structure.sql`

### Recommended next tracing targets

1. Contract map: frontend calls → Heart route → controller/service → DB tables.
2. Auth/vendor role lifecycle: registration, approval, token refresh, role propagation.
3. Payment/order/delivery transaction lifecycle.
4. Brain decision endpoint and rule/model dependencies.
5. Database schema ownership and table-to-module map.

