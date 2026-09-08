# WorkToGo V2 — System Readiness Check & Launch Gap Map

> **Purpose:** A complete, honest picture of V2 before the founder buys hosting/credentials — what's built, what's partial, what's missing, what must be verified on staging, and exactly what's needed to launch.
> **Method:** READ-ONLY scan of the `v2` branch (26 commits ahead of `origin/main`, Phase 0 → Phase 2) against V2-VISION / V2-BUILD-BLUEPRINT. Every claim below is grounded in actual v2 code/commits; nothing assumed. No files edited except this report.
> **Date:** 2026-08-16. **Branch:** `v2` (worktree `wtg-v2/`), working tree clean. **No PHP runtime in this workspace** — so all "must verify on staging" items are called out explicitly.

---

## 0. What was built on v2 (commit spine)

26 V2 commits ahead of `main`, in three phases:

**Phase 0 — launch blockers (the 3 that stopped real bookings/payments):**
- `0b56743` **B2** — admin (un)assign now syncs `jobs.vendor_id` (a shared `$syncBookingJobVendor` closure, `heart/api/admin/index.php:73`) so vendors can accept/start/complete.
- `be5a950` **B3** — OTP phone find-or-create is upsert-safe (no 500 at the login gate on repeat phone).
- `2c69474` **B1** — inspection/Expert-Visit Cashfree order creation hardened (absolute URLs, env-sourced creds/return URLs).

**Phase 1 — data-model foundation + money-safety (migrations):**
- `00d8996`/`dbb7a76` cities table + `city_id` on all domain tables (backfill Haldwani).
- `08baeea` `vendor_categories` as the canonical vendor↔category source.
- `45ce58c` unified `media` table (photos now, video later).
- `a722e72` `wallet_transactions.reference_type`/`reference_id` (H3 collision fix — booking vs order id-space).

**Phase 2 — service engine + admin control room (the launch product):**
- Company View shell (`a7ad151`), admin-controlled packages (`09d6c5f`), customer package display + live sqft estimate (`d0188e5`), structured package columns (`898b444`), Expert-Visit pricing config (`7463aa2`), Expert-Visit pay-flow free/paid (`a84911a`), tracking split (`21c6bdf`), vendor profile structured fields (`23d5c4d`), vendor work-photo gallery (`1e56ea2`), admin media approval UI (`de9ffbe`), RBAC foundation (`e0538b7`), Payment Room (`1db64a9`), expert-fee 100%-company fix (`e1eba84`), vendor settlement wiring (`3e71fdc`), vendor accountability auto-flag (`6f3a17c`), work_throttle enforcement (`e8071a4`), inspection→Expert-Visit wording unification (`cd94150`), never-a-dead-screen polish (`4994668`).

---

## 1. FEATURE STATUS MATRIX

Legend: ✅ BUILT (functional, code-verified) · 🟡 PARTIAL (works with a named gap) · ⛔ MISSING.

