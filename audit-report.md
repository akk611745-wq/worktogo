# WorkToGo Marketplace Platform — End-to-End Production Audit

Scope: static production audit across the complete workspace inventory. No source files were modified during the audit. Local PHP linting could not be executed because the Windows environment does not have PHP CLI installed, and live database comparison could not be executed because the MySQL client is not installed. The static code/schema audit is still conclusive for many production-breaking issues.

## ✅ What is fully working

1. **Workspace modules exist**: admin UI, vendor UI, customer app, auth, shopping engine, service engine, payment, alerts, brain, delivery, cron, migrations, and SQL schema are all present.
2. **Admin email/password login endpoint exists**: frontend calls `/api/auth/email/login` from `admin/shared/auth.js:20`, auth routing dispatches it through `heart/api/auth/router.php:24`, and credential verification is implemented in `heart/api/auth/AuthController.php:67`.
3. **Customer OTP backend flow is mostly implemented**: OTP send validates phone, rate-limits, stores hashed OTP, and dispatches via SMS/log providers in `heart/api/auth/send-otp.php:20`. OTP verify checks expiry/attempts, creates a user if missing, marks phone verified, and issues a JWT in `heart/api/auth/verify-otp.php:21`.
4. **JWT signing/verification exists**: HS256 encode/decode, `iat`, `nbf`, `exp`, and constant-time signature comparison are implemented in `core/helpers/JWT.php:10` and `core/helpers/JWT.php:30`.
5. **Token blacklist check exists**: revoked token hashes are checked in `heart/middleware/AuthMiddleware.php:57`, and logout inserts blacklist rows in `heart/api/auth/logout.php:14`.
6. **Shopping order creation has strong internal inventory controls**: cart checkout locks inventory rows with `FOR UPDATE`, blocks inactive/deleted products, reserves stock, splits by vendor, and releases stock on cancellation in `body/shopping-engine/api/orders/OrderService.php:66` and `body/shopping-engine/api/orders/OrderService.php:781`.
7. **Cashfree webhook handler exists**: webhook signature verification is enforced before DB work in `api/payment/webhook.php:43`, and event idempotency is attempted in `lib/PaymentService.php:157`.
8. **Admin dashboard stats route has substantive SQL**: `/api/admin/dashboard` aggregates orders, finance, delivery, system health, and alerts in `heart/api/admin/index.php:16`.
9. **Migration tracking exists at runtime**: `migrate.php:44` creates a `migrations_log` table and records applied SQL files.

## ❌ What is broken

### Critical bootstrapping and route failures

1. **All admin API endpoints are broken via the normal heart entrypoint.** `heart/index.php:180` requires `heart/api/admin/index.php` without requiring `heart/middleware/AuthMiddleware.php`. `heart/api/admin/index.php:12` immediately calls `AuthMiddleware::requireRole()`, causing a fatal class-not-found before the `try` block.
2. **Delivery API has the same missing middleware bootstrap problem.** `heart/index.php:186` requires `heart/api/delivery/index.php`, but that file calls `AuthMiddleware::requireRole()` at `heart/api/delivery/index.php:7` without loading the middleware.
3. **Service engine direct routes have wrong include paths.** `body/service-engine/api/services/index.php:14` resolves helpers under a non-existent `body/core` path because the `dirname()` depth is wrong. The same issue exists in `body/service-engine/api/vendors/index.php:12`. Direct service/vendor endpoints will fatal before logic runs.
4. **Pipeline-to-service engine is broken by the same bad includes.** `EngineLoader::callInternal()` includes engine files directly. When it includes the service engine configured by `config/engines.php:8`, the bad include in `body/service-engine/api/services/index.php:14` fails.
5. **Shopping pipeline intents do not match frontend intents.** Customer frontend sends `shopping:list_orders` in `app/js/api.js:107`, but `heart/router.php:195` has no such route. Customer booking sends `service:create_booking` in `app/js/api.js:120`, but router uses `book_service` / `service:book_service` at `heart/router.php:210`.
6. **Vendor frontend API is mostly disconnected from backend routes.** Vendor UI sends `vendor:get_summary`, `vendor:list_products`, `vendor:create_product`, `vendor:list_orders`, and `vendor:list_jobs` through `vendor/shared/api.js:57`, `vendor/shared/api.js:72`, and `vendor/shared/api.js:83`. None of those intent names exist in `heart/router.php:195`.

