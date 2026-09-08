# WorkToGo Dependency Map — Phase 01

Generated: 2026-05-12T03:44:00Z

---

## 1. Primary Dependency Graph

```text
wtg live/.htaccess
  └─ routes unknown requests to index.php
      └─ heart/index.php
          ├─ heart/bootstrap.php
          │   ├─ config/app.php
          │   ├─ core/helpers/JWT.php
          │   ├─ core/helpers/Response.php
          │   ├─ heart/middleware/AuthMiddleware.php
          │   └─ core/helpers/Database.php
          ├─ config/database.php
          ├─ config/engines.php
          ├─ core/helpers/Logger.php
          ├─ core/helpers/Validator.php
          ├─ core/helpers/RateLimiter.php
          ├─ REST route files/controllers
          └─ heart/router.php for POST intent pipeline
              ├─ heart/pipeline/ContextBuilder.php
              ├─ heart/pipeline/ReflexEngine.php
              ├─ heart/pipeline/BrainConnector.php
              ├─ heart/pipeline/EngineLoader.php
              ├─ heart/pipeline/PriorityResolver.php
              └─ heart/pipeline/ResponseBuilder.php
```

---

## 2. Frontend Dependency Graph

### Customer app

```text
app/index.html or app/index.php
  ├─ app/env.js
  ├─ inline window.WTG_BASE_URL
  ├─ app/js/config.js
  ├─ app/js/ui.js
  ├─ app/js/api.js
  ├─ app/js/auth.js
  ├─ app/js/router.js
  ├─ app/pages/vendor-apply-modal.js
  └─ ROUTER.init()
      └─ dynamic import app/pages/*.js
          └─ API methods
              ├─ /api/auth/* REST
              └─ POST base URL with { intent, data }
```

### Admin panel

```text
admin/*.html
  ├─ admin/config.js
  ├─ admin/shared/auth.js
  ├─ admin/shared/api.js
  ├─ admin/shared/shell.js
  └─ admin/assets/app.js / page inline scripts
      └─ APIClient
          └─ /heart/api + /admin prefix logic
              └─ Heart normalizes /heart prefix then dispatches /api/admin/*
```

### Vendor panel

```text
vendor/*.php / *.html
  ├─ window.WTG_BASE_URL injection in PHP pages
  ├─ vendor/config.js
  ├─ vendor/shared/auth.js
  ├─ vendor/shared/api.js
  ├─ vendor/shared/shell.js
  ├─ vendor/shared/realtime.js
  └─ API.intent(name, payload)
      └─ POST base URL with { intent, data }
          └─ Heart pipeline AppRouter
```

---

## 3. Backend Route Dependency Table

| Incoming Pattern | Heart Target | Downstream Dependencies | Notes |
|---|---|---|---|
| `/api/system/deploy` | `deploy-hook.php` | `migrate.php` | DevOps endpoint through Heart |
| `/api/system/migrate` | `migrate.php` | DB/migrations | Migration endpoint through Heart |
| `/api/health` | `heart/health.php` | likely DB/config | Health check |
| `/api/payment/*` | `api/payment/*.php` | `lib/PaymentService.php`, `WebhookVerifier.php`, `Database`, auth | Dynamic file mapping |
| `/api/auth/*` | `heart/api/auth/router.php` | `AuthController`, OTP handlers, JWT, DB | Auth router |
| `/api/admin/*` | `heart/api/admin/index.php` | AuthMiddleware admin role, DB, Validator, SettingsController, LedgerEngine | Very large admin dispatcher |
| `/api/delivery/*` | `heart/api/delivery/index.php` | DB/auth likely | Delivery direct route |
| `/api/orders`, `/api/vendor/orders`, etc. | `body/shopping-engine/api/orders/index.php` | OrderController, OrderService, CartService, PaymentService | Shopping order REST |
| `/api/products`, `/api/vendor/products`, etc. | `body/shopping-engine/api/products/index.php` | ProductController, ProductService | Product REST |
| `/api/cart/*` | `body/shopping-engine/api/cart/index.php` | CartController, CartService | Cart REST |
| `/api/services`, `/api/service/request`, `/api/jobs/*` | `body/service-engine/api/services/index.php` | Booking/service/job logic, Payment helper | Service REST |
| `/api/vendors`, `/api/vendor/jobs` | `body/service-engine/api/vendors/index.php` | Vendor/job logic | Vendor service REST |
| `/api/vendor/analytics/*` | Shopping analytics controller | AuthMiddleware, DB | Shopping vendor analytics |
| `/api/vendor/service-analytics/*` | Service analytics controller | AuthMiddleware, DB | Service vendor analytics |
| `/api/vendor/availability`, `/api/booking/check-slot` | `SlotController.php` | AuthMiddleware, DB | Availability and slot checks |
| `/api/feed` | `brain/BrainCore.php` | `FeedRankingEngine`, DB, optional JWT | Direct Heart-to-BrainCore feed |
| `/api/search` | `heart/api/search/index.php` | DB/search logic | Search REST |
| `/api/settings` | `core/controllers/SettingsController.php` | DB | Public settings |
| `/api/vendor/story`, `/api/feed/stories`, `/api/story/*` | `body/content-engine/api/StoryController.php` | AuthMiddleware, DB | Story/content feature |

---

## 4. Intent-to-Engine Dependency Table

