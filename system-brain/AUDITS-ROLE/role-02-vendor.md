# Role Audit 02 — VENDOR / MERCHANT (deep, world-class stress-test)

> **Scope:** Launch-readiness deep-check of the **vendor/merchant role** (our partner), grounded in real `wtg-v2` code (git branch `v2`) as of **2026-09-02**.
> **Method:** Became the vendor. Walked the full journey — signup → apply → approval → profile/listing → jobs → work-photos → earnings → the conversational vendor-agent — and traced each to its backend handler (`body/service-engine/api/vendors/index.php`, `api/services/index.php`, `api/assignments/accept.php`, `heart/api/auth/*`, `core/agent/tools/VendorTools.php`, `SlotController`, `VendorAnalyticsController`). Cross-read `role-01-admin.md` for continuity.
> **Read-only.** Nothing edited/moved/renamed/deleted. `shopping-engine`, `video-engine`, `orders.js` read only. "Where to fix X" cites `MAP/system-map.md` §C.
> **Verdict up front:** For the **launch scope (service vendor, single city)** the vendor role is **~78% ready**. The *operational* journey — get in, build a listing, receive and run jobs, post work photos — is genuinely well-engineered and hardened (race-safe accept, strong IDOR protection, customer-phone-hidden-until-start, bulletproof uploads/OTP). The one **serious partner-trust gap is earnings transparency: a vendor cannot see their own wallet, commission, net payout, or COD cash anywhere.** Merchant self-serve is essentially absent at launch (commerce is admin-managed and OFF).

---

## 0. The vendor identity model (what everything rests on)

| Fact | Where | Consequence |
|---|---|---|
| A vendor is a **`users` row (role `vendor_service`/`vendor_shopping`) + a `vendors` row** (`user_id` FK). Identity is the shared platform login — no per-engine user table. | `body/service-engine/api/vendors/index.php:33-45` (`resolveVendorId`) | One login for customer→vendor. Every vendor endpoint resolves `vendors.id` **from the JWT `user_id`**, never trusts a client-supplied vendor id → strong IDOR posture. |
| The JWT `role` claim is **baked at login**. Approval changes `users.role` in the DB but not the live token. | `heart/api/auth/AuthController.php:174-179`; approve at `heart/api/admin/index.php:1802-1819` | After approval the vendor **must log out and back in** to get a `vendor_service` token. The panel detects this and `alert()`s them (`vendor/shared/auth.js:71-76`). Real friction (§2, §4-E9). |
| Vendor role gates every vendor route via `AuthMiddleware::requireRole(ROLE_VENDOR_SERVICE, …)`. Admin can act on any vendor (bypass). | `vendors/index.php:248,297,333`; `AuthMiddleware.php:28-47` | RBAC "vendor role" is the **legacy enum**, same as admin — the new data-driven RBAC (role-01 §3) is **not** what gates vendors; it's dormant here too. |

---

## 1. VENDOR JOURNEY — end to end (grounded)

