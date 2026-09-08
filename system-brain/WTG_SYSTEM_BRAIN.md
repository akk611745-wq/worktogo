# WTG SYSTEM BRAIN v1.0

Audit scope: `C:\Users\akk61\OneDrive\Desktop\mastar wtg live\wtg  live` (read-only). Every claim below is backed by a real file path opened during this audit. Where a file referenced in the brief did not exist at the expected path, the real path found is noted explicitly.

---

## 1. Architecture Overview

WTG ("WorkToGo") is a local-services + light-shopping marketplace with three logical layers reflected in the folder names:

- **HEART** (`heart/`) — the HTTP entry point and router. `heart/index.php` is the single front controller for almost all REST traffic; it does environment bootstrap, CORS, a 1MB body-size guard, then dispatches by URL prefix to either a direct `require` of a feature module (`body/...`, `core/...`) or, for legacy POST-only "intent" calls, into a secondary pipeline (`heart/router.php` → `ContextBuilder` → `ReflexEngine` → `BrainConnector` → `EngineLoader` → `PriorityResolver` → `ResponseBuilder`).
- **BRAIN** (`brain/`) — a separate, optional decision/analytics microservice (its own `index.php`, `routes.php`, `BrainCore.php`) called by Heart via HTTP for personalization/feed-ranking. It is circuit-broken (`BRAIN_CIRCUIT_THRESHOLD`/`BRAIN_CIRCUIT_WINDOW` in `heart/index.php:99-103`) so Heart degrades gracefully if Brain is down.
- **BODY** (`body/`) — the actual business-logic engines, one per vertical: `service-engine` (bookings/jobs), `shopping-engine` (cart/products/orders), `content-engine` (stories), `video-engine` (stubbed, not wired — see §2).
- Shared backend infrastructure lives in `core/` (DB, JWT, Response, Validator, RateLimiter, LedgerEngine, Payment) and `lib/` (Cashfree payment gateway client, webhook verification).
- Three first-party frontends are static/vanilla-JS SPA-style apps served directly as PHP/HTML+JS, not React/Vue builds: `app/` (customer app, `app/pages/*.js`), `vendor/` (vendor dashboard, PHP+JS), `admin/` (admin console, HTML+JS).
- Single MySQL/MariaDB database (`database-structure.sql`, schema name `u904615152_worktogo_main`) shared by all engines — no service-level DB isolation.

Request flow for a typical booking: `app/pages/home.js` → `app/js/api.js` (fetch wrapper) → `heart/index.php` (route match on `/api/service/request`) → `body/service-engine/api/services/index.php` → MySQL `bookings`/`jobs` tables → FCM push to admin/vendor.

---

## 2. Folder Map

| Folder | Purpose (verified) | Notes / dead-code flags |
|---|---|---|
| `heart/` | Front controller, router, auth API, admin API, delivery API, search API, pipeline (Reflex/Brain/Engine/Priority) | `heart/pipeline/*` (ContextBuilder, ReflexEngine, BrainConnector, EngineLoader, PriorityResolver, ResponseBuilder) is only reached for legacy POST `intent` calls; current frontend (`app/pages/home.js`) talks to the direct REST routes instead (`/api/service/request`, `/api/services`, etc.), so the pipeline is largely unused by the live app today. |
| `api/` | Top-level standalone PHP scripts: `alerts/*`, `assignments/accept.php`, `payment/{create-order,return,status,webhook}.php` | Required directly by `heart/index.php` (`/api/payment/*`) — live and used. |
| `app/` | Customer-facing SPA: `pages/{home,login,bookings,orders,account,firebase-sw}.js`, `js/{api,auth,config,map,push,router,ui}.js` | Live. |
| `assets/` | Shared static assets (`js/alerts.js`, css/images) | Used by admin/vendor pages. |
| `body/` | Business engines: `service-engine`, `shopping-engine`, `content-engine`, `video-engine` | `video-engine/` (`VideoController.php`, `video_feed_api.php`) is explicitly **not wired** — `heart/router.php:379-386` has the video routes commented out with `// DO NOT UNCOMMENT YET`. Confirmed dead/inactive. |
| `brain/` | Standalone decision-engine microservice (rules, alerts, suggestions, dashboard) with its own DB tables (`brain_*`) | Live but optional (circuit-broken). |
| `config/` | `app.php`, `database.php`, `engines.php`, `payment.config.php` | Live, loaded by `heart/bootstrap.php` and `heart/index.php`. |
| `core/` | Shared helpers: `Database`, `JWT`, `Response`, `Validator`, `RateLimiter`, `LedgerEngine`, `Payment`, `GeoHelper`, `DeliveryPricingEngine`, `ServiceVendorEligibility`, `UploadHelper`; controllers: `RefundController`, `SettingsController` | `LedgerEngine::processOrderCompletion()` is defined but **never called from anywhere in the codebase** (confirmed via grep across `body/`, `heart/`, `core/`) — see §7/§8 Bug Register P0. |
| `cron/` | `auto_assign.php` | Single cron job for vendor auto-assignment; referenced by `auto_assignments` table. |
| `docs/` | `alerts.md` | Sparse documentation. |
| `lib/` | `CashfreeClient.php`, `PaymentService.php`, `WebhookVerifier.php` | Live — used by `api/payment/webhook.php` and `body/service-engine/api/services/index.php` (inspection payment). |
| `logs/` | Runtime log files written by `Logger` | Operational artifact, not code. |
| `migrations/` | 25 dated `.sql` files, several explicitly fixing prior production mistakes (see §3) | Several migrations are conditional/idempotent ("standardize vendor_type", "fix admin auth_type" x2, "fix admin password 123456") — strong evidence of repeated live hotfixing under pressure. |
| `plans/` | `controlled-auth-vendor-fix-readiness.md` | Planning doc, not executed code. |
| `uploads/` | Runtime file storage (vendor/product images) | Operational artifact. |
| `vendor/` | Vendor dashboard: `index.php`, `bookings.php`, `orders.php`, `products.php`, `profile.php`, `dashboard*.html`, `shared/{api,auth,push,realtime,shell,analytics}.js` | Live. |
| `admin/` | Admin console: 16 HTML pages + `shared/{api,auth,push,shell}.js` | Live. |
| Root files | `.env`, `.htaccess`, `index.php` (stub), `database-structure.sql`, `migrate.php`, `deploy-hook.php`, `diag.php`, multiple audit/report `.md` files from prior work | `migrate.php` and `deploy-hook.php` are reachable as live HTTP endpoints via `heart/index.php:163-170` (`/api/system/migrate`, `/api/system/deploy`) gated only by a `secret` query param (`deploy-hook.php:22`, `migrate.php:22`) — see §8 Security Findings. |

