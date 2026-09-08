# Phase 10 — Attack Surface Analysis

Scope: mapped attack surfaces from traced runtime routes, controllers, webhooks, frontend polling, cron, and external integrations. Documentation only.

## Attack Surface Inventory

| Surface | Entry Point | Auth Boundary | Mutation Capability | Key Risk |
|---|---|---|---|---|
| Auth login/register | `/api/auth/*` | Public credentials / OAuth / guest | Creates users/tokens | brute force, token lifecycle drift |
| Admin APIs | `/api/admin/*` | `ROLE_ADMIN` JWT | Broad platform mutation | admin token compromise, overreach |
| Customer orders/cart | `/api/cart`, `/api/orders` | Customer/admin JWT | cart/order/inventory reservation | abuse via order/cart writes |
| Vendor order/service APIs | `/api/vendor/*`, `/api/services`, `/api/jobs/*` | vendor/admin JWT + vendor lookup | products/services/jobs/orders | ownership drift, abuse by vendor |
| Payment webhook | `/api/payment/webhook` | Cashfree HMAC | payment/order/transaction mutation | replay/idempotency/log-only failures |
| Delivery webhook | `/api/delivery/status-update` | shared secret | delivery/order status mutation | replay/out-of-order/secret leakage |
| Story upload | `/api/vendor/story` | vendor/admin JWT | filesystem + story rows | uploaded media abuse/storage exhaustion |
| Alerts polling | `/api/alerts/fetch.php` | JWT | read alerts | polling amplification |
| Heart pipeline | `heart/router.php` fallback POST | JSON + optional auth passed downstream | dispatches engines/brain/cache | synchronous fanout and cache ambiguity |
| Cron assignment | `cron/auto_assign.php` | local CLI/file lock | jobs/orders/alerts/assignments | single-node operational abuse/failure |

## High-Value Findings

### A1 — Hidden admin exposure through broad single-role authority

- WHERE: Admin API enters with one `AuthMiddleware::requireRole(ROLE_ADMIN)` check and then exposes user role edits, vendor approval, order status changes, delivery assignment, log purging, product archival, payments listing, and system config writes.
- WHY: Admin endpoints are centralized and superuser-only.
- ROOT CAUSE: No traced granular admin permission model.
- CONNECTED SYSTEMS: users, vendors, orders, deliveries, payments, logs, system_config.
- SECURITY IMPACT: Admin token compromise enables platform-wide changes.
- PERFORMANCE IMPACT: Admin dashboard queries aggregate across large operational tables.
- SCALE IMPACT: Admin list/aggregate endpoints become expensive as tables grow.
- FUTURE RISK: Adding more admin functions increases blast radius.
- Evidence: `wtg  live/heart/api/admin/index.php:12-13`, `wtg  live/heart/api/admin/index.php:441-477`, `wtg  live/heart/api/admin/index.php:480-604`, `wtg  live/heart/api/admin/index.php:634-892`.
- Confidence: High.

### A2 — Customer abuse vector: order creation is DB-rate-limited but fail-open on rate-limit query failure

- WHERE: `OrderService::checkRateLimit()` counts parent orders over 60 seconds and throws when >= 5, but catches query failures and allows order creation.
- WHY: Avoid blocking customers during rate-limit query errors.
- ROOT CAUSE: DB-only rate limiting and fail-open error path.
- CONNECTED SYSTEMS: orders, cart, inventory, alerts, delivery dispatch.
- SECURITY IMPACT: If rate-limit query fails or is degraded, automated order abuse can continue.
- PERFORMANCE IMPACT: Order abuse creates DB transactions, row locks, inventory updates, delivery calls.
- SCALE IMPACT: Growth multiplies write contention on orders/inventory/cart.
- FUTURE RISK: Abuse can cascade into delivery dispatch and alerts.
- Evidence: `wtg  live/body/shopping-engine/api/orders/OrderService.php:763-788`, `wtg  live/body/shopping-engine/api/orders/OrderService.php:141-309`.
- Confidence: High.

### A3 — Vendor abuse vector: vendor status transitions can mutate fulfillment state and inventory

