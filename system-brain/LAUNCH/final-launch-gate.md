# WorkToGo — Final Launch Gate (Founding-CTO review)

> **Purpose:** The last honest gate before going live with **Service + AI-Agent in one city (Haldwani)**. Re-scans the whole system, confirms the fix batches landed, gives a per-engine readiness + money picture, and lays out a complete, execute-ready launch plan (staging → pipeline → test → cutover → rollback → testing-while-live). Commerce/delivery plug in later.
> **Grounded in:** real `wtg-v2` code (branch `v2`) @ 2026-09-04, the recorded fix commits, and the full `WTG-SYSTEM-BRAIN` (MAP, V2, COMMERCE, AGENT, AUDITS-ROLE role-01–05, `agent-11`, `master-readiness.md`).
> **READ-ONLY** — no code changed. Every claim cites code or a commit.
> **Bottom line up front:** Code-side is **finished for the service+agent scope.** The one real loose end this scan caught — the vendor panel split-brain — was **fixed in Batch 3.5** (`32abf62` unify, `1ee9fb2` `.env.example`, `7a1acde` remove dead `map.js`): every vendor entry point now lands on one coherent dashboard and the Earnings tab is reachable. Everything else outstanding is **staging validation** or **hosting/budget**, not building. Overall service+agent readiness: **~90%**; the path to public is ~1.5–2 focused weeks once budget/hosting arrive.

---

# PART 1 — FINAL RE-SCAN (did we miss anything?)

## 1. Fix batches 1–3 — landed and clean (commits verified on `v2`)

All 16 commits are present in `git log` on `v2`, newest first:

| Batch | Commits | What |
|---|---|---|
| **1 — staging-safety + hygiene** | `abdf386` `ca7aee3` `745e621` | BASE_URL env-drive → same-origin (never prod); removed per-payment `CASHFREE_ORDER_DEBUG`; fixed stale throttle comment + flag annotations |
| **2 — first-open speed** | `f80ee49` `f8ac74c` `ddbcfb7` `8041da0` `1026e48` | Maps on-demand (`WTG_loadMaps()`); Firebase only when FCM configured; fonts via parallel `<link>`; Chart.js pinned+guarded; real service-worker cache |
| **3 — vendor earnings + guards + polish** | `e7d5acd` `40dc328` `35439bc` `c5acfa7` `baac240` `a197455` `a7c332e` `f963877` | `GET /api/vendor/earnings` + Earnings tab; last-admin guard; `is_public=0`; agent commerce OFF by default; pinch-zoom; admin JWT 12h; re-login overlay |

**Did anything break?** No regressions found on inspection:
- BASE_URL: fail-safe to same-origin; prod (app+API co-hosted) resolves identically; admin already relative. ✔
- Maps/Firebase/fonts: home.js's two Maps consumers already guard `!window.google?.maps?.places` and now `await WTG_loadMaps()`; push.js already no-ops without FCM keys; font `display=swap` preserved. ✔
- Earnings endpoint: ownership-scoped via `resolveVendorId` (JWT), reuses `PaymentReport::vendorRevenue`, route prefix registered in `heart/index.php`; UI verified in-browser (renders, ₹ en-IN, graceful no-backend fallback, no console errors). ✔
- Guards: each is additive/fail-closed and scoped (admin-only JWT change; commerce default merges under any explicit `agent_capabilities`). ✔

## 2. Per-engine / per-layer readiness (honest, grounded) + loose ends