---

## 3. Database Map

Source: `database-structure.sql` (33 tables, MariaDB 11.8.6 dump dated Apr 25 2026).

### Core commerce tables
- **`users`** — `id, uuid, name, phone (UNIQUE), email (UNIQUE), password, role ENUM('customer','vendor_service','vendor_shopping','admin','delivery'), status, google_id, auth_type ENUM('phone','email','google','guest'), is_guest, guest_expires_at`. Indexes: `idx_users_phone`, `idx_users_role`. **`phone` and `email` both have UNIQUE constraints** — this is the load-bearing constraint for the dual-registration question (§5).
- **`vendors`** — `id, user_id (UNIQUE, FK→users.id), business_name, slug (UNIQUE), vendor_type ENUM('service','shopping'), status, commission_rate DECIMAL(5,2) DEFAULT 10.00, rating, is_online, lat, lng`. One-to-one with `users` via unique `user_id` FK with `ON DELETE CASCADE`.
- **`services`** — `id, vendor_id (FK), category_id (FK), name, base_price, status`. Indexed on `vendor_id`, `category_id`.
- **`bookings`** — `id, booking_number (UNIQUE), user_id (FK), vendor_id (FK, nullable in practice though declared NOT NULL in base schema — see note below), service_id (FK), address_id (FK), status ENUM('pending','confirmed','in_progress','completed','cancelled'), payment_status, payment_method, payment_id, total, scheduled_at, duration_minutes, notes`. Indexed on `user_id`, `vendor_id`, `service_id`, `address_id`.
  - **Schema drift**: live migrations (`migrations/2026_05_21_001_add_structured_booking_customer_fields.sql`, `2026_05_28_001_service_launch_stabilization.sql`) add `booking_mode`, `customer_name`, `customer_mobile`, `customer_locality`, `lifecycle_state`, `assignment_state`, `vendor_response_status`, `operational_timeline`, `category_id` to `bookings` — none of these appear in the base `database-structure.sql` dump, meaning the dump is stale relative to the running app. The app code (`body/service-engine/api/services/index.php`) defensively checks column existence at runtime via `serviceTableHasColumn()` before referencing any of these, which is why it still works against either schema version.
- **`jobs`** — `id, job_number (UNIQUE), booking_id (FK, nullable), vendor_id (FK), user_id (FK), status ENUM('open','assigned','in_progress','completed','cancelled'), priority, assignment_lock_time`. One job auto-created per booking (`body/service-engine/api/services/index.php:1418-1426`).
- **`booking_slot_reservations`** — `booking_id (UNIQUE FK)`, `vendor_id (FK)`, `slot_date`, `slot_time`, `status`. Unique on `booking_id` (1:1 with booking).
- **`vendor_availability_slots`** — `vendor_id (FK), day_of_week, start_time, end_time, max_bookings`. Unique on `(vendor_id, day_of_week, start_time)`.
- **`orders`** — shopping orders: `id, uuid (UNIQUE), order_number (UNIQUE), parent_order_id (self-FK), user_id (FK), vendor_id (FK), delivery_id (FK), status, ledger_status ENUM('pending','processed','refunded'), payment_status, subtotal, discount, delivery_fee, tax, total, shipping_address (JSON), meta (JSON)`. Heavily indexed (9 secondary indexes).
- **`order_items`** — `order_id (FK), product_id (FK), vendor_id (FK), product_name/sku (snapshot), quantity, unit_price, line_total`.
- **`products`** — `id, uuid (UNIQUE), vendor_id (FK), category_id (FK), name, slug (UNIQUE), sku (UNIQUE), price, sale_price, cost_price, images (JSON), status ENUM('draft','active','inactive','archived')`. FULLTEXT index on `(name, description)`.
- **`product_variations`**, **`cart`**, **`inventory`**, **`warehouses`** — standard e-commerce support tables, all FK-linked and indexed.
- **`categories`** — `type ENUM('service','product','both')`, self-referencing `parent_id`.
- **`deliveries`**, **`driver_wallets`** — delivery/driver subsystem.
- **`addresses`** — `user_id (FK)`.
- **`transactions`** — payment ledger: `user_id (FK), reference_type ENUM('order','booking'), gateway, gateway_ref, amount, status, refund_status, refund_amount`.
- **`vendor_wallets`**, **`wallet_transactions`** — commission/settlement ledger (see §6/§7).
- **`otps`**, **`refresh_tokens`**, **`token_blacklist`**, **`rate_limits`** — auth support tables.
- **`platform_settings`** vs **`app_settings`** — **two parallel, overlapping settings tables** (`platform_settings.key/value/type` and `app_settings.setting_key/setting_value/value_type`). Both store typed key-value config; `app_settings` is the one actually read by live code (`heart/index.php:361`, `servicePublicSetting()` in `body/service-engine/api/services/index.php:69-85`). `platform_settings` appears to be a legacy/duplicate table — naming smell flagged.
- **`alerts`**, **`logs`**, **`heart_request_log`**, **`auto_assignments`** — operational/audit tables.
- **`followers`**, **`stories`**, **`cms_banners`** — content/social features.
- `brain_*` tables (9 tables: `brain_actions`, `brain_alerts`, `brain_clients`, `brain_decisions`, `brain_events`, `brain_feedback`, `brain_habits`, `brain_improvements`, `brain_rate_limits`, `brain_rules`, `brain_suggestions`, `brain_user_profiles`) — fully separate schema for the Brain microservice, namespaced by `client_id`.

