# WorkToGo Architecture Overview — Phase 01

Generated: 2026-05-12T03:44:00Z

---

## 1. Architecture Model

The application uses a layered PHP architecture with biological subsystem names:

| Biological Name | Technical Meaning | Primary Location | Runtime Responsibility |
|---|---|---|---|
| Heart | Gateway/orchestrator | `wtg  live/heart/` | Request bootstrap, auth, REST dispatch, intent pipeline, body/brain coordination |
| Brain | Intelligence/rules/ranking | `wtg  live/brain/` | Decisions, feedback, events, admin intelligence views, feed ranking |
| Body | Business engines | `wtg  live/body/` | Shopping, service, content, video features |
| Core | Shared platform primitives | `wtg  live/core/` | DB, response, JWT, logger, validation, payment, ledger, upload |
| App/Admin/Vendor | Frontends | `wtg  live/app/`, `admin/`, `vendor/` | Browser UI layers |

---

## 2. Runtime Entry Points

| Entry | Where | Purpose | Connected Modules | Runtime Role | Scale Risk | Maintainability Risk | Future Impact |
|---|---|---|---|---|---|---|---|
| Public PHP entry | `wtg  live/index.php` | Delegates everything to Heart | Heart | Main backend entry | Low | Low | Stable if Heart remains stable |
| Heart entry | `wtg  live/heart/index.php` | Loads env/config/core and dispatches routes | Config, DB, Logger, REST controllers, pipeline | Central gateway | High | High | Route additions likely grow monolith |
| Heart pipeline | `wtg  live/heart/router.php` | POST JSON intent orchestration | Pipeline classes, Brain, Body engines | Unified business response shape | Medium-high | Medium-high | Needs contract discipline |
| Brain entry | `wtg  live/brain/index.php` | Brain boot/router | Brain autoload, DB, Logger, brain routes | Intelligence API | Medium | Medium | Optional but important for personalization |
| Body shopping entry | `wtg  live/body/shopping-engine/index.php` | Internal Heart intent dispatcher | Shopping API controllers/services | Commerce engine | Medium-high | Medium | Shopping scale depends on service isolation |
| Body service entry | `wtg  live/body/service-engine/index.php` | Internal Heart intent dispatcher | Service API controllers | Service booking engine | Medium-high | Medium-high | Booking/jobs need stronger contracts |

---

## 3. Architectural Patterns Found

### 3.1 Front-controller pattern

`.htaccess` routes unknown requests to `index.php`, and `index.php` delegates to Heart. This is classic front-controller behavior.

### 3.2 Direct file dispatcher pattern

Heart dispatches many URI prefixes by `require_once`-ing files directly. Example route families:

- `/api/auth` → `heart/api/auth/router.php`
- `/api/admin` → `heart/api/admin/index.php`
- `/api/payment` → dynamic file in `api/payment/*.php`
- `/api/orders` → `body/shopping-engine/api/orders/index.php`
- `/api/services` → `body/service-engine/api/services/index.php`

### 3.3 Intent pipeline pattern

POST requests not matched as direct REST enter a generic intent pipeline. The pipeline builds normalized context and dispatches engines based on `AppRouter` mappings.

### 3.4 Internal include as service-call pattern

When engine URLs are local paths, Heart includes PHP files directly and uses global flags/payloads to emulate an HTTP request.

### 3.5 Singleton shared DB pattern

`Database::getInstance()` returns a static PDO instance used across all modules.

### 3.6 Static helper pattern

Most shared services are static classes or global functions (`Response`, `JWT`, `Logger`, `RateLimiter`, `getDB()`).

---

## 4. Module Boundary Assessment

| Boundary | Status | Evidence | Risk |
|---|---|---|---|
| Frontend vs backend | Partial | Separate folders exist, but `.php` frontends inject config and static `.html` contains PHP code | Medium |
| Heart vs body | Partial | Heart orchestrates body engines, but also directly calls body controllers and creates controller instances | High |
| Body shopping vs service | Partial | Separate folders, but vendor analytics, vendor panel, roles, payments overlap | Medium-high |
| Core vs feature modules | Weak | Core helper changes affect every module; feature modules depend on constants from Heart | High |
| Brain vs Heart | Partial | Brain optional via connector, but Heart directly includes BrainCore for feed | Medium |
| Payment vs orders/bookings/refunds | Weak | Payment logic appears in `core/helpers/Payment.php`, `lib/PaymentService.php`, orders, services, refunds | High |
| Admin backend vs platform backend | Weak | Admin routes live inside Heart monolithic admin index and query many feature tables directly | Medium-high |

---