### Authentication flow failures

7. **Customer OTP frontend login is broken by response-shape mismatch.** Backend returns token under `data.token` inside a wrapper via `Response::success()` at `heart/api/auth/verify-otp.php:102`. Frontend expects `result.data.token` directly in `app/js/auth.js:57`, so successful OTP verification will not save the token.
8. **Customer and guest email registration can fail against the declared schema.** `heart/api/auth/AuthController.php:38` allows empty phone and inserts `phone` as `null`, but `database-structure.sql:780` declares `users.phone` as `NOT NULL`. `heart/api/auth/AuthController.php:208` inserts no phone at all for guest login, also violating the schema.
9. **Refresh-token flow is not functional.** `heart/api/auth/refresh.php:9` requires `refresh_token` and verifies it against `refresh_tokens`, but login/register/OTP flows only return access tokens in `heart/api/auth/AuthController.php:106`, `heart/api/auth/AuthController.php:53`, and `heart/api/auth/verify-otp.php:96`. No refresh token is generated or stored.
10. **Admin frontend expects a refresh token that backend never returns.** `admin/shared/auth.js:41` stores `data.refreshToken`, but backend login returns only `token`, `user`, and `admin` in `heart/api/auth/AuthController.php:113`.
11. **Role constants are inconsistent.** Service engine uses undefined `ROLE_VENDOR` in `body/service-engine/api/services/index.php:220`, `body/service-engine/api/services/index.php:296`, and `body/service-engine/api/vendors/index.php:163`. `config/app.php:29` defines `ROLE_VENDOR_SERVICE` and `ROLE_VENDOR_SHOPPING`, not `ROLE_VENDOR`.
12. **Vendor service job detail route is fatal.** `body/service-engine/api/vendors/index.php:163` calls `AuthMiddleware::requireRole()` with undefined `ROLE_VENDOR`.

### Database/schema mismatches causing endpoint failures

13. **If live DB matches `database-structure.sql`, many endpoints are guaranteed broken.** Code references columns absent from the declared schema.
14. **`services` schema is far behind service code.** Code expects `slug`, `short_desc`, `description`, `deleted_at`, `is_featured`, `rating`, and `duration_minutes` in `body/service-engine/api/services/index.php:50`, `body/service-engine/api/services/index.php:61`, and `body/service-engine/api/services/index.php:158`. Declared `services` only has `id`, `vendor_id`, `category_id`, `name`, `base_price`, `status`, and `created_at` in `database-structure.sql:706`.
15. **`vendors` schema is far behind vendor code.** Code expects `deleted_at`, `description`, `logo_url`, `type`, `total_reviews`, and `address_id` in `body/service-engine/api/vendors/index.php:47` and `body/service-engine/api/vendors/index.php:67`. Declared `vendors` has `vendor_type`, not `type`, and lacks those columns in `database-structure.sql:810`.
16. **`categories` schema does not match product category endpoint.** `body/shopping-engine/api/products/index.php:12` selects `image_url`, `module`, `is_active`, and `sort_order`; declared `categories` has `type` and `status`, not those columns, in `database-structure.sql:383`.
17. **Search endpoint has wrong product column.** `heart/api/search/index.php:53` selects `p.short_description`, but declared products use `short_desc` in `database-structure.sql:632`.
18. **Search endpoint has wrong service columns.** `heart/api/search/index.php:28` uses `s.slug`, `s.short_desc`, `s.rating`, `s.description`, `s.deleted_at`, and `s.is_featured`, absent from declared `services` in `database-structure.sql:706`.
19. **Delivery status update references missing column.** `heart/api/delivery/index.php:92` updates `deliveries.updated_at`, but declared `deliveries` has no `updated_at` in `database-structure.sql:419`.
20. **Booking slot controller references missing column.** `body/service-engine/api/BookingController.php:64` inserts `booking_slot_reservations.updated_at`, but declared table has no `updated_at` in `database-structure.sql:142`.
21. **Payment code uses missing `orders.payment_id`.** `lib/PaymentService.php:97`, `lib/PaymentService.php:175`, and `api/payment/create-order.php:111` use `payment_id`; declared orders table has `payment_ref` in `database-structure.sql:546`, not `payment_id`.
22. **Payment create-order endpoint always rejects authenticated users.** `AuthMiddleware::require()` returns payload field `user_id`; `api/payment/create-order.php:37` checks `$currentUser['id']`, which is never set, so it returns 401 even with a valid token.
23. **Payment service updates non-existent `payment_id` allow-list.** `lib/PaymentService.php:286` explicitly allows `payment_id`, which does not exist in the declared schema.
24. **Live DB schema could not be verified.** The environment has neither PHP CLI nor MySQL CLI available. `php` is not recognized, and `where mysql` found no client. Therefore exact live-vs-SQL diff is not possible from this workspace, but code-vs-declared-schema drift is extensive and production-breaking.

