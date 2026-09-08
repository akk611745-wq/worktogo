# WorkToGo Architectural Risks — Phase 01

Generated: 2026-05-12T03:44:00Z

---

## 1. Risk Register

| ID | Risk | Severity | Where | Purpose Affected | Connected Modules | Runtime Role | Scale Risk | Maintainability Risk | Future Impact |
|---|---|---:|---|---|---|---|---|---|---|
| R-001 | Heart monolithic gateway | High | `heart/index.php` | All routing/bootstrap | Config, core, auth, admin, body, brain, payment | Main dispatcher | High | High | Any new feature can destabilize gateway |
| R-002 | Admin monolithic API | High | `heart/api/admin/index.php` | Admin operations | Users, vendors, orders, payments, deliveries, settings, logs | Admin backend | Medium-high | High | Admin expansion becomes risky |
| R-003 | Hidden global include contract | High | `EngineLoader`, `BrainConnector`, `Response` | Pipeline internal calls | Body, Brain, Core | Service-call transport | High | High | Hard to test and split services later |
| R-004 | Intent route drift | High | `app/js/api.js`, `vendor/shared/api.js`, `heart/router.php`, body indexes | App/vendor runtime calls | Frontends, Heart, Body engines | Intent API | High | High | User-facing broken flows likely unless mapped |
| R-005 | Payment duplication | High | `core/helpers/Payment.php`, `lib/PaymentService.php`, `api/payment`, order/service/refund code | Payment/refund lifecycle | Cashfree, orders, bookings, refunds, ledger | Financial runtime | High | High | Payment correctness and auditability at risk |
| R-006 | Config/env duplication | Medium-high | `heart/index.php`, `heart/bootstrap.php`, `payment.config.php` | Runtime configuration | App, DB, payment, CORS | Boot config | Medium | Medium-high | Environment-specific bugs likely |
| R-007 | Public tree contains private code | Medium-high | entire `wtg live/` with `.htaccess` protection | Deployment/security | Core, config, brain, lib, SQL, docs | Hosting layout | Medium | Medium | Host migration could expose internals |
| R-008 | Schema vocabulary inconsistency | Medium-high | Admin/body/order/delivery code | DB ownership | Vendors/merchants, drivers/riders, payments/transactions | Data layer | Medium | High | Future migrations may break features |
| R-009 | Brain optional but route assumed | Medium | `BRAIN_API_URL` default, `BrainConnector`, `/api/feed` | Intelligence/feed | Heart, Brain, DB | Optional AI layer | Medium | Medium | Personalization behavior varies by env |
| R-010 | Cache/rate-limit behavior varies by extension | Medium | ReflexEngine, HeartRateLimiter | Performance/abuse control | Redis/APCu/files | Runtime infra | Medium | Medium | Production behavior differs from dev/shared hosting |
| R-011 | CORS differs by subsystem | Medium | Heart index, Heart router, Brain index | Browser API access | Frontends, Heart, Brain | HTTP headers | Medium | Medium | Endpoint-specific browser failures possible |
| R-012 | Static/PHP frontend entry ambiguity | Medium | `app/index.html`, `app/index.php` | Customer app config | App frontend | Bootstrap | Low-medium | Medium | Base URL may differ by served entry |
| R-013 | Dead/placeholder features in route/config | Medium | video engine, delivery engine, NightlyJob | Feature discovery | Heart, config, body, cron | Dormant runtime | Low now | Medium | Future activation could assume false readiness |
| R-014 | Logging config mismatch | Low-medium | `config/app.php`, `Logger.php` | Observability | Heart, Brain, core | Runtime logs | Low | Medium | Debugging may miss expected logs |

---

## 2. Dangerous Coupling Details

### 2.1 Heart to Body through direct include

WHERE: `heart/pipeline/EngineLoader.php` includes body engine paths when engine URLs are not HTTP.  
PURPOSE: Avoid HTTP calls for local engines.  
CONNECTED MODULES: Heart pipeline, body shopping/service, core Response.  
RUNTIME ROLE: Transports pipeline payload into body engines.  
SCALE RISK: Local includes are sequential for local engines and run in same PHP process.  
MAINTAINABILITY RISK: Body code changes can break pipeline due invisible globals.  
FUTURE IMPACT: Splitting engines into services later will require formal HTTP contracts.