## 5. Naming Consistency

### Consistent areas

- Major subsystem naming: `heart`, `brain`, `body`, `core`.
- Role constants: `admin`, `vendor_service`, `vendor_shopping`, `delivery`, `customer`.
- Most core helpers use PascalCase class names matching filenames.

### Inconsistent areas

| Inconsistency | Where | Impact |
|---|---|---|
| Vendor frontend intents vs backend route keys | `vendor/shared/api.js` vs `heart/router.php` | Some vendor calls likely resolve to 422 or default responses |
| Admin frontend endpoint base | `admin/config.js` uses `/heart/api` while Heart strips `/heart` and expects `/api/*` after routing | Can work by normalization, but confusing and fragile |
| Payment abstractions | `core/helpers/Payment.php` vs `lib/PaymentService.php` | Unclear single source of truth |
| Product ownership tables | Admin queries `merchants`, shopping engine likely uses `vendors`/products | Indicates legacy or duplicated commerce concepts |
| Delivery identifiers | Some queries use `driver_id`, others `rider_id` | Schema/API ambiguity |
| Config log key | App config uses `HEART_LOG_LEVEL`, Logger reads `LOG_LEVEL` | Runtime logging expectations may not match |

---

## 6. Legacy / Dead / Placeholder Structures

| Structure | Evidence | Risk |
|---|---|---|
| Video engine placeholder | Heart route comments say not activated; config has empty `ENGINE_VIDEO_URL`; placeholder controller exists | Future activation requires route/config work |
| Brain script references missing/uncertain `config/env.php` | `brain/scripts/NightlyJob.php` references `brain/config/env.php`, not seen in folder snapshot | Scheduled job may be unreachable/broken |
| Customer `index.html` contains PHP echo | Static HTML will not execute PHP | Base URL injection may silently fail |
| Admin test files | `admin/test-auth-flow.html`, `admin/test-url-fix.html` | Test files in production folder may be operational clutter |
| Existing audit/fix reports in webroot | `.htaccess` blocks `.md`, but files remain in project root | Docs in public tree rely on server deny rules |
| Delivery engine configured empty | `config/engines.php` has blank delivery URL | Pipeline delivery intents produce skipped engine unless external env set |
| `core/helpers/EventEngine.php` referenced but not in snapshot | `OrderService` tries `CORE_PATH . '/helpers/EventEngine.php'` | Event emission path may silently fail or throw depending file existence guard |

---

## 7. Deployment / Runtime Assumptions

| Assumption | Evidence | Risk |
|---|---|---|
| Apache with mod_rewrite | `.htaccess` controls routing/security | Non-Apache hosting needs rewrite equivalent |
| Shared hosting compatibility | Comments mention Hostinger, file cache fallback | Concurrency/file permissions need careful review |
| MySQL/PDO | `Database.php` uses MySQL DSN | DB unavailable blocks most runtime |
| Optional APCu/Redis | Reflex and rate limiting use APCu/Redis if available | Behavior changes by hosting extension availability |
| Cashfree payments | Payment config and libs use Cashfree | Env must be correct for online payment |
| External SwiftDeliver | Payment/order service reference `SWIFTDELIVER_URL`/secret | Delivery dispatch may depend on external service |
| `.env` in project root | Multiple loaders read `wtg  live/.env` | Secrets must stay denied by `.htaccess` and deployment controls |

---

## 8. Core Architecture Risks

| Risk | Severity | Where | Why it matters |
|---|---:|---|---|
| Heart monolith | High | `heart/index.php`, `heart/api/admin/index.php` | Bootstrap, routing, controller construction, and business admin queries are mixed |
| Hidden global state | High | `HEART_INTERNAL_INC`, `GLOBALS['HEART_PAYLOAD']`, `$_SERVER` mutation | Internal includes have side effects and invisible contracts |
| Route contract drift | High | Frontends vs Heart AppRouter vs body indexes | Users/vendors/admin can call endpoints that backend does not map consistently |
| Payment duplication | High | `core/helpers/Payment.php`, `lib/*`, order/service/refund code | Gateway behavior may diverge and complicate refunds/webhooks |
| Duplicate env loading | Medium-high | `heart/index.php`, `heart/bootstrap.php`, `config/payment.config.php` | Different precedence/comment parsing can cause inconsistent config |
| Optional extension behavior | Medium | APCu/Redis/cache/rate limiting | Production behavior varies by PHP extension availability |
| Mixed schema vocabulary | Medium-high | `vendors`, `merchants`, `drivers`, `riders`, `payments`, `transactions` | Hard to reason about ownership and lifecycle |

