# Controlled Auth + Vendor Fix Readiness

## Scope
- Normalize auth/account creation identity fields so missing `phone` and `email` are stored as `NULL`, not empty strings.
- Align active vendor code to production `vendors.type`.
- Align refresh token naming to canonical `refresh_token` while preserving legacy `refreshToken` compatibility.
- Prepare reviewed migration/deploy procedure only. Do not deploy or run migrations yet.

## Changed Files
- `heart/api/auth/AuthController.php`
- `heart/api/auth/register.php`
- `heart/api/auth/verify-otp.php`
- `heart/api/auth/refresh.php`
- `body/service-engine/api/vendors/index.php`
- `heart/api/admin/index.php`
- `brain/FeedRankingEngine.php`
- `app/js/config.js`
- `app/js/auth.js`
- `migrations/2026_05_11_001_auth_vendor_contract_alignment.sql`
- `plans/controlled-auth-vendor-fix-readiness.md`

## Local Verification Status
- PHP syntax lint could not be executed locally because `php` is not installed or not available in PATH on this Windows environment.
- No migrations have been run.
- No deployment has been performed.

## Migration Files
- `migrations/2026_05_11_001_auth_vendor_contract_alignment.sql`

## Exact Deployment Order
1. Take a production database backup.
2. Upload backend PHP changes.
3. Upload frontend JS changes.
4. Run migration only after code files are in place.
5. Clear PHP/opcache and CDN/static cache if enabled.
6. Run smoke tests below.

## Exact Migration Execution Order
1. Execute `migrations/2026_05_11_001_auth_vendor_contract_alignment.sql`.
2. Confirm `users.phone` is nullable.
3. Confirm `users.email` is nullable.
4. Confirm `vendors.type` exists.
5. Confirm active schema no longer requires `vendors.vendor_type` for application queries.

## Rollback Notes
- Restore the database backup if the migration fails mid-execution or smoke tests show schema-related write failures.
- Revert the changed application files to the previous deploy package.
- If only frontend refresh-token storage fails, reverting `app/js/auth.js` and `app/js/config.js` is sufficient for session behavior, while backend remains backward compatible.

## Smoke-Test Checklist
1. Email register with no phone: verify user row has `phone IS NULL`, `email` set, and response includes `token`, `refresh_token`, and `refreshToken`.
2. Email login: verify session stores access token and refresh token, and user can load authenticated home/account flow.
3. Guest login: verify user row has `phone IS NULL` and `email IS NULL`.
4. OTP login for new phone: verify user is created with real phone and refresh token response includes both names.
5. Refresh endpoint with `refresh_token`: verify new access token returned.
6. Refresh endpoint with legacy `refreshToken`: verify new access token returned.
7. Vendor apply/create: verify row inserted using `vendors.type`.
8. Vendor list/detail: verify list/detail load and response still includes `type`; legacy `vendor_type` alias may be present for compatibility.
9. Admin vendor approve: verify role changes to `vendor_service` or `vendor_shopping` based on `vendors.type`.
10. Confirm no active runtime errors referencing missing `vendor_type`.

