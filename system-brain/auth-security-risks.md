# Auth Security Risks

Generated: 2026-05-12T04:40:00Z

This file documents observed risks only. No fixes were applied.

---

## Risk 1 — Non-admin logout does not revoke server token

WHERE: Customer `AUTH.logout()` and vendor `Auth.logout()` clear localStorage only.

WHY: Only admin logout calls backend `/auth/logout`.

ROOT CAUSE: Inconsistent frontend logout implementations.

CONNECTED SYSTEMS: Customer app, vendor panel, `token_blacklist`, `refresh_tokens`.

USER IMPACT: A copied/stolen token remains usable after user logs out.

SCALE IMPACT: Logout semantics become unreliable across devices/surfaces.

SECURITY IMPACT: Token theft window lasts until token expiry.

FUTURE RISK: Multi-device logout and incident revocation become hard.

Evidence: `wtg  live/app/js/auth.js:26`, `wtg  live/vendor/shared/auth.js:49`, `wtg  live/admin/shared/auth.js:53`, `wtg  live/heart/api/auth/logout.php:14`.

Confidence: High.

---

## Risk 2 — OTP login token expiry bug / stale token risk

WHERE: OTP verify calls `JWT::encode(..., time() + (86400 * 30))` as the third duration parameter.

WHY: `JWT::encode()` treats third argument as duration seconds and adds it to current time.

ROOT CAUSE: Inconsistent JWT helper calling convention.

CONNECTED SYSTEMS: OTP login, customer mobile auth, token blacklist, all protected routes.

USER IMPACT: OTP users may receive much longer sessions than intended.

SCALE IMPACT: More long-lived tokens increase revocation/blacklist burden.

SECURITY IMPACT: Stolen OTP-issued JWTs may persist far beyond intended 30 days.

FUTURE RISK: Token TTL assumptions become false in audits and incident response.

Evidence: `wtg  live/heart/api/auth/verify-otp.php:95`, `wtg  live/core/helpers/JWT.php:17`.

Confidence: High.

---

## Risk 3 — Role changes do not invalidate existing JWTs

WHERE: JWT role claim is trusted by middleware; role/status are not rechecked on every protected request.

WHY: Stateless JWT payload is the authorization source after signature validation.

ROOT CAUSE: No token version, user status check, or forced revocation on role change traced.

CONNECTED SYSTEMS: Admin user management, vendor approval, all role-protected APIs.

USER IMPACT: Demoted/suspended users may retain access until token expiry.

SCALE IMPACT: Role migration and emergency suspension become weak at scale.

SECURITY IMPACT: Stale admin/vendor tokens can preserve dangerous permissions.

FUTURE RISK: Hard to safely evolve RBAC.

Evidence: `wtg  live/heart/middleware/AuthMiddleware.php:65`, `wtg  live/heart/api/admin/index.php:441`, `wtg  live/heart/api/admin/index.php:480`.

Confidence: High.

---

## Risk 4 — Direct vendor-role registration can issue vendor-role JWT before approval

WHERE: `/api/auth/register` accepts `vendor_service` and `vendor_shopping` roles and issues JWT with that role while vendor row is pending.

WHY: Role selection is user-supplied within validator allowlist.

ROOT CAUSE: Registration flow combines account creation and role assignment.

CONNECTED SYSTEMS: Vendor registration, service/product endpoints, vendor profile resolution.

USER IMPACT: Users may appear as vendor-role before admin approval.

SCALE IMPACT: Onboarding state and authorization state diverge.

SECURITY IMPACT: Endpoints that rely only on role may be exposed to pending vendors.

FUTURE RISK: New vendor endpoints could forget active-vendor checks.

Evidence: `wtg  live/heart/api/auth/register.php:13`, `wtg  live/heart/api/auth/register.php:67`, `wtg  live/heart/api/auth/register.php:76`.

Confidence: High.

---

## Risk 5 — Centralized permission system is absent

WHERE: Authorization logic lives in scattered route/controller checks.

WHY: RBAC is implemented through `requireRole()` calls and local ownership checks.

ROOT CAUSE: No permissions table/policy map was traced.

CONNECTED SYSTEMS: Admin, customer, shopping, service, content, payment.

USER IMPACT: Inconsistent access behavior across modules.

SCALE IMPACT: New endpoints can ship with missing/incorrect role checks.

SECURITY IMPACT: Permission bypass risk increases as modules expand.

FUTURE RISK: Difficult auditability and AI-assisted refactor risk.

Evidence: `wtg  live/heart/middleware/AuthMiddleware.php:28`, `wtg  live/body/shopping-engine/api/orders/OrderController.php:100`, `wtg  live/body/service-engine/api/services/index.php:327`.

Confidence: High.

---

## Risk 6 — Protected request path depends on DB blacklist lookup

WHERE: Every valid token calls `token_blacklist` lookup.

WHY: Logout revocation is enforced through database table.

ROOT CAUSE: Stateful revocation added to stateless JWT without cache layer in middleware.

CONNECTED SYSTEMS: All protected APIs, DB connection pool.

USER IMPACT: Auth may fail with `503` if blacklist DB check fails.

SCALE IMPACT: Blacklist lookup becomes hot-path bottleneck.

SECURITY IMPACT: Fail-secure behavior is good, but outage blast radius is high.

FUTURE RISK: Traffic spikes can degrade all authenticated endpoints.

Evidence: `wtg  live/heart/middleware/AuthMiddleware.php:77`, `wtg  live/heart/middleware/AuthMiddleware.php:89`.

Confidence: High.

---

## Risk 7 — localStorage token storage across all surfaces

WHERE: Customer, vendor, and admin store JWTs in localStorage.

WHY: SPA panels manually persist tokens.

ROOT CAUSE: No HttpOnly cookie/session design.

CONNECTED SYSTEMS: All browser clients.

USER IMPACT: XSS can steal tokens.

SCALE IMPACT: One frontend XSS can compromise many sessions.

SECURITY IMPACT: High-value admin/vendor tokens are script-readable.

FUTURE RISK: Third-party scripts or injected content can create account compromise.

Evidence: `wtg  live/app/js/auth.js:7`, `wtg  live/vendor/shared/auth.js:8`, `wtg  live/admin/shared/auth.js:41`.

Confidence: High.

---

## Risk 8 — Refresh tokens exist but are not actively used by clients

WHERE: Auth APIs return refresh tokens, but API clients logout on 401 instead of refreshing.

WHY: No automatic refresh logic traced.

ROOT CAUSE: Token lifecycle incomplete across frontends.

CONNECTED SYSTEMS: Customer app, admin panel, auth refresh route.

USER IMPACT: Session expiry causes hard logout despite refresh token.

SCALE IMPACT: Unused refresh tokens accumulate and add DB exposure.

SECURITY IMPACT: Refresh tokens are stored but provide limited UX value and add theft risk.

FUTURE RISK: Implementing refresh later may conflict with existing logout-on-401 behavior.

Evidence: `wtg  live/heart/api/auth/refresh.php:7`, `wtg  live/app/js/api.js:44`, `wtg  live/admin/shared/api.js:62`.

Confidence: High.