| Intent Family | Heart AppRouter Mapping | Engine Target | Current Engine Implementation | Risk |
|---|---|---|---|---|
| `shopping:list_products`, `shopping:search_products` | shopping engine, optional Brain for search | `ENGINE_URLS['shopping']` | `body/shopping-engine/index.php` routes to products index | Good path, but search/list body behavior needs validation |
| `shopping:view_product` | shopping + Brain | shopping | ProductController `show()` | Likely works if data id present |
| `shopping:add_to_cart` | shopping | shopping | CartController `add()` | Needs auth contract via shopping helper |
| `shopping:create_order` | shopping | shopping | OrderController `create()` | High coupling to cart/payment/delivery |
| `shopping:list_orders` | shopping | shopping | Not mapped in `body/shopping-engine/index.php` internal switch | Likely returns default empty unless direct route used |
| `service:list_services` | service | service | Service engine rewrites to `/api/services` GET | Works by `$_SERVER` mutation |
| `service:create_booking` | service + Brain | service | Rewrites to `/api/service/request` POST | Coupled to auth/payment/slot/job |
| `service:list_bookings` | service | service | Not mapped in `body/service-engine/index.php`; vendor_bookings exists instead | Drift risk |
| `vendor:get_summary` | shopping + service | both | Shopping/service indexes do not explicitly handle `vendor:get_summary` | Likely empty/default blocks |
| `vendor:list_products`, `vendor:create_product` | shopping | shopping | Shopping index handles `vendor:list_products`? No explicit case except shopping vendor_orders/update_order | Drift risk |
| `vendor:list_orders` | shopping | shopping | Heart maps `vendor:list_orders`; shopping index handles `shopping:vendor_orders`, not `vendor:list_orders` | Drift risk |
| `vendor:list_jobs` | service | service | Service index handles `service:vendor_bookings`, not `vendor:list_jobs` | Drift risk |
| `delivery:*` | delivery | empty unless `ENGINE_DELIVERY_URL` configured | No body delivery engine folder except heart API | Often skipped |
| `video:*` | video | empty unless `ENGINE_VIDEO_URL` configured | Placeholder video engine | Not active |
| `ask_brain` | Brain only | none | BrainConnector | Works only if Brain configured and route Brain enabled |

---

## 5. Shared Service Dependencies

| Shared Service | Where | Used By | Runtime Role | Risk |
|---|---|---|---|---|
| Database | `core/helpers/Database.php` | Nearly all PHP modules | Static PDO singleton and global `getDB()` | Single shared connection, hard process exit on failure |
| Response | `core/helpers/Response.php` | REST handlers, internal engines | JSON response and internal include exception interception | `never` + exception path is powerful but hidden |
| JWT | `core/helpers/JWT.php` | AuthMiddleware, feed optional auth, auth controllers | Token encode/decode | Global `JWT_SECRET` dependency |
| AuthMiddleware | `heart/middleware/AuthMiddleware.php` | Admin/body/payment/story routes | JWT auth + RBAC + blacklist | Requires DB for blacklist every auth call |
| Logger | `core/helpers/Logger.php` | Heart, Brain, admin, errors | Filesystem logs | Reads `LOG_LEVEL`, not `HEART_LOG_LEVEL` |
| Validator | `core/helpers/Validator.php` | Admin/auth/body | Input validation | Static/helper style |
| RateLimiter | `core/helpers/RateLimiter.php` | Auth/API likely | DB-backed IP rate limiting | Separate from Heart APCu rate limiter |
| Payment | `core/helpers/Payment.php` | Service bookings, refunds | Cashfree helper | Duplicates lib payment layer |
| PaymentService | `lib/PaymentService.php` | Payment API, OrderService | Cashfree order/webhook/payment lifecycle | Also dispatches delivery |
| LedgerEngine | `core/helpers/LedgerEngine.php` | Refunds/admin/delivery cash constraints | Wallet/settlement/refund accounting | Namespace use appears inconsistent with file class declaration; needs deeper inspection |

---

## 6. Circular / Hidden Dependency Risks

| Risk | Where | Explanation |
|---|---|---|
| Heart → Body → Heart middleware | Body engines require `heart/middleware/AuthMiddleware.php` while Heart includes body engines | Direction is bidirectional at folder level |
| Core Response internal include behavior | Body engines call `Response::success()`, which throws if Heart internal flag set | Body behavior changes depending on caller context |
| Brain local include mutates request environment | BrainConnector sets `$_SERVER['REQUEST_URI']` and includes Brain | Brain code sees artificial request state, not true HTTP request |
| Engine local include mutates request environment | EngineLoader/body service rewrites `$_SERVER` | Nested includes can inherit modified URI/method |
| Payment → delivery external dispatch | PaymentService and OrderService reference SwiftDeliver env | Payment completion can trigger delivery side effects |
| Admin queries feature tables directly | Admin index queries orders, wallet, vendors, services, products, deliveries | Admin API is coupled to every schema area |

---

## 7. Dependency Graph Summary

The dependency direction intended by architecture appears to be:

```text
Frontend → Heart → Brain/Body → Core → DB/external services
```

The dependency direction observed in code is closer to:

```text
Frontend → Heart
Heart → Core + Brain + Body + API + Admin + Payment
Body → Core + Heart middleware + Payment + sometimes external delivery
Brain → Core + Brain autoload/models/controllers
Admin → Core + all feature tables + ledger/settings
Payment → Core + Lib + external Cashfree + external SwiftDeliver + orders/transactions tables
```

The observed graph has more cross-layer coupling than the naming suggests.