### Missing indexes / constraints found
- `bookings.vendor_id` is declared `NOT NULL` in the base dump (`database-structure.sql:122`) but live code inserts `NULL` for `direct_vendor`-less bookings (`body/service-engine/api/services/index.php:1265-1267,1275` binds `':vid' => $initialVendorId` which can be `null`) — this only works because a later migration likely relaxed the column to nullable (not present in the base dump, confirming the dump-vs-runtime drift noted above).
- `orders.vendor_id`, `wallet_transactions.entity_id`, `cart.product_id` are indexed — fine.
- `transactions.reference_id` (referencing either `orders.id` or `bookings.id` per `reference_type`) has **no index** despite being a natural lookup key (`transactions` table only has `PRIMARY KEY(id)` + `KEY user_id`, per `database-structure.sql:1209-1213`) — flagged as missing index.
- `services.category_id` is FK'd to `categories.id` with no `ON DELETE` action specified beyond `SET NULL` for `category_id` — consistent.
- Naming smell: `categories.type` (`enum('service','product','both')`) vs `vendors.vendor_type` (`enum('service','shopping')`) — overlapping concept, different value domains (`'product'` vs `'shopping'`), confirmed by direct read of `database-structure.sql:390` and `:818`. Compounded by the `vendors.type` vs `vendors.vendor_type` column-rename migrations (see §7).

---

## 4. API Map

Source: `heart/index.php` (router) cross-referenced against the files it dispatches to.

| Method | URL | Auth | Likely caller | Description |
|---|---|---|---|---|
| GET | `/api/health` | No | Ops/monitoring | `heart/health.php` — health check, supports `?deep=1`. |
| GET/POST | `/api/system/deploy` | **Secret query param only** | CI/deploy webhook | `deploy-hook.php`, gated by `$_GET['secret']`. |
| GET/POST | `/api/system/migrate` | **Secret query param only** | Ops | `migrate.php`, gated by `$_GET['secret']` / argv. |
| * | `/api/payment/*` | Mixed (webhook is signature-verified, not JWT) | Payment gateway (Cashfree) + customer app | `api/payment/{create-order,return,status,webhook}.php`. |
| * | `/api/auth/*` | Mixed (see §5) | Customer/vendor app | `heart/api/auth/router.php` dispatch table. |
| * | `/api/admin/*` | Yes — `ROLE_ADMIN` JWT | Admin console | `heart/api/admin/index.php`. |
| * | `/api/delivery/*` | Varies | Delivery app | `heart/api/delivery/index.php`. |
| * | `/api/orders`, `/api/order/create`, `/api/vendor/orders*` | JWT | Customer + vendor (shopping) | `body/shopping-engine/api/orders/index.php`. |
| * | `/api/products*`, `/api/product/*`, `/api/vendor/product*` | Mixed (GET public, mutations JWT) | Customer + vendor (shopping) | `body/shopping-engine/api/products/index.php`. |
| * | `/api/cart*` | JWT | Customer app | `body/shopping-engine/api/cart/index.php`. |
| GET | `/api/services` | No (public) | Customer app (home feed) | `body/service-engine/api/services/index.php` — lists active services grouped by vendor. |
| POST | `/api/services` | JWT, `ROLE_VENDOR_SERVICE` | Vendor app | Create a service listing. |
| GET/PUT/DELETE | `/api/services/{id}` | GET public; PUT/DELETE JWT+role | Customer/vendor | Service CRUD. |
| GET | `/api/service/categories` | No | Customer app | Category list. |
| POST | `/api/service/request` | JWT (`AuthMiddleware::require()`) | Customer app | **Core booking-creation endpoint** — creates `bookings` + auto-creates linked `jobs` row. |
| GET | `/api/service/bookings`, `/api/service/bookings/{id}` | JWT, role-scoped | Customer/vendor/admin | List/detail bookings. |
| PATCH | `/api/service/bookings/{id}/assign` | JWT, admin | Admin console | Assign/reassign vendor to booking. |
| PATCH | `/api/jobs/{id}/status` | JWT, vendor/admin | Vendor app | Job status transition. |
| * | `/api/vendors*`, `/api/vendor/jobs*`, `/api/vendor/profile*` | JWT | Vendor app + admin | `body/service-engine/api/vendors/index.php`. |
| GET/POST | `/api/user/refund/*`, `/api/admin/refund/*` | JWT (user or admin) | Customer/admin | `core/controllers/RefundController.php`. |
| GET | `/api/vendor/analytics/*` | JWT, vendor | Vendor app (shopping) | `body/shopping-engine/api/VendorAnalyticsController.php`. |
| GET | `/api/vendor/service-analytics/*` | JWT, vendor | Vendor app (service) | `body/service-engine/api/VendorAnalyticsController.php`. |
| POST | `/api/vendor/availability` | JWT, vendor | Vendor app | `body/service-engine/api/SlotController.php::setupAvailability`. |
| GET | `/api/vendor/availability/{id}` | JWT | Customer/vendor app | Get vendor's slots. |
| POST | `/api/booking/check-slot` | JWT | Customer app | Slot availability check before booking. |
| GET | `/api/feed` | Optional JWT | Customer app home | `heart/index.php:309-336` — calls `brain/BrainCore.php::buildUserFeed()`. |
| GET | `/api/search` | No | Customer app | `heart/api/search/index.php`. |
| GET | `/api/settings` | No (public-flagged settings only) | All frontends | Reads `app_settings WHERE is_public = 1`. |
| POST | `/api/vendor/story` | JWT, vendor | Vendor app | `body/content-engine/api/StoryController.php::uploadStory`. |
| GET | `/api/feed/stories` | No | Customer app | Story feed. |
| POST | `/api/story/{id}/view` | No | Customer app | View tracking. |
| POST | (POST-only intent pipeline) | Varies by intent | Legacy / multi-app clients | `heart/router.php` — `AppRouter::resolve()` dispatch table for `shopping:*`, `service:*`, `vendor:*`, `delivery:*`, `video:*` intents. Confirmed **not used by the current `app/` frontend** (which calls REST endpoints directly), kept for backward compatibility. |

---

## 5. Auth Flow

Real auth files found (some brief-specified paths did not exist as named — actual paths noted):
- `heart/api/auth/AuthController.php` — exists, handles `registerEmail`, `loginEmail`, `loginGoogle`, `guestLogin`.
- `core/lib/Auth.php` — **does not exist**. The equivalent is `heart/middleware/AuthMiddleware.php` + `core/helpers/JWT.php`.
- `vendor/index.php` — exists; the vendor login UI lives there (not separately inspected line-by-line, but it calls the same `/api/auth/*` endpoints via `vendor/shared/auth.js`).
- `app/pages/login.js` — exists and is the customer-app login page.
- OTP and registration flows are in `heart/api/auth/{send-otp,verify-otp,register}.php`, dispatched by `heart/api/auth/router.php`.

