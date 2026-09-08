# Phase 10 — Security Boundary Map

Scope: documentation-only trace of the active WorkToGo project under `wtg  live/`. No hardening, optimization, architecture changes, or runtime behavior changes were performed.

## Executive Reality

WorkToGo security boundaries are real but fragmented. The strongest boundary is the shared JWT verification path in `AuthMiddleware`, but enforcement depends on each route/controller choosing the right helper. Admin routes consistently require admin role, while several high-risk public or webhook surfaces intentionally bypass user auth and rely on secrets, signatures, or no auth. The largest boundary weakness is not a single missing check; it is inconsistent enforcement models across direct REST routes, Heart pipeline intents, legacy controllers, webhooks, cron scripts, frontend local token state, and external delivery/payment callbacks.

## Boundary Map

```mermaid
flowchart TD
  Browser[Customer/Vendor/Admin Browser] -->|Bearer JWT localStorage| HeartIndex[heart/index.php REST dispatcher]
  Browser -->|POST intent JSON| HeartRouter[heart/router.php pipeline]
  HeartIndex --> AuthRouter[/api/auth routes]
  HeartIndex --> AdminAPI[/api/admin/*]
  HeartIndex --> ShoppingAPI[Shopping cart/orders/products]
  HeartIndex --> ServiceAPI[Services/bookings/jobs]
  HeartIndex --> DeliveryAPI[Delivery APIs]
  HeartIndex --> PaymentWebhook[Cashfree webhook]
  Cashfree[Cashfree] -->|HMAC timestamp+raw body| PaymentWebhook
  SwiftDeliver[SwiftDeliver] -->|X-Webhook-Secret| DeliveryWebhook[/api/delivery/status-update]
  Cron[cron/auto_assign.php] -->|local CLI/file lock| DB[(MySQL)]
  AdminAPI -->|ROLE_ADMIN| DB
  ShoppingAPI -->|mixed AuthMiddleware + se_require_auth| DB
  ServiceAPI -->|role checks + ownership checks| DB
  DeliveryAPI -->|ROLE_DELIVERY/ADMIN except webhook| DB
```

## Major Findings

### 1. JWT boundary is signature-checked but stateless role claims can drift

- WHERE: `AuthMiddleware::resolveToken()` validates JWT via `JWT::decode()`, requires `user_id` and `role`, and checks `token_blacklist`; admin/vendor/customer roles are read from token claims.
- WHY: Role authorization is claim-based at request time, not DB-revalidated per request.
- ROOT CAUSE: Token payload stores role and only blacklist DB is consulted, not current user status/role.
- CONNECTED SYSTEMS: Auth login/register, admin user role changes, vendor approval, all protected APIs.
- SECURITY IMPACT: Existing tokens can retain old roles until expiry or blacklist. Admin approval explicitly returns `require_relogin`, implying role refresh requires user action.
- PERFORMANCE IMPACT: Every authenticated request performs an extra DB lookup against `token_blacklist`.
- SCALE IMPACT: Auth throughput is bounded by DB availability and blacklist query/index health.
- FUTURE RISK: Role changes, suspensions, vendor deactivation, and emergency revocation are not guaranteed to take immediate effect unless tokens are revoked.
- Evidence: `wtg  live/heart/middleware/AuthMiddleware.php:57-97`, `wtg  live/core/helpers/JWT.php:30-67`, `wtg  live/heart/api/admin/index.php:480-500`.
- Runtime dependency: `JWT_SECRET`, `token_blacklist`, MySQL.
- Confidence: High.

### 2. Admin boundary is broad and admin bypass exists globally

- WHERE: `AuthMiddleware::requireRole()` returns immediately for role `admin`; `/api/admin/*` requires admin once at file entry.
- WHY: Admin is treated as universal superuser.
- ROOT CAUSE: Central middleware has admin bypass, and admin API has many mutation endpoints in one monolithic file.
- CONNECTED SYSTEMS: Users, vendors, orders, deliveries, products, payments, settings, system config, logs.
- SECURITY IMPACT: A single admin token can mutate role/status/config/order/payment surfaces. There is no traced scoped admin permission model.
- PERFORMANCE IMPACT: Admin dashboards run many aggregate queries and list endpoints.
- SCALE IMPACT: Admin-heavy use can cause large table scans and lock contention on operational tables.
- FUTURE RISK: Hidden admin exposure becomes severe if admin token is stolen from browser storage.
- Evidence: `wtg  live/heart/middleware/AuthMiddleware.php:28-47`, `wtg  live/heart/api/admin/index.php:12-13`, `wtg  live/heart/api/admin/index.php:441-477`, `wtg  live/heart/api/admin/index.php:853-892`.
- Runtime dependency: Admin JWT token, browser localStorage/admin client, MySQL.
- Confidence: High.

### 3. Auth enforcement consistency differs by module

- WHERE: Customer order routes use `AuthMiddleware::requireRole()`, vendor order routes use `se_require_auth()` then resolve vendor ID, service routes use mixed `AuthMiddleware::require()`, `requireRole()`, and ownership checks, booking controller directly accepts `user_id` from body.
- WHY: Multiple generations of controllers coexist.
- ROOT CAUSE: Shared middleware exists, but not every controller consistently uses it.
- CONNECTED SYSTEMS: Shopping orders/cart, service bookings, slot reservations, vendor operations.
- SECURITY IMPACT: Strong routes are role/ownership-scoped; weaker routes can trust request body identity or use role-agnostic authentication.
- PERFORMANCE IMPACT: Multiple helper stacks duplicate auth queries and error behavior.
- SCALE IMPACT: Inconsistent patterns make future horizontal/API expansion error-prone.
- FUTURE RISK: New endpoints may copy weaker patterns.
- Evidence: `wtg  live/body/shopping-engine/api/orders/OrderController.php:27-57`, `wtg  live/body/shopping-engine/helpers/shopping.helpers.php:72-100`, `wtg  live/body/service-engine/api/BookingController.php:9-18`, `wtg  live/body/service-engine/api/services/index.php:204-237`.
- Runtime dependency: Authorization header, `vendors` ownership resolution, DB.
- Confidence: High for traced files; uncertain for untraced legacy endpoints.