| Layer | Ready | Core loop works E2E? | Loose ends found |
|---|:--:|---|---|
| **Service engine** (launch product) | **86%** | ✅ browse → book (free & paid ₹299 expert visit) → assign → vendor accept/start/complete → track. Money-safe (server amount, webhook authority, idempotent dedup). | Legacy hot-table indexes (`bookings.user_id`, `bookings.client_request_id`, `otps.phone`) unverified — Batch 4 `EXPLAIN`. |
| **AI Agent** | **85%** (with real key) | ✅ per-role tools enforced; deterministic Hinglish keywords work with no AI; writes confirm-gated + single-use; PII wall fail-closed; degrades to Mock. | Needs a real key on staging to *understand* messy input; bare-city-word PII residual (documented). See §3. |
| **Motherboard** (core/lib: identity, city, media, ledger, RBAC, payment orchestrator) | **88%** | ✅ shared, well-indexed V2 tables, clean engine plug-points (`config/engines.php`: service local; delivery/video empty). | RBAC delegation dormant (post-launch); duplicate payment helper (`core/helpers/Payment.php`) still flagged for consolidation. |
| **Payment** | **85% safe / 30% failover** | ✅ Cashfree live path is money-safe; credential guard; webhook = sole authority. | **No live gateway failover** — `secondary=null`, Razorpay is a stub. Needs a real ₹1 round-trip on staging. |
| **Auth / identity** | **85% / 30% failover** | ✅ OTP (rate-limited, hashed, fails-loud) + Google + email; smart-auth failover engine correct. | **Only one real SMS provider (MSG91)** — the failover has nowhere to divert until a 2nd channel is wired. |
| **Vendor panel** | **74%** | ✅ apply → approve → jobs → photos → **earnings** now exist. | **⚠ NEW: split-brain UI (see below) — the Earnings tab is on the wrong page for the normal login flow.** |
| **Commerce / delivery** | built, **hidden** | n/a at launch (`SERVICE_ONLY_MODE`, engines empty). | Two product systems (shopping-engine vs commerce.php) to reconcile before commerce launch; rider onboarding + cash-deposit reconciliation (post-launch). |

### ✅ RESOLVED (Batch 3.5, 2026-09-04) — vendor panel split-brain
> **Fixed** in `32abf62` (unify) + `1ee9fb2` (`.env.example`) + `7a1acde` (remove dead `map.js`). Both `dashboard-*.html` now read the unified `wtg_vendor_token` and bounce to the real `index.php` (the dead `/vendor/login.html` 404 is gone); every vendor entry point (email login, OTP login, revisit guard, onboarding) now converges on the one coherent dashboard, so a returning vendor sees the Earnings tab. Verified end-to-end in code + in-browser (no console errors). The original analysis is kept below as the record.


The vendor panel has **two parallel UIs with inconsistent auth**, and the Batch-3 Earnings tab landed on the branch the normal login does **not** use:
- **Normal login** (`vendor/index.php`) routes a **service** vendor to **`bookings.php`** (`:307`,`:499`). The `.php` pages (`bookings/orders/products/profile/dashboard.php`) use `shared/auth.js` + the **`wtg_vendor_token`** key.
- **`dashboard-service.html`** (where the Earnings tab was added) reads a **different, never-set key `vendor_token`** (`:836`) and, when it's missing, redirects to **`/vendor/login.html` — which does not exist** (404). It is reachable only from `onboarding.html:941` (post-onboarding redirect), not from a returning login.
- **Consequence:** a returning vendor lands on `bookings.php` and **won't see the Earnings tab at all**; a freshly-onboarded vendor may briefly land on `dashboard-service.html`. So the earnings *backend* is done and correct, but its *reach* is partial.
- **Recommended follow-up (small code fix, next batch):** (a) unify the token key (`vendor_token` → `wtg_vendor_token`) and fix the dead `/vendor/login.html` redirect, and (b) surface earnings in the **live `.php` flow** (an `earnings.php` or an Earnings link in the `.php` nav) — or consolidate the vendor panel onto one UI. **Not a launch blocker for service, but the earnings-trust win isn't fully delivered until this is done.**

