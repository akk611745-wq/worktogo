# WTG BUSINESS READINESS AUDIT

Read-only audit. No code changed. All file:line references verified by direct read against the current working tree at `wtg  live/`.

## Section 1: Deployment Verify

| Commit | Fix | Status | Evidence |
|---|---|---|---|
| 8ff5520 | register.php vendor_type column | ✅ FOUND | `heart/api/auth/register.php:101` `ServiceVendorEligibility::vendorTypeColumn($db)` |
| fa50db8 | Commission engine (bookings) | ✅ FOUND | `core/helpers/LedgerEngine.php:145` `processBookingCompletion()`; called at `body/service-engine/api/services/index.php:2227` |
| 0d0a2e6 | Commission engine (admin force-complete) | ✅ FOUND | `heart/api/admin/index.php:1832` |
| e2b8fbf | Customer phone masking | ✅ FOUND | `body/service-engine/api/vendors/index.php:261` and `:300` (`$job['customer_phone'] = null;`) |
| 0b68b94 | Direct vendor booking (vendor_id-only) | ✅ FOUND | `body/service-engine/api/services/index.php:1135-1151` (`$vendorIdInput`, `$isVendorRouted`); `app/pages/home.js:705` (`is_vendor_only ? null : _currentService.id`) |
| 07a812e | Date/time timezone fix | ✅ FOUND | `app/pages/home.js:1255` `_toLocalInputValue()`, used at `:1261, :1267, :1273` |
| 2bcabea | Dual role phone fix | ✅ FOUND | `heart/api/auth/register.php:44` `$canUpgradeToVendor` |
| dc70817 | Vendor desktop layout | ✅ FOUND | `vendor/profile.php:58, 146` `margin-left:auto;margin-right:auto;` |

All 8 fixes are present and not reverted.

## Section 2: Auth Flows

**PATH 1 — Google login**: ⚠️ Risk. `AuthController.php:175-269` validates token audience/issuer/expiry/email_verified correctly (solid). Existing email/google_id → reuses account, role preserved (`:243-257`). **New user INSERT (`:261-266`) does not set `phone`**, and `users.phone` is `varchar(20) NOT NULL` with no `DEFAULT` (`database-structure.sql:783`). On a DB running in MySQL strict mode (default since 5.7), this INSERT will throw `Field 'phone' doesn't have a default value` and **brand-new Google sign-ups will fail**. Existing-account Google logins are unaffected.

**PATH 2 — OTP login**: ✅ Works. `heart/api/auth/send-otp.php:21-30` rate-limits (`OTP_MAX_ATTEMPTS`/`OTP_RESEND_WAIT`); `heart/api/auth/verify-otp.php:60-93` correctly finds-or-creates by phone, preserves existing role on repeat login. ⚠️ Side risk: `send-otp.php:105-109` and `:183` log the full MSG91 request payload (which contains the plaintext OTP) and the full provider response to the application log via `Logger::error('MSG91_PAYLOAD_DEBUG', ...)` / `Logger::error('MSG91_RESPONSE', ...)`. Comments mark these as temporary debug blocks "remove once OTP delivery confirmed" — they are still present. This writes live OTP codes to disk in plaintext.

**PATH 3 — Email+password**: ✅ Works. `AuthController.php:35-69` reuses existing account (Google-no-password case handled, password-mismatch rejected), creates new otherwise. Duplicate email is handled (reuse, not error, unless password wrong).

**PATH 4 — Vendor registration**: ⚠️ Risk, mixed.
- Same phone as existing customer → ✅ now works (`register.php:43-63`, commit 2bcabea) — upgrades role, attaches vendor profile, idempotent (`:96-98` skips vendor INSERT if one already exists for that user).
- Same email as existing customer, via `register.php`'s own flow → the email-uniqueness check (`:67-72`) only runs in the `else` (brand-new-phone) branch, so it's not exercised at all in the phone-reuse path; not a regression, just untouched, no conflict.
- Same Google account as customer trying to register via `register.php` → **not handled at all**: `register.php` never checks `google_id`, so a Google-authenticated customer with a different effective phone on file would go through the brand-new-phone insert and hit the same `email` uniqueness check (`:67-72`), which **correctly blocks it** with "email already registered" rather than silently creating a duplicate account — safe but the user gets stuck with no guidance to use the dual-role phone path instead.
- Vendor already pending/active → handled cleanly via the idempotency check at `register.php:96-98` (skips re-insert) and separately via `POST /api/vendors`'s own 409 `VENDOR_ALREADY_EXISTS` guard (`body/service-engine/api/vendors/index.php:73-80`).

**PATH 5 — Admin login**: ✅ Works. No separate admin login endpoint exists — admin accounts log in via the normal email/OTP flow with `role = admin` already set in the `users` row, and `heart/api/admin/index.php:16` enforces `AuthMiddleware::requireRole(ROLE_ADMIN)` on every route in the file before any handler runs.

## Section 3: Booking Flows