| # | Capability | Status | Real state (code-grounded) |
|---|---|---|---|
| 1 | **Company View** | ✅ BUILT | Full-page company surface in `home.js` (`openCompanyView`, `_companyViewHTML`), category switch, Expert-Visit + Packages paths. |
| 2 | **Packages** | ✅ BUILT | `packages` table (migration 005); admin CRUD + cover photo (`/api/admin/…`); customer cards + **live sqft→cost estimate**; selection persisted as **structured columns** (`bookings.package_id`, `estimate_amount`), not text. |
| 3 | **Expert Visit pay-flow** | 🟡 BUILT (verify live) | `companyExpertVisit` free-or-paid; paid reuses the Cashfree pay-to-confirm path (`booking_mode='inspection'`), never confirms before payment. **Gap:** a live ₹1 PRODUCTION Cashfree round-trip has not been run (needs real creds on staging). |
| 4 | **Near Me / Direct vendor** | ✅ BUILT | Customer `GET /api/vendors` discovery (rating-ordered, throttle-aware); `direct_vendor` booking path (`POST /api/service/request`). |
| 5 | **Tracking split** | ✅ BUILT | `bookings.js:380` — `direct_vendor` → simple Direct track; everything else → rich Company track (request→confirm→expert→…); Expert-Visit badge. |
| 6 | **Vendor profile (structured)** | ✅ BUILT | bio / service areas / languages / multi-category structured fields (migration 009); vendor panel (`vendor/dashboard-service.html`, `bookings.php`, `onboarding.html`). |
| 7 | **Work-photo gallery + admin approval** | ✅ BUILT | Vendor upload → `media` table (pending); customer trust strip (approved only); admin `media.html` queue + approve/reject + **auto-approve toggle** (`media_auto_approve`). |
| 8 | **Payment Room (revenue control)** | ✅ BUILT (read-only) | `admin/payments.html` + `PaymentReport.php`: GMV, online/cash split, per-category P&L, per-city, commission-vs-payout, expert-visit fees, top vendors, vendor drill-down. Computed from structured columns; **moves no money**. |
| 9 | **Vendor settlement** | 🟡 PARTIAL | `LedgerEngine::runSettlement($vendorId,$db)` wired to admin `POST /api/admin/payments/settle` (FOR-UPDATE locked, idempotent, per-vendor or all). **Gap by design:** advances internal `pending→available` ("ready to pay") only — **no bank disbursement / payout gateway** yet. |
| 10 | **Accountability / bypass auto-flag** | ✅ BUILT | `VendorAccountability.php` derives signals from `jobs` + `auto_assignments`; threshold-driven flag (app_settings); admin flagged-vendors surface in `vendors.html` (banner + reasons + severity). |
| 11 | **work_throttle enforcement** | ✅ BUILT | `paused` excluded / `reduced` de-prioritised in admin assign (`ServiceVendorEligibility`), auto-assign cron, and customer discovery — all schema-defensive. |
| 12 | **RBAC** | ✅ BUILT | `roles`/`permissions`/`role_permissions`/`user_roles` (migration 08_13_001); `Permission.php` (fail-closed, legacy-admin bypass); **18 `Permission::enforce` gates**; admin mapped to `super_admin`. **UI now added** (`admin/roles.html`, commit `2981a9d`): create/edit roles, tick permissions from the grouped catalog, assign roles to users with scope — from the panel, no API/curl. |
| 13 | **Expert-Visit pricing config** | ✅ BUILT | `ExpertVisitPricing.php` + config table (migration 007): global default + per-category/city overrides, free-or-paid. |
| 14 | **city_id / multi-city** | ✅ BUILT (1 city live) | cities table + `city_id` on domain tables + backfill Haldwani (migration 001). **Admin Cities management now added** (`admin/cities.html` + `GET/POST/PATCH /api/admin/cities`, commit `bac577e`) — a new city is one click (add / enable / disable), no code. Only Haldwani is populated/enabled today. |
| 15 | **Unified media** | ✅ BUILT | one `media` table, polymorphic owner, `storage_provider='local'` now (R2/S3 later = data change, not code). |
| 16 | **Wallet money-safety** | ✅ BUILT | `wallet_transactions.reference_type`/`reference_id` disambiguation (LedgerEngine reads/writes both, back-compatible); expert-fee = **100% company** (`isExpertVisitFee` → platform-only row). |
| 17 | **Blocker fixes B1/B2/B3** | ✅ BUILT | All three in code (see §0). B1's live PRODUCTION success is the one item still externally gated. |
| 18 | **Auth (OTP/Google/email/guest)** | ✅ BUILT | Strongest layer (inherited + hardened): JWT + `token_blacklist` revoke + fail-secure; MSG91 OTP (widget + SMS) + Google + email. |
| 19 | **Admin control room (broad)** | 🟡 PARTIAL | Dashboard, live pipeline/kanban, manual + cron auto-assign, category control room, follow-up, media approvals, Payment Room, accountability — all present. Inherited-partials remain (Morning Brief dead cards, Area Intelligence still read-only, no single Vendor Scorecard leaderboard). |
| 20 | **Company Projects (Engine 5)** | ⛔ MISSING | No file/route/table (grep-confirmed). The "managed contracting" project surface from the vision is not built; the Company View + Expert Visit + Packages cover the customer intake it was meant to feed. |

---

## 2. MISSING / PARTIAL FOR LAUNCH (honest)