### 1A. REGISTER / LOGIN — **BUILT, well-hardened**
- **Passwordless (H2) path — the primary India flow.** `POST /api/auth/otp/send` → 6-digit OTP, **bcrypt-hashed**, expiring, stored in `otps`; `POST /api/auth/otp/verify` → **auto-creates a `ROLE_CUSTOMER` account on first verify** (`verify-otp.php:82-108`) and issues a 30-day JWT + refresh token. Also supported: **email/password** register/login and **Google** sign-in (`AuthController.php`).
- **Abuse hardening (strong):** per-phone resend throttle (`OTP_MAX_ATTEMPTS`/`OTP_RESEND_WAIT`) **and** per-IP ceilings on both send (`OTP_SEND_IP_MAX=20/600s`) and verify (`OTP_VERIFY_IP_MAX=30/600s`) to stop SMS-cost bombing and distributed brute-force (`send-otp.php:32-36`, `verify-otp.php:29-33`). Login is rate-limited 5/300s.
- **Channel failover:** delivery goes through the Smart Auth Layer (`OtpChannelManager`, MSG91 primary → configured secondary). **Fails LOUD in prod** — if no real SMS provider is configured, `_logOtp` returns `false` and the caller reports "Failed to send OTP" instead of silently "succeeding" (`send-otp.php:185-205`). OTP is never returned in prod.
- **Becoming a vendor:** a logged-in user `POST /api/vendors` (`business_name` + `type`) → inserts a **`status='pending'` vendor row**, inherits the user's city, notifies admin via FCM (`vendors/index.php:48-121`). Duplicate guard: **409 `VENDOR_ALREADY_EXISTS`** if a vendor row already exists for the user. Self-serve UI is `vendor/onboarding.html`; the vendor-agent stepper is an alternative (§1E).
- **Where it FAILS / recovers:**
  - OTP provider down / misconfigured → API returns **500 "Failed to send OTP"**. This **dead-ends unless the login UI offers password/Google** as an alternate — `methods.php` enumerates available methods, but the *recovery hand-off* is UI-dependent, not guaranteed. (§4-E1.)
  - Concurrent/duplicate first-login on the same phone → recovered as a login, not a 500 (Blocker B3 handling, `verify-otp.php:109-133`). Suspended/soft-deleted accounts return a clear coded error.

### 1B. PROFILE / LISTING — **BUILT, structured**
- `PATCH /api/vendor/profile` writes a rich, **structured** profile (`vendors/index.php:386-518`): name/phone (→`users`), description, **multi-category** (canonical `vendor_categories` junction, primary mirrored to `vendors.category_id`), logo, **experience_years (clamped 1–50)**, **service areas** (canonical `vendor_service_areas`, city-scoped, plus a `service_localities` text mirror the matcher reads), **languages** (JSON array). Every field is `tableHasColumn`-guarded → degrades gracefully pre-migration. Optional password change requires current+new, ≥8 chars, bcrypt.
- **Photos:** `POST /api/vendor/profile/photo` (logo) and a full **work-photo gallery** (`/api/vendor/work-photo` POST, `/work-photos` GET own-all-statuses, `/work-photo/{id}` DELETE own-only soft-delete, and a **PUBLIC `/api/service/vendor/{id}/work-photos` that returns approved-only**). Photos default **PENDING** → admin moderation queue (role-01 §1-7), unless `media_auto_approve` is on.
- **Availability:** `POST /api/vendor/availability` sets weekly slots (`day_of_week`, start/end, `max_bookings`) into `vendor_availability_slots` (replace-all); `GET /api/vendor/availability/{id}` returns day slots minus reservations (`SlotController`).
- **Uploads are bulletproof** (`core/helpers/UploadHelper.php`): 5 MB cap, MIME **and** extension whitelist (jpg/png/webp), `fileinfo` content check, **PHP-in-image malicious-content scan**, `getimagesize` validation — clear 400s on each failure.

### 1C. JOBS — **BUILT, race-safe accept, phone-privacy correct**
- `GET /api/vendor/jobs` (own, `status` filter) + `GET /api/vendor/jobs/{id}` — ownership enforced against the **resolved `vendors.id`** (`vendors/index.php:296-329`). **Customer phone is nulled until the job is `in_progress`/`completed`** in both list and detail (`:282-289`, `:323-325`) — "hidden-until-start" ✅.
- **Accept (race-safe path):** `api/assignments/accept.php` — `SELECT … FOR UPDATE` on the `auto_assignments` row **and** the job/order row, checks status is still open, and rejects with **409 "already accepted by another vendor"** if someone won the race. Prepared statements throughout, transactional, notifies customer+vendor. This is production-grade.
- **Status transitions:** `PATCH /api/jobs/{id}/status` (accept→`assigned`, `in_progress`, `completed`, or **reject→requeue**). Transactional, ownership-checked, mirrors status onto the booking + maintains a lifecycle timeline. **Reject** cleanly returns the job to the admin queue (`vendor_id=NULL`, `status=open`, booking→`pending`, `vendor_route='admin_queue'`). *Caveat:* this path does **not** `SELECT … FOR UPDATE` the job before writing — a minor TOCTOU vs. a simultaneous admin reassignment (`services/index.php:2301-2411`, §4-E7).
- **Throttle effects (confirmed in role-01):** `work_throttle='paused'` vendors are **excluded** from `cron/auto_assign.php` and the vendor eligibility list; `reduced` are de-prioritised. *But* a paused vendor can still log in and act on **existing** jobs — throttle gates new assignment only (§4-E6).