### Payment system failures

25. **Payment gateway configured as Cashfree, but credentials are placeholders.** `config/payment.config.php:38` loads Cashfree constants; `.env:33` sets `CASHFREE_ENV=production`, but `.env:34` and `.env:35` contain placeholder app ID and secret.
26. **Payment config loads the wrong `.env` path when used directly.** `config/payment.config.php:21` looks for `config/.env`, not the root environment file. Direct payment endpoint usage may fail config loading.
27. **Payment URLs use the wrong environment key.** `config/payment.config.php:51` reads `APP_BASE_URL`, while root environment defines `APP_URL` in `.env:9`. Return and webhook URLs therefore default to `https://yourdomain.com` unless the heart bootstrap has already set alternatives.
28. **Shopping checkout online payment path conflicts with Cashfree implementation.** `body/shopping-engine/api/orders/OrderService.php:190` calls `Payment::createOrder()` with `razorpay`, while the integrated payment service is Cashfree in `lib/PaymentService.php:27`. This is an incomplete/contradictory payment flow.
29. **Payment status endpoint is unauthenticated IDOR.** `api/payment/status.php:13` accepts any `order_id` and returns payment status with no auth/ownership check.
30. **Create payment order lacks order ownership check.** `api/payment/create-order.php:100` fetches by order ID only and does not verify the order belongs to the authenticated user.

### Admin system failures

31. **Admin frontend endpoint map lists many endpoints not implemented in admin router.** `admin/config.js:27` references user block/unblock, vendor reject/status/detail, product status/delete, services, order status/cancel/detail, deliveries, payments, system config/toggle/emergency, and logs. `heart/api/admin/index.php:16` only implements dashboard, stats, vendors list/approve, orders list, settings, users list/detail/update, deliveries assign, activity logs, and log purge.
32. **Admin role update validation uses invalid role values.** `heart/api/admin/index.php:354` allows `vendor`, but declared user roles are `vendor_service` and `vendor_shopping` in `database-structure.sql:783`.
33. **Admin dashboard query checks impossible order statuses.** `heart/api/admin/index.php:24` checks `completed` and `failed` for `orders.status`, but declared order enum does not include `completed` or `failed` in `database-structure.sql:542`.
34. **Admin logs purge path is likely wrong.** `heart/api/admin/index.php:437` computes a `logs` directory relative to project root, but no `logs` directory exists in the workspace inventory.

### Vendor/customer business flow failures