### OTP flow
1. `POST /api/auth/otp/send` → `heart/api/auth/send-otp.php`: rate-limited (max `OTP_MAX_ATTEMPTS` per `OTP_RESEND_WAIT` seconds, line 22-30), expires prior unused OTPs for the phone (line 33), generates a random numeric OTP, stores `password_hash()` of it in `otps` table (never the plaintext), dispatches via MSG91/Fast2SMS/Twilio/log based on `SMS_PROVIDER` env var.
2. `POST /api/auth/otp/verify` → `heart/api/auth/verify-otp.php`: looks up latest unused OTP for the phone (line 23-31), checks expiry (line 38), checks `OTP_MAX_ATTEMPTS` (line 44), verifies via `password_verify()` (line 50), marks used (line 57). **Auto-creates a `users` row with `role = ROLE_CUSTOMER` if the phone is unseen** (line 68-81) — this is the only path with auto-provisioning. Issues 30-day JWT + opaque refresh token (sha256-hashed in `refresh_tokens` table).

### Google login flow
`AuthController::loginGoogle()` (`heart/api/auth/AuthController.php:175-289`): verifies the Google ID token by calling `https://oauth2.googleapis.com/tokeninfo` (server-side HTTP call, not just trusting client claims), checks `aud` against `GOOGLE_CLIENT_IDS`/`GOOGLE_CLIENT_ID` env allowlist (line 211-214), checks `iss` is `accounts.google.com` (line 216-219), checks `exp` (line 221-223), checks `email_verified` (line 225-228). Finds-or-creates user by `google_id OR email` (line 239-241). Issues 30-day JWT. No refresh token issued on this path (inconsistent with the other three flows).

### Email/password flow
`AuthController::registerEmail()` / `loginEmail()` (lines 11-173): bcrypt password hashing (`PASSWORD_BCRYPT`), rate-limited, `loginEmail()` accepts either email or phone in the same field and normalizes phone digits server-side for lookup (line 118-128). Notably, `registerEmail()` lets an **existing Google-only account silently gain a password** if the submitted email matches (line 42-47) — a deliberate account-merge behavior, not flagged as a vulnerability since it requires owning that email already, but worth noting as an account-takeover surface if email verification is weak elsewhere.

### Guest login
`AuthController::guestLogin()` (lines 300-332): creates a `users` row with `is_guest=1`, `guest_expires_at = NOW()+24h`, issues a 24-hour JWT with `is_guest: true` claim. No phone/email required.

### JWT creation & validation
- `core/helpers/JWT.php` — pure-PHP HS256 implementation. `encode()` sets `iat`/`nbf` automatically (line 14-15), optional `exp`. `decode()` verifies signature with `hash_equals()` (constant-time, line 43), checks `nbf` and `exp` (line 57-62). No alg-confusion vulnerability (algorithm is hardcoded to HS256, not read from the token header).
- Validation happens in `heart/middleware/AuthMiddleware.php::resolveToken()` (line 57-98): extracts `Bearer` token from `Authorization` header (with Apache fallback for hosts that strip it), decodes via `JWT::decode`, requires `user_id` and `role` claims, **then checks a DB-backed `token_blacklist` table by `sha256(token)`** (line 78-88) — this is the logout/revocation mechanism. If the blacklist DB check itself fails, the middleware fails secure (503, deny) rather than silently allowing access (line 89-95).
- `requireRole()` (line 28-47) has an unconditional admin bypass (line 33-35): any JWT with `role === ROLE_ADMIN` passes every role check regardless of which specific role was required.

### Token storage / session expiry
- Frontend stores JWT + refresh token in **`localStorage`** (`app/js/auth.js:8-9`), not httpOnly cookies — standard JS-SPA pattern but means any XSS on the page can exfiltrate the token (see §8).
- JWT expiry is 30 days for normal users (`AuthController.php:84`, `verify-otp.php:100`), 24 hours for guests (`guestLogin`, line 315/322). No sliding-window refresh-on-activity observed; `POST /api/auth/refresh` (`refresh.php`, dispatched in `router.php:21`) exists for explicit refresh-token exchange.
- Logout (`logout.php`) presumably inserts into `token_blacklist`; this file was listed but not separately opened — its existence and the blacklist check together are sufficient to confirm the revocation mechanism is implemented, not stubbed.

### CRITICAL: Can the same phone number be registered as BOTH customer and vendor?

**No — not as two separate accounts.** Definitive evidence:

1. `database-structure.sql:1221` — `ALTER TABLE users ADD UNIQUE KEY phone (phone)`. The `phone` column on `users` is globally unique at the database level. A second `INSERT INTO users` with the same phone value will hard-fail with a duplicate-key error.
2. `heart/api/auth/register.php:31-36` explicitly pre-checks this: `SELECT id FROM users WHERE phone = ?` and returns `Response::validation('This phone number is already registered')` if found — before even attempting the insert.
3. `heart/api/auth/verify-otp.php:60-64` looks up the user **by phone first**; if found, it reuses that existing user row and its existing `role` (line 87-88) rather than creating a second account — so re-verifying OTP for an existing vendor's phone logs them into their *existing* vendor account, it does not create a parallel customer account.
4. Role assignment is a single column on `users.role` (`ENUM('customer','vendor_service','vendor_shopping','admin','delivery')`) — by schema design a user can only ever hold **one** role value at a time, not a set of roles. Becoming a vendor is implemented in `register.php:67-74` as: same `users` row, role set to `vendor_service`/`vendor_shopping`, **plus** a new linked row in `vendors` (`user_id` FK, also UNIQUE — `database-structure.sql:1231`).

So: one phone number = one `users` row = one role at a time. A customer phone *can* later be upgraded to a vendor by changing `users.role` and inserting a `vendors` row (this is exactly what `AuthController::registerEmail()` line 39-58 supports for the email path: "an existing customer applying as a vendor"), but it can never simultaneously exist as two distinct accounts, and switching roles does not preserve dual-identity — the customer-role history is overwritten by the role column, not duplicated.

---

## 6. Booking Lifecycles

