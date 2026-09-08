# Role Audit 03 — CUSTOMER (deep, world-class stress-test)

> **Scope:** Launch-readiness deep-check of the **customer role** (customer = the business), grounded in real `wtg-v2` code (git branch `v2`) as of **2026-09-02**.
> **Method:** Became the customer. Walked the full journey — browse → discover → book (free & paid Expert Visit) → pay (Cashfree) → track → my-account — and traced each to code: `app/` SPA (`index.php`, `js/{config,api,auth,router,ui}.js`, `pages/{home,bookings,account,login}.js`), the booking backend (`body/service-engine/api/services/index.php`), and payment (`api/payment/*`, inline Cashfree in the service engine). Cross-read `role-01-admin.md` + `role-02-vendor.md` for continuity (the OTP/auth layer is shared and was audited in role-02).
> **Read-only.** Nothing edited/moved/renamed/deleted. `shopping-engine`, `video-engine`, `orders.js` read only. "Where to fix X" cites `MAP/system-map.md` §C.
> **Verdict up front:** The customer role is the **most polished of the three — ~82% ready**. The core journey works end-to-end, is **money-safe and IDOR-strong**, and is engineered to **never dead-end** (skeletons, retry, resume-after-login, and a WhatsApp manual fallback). What holds it back is **weak-internet load weight** (render-blocking Google Maps + a 215 KB `home.js` + Firebase loaded even while notifications are off), **staging safety** (`BASE_URL` hardcoded to production), a **leftover per-payment debug log**, and thin **my-account** (no address book / receipts).

---

## 0. Customer identity & the register-gate (what everything rests on)

| Fact | Where | Consequence |
|---|---|---|
| A customer is a `users` row, `role='customer'`, created on **first OTP verify** (passwordless), or via Google / email. | `heart/api/auth/verify-otp.php:82-108` (audited role-02 §1A) | One shared identity; minimal PII (name/phone/email/city). |
| **Browse is public; booking requires login.** Catalog GETs (`/api/services`, `/api/service/categories`, `/api/service/packages`, `/api/service/expert-visit-fee`) are unauthenticated; **create** (`POST /api/service/request`) and **list** bookings use `AuthMiddleware::require()`. | `services/index.php:1231,1760`; `app/js/auth.js:64-102` | Matches the pilot promise *"login only when you send a booking request."* The customer is never blocked from exploring. |
| The frontend **preserves the in-progress booking across the login redirect**: `requireAuth()` stashes `wtg_pending_booking` + `wtg_post_login_route`; `resolvePostLogin()` resumes it. | `app/js/auth.js:64-102` | No lost work when the login-gate fires mid-booking — a key "no dead-end" behaviour. |
| Session = JWT + refresh token in `localStorage`; 401 → auto-logout. | `app/js/api.js:44-49` | **But the stored refresh token is never used** — a 401 forces a full re-login (§4-E8). JWT is 30-day, so rarely hit. |

---

## 1. CUSTOMER JOURNEY — end to end (grounded)

### 1A. REGISTER / LOGIN — **BUILT, hardened, multi-method**
- **Methods:** phone **OTP** (passwordless, auto-creates the account), **Google** sign-in, **email/password** — all offered on `login.js`. The OTP layer is the same hardened one audited in role-02 §1A (per-phone + per-IP rate limits, bcrypt-hashed OTP, expiry, attempt caps, channel failover, **fails loud in prod** if no SMS provider).
- **Failure recovery:** if OTP send fails, the login screen still offers **Google and email** as alternate paths, and **Resend** for transient failures — so an SMS outage is a degraded experience, not a hard dead-end (`login.js`). *Caveat:* the OTP→alternate hand-off is the login UI offering choices, not an automatic fallback; verify all three methods are visible on one screen.
- **No true "guest" account** in the customer app — "guest" = anonymous browsing + the login-gate at booking. (An `AuthController::loginGuest` exists but the customer app relies on browse-then-gate.)
- **My-identity/data:** created server-side; the client only ever holds a JWT + a cached user object (name/phone/role). Card data never touches WorkToGo (Cashfree-hosted). §3.

