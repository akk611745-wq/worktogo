# WorkToGo Shared Services Map — Phase 01

Generated: 2026-05-12T03:44:00Z

---

## 1. Shared Backend Services

| Service | Where | Purpose | Connected Modules | Runtime Role | Scale Risk | Maintainability Risk | Future Impact |
|---|---|---|---|---|---|---|---|
| Database | `wtg  live/core/helpers/Database.php` | MySQL PDO singleton and `getDB()` | Heart, Brain, Body, payment, admin, cron | Foundation DB access | High if DB overloaded; no pooling beyond PDO per process | Medium: exits on connection failure | Future read replicas/multi-db require redesign |
| Response | `wtg  live/core/helpers/Response.php` | Unified JSON response helper | REST controllers, internal body engines | Sends JSON or throws `InternalResponseException` during Heart internal include | Medium | Medium-high: behavior changes by global constant | Any response envelope change impacts all APIs |
| JWT | `wtg  live/core/helpers/JWT.php` | JWT encode/decode | AuthMiddleware, auth controllers, feed optional auth | Auth token primitive | Medium | Medium: custom implementation requires security review | Future auth changes must preserve token contract |
| AuthMiddleware | `wtg  live/heart/middleware/AuthMiddleware.php` | Require/optional JWT, RBAC, blacklist | Admin, body APIs, payment, stories | Access control gate | Medium-high: DB blacklist per request | High: depends on core, roles, DB, logger | Role changes ripple through all protected endpoints |
| Logger | `wtg  live/core/helpers/Logger.php` | File logging | Heart, Brain, exceptions, admin | Operational logging | Medium: file IO under load | Medium: env key mismatch | Needed for observability roadmap |
| Validator | `wtg  live/core/helpers/Validator.php` | Request validation | Admin/auth/body | Input validation | Low-medium | Medium: custom rule syntax | Should become shared contract validator |
| RateLimiter | `wtg  live/core/helpers/RateLimiter.php` | DB/IP action limiter | Auth/API likely | Abuse control | Medium: DB writes under attacks | Medium: separate from Heart APCu limiter | Rate limiting strategy should be unified |
| HeartRateLimiter | `wtg  live/heart/router.php` | APCu IP/user limiter for pipeline | Intent pipeline | Pipeline abuse control | Medium: disabled if APCu unavailable | Medium: embedded in router file | Should be extracted if pipeline grows |
| ReflexEngine | `wtg  live/heart/pipeline/ReflexEngine.php` | Cache layer Redis/APCu/file | Intent pipeline | Fast response cache | Medium: file fallback can scale poorly | Medium: cache key correctness critical | Important for performance but needs invalidation map |
| BrainConnector | `wtg  live/heart/pipeline/BrainConnector.php` | Optional Brain call with circuit breaker | Pipeline, Brain | AI/decision enrichment | Medium: network/local include latency | Medium-high: mutates request globals | Future intelligence relies on contract hardening |
| EngineLoader | `wtg  live/heart/pipeline/EngineLoader.php` | Calls body engines local/HTTP | Pipeline, Body engines | Business data aggregation | High: local includes sequential and stateful | High: hidden service-call contract | Future microservice split depends on this |
| Payment helper | `wtg  live/core/helpers/Payment.php` | Cashfree helper/refunds | Service booking, refunds | Payment utility | High | High due duplication with `lib` | Must be reconciled with payment library |
| PaymentService | `wtg  live/lib/PaymentService.php` | Cashfree payment/order/webhook lifecycle | Payment APIs, OrderService | Payment service abstraction | High | Medium-high | Likely better owner than core helper, but needs confirmation |
| CashfreeClient | `wtg  live/lib/CashfreeClient.php` | HTTP client for Cashfree | PaymentService | External gateway client | Medium | Medium | External API version dependency |
| WebhookVerifier | `wtg  live/lib/WebhookVerifier.php` | Cashfree webhook signature verification | Payment webhook | Security gate | High | Medium | Critical for payment integrity |
| LedgerEngine | `wtg  live/core/helpers/LedgerEngine.php` | Wallet settlement/refund/cash controls | Refunds, admin, orders maybe | Financial accounting | High | High: namespace/class consistency needs inspection | Critical before scaling finance |
| DeliveryPricingEngine | `wtg  live/core/helpers/DeliveryPricingEngine.php` | Delivery pricing | Delivery/order/service likely | Pricing calculation | Medium | Medium | Needed if delivery internalizes |
| GeoHelper | `wtg  live/core/helpers/GeoHelper.php` | Distance calculations | Delivery pricing, search/geo | Geo utility | Low | Low | Foundational for local marketplace |
| UploadHelper | `wtg  live/core/helpers/UploadHelper.php` | File upload validation/storage | Stories/products/services likely | Media handling | Medium | Medium: upload path/env assumptions | Important for content/video expansion |

