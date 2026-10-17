# WorkToGo — SERVER-DAY CHECKLIST (from "hosting bought" → "staging live")

> **What this is:** The single, ordered, do-not-think checklist for the day hosting arrives. Every command, every `.env` value, migrations in order (backup first), the smoke tests, the role-by-role test flow, and exactly what **"green"** looks like at each step. Follow it top to bottom.
> **Grounded in** real `wtg-v2` code @ 2026-09-06 (branch `v2`, backed up to GitHub repo `worktogo-platform` as `main`). Companion to `LAUNCH/final-launch-gate.md`, `LAUNCH/prehosting-prep.md`, `LAUNCH/github-new-repo-plan.md`, `LAUNCH/alert-notification-flow.md`.
> **Nothing here has been executed** — it runs on the server, on the day, with the founder driving the account/credentials and Claude Code assisting with commands/verification.

---

## HOW THE DAY IS SPLIT — founder vs Claude Code

| Founder does (account/credentials/clicks) | Claude Code can do (prep/commands/verify) |
|---|---|
| Buy hosting + DB; get SSH/panel access | Give the exact commands to paste |
| Create/obtain all API keys & credentials | Assemble the `.env` from `.env.example` (blanks for you to paste secrets) |
| Sign in to GitHub; authorize the server to pull | Draft the deploy/pull + cron commands |
| Run each command / paste secrets (secrets stay yours) | Read results, confirm "green", diagnose failures |
| Point DNS; approve going public | Run the read-only smoke checks, interpret logs |

> **Rule:** Claude Code prepares and verifies; **the founder runs anything that pushes, deploys, or handles a real secret.** Secrets never go in a file that's committed — only in the server's `.env`.

---

## PHASE 0 — Have these ready before you start (from Step 0 of the launch gate)
- [ ] **Hosting:** a VPS (2–4 GB) or managed PHP host, + **MySQL/MariaDB** (managed, or VPS-local to start). Slow-query log ON. Automated DB backups ON.
- [ ] **DNS:** `worktogo.in` + a `staging.worktogo.in` subdomain, via Cloudflare (free CDN + SSL).
- [ ] **Credentials in hand:** Razorpay (test + live), Cashfree (have), **Anthropic API key**, MSG91 (have) + a **2nd SMS provider**, Google Maps key, Google OAuth client id, FCM keys *(only if enabling push — off at launch)*.
- **GREEN =** you can SSH into the server (or use its panel) and reach an empty MySQL database.

---

## PHASE 1 — Connect GitHub → server & deploy the code
> Deploy `main` (the real system) from `worktogo-platform`. The repo is the source of truth — **never edit code on the server.**

