# WorkToGo Runtime Bootstrap Map — Phase 01

Generated: 2026-05-12T03:44:00Z

---

## 1. Apache / Hosting Bootstrap

| Stage | Where | Behavior | Risk |
|---|---|---|---|
| HTTPS enforcement | `wtg  live/.htaccess` | Redirects HTTP to HTTPS | Assumes Apache rewrite support |
| GET root redirect | `wtg  live/.htaccess` | GET `/` redirects to `/app/` | POST root preserved for pipeline |
| Sensitive folder block | `wtg  live/.htaccess` | Blocks direct access to `core`, `brain`, `config`, `database`, `logs`, `tests`, `lib` | But later PHP allow-list permits `/brain/`; rule ordering needs live validation |
| Sensitive extension block | `wtg  live/.htaccess` | Denies `.env`, `.sql`, `.log`, `.md`, `.json`, etc. | Docs/files in webroot depend on this |
| PHP direct access controls | `wtg  live/.htaccess` | Blocks direct PHP except allowed subsystems | Complex allow-list |
| Front-controller fallback | `wtg  live/.htaccess` | Unknown paths route to `index.php` | Centralizes runtime in Heart |

---

## 2. Heart Bootstrap Order

Observed order in `heart/index.php`:

1. Enable strict types.
2. Set global error/exception handlers.
3. Define `HEART_ROOT`, `SYSTEM_ROOT`, `HEART_START`.
4. Load `.env` manually from root.
5. Load `heart/bootstrap.php`.
6. Load `config/database.php` and `config/engines.php`.
7. Load `Logger`, `Validator`, `RateLimiter`.
8. Create `$db = getDB()`.
9. Initialize logger.
10. Define legacy/global constants.
11. Set global JSON and CORS headers.
12. Enforce max content length.
13. Normalize request URI.
14. Try direct REST dispatch.
15. For unmatched non-POST: 405.
16. For unmatched POST: require `heart/router.php` pipeline.

---

## 3. `heart/bootstrap.php` Boot Order

| Step | Dependency | Purpose |
|---|---|---|
| 0 | Manual `.env` loader | Inject root `.env` into `getenv`, `$_ENV`, `$_SERVER` without Composer |
| 1 | `config/app.php` | Defines roles/JWT constants and app config |
| 2 | `core/helpers/JWT.php` | Token encode/decode |
| 3 | `core/helpers/Response.php` | JSON response + internal include exception |
| 4 | `heart/middleware/AuthMiddleware.php` | JWT/RBAC guard |
| 5 | `core/helpers/Database.php` | PDO singleton and `getDB()` |

Risk: `.env` is loaded once in `heart/index.php` and again in `heart/bootstrap.php` with slightly different parsing/precedence behavior.

---

## 4. Direct REST Dispatch Sequence

After normalization, Heart tests route families in fixed order. Important sequence effects:

- System deploy/migrate are checked before health and business APIs.
- `/api/payment` dynamic file dispatch occurs before auth/admin/body APIs.
- `/api/auth`, `/api/admin`, `/api/delivery` are Heart-native.
- Shopping/service body API prefixes are matched in Heart before generic pipeline.
- Feed/story/settings/search are direct REST, not pipeline.
- The pipeline is only reached after all direct handlers fail and method is POST.

Operational risk: route precedence is encoded procedurally. Adding a broader prefix above a narrower route can shadow routes.

---

## 5. Pipeline Bootstrap

`heart/router.php` loads:

1. `ContextBuilder`
2. `ReflexEngine`
3. `BrainConnector`
4. `EngineLoader`
5. `PriorityResolver`
6. `ResponseBuilder`

Then it:

1. Sends JSON/CORS headers.
2. Rejects non-POST.
3. Rejects non-JSON.
4. Parses raw JSON.
5. Requires `intent`.
6. Applies Heart APCu rate limiter.
7. Builds context.
8. Resolves AppRouter route.
9. Checks reflex cache.
10. Calls Brain if route says `brain = true`.
11. Dispatches engines.
12. Resolves priority.
13. Builds response.
14. Stores reflex cache.
15. Sends JSON response.