---

## 2. Shared Frontend Services

| Service | Where | Purpose | Connected Modules | Risk |
|---|---|---|---|---|
| Customer CONFIG | `wtg  live/app/js/config.js` | Base URL, endpoints, tokens, statuses, feature flags | Customer `API`, `AUTH`, pages | Endpoint constants partially not used/aligned |
| Customer API | `wtg  live/app/js/api.js` | Fetch wrapper, auth headers, timeout, intent calls | Customer pages/auth | Direct `fetch` discouraged; intent path posts to base URL |
| Customer AUTH | `wtg  live/app/js/auth.js` | localStorage token/user/session handling | Router/API/pages | No token refresh path observed in this file |
| Customer ROUTER | `wtg  live/app/js/router.js` | Hash-based SPA routing and polling | Page modules | Globals/script order required |
| Admin CONFIG | `wtg  live/admin/config.js` | Admin base API config/endpoints | Admin API/auth/pages | `/heart/api` base adds confusion |
| Admin APIClient | `wtg  live/admin/shared/api.js` | Admin fetch wrapper/prefix handling | Admin pages | Prefix normalization is complex |
| Vendor CONFIG | `wtg  live/vendor/config.js` | Vendor base URL/tokens/statuses | Vendor shared API/auth/pages | Separate token namespace from customer/admin |
| Vendor API | `wtg  live/vendor/shared/api.js` | Vendor fetch wrapper and intent modules | Vendor pages | Intent names drift from backend mappings |

---

## 3. Global State Map

| Global State | Owner | Consumers | Risk |
|---|---|---|---|
| PHP constants `SYSTEM_ROOT`, `HEART_ROOT`, `CORE_PATH` | Heart index | Body/admin/core modules | Modules fail if included outside expected boot path |
| PHP constants `JWT_SECRET`, roles | Config app | AuthMiddleware/controllers | Boot-time constant conflicts possible if config loaded repeatedly |
| PHP constant `HEART_INTERNAL_INC` | BrainConnector/EngineLoader | Response/body/brain internal handling | Once defined, remains for current PHP request |
| `$GLOBALS['HEART_PAYLOAD']` | BrainConnector/EngineLoader | Brain/body helpers/controllers | Hidden input source |
| `$_SERVER['REQUEST_METHOD']`, `REQUEST_URI` | Web server, then mutated by internal dispatch | Routers/controllers | State leak risk |
| `window.WTG_BASE_URL` | `env.js` or PHP inline | frontend config | Static HTML/PHP duplication risk |
| Browser localStorage token keys | app/admin/vendor configs | API/auth modules | Separate sessions by panel; logout not shared |

---

## 4. Shared Service Operational Observations

- Shared services are mostly loaded manually via `require_once`, not Composer.
- There is no centralized dependency injection container.
- Backend modules assume global helpers and constants are already present.
- Several helpers exit directly on error, making composition/testing difficult.
- Internal include mode uses exception interception as a substitute for returning values.
- Observability is file-log based, with potential log-level config mismatch.

---

## 5. Next Shared-Service Tracing Targets

1. Inspect `Validator` rule coverage vs controller usage.
2. Inspect `JWT` security details and auth controller token issuance.
3. Inspect `LedgerEngine` namespace/class declarations and callers.
4. Build payment abstraction comparison: `Payment` vs `PaymentService` vs `CashfreeClient`.
5. Build frontend token/session interoperability map across app/admin/vendor.