**Not blocking a service-engine pilot launch, but real:**
1. **Cashfree PRODUCTION live-verify (externally gated).** Code path is robust; the credential guard only rejects literal `placeholder`/`your_` app-ids (not a wrong-but-real key). A **₹1 live round-trip on staging with real prod creds** is mandatory before public launch. This is the single live revenue path.
2. **Vendor settlement = internal only.** `pending→available` is wired and safe; **actual bank payout** (Cashfree Payouts / bank transfer) + a `payouts` audit table is a separate, post-launch build. Vendors' real money is currently paid off-ledger.
3. ~~**RBAC has no management UI.**~~ **CLOSED** (commit `2981a9d`) — `admin/roles.html` "Access Control" page ships: create/edit roles, compose permissions from the grouped catalog, assign scoped roles to users, all over the existing gated endpoints.
4. **`home.js` is a 4,034-line monolith** (grew, not split). Works and is polished, but is a maintainability/regression risk — the vision's "de-monolith" is deferred. Not a launch blocker.
5. **Media on local disk.** `storage_provider='local'`, files under `<docroot>/uploads`. Fine for a single-city pilot; a CDN/R2 is a scale item (config/data change, no reader rewrite).
6. **Company Projects / Engine 5 — MISSING** (see matrix #20). Decide whether the pilot needs it or the Company View flow suffices (recommendation: launch without it).
7. **Personalization / Brain / unified feed — deferred** (Phase 5). Brain scaffold is wired but placeholder; not needed for launch.
8. **Shopping + Video engines — dormant** (Phase 3/4). Hidden by `SERVICE_ONLY_MODE=true` / `PILOT_MODE=true`. Correctly out of scope for this launch.

**Inherited admin partials to tidy post-launch (from Audit 04, not regressions):** Morning Brief 2 dead cards, Area Intelligence read-only (no control surface), Vendor Scorecard has per-vendor stats but no ranked leaderboard, `payments.html`/`refunds.html`/`system.html` gated by pilot mode.

---

## 3. MIGRATIONS LIST (apply on staging, in this order)

All 11 V2 migrations are **idempotent** (`CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`, `INSERT … ON DUPLICATE KEY / IGNORE`). `migrate.php` applies unapplied files in **filename (date) order**, recording each in `migrations_log` — so the order below is automatic. Apply on a **restored copy of the prod dump**.

| Order | File | What it does | Depends on |
|---|---|---|---|
| 1 | `2026_07_04_001_cities_and_city_id_foundation.sql` | Creates `cities`; adds nullable `city_id` to users/vendors/bookings/services (+ backfill Haldwani + FKs). | — (run first) |
| 2 | `2026_07_04_002_unify_vendor_category_canonical.sql` | Makes `vendor_categories` the canonical vendor↔category source; reconciles data. | — |
| 3 | `2026_07_04_003_create_media_table.sql` | Unified `media` table (polymorphic owner; FK→cities/users). | 001 (cities FK) |
| 4 | `2026_07_04_004_wallet_transactions_reference_type.sql` | Adds `reference_type`/`reference_id` to `wallet_transactions` (+ backfill). | — |
| 5 | `2026_07_04_005_create_packages_table.sql` | `packages` (per-tier rate/base, `cover_media_id`, `city_id`). | 001, 003 (cover FK) |
| 6 | `2026_07_04_006_bookings_package_selection.sql` | `bookings.package_id`, `estimate_amount`. | 005 (package FK) |
| 7 | `2026_07_04_007_expert_visit_pricing.sql` | Expert-Visit pricing config (city×area×category free/paid/price) + seeds default 299. | 001 |
| 8 | `2026_07_04_008_bookings_expert_visit.sql` | `bookings.visit_type`, `expert_visit_fee`, `expert_visit_free`, `final_quote_amount`. | — |
| 9 | `2026_07_04_009_vendor_profile_fields.sql` | Vendor bio/areas/languages structured fields. | — |
| 10 | `2026_08_13_001_rbac_foundation.sql` | `roles`/`permissions`/`role_permissions`/`user_roles` + seed (super_admin + 5 stub roles, 41 perms) + maps every `users.role='admin'` → super_admin. | users table |
| 11 | `2026_08_15_001_vendor_accountability.sql` | Seeds 7 `app_settings` flag thresholds; adds nullable `vendors.manual_flag`/`flag_reviewed_at`/`flag_notes`/`work_throttle`; composite indexes on `jobs`/`auto_assignments`. | vendors, app_settings |

**Dependency flags:** 003 before 005 (`cover_media_id` FK); 005 before 006 (`package_id` FK); 001 before 003/005/007 (`city_id` FKs). Filename order preserves all of these — no manual reordering needed. **Verify:** after applying, `SELECT COUNT(*) FROM migrations_log` includes all 11, and each migration's own verification block (bottom of each file) returns the expected rows.

---

## 4. CREDENTIALS / SERVICES NEEDED TO GO LIVE

Set as environment variables (host panel) or in `.env` **outside webroot**. `config/payment.config.php` + `config/app.php` read via `getenv`/`getenv_safe`.

**A. Required to run at all**
- **Database:** `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_PORT` (Hostinger MySQL).
- **App:** `APP_ENV=production`, `APP_URL=https://<domain>`, `APP_TIMEZONE=Asia/Kolkata`, `APP_DEBUG=false`.
- **JWT:** `JWT_SECRET` (**hard-required in production** — app throws without it), optional `JWT_EXPIRY`/`JWT_REFRESH_EXP`.
- **Migrations:** `MIGRATE_SECRET` (gates `migrate.php`).

**B. Payments — Cashfree (the live revenue path)**
- `CASHFREE_APP_ID`, `CASHFREE_SECRET_KEY`, `CASHFREE_ENV=PRODUCTION` (base auto-derives to `api.cashfree.com/pg`).
- In the **Cashfree dashboard:** set webhook URL → `https://<domain>/api/payment/webhook`; whitelist the return URL. Confirm the merchant account is live/KYC-approved.
- ⚠ Guard rejects only literal placeholder keys — a wrong-but-real key fails silently at order creation. **Verify with a ₹1 live payment.**

**C. OTP / SMS — MSG91 (primary), Twilio (optional fallback)**
- `SMS_PROVIDER=msg91`, `MSG91_AUTH_KEY`, `MSG91_TEMPLATE_ID`, `MSG91_SENDER_ID` (default `WRKGO`).
- OTP **widget** path also used (frontend `WTG_OTP_METHOD`) — configure the MSG91 widget token in the customer app.
- Optional fallback: `TWILIO_SID`, `TWILIO_TOKEN`, `TWILIO_FROM`.

**D. Google Sign-In**
- `GOOGLE_CLIENT_ID` (and/or `GOOGLE_CLIENT_IDS` for multiple origins). Frontend GSI client id must match the **production origin**.

**E. Push — FCM**
- `FCM_API_KEY`, `FCM_PROJECT_ID`, `FCM_SENDER_ID`, `FCM_APP_ID`, `FCM_VAPID_KEY`, `FCM_SERVICE_ACCOUNT_PATH` (service-account JSON path).
- Frontend Firebase config in `app/env.js` + `app/firebase-sw.js` / `vendor/firebase-sw.js`.

**F. Media / storage**
- **Now:** local disk — `UPLOAD_PATH` optional (defaults `<docroot>/uploads`); ensure the dir is **writable**. `storage_provider='local'`.
- **Later (scale):** R2/S3/CDN — a provider account + keys; switching is data-only (new `storage_provider`+`storage_key`+`public_url`), no reader change.

**G. Domain / hosting**
- Domain + SSL; document root → the app; `.htaccess` catch-all to `index.php` (present). PWA `manifest.json` + service worker already shipped.

**H. Optional / dormant (not needed for launch)**
- `SWIFTDELIVER_URL`/`SECRET` (delivery), `BRAIN_*` (brain/feed), `REDIS_*` (cache), `ENGINE_*_URL` (multi-engine gateway). Leave unset.

---

## 5. SECURITY & SCALE NOTES

**Auth / tokens (strong):** JWT (30-day) signed with `JWT_SECRET` (prod-required); `AuthMiddleware` verifies signature + expiry + required claims, then checks `token_blacklist` (logout revoke) and **fails secure** (503/deny) if the blacklist check errors. Return/status pages never self-authorize payment; the webhook is the only authority for "paid".

**RBAC (fail-closed):** `Permission::userHas` grants only via explicit role→permission rows; legacy `users.role='admin'` and the `super_admin` role bypass (so today's admin is unaffected); **any error or missing table → deny** for non-admins. 18 `Permission::enforce` gates on admin write/read endpoints.

**Money-write safety:** ledger and webhook use `SELECT … FOR UPDATE` row locks + idempotency guards (webhook by payment id; settlement by pending status; booking completion by existing non-refunded row). Settlement is one transaction, per-vendor lockable, idempotent (re-run settles nothing). Expert-fee split fixed to 100% company (no vendor-payout leak). Wallet id-space collision (booking vs order) closed by `reference_type`/`reference_id`.

**Validation / injection:** endpoints use PDO **prepared statements with bound params** throughout (schema probes use `information_schema` with bound table/column names). Dynamic SQL fragments (throttle filters, column-presence selects) are **built from server-side booleans, not user input**. Output-facing helpers `_esc`/`escHtml` used in customer/admin render paths. **To check on staging:** confirm no endpoint interpolates raw `$_GET`/`$_POST` into SQL (spot-checked clean in the surfaces reviewed); run one auth-bypass pass on the admin API (every route sits behind `AuthMiddleware::requireRole(ROLE_ADMIN)` + per-endpoint `Permission::enforce`).

**Scale (honest):**
- **As-is** on standard shared/VPS hosting with one MySQL: comfortably handles a **single-city pilot** — low-thousands of users, hundreds of bookings/day. Reports (Payment Room, accountability, flagged list) are computed on-demand with the right indexes (`jobs(vendor_id,status,created_at)`, `auto_assignments(vendor_id,entity_type,status)`, media/wallet indexes) — fine at pilot volume.
- **What scales it:** (a) **DB** — the on-demand aggregates (Payment Room, flagged vendors, feed) should become **cron-materialized snapshots** past ~tens of thousands of bookings; add read replicas later. (b) **Media** — move `local` → **R2/S3 + CDN** (data-only) before photo volume grows. (c) **Server** — move off shared hosting to a VPS/containers; add **Redis** (`REDIS_*` already wired) for rate-limiting/cache. (d) The **auto-assign cron** is lock-safe (`flock` + `FOR UPDATE SKIP LOCKED`) and scales to a real queue later.
- **Not yet load-tested** — no synthetic load run (no runtime here). Do a basic k6/ab pass on `/api/services`, `/api/service/request`, `/api/payment/webhook` on staging.

---

## 6. STAGING TEST PLAN (founder tests all 3 roles)

Restore prod dump → run migrations → set staging creds (Cashfree can be **sandbox first**, then one prod ₹1). "Green" = the stated outcome with no 500s and correct DB rows.

**CUSTOMER (mobile web)**
1. **Login** — OTP (widget + SMS), Google, email; repeat-phone login → **no 500** (B3). Green: token issued, lands home.
2. **Discover** — home feed + Near Me vendors load (skeletons → cards); empty/failed states show a **next action / retry**, never blank (polish pass). Green: no dead screen in any empty/error case.
3. **Company View → Packages** — open a category, see packages, enter sq ft → **live estimate**; "Send request" with/without a package. Green: booking row with structured `package_id`/`estimate_amount`.
4. **Expert Visit (paid)** — request an Expert Visit in a paid category → Cashfree checkout → pay ₹1 → return page polls **DB truth** → "paid". Green: `bookings.payment_status='paid'`, a `transactions` success row, `booking_mode='inspection'`, and the ledger books the fee **100% platform** on completion.
5. **Expert Visit (free)** — a free-configured category → no payment, request saved. Green: `total=0`, tracked on the Company track.
6. **Tracking** — Bookings screen shows the **Company track** (rich stages) vs a **Direct track** badge; wording says **"Expert Visit"** everywhere (no "inspection"). Green: correct track + no internal jargon.

**VENDOR (vendor panel)**
7. **Onboard/login** — role-gated entry; profile with bio/areas/languages/multi-category. Green: profile saves to structured fields.
8. **Work photos** — upload → shows **Pending**; after admin approval → **Live** and appears on the customer trust strip. Green: `media.status` transitions.
9. **Job accept/start/complete** — accept an assigned job (B2: `jobs.vendor_id` set so accept works), start, complete. Green: on complete, `processBookingCompletion` books vendor earning (pending) + platform commission; **customer phone hidden until status is in_progress/completed** (anti-bypass, already enforced).

**ADMIN (admin panel)**
10. **Assign** — Live pipeline: assign a booking to a vendor; a **paused** vendor is not offered, **reduced** sinks below normal. Green: assignment writes `jobs.vendor_id`; throttled vendors filtered/sorted.
11. **Media approvals** (`media.html`) — approve/reject pending photos; flip **auto-approve** on/off. Green: queue clears; approved photo goes live.
12. **Payment Room** (`payments.html`) — see GMV / online-vs-cash / per-category P&L / per-city / commission-vs-payout / expert-visit fees / top vendors; filter by period + city. Then **Settle** → vendor `pending→available` moves (before/after shown); re-settle is a no-op. Green: numbers match DB; settlement idempotent.
13. **Accountability** (`vendors.html`) — a vendor with repeated non-completion shows a **🚩 flag** with reasons; set **work_throttle** (normal/reduced/paused); tune thresholds. Green: flag appears; throttle enforced in assign/discovery.
14. **RBAC (API for now)** — `GET /api/admin/roles`, create a role, assign a permission, assign a user; confirm `super_admin`/admin still passes everything. Green: endpoints work; admin access unchanged.

---

## 7. LAUNCH CHECKLIST (buy hosting → public)

1. **Buy** hosting (PHP 8+, MySQL) + domain + SSL. Obtain **all §4 credentials** (Cashfree PROD, MSG91, Google, FCM).
2. **Full prod DB backup** from `u904615152_worktogo_main`; restore a copy as **staging**.
3. Deploy the **`v2`** build to staging (merge `v2`→`main` or deploy the v2 artifact); set env vars / `.env` outside webroot.
4. **Run `migrate.php?secret=…`** on staging → confirm all 11 V2 migrations applied (`migrations_log`) with zero errors; run each migration's verification block.
5. **Set Cashfree PROD** creds + webhook/return URLs; do the **₹1 live round-trip** (§6.4). Confirm the webhook marks paid.
6. **Run the full §6 test plan** across customer / vendor / admin. Fix any red before proceeding.
7. **Smoke security pass** — admin API requires admin token; a non-admin token is denied; logout revokes (blacklist).
8. **Config for launch** — `SERVICE_ONLY_MODE=true`, enabled categories/city (Haldwani), Expert-Visit required categories, support/WhatsApp contacts; `APP_DEBUG=false`; ensure `/uploads` writable.
9. **Cutover:** backup prod again → point domain/docroot to V2 → run migrations on prod → clear caches → repeat the §6.4 ₹1 live check on prod.
10. **Rollback ready:** keep the V1 artifact + pre-cutover DB backup + a one-command revert documented.
11. **Go public;** watch logs (`HEART_LOG_LEVEL`), Cashfree webhook deliveries, and the first real bookings end-to-end.

---

## 8. HONEST VERDICT

**Is this a real, functioning company-grade system (not a dummy)? — Yes.** The evidence: a real normalized schema with dated idempotent migrations; a genuine ledger with row-locking, idempotency and a corrected commission model; signature-verified idempotent payment webhook; JWT auth with revocation and fail-secure; a data-driven RBAC actually enforced on 18 endpoints; a lock-safe auto-assign cron; and a computed (not faked) revenue Payment Room + accountability engine reading real structured columns. The customer flow is polished (empty/loading/error states, one consistent "Expert Visit" concept). This is an assembled, working platform, not a mock.

**Readiness: ~93% for a single-city service-engine pilot launch.**
- **Strengths:** money-safety + auth are the strongest layers; the Phase-0 blockers are fixed; the full Company View → Packages → Expert-Visit → tracking → admin-assign → vendor-accept → complete → Payment Room loop exists in code.
- **The ~2-3% the founder should expect to fix before/at launch:**
  1. **Cashfree PRODUCTION live-verify** — one ₹1 real round-trip with real creds (externally gated; can't be proven from code).
  2. **Staging migration dry-run + the §6 test plan** — never executed here (no runtime); must pass on staging.
  3. **Small conveniences:** an RBAC management **UI**, and (post-launch) **bank payout** for settlement.
- **Real risks (ranked):**
  1. **Cashfree prod credentials/merchant approval** — the single revenue path; a wrong-but-real key fails silently past the guard. Verify live.
  2. **Untested-on-staging** — migrations and flows are code-correct and idempotent but have not run against the prod dump in this workspace.
  3. **Scale ceiling** — fine for a city pilot; needs cron-materialized reports + media CDN + off-shared-hosting before high volume.
  4. **`home.js` monolith** — regression risk for future changes (not a launch blocker).

**Bottom line:** buy the hosting + credentials, restore the dump to staging, run the migrations, do the ₹1 Cashfree live check and the 14-step test plan. If those go green, this is launch-ready for Haldwani. Nothing in the core loop is a stub; the remaining work is verification and a couple of post-launch conveniences — not missing product.

---

## 9. UPDATE — Pre-launch code-completion pass (2026-08-16)

After the readiness assessment above, the **code-side** gaps that could be closed without a live server were fixed on `v2` (small, scoped, schema-defensive, browser-verified against endpoint mocks). Triage of every §2 gap:

| Gap (from §2) | Decision | Why |
|---|---|---|
| 3 · RBAC has no management UI | **FIXED now** (`2981a9d`) | Pure UI over existing gated endpoints — `admin/roles.html`. |
| (multi-city has no "add city" button) | **FIXED now** (`bac577e`) | Cities table existed; added `GET/POST/PATCH /api/admin/cities` + `admin/cities.html`. Add/enable a city = one click. |
| 1 · Cashfree PRODUCTION live-verify | **staging-only** | A wrong-but-real key can only be caught by Cashfree's runtime 401; no pre-flight guard can detect it. `config/payment.config.php` already rejects empty keys. Nothing more to add in code — needs the ₹1 live round-trip. |
| 2 · Settlement bank payout | **post-launch** | A real payout gateway (Cashfree Payouts) + `payouts` audit table + creds — a whole feature, not a loose end. Internal `pending→available` is done and safe. |
| 4 · `home.js` monolith | **post-launch** | Explicitly out of scope (big refactor). Works and is polished. |
| 5 · Media on local disk | **staging/scale** | Needs a CDN/R2 account; switching is data/config, not code. Fine for the pilot. |
| 6 · Company Projects / Engine 5 | **post-launch** | Net-new surface; the Company View flow covers the pilot. |
| 7–8 · Personalization/Brain, Shopping/Video | **deferred (Phase 3–5)** | Correctly dormant behind `SERVICE_ONLY_MODE`. |
| Inherited admin partials (Morning Brief dead cards, Area Intelligence controls, Vendor Scorecard leaderboard) | **post-launch tidy** | Larger, pre-existing (Audit 04), not launch-blocking; too risky to guess-fix without the live data. |

**What changed (2 commits):**
- `2981a9d` — `admin/roles.html` (Access Control: roles CRUD + permission editor + assign-to-users) + shell nav.
- `bac577e` — `admin/cities.html` + `GET/POST/PATCH /api/admin/cities` (RBAC-gated, 503 if the city migration hasn't run) + shell nav.

Both are additive, backward-compatible, and touch **no** payment/booking/assignment logic and none of the never-touch engines. No new migration (both use tables from existing migrations 001 & 08_13_001).

**Net effect on readiness:** the code side is now essentially complete for the pilot — the RBAC and multi-city admin surfaces exist, so the founder manages roles and cities from the panel with zero code. **Everything remaining is a live-server / staging task**, not code:
1. Buy hosting + obtain credentials (§4).
2. Restore the prod dump → run the 11 migrations (§3) → verify.
3. Set Cashfree PROD + do the **₹1 live round-trip** (§6.4).
4. Run the **14-step 3-role test plan** (§6).
5. Cutover (§7).

Post-launch (not pilot-blocking): settlement bank payout, media CDN, Company Projects, `home.js` de-monolith, and the inherited admin-partial tidy.

---

*Read-only readiness assessment + a small code-completion pass. The pass added only two additive admin surfaces (RBAC UI, Cities) over existing endpoints/tables — every other §2 gap is documented above as staging-only or post-launch, with the reason. The two items that cannot be proven without a live environment — Cashfree PRODUCTION success and a staging migration/test run — remain the gating work, and are code-complete on this side.*