### 1D. EARNINGS — **the big gap: no vendor-facing money view**
- The payout ledger (`vendor_wallets.pending_balance/available_balance`, `wallet_transactions`, commission, settlement) is read **only by admin / LedgerEngine / PaymentReport** — **grep confirms no vendor-facing endpoint touches `vendor_wallets`.** The admin settles vendors (role-01 §1-6), but the vendor has **no screen** for: their **commission rate**, **net earnings**, **pending payout**, **settlement history**, or **COD cash collected/owed**.
- The closest thing, `GET /api/vendor/service-analytics/stats`, exposes **gross** figures only — `revenue_week` and average ticket computed from `transactions` (`VendorAnalyticsController.php:53-59`), **not net-of-commission earnings** — and it is behind `VENDOR_ANALYTICS:false`, i.e. **off at launch** (`vendor/config.js`, `vendor/dashboard.php:89`, `vendor/bookings.php:187`).
- **Net effect:** at launch a partner cannot answer *"how much have I earned, what's my cut, and when do I get paid?"* from anywhere in the product. For a partner-facing role this is a **trust-critical** gap (§7 must-fix).

### 1E. VENDOR-AGENT — **BUILT, maps correctly, admin-approval intact**
- `core/agent/tools/VendorTools.php`: **`start_listing`** (conversational stepper in Hindi, fetches real categories so the vendor taps not types), **`save_listing`** (→ `PATCH /api/vendor/profile` via a clean adapter; **stays a draft, admin approves** — the agent proposes, never self-approves), **`my_status`** (composes profile + service-analytics + work-photos into a friendly status + data-driven growth hints), **`set_reminder`**, and reused **`upload_work_photo`**.
- Guardrails intact: RBAC scoped to `vendor_*`, re-checked by the real endpoints; **no PII to the AI** (PiiWall); writes confirmation-gated; every step audited. So "run your business by talking" works and cannot bypass admin approval or the money/identity rules. ✅

---

## 2. DEVICE / UX REALITY (India)