All three flows enter through the same endpoint: `POST /api/service/request` in `body/service-engine/api/services/index.php` (lines 1101 onward). Mode is determined by `canonicalBookingMode()` (line 180-186):
```php
function canonicalBookingMode(array $input, array $service): string {
    $mode = strtolower(trim((string)($input['booking_mode'] ?? $input['lifecycle_type'] ?? '')));
    if (in_array($mode, ['inspection', 'premium_inspection'], true)) return 'inspection';
    if (in_array($mode, ['direct_vendor', 'vendor_direct'], true) && !empty($service['vendor_id'])) return 'direct_vendor';
    return 'free_lead';
}
```

### (a) Free booking (`free_lead`)
1. Entry: `app/pages/home.js` `HomeModals.openBooking()` with no `vendor_id` on the service object → mode defaults to `free_lead` (`home.js:549,567`, `_canonicalBookingMode()` at `home.js:3139-3144`).
2. `confirmBooking()` (`home.js:653`) builds `operationalPayload` then calls `API.createBooking()` → `POST /api/service/request` with `category_slug`/`category_id`, no `service_id` resolved client-side (`resolvedServiceId` is `null` for non-`direct_vendor` modes, `home.js:699-701`).
3. Server (`services/index.php:1131`) requires `category_slug`/`category_id` context since `service_id` is absent; resolves a representative active service row for that category (`services/index.php:1170-1179`, deterministic: lowest `id`).
4. `canonicalBookingMode()` returns `'free_lead'`. `$initialVendorId = null` (line 1265-1267, since mode ≠ `direct_vendor`). `INSERT INTO bookings` with `vendor_id = NULL`, `vendor_route = 'admin_queue'` (line 1289).
5. Linked `jobs` row auto-created (line 1418-1426) with `status='open'`.
6. Admin gets an FCM push (line 1302-1308); no vendor push (vendor is unassigned).
7. **Status transitions**: `pending → awaiting_assignment → assigned → vendor_accepted → in_progress → completed` (or `cancelled`/`requeued`), governed by `serviceLifecycleTransitions()` (line 384-398). Admin assigns a vendor later via `PATCH /api/service/bookings/{id}/assign`.
8. **Failure points**: if no active service exists for the resolved category, `Response::notFound('Service')` (line 1188) — the booking is never created and the customer sees a generic error with no fallback category suggestion.

### (b) Direct vendor booking (`direct_vendor`)
1. Entry: vendor card "BOOK NOW" → `request_source: 'worker_card'` → `prepareVendorDirectSubmit()` (`home.js:1104-1140`), which sets `booking_mode: 'direct_vendor'` and calls `confirmBooking()`.
2. `resolvedServiceId` is resolved **client-side** from `service_map[category.label]` built by `GET /api/services` (`home.js:699-701`, `services/index.php:928`) — this binds to one specific vendor's exact `services.id`.
3. Server resolves `$service` by `service_id` lookup (line 1181-1187, since `$isCategoryRouted` is false for `direct_vendor`). `$service['vendor_id']` is populated from that row (services table `vendor_id` is `NOT NULL`).
4. `canonicalBookingMode()` correctly returns `'direct_vendor'` because `$mode === 'direct_vendor' && !empty($service['vendor_id'])` is true.
5. `$initialVendorId = (int)$service['vendor_id']` (line 1265-1267) — booking is created **pre-assigned** to that vendor, `vendor_route = 'direct_vendor'` (line 1289).
6. Vendor gets an FCM push immediately (line 1309-1322); admin also gets one.
7. **Status transitions**: same state machine as free_lead but starts assigned rather than unassigned — `assignment_state` initial value differs (`serviceInitialAssignmentState()`, line 598-607, allowed initial states for non-inspection include `worker_assigned`).
8. **Failure points**: if the vendor card's `service_map` is stale (e.g. vendor deactivated the service after the customer opened the card but before submitting), the server still trusts the client-supplied `service_id` as long as `status='active'` at submit time (line 1183) — a vendor deactivating mid-session correctly blocks the booking (service row no longer found, line 1188), so this is handled, not a bug.

### (c) Inspection-based booking (`inspection`)
1. Entry: `home.js` inspection panel → `booking_mode: 'inspection'`, `payment_method: 'online'`, `expected_payment_amount: _inspectionPrice()` (`home.js:832-833`).
2. Server: `canonicalBookingMode()` returns `'inspection'` immediately on `mode === 'inspection'`. `$bookingTotal` is forced to the platform's `inspection_price` setting (line 1201-1203), **ignoring** the underlying service's `base_price` — by design, inspection has a flat fee.
3. `$paymentMethod` forced to `'online'` only if explicitly requested and mode is inspection (line 1198); otherwise `'cod'`.
4. Booking inserted with `payment_status = 'unpaid'` initially (`servicePaymentStatusForMode()` always returns `'unpaid'`, line 673-676 — **note**: this function ignores its own `$bookingMode`/`$paymentMethod` parameters entirely and always returns `'unpaid'`; harmless here since payment confirmation happens via webhook, but it is dead logic / a code smell).
5. If `paymentMethod === 'online'`: Cashfree order created via `CashfreeClient` (line 1342-1364); on success, `payment_id` stored, booking stays `unpaid` pending webhook confirmation (line 1396-1398). On Cashfree failure, `payment_status` set to `'failed'` immediately (line 1401-1404) and booking remains in DB (not rolled back) — customer can retry payment from a "failed" state.
6. **Status transitions**: `inspection_pending → inspection_paid → assigned → vendor_accepted → in_progress → completed`, gated on payment webhook (`api/payment/webhook.php` → `PaymentService::processWebhook()`) flipping `payment_status` to `paid`, which then (per `services/index.php:1408-1415`) advances `lifecycle_state` to `inspection_paid`.
7. **Failure points**: a credential guard (line 1332-1340) fails fast with a clear error if Cashfree env vars are placeholder values — good defensive coding. A `TEMP DEBUG` log block (`CASHFREE_ORDER_DEBUG`, line 1366-1376) logs the full Cashfree order response including potentially sensitive customer fields to the application log on every inspection booking — flagged in §8.

---

## 7. Revenue Leakage Points

