# Auth API Map

Generated: 2026-05-12T04:40:00Z

---

## 1. Auth Endpoints

| Method | Route | Handler | Auth required | Response/session behavior | Evidence |
|---|---|---|---|---|---|
| `POST` | `/api/auth/login` | Redirects 307 to `/api/auth/email/login` | No | Compatibility redirect | `wtg  live/heart/api/auth/router.php:10` |
| `POST` | `/api/auth/email/login` | `AuthController::loginEmail()` | No | JWT + refresh token + user/admin payload | `wtg  live/heart/api/auth/router.php:24`, `wtg  live/heart/api/auth/AuthController.php:75` |
| `POST` | `/api/auth/email/register` | `AuthController::registerEmail()` | No | Creates customer, JWT + refresh token | `wtg  live/heart/api/auth/router.php:23`, `wtg  live/heart/api/auth/AuthController.php:11` |
| `POST` | `/api/auth/register` | `register.php` | No | Creates user, optional pending vendor, JWT only | `wtg  live/heart/api/auth/router.php:16`, `wtg  live/heart/api/auth/register.php:7` |
| `POST` | `/api/auth/logout` | `logout.php` | Yes | Blacklists access token; deletes refresh tokens | `wtg  live/heart/api/auth/router.php:17`, `wtg  live/heart/api/auth/logout.php:7` |
| `GET` | `/api/auth/me` | `me.php` | Yes | Returns user profile from DB | `wtg  live/heart/api/auth/router.php:18`, `wtg  live/heart/api/auth/me.php:7` |
| `POST` | `/api/auth/refresh` | `refresh.php` | Refresh token | Issues new access token | `wtg  live/heart/api/auth/router.php:19`, `wtg  live/heart/api/auth/refresh.php:7` |
| `POST` | `/api/auth/otp/send` | `send-otp.php` | No | Creates hashed OTP and sends SMS/log | `wtg  live/heart/api/auth/router.php:20`, `wtg  live/heart/api/auth/send-otp.php:8` |
| `POST` | `/api/auth/otp/verify` | `verify-otp.php` | No | Verifies OTP, creates user if needed, JWT + refresh token | `wtg  live/heart/api/auth/router.php:21`, `wtg  live/heart/api/auth/verify-otp.php:7` |
| `POST` | `/api/auth/google` | `AuthController::loginGoogle()` | No | Verifies Google token and issues JWT | `wtg  live/heart/api/auth/router.php:25`, `wtg  live/heart/api/auth/AuthController.php:144` |
| `GET` | `/api/auth/guest` | `AuthController::guestLogin()` | No | Creates guest user and 24-hour JWT | `wtg  live/heart/api/auth/router.php:26`, `wtg  live/heart/api/auth/AuthController.php:260` |

---

## 2. Major Protected API Surfaces

| Surface | Route pattern | Protection | Evidence | Confidence |
|---|---|---|---|---|
| Admin | `/api/admin/*` | `requireRole(admin)` once at file load | `wtg  live/heart/api/admin/index.php:12` | High |
| Customer cart | `/api/cart/*` | `se_require_auth()` | `wtg  live/body/shopping-engine/api/cart/CartController.php:20` | High |
| Customer orders | `/api/orders*` | `requireRole(customer, admin)` | `wtg  live/body/shopping-engine/api/orders/OrderController.php:27` | High |
| Vendor shopping products | `/api/vendor/products*` | `requireRole(vendor_shopping, admin)` | `wtg  live/body/shopping-engine/api/products/ProductController.php:53` | High |
| Vendor shopping orders | `/api/vendor/orders*` | `se_require_auth()` + active vendor lookup | `wtg  live/body/shopping-engine/api/orders/OrderController.php:100` | High |
| Service booking create | `/api/service/request` | any authenticated user | `wtg  live/body/service-engine/api/services/index.php:204` | High |
| Service booking list/detail | `/api/service/bookings*` | any auth + scoped by role/owner | `wtg  live/body/service-engine/api/services/index.php:327` | High |
| Service jobs status | `/api/jobs/{id}/status` | `requireRole(vendor_service, admin)` + ownership | `wtg  live/body/service-engine/api/services/index.php:409` | High |
| Service vendors create | `/api/vendors` `POST` | `vendor_service`, `admin`, or `customer` | `wtg  live/body/service-engine/api/vendors/index.php:42` | High |
| Public vendors list/detail | `/api/vendors` `GET`, `/api/vendors/{id}` | Public | `wtg  live/body/service-engine/api/vendors/index.php:114` | High |
| Payment create/status | `/api/payment/create-order`, `/api/payment/status` | any authenticated user | `wtg  live/api/payment/create-order.php:31`, `wtg  live/api/payment/status.php:12` | High |
| Payment webhook | `/api/payment/webhook` | No auth; Cashfree signature expected | `wtg  live/api/payment/webhook.php:10` | Medium |
| Feed stories | `/api/feed/stories` | Optional JWT; public fallback | `wtg  live/body/content-engine/api/StoryController.php:85` | High |

---

## 3. Frontend API Clients

| Client | Base URL | Token key | Auth behavior | Evidence |
|---|---|---|---|---|
| Customer app | `https://worktogo.in` by default | `wtg_token` | Adds bearer token if present, local logout on 401 | `wtg  live/app/js/config.js:11`, `wtg  live/app/js/api.js:14` |
| Vendor panel | `https://worktogo.in` by default | `wtg_vendor_token` | Adds bearer token if present, local logout on 401 | `wtg  live/vendor/config.js:9`, `wtg  live/vendor/shared/api.js:9` |
| Admin panel | `/heart/api` | `wtg_admin_token` | Adds bearer token, clears session on 401/403 | `wtg  live/admin/config.js:8`, `wtg  live/admin/shared/api.js:13` |

---

## 4. Unverified API Areas

- Delivery auth details were not fully traced beyond routing.
- Brain admin route auth was not deeply traced; separate Brain routes exist and may use API keys/admin keys.
- Legacy vendor HTML dashboards use older token keys (`vendor_token`) in some files; these appear separate from the shared vendor panel flow and require future inspection.