| Aspect | Finding | Verdict |
|---|---|---|
| **Viewport / mobile-first** | Every vendor surface sets `<meta name="viewport" width=device-width>` (`dashboard-service.html:5`, `onboarding.html:5`). Layouts are CSS-driven, not fixed-width. | ✅ Mobile-first intent is there. |
| **Weight on a cheap phone / weak internet** | `dashboard-service.html` is **59 KB** HTML with inline CSS + ~1 300 lines JS, and pulls **Chart.js from a CDN — unpinned** (`https://cdn.jsdelivr.net/npm/chart.js`, i.e. "latest", no version, no SRI) (`:8`). On weak 2G/3G or a blocked CDN the chart fails (there's a zero-data fallback render, so the page survives). | ⚠️ **Unpinned CDN dep** = load-time + supply-chain + version-drift risk. Pin it and self-host or lazy-load. |
| **Onboarding weight** | `onboarding.html` loads `canvas-confetti` (pinned 1.9.2 — good) and uses `ui-avatars.com` (external image for the default avatar). Confetti is cosmetic weight; the external avatar is a 3rd-party image call per load. | ⚠️ Minor; drop confetti / self-generate avatar for weak links. |
| **Feels fast?** | Dashboards fetch several endpoints on load (profile, jobs, stats, chart) — fine on wifi, several serial round-trips on 3G. No obvious skeleton/optimistic UI. | 🟡 Acceptable; not optimised for 3G. |
| **Re-login friction** | Post-approval the vendor is bounced with a blocking **`alert()`** telling them to log out/in (`auth.js:71-80`). Ugly and easy to misread as an error. | ⚠️ Replace with a smooth re-auth or auto-refresh of the token. |
| **Staging config** | `vendor/config.js` `BASE_URL` is **hardcoded to `https://worktogo.in`** (prod), only overridable via a global `window.WTG_BASE_URL`. | ⚠️ Staging must set `window.WTG_BASE_URL` or the panel talks to prod (§4-E10). |
| **Dead feature flags** | `VENDOR_ONBOARDING`, `SHOPPING_VENDOR_UI`, `SERVICE_PROOF_UPLOAD` are declared in `config.js` but **referenced nowhere** — only `VENDOR_ANALYTICS` and `SERVICE_ONLY_MODE` are enforced. | ⚠️ False sense of control; a flag that doesn't gate anything will mislead whoever flips it. |

**Device verdict:** responsive and mobile-first by construction, and it degrades rather than breaks — but it is **not yet tuned for cheap phones on weak internet** (unpinned CDN chart lib, several serial fetches, external avatar/confetti, alert()-driven UX). No layout is *broken* on small screens from what the markup shows, but it should be verified on a real ₹6–8k Android in a 3G throttle before launch.

---

## 3. WHAT A VENDOR CAN CREATE / EDIT

| Entity | Endpoint | Lands in | Structured? |
|---|---|---|---|
| Vendor profile (bio, exp, logo, phone/name) | `PATCH /api/vendor/profile` | `vendors` + `users` | ✅ typed columns, clamped exp |
| Categories (skills) — multi | same | `vendor_categories` junction (+ `vendors.category_id` primary mirror) | ✅ clean junction |
| Service areas | same (`service_localities`) | `vendor_service_areas` (city-scoped) + text mirror | ✅ junction + compat mirror |
| Languages | same | `vendors.languages` (JSON array) | ✅ normalised JSON |
| Logo photo | `POST /api/vendor/profile/photo` | `vendors.logo_url` (file in `uploads/`) | ✅ validated upload |
| Work-photo gallery | `POST /api/vendor/work-photo` | unified `media` table (provider=local, `pending`) | ✅ moderated, public shows approved-only |
| Availability | `POST /api/vendor/availability` | `vendor_availability_slots` (replace-all) | ✅ typed slots |
| Password | `PATCH /api/vendor/profile` (current+new) | `users.password` (bcrypt) | ✅ |
| Reminders (via agent) | `set_reminder` tool | `agent_reminders` | ✅ |
| **Merchant: products / orders** | `/api/vendor/products`, `/api/vendor/orders` | **dormant shopping-engine** (READ-only, `SERVICE_ONLY_MODE`) | see §5 — off at launch |

The data structures are clean and normalised (junctions for many-to-many, a unified media table, city-scoped areas). No blob-dumping.

---

## 4. EDGE CASES & FAILURE MODES (broken on paper)

- **E1 — OTP not delivered / provider down.** *Symptom:* `POST /otp/send` → 500 "Failed to send OTP"; vendor can't get in unless the login screen offers password/Google. Prod fails **loud** (good) but the **fallback hand-off is UI-dependent**. *Fix:* `lib/auth/OtpChannelManager` (add a live secondary channel) + guarantee the login UI surfaces alternate methods from `heart/api/auth/methods.php` (system-map §C "OTP not arriving → lib/auth").
- **E2 — Duplicate signup / two vendors, same phone.** *Symptom:* none — `users.phone` is UNIQUE; the 2nd person logs into the **existing** account (Blocker B3, `verify-otp.php:109-133`), and a 2nd vendor application on the same user returns **409** (`vendors/index.php:74-81`). Handled. *Watch:* a shared phone means two real people share one identity — a business reality, not a bug. *Fix if needed:* identity policy, not code.
- **E3 — Photo upload fails / oversize / malicious.** *Symptom:* clear 400 ("too large / invalid extension / malicious content detected"). Fully handled by `UploadHelper` (system-map §C "upload → UploadHelper + Media").
- **E4 — IDOR (see another vendor's data).** *Symptom:* none found — jobs, job detail, work-photo delete, and profile all resolve `vendors.id` from the JWT and enforce ownership (403 otherwise); customer phone is masked pre-start. **Strong.** *Fix:* n/a; keep the `resolveVendorId` pattern on any new vendor route.
- **E5 — Approval stuck (vendor 'pending' forever).** *Symptom:* the vendor sits in `pending`, can log in but has no jobs; there is **no SLA / auto-escalation / applicant-facing ETA**. They can see `status:'pending'` via profile / the agent's `my_status`, but nothing chases the admin. *Fix:* an admin ops reminder / accountability rule (system-map §C "permissions/ops → admin"), + a clearer applicant status screen.
- **E6 — Throttled / blocked vendor.** *Symptom:* `paused` → no **new** auto-assignments and hidden from eligibility, but can still log in and progress **existing** jobs; `reduced` → de-prioritised. Correct-by-design, but "paused" is not a full lockout. *Fix (if a hard stop is wanted):* also gate `PATCH /api/jobs/{id}/status` on throttle in `body/service-engine` (system-map §C "vendor can't accept → assignment path").
- **E7 — Concurrent job actions.** *Symptom:* the **accept** path is race-safe (`FOR UPDATE`, 409 on loser). The **status-update** path is transactional but not row-locked → a vendor's `complete` racing an admin **reassign** could apply against a stale read (`WHERE id=` only, no `vendor_id` re-guard in the UPDATE). Low-probability, low-blast. *Fix:* add `SELECT … FOR UPDATE` + a `vendor_id` guard in `services/index.php:2301-2411`.
- **E8 — Missing profile columns at launch.** *Symptom:* if the V2 migrations for `languages` / `vendor_service_areas` / `vendor_categories` aren't applied, those fields **silently drop** (schema-defensive `tableHasColumn`) — the vendor thinks they saved languages/areas but they didn't persist. *Fix:* verify migrations ran (system-map §C "schema → migrations/"); the defensive code hides the miss, so check explicitly.
- **E9 — Role change requires re-login.** *Symptom:* freshly-approved vendor sees "customer" powers until they log out/in; communicated only via a blocking `alert()`. *Fix:* re-issue/refresh the token on approval or detect+auto-refresh in `vendor/shared/auth.js:71-80`.
- **E10 — Panel points at prod from staging.** *Symptom:* `config.js` `BASE_URL` hardcoded to `worktogo.in`; a staging deploy without `window.WTG_BASE_URL` will mutate **production** data during testing. *Fix:* env-drive `BASE_URL` (system-map §C "config → config/"). **Treat as must-fix for a safe staging test.**
- **E11 — No earnings visibility (see §1D).** *Symptom:* vendor disputes payout with no self-service statement → support load + trust erosion. *Fix:* a vendor wallet/earnings read view over `vendor_wallets`/`wallet_transactions` (system-map §C "commission/payout → LedgerEngine").

---

## 5. MERCHANT (commerce vendor) SPECIFICS — vs an Amazon-seller flow

**Reality at launch: OFF.** `SERVICE_ONLY_MODE` hides all commerce, and the vendor panel's shopping UI is not live. When commerce turns on, the **V2 model is admin-managed, not seller-self-serve**:

- **Store/product/inventory** are created by the **admin** in the commerce control-room (`heart/api/admin/commerce.php` — store-types, stores incl. company dark-store *and* local vendor store, products, `inventory.manage`), each RBAC-gated (role-01 §1-9). A seller does **not** build their own catalogue there.
- A **separate, older self-serve** path exists — `vendor/dashboard-shopping.html` → `/api/vendor/products` + `/api/vendor/orders` in the **`body/shopping-engine`** — but that engine is the **dormant, READ-only** V1 system (`system-map` B.3). So there are effectively **two competing product systems**, and the launch-intended one is admin-driven.
- **Getting paid / earnings:** same gap as service (§1D) — no seller payout dashboard, no seller-visible commission, no settlement statement.

**Gaps vs Amazon-seller:** no seller-driven catalog/listing creation in the launch model; no seller inventory self-management surfaced; no seller payout/settlement dashboard; no order-fulfilment console for the seller in the live path; the two-product-systems ambiguity needs a decision before commerce launches. **For a service-only launch this is out of scope — but it means "merchant" is not a launch-ready role yet.**

---

## 6. STAGING TEST SCRIPT (vendor) — click-by-click, green + negative

> **Pre-req (must-fix E10):** deploy the vendor panel with `window.WTG_BASE_URL` pointed at **staging**, or you will be editing production. Ensure an SMS provider (or dev-log mode with `APP_ENV=local`) is configured so OTP works.

**A. Signup (passwordless)**
1. Open the vendor panel → login → enter a fresh phone → "Send OTP". **Green:** "OTP sent"; on staging/dev the OTP appears (log/debug), in prod it arrives by SMS. **Negative:** spam Send 6× → **429** "Too many OTP requests". **Negative:** wrong OTP 5× → "Too many failed attempts, request a new OTP".
2. Verify OTP. **Green:** account created, logged in as **customer** (that's expected pre-application).

**B. Apply to be a vendor**
3. Go through `onboarding.html` (or the agent's `start_listing`): business name, category, area, experience, languages → submit. **Green:** 201, vendor row `status='pending'`, admin gets an FCM "New Vendor Registration". **Negative:** submit again → **409 VENDOR_ALREADY_EXISTS**.

**C. Approval + re-login**
4. As admin (other tab) approve the vendor. Back in the vendor panel, reload → you should be told to **log out and back in**. **Green:** after re-login the role is `vendor_service` and the service dashboard loads. *(This is the friction point — confirm the message is understandable.)*

**D. Profile / listing**
5. Edit profile: add a second category, set experience 5, add service areas "Rajpur Road, Mall Road", languages Hindi+English, upload a logo. **Green:** "Profile updated"; re-fetch shows `categories[]`, `service_areas[]`, `languages[]` populated. **Negative:** upload a 10 MB file / a `.txt` renamed `.jpg` → 400 "too large" / "invalid content type".
6. Upload a **work photo**. **Green:** appears in your gallery as **pending**; confirm it is **not** visible on the public `/api/service/vendor/{id}/work-photos` until the admin approves it.
7. Set **availability** slots. **Green:** "Availability slots updated"; `GET /api/vendor/availability/{id}` returns them.

**E. Jobs**
8. As admin, assign a booking to this vendor (or let `auto_assign` run). Vendor: **Jobs** shows it; open detail → **customer phone is hidden**. **Green:** phone masked while status is assigned/confirmed.
9. Accept → Start (`in_progress`) → **customer phone now visible** → Complete. **Green:** each transition succeeds; booking mirrors the status. **Negative:** try to accept a job already accepted by another vendor (two vendors, one auto-assignment) → **409 "already accepted by another vendor"**. **Negative:** open `/api/vendor/jobs/{id}` for a job **not** yours → **403**.
10. **Reject** a job → **Green:** returns to the admin queue (`status=open`, `vendor_id` cleared), booking back to `pending`.

**F. Vendor-agent**
11. Open the vendor agent → "meri listing update karo" (start_listing) → answer steps → confirm save. **Green:** profile updated, still shows as a normal (admin-approvable) profile — the agent did **not** self-approve. Ask "mera status?" (my_status) → friendly summary. Confirm the AI transcript contains **no** customer PII.

**G. Earnings (expectation-setting — proves the gap)**
12. Look for any "earnings / wallet / payout / my money" screen. **Expected result: there is none** (analytics is off, and even when on it shows gross revenue, not payout). This confirms §1D — log it as the top must-fix.

---

## 7. HONEST VERDICT

### Is the vendor role launch-ready?

**For a service vendor at launch: ~78% ready — ship after a short must-fix list.** The *operational spine* is genuinely strong and, in places, better than most pre-launch systems: race-safe accept, disciplined IDOR/ownership, customer-phone-privacy done right, hardened OTP + uploads, a clean structured profile, and a conversational agent that respects admin approval and the PII wall. What holds it back from "ready" is **partner trust and polish**, not correctness of the core flow.

**For a merchant/seller at launch: not a launch-ready role (~30%)** — commerce is admin-managed and OFF; there is no seller self-serve catalog or payout view. Fine, because commerce isn't in the launch scope — but it should be stated plainly.

### Readiness by area
- Register/login (OTP + fallbacks): **~90%**
- Profile / listing / photos / availability: **~90%**
- Jobs (receive/accept/status/privacy): **~88%**
- **Earnings / wallet visibility: ~10%** (admin-only; nothing vendor-facing)
- Vendor-agent: **~85%**
- Device/UX on cheap phones + weak net: **~70%**
- Merchant self-serve: **~30%** (out of launch scope)

### Biggest gaps / risks (ranked)
1. **No vendor-facing earnings/wallet/commission/payout view** (§1D, §4-E11). A partner can't see their money → disputes, distrust, support load. *Highest priority.*
2. **Staging points at production** (`BASE_URL` hardcoded, §4-E10). Testing will mutate live data. *Must fix before any staging run.*
3. **Re-login friction after approval** via blocking `alert()` (§2, §4-E9). First impression is "is it broken?".
4. **Unpinned Chart.js CDN** on the dashboard (§2) — weak-internet + supply-chain risk.
5. **Approval has no SLA / applicant ETA** (§4-E5) — vendors can be stranded in `pending`.
6. **Dead feature flags** and the **two-product-systems** ambiguity (§2, §5) — misleading; decide before commerce.
7. Minor: status-update TOCTOU (§4-E7); silent drop of profile fields if migrations unrun (§4-E8).

### Must-fix **before** launch
- **M1.** Ship a **vendor earnings view** (read-only over `vendor_wallets`/`wallet_transactions`: pending, available, last settlement, commission %, COD collected). Even a basic statement closes the trust gap.
- **M2.** Env-drive `vendor/config.js` `BASE_URL` (or set `window.WTG_BASE_URL`) so staging ≠ prod.
- **M3.** Replace the post-approval `alert()` with a smooth re-auth / token refresh.
- **M4.** Pin (and ideally self-host) Chart.js; confirm the dashboard on a real cheap Android under 3G throttle.
- **M5.** Verify the vendor V2 migrations (`languages`, `vendor_service_areas`, `vendor_categories`) are applied on the target env (the defensive code hides a miss).

### Fine to defer **post-launch**
- Approval SLA/auto-escalation + a friendlier applicant status screen.
- `FOR UPDATE` on the job status path (§4-E7); optional throttle-gating of manual status changes.
- Remove/wire the dead feature flags; resolve the service-vs-shopping product-system split before commerce goes live.
- Full merchant self-serve (catalog + inventory + payout console) — a project in itself, tied to the commerce launch.

**One-line verdict:** *The vendor's day-to-day — sign up by phone, build a listing, receive and run jobs, post work photos, even do it by talking to the agent — is well-built and hardened and can ship; but a partner currently has no way to see their own money, and staging must stop pointing at production. Close the earnings view and the config, smooth the re-login, and the service-vendor role is launch-ready. Merchant/seller is a later, separate launch.*

---
*Grounded in `wtg-v2` @ 2026-09-02. Read-only audit — nothing modified. Companion to `MAP/system-map.md` and `role-01-admin.md`. Next: role-03 (customer), role-04 (rider), role-05 (agent).*