---

## 6. Brain Bootstrap

`brain/index.php`:

1. Determines app env from `$_SERVER`/`$_ENV`.
2. Sets display error behavior.
3. Sends JSON content-type.
4. Defines `SYSTEM_ROOT` if absent.
5. Requires `config/app.php`, `core/helpers/Database.php`, `core/helpers/Logger.php`, `brain/config/autoload.php`.
6. Creates DB and initializes logger.
7. Configures CORS from `ALLOWED_ORIGINS` env or `*`.
8. Handles OPTIONS.
9. Requires `brain/routes.php`.

Brain route dispatch uses `brain/core/Router.php` through the autoloader.

Risk: The Brain comments mention `config/env.php` but active `brain/index.php` does not load it. `NightlyJob.php` references `brain/config/env.php`, which was not present in the snapshot.

---

## 7. Environment / Config Usage

| Config Key / Source | Where Used | Role | Risk |
|---|---|---|---|
| `.env` root | Heart/bootstrap/payment config | Main env source | Multiple loaders |
| `APP_ENV` | app config, payment, Brain errors | Environment mode | Defaults production in app config |
| `APP_URL` | CORS, frontend base injection, payment return/webhook | Public base URL | Empty/mismatch breaks CORS/payment URLs |
| `JWT_SECRET` | app config/JWT/AuthMiddleware | Token signing | Required in production; boot fails if absent |
| `JWT_EXPIRY`, `JWT_REFRESH_EXP` | app config/auth | Token lifetime | Global constants |
| `ADMIN_KEY` | Heart debug, Brain admin, app config | Admin/debug guard | Empty disables/blocks admin debug depending path |
| `HEART_INTERNAL_KEY` | internal engine/brain headers | Service-to-service marker | Often set but direct internal includes do not truly verify in body entries |
| `ENGINE_SHOPPING_URL`, `ENGINE_SERVICE_URL` | engine config | Body engine targets | Defaults local PHP paths |
| `ENGINE_DELIVERY_URL`, `ENGINE_VIDEO_URL` | engine config | Optional engines | Empty by default |
| `BRAIN_API_URL`, `BRAIN_API_KEY`, `BRAIN_CLIENT_ID` | Heart BrainConnector | Brain integration | Default Brain URL computed from APP_URL + `/brain`; may be remote HTTP unexpectedly |
| `BRAIN_CIRCUIT_THRESHOLD`, `BRAIN_CIRCUIT_WINDOW` | BrainConnector | Circuit breaker | APCu only |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASS`, `REDIS_DB` | ReflexEngine | Cache driver | Optional |
| `TRUST_PROXY` | ContextBuilder/HeartRateLimiter | Client IP behavior | Boolean casting of string may surprise |
| `LOG_LEVEL` | Logger | Log threshold | App config uses `HEART_LOG_LEVEL`, mismatch |
| Cashfree keys | Payment config/helpers/lib | Payments | Duplicated config paths |
| `SWIFTDELIVER_URL`, `SWIFTDELIVER_SECRET` | PaymentService/OrderService | External delivery dispatch | Optional hidden runtime dependency |

---

## 8. Runtime Initialization Risks

| Risk | Where | Impact |
|---|---|---|
| Boot dies if JWT secret missing | `config/app.php` | Whole backend unavailable in production |
| Database connection exits immediately on failure | `core/helpers/Database.php` | No graceful degraded mode for many routes |
| Headers emitted in multiple places | Heart index, Heart router, Brain index, Response | Header duplication/order issues possible |
| CORS differs by subsystem | Heart index vs Heart router vs Brain index | Browser behavior may vary by endpoint |
| Internal include cannot be parallel for local engines | EngineLoader handles local includes sequentially even for multiple engines | “parallel HTTP” only applies to remote HTTP engines |
| File cache under `logs/reflex_cache` | ReflexEngine | Requires writable logs directory; cache files accumulate unless TTL checked/deleted |