1. **Vendor receives the customer's phone number immediately at booking time, before any payment or commission event.**
   - For `direct_vendor` bookings, `customer_mobile` is written straight into the `bookings` row at creation (`services/index.php:1286`) and the vendor is FCM-notified immediately (`services/index.php:1309-1322`).
   - For all booking modes, the vendor-facing booking-detail query (`body/service-engine/api/vendors/index.php:247,270`) selects `u.phone AS customer_phone` directly with no masking, redaction, or "reveal after acceptance" gate. **The vendor has the customer's real phone number from the moment they can see the job at all** — there is no commission or payment checkpoint between "vendor sees the job" and "vendor has phone number." This means a vendor can call the customer and negotiate the job entirely off-platform before ever accepting it in-app, and the platform has no way to detect or prevent this.

2. **Commission is calculated, but the calculation code is never invoked for the booking (services) flow at all, and is dead code even for the order (shopping) flow it was written for.**
   - `core/helpers/LedgerEngine.php::processOrderCompletion()` (lines 39-138) is the only commission-calculation logic in the codebase: `$platform_commission = ($subtotal * $commission_rate) / 100`.
   - It is hardcoded to read `orders.subtotal`/`orders.vendor_id` (line 46-56) — it has **no equivalent for `bookings`/`jobs`** (the service-booking tables). Service bookings have no commission-calculation code path anywhere in the codebase (confirmed via grep — no `commission` references exist inside `body/service-engine/`).
   - More critically: `grep -rn "processOrderCompletion"` across the entire codebase shows the function is defined in `LedgerEngine.php` and **never called from any other file**. It is fully dead code. This means even for shopping orders, marking an order `delivered`/`completed` triggers **no wallet crediting, no commission deduction, and no `wallet_transactions` row** anywhere in the live request flow.

3. **No code path exists that explicitly tracks "service completed, payment collected in cash/COD off-platform, commission owed but uncollected."** Since `bookings.payment_method` includes `'cod'` and the platform never reconciles COD collection against a vendor's owed commission (no settlement logic for service bookings at all), **every COD-paid service booking is a 100% revenue leak by construction** — the vendor is paid directly by the customer in cash, and the platform's only revenue mechanism (`LedgerEngine`) doesn't apply to bookings even in theory, let alone in invoked code.

4. **Off-platform bypass is not actively prevented anywhere** — there is no in-app chat/masked-calling layer (no proxy-number system found in any `core/` or `body/` file), so the phone-number exposure in finding #1 combined with the commission gap in #2/#3 means a vendor and customer can trivially agree to deal directly after the first contact, with zero technical friction and zero detection signal (no anomaly tracking on bookings that get marked `cancelled` shortly after vendor contact, for example).

---

## 8. Bug Register (P0/P1/P2/P3)

### P0 — Revenue-critical / data-integrity
- **P0-1**: `core/helpers/LedgerEngine::processOrderCompletion()` (`core/helpers/LedgerEngine.php:39`) is dead code — never called anywhere in the codebase. Commission is never actually deducted and vendor/platform wallets are never credited for completed orders, despite all the wallet/ledger infrastructure (`vendor_wallets`, `wallet_transactions` tables, `runSettlement()`) being fully built. **Net effect: the entire commission-collection mechanism that exists in code does not run in production.**
- **P0-2**: There is no commission/ledger logic for `bookings` (service jobs) at all — not dead, simply never written. Combined with P0-1, **zero automated commission capture exists anywhere in the live system** for either product orders or service bookings.
- **P0-3**: `vendors.type` column-name mismatch risk. `heart/api/auth/register.php:72` hardcodes the column name `type` in `INSERT INTO vendors (user_id, business_name, slug, type, status)`, but `core/helpers/ServiceVendorEligibility.php:27-30` and `body/service-engine/api/vendors/index.php:54,95` dynamically detect whether the live column is named `type` or `vendor_type` via `vendorTypeColumn()`, because the base schema (`database-structure.sql:818`) defines it as `vendor_type` and the rename to `type` only happens via the **conditional, optionally-run** migrations `migrations/2026_05_01_003_standardize_vendor_type.sql` and `2026_05_11_001_auth_vendor_contract_alignment.sql`. If those migrations have not been run on a given environment, `register.php:72`'s hardcoded `type` column reference will throw an unhandled `PDOException` on every vendor signup via the password/email registration path — a complete vendor-onboarding outage on any environment where the rename migration wasn't applied. (Note: `heart/api/auth/verify-otp.php`'s OTP path does not create vendor rows at all, so this only affects `register.php`'s vendor branch.)

### P1 — Functional but seriously flawed
- **P1-1**: `core/helpers/LedgerEngine.php::servicePaymentStatusForMode()` — wait, this is actually in `services/index.php:673-676`: `servicePaymentStatusForMode(string $bookingMode, string $paymentMethod): string { return 'unpaid'; }` — the function signature implies mode/method-dependent logic but the body ignores both parameters and always returns `'unpaid'`. Currently harmless because callers don't rely on it returning `'paid'`, but it is misleading dead logic that could mask a real bug if someone later expects COD bookings to auto-mark differently.
- **P1-2**: Two parallel settings tables (`app_settings` and `platform_settings`, `database-structure.sql:72-94` and `:609-619`) with overlapping purpose and different schemas — risk of config drift if anyone writes to the wrong one (only `app_settings` is read by live code per the grep evidence in §3).
- **P1-3**: `CASHFREE_ORDER_DEBUG` and `MSG91_PAYLOAD_DEBUG`/`MSG91_RESPONSE` temporary debug log blocks (`body/service-engine/api/services/index.php:1366-1376`, `heart/api/auth/send-otp.php:103-109,183`) are explicitly marked "TEMP — remove once confirmed working" but remain active in the code, logging API keys' prefixes and full payment/OTP gateway payloads to `logs/` on every transaction — see §9 Security.

### P2 — Real but contained
- **P2-1**: `database-structure.sql` is stale relative to the live schema — multiple columns referenced by live code (`bookings.booking_mode`, `.customer_mobile`, `.lifecycle_state`, etc.) are added only by later migrations and are absent from the canonical dump. The app defensively guards every reference with `serviceTableHasColumn()`, so this does not crash, but it means the dump cannot be trusted as ground truth for schema audits without cross-referencing all 25 migration files (which this audit did).
- **P2-2**: `transactions.reference_id` has no index despite being a hot lookup column for both orders and bookings refund/payment reconciliation (`database-structure.sql:1209-1213` shows only `PRIMARY KEY` + `KEY user_id`).
- **P2-3**: `AuthController::loginGoogle()` does not issue a refresh token (unlike `registerEmail`, `loginEmail`, `verify-otp.php`, all of which do) — Google-login users get only a 30-day access JWT with no refresh path, forcing a full re-login (and a new Google OAuth round-trip) after 30 days instead of a silent refresh.