- WHERE: Vendor order status update resolves vendor ID and then allows transitions through confirmed/processing/shipped/delivered/cancelled; delivered deducts inventory and releases reserve.
- WHY: Vendors control fulfillment progression.
- ROOT CAUSE: Vendor self-service operational mutation is business-required; abuse prevention depends on status transition and vendor ownership checks.
- CONNECTED SYSTEMS: vendor orders, order_items, inventory, customer order views.
- SECURITY IMPACT: Compromised vendor account can manipulate own orders and stock state.
- PERFORMANCE IMPACT: Status changes run transactions and per-item inventory updates.
- SCALE IMPACT: High item counts increase transaction duration.
- FUTURE RISK: No traced fraud/audit workflow beyond logs/errors for suspicious vendor behavior.
- Evidence: `wtg  live/body/shopping-engine/api/orders/OrderController.php:117-145`, `wtg  live/body/shopping-engine/api/orders/OrderService.php:429-549`.
- Confidence: High.

### A4 — Payment replay/idempotency is partial

- WHERE: HMAC timestamp rejects webhooks older than 5 minutes; process logic skips already-paid orders and checks existing success transaction; no durable event-ID ledger was traced.
- WHY: Idempotency is implemented at order/payment outcome level, not webhook event storage level.
- ROOT CAUSE: No traced `webhook_events` table or event nonce store.
- CONNECTED SYSTEMS: Cashfree, orders, transactions, dispatch, alerts.
- SECURITY IMPACT: Duplicate valid webhooks inside timestamp window rely on row locks/status checks rather than event replay ledger.
- PERFORMANCE IMPACT: Duplicate webhooks still enter DB transaction until skipped.
- SCALE IMPACT: Payment provider retry storms can lock hot orders.
- FUTURE RISK: Non-success status transitions and out-of-order events may be ambiguous.
- Evidence: `wtg  live/lib/WebhookVerifier.php:39-60`, `wtg  live/lib/PaymentService.php:183-285`.
- Confidence: High.

### A5 — Delivery callback replay and monotonicity uncertainty

- WHERE: Delivery webhook accepts status if secret matches and status is in allowed list, then updates delivery/order directly.
- WHY: External runtime synchronization is direct.
- ROOT CAUSE: No traced event timestamp, signature timestamp, nonce, or monotonic transition validation.
- CONNECTED SYSTEMS: SwiftDeliver, deliveries, orders, admin/customer/vendor views.
- SECURITY IMPACT: Captured shared secret enables forged state updates; repeated old callbacks may revert state.
- PERFORMANCE IMPACT: High callback volume causes repeated updates/poll invalidations.
- SCALE IMPACT: Out-of-order delivery events create state churn and support load.
- FUTURE RISK: Multi-city/multi-provider delivery expansion will amplify ambiguity.
- Evidence: `wtg  live/heart/api/delivery/index.php:115-173`.
- Confidence: High.

## Error Leakage and Logging Exposure

- Heart pipeline exception handler hides file/line outside development but logs message/file/line internally.
- Some controllers return generic 500; `BookingController::createBooking()` returns raw exception message to client on catch.
- Logger writes JSON context to local files with `LOCK_EX`; sensitive token values were not seen logged in traced paths, but user IDs, URI, IP, payment/order identifiers, and operational context are logged.
- Evidence: `wtg  live/heart/router.php:79-94`, `wtg  live/heart/index.php:18-49`, `wtg  live/body/service-engine/api/BookingController.php:74-77`, `wtg  live/core/helpers/Logger.php:34-50`.
- Confidence: High.

## Attack Surface Uncertainty Register

- Full `.htaccess` upload execution restrictions not traced.
- Whether production sets `APP_URL` with scheme matching browser `Origin` is uncertain; CORS exact-match behavior depends on env.
- Whether `token_blacklist`, `refresh_tokens`, `orders`, `alerts`, `booking_slot_reservations`, and `inventory` have supporting indexes/constraints in deployed DB needs schema validation.
- Whether SwiftDeliver secret is rotated/scoped per environment is uncertain.
- Whether admin frontend has CSP, token protection, and XSS protections beyond traced backend was not revalidated.