### Other loose ends (smaller)
- **No `.env.example` in the repo** — a new deploy has no template of required env vars. Add one (the full list is in §6's env checklist). *Deploy-readiness gap, not a code bug.*
- **`app/js/map.js`** `loadSDK()` fetches the Maps key from the admin-only `/admin/settings` — broken for customers, but `map.js` isn't loaded in the customer shell, so it's **dead code** (harmless; delete or fix later).
- **`wallet_transactions(entity_type,entity_id)`** index for the earnings query — folded into Batch 4's `EXPLAIN` pass.

## 3. AGENT — re-confirmed ready to run (with a real key on staging)

Re-verified against `core/agent/**` and `agent-11`:
- **Per-role power is correct and enforced twice** — the AI only ever sees `catalogForRole`; `isExposedTo` is re-checked at dispatch *and* at confirmed execution; the backend re-authorizes the caller's own JWT (no elevation). A customer can't reach vendor tools and vice-versa.
- **Privacy wall holds** — single fail-closed choke-point (`SanitizedPrompt` private-ctor + backtrace check), scrubs phone/email/UPI/address + own & contact names, tripwire re-scans outbound bytes and throws `PiiLeakException` (safe 422). Residual: bare city words (documented, low risk single-city).
- **Control-room governs it** — kill-switch (default OFF), per-capability toggles (**commerce now OFF by default** after `baac240`), $20 auto-pause budget, rate limits, read-only monitor.
- **Writes are safe** — deterministic (not AI-worded) confirm summary, HMAC + uid-bound + **10-min single-use** token (`agent_used_tokens`), replay-proof.
- **Remaining agent gap:** it only *understands* free-form/low-literacy/multi-language input with a **real AI key**; deterministic Hinglish keywords cover the common asks without one. **Staging must:** point at the live Anthropic adapter, confirm zero-retention terms, run a live booking-via-chat + a PII-block 422 + a cross-user 404 negative test. Keep the kill-switch OFF until those pass.

## 4. Outstanding items — deduplicated, by lane

**CODE (finished for the service+agent scope):**
- ✅ Batches 1–3 (all recorded above).
- ✅ **Vendor UI split-brain** — unified token + login/onboarding converge on the coherent dashboard; Earnings reachable (`32abf62`, Batch 3.5).
- ✅ **`.env.example`** committed (`1ee9fb2`); ✅ dead `map.js` removed (`7a1acde`).
- (optional, post-launch) consolidate the duplicate payment helper; retire the now-unused legacy `.php` vendor pages.
→ **The code side is now genuinely finished for the service+agent launch scope.** Remaining is staging validation + hosting/budget.

**STAGING-ONLY (needs a live server):** run all migrations (`php migrate.php`) + verify; `EXPLAIN` hot indexes (bookings/otps/jobs/auto_assignments/wallet_transactions); real ₹1 Cashfree round-trip; real AI-key round-trip + PII/cross-user tests; wire a 2nd OTP provider (and a payment secondary).

**HOSTING / BUDGET:** app tier off shared hosting; managed MySQL (slow-query log on); CDN; a real cron runner; AI key; 2nd SMS provider; payment gateway account.

**POST-LAUNCH:** RBAC delegation; commerce/merchant self-serve (+ reconcile the two product systems); delivery go-live (rider onboarding + cash reconciliation); notifications + realtime; agent voice/more languages/NER; customer address book + receipts + refresh-token.

---

# PART 2 — MONEY / COST PICTURE (India, ~2026 estimates — verify current quotes)

> These are honest planning ranges, not quotes. Payment gateway fees are per-transaction, not fixed.

| Item | Minimum to TEST (staging) | Realistic to LAUNCH (1 city) | Monthly run-rate |
|---|---|---|---|
| **App hosting** | small VPS ₹700–1,000/mo (or reuse current Hostinger) | VPS 2–4 GB (Hostinger/DigitalOcean/Lightsail) ₹1,500–3,000/mo | ₹1,500–3,000 |
| **Database** | VPS-local MySQL (₹0 extra) | managed MySQL (DO/RDS) ₹1,200–2,500/mo, or VPS-local to start | ₹0–2,500 |
| **CDN** | Cloudflare Free | Cloudflare Free (Pro ₹1,700 later) | ₹0 |
| **AI (Anthropic key)** | ~$5 (₹450) test spend | capped at **$20/mo (~₹1,700)** by code (auto-pause) | ₹500–1,700 (capped) |
| **SMS OTP (MSG91 primary)** | test pack ₹500 | ~₹0.15–0.25/OTP → ₹1,000–2,000/mo at pilot volume | ₹1,000–2,000 |
| **2nd SMS provider (failover)** | ₹0 (sign up) | small top-up ₹500 | ₹300–800 |
| **Payment gateway** | ₹0 fixed (test mode) | **Razorpay/Cashfree: ~2% + GST per txn**, no monthly fee | ~2% of GMV |
| **Domain** | owned (worktogo.in) ~₹900/yr | ~₹900/yr | ~₹75/mo |
| **Google Maps** | free tier | free $200 credit usually covers pilot (Maps is now on-demand) | ₹0–500 |
| **Play Store** (optional; PWA launches without it) | — | one-time $25 (~₹2,100) | — |
| **FCM / push** | free | free | ₹0 |

**Totals:**
- **Minimum to test on staging:** **~₹2,000–3,500 one-off** (a test month + AI/SMS test credit).
- **Realistic to launch, month 1:** **~₹6,000–10,000/mo** run-rate **+ ~2% of GMV** in gateway fees (+ one-time $25 Play Store if publishing an app).
- **Cheapest viable start:** reuse current Hostinger for staging, VPS-local MySQL, Cloudflare Free, capped AI, MSG91 only at first → **~₹3,000–4,000/mo**, adding the 2nd SMS + managed DB as volume grows.

**Recommendation:** Razorpay as **primary** gateway (broad UPI/method coverage, clean onboarding), Cashfree as the **secondary** (already integrated) — this also finally lights up the built-in payment failover. Keep the AI budget cap at $20 to start.

---

# PART 3 — COMPLETE LAUNCH PLAN (execute-ready)

## Step 0 — Budget arrives: buy + create accounts (founder, ~1 day)
1. **Hosting**: a VPS (2–4 GB) or managed PHP host + **managed MySQL** (or VPS-local to start). Enable the **slow-query log**.
2. **Domain/DNS**: point `worktogo.in` (and a `staging.worktogo.in` subdomain) via Cloudflare (free CDN + SSL).
3. **Credentials to obtain**: Razorpay (prod + test keys), Cashfree (already), an **Anthropic API key**, MSG91 (already) + a **2nd SMS provider** (e.g., 2Factor/Fast2SMS), Google Maps API key, Google OAuth client id, FCM keys (only if enabling notifications — off at launch).

## Step 1 — Stand up STAGING (we + founder, ~1 day)
1. **Deploy code**: clone `v2` to `staging.worktogo.in`. This is the deploy artifact — never edit on the server.
2. **Create `.env`** from the checklist below. Critically: **`APP_URL=https://staging.worktogo.in`**, `APP_ENV=staging` (so OTP fails loud, no dev echo), `CASHFREE_ENV=SANDBOX` (or `TEST`).
3. **Back up first, then migrate**: take a DB dump, then run **`php migrate.php`** (applies `migrations/` in filename order, tracked in `migrations_log`). Verify the tail: RBAC, agent (`agent_used_tokens`), vendor V2 fields, delivery, indexes.
4. **Flags**: `SERVICE_ONLY_MODE` on (default); agent kill-switch **OFF**; `agent_capabilities` leave commerce off (now the code default).
5. **Cron runner**: schedule `cron/auto_assign.php` + `cron/delivery_lifecycle.php` (dormant ok) + `cron/agent_reminders.php` + `cron/agent_demand_detect.php` with missed-run alerting.

**Env checklist (from the code's `getenv` usage):** `DB_HOST/PORT/NAME/USER/PASS`, `APP_URL`, `APP_ENV`, `APP_TIMEZONE`, `JWT_SECRET`, `AGENT_CONFIRM_SECRET`, `CASHFREE_APP_ID/SECRET_KEY/ENV`, `RAZORPAY_KEY/SECRET` (when wired), `ANTHROPIC_API_KEY` (+ `AGENT_ANTHROPIC_MODEL`,`AGENT_ANTHROPIC_MAXTOK`), `MSG91_AUTH_KEY/TEMPLATE_ID/SENDER_ID` (+ the 2nd provider's keys), `GOOGLE_MAPS_API_KEY`, `GOOGLE_CLIENT_ID(S)`, `HEART_INTERNAL_KEY`, `ADMIN_KEY`, the `OTP_*_IP_MAX/WINDOW` + `BOOKING_RATE_*` tuning vars, and `FCM_*` only if enabling notifications. *(Add a committed `.env.example` with these keys and blank values.)*

## Step 2 — The deploy PIPELINE + ROLLBACK (like a real company)
- **Flow:** `git v2` → **staging** (auto-pull on push, or a deploy script) → test → **prod** (a tagged release, e.g. `git tag launch-1`, pulled on the prod host). Never edit code on servers; the repo is the source of truth.
- **Migrations:** always **back up the DB before `migrate.php`**; migrations are additive/`IF NOT EXISTS`-guarded and tracked, so re-running is safe.
- **Rollback:** code — `git revert <commit>` (or check out the previous tag) and re-pull; because every fix is a separate clean commit, any one can be reverted without unpicking others. Data — restore the pre-migration dump. Feature-level — the kill-switch (agent), `SERVICE_ONLY_MODE`, and `agent_capabilities` let you disable a capability instantly without a deploy.

## Step 3 — Full staging TEST PLAN (role-by-role — must be green)
- **Admin:** log in (confirm 12h token via `a7c332e`); add a city; set expert-visit pricing; approve a vendor; moderate a photo; open the Payment Room; run a settlement; **negative:** try to demote the last/self admin → 409 (`35439bc`); PUT a new setting → confirm `is_public=0` and absent from public settings (`c5acfa7`).
- **Vendor:** OTP signup → apply → (admin approves) → **re-login sees the clean overlay** (`f963877`), not an alert → jobs list (customer phone hidden until start) → accept/start/complete → upload a work photo → **open Earnings** and confirm own numbers. **Negative:** another vendor sees only theirs (no IDOR); confirm the **split-brain fix** (Earnings reachable from the live login flow) if the follow-up code landed.
- **Customer:** browse without login; book free + **paid Expert Visit**; confirm first paint shows **no `maps.googleapis`/`firebasejs`** requests (Batch 2) — Maps loads only at the address step; track the booking; **negative:** open another user's booking id → 403.
- **Payment — real ₹1 round-trip:** sandbox + one live ₹1 on Razorpay/Cashfree → return page shows "verification in progress" → **webhook flips `paid`** → status endpoint confirms; **negative:** cancel on the gateway → booking `failed`, retry allowed; blank creds → "gateway not configured" friendly error.
- **Agent (real key):** enable the kill-switch per-capability; a real **booking via chat**; **PII-block test** (send a phone number → 422); **cross-user test** (can't act on another user's data → 404/forbidden); confirm the transcript carries no PII; confirm **commerce tools are refused** (default off).
- **Performance:** `EXPLAIN` the hot queries (`bookings.user_id`, `bookings.client_request_id`, `otps.phone`, `jobs.booking_id`, `auto_assignments(entity_type,entity_id)`, `wallet_transactions(entity_type,entity_id)`) → add any missing index as a dated migration.
- **Failover:** wire the 2nd SMS provider + Razorpay secondary → simulate a primary outage → confirm auto-diversion (OTP still sends; payment still routes).

## Step 4 — Cutover to PUBLIC (one city: service + agent)
1. Repeat Step-1 on **prod** (`APP_URL=https://worktogo.in`, `APP_ENV=production`, `CASHFREE_ENV=PRODUCTION`/Razorpay live), back up, `migrate.php`.
2. **Zero fake data** — real accounts only; seed only the real city (Haldwani), real categories, real expert-visit pricing.
3. Turn the **agent kill-switch ON per-capability** (service first; commerce stays off).
4. Soft-launch: a small real cohort first; watch the slow-query log + the admin dashboard + the agent monitor.

## Step 5 — Testing WHILE live (how real companies do it)
- **Staging stays permanently up** as a mirror of prod (same code, separate DB + separate `APP_URL`). All new work is tested there first — **prod is never the test bed**.
- Every change: `v2` → staging → full role test → tag → prod. Keep the pre-deploy DB backup.
- Use the **kill-switch / flags** to dark-launch a capability to prod and enable it for yourself before the public.
- Watch: slow-query log, error logs, payment webhook success rate, agent PII-block count + budget, OTP delivery rate.

## Step 6 — What to launch NEXT (after revenue starts)
1. **Commerce plug-in**: reconcile the two product systems, unhide via `SERVICE_ONLY_MODE`, enable the agent `commerce` capability, run the commerce test plan.
2. **Delivery**: rider onboarding + in-app cash-deposit reconciliation, then flip delivery on.
3. **RBAC delegation** when you hire ops staff; **notifications/realtime**; **agent voice/languages**.

---

## GO / NO-GO CHECKLIST (all must be GREEN before public)

**Code**
- [ ] Batches 1–3 on prod (verified: 16 commits).
- [x] ✅ **Vendor UI split-brain stitched** (`32abf62`) — token unified to `wtg_vendor_token`, dead `login.html` redirect fixed, all login/OTP/onboarding paths converge on the coherent `dashboard-*.html` panel; Earnings now reachable for returning vendors. *(Batch 3.5)*
- [x] ✅ **`.env.example` committed** (`1ee9fb2`); dead `map.js` removed (`7a1acde`).

**Staging validated**
- [ ] All migrations run + verified (RBAC, `agent_used_tokens`, vendor fields, delivery, indexes).
- [ ] `EXPLAIN` clean on all hot queries (indexes added where missing).
- [ ] Real ₹1 payment round-trip: webhook flips `paid`; return never self-authorizes; credential-guard error shown.
- [ ] Agent on a real key: live booking + PII-block 422 + cross-user 404 all pass; zero-retention terms confirmed.
- [ ] 2nd SMS provider wired → OTP failover verified; Razorpay primary + Cashfree secondary → payment failover verified.
- [ ] Every role test (admin/vendor/customer) green, incl. the negative/IDOR tests.

**Infra**
- [ ] Off shared hosting; managed MySQL with slow-query log on; automated DB backups.
- [ ] Cron runner live with missed-run alerting.
- [ ] `APP_URL` set correctly on both staging and prod (same-origin never hits the wrong env).

**Ops**
- [ ] Rollback rehearsed (git revert + DB restore).
- [ ] Kill-switch OFF until agent tests pass; then per-capability ON (commerce stays off).
- [ ] Zero fake data; real city/categories/pricing seeded.
- [ ] Monitoring dashboards watched (slow-query, errors, webhook rate, agent budget/PII).

**If any box is red, do not flip public.** None of the red boxes require new building — they are the vendor-UI stitch, staging validation, and the hosting/provider setup.

---

## Founding-CTO verdict
The hard, expensive, easy-to-get-wrong parts — money-safety, identity/IDOR, the agent's safety model, failure-isolation, and a control room to run the business — are **built and correct**. Batches 1–3 closed the staging-safety, first-open-speed, and vendor-trust/guard gaps cleanly. The scan surfaced **one honest miss** (the vendor Earnings tab is on the split-brain UI branch the login doesn't use) — a small, well-scoped code fix, not a rebuild. After that stitch + a disciplined staging pass on a real hosting tier, **WorkToGo is ready to launch Service + Agent in one city.** Nothing here is a surprise, and nothing here is a rewrite.

---
*Final gate grounded in `wtg-v2` @ 2026-09-04. Read-only — no code changed. Companion to `MAP/system-map.md`, `AUDITS-ROLE/role-01…05`, `AGENT/agent-11`, and `LAUNCH/master-readiness.md`.*