### 1B. DISCOVER / BOOK SERVICE — **BUILT, never-a-dead-end**
- **Home / catalog:** category browse, **Company View**, **packages + live estimate**, **Expert Visit** (free or paid), and **Near Me** vendors → direct booking, all in `app/pages/home.js`. City is auto-resolved via `CityResolver` (default Haldwani); catalog endpoints are public and **cache-busted** (`?_t=` in `api.js`) so nothing is stale.
- **State discipline:** the page renders **skeletons** while loading, explicit **"Failed — Try again"** retry blocks on error, and routes **unavailable services / no-coverage** to a **Manual WhatsApp assistance** fallback (`home.js` — many `Manual`/`WhatsApp` branches). This is the "never a dead screen" principle actually implemented.
- **Booking create (`POST /api/service/request`) is robust:**
  - **Server-authoritative pricing.** Expert-visit fee is resolved by `ExpertVisitPricing::resolve()` (admin config, category+city precedence) — **never the client's number** (`services/index.php:1403-1421`). Free → `cod/unpaid`; paid → the inspection Cashfree path.
  - **Idempotent / duplicate-proof.** A `client_request_id` (idempotency key) scoped to `user_id` short-circuits a double-tap/refresh: an active existing booking is returned with `duplicate_prevented:true` and **no second booking is created** (`:1426-1476`). A **failed-payment** booking is treated as inactive so the customer can cleanly retry.
  - **IDOR-safe by construction.** `user_id` is taken from the JWT (`:1494`), never the client. Collision-resistant `WTG-BKG-…`/`WTG-JOB-…` refs. Transactional.

### 1C. PAYMENT (Cashfree) — **BUILT, money-safe from the customer side**
- Paid Expert Visit / inspection creates a Cashfree order **inline** (`services/index.php:1587-1689`): **credential guard** (empty/placeholder `CASHFREE_APP_ID` → booking marked `failed`, "Payment gateway not configured. Contact support." — no crash), **absolute HTTPS** return/notify URLs (B1 fix — falls back to the live host so URLs are never relative), amount = server `$bookingTotal`, `reference_type='booking'`, ref `WTG_B{id}_{ts}`. Returns `payment_session_id` + `mode` → the **Cashfree JS SDK v3** launches checkout (`app/index.php:169`).
- **Return handling is comprehensive** (`home.js:1905-1939+`): `_normalizePaymentState()` maps *every* gateway string (paid/success/captured, failed/declined, cancelled/user_dropped/aborted, refunded, pending/processing/verifying/flagged) to a canonical state; `_showInspectionVerificationState()` shows **"Payment verification in progress"** while polling `GET /api/payment/status` — which is **auth- + ownership-checked** ("Forbidden: You do not own this order", `api/payment/status.php:51`) and reads the **webhook-authoritative** `payment_status`. **The webhook is the sole authority; the return page never self-authorizes** (role-01 §0, memory). So success / failure / timeout / user-drop all resolve to a clear state — no ambiguous "did it go through?" dead-end.
- **Refunds:** admin-initiated only (`core/controllers/RefundController.php`); the customer has **no self-serve refund** — it goes through support. Acceptable at launch; note it.
- ⚠️ **Leftover debug log:** `Logger::error('CASHFREE_ORDER_DEBUG', …)` fires at ERROR level on **every** order creation, dumping booking id / URLs / gateway reply (`services/index.php:1657`). Its own comment says "Remove once the prod root cause is confirmed on staging." → **remove before launch** (log noise + booking-metadata exposure to anyone with log access).