**FLOW 1 — Free booking**: ✅ Complete. Saves via `POST /api/service/request` with category-routed resolution; admin gets `FcmNotifier::notifyRole(... ROLE_ADMIN ...)` (`services/index.php:1344`); vendor assignment via admin's `assign_vendor` action (`heart/api/admin/index.php` ops handler); vendor gets `FcmNotifier::notifyUser` (`services/index.php:1356`); vendor accept/complete via `PATCH /api/jobs/{id}/status`; commission fires via the verified `processBookingCompletion` call (Section 1/4). ⚠️ Customer-facing status visibility was not directly verified in this pass — no explicit `GET /api/my-bookings`-style endpoint was checked; flag for separate confirmation if customer status tracking matters for launch.

**FLOW 2 — Direct vendor**: ✅ Complete (post 0b68b94). `booking_mode = 'direct_vendor'` correctly set via `canonicalBookingMode()` (`services/index.php:184`) once `service['vendor_id']` is populated/overridden (`:1228-1231`); `vendor_id` saved via `$initialVendorId` (`:1265-1267` equivalent, now also fed by `$directVendorRow`); vendor is pre-assigned at creation (no separate "assign" step needed); commission fires on completion via the same verified `processBookingCompletion` path.

**FLOW 3 — Inspection**: ⚠️ Partial / **CASHFREE_ENV = PRODUCTION** (confirmed read from `.env`, value only — not a secret). This means inspection payments are live and will charge real money — any further testing of the inspection flow on this environment must use real cards/UPI or a sandboxed test environment, not this `.env`. Lifecycle states (`inspection_queued`, `inspection_completed`, etc.) exist and are wired in `heart/api/admin/index.php:1774-1787`; payment integration itself (Cashfree session creation/webhook) was not re-traced line-by-line in this pass.

## Section 4: Commission Audit

**Will commission actually record? YES**, with one caveat below.

