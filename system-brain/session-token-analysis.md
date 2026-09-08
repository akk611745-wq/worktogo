# Session and Token Analysis

Generated: 2026-05-12T04:40:00Z

---

## 1. Token Model

WorkToGo uses signed HS256 JWTs for access tokens. There is no traced cookie-based or PHP session-based authentication for normal app flows.

Evidence: `wtg  live/core/helpers/JWT.php:7`, `wtg  live/heart/middleware/AuthMiddleware.php:100`.

Confidence: High.

---

## 2. JWT Contents

Common claims:

- `user_id`
- `role`
- `phone` for some flows
- `is_guest` for guest login
- `iat`
- `nbf`
- `exp`

Evidence: `wtg  live/heart/api/auth/AuthController.php:118`, `wtg  live/heart/api/auth/verify-otp.php:95`, `wtg  live/core/helpers/JWT.php:10`.

Important finding: `JWT::encode()` overwrites/sets `iat` and `nbf`, and only sets `exp` if the third parameter is greater than zero. Some callers manually provide `exp`; others pass a duration.

Evidence: `wtg  live/core/helpers/JWT.php:10`.

---

## 3. Expiration Behavior

| Flow | Access-token expiry behavior | Evidence | Confidence |
|---|---|---|---|
| Email login | Manual `exp = time() + 30 days` | `wtg  live/heart/api/auth/AuthController.php:118` | High |
| Email register | Manual `exp = time() + 30 days` | `wtg  live/heart/api/auth/AuthController.php:54` | High |
| OTP verify | Passes `time() + 30 days` as third `expiresIn` parameter, causing `exp = now + (absolute timestamp)` rather than intended 30 days | `wtg  live/heart/api/auth/verify-otp.php:95`, `wtg  live/core/helpers/JWT.php:17` | High |
| Legacy register | Uses `JWT_EXPIRY` duration | `wtg  live/heart/api/auth/register.php:76` | High |
| Refresh | Uses `JWT_EXPIRY` duration | `wtg  live/heart/api/auth/refresh.php:61` | High |
| Guest | Manual 24-hour `exp` | `wtg  live/heart/api/auth/AuthController.php:271` | High |

Security note: OTP verify appears to issue extremely long-lived tokens because a Unix timestamp is passed as a duration. This is documented as operational reality, not patched.

---

## 4. Refresh Token Lifecycle

Refresh tokens are random 32-byte hex strings in modern flows and are stored as SHA-256 hashes in `refresh_tokens` with 30-day expiry.

Evidence: `wtg  live/heart/api/auth/AuthController.php:128`, `wtg  live/heart/api/auth/verify-otp.php:102`, `wtg  live/database-structure.sql:695`.

Refresh route supports both legacy JWT refresh tokens and current hex tokens.

Evidence: `wtg  live/heart/api/auth/refresh.php:17`.

Client-side automatic refresh was not found in customer, vendor, or admin API clients. Clients generally logout on 401.

Evidence: `wtg  live/app/js/api.js:44`, `wtg  live/vendor/shared/api.js:20`, `wtg  live/admin/shared/api.js:62`.

Confidence: High.

Scale implication: refresh tokens may accumulate because refresh does not rotate or delete old token rows; logout deletes all user refresh tokens.

---

## 5. Blacklist Lifecycle

Logout blacklists the current access token by SHA-256 hash with its expiry timestamp. `token_blacklist.token_hash` is unique.

Evidence: `wtg  live/heart/api/auth/logout.php:14`, `wtg  live/database-structure.sql:742`, `wtg  live/database-structure.sql:1204`.

Every protected request checks blacklist.

Evidence: `wtg  live/heart/middleware/AuthMiddleware.php:77`.

Uncertainty: no token blacklist cleanup job was traced. If none exists, expired blacklist records may grow indefinitely.

---

## 6. Storage Model

| Surface | Access token storage | Refresh token storage | Server revocation on logout? | Evidence |
|---|---|---|---|---|
| Customer app | localStorage `wtg_token` | localStorage `wtg_refresh_token` | No, local clear only | `wtg  live/app/js/auth.js:7`, `wtg  live/app/js/auth.js:26` |
| Vendor panel | localStorage `wtg_vendor_token` | Not stored by shared vendor auth | No, local clear only | `wtg  live/vendor/shared/auth.js:8`, `wtg  live/vendor/shared/auth.js:49` |
| Admin panel | localStorage `wtg_admin_token` | localStorage `wtg_admin_refresh` | Yes, attempts `/auth/logout` | `wtg  live/admin/shared/auth.js:41`, `wtg  live/admin/shared/auth.js:53` |

Security implication: localStorage token storage exposes access tokens to any XSS in the relevant frontend surface.

---

## 7. Revalidation Logic

Server-side `/api/auth/me` revalidates token then fetches the user row with `deleted_at IS NULL`, but it does not check `status='active'`.

Evidence: `wtg  live/heart/api/auth/me.php:10`.

Client-side route guards generally do not call `/me` at app startup.

Evidence: `wtg  live/app/js/router.js:64`, `wtg  live/vendor/shared/auth.js:61`, `wtg  live/admin/shared/auth.js:71`.

Confidence: High.

Future risk: stale local sessions can look valid client-side even when server would reject or role has changed.