### 1D. TRACKING / MY REQUESTS — **BUILT**
- `app/pages/bookings.js` renders the customer's own requests with **status**, **empty-states**, and **refresh**. The list (`GET /api/service/bookings`) is **role-scoped to `user_id`** with `Cache-Control: no-store` (`services/index.php:1760-1777`) → always fresh, always own. Booking detail enforces ownership (403 otherwise, `:1896-1907`).
- **No realtime** (`REALTIME_WS:false`); it's a poll/refresh model (`CONFIG.POLL_INTERVAL_MS = 12000`). The customer always *can* see the current state, but updates aren't pushed live unless FCM notifications are on (they're `NOTIFICATIONS:false` at launch). §2.

### 1E. COMMERCE — **hidden at launch (out of scope)**
`SHOPPING_UI:false`, `SERVICE_ONLY_MODE:true`. `app/commerce/shop.js` and `app/pages/orders.js` (never-touch) exist but are gated off. The storefront is COD-only when on (memory). Not part of the customer launch; a dead-route guard shows *"This feature is hidden during the Haldwani service pilot."*

---

## 2. UX / HUMAN-PSYCHOLOGY / "ADDICTION" REALITY

**What's genuinely good (feels like a real platform):**
- **Never-a-dead-end** is real: skeletons → content, error → retry, no-coverage → WhatsApp human, login-gate → resume the exact booking after login. This is the single most important thing for conversion and it's implemented, not aspirational.
- **Clean payment reassurance:** "verification in progress" instead of a blank redirect, with all gateway outcomes handled — this is where most clones lose the customer, and here it's solid.
- **Mobile-first PWA:** installable (`manifest.json`, service worker), `viewport-fit=cover`, Hindi-localised Maps (`language=hi`), India support/WhatsApp affordances.

**What would make a customer leave / what confuses:**
- **First-load weight on weak internet (the biggest risk):** the shell synchronously loads **Google Maps JS** (`app/index.php:94` — a **render-blocking** `<script>`, no `async`/`defer`), plus **Firebase app+messaging** (two files, loaded even though `NOTIFICATIONS:false`), **Google GSI**, **Cashfree SDK**, and Google Fonts — and `home.js` itself is **215 KB** of JS to parse on a cheap phone. On a 3G/₹6-8k-Android reality this is a slow first paint. (§5.)
- **No live updates** (notifications + realtime off) — after booking, the customer must reopen/refresh to see progress; combined with manual coordination this can feel quiet ("did anything happen?").
- **Pinch-zoom disabled** (`user-scalable=no`, `maximum-scale=1.0`, `app/index.php:52`) — an accessibility/readability issue for older or low-vision users.
- **Thin my-account** (§3): no saved addresses, no receipts/history beyond the booking list — smaller than the "big-platform" expectation.
- **Session expiry = hard logout** (no refresh-token use) — a customer returning after the token lapses is bounced to login rather than silently refreshed (§4-E8).

**Net feel:** on wifi/normal phone it reads as an Amazon/Instagram-clean, confident flow. On a cheap phone + weak net the *logic* still holds (it degrades gracefully) but the *first load* is heavier than it should be — that's the gap between "works" and "feels instant."

---

## 3. MY-ACCOUNT / DATA PROTECTION

- **Data stored:** `users` (name, phone, email, role, city) + per-booking snapshot (`customer_name/mobile/locality/address` text on the booking) + `payment_status`. **No card data** (Cashfree-hosted). Small, low-sensitivity footprint.
- **My-account surface (`account.js`) is minimal:** profile card (name/phone) + Edit + nav to bookings/support. **No address book** (addresses are inline text per booking; an `address_id` column exists but isn't a customer-managed entity), **no receipts/invoice history**, no data-export/delete-account. XSS-escaped on render (`_escapeHtml`).
- **IDOR posture — STRONG.** Every customer-scoped read enforces ownership from the JWT `user_id`:
  - bookings list → `WHERE b.user_id = :uid` (`services/index.php:1775`);
  - booking detail → explicit owner/vendor/admin check, 403 otherwise (`:1896-1907`);
  - payment status → owner check, "Forbidden: You do not own this order" (`payment/status.php:51`);
  - profile is derived from the token. Catalog is intentionally public.
  A customer **cannot** see or act on another customer's bookings, payments, or profile. No client-supplied id is trusted.

---

## 4. EDGE CASES & FAILURE MODES (broken on paper)

- **E1 — OTP not delivered / provider down.** *Symptom:* "Failed to send OTP"; the login screen still offers **Google + email + Resend**, so recovery exists but is user-driven. *Fix:* `lib/auth/OtpChannelManager` (live secondary) + ensure all methods render together (system-map §C "OTP → lib/auth").
- **E2 — Payment fails / times out / user drops.** *Symptom:* handled — the return page normalises the state and shows verified / in-progress / needs-attention; polling defers to the **webhook** (idempotent, role-01 §0). A failed-payment booking is marked `failed` and can be **retried** (dedup treats it as inactive). *Fix:* n/a; keep webhook-as-authority (system-map §C "paid but not recorded → webhook").
- **E3 — Double-tap "Book" / refresh mid-submit.** *Symptom:* `client_request_id` dedup returns the existing booking (`duplicate_prevented:true`) — **no duplicate** (`services/index.php:1426-1476`). Handled.
- **E4 — Booking with no vendors in area / empty or wrong city.** *Symptom:* the request still creates (admin queue) and the UI routes coverage-gaps to **Manual WhatsApp assistance** — no dead screen; but if `cities`/vendor coverage is empty the customer gets manual handling, not instant fulfilment. *Fix:* `CityResolver` + pilot coverage config; admin adds a city/vendors (system-map §C "wrong city → CityResolver").
- **E5 — Back-button / refresh mid-flow.** *Symptom:* `wtg_home_state` + `wtg_pending_booking` in sessionStorage restore the flow / resume the booking after login (`auth.js:76-102`). Handled.
- **E6 — Concurrent actions.** *Symptom:* booking dedup + webhook idempotency guard the money paths; the customer can't double-create or double-pay. Handled.
- **E7 — Stale data.** *Symptom:* bookings list is `no-store`; catalog is `_t=`-cache-busted. Fresh. Handled.
- **E8 — Expired session.** *Symptom:* 401 → **auto-logout** (`api.js:44-49`); the stored **refresh token is never exercised**, so the customer re-logs in fully. Mid-booking work survives (sessionStorage). *Fix:* wire refresh-token renewal in `app/js/api.js` (system-map §C "token invalid → JWT/AuthMiddleware").
- **E9 — Google Maps key missing/empty.** *Symptom:* `GOOGLE_MAPS_API_KEY` unset → Places autocomplete + geocoding silently fail → address entry degrades (manual text still works). *Fix:* set the env key; degrade to plain address input (system-map §C "config → config/").
- **E10 — Staging points at production.** *Symptom:* `app/js/config.js` `BASE_URL` hardcoded `https://worktogo.in`; a staging deploy without `window.WTG_BASE_URL` sends **real customer bookings/payments to prod**. **Must-fix for a safe staging test.** *Fix:* env-drive `BASE_URL` (same root cause as vendor role-02 §4-E10).
- **E11 — Leftover payment debug log.** *Symptom:* every Expert-Visit payment writes booking metadata + gateway reply at ERROR level (`services/index.php:1657`). *Fix:* delete the `CASHFREE_ORDER_DEBUG` block (system-map §C "payment → lib/payment / service inline order").
- **E12 — Weak-internet first load.** *Symptom:* render-blocking Maps + 215 KB home.js + Firebase can leave a slow/blank first paint or a stalled catalog on 3G. *Fix:* `defer`/lazy-load Maps, code-split `home.js`, drop Firebase while notifications are off (system-map §C "customer surfaces → app/pages/home.js").

---

## 5. DEVICE / UX REALITY (India)

| Aspect | Finding | Verdict |
|---|---|---|
| **Mobile-first / responsive** | Full viewport meta, PWA install, `viewport-fit=cover`, CSS-driven layouts. | ✅ Built for phones. |
| **First-load weight (weak net)** | Render-**blocking** Google Maps `<script>` (no async/defer, `index.php:94`); **Firebase app+messaging** loaded despite `NOTIFICATIONS:false`; GSI + Cashfree + Google Fonts; **`home.js` = 215 KB**. | ⚠️ **Heaviest risk.** Defer/lazy-load Maps, code-split home.js, drop Firebase-when-off. |
| **Cheap-phone JS parse** | 215 KB single page script + big inline render functions. | 🟡 Verify on a real ₹6-8k Android under 3G throttle before launch. |
| **Degradation** | API layer has 10 s timeout → "Request timed out", offline detection → "No internet connection"; pages show skeleton/retry. | ✅ Degrades rather than breaks. |
| **Accessibility** | Pinch-zoom disabled (`user-scalable=no`). | ⚠️ Re-enable zoom for readability. |
| **Staging vs prod** | `BASE_URL` hardcoded to prod (E10). | ⚠️ Must set `window.WTG_BASE_URL` on staging. |

**Device verdict:** logically resilient and mobile-first, but **not yet load-optimised for the exact phones/networks the pilot targets.** The flow won't *break* on a cheap phone; it will *feel slow on first open*. That's fixable with defer/lazy-load/code-split, and should be verified on a real device before launch.

---

## 6. STAGING TEST SCRIPT (customer) — click-by-click, green + negative

> **Pre-reqs (must-fix):** point the app at **staging** via `window.WTG_BASE_URL` (E10), set `GOOGLE_MAPS_API_KEY`, and configure **Cashfree sandbox** creds. Remove/expect the `CASHFREE_ORDER_DEBUG` log (E11).

**A. Browse without login (register-gate)**
1. Open `/app/` **not logged in** → browse categories, Company View, packages, "Near Me". **Green:** everything renders (skeleton → content), no login wall. **Negative:** kill the network mid-load → "No internet connection." / a Try-again block, not a blank screen.

**B. Login (recovery paths)**
2. Tap "Book" → login-gate appears → request OTP → verify. **Green:** logged in **and returned to the exact booking in progress** (resume). **Negative:** spam OTP 6× → 429; enter wrong OTP 5× → "request a new OTP"; then confirm **Google/email** are visible as alternates.

**C. Free Expert Visit / free request**
3. Complete a **free** category booking. **Green:** booking created, `WTG-BKG-…`, status `pending`, no payment. **Negative:** immediately re-submit the same request (double-tap) → **"No duplicate booking was created"** (`duplicate_prevented`).

**D. Paid Expert Visit — real ₹ round-trip**
4. Pick a **paid** Expert-Visit category → book → Cashfree checkout opens (sandbox). **Do a real low-value round-trip:** in sandbox pay ~₹1 (or the configured test amount); in a prod smoke-test use a **real ₹1** where possible. **Green (success):** redirect back → **"Payment verification in progress" → "Payment verified"** once the **webhook** lands; `GET /api/payment/status` shows `paid`. **Negative (fail/drop):** cancel on the Cashfree page → return shows "Payment not completed / needs attention", booking `payment_status=failed`, and you can **retry** (a new order is allowed). **Negative (never self-authorize):** confirm the return page does **not** show "paid" until the webhook has recorded it.
5. **Credential-guard test:** with Cashfree creds blanked, book a paid visit → **"Payment gateway not configured. Contact support."**, booking `failed`, no crash.

**E. Tracking**
6. Open **Bookings** → see the new request with status + empty-state handling when there are none. **Green:** only *your* bookings appear, fresh (no-store). **Negative:** try to open another user's booking id via `/api/service/bookings/{id}` → **403**.

**F. My-account & IDOR**
7. Edit profile (name) → save. **Green:** persists. **Negative:** confirm there is **no** address book / receipts (expectation-setting, §3) and that `GET /api/payment/status?booking_id=<not yours>` → **"Forbidden: You do not own this order."**

**G. Device**
8. Repeat B–D on a **real cheap Android under a 3G throttle**. **Watch:** first-paint time, whether Maps blocks, whether the catalog still loads. Log anything > a few seconds to interactive.

---

## 7. HONEST VERDICT

### Is the customer role launch-ready?

**Yes — ~82% ready; ship after a short must-fix list.** This is the strongest of the three roles. The end-to-end flow (browse → login-gate-with-resume → book → pay → verify → track) is **correct, money-safe, and IDOR-strong**, and it embodies the founder's "never a dead screen" principle in real code (skeletons, retry, WhatsApp fallback, resume-after-login, all-states payment return). Nothing in the core path is broken on paper.

What keeps it from "done" is **polish and operational safety**, not correctness: weak-internet load weight, a staging-safety config, a leftover debug log, and a thin account surface.

### Readiness by area
- Register/login + recovery: **~88%**
- Discover/book (incl. estimate, expert visit, dedup): **~88%**
- Payment (Cashfree, states, money-safety): **~85%** (−debug log, −refund self-serve)
- Tracking / my-requests: **~80%** (no realtime/notifications)
- My-account / data protection / IDOR: **IDOR ~95%**, account features **~55%**
- Device/UX on cheap phones + weak net: **~68%**

### Biggest gaps / risks (ranked)
1. **Weak-internet first-load weight** (§2, §5, §4-E12) — render-blocking Maps + 215 KB `home.js` + Firebase-when-off. *Directly hits conversion on the target phones.*
2. **Staging points at production** (`BASE_URL`, §4-E10) — test bookings/payments would hit live. *Must fix before any staging run.*
3. **Leftover `CASHFREE_ORDER_DEBUG`** on every payment (§1C, §4-E11).
4. **No live updates** after booking (notifications + realtime off) (§1D, §2) — quiet post-booking experience.
5. **Session expiry = hard logout** (refresh token unused, §4-E8).
6. **Thin my-account** — no address book / receipts (§3); pinch-zoom disabled (§5).
7. **Cashfree production readiness** — confirm live creds + a real ₹1 round-trip (the return/notify URL B1 fix and credential guard exist, but prod must be smoke-tested).

### Must-fix **before** launch
- **M1.** Env-drive `app/js/config.js` `BASE_URL` (set `window.WTG_BASE_URL` on staging) so testing never touches prod.
- **M2.** Remove the `CASHFREE_ORDER_DEBUG` ERROR log (`services/index.php:1657`).
- **M3.** **Real payment smoke-test**: sandbox + a live ₹1 round-trip; confirm webhook flips `paid`, return page never self-authorizes, credential-guard path shows the friendly error.
- **M4.** Load-optimise the shell for weak net: `defer`/lazy-load Google Maps, **drop Firebase while `NOTIFICATIONS:false`**, and code-split/trim `home.js`; verify on a real cheap Android under 3G.
- **M5.** Confirm `GOOGLE_MAPS_API_KEY` is set on the target env (else address entry silently degrades).

### Fine to defer **post-launch**
- Refresh-token renewal (smooth session continuity, §4-E8).
- FCM notifications + (later) realtime tracking so the post-booking experience isn't quiet.
- Address book, receipts/invoice history, account data-export/delete.
- Re-enable pinch-zoom; customer self-serve refund request.

**One-line verdict:** *The customer journey is well-architected, money-safe, IDOR-tight, and genuinely never-a-dead-end — it can ship. Before launch, stop staging from hitting production, pull the leftover payment debug log, run a real ₹1 Cashfree round-trip, and lighten the first load for cheap phones on weak internet; the rest (notifications, address book, refresh-token) is post-launch polish.*

---
*Grounded in `wtg-v2` @ 2026-09-02. Read-only audit — nothing modified. Companion to `MAP/system-map.md`, `role-01-admin.md`, `role-02-vendor.md`. Next: role-04 (rider), role-05 (agent).*