```bash
# on the STAGING server, in the web root
git clone https://github.com/akk611745-wq/worktogo-platform.git .
git checkout main
```
- Login: use the browser popup or a Personal Access Token (see `github-new-repo-plan.md §B2 Step 3`).
- Point the web server's document root at this folder; `index.php` is the entry (`.htaccess` is included).
- **GREEN =** visiting `https://staging.worktogo.in/` returns the app shell (even if it errors on DB — that's the next phase).

---

## PHASE 2 — Create the `.env` (copy the template, paste real values)

```bash
cp .env.example .env
# then edit .env
```

**Set these for STAGING (the REQUIRED minimum in bold):**

| Key | Staging value |
|---|---|
| **`APP_URL`** | leave **blank** (same-origin) — staging talks to staging |
| **`APP_ENV`** | `staging` (OTP fails loud, no dev echo) |
| `APP_DEBUG` | `false` |
| **`DB_HOST/PORT/NAME/USER/PASS`** | your DB connection |
| **`JWT_SECRET`**, **`AGENT_CONFIRM_SECRET`** | long random strings |
| **`MIGRATE_SECRET`** | long random string *(required for the next phase)* |
| `CRON_SECRET` | long random string *(for HTTP-triggered crons)* |
| `ALERT_INTERNAL_SECRET`, `HEART_INTERNAL_KEY` | random strings |
| **`CASHFREE_APP_ID/SECRET_KEY`**, `CASHFREE_ENV=SANDBOX` | Cashfree sandbox creds |
| `RAZORPAY_KEY_ID/KEY_SECRET`, `RAZORPAY_ENV=test` | Razorpay test creds (primary) |
| **`ANTHROPIC_API_KEY`** (+ model) | your AI key (needed for the agent test) |
| **`MSG91_AUTH_KEY/TEMPLATE_ID/SENDER_ID`** | OTP provider (+ the 2nd provider's keys) |
| **`GOOGLE_MAPS_API_KEY`**, `GOOGLE_CLIENT_ID` | Maps + Google login |
| `TRUST_PROXY` | `true` if behind Cloudflare/Nginx |
| `ALLOWED_ORIGINS` | `https://staging.worktogo.in` |
| `FCM_*` | leave blank at launch (push off) |

- **GREEN =** `.env` exists, is **not** world-readable (`chmod 600 .env`), and is **not** in git (`.gitignore` covers it).

---

## PHASE 3 — Database: **BACK UP FIRST, then migrate**

```bash
# 1) ALWAYS back up before touching the schema
mysqldump -u <user> -p <dbname> > backup_pre_migrate_$(date +%F_%H%M).sql

# 2) run all migrations in order (idempotent; tracked in migrations_log)
php migrate.php "<MIGRATE_SECRET>"
```
`migrate.php` applies every file in `migrations/` in filename order and records each in `migrations_log`, skipping any already applied. Re-running is safe.

- **Verify the tail landed** (spot-check a few): `rbac_*`, `agent_used_tokens`, vendor V2 fields, delivery, media, packages, expert-visit, auth_channels. *(Full ordered list of all 52 in Appendix A.)*
- **⚠️ Legacy tables check:** some hot tables live in the **inherited V1 schema, not the migrations** — confirm they exist and are indexed on the target DB: `bookings`, `jobs`, `otps`, `auto_assignments`, **`alerts`** (the alert feed). Run `EXPLAIN` on the hot queries and add any missing index as a **new dated migration**:
  `bookings.user_id`, `bookings.client_request_id`, `otps.phone`, `jobs.booking_id`, `auto_assignments(entity_type,entity_id)`, `wallet_transactions(entity_type,entity_id)`.
- **GREEN =** `php migrate.php` returns `"No pending migrations to apply."` on a second run, and the legacy tables exist with clean `EXPLAIN`.

---

## PHASE 4 — Schedule the crons (assignment & reminders depend on these)
Add to the server's scheduler (cron/systemd). If triggering over HTTP, append `?secret=<CRON_SECRET>`.

```bash
* * * * *  php /var/www/worktogo/cron/auto_assign.php          # vendor assignment + its alerts
* * * * *  php /var/www/worktogo/cron/commerce_lifecycle.php   # PB6: offer + quote timeouts → reroute (idempotent, flock-locked)
*/2 * * * * php /var/www/worktogo/cron/delivery_lifecycle.php  # dormant at launch, harmless
*/5 * * * * php /var/www/worktogo/cron/agent_reminders.php     # agent reminders → notifications
*/15 * * * * php /var/www/worktogo/cron/agent_demand_detect.php# admin demand alerts
```
- **Local (Windows) dev:** schedule `cron/commerce_lifecycle.bat` every 1 min via Task Scheduler —
  `schtasks /Create /TN "WTG Commerce Lifecycle" /SC MINUTE /MO 1 /TR "\"<repo>\cron\commerce_lifecycle.bat\"" /F`.
  Toggle the whole sweep with app_setting `commerce_lifecycle_enabled` (1/0).
- Add **missed-run alerting** (a dead cron = no vendor assignment, silently).
- **GREEN =** after placing a test booking, `auto_assign` logs "Assigned job #… to Vendor #…" within a minute and an `alerts` row appears for that vendor. *(See `alert-notification-flow.md` — this is the primary assignment path.)*

---

## PHASE 5 — System smoke tests (is it alive?)
- [ ] Home page loads; no 500s in the error log.
- [ ] **First paint shows NO `maps.googleapis` / `firebasejs` requests** (Batch 2 — Maps loads only at the address step; Firebase off).
- [ ] A public catalog/browse call returns data (DB reachable).
- [ ] OTP send returns success **without** a debug echo (proves `APP_ENV=staging`).
- **GREEN =** the app loads fast and clean on a phone, no console errors, DB-backed data shows.

---

## PHASE 6 — Role-by-role STAGING TEST FLOW (what to click, what confirms green)

### 6-A · Admin
1. Log in → confirm the session lasts (12h token). 2. Add the city (Haldwani). 3. Set expert-visit pricing. 4. Approve a test vendor. 5. Moderate a photo. 6. Open the Payment Room; run a settlement.
- **Negative:** try to demote the last/only admin → **409** (lockout guard). PUT a new setting → confirm it's **private** (`is_public=0`), absent from public settings.
- **GREEN =** all actions work; the two negative tests behave.

### 6-B · Vendor
1. OTP signup → apply → (admin approves) → **re-login shows the clean overlay**, not an alert. 2. See the jobs list (customer phone hidden until start). 3. Accept → start → complete a job. 4. Upload a work photo. 5. **Open the Earnings tab** → own numbers show.
- **Negative:** a second vendor sees **only their own** jobs/earnings (no IDOR).
- **GREEN =** the full vendor loop works; Earnings reachable from the normal login; no cross-vendor leakage.

### 6-C · Customer
1. Browse without login. 2. Book a **free** service. 3. Book a **paid Expert Visit** (₹299) → next phase. 4. Track the booking.
- **Negative:** open another user's booking id → **403**.
- **GREEN =** browse→book→track works; the IDOR test blocks.

### 6-D · Notifications / alerts *(the founder's concern — see `alert-notification-flow.md`)*
1. Place a booking → **admin** gets a "New Booking Created" bell entry. 2. Let `auto_assign` run → a **vendor** "New Request" **alert** appears (poll-based). 3. Vendor accepts → the **customer** gets an accept **alert**.
- **Note the known gap:** an auto-assigned job gives the vendor an *alert row* but **no push** (push is off at launch anyway; the fix is a post-launch `FcmNotifier` call in `auto_assign.php`).
- **GREEN =** each in-app entry appears for the right role. *(Real push is a later phase once FCM keys are set.)*

### 6-E · Payment — a real ₹1 round-trip
1. Sandbox pay, then **one live ₹1** on Razorpay/Cashfree. 2. Return page shows "verification in progress" (**never self-authorizes**). 3. The **webhook flips the booking to `paid`**; the status endpoint confirms.
- **Negative:** cancel on the gateway → booking `failed`, retry allowed. Blank creds → friendly "gateway not configured".
- **GREEN =** money moves, the webhook (sole authority) records it, the negative paths are graceful.

### 6-F · Agent (real AI key)
1. Enable the kill-switch **per-capability** (service only). 2. Do a real **booking via chat**. 3. **PII-block test:** send a phone number → **422**. 4. **Cross-user test:** try to act on another user's data → **404/forbidden**. 5. Confirm the transcript carries **no PII** and **commerce tools are refused** (off by default).
- **GREEN =** the agent books for real, the privacy wall and no-elevation hold, commerce stays off.

### 6-G · Failover
Wire the **2nd SMS provider** (+ `routing.sms.secondary`) and **Razorpay primary / Cashfree secondary** → simulate a primary outage → OTP still sends, payment still routes.
- **GREEN =** a provider can go down without taking OTP or payments down.

---

## PHASE 7 — GO / NO-GO (all green before public)
- [ ] Migrations applied + verified; legacy tables exist; `EXPLAIN` clean.
- [ ] Real ₹1 payment: webhook flips `paid`; credential-guard friendly error.
- [ ] Agent on a real key: booking + PII-422 + cross-user-404 pass; zero-retention confirmed.
- [ ] 2nd SMS + Razorpay secondary: failover verified.
- [ ] Every role test green, incl. the negative/IDOR tests.
- [ ] Notifications appear in-app for all three roles (Phase 6-D).
- [ ] Crons live with missed-run alerting; DB auto-backups on; slow-query log on.
- **If any box is red, do not flip public.** None require new building.

**Cutover to prod:** repeat Phases 1–4 on prod (`APP_URL=https://worktogo.in`, `APP_ENV=production`, `CASHFREE_ENV=PRODUCTION` / Razorpay live), back up, migrate, seed only real Haldwani data, turn the agent kill-switch **on per-capability**, soft-launch a small cohort, watch the logs.

---

## PHASE 8 — Branch model on the new repo (founder runs; Claude explains)
Set up the world-class model once (from `naming-and-structure.md §1.2`):

```bash
# from your local wtg-v2 folder (branch v2 = the real system, already pushed as main)
git push platform v2:staging      # create the staging branch on the new repo
git push platform v2:dev          # optional: an integration branch
```
On GitHub → the new repo → **Settings → Branches**: protect **`main`** and **`staging`** (require a Pull Request to merge). Invite teammates under **Settings → Collaborators**.

**How local → GitHub → server maps:**
```
your PC (branch v2)  ─push→  GitHub worktogo-platform: main ⇄ staging ⇄ feature/*
                                    │ (server pulls)
                        staging.worktogo.in  ──test──▶  worktogo.in (prod)
```
- **Deploy:** server `git pull` a tag (`git tag v2.0-launch && git push platform v2.0-launch`).
- **Rollback:** `git revert <commit>` + redeploy (each fix is a clean commit), or check out the previous tag; data → restore the pre-migration dump; feature → flip a flag/kill-switch (no deploy).

---

## FINAL GAP-SWEEP — honest status (zero surprises)

### ✅ DONE — code-side (nothing left to build for service + agent)
- All fix batches 1–3.5 on `v2` (19 commits) + this session's additive hygiene (`4a8a4c3`: README, complete `.env.example`, hardened `.gitignore`).
- Money-safety, IDOR/identity, agent safety wall, failure-isolation, admin control room, vendor earnings — built and verified in code.
- Backup exists: real system pushed to `worktogo-platform`.
- In-app notification/alert flow complete for all three roles.

### 🖥️ SERVER-ONLY — can only happen once hosting exists (this checklist)
- Provision + connect GitHub→server; set `.env`; **backup + `migrate.php`**; `EXPLAIN` + add any missing index; schedule crons; real ₹1 payment; real AI-key round-trip; wire 2nd SMS + payment secondary; role tests; cutover.

### 🕓 POST-LAUNCH — deliberately deferred (not blockers)
- **Push notifications:** set FCM keys **and** add the `FcmNotifier` call to `auto_assign.php` so **auto-assigned jobs push to the vendor** (the one code gap — see `alert-notification-flow.md §3`).
- RBAC delegation (limited staff logins); commerce/merchant self-serve + reconcile the two product systems; delivery go-live (rider onboarding + cash reconciliation); agent voice/languages/NER; customer address book + receipts + refresh-token; consolidate the duplicate `core/helpers/Payment.php`.

### 🔎 Small SAFE pre-server prep still open (flagged, not done — founder-approved when wanted)
- **Review `diag.php` + `deploy-hook.php`** (repo root) — confirm they're secret-gated or block them in `.htaccess` before prod (they'll be web-reachable). *Not reviewed in depth; flag only.*
- **(Optional, pre-launch batch)** add the vendor-push call in `auto_assign.php` (the notification gap) — small, but it's cron logic on the assignment path, so left for an approved batch, not this additive pass.

---

## Appendix A — all 52 migrations, in run order
`migrate.php` sorts by filename, so this is the exact order:
```
2026_04_25_000001_fix_admin_auth_type          2026_06_22_001_create_notifications
2026_04_26_000003_fix_admin_auth_type          2026_06_22_002_vendor_trust_fields
2026_04_27_000001_fix_admin_password_123456     2026_07_04_001_cities_and_city_id_foundation
2026_04_27_002_add_services_columns             2026_07_04_002_unify_vendor_category_canonical
2026_04_27_003_add_vendors_columns              2026_07_04_003_create_media_table
2026_04_27_004_add_categories_columns           2026_07_04_004_wallet_transactions_reference_type
2026_04_27_005_fix_order_delivery_booking       2026_07_04_005_create_packages_table
2026_04_27_006_fix_users_phone_nullable         2026_07_04_006_bookings_package_selection
2026_04_28_001_secure_admin                     2026_07_04_007_expert_visit_pricing
2026_05_01_001_add_bookings_updated_at          2026_07_04_008_bookings_expert_visit
2026_05_01_002_add_slot_timestamps              2026_07_04_009_vendor_profile_fields
2026_05_01_003_standardize_vendor_type          2026_08_13_001_rbac_foundation
2026_05_09_001_seed_public_cart_product         2026_08_15_001_vendor_accountability
2026_05_11_001_auth_vendor_contract_alignment   2026_08_22_001_commerce_store_foundation
2026_05_21_001_add_structured_booking_customer_fields  2026_08_22_002_commerce_order_alignment_triggers
2026_05_23_001_service_vendor_eligibility       2026_08_22_003_delivery_engine
2026_05_23_002_service_propagation_hardening    2026_08_22_004_delivery_lifecycle
2026_05_28_001_service_launch_stabilization     2026_08_26_001_payment_gateway_abstraction
2026_06_08_001_vendor_category                  2026_08_27_001_agent_foundation
2026_06_10_001_inspection_price_setting          2026_08_27_002_agent_action_log
2026_06_10_002_add_user_address                 2026_08_27_003_agent_memory
2026_06_14_001_seed_initial_services            2026_08_27_004_agent_reminders
2026_06_15_001_vendor_categories_junction       2026_08_27_005_agent_governance
2026_06_21_001_create_fcm_tokens                2026_08_27_006_agent_people
2026_06_22_...                                  2026_08_27_007_agent_proactive
                                                2026_08_27_008_agent_used_tokens
                                                2026_08_27_009_agent_budget_default
                                                2026_08_27_010_auth_channels
```
*(Legacy tables `bookings`, `jobs`, `otps`, `auto_assignments`, `alerts` come from the inherited V1 schema — verify + index them; they are not in this list.)*

---
*End of server-day checklist. On the day: Phase 0 → 8 in order; if a box isn't green, stop and fix before continuing. Companion to `final-launch-gate.md`, `prehosting-prep.md`, `github-new-repo-plan.md`, `alert-notification-flow.md`.*