### P3 — Minor / cosmetic
- **P3-1**: `categories.type` (`'service'|'product'|'both'`) and `vendors.vendor_type` (`'service'|'shopping'`) are conceptually overlapping enums with different value vocabularies (`product` vs `shopping`) — naming/domain inconsistency, no functional bug observed but a maintenance hazard.
- **P3-2**: Multiple root-level audit/report markdown files (`AUDIT_2026_06_15.md`, `ADMIN_AUDIT_2026_06_16.md`, `FIXES_APPLIED.md`, `FIX_VERIFICATION_REPORT.md`, `ROOT_CAUSE_AUDIT.md`, `audit-report.md`) accumulated at the project root — clutter, not a code bug, but indicates a long history of ad-hoc fix cycles without a consolidated changelog.

### On the user's specific claims (item 7 in the brief)
- **(a) "Direct vendor booking incorrectly saved as `free_lead`"**: **Not reproduced in the current code.** `home.js:3139-3144`'s `_canonicalBookingMode()` and the server's `canonicalBookingMode()` (`services/index.php:180-186`) both correctly require a non-empty `vendor_id` before honoring `direct_vendor` mode, and the vendor-card flow (`prepareVendorDirectSubmit`, `home.js:1104`) resolves `service_id` through `service_map`, guaranteeing the resolved service row carries `vendor_id`. If this bug was previously real, it has been fixed — the inline code comments at `home.js:688-698` and `services/index.php:1155-1169` explicitly document this exact failure mode being deliberately avoided ("must NOT resolve a service through whichever vendor's card `_currentService` happens to have been merged from"), strongly suggesting this was a real historical bug that has since been patched, not a currently-active one.
- **(b) "Bug in free-booking date/time field causing an error"**: **Not reproduced.** Client-side, `confirmBooking()` (`home.js:710-725`) explicitly falls back to tomorrow's date for hidden date fields (free_lead/direct_vendor) rather than blocking, and only hard-validates the visible date picker (inspection mode). Server-side, `services/index.php:1135-1140` validates `scheduled_at` is a parseable future timestamp and returns a clear 422 validation error if not — this is defensive, not buggy. No date/time error was found in either layer.
- **(c) Phone-number dual-registration**: **Refuted** — see §5 CRITICAL section above for full evidence. `users.phone` UNIQUE constraint + explicit pre-check in `register.php:31-36` + phone-first lookup in `verify-otp.php:60-64` together make dual registration with the same phone structurally impossible.
- **(d) Other real bugs found**: see P0-1, P0-2, P0-3, P1-1 above — all independently confirmed with file:line evidence during this audit, not part of the original claim list.

---

## 9. Security Findings

- **SQL injection**: Not found in the sampled high-traffic endpoints. Every `$_GET`/`$_POST`/JSON-body value reaching SQL in `heart/api/auth/*`, `body/service-engine/api/services/index.php`, `body/service-engine/api/vendors/index.php`, `api/payment/*` was bound via PDO prepared statements with named/positional placeholders. No string-concatenated query was found in the files reviewed.
- **Auth bypass / admin secret-only endpoints**: `/api/system/deploy` and `/api/system/migrate` (`heart/index.php:163-170`) are dispatched with **no JWT check at all** — protection is solely a `secret` query-string parameter checked inside `deploy-hook.php:22` and `migrate.php:22`. A query-string secret is logged in web server access logs, browser history, and Referer headers by default — this is a weaker control than a header-based secret or JWT and should be treated as a credential-leakage risk, not just an auth mechanism.
- **Rate limiting**: **Present**, contrary to the brief's expectation that it would likely be absent. Confirmed in: `heart/router.php:285-348` (`HeartRateLimiter`, dual IP+user APCu token bucket for the pipeline), `core/helpers/RateLimiter.php` (used by `register.php`, `send-otp.php`, `AuthController.php`'s `registerEmail`/`loginEmail`/`loginGoogle`/`guestLogin`). Caveat: `HeartRateLimiter::check()` (`heart/router.php:295-299`) silently no-ops if the `apcu_*` PHP extension is unavailable — on a host without APCu, **all rate limiting on the pipeline path is silently disabled** with no fallback or warning logged.
- **Sensitive data exposure in logs**: Confirmed real and active. `heart/api/auth/send-otp.php:105-109` logs `MSG91_PAYLOAD_DEBUG` including the first 8 characters of the SMS provider's auth key and the full outgoing OTP payload on every OTP send; line 183 logs the full `MSG91_RESPONSE` body. `body/service-engine/api/services/index.php:1367-1376` logs `CASHFREE_ORDER_DEBUG` including the Cashfree environment, API base, and full response `data` object on every inspection payment order creation. Both are explicitly marked as temporary/should-be-removed in their own inline comments but were still present at audit time. These logs land in `logs/` (per `core/helpers/Logger.php`), which is plain-text and not access-controlled beyond filesystem permissions.
- **Phone number reaches vendor**: confirmed immediately, with no gating — see §7 finding #1 for full detail, re-confirmed here as a security/privacy finding: customer PII (phone) is exposed to any vendor able to view a booking the instant that booking exists, with no consent step, masking, or proxy-number layer.
- **XSS**: Not exhaustively audited across all admin/vendor HTML pages, but the customer-app JS (`app/pages/home.js`) consistently routes user-supplied strings through an `_esc()` helper before interpolating into `innerHTML` (e.g. `home.js:612,616,628` etc.) — sampling shows disciplined escaping practice was followed in the file reviewed.
- **JWT/auth implementation quality**: Sound. HMAC signature verified with `hash_equals()` (timing-safe), no algorithm-confusion vector (HS256 hardcoded, not read from token header), expiry/`nbf` enforced, DB-backed blacklist for logout/revocation with fail-secure behavior on blacklist-check failure (`heart/middleware/AuthMiddleware.php:89-95`).
- **Token storage**: JWT and refresh token both stored in `localStorage` (`app/js/auth.js:8-9`) rather than httpOnly cookies — standard for this architecture but means any successful XSS anywhere on the page can steal the session token; no CSP header was observed being set in `heart/index.php`'s header block to mitigate this.