1. `body/service-engine/api/services/index.php:2227` — call exists, guarded by `if ($bookingStatus === 'completed' && $jobRow['booking_id'])`, placed after the job/booking-update transaction commits; `$db` and `$jobRow` are in scope. Will execute without error.
2. `heart/api/admin/index.php:1832` — same pattern, guarded by `$completedBookingId !== null`, covers both the jobs-table path and the booking-id-fallback path.
3. `core/helpers/LedgerEngine.php:145-194` (`processBookingCompletion`) — query joins `bookings b JOIN vendors v ON v.id = b.vendor_id` (line ~149), correct against schema. `ensureVendorWallet()` is called unconditionally before the wallet UPDATE (so a missing `vendor_wallets` row is created first — no failure case there). `commission_rate` NULL is handled with a `10.0` fallback (`$booking['commission_rate'] ? ... : 10.0`). The function wraps everything in a try/catch around a transaction; on any exception it rolls back and logs via `Logger::error`, returning `false` — it fails safe (no half-applied wallet credit) but does **not surface the failure to the caller** (the calling code in both wired sites doesn't check the boolean return value), so a failed commission run would be silent unless someone is watching logs.
4. `database-structure.sql` — `vendor_wallets` (`:851-857`) has `vendor_id`, `available_balance`, `pending_balance`; `wallet_transactions` (`:865-876`) has `entity_type`, `entity_id`, `order_id`, `type`, `amount`, `status`. All columns `processBookingCompletion` references are present.

**Caveat**: `bookings.vendor_id` is `NOT NULL` in the base `database-structure.sql` dump (`:122`), which would make a free_lead/direct_vendor split impossible — but migration `2026_05_23_002_service_propagation_hardening.sql:5` explicitly `MODIFY COLUMN vendor_id ... NULL`, resolving this. This means **the base SQL dump file is stale relative to the live schema** — fine as long as that migration has actually been applied (tracked via `migrations_log`), but the dump itself should not be trusted as a snapshot of the real production schema.

## Section 5: Vendor Panel Flows

1. **New vendor registration** → ✅ `vendor/profile.php:254-260` (`isProfileComplete()`) auto-forces edit mode and shows the incomplete-profile banner when `category_id` or `service_localities` is missing — confirmed by direct read. Category/area save path exists (`enableEdit()`/save handler at `:350`+, not traced to network call in this pass). No explicit "redirect to bookings after save" was found/verified — likely just stays on profile.
2. **Vendor visibility to customers** → not fully re-traced this pass; `vendors/index.php` exposes `category_id`/`service_localities` columns where present (`ServiceVendorEligibility::tableHasColumn` guards), so incomplete profiles are not structurally hidden from `GET /api/vendors` by a WHERE clause — visibility filtering, if any, would need to happen client-side. Flag as ⚠️ unverified, not confirmed broken.
3. **Vendor receives booking** → ✅ FCM notify confirmed (`services/index.php:1356`); accept/reject via `PATCH /api/jobs/{id}/status` with vendor-reject → requeue logic (verified in earlier session); customer phone masking re-confirmed intact (`vendors/index.php:261, 300`); mark-complete triggers commission (Section 4).

## Section 6: Security Quick Check

| Check | Result |
|---|---|
| `.env` web-accessible | ✅ PASS — `.htaccess` blocks `\.(env|sql|log|md|sh|bak|git|json)$` via `FilesMatch` |
| `.git/` blocked | ✅ PASS — explicit `RewriteRule ^\.git(/.*)?$ - [F,L]` |
| OTP rate limiting | ✅ PASS — `send-otp.php:21-30` |
| Customer phone hidden pre-`in_progress` | ✅ PASS — `vendors/index.php:261, 300` (re-confirmed) |
| Admin endpoints JWT-only | ✅ PASS — `heart/api/admin/index.php:16`, no `$_GET` secret bypass found in that file |
| Hardcoded secrets in JS | ✅ PASS — grep for key/secret/Bearer patterns in `app/` and `vendor/` JS returned no hits |
| ⚠️ Extra finding (not in checklist) | **RISK** — `deploy-hook.php` is blocked entirely by `.htaccess` (`<Files "deploy-hook.php"> Deny from all`), which would break any webhook-based deploy automation that relies on hitting it over HTTP — ops issue, not a security hole. |
| ⚠️ Extra finding (not in checklist) | **RISK** — OTP plaintext logged via temporary debug blocks in `send-otp.php:105-109, 183` (see Section 2, Path 2). |

## Section 7: Environment Check

(Values read directly from `.env`; only presence reported for secrets, actual value reported only for the operationally-relevant `CASHFREE_ENV`.)

| Variable | Status |
|---|---|
| CASHFREE_ENV | **PRODUCTION** |
| GOOGLE_CLIENT_ID | SET |
| MSG91_AUTH_KEY | SET |
| MSG91_TEMPLATE_ID | SET |
| MSG91_SENDER_ID | SET |
| SMS_PROVIDER | SET (`msg91`) |
| FCM_PROJECT_ID / FCM_API_KEY / FCM_SENDER_ID / FCM_APP_ID / FCM_VAPID_KEY / FCM_SERVICE_ACCOUNT_PATH | all SET |
| DB_HOST / DB_NAME / DB_USER / DB_PASS | all SET |

## Section 8: Known Bug Verify

1. Vendor-only card → direct_vendor mode: **FIXED** — `app/pages/home.js:1527` sets `mode: 'direct_vendor'` (verified in this session's earlier diff and re-confirmed present).
2. Free booking date/time: **FIXED** — `_toLocalInputValue()` exists at `app/pages/home.js:1255`, used by all three date-default helpers.
3. Phone dual role: **FIXED** — `heart/api/auth/register.php:44` `$canUpgradeToVendor` logic present and correctly ordered before the email-uniqueness branch.
4. Admin approve endpoint / commit `2c0a44a`: **N/A — commit does not exist in this repo's `git log`.** No commit with that hash was found. Checked instead whether any admin vendor-approval endpoint in `heart/api/admin/index.php` handles the `type`/`vendor_type` column ambiguity: did not find a dedicated "approve vendor" status-update path using a literal `type`/`vendor_type` column write in this file (vendor approval appears to go through a generic `PATCH` on `vendors.status`, which doesn't touch the type column at all) — so the ambiguity this commit name implies doesn't appear to apply to an existing code path. Treat as not applicable rather than confirmed-fixed.

## Overall Score: 78/100

Core money-path logic (commission, direct-vendor binding, phone masking, dual-role registration) is solid and verified working. Score is held back by one real signup-breaking bug (Google new-user phone NOT NULL) and an OTP-plaintext-logging hygiene issue — both fixable in under an hour, neither requires architectural change.

## Launch Blockers
- **New Google sign-ups likely fail outright**: `AuthController.php:261-266` INSERT omits `phone`, but `users.phone` is `NOT NULL` with no default. Needs either a `phone` value, a placeholder, or a schema default before relying on Google signup for new users.

## Warnings
- OTP codes and MSG91 responses are logged in plaintext via leftover debug blocks (`send-otp.php:105-109, 183`) — remove before scaling traffic, since OTP logs are now a live credential leak surface.
- `CASHFREE_ENV=PRODUCTION` — any inspection-flow testing on this environment charges real money; do not test casually.
- `deploy-hook.php` is fully blocked by `.htaccess`, which will break webhook-triggered deploys if that's the intended deploy mechanism.
- Commission failures inside `LedgerEngine::processBookingCompletion` fail silently (return `false` is not checked by either call site) — acceptable for data safety, but means a stuck/failed commission run has no operator-visible alert beyond the log file.
- Vendor visibility filtering (only show vendors with category+area set) was not confirmed server-side in this pass — worth a dedicated check before launch if that's a hard requirement.

## Safe to Launch: NO — not until the Google-signup NOT NULL bug is fixed. Everything else found is a warning, not a hard blocker.