### 4. Payment webhook boundary has signature and timestamp checks, but failure handling depends on logs

- WHERE: `/api/payment/webhook.php` is public and verifies Cashfree HMAC before DB work; `WebhookVerifier` rejects stale timestamps over 5 minutes; `PaymentService::processWebhook()` locks order row and checks amount.
- WHY: Webhooks must be public but authenticated by Cashfree signature.
- ROOT CAUSE: Server-to-server trust is HMAC-bound; processing errors are often acknowledged with HTTP 200.
- CONNECTED SYSTEMS: Cashfree, orders, transactions, alerts, SwiftDeliver dispatch.
- SECURITY IMPACT: Tampering protection exists. Replay window exists but event ledger uniqueness is not fully traced beyond paid-status/transaction checks.
- PERFORMANCE IMPACT: Webhook processing performs DB transaction, row locks, transaction lookup, optional dispatch HTTP call after commit.
- SCALE IMPACT: Payment spike can create contention on orders/transactions and dispatch calls.
- FUTURE RISK: HTTP 200 on processing failures requires operational monitoring or failures become silent.
- Evidence: `wtg  live/api/payment/webhook.php:43-49`, `wtg  live/lib/WebhookVerifier.php:32-60`, `wtg  live/lib/PaymentService.php:183-285`, `wtg  live/api/payment/webhook.php:88-94`.
- Runtime dependency: `CASHFREE_SECRET_KEY`, MySQL, logs, optional SwiftDeliver env.
- Confidence: High.

### 5. Delivery webhook boundary is secret-based but lacks traced replay/event ledger

- WHERE: `/api/delivery/status-update` bypasses delivery/admin JWT and validates `X-Webhook-Secret` against `SWIFTDELIVER_SECRET`.
- WHY: External delivery service must call back server-to-server.
- ROOT CAUSE: Shared secret authentication only; no traced timestamp, nonce, event table, or monotonic transition guard.
- CONNECTED SYSTEMS: SwiftDeliver, deliveries, orders, admin dashboard, customer/vendor state.
- SECURITY IMPACT: Secret compromise allows direct order/delivery status mutation.
- PERFORMANCE IMPACT: Simple DB updates per webhook.
- SCALE IMPACT: Duplicate/out-of-order callbacks can thrash state and pollers.
- FUTURE RISK: Replay/idempotency weakness under unreliable networks.
- Evidence: `wtg  live/heart/api/delivery/index.php:115-173`.
- Runtime dependency: `SWIFTDELIVER_SECRET`, MySQL.
- Confidence: High.

### 6. File/media upload boundary checks extension and size, not traced MIME/content inspection

- WHERE: Story upload requires vendor/admin role, resolves active vendor, creates `uploads/stories`, checks extension list and file size, then moves uploaded file.
- WHY: Vendor stories support images/videos.
- ROOT CAUSE: Upload validation is extension/size-based in traced controller.
- CONNECTED SYSTEMS: Vendor roles, `vendors`, `stories`, public `/uploads/stories/` media serving.
- SECURITY IMPACT: If web server allows executing uploaded content or MIME spoofing bypasses expectations, uploaded media can become risky. Exact server execution protections for uploads were not traced.
- PERFORMANCE IMPACT: Large videos up to 10 MB hit PHP upload and disk writes.
- SCALE IMPACT: Local filesystem storage is a single-node capacity and horizontal scaling boundary.
- FUTURE RISK: Media growth creates storage, CDN, malware scanning, and backup concerns.
- Evidence: `wtg  live/body/content-engine/api/StoryController.php:10-79`.
- Runtime dependency: local filesystem write permissions, web server static serving, `uploads/stories`.
- Confidence: Medium-high; upload serving rules uncertain.

## Partially Traced / Uncertain Security Boundaries

- Upload execution behavior under web server is uncertain; `.htaccess` for uploads was not traced.
- Full frontend token storage patterns were inherited from prior phases and visible open tabs, but not fully re-read in this phase.
- Some product/vendor controllers and legacy auth files were not exhaustively re-read in this phase; findings focus on traced critical surfaces.
- Production web server header forwarding and CORS behavior depend on `APP_URL`, host config, and proxy behavior.
- Database indexes/constraints supporting blacklist, alerts, order rate limit, and reservation uniqueness were not fully revalidated from schema in this phase.

## Boundary Summary

| Boundary | Current Reality | Main Failure Mode | Confidence |
|---|---|---|---|
| JWT auth | HS256 + expiry + blacklist DB lookup | Stale role/status claims, DB auth dependency | High |
| Admin | Single admin role with global bypass | Broad token blast radius | High |
| Vendor | Role + vendor lookup in stronger routes | Inconsistent helper use across legacy routes | High |
| Payment webhook | HMAC + timestamp + amount checks | Silent processing failures / partial idempotency | High |
| Delivery webhook | Shared secret | Replay/out-of-order status mutation | High |
| Uploads | Role + extension + size | MIME/execution/storage uncertainty | Medium |
| Cron | Local file lock | Single-node assumption, no distributed lock | High |