35. **Vendor registration creates pending vendors but no approval path from vendor side.** `heart/api/auth/register.php:66` creates pending vendor profiles. Products require an active vendor in `body/shopping-engine/api/products/ProductController.php:527`, so a vendor cannot list products until admin approval works. Admin approval route exists at `heart/api/admin/index.php:388`, but admin API boot is broken.
36. **Vendor service registration and service listing are incomplete.** Registration can create a `vendor_service` profile in `heart/api/auth/register.php:67`, but there is no implemented vendor service creation endpoint in `body/service-engine/api/services/index.php:46`. Only public list/detail and booking/job status exist.
37. **Vendor dashboard APIs in frontend are disconnected.** `vendor/shared/api.js:56` uses unregistered pipeline intents; backend analytics endpoints exist in `heart/index.php:236`, but vendor frontend API does not call them.
38. **Customer browse/search is broken if schema matches SQL.** Product browse can work through shopping engine if routed correctly, but unified search fails due wrong columns in `heart/api/search/index.php:53`. Service browse fails due service schema drift in `body/service-engine/api/services/index.php:50`.
39. **Customer cart flow backend exists but frontend app does not call REST cart endpoints.** Cart endpoints exist in `body/shopping-engine/api/cart/index.php:9`, but customer API layer in `app/js/api.js:85` exposes no cart functions and uses pipeline intents instead.
40. **Customer order flow frontend sends wrong intents.** `app/js/api.js:110` sends `shopping:create_order`, which does exist in `heart/router.php:203`, but order listing sends missing `shopping:list_orders` in `app/js/api.js:107`.
41. **Customer booking flow frontend sends wrong intent.** `app/js/api.js:120` sends `service:create_booking`, while backend route is `service:book_service` in `heart/router.php:210`.
42. **Business flow vendor register → list service → receive booking → get paid is not complete.** Vendor registration exists; service listing creation is missing; booking relies on broken service engine includes/schema; payment relies on broken payment/order columns.
43. **Business flow customer register → search → book → pay is not complete.** OTP backend exists but frontend token handling is broken; search schema is broken; booking route/intents mismatch; payment initiation is broken by auth payload and schema mismatches.
44. **Business flow admin manage users/vendors/bookings/payments is not complete.** Admin API boot fails, many admin endpoints are missing, and payments are schema-broken.

## ⚠️ What is incomplete or partially working

1. **Endpoint coverage is inconsistent.** The platform mixes heart REST routes in `heart/index.php:147`, pipeline intents in `heart/router.php:195`, direct shopping routers, and standalone `/api` scripts. There is no single authoritative routing layer.
2. **CORS is only partially handled.** REST preflight in `heart/index.php:132` uses wildcard origin, while pipeline CORS in `heart/router.php:42` uses configured origin. Normal REST responses do not consistently emit CORS headers.
3. **Error handling is not consistently wrapped.** Admin code has a large `try` block in `heart/api/admin/index.php:14`, but auth controller methods have no method-level `try/catch` in `heart/api/auth/AuthController.php:11`, and payment scripts use `die()` paths instead of centralized responses in `api/payment/create-order.php:23`.
4. **Migration tracking exists but is incomplete as schema governance.** `migrate.php:44` creates `migrations_log`, but `database-structure.sql:1` does not include `migrations_log`, and the migrations only alter admin auth/password values in `migrations/2026_04_25_000001_fix_admin_auth_type.sql:1`, `migrations/2026_04_26_000003_fix_admin_auth_type.sql:1`, and `migrations/2026_04_27_000001_fix_admin_password_123456.sql:1`.
5. **Frontend responsiveness is present at a basic level but not verified runtime.** Admin and vendor pages include viewport metadata such as `vendor/index.html:5`, and CSS files exist, but no browser/runtime validation was possible in this static audit.
6. **Video system is placeholder/inactive.** Routes are commented in `heart/router.php:365`, while video controller methods are placeholders in `body/video-engine/VideoController.php:14`.
7. **Delivery integration is incomplete and inconsistent.** Environment defines `SWIFTDELIVER_URL` and `SWIFTDELIVER_SECRET` in `.env:61`, but delivery code uses `SWIFTDELIVER_API_KEY` and a hardcoded Railway URL in `heart/api/delivery/index.php:36` and `heart/api/delivery/index.php:55`.
8. **Analytics endpoints exist but depend on schema assumptions.** Vendor analytics are dispatched from `heart/index.php:236`, but shopping analytics queries use statuses/columns that do not match declared product status values in `body/shopping-engine/api/VendorAnalyticsController.php:35`.

## 📡 Endpoint inventory and status

### `heart/index.php`

