# WorkToGo V2 — Security & Abuse-Resistance Audit

> **Scope:** Banking-grade, read-only review of the `v2` branch against the 8 audit areas (auth/sessions, authorization/RBAC, money paths, input validation, bypass/abuse, rate-limiting, data exposure, audit trail). Every finding is grounded in an actual file/line; nothing invented, nothing glossed.
> **Method:** static code trace, no live execution. Threat model: a hostile customer, a bypassing/gaming vendor, an abusive team member (least-privilege), and an outside attacker.
> **Date:** 2026-08-16. **Branch:** `v2` (worktree `wtg-v2/`), working tree clean. **No code was modified** (read-only audit; fixes are separate tasks).

---

## Executive summary

The **core money and auth machinery is genuinely strong** — not a dummy: a signature-verified, replay-windowed, idempotent Cashfree webhook; **server-authoritative amounts** (the client cannot set what it pays); `FOR UPDATE` row-locking everywhere money moves; a clean HS256 JWT with no alg-confusion and DB-backed revocation; fail-closed RBAC on 18 endpoints; proper IDOR ownership checks; banking-grade file-upload validation; broad rate-limiting; and a thorough admin audit trail (42 logged actions).

**No CRITICAL findings.** The gaps are **2 HIGH** (a public endpoint leaking vendor contact — which also breaks the anti-bypass strategy — and a register-flow account-integrity flaw) and **3 MEDIUM** (OTP-in-logs fallback, a token-expiry bug, and rate-limiting's APCu dependency). All are small, targeted fixes.

| Severity | Count |
|---|---|
| CRITICAL | 0 |
| HIGH | 2 |
| MEDIUM | 3 |
| LOW | 3 |

---

## Findings

### 🔴 HIGH

**H1 — Public vendor-discovery endpoint leaks vendor phone + email (data exposure **and** platform bypass).**
- **Where:** `body/service-engine/api/vendors/index.php` — `GET /api/vendors` (line ~137). The route has **no `AuthMiddleware` gate** (confirmed: unauthenticated/public), and its SELECT returns `u.phone AS owner_phone, u.email AS owner_email` (line ~16 of the query) in the JSON for every vendor.
- **Risk:** (a) **Data harvesting** — any anonymous visitor can page through and scrape the personal phone + email of every vendor. (b) **Direct platform bypass** — the whole V2 strategy is *"hide the customer's contact until the job starts"* (V2-VISION §5), and the vendor jobs API correctly nulls `customer_phone` until a job is in progress. But this discovery list hands the customer the **vendor's** phone/email up front, so a customer can find a vendor in "Near Me", read the number, and go off-platform — defeating the core anti-bypass design and the commission model.
- **Fix direction:** remove `owner_phone`/`owner_email` (and any raw contact) from the public `/api/vendors` response. Customers never need a vendor's raw contact — coordination is the platform's job, and contact is revealed only through the booking/job flow after commitment. Keep business_name/rating/areas for discovery.

**H2 — Passwordless-account claim via `/api/auth/register` (account integrity / takeover without OTP proof).**
- **Where:** `heart/api/auth/register.php` — `_registerResolveExisting()` (lines 59-74). Registration matches an existing user by phone (line ~90) or email. If the matched account has **no password** (an OTP-only customer, common in this app), the endpoint **sets the caller's chosen password** (lines 63-65) and **UPDATEs the role to `vendor_service`** (line 74) — with **no OTP/phone-ownership verification** in this path.
- **Risk:** anyone who knows a victim's phone number (a low-secret) can POST `register` with `role=vendor_service` + any password and **claim the victim's passwordless account** — injecting a password they control and changing the victim's role to vendor. That's an unauthorized role change and a foothold for takeover (the account now has an attacker-known password; exploitability of *login* depends on the phone/email+password login path, but the integrity breach — silently repurposing a real user's account — stands regardless). The register whitelist correctly blocks `role=admin` and blocks re-registering existing vendor/admin accounts, so this is bounded to passwordless→vendor, but it is still an auth-integrity flaw on a real-money platform.
- **Fix direction:** never grant a password/role to an existing account on a bare phone/email match. Require an OTP round-trip (prove phone ownership) before a passwordless account can be given a password or upgraded to vendor — i.e., route "become a vendor" through the verified OTP/login flow, not password-register.

---

### 🟠 MEDIUM

**M1 — OTP written to application logs in the no-provider fallback (+ TEMP debug logging of the OTP-send payload).**
- **Where:** `heart/api/auth/send-otp.php` — `_logOtp()` (line 155-158) does `Logger::info('OTP (dev/log mode)', ['phone' => $phone, 'otp' => $otp])` and **returns `true`** (i.e. "sent" succeeds). It is the fallback whenever a provider key is missing (`_sendMsg91`/`_sendFast2Sms`/`_sendTwilio` each `return _logOtp(...)` when their key is absent). Separately, `MSG91_PAYLOAD_DEBUG` (line 105) logs the outgoing MSG91 payload at **ERROR** level on *every* OTP send (a "TEMPORARY DEBUG — remove" block).
- **Risk:** if the launch host ever lacks a configured SMS provider (or a path falls through), **plaintext OTPs are written to the log file and delivery silently "succeeds"** — anyone with log/file access (a team member, a support engineer, a log aggregator, a backup) can read OTPs and authenticate as any user. The TEMP MSG91 payload log is log-spam + potential secret leakage in production.
- **Fix direction:** gate `_logOtp` behind `APP_ENV !== 'production'` (or remove it) so a misconfigured prod fails loudly instead of logging secrets; remove the `MSG91_PAYLOAD_DEBUG` block; and enforce (launch checklist) that a real provider is configured. Never log OTPs or auth payloads in production.

**M2 — Customer access tokens effectively never expire (absolute-vs-duration bug).**
- **Where:** `heart/api/auth/verify-otp.php:146` and `heart/api/auth/verify-widget-token.php:127` call `JWT::encode([...], JWT_SECRET, time() + (86400*30))`. `JWT::encode($payload,$secret,$expiresIn)` computes `exp = now + $expiresIn` (`core/helpers/JWT.php:17-18`), so passing the **absolute** `time()+30d` yields `exp = now + (now + 30d)` ≈ **year 2083**. The intended 30-day session becomes ~55 years.
- **Risk:** these are the **primary customer login paths** (OTP + MSG91 widget). A leaked/stolen customer token (device theft, XSS, shared computer) stays valid essentially forever; the only revocation is the logout blacklist or a JWT-secret rotation. (AuthController email/Google/guest paths set `exp` correctly at now+30d; register/refresh use `JWT_EXPIRY`=1h correctly — so this is isolated to the two OTP paths.)
- **Fix direction:** pass a **duration** (`86400 * 30`) not an absolute timestamp, or set `exp` in the payload as the AuthController paths do. Consider shorter access-token TTLs + refresh across the board.

**M3 — Router-level rate limiting silently disabled without APCu.**
- **Where:** `heart/router.php` — `HeartRateLimiter::check()` (line 297-298): `if (!function_exists('apcu_add')) { return; }`. The IP (200/60s) and user (300/60s) flood guards use APCu in-memory counters and **no-op entirely if APCu is not installed**.
- **Risk:** on a host without APCu (common on basic shared hosting), the general request-flood / scraping guard is off. Mitigation: the *auth-specific* limits (`RateLimiter::check` for register/login/Google/guest — DB-backed `rate_limits` table) and the *per-phone OTP* throttle (`otps` query) are **DB-backed and still work regardless** — so brute-force/OTP-bombing of auth is still limited; only the broad per-IP/per-user flood ceiling depends on APCu.
- **Fix direction:** confirm APCu is enabled on the launch host, or add a DB/Redis-backed fallback for the router limiter (Redis env vars are already wired) so flood protection doesn't depend on an optional extension.

---

### 🟡 LOW

**L1 — 30-day access-token lifetime (design choice).** AuthController-issued tokens live 30 days (`AuthController.php` sets `exp = now + 30d`). Long for a bearer token but common in consumer apps and mitigated by the logout blacklist. Consider shorter access tokens + silent refresh, especially for **admin** tokens.

**L2 — Open self-registration as vendor.** `register.php` accepts `role=vendor_service|vendor_shopping` (`in:` whitelist, line 20), so anyone can create a vendor account. This is **by design** (vendors self-onboard) and the vendor row is created `status='pending'` needing admin approval before it is assignable/visible; work photos default to `pending`. Confirm the pending-gate holds on every vendor-facing surface (it did on the ones reviewed). Not a vulnerability, but a spam/abuse vector to watch (rate-limited by the register limiter, 3/300s).

**L3 — Debug/log hygiene.** `MSG91_PAYLOAD_DEBUG` and `MSG91_RESPONSE` are marked "TEMP … remove after debug", and OTP dev-mode logging exists (see M1). General pre-launch cleanup: strip temporary debug logging and lower log verbosity (`HEART_LOG_LEVEL=WARN`) so logs don't accumulate sensitive request detail.

---

## What is solid (verified, not assumed)

- **JWT (`core/helpers/JWT.php`):** HS256 only — `decode()` *always* recomputes the HMAC and `hash_equals()`-compares; it never reads `alg` from the header, so **`alg:none`/algorithm-confusion is impossible**. `exp`/`nbf` enforced. No forgery without `JWT_SECRET`.
- **Sessions (`AuthMiddleware.php`):** signature+expiry verified; requires `user_id`+`role`; **`token_blacklist` revocation** on logout; **fail-secure** (503/deny) if the blacklist check errors. Role separation is by the *signed* `role` claim (server-set at login; not user-influenced) — a customer/vendor cannot forge `role='admin'`, and register cannot self-assign admin.
- **Refresh (`refresh.php`):** refresh tokens are opaque `random_bytes(32)`, stored **hashed** in `refresh_tokens` with `expires_at`, and revocable; the new access token's role is loaded **fresh from the DB** (so a revoked/changed role takes effect), status re-checked.
- **RBAC (`Permission.php` + 18 `Permission::enforce` gates):** **fail-closed** — grants only via explicit role→permission rows; legacy `admin`/`super_admin` bypass consistently; any error/missing table → deny for non-admins. The new admin surfaces (Access Control, Cities) are RBAC-gated.
- **IDOR:** booking detail *and* list are ownership-scoped by role (`bookings/{id}` checks admin/vendor.vendor_id/customer.user_id — `services/index.php:1880-1892`; list scopes `b.vendor_id`/`b.user_id`); `payment/status.php` 403s if `row.user_id != caller`; `update-profile.php` writes `WHERE id = auth.user_id` and **whitelists only name/address** (no mass-assignment of role/status).
- **Money paths:** amounts are **server-resolved** — inspection/Expert-Visit fee comes from `servicePublicSetting`/`ExpertVisitPricing::resolve`, explicitly *"never the client's number"* (`services/index.php:1386`); order-create and webhook both re-check `sent/webhook amount == db total` and **reject/flag** mismatches (`PaymentService.php:64-67, 209-214`) rather than paying; webhook signature is HMAC-SHA256(timestamp+body) with **constant-time compare + a 5-minute replay window** (`WebhookVerifier.php`), verified **before any DB op** (`webhook.php`); processing is **idempotent**; ledger/settlement/webhook use `SELECT … FOR UPDATE`; settlement is pending-only + idempotent (no double-settle, no negative injection found).
- **Input validation:** PDO **prepared statements with bound params** throughout the surfaces reviewed; dynamic SQL fragments (throttle filters, schema-presence selects) are built from **server-side booleans, not user input**; search is parameterized. No SQL-injection found. Output is escaped via `_esc` (customer) / `escHtml` (admin).
- **File upload (`UploadHelper.php`):** **banking-grade** — size cap, extension whitelist, **real MIME via `finfo` magic bytes** (not the HTTP header), a **PHP/`<script>` polyglot scan** of the first 512 bytes, `getimagesize` sanity, **random filename** (`bin2hex(random_bytes(16))`), and a **sanitized folder** — no path traversal, no executable upload. Vendor work-photo upload routes through `UploadHelper::save`.
- **Rate-limiting:** router **dual-layer IP+user** (when APCu present) + **DB-backed** per-endpoint limits (register 3/300, login 5/300, Google/guest 10/300) + **per-phone OTP throttle** and **verify-attempt cap** (OTP brute-force blocked).
- **Audit trail:** **42 `Logger::info('Admin …')`** entries, each with `admin_id`, covering every sensitive action — role create/assign/unassign, permission changes, media approval, **settlement**, accountability threshold changes, throttle/vendor moderation, city add, user block. A team member cannot act invisibly.
- **Anti-bypass (partial):** customer contact is correctly hidden from vendors until a job is `in_progress`/`completed` (`vendors/index.php`), and the `work_throttle` paused/reduced enforcement holds across assign/cron/discovery. **The one leak in this shield is H1** (vendor contact exposed to customers).

---

## Must-fix before launch (shortlist)

1. **H1 — strip `owner_phone`/`owner_email` from public `GET /api/vendors`.** (Bypass + mass data exposure; small change.)
2. **H2 — require OTP proof before `register` can password/upgrade a passwordless account.** (Account integrity.)
3. **M1 — gate/remove `_logOtp` for production + remove the `MSG91_PAYLOAD_DEBUG` block; confirm a real SMS provider is set.** (Secret-in-logs.)
4. **M2 — fix the OTP token-expiry bug (duration, not absolute timestamp).** (Non-expiring customer sessions.)
5. **M3 — confirm APCu on the launch host or add a DB/Redis rate-limit fallback.** (Flood protection.)

The two HIGH items are the real gate; the three MEDIUM are prod-hygiene/config fixes best done in the same pass.

---

## Verdict

**Is it safe to handle real money at launch?** — **Yes, for a controlled pilot, once the 2 HIGH + 3 MEDIUM must-fixes are done.** The parts that actually protect money and identity are done properly and were the hardest to get right: server-authoritative pricing, a correctly-verified idempotent webhook with replay protection, row-locked and idempotent settlement, a clean non-forgeable JWT with revocation, fail-closed RBAC, real IDOR ownership checks, and hardened file uploads. There is **no CRITICAL flaw** and **no path found to move money incorrectly, forge a session, or escalate to admin**.

**Residual risk after the must-fixes:**
- **Session longevity** — even corrected, 30-day access tokens mean a stolen token is usable until logout/rotation; tighten (esp. admin) post-launch.
- **Host dependency** — flood protection leans on APCu; verify or add a fallback.
- **Operational hygiene** — the platform's abuse-resistance now depends on operators keeping debug logging off, SMS configured, and secrets out of `.env`-in-webroot (config loads `.env` from *outside* webroot — good — verify on the host).
- **Bypass is a moving target** — H1 is the code leak; the deeper anti-bypass game (customers/vendors colluding off-platform) is mitigated by value + contact-hiding, not eliminable by code alone.

**One-line:** *A real, well-built, banking-minded system with no critical holes — two targeted HIGH fixes (a vendor-contact leak that doubles as a bypass, and a register account-integrity flaw) plus three prod-hygiene fixes stand between it and a safe real-money pilot.*

---

*Read-only security audit. No code modified. Every finding cites a file/line in the `v2` branch; strengths are verified in code, not assumed. Two things intentionally out of scope here (they need a live environment): confirming APCu/SMS/HTTPS on the launch host, and dynamic testing (fuzzing, real token theft, load) — recommended as a staging security pass after the must-fixes land.*