### 2.2 Payment workflow spread

WHERE: `api/payment`, `lib`, `core/helpers/Payment.php`, `OrderService`, service booking route, refund controller.  
PURPOSE: Online payment, webhook, refunds, payment status.  
CONNECTED MODULES: Cashfree, DB, orders, bookings, refunds, ledger, delivery.  
RUNTIME ROLE: Critical financial flow.  
SCALE RISK: Financial side effects can race or duplicate if multiple pathways update status.  
MAINTAINABILITY RISK: Engineers may patch the wrong payment abstraction.  
FUTURE IMPACT: Audit/compliance and refunds require single source of truth.

### 2.3 Vendor role lifecycle crossing layers

WHERE: admin vendor approval updates user role; vendor frontend stores vendor token/user; body APIs require `vendor_service`/`vendor_shopping`; Heart AppRouter has vendor intents.  
PURPOSE: Promote users into vendor roles and enable vendor operations.  
CONNECTED MODULES: Admin, Auth, Vendor UI, Body shopping/service.  
RUNTIME ROLE: Vendor access control.  
SCALE RISK: Token role can become stale after approval.  
MAINTAINABILITY RISK: Vendor type/status/role mapping exists in multiple places.  
FUTURE IMPACT: Multi-role users or vendor teams will be difficult without redesign.

### 2.4 Admin API directly queries operational tables

WHERE: `heart/api/admin/index.php`.  
PURPOSE: Dashboard and management views.  
CONNECTED MODULES: orders, users, vendors, wallet, deliveries, payments, services, products.  
RUNTIME ROLE: Admin console backend.  
SCALE RISK: Heavy dashboard queries can affect production DB.  
MAINTAINABILITY RISK: Schema changes break admin silently.  
FUTURE IMPACT: Admin should eventually use service-level queries or read models.

---

## 3. Circular Dependency Risks

| Cycle | Evidence | Risk |
|---|---|---|
| Heart → Body → Heart middleware | Body engines require `heart/middleware/AuthMiddleware.php` | Folder-level cycle complicates extraction |
| Core Response ↔ Heart internal mode | Core Response checks Heart-defined `HEART_INTERNAL_INC`; Heart catches core exception | Core behavior depends on Heart runtime flag |
| Heart → Brain → Core app config → Heart constants maybe absent | Brain includes shared config/core while Heart can include Brain internally | Boot context differences can produce undefined assumptions |
| Orders → Payment → Delivery/SwiftDeliver → DB orders | Order creation/payment webhook can dispatch delivery and update order state | Side-effect cycle around order state |

---

## 4. Global-State Dangers

| Global State | Danger |
|---|---|
| `$_SERVER['REQUEST_URI']` mutation | Route handlers may see simulated path rather than original request path |
| `$_SERVER['REQUEST_METHOD']` mutation | Nested includes may treat request as GET/POST/PATCH incorrectly |
| `HEART_INTERNAL_INC` constant | Cannot be undefined during same request once set |
| `$GLOBALS['HEART_PAYLOAD']` | Shared mutable request body for internal calls |
| Static `Database::$instance` | One DB connection/config per request; no context-specific DB |
| Browser `localStorage` auth | Tokens persist across tabs and are not httpOnly |

---

## 5. Scaling Bottlenecks

| Bottleneck | Why |
|---|---|
| Single PHP request does all orchestration | Heart pipeline waits for Brain/body results in same request |
| Local engine includes | Not actually parallel; all local engine work shares one process and memory |
| File cache fallback | O(N) scans for prefix invalidation and filesystem lock contention possible |
| Admin dashboard queries | Aggregations across orders/wallets/users on live DB |
| Auth blacklist DB check every protected request | Adds DB read to every auth-protected API |
| Payment/delivery side effects in request path | External calls can slow user-facing operations |

---

## 6. Recommended Risk Follow-Up

1. Create intent contract test table without changing runtime.
2. Create route ownership matrix.
3. Trace payment/order/delivery/refund lifecycle end-to-end.
4. Trace auth token issuance/refresh/logout/blacklist behavior.
5. Verify `.htaccess` behavior in actual hosting environment.
6. Trace schema vocabulary and identify legacy tables/aliases.