- `/api/system/deploy` → `deploy-hook.php`: file exists; security depends on that file.
- `/api/system/migrate` → `migrate.php`: file exists; protected by query/argv secret in `migrate.php:21`.
- `/api/health` → `heart/health.php`: file exists.
- `/api/payment/*` → dynamic file in `api/payment`: files exist, but payment logic is broken as above.
- `/api/auth/*` → `heart/api/auth/router.php`: file exists; multiple response/refresh/schema issues.
- `/api/admin/*` → `heart/api/admin/index.php`: file exists; broken due missing middleware include.
- `/api/delivery/*` → `heart/api/delivery/index.php`: file exists; broken due missing middleware include and schema mismatch.
- `/api/user/refund/request`, `/api/user/refunds`, `/api/admin/refund/approve`, `/api/admin/refund/reject`, `/api/admin/refunds` → `core/controllers/RefundController.php`: file exists; middleware included in this branch at `heart/index.php:193`.
- `/api/vendor/analytics/stats`, `/api/vendor/analytics/revenue`, `/api/vendor/analytics/funnel`, `/api/vendor/analytics/top-products` → `body/shopping-engine/api/VendorAnalyticsController.php`: file exists; schema/status assumptions likely broken.
- `/api/vendor/service-analytics/stats`, `/api/vendor/service-analytics/bookings` → `body/service-engine/api/VendorAnalyticsController.php`: file exists; schema assumptions likely broken.
- `/api/vendor/availability`, `/api/vendor/availability/{id}`, `/api/booking/check-slot` → `body/service-engine/api/SlotController.php`: file exists; uses missing `vendors.deleted_at` and missing slot timestamps.
- `/api/feed` → `brain/BrainCore.php`: file exists; optional JWT decode uses env secret at `heart/index.php:286`.
- `/api/search` → `heart/api/search/index.php`: file exists; SQL columns wrong.
- `/api/settings` → `core/controllers/SettingsController.php`: file exists.
- `/api/vendor/story`, `/api/feed/stories`, `/api/story/{id}/view` → `body/content-engine/api/StoryController.php`: file exists.
- Pipeline fallback POST intents → `heart/router.php`: file exists; route registry incomplete for frontend intent names.

### `heart/api/auth/router.php`

- `POST /api/auth/login` → 307 redirect to `/api/auth/email/login` at `heart/api/auth/router.php:10`.
- `POST /api/auth/register` → `heart/api/auth/register.php`: file exists; vendor/customer register backend partially works but schema and vendor approval issues remain.
- `POST /api/auth/logout` → `heart/api/auth/logout.php`: file exists; blacklist storage exists.
- `GET /api/auth/me` → `heart/api/auth/me.php`: file exists; depends on middleware include inside file.
- `POST /api/auth/refresh` → `heart/api/auth/refresh.php`: file exists; broken because refresh tokens are never issued.
- `POST /api/auth/otp/send` → `heart/api/auth/send-otp.php`: backend implemented.
- `POST /api/auth/otp/verify` → `heart/api/auth/verify-otp.php`: backend implemented; frontend response mismatch.
- `POST /api/auth/email/register` → `heart/api/auth/AuthController.php:11`: file exists; schema issue with nullable phone.
- `POST /api/auth/email/login` → `heart/api/auth/AuthController.php:67`: file exists; used by admin/vendor.
- `POST /api/auth/google` → `heart/api/auth/AuthController.php:121`: file exists; schema issue if phone required.
- `GET /api/auth/guest` → `heart/api/auth/AuthController.php:204`: file exists; schema issue with missing phone.

### Admin endpoints in `heart/api/admin/index.php`

Implemented but currently unreachable due missing middleware bootstrap: `/api/admin/dashboard`, `/api/admin/stats`, `/api/admin/vendors`, `/api/admin/orders`, `/api/admin/settings`, `/api/admin/settings/{key}`, `/api/admin/users`, `/api/admin/users/{id}`, `/api/admin/vendors/{id}/approve`, `/api/admin/deliveries/assign`, `/api/admin/logs/activity`, and `/api/admin/logs`. Many frontend-configured endpoints are absent.

### Shopping endpoints