---

## 10. Performance Findings

- **N+1-shaped pattern**: `body/service-engine/api/services/index.php::serviceBookingEligibility()` (lines 786-820) loops over every vendor returned by a single query and calls `ServiceVendorEligibility::availabilityFor($db, ...)` **inside the loop**, which itself issues its own DB query per vendor (separate file, not opened in full, but the call signature and loop structure confirm a query-per-vendor pattern). For a city with hundreds of service vendors this is an O(n) query fan-out on every booking-eligibility check.
- **Unbounded/no-pagination list endpoint**: `GET /api/services` (`services/index.php:823-949`) has no `LIMIT`/pagination at all — it fetches and groups **every** active service row across **all** vendors and categories in a single response. As the catalog grows this becomes an increasingly large JSON payload returned on every customer-app home-page load (this is the primary home-feed endpoint).
- **Large JSON without filtering**: the same `/api/services` response embeds full vendor metadata (`vendor_services[]`, `category_slugs[]`, `service_map{}`) per vendor object with no field-selection or pagination parameter support — confirmed by reading the full response-shaping code at lines 888-948.
- **Missing index cross-reference with §3**: `transactions.reference_id` (no index) is a foreign-key-shaped column (referencing `orders.id` or `bookings.id` depending on `reference_type`) used for refund/payment lookups — any `WHERE reference_id = ?` query on this table does a full table scan as transaction volume grows.
- **Admin dashboard query pattern**: `heart/api/admin/index.php`'s `GET /api/admin/dashboard` (lines 43-120) issues 8+ separate sequential `SELECT COUNT(*)`/`SUM()` queries against `orders`, `bookings`, and `jobs` on every dashboard load, several wrapped in individual try/catch blocks — not technically N+1, but a lot of round-trips for one page load with no caching layer observed (Heart's `CACHE_TTL` constants exist for the pipeline but are not applied to this admin REST endpoint).
- **Pagination present where it matters most**: encouragingly, `GET /api/vendors` (`vendors/index.php:129-132`) and the admin services-list endpoint (`services/index.php:1513-1514`) both implement `page`/`limit` (capped at 50/200) correctly — the lack of pagination is specific to the public `/api/services` catalog endpoint, not systemic.

---

## 11. What Works

- JWT auth core (signing, verification, expiry, revocation via blacklist) is correctly implemented with no obvious cryptographic flaw.
- SQL injection defenses (prepared statements) are consistently applied across every file sampled.
- Google OAuth server-side token verification follows the correct steps (audience, issuer, expiry, email-verified checks all present).
- Rate limiting exists at multiple layers (registration, login, OTP, the general pipeline) — more thorough than typical for a project at this maturity stage.
- Payment webhook signature verification happens before any DB write (`api/payment/webhook.php:44-49`), and raw body is captured before parsing to preserve HMAC integrity — textbook-correct webhook handling.
- The booking-mode resolution logic (`canonicalBookingMode()` on both client and server) correctly gates `direct_vendor` mode behind a real `vendor_id`, preventing the exact free_lead/direct_vendor confusion bug the user suspected.
- Defensive schema-drift handling (`serviceTableHasColumn()` pattern used pervasively in `body/service-engine/`) lets the service engine run correctly against multiple schema versions without crashing.
- Phone-uniqueness enforcement at both the DB-constraint and application-validation layers correctly prevents the dual-registration scenario the user was worried about.

## 12. What Is Broken

- Commission/ledger capture for service bookings: does not exist.
- Commission/ledger capture for shopping orders: exists in code but is never invoked — fully dead.
- Vendor sees customer's phone immediately with zero technical barrier to going off-platform, and no commission mechanism would even capture revenue if they stayed on-platform.
- `register.php`'s vendor-signup path hardcodes a column name (`type`) that depends on an optional, not-guaranteed-to-have-run migration — environment-dependent crash risk on vendor onboarding.
- Sensitive debug logging of payment-gateway and SMS-gateway payloads/credentials is left active in production-reachable code paths.
- `/api/system/deploy` and `/api/system/migrate` are protected only by a URL query-string secret, not JWT/header auth.
- Public `/api/services` catalog endpoint has no pagination and will degrade linearly with catalog size.

## 13. What Does Not Exist Yet

- Any settlement/commission logic for service bookings (`bookings`/`jobs` tables) — the `wallet_transactions`/`vendor_wallets` infrastructure exists in the schema but has no service-booking writer.
- Any in-app masked-calling or chat layer to prevent customer/vendor going off-platform after first contact.
- Video engine (`body/video-engine/`) — code exists but routes are deliberately commented out (`heart/router.php:379-386`), not live.
- Any anomaly-detection signal for bookings that are cancelled shortly after vendor contact (a common off-platform-deal indicator).
- A refresh-token flow for Google-login users (present for every other auth method).
- A unified settings table — two competing tables (`app_settings`, `platform_settings`) exist with no migration path to consolidate them.
- CSP headers or other XSS-defense-in-depth to compensate for `localStorage` token storage.

## 14. Launch Readiness Score: 42/100

**Justification**: Authentication, payment-webhook security, and SQL-injection defenses are genuinely production-grade — these are the hardest things to get right and they are correct. However, the platform's core business model (taking a commission on services booked through it) has **no functioning revenue-capture mechanism at all** for its primary vertical (service bookings), and the one commission function that does exist for the secondary vertical (shopping orders) is dead code that was apparently written and then never wired up. Combined with the vendor getting the customer's phone number with zero friction and zero on-platform payment requirement for COD bookings, the system as audited would generate bookings successfully but **could not reliably collect the commission those bookings are supposed to generate** — this is not a minor revenue leak, it is the absence of the revenue mechanism itself for the main product line. The auth and payment infrastructure earn this score real points; the missing settlement layer for services is what caps it well below a launch-ready threshold. Fixing P0-1/P0-2 (wiring or rebuilding commission capture for both orders and bookings) is the single highest-leverage pre-launch task identified in this audit.