- Product router `body/shopping-engine/api/products/index.php:9`: `/api/products/categories`, `/api/vendor/products`, `/api/products`, `/api/products/{id}`, `/api/vendor/product/variation/{id}/stock`, `/api/product/{id}`. Category route is schema-broken.
- Cart router `body/shopping-engine/api/cart/index.php:9`: `/api/cart`, `/api/cart/add`, `/api/cart/remove`, `/api/cart/update`. Backend exists; customer frontend lacks cart calls.
- Order router `body/shopping-engine/api/orders/index.php:9`: `/api/order/create`, `/api/orders/{id}/cancel`, `/api/vendor/orders/{id}/status`, `/api/vendor/orders`, `/api/orders`, `/api/orders/{id}`. Backend exists; payment and frontend intent mismatches remain.

### Service endpoints

- `body/service-engine/api/services/index.php:46`: `/api/services`, `/api/services/{id}`, `/api/service/request`, `/api/service/bookings`, `/api/service/bookings/{id}`, `/api/jobs/{id}/status`. Direct include path and schema are broken.
- `body/service-engine/api/vendors/index.php:39`: `/api/vendors`, `/api/vendors/{id}`, `/api/vendor/jobs`, `/api/vendor/jobs/{id}`. Direct include path, schema, and undefined role are broken.

## 🔒 Security issues found

1. **Production secrets are committed in root environment file.** Database credentials, JWT secret, admin key, internal key, and migration secret are present in `.env:15`, `.env:28`, `.env:56`, `.env:57`, and `.env:66`.
2. **Migration endpoint is remotely callable with query secret.** `heart/index.php:152` exposes `/api/system/migrate`; `migrate.php:22` accepts secret via query string, which can leak in logs/history.
3. **Admin password migration sets a known weak hash.** `migrations/2026_04_27_000001_fix_admin_password_123456.sql:2` sets a hardcoded bcrypt hash. The filename claims `123456`, while the hash is a known sample hash commonly used for `password`.
4. **Payment status endpoint leaks order payment state.** `api/payment/status.php:13` has no authentication or ownership check.
5. **Payment creation lacks ownership enforcement.** `api/payment/create-order.php:100` fetches any order by ID.
6. **CORS wildcard on REST preflight.** `heart/index.php:133` allows `*`, while the rest of the app tries to use configured CORS in `heart/router.php:42`.
7. **JWT stored in localStorage across apps.** Admin and vendor store bearer tokens in localStorage in `admin/shared/auth.js:40` and `vendor/shared/auth.js:9`, increasing impact of XSS.
8. **OTP fallback logs OTP values.** Missing SMS provider credentials fall back to logging OTP in `heart/api/auth/send-otp.php:89` and `heart/api/auth/send-otp.php:144`. This is acceptable only in local/dev, but production placeholders make fallback likely.
9. **Default JWT secret fallback exists.** `config/app.php:34` falls back to `change-me-in-production`, dangerous if environment load fails.
10. **Direct scripts have inconsistent bootstrap and auth assumptions.** Payment scripts include middleware but not always full config/bootstrap, making security behavior dependent on server routing.

## 🧹 Code quality issues

1. **Schema drift is the dominant quality failure.** Declared schema in `database-structure.sql` does not match service, vendor, category, delivery, booking, payment, and search code.
2. **Response shapes are inconsistent.** `core/helpers/Response.php:11` wraps data, `body/shopping-engine/helpers/shopping.helpers.php:7` uses a different wrapper, and auth controller sometimes returns raw arrays through `core/helpers/Response.php:22`.
3. **Routing is fragmented.** Routes are spread across `heart/index.php:147`, `heart/router.php:195`, `heart/api/auth/router.php:15`, `api/payment/create-order.php:1`, and engine routers.
4. **Frontend API layers are duplicated and divergent.** Admin uses `admin/shared/api.js:6`, vendor uses `vendor/shared/api.js:7`, and customer uses `app/js/api.js:6`, all with different assumptions.
5. **No autoloader or unified bootstrap.** Many files rely on constants and classes being loaded elsewhere, causing direct vs routed behavior differences.
6. **Dead/inactive code exists.** Video routes are commented in `heart/router.php:365`, and video controllers are placeholders in `body/video-engine/VideoController.php:14`.
7. **Inconsistent naming.** Backend roles use `customer`, `vendor_service`, and `vendor_shopping` in `config/app.php:29`, while customer frontend roles use `user`, `vendor`, and `creator` in `app/js/config.js:56`.
8. **Inconsistent payment abstractions.** Cashfree service exists in `lib/PaymentService.php:27`, but order checkout calls `Payment::createOrder()` with Razorpay in `body/shopping-engine/api/orders/OrderService.php:190`.
9. **Migrations do not represent real schema evolution.** Current migrations only update admin auth/password values, not the many missing columns required by code.
10. **Potential N+1 issues remain in analytics/admin.** Admin dashboard runs many separate aggregate queries in `heart/api/admin/index.php:16`. Some shopping order N+1 issues are explicitly addressed in `body/shopping-engine/api/orders/OrderService.php:627`.

## 📋 Priority fix list

1. **Stop production exposure of secrets immediately.** Rotate DB password, JWT secret, admin key, internal key, migration secret, and Cashfree credentials exposed in `.env`.
2. **Create a single bootstrap/autoload layer and require it everywhere.** Ensure `AuthMiddleware`, config constants, DB, logger, response helper, and JWT are always loaded before any route/controller.
3. **Fix admin/delivery API bootstrap.** Add proper middleware loading before `heart/api/admin/index.php:12` and `heart/api/delivery/index.php:7` execute.
4. **Decide the real schema and reconcile code to it.** Either update `database-structure.sql` and migrations for missing columns or refactor queries to existing columns. This must cover `services`, `vendors`, `categories`, `orders`, `deliveries`, `booking_slot_reservations`, and `users`.
5. **Add real migrations for all schema changes and include `migrations_log` in the canonical schema.** Current migration coverage is not adequate.
6. **Fix auth response shapes and refresh-token issuance.** Customer frontend `app/js/auth.js:55` and backend `heart/api/auth/verify-otp.php:102` must agree; login/register must issue refresh tokens or refresh route must be removed.
7. **Fix payment flow end-to-end.** Standardize on Cashfree or another gateway, fix `payment_ref` vs `payment_id`, fix auth payload in `api/payment/create-order.php:37`, add order ownership checks, and verify webhook DB updates in `lib/PaymentService.php:157`.
8. **Make route registry match frontend API calls.** Align customer `app/js/api.js`, vendor `vendor/shared/api.js`, admin `admin/config.js`, and `heart/router.php:195`.
9. **Fix service engine include paths and undefined role constants.** Correct `body/service-engine/api/services/index.php:14`, `body/service-engine/api/vendors/index.php:12`, and remove/replace undefined `ROLE_VENDOR` usages.
10. **Harden admin system.** Implement missing admin endpoints configured in `admin/config.js:20`, fix role validation in `heart/api/admin/index.php:354`, and verify all pages use real endpoints.
11. **Normalize CORS.** Replace wildcard preflight in `heart/index.php:133` with configured origin handling matching `heart/router.php:42`, and emit CORS headers for all REST responses.
12. **Protect standalone scripts.** Lock down `migrate.php`, `deploy-hook.php`, `diag.php`, and payment status scripts; avoid query-string secrets.
13. **Remove hardcoded/default credentials and weak admin migrations.** Remove hardcoded admin password migrations like `migrations/2026_04_27_000001_fix_admin_password_123456.sql:1`.
14. **Unify response contracts.** Standardize on one JSON structure across `core/helpers/Response.php`, shopping helpers, auth, payment, and frontend clients.
15. **Run runtime tests in a proper PHP/MySQL environment.** PHP CLI and MySQL CLI were unavailable locally, so final production validation must include route smoke tests, SQL migration dry-run, and live schema diff.

## Final verdict

This platform is **not production-ready** in its current state. Admin APIs, service engine, customer frontend auth, vendor frontend APIs, payment initiation/webhook DB updates, and multiple business-critical flows are broken or incomplete. The most severe root causes are **schema drift**, **fragmented routing/bootstrap**, **frontend-backend contract mismatch**, and **exposed production secrets**.
