# WTG System Audit 01 — Full Inventory Map (READ-ONLY)

> **Purpose:** A single map of the *real, current* WorkToGo system — login/auth, Customer App, Vendor Panel, and Admin Panel (12 engines) — so a human or AI can understand the actual state without re-reading the codebase.
> **Method:** Read-only inspection of `wtg  live/` on branch `main` (HEAD `0bcd8ad`). Nothing was edited.
> **Date:** 2026-07-03
> **Status legend:** ✅ BUILT (fully wired & working) · 🟡 PARTIAL (UI-only, backend-only, hidden, or thin) · ⛔ MISSING (not built)

---

## 0. System Shape (how the pieces fit)

The live system lives entirely inside **`wtg  live/`** (note the double space in the folder name). Everything else in the repo root is peripheral:

| Path | What it is |
|---|---|
| `wtg  live/` | **The live product.** All 4 surfaces + backend. |
| `swiftdeliver-main/` | Old **React/TypeScript prototype** (RiderDashboard, VendorDashboard, AdminDashboard, TrackingPage). Superseded — not the live system. **Dead/legacy.** |
| `WTG-SYSTEM-BRAIN/` | ~100 prior audit `.md` files (mostly May 2026) + this file. Documentation only. |
| `WTG-HARDENING/` | Hardening notes. |
| `u904615152_worktogo_main (4).sql` | DB dump. |

### Backend anatomy (`wtg  live/`)
The backend is **PHP, no framework**, organized as body-metaphor modules:

- **`heart/`** — API gateway + auth. `heart/index.php` is the front controller; `heart/router.php` maps **intents**; `heart/pipeline/*` builds context and calls the Brain; `heart/api/auth/*` is REST auth; `heart/api/admin/index.php` (**1928 lines**) is the entire admin API.
- **`brain/`** — decision/intelligence engine (BrainCore + engines + models). Wired into the pipeline via `heart/pipeline/BrainConnector.php`. This is "Business Brain."
- **`body/`** — feature engines: `service-engine/` (bookings), `shopping-engine/` (**do not analyze**), `content-engine/`, `video-engine/` (**do not analyze**).
- **`core/`** — shared helpers (JWT, Payment, LedgerEngine, DeliveryPricingEngine, ServiceVendorEligibility, Response, RateLimiter, FcmNotifier…).
- **`lib/`** — `CashfreeClient`, `PaymentService`, `WebhookVerifier`.
- **`api/`** — standalone endpoints: `alerts/*`, `payment/*` (create-order, webhook, status, return), `assignments/accept.php`.
- **`cron/auto_assign.php`** — auto-assignment engine (Smart Assignment backend).
- **`config/`** — `app.php`, `database.php`, `engines.php`, `payment.config.php`.
- **`migrations/`** — 28 dated SQL migrations (latest 2026-06-22).

### Two front-end request styles (important)
1. **Intent pipeline** — Customer App posts `{intent, data}` to the API root; `heart/router.php` dispatches (`service:create_booking`, `service:list_bookings`, `shopping:*`, `user:get_profile`…) into `body/*-engine/index.php`.
2. **Plain REST** — auth, `/api/services`, `/api/search`, `/api/payment/*`, and the **entire Admin panel** (`/heart/api/admin/*`).

---

## 1. Login & Auth

Three separate front-end auth stacks, one shared JWT backend (`core/helpers/JWT.php`, 30-day tokens, `refresh_tokens` table). Backend auth logic: `heart/api/auth/AuthController.php` + `heart/api/auth/*.php`.

### Token keys (confirmed)
| Surface | Access token | User obj | Refresh |
|---|---|---|---|
| Customer App | `wtg_token` | `wtg_user` | `wtg_refresh_token` |
| Vendor Panel | `wtg_vendor_token` | `wtg_vendor_user` | — |
| Admin Panel | `wtg_admin_token` | `wtg_admin_user` | `wtg_admin_refresh` (+ 8h `wtg_admin_expiry`) |

### 1a. Customer login — `app/pages/login.js`, `app/js/auth.js`, `app/js/api.js`
Four methods, all working end-to-end:
- **MSG91 OTP widget** ✅ — when `window.WTG_OTP_METHOD === "widget"`. Loads `verify.msg91.com/otp-provider.js` (fallback `verify.phone91.com`), returns an access token → `POST /api/auth/widget/verify-token`. This is the **primary** path.
- **SMS OTP (direct)** ✅ — fallback path: `POST /auth/otp/send` → `POST /auth/otp/verify` (6-digit, resend timer, paste-to-fill).
- **Google Sign-In** ✅ — GSI button (`window.WTG_GOOGLE_CLIENT_ID`), credential → `POST /api/auth/google`. Backend verifies via Google `tokeninfo`, checks `aud`/`iss`/`exp`/`email_verified`. New Google users get `phone = NULL` (relies on nullable-phone migration).
- **Email / password** ✅ — `POST /api/auth/email/login` + `/register`. Positioned as secondary ("Login karne mein problem?").
- **Guest login** exists in backend (`AuthController::guestLogin`, 24h) but is **not surfaced** in the customer login UI.

Feature flags (`app/js/config.js`): `SERVICE_ONLY_MODE=true`, `GOOGLE_LOGIN=true`, `EMAIL_AUTH=true`.
Post-login routing (`AUTH.resolvePostLogin`) resumes a pending booking / saved route. **Working.**

### 1b. Vendor login — `vendor/index.php`, `vendor/shared/auth.js`
- **Email/password**, **OTP login**, **Forgot password**, and a **Register/apply** tab. ✅ working.
- Role-gated: only `vendor_service` / `vendor_shopping` may enter (`Auth.guard()`); a plain `customer` is told to "logout and login again" (role only flips on admin approval).
- Redirect after login → `bookings.php` (service) or `dashboard.php` (shopping) — **the legacy PHP panel**, *not* the newer HTML dashboards (see §3 duplication note).

### 1c. Admin login — `admin/index.html`, `admin/shared/auth.js`
- **Email/password only** → `POST /auth/email/login`; front-end **rejects any role ≠ `admin`**. No OTP, no Google. ✅ working.
- 8-hour client-side session expiry; `API` client force-logs-out on 401/403.

**Auth verdict:** ✅ The most mature part of the system. All three stacks work. Minor: guest login is dead UI-wise; customer app carries 4 parallel auth methods (surface area).

---

## 2. Customer App (`app/`)

SPA: `app/index.php` shell → `app/js/router.js` (hash routing) → ES-module pages. Only 5 routes are registered; shopping routes are hidden by `SERVICE_ONLY_MODE`.

| Screen / flow | File | Status | Notes |
|---|---|---|---|
| **Login** | `pages/login.js` (597) | ✅ BUILT | See §1a. |
| **Home / Booking** | `pages/home.js` (**3647**) | ✅ BUILT | The core. Service list, category chips/ecosystem, locality header+selector, hero stats, quick-service cards. Two booking modes: **free request / inspection intake** and **vendor-direct**. Full inspection→checkout→payment→verification state machine (`_handleInspectionCheckout`, `_restoreInspectionPaymentReturn`, `_checkInspectionPaymentStatus`). Duplicate-booking guard. |
| **Bookings / tracking** | `pages/bookings.js` (378) | ✅ BUILT | "My Requests." Tabs All/Upcoming/Completed. Auto-refresh 12s. Statuses: pending→confirmed→assigned→in_progress→completed/cancelled. |
| **Account** | `pages/account.js` (231) | ✅ BUILT | Profile card + inline edit (name, address → `PATCH /api/auth/profile`), Help/Support, "Become a vendor" / "Vendor Panel" link, logout. |
| **Orders (shopping)** | `pages/orders.js` (138) | 🟡 PARTIAL | Exists but **hidden** in `SERVICE_ONLY_MODE` (in `SERVICE_ONLY_HIDDEN_PAGES`). Part of shopping-engine — *not analyzed per instructions.* |
| Search / Products / Services / Vendor / Creator routes | — | ⛔/hidden | Listed in `SERVICE_ONLY_HIDDEN_PAGES`; commented-out phases in router (`// Phase 2: vendor`, `// Phase 3: creator`). Not built for the pilot. |

**Support pieces:** `js/map.js` (map), `js/push.js` + `pages/firebase-sw.js` (FCM), `js/ui.js` (toast/nav/skeleton/support), `js/config.js`.

**Observations while reading:**
- `home.js` at 3647 lines is a **monolith** carrying every customer flow — the single biggest maintainability risk on the customer side.
- Pilot lockdowns are config-driven, so several nav destinations intentionally dead-end to a toast ("hidden during service-only mode").
- Guest login backend exists but no UI entry point.
- Language is mixed English/Hinglish in copy (intentional for the Haldwani pilot).

---

## 3. Vendor Panel (`vendor/`)

> ⚠️ **Two generations coexist.** Login sends vendors to the **legacy PHP** panel (`bookings.php`/`dashboard.php`), while newer, richer **HTML dashboards** (`dashboard-service.html`, `dashboard-shopping.html`, `onboarding.html`) also exist. This is the biggest duplication in the repo (see §5).

### Active (legacy PHP, where login lands)
| File | Purpose | Status |
|---|---|---|
| `index.php` (681) | Login / register / OTP / forgot-password | ✅ BUILT |
| `bookings.php` (579) | Service job list (service vendor landing) | ✅ BUILT (assumed active — login target) |
| `dashboard.php` (194) | Shopping vendor landing | 🟡 PARTIAL |
| `orders.php` (264) | Shopping orders | 🟡 (shopping — not analyzed) |
| `products.php` (252) | Product mgmt | 🟡 (shopping — not analyzed) |
| `profile.php` (524) | Vendor profile | ✅ BUILT |

### Newer HTML dashboards (built but not the login target)
| File | Sections present | Status |
|---|---|---|
| `dashboard-service.html` (**1583**) | Dashboard, Bookings (accept/**Decline** modal), **Availability** (weekly schedule + booking calendar), **Portfolio** (add item), **Performance** ("Bookings last 4 weeks"), **Reviews** (reply), **Profile** (+ cert upload), "**Unlock Creator Mode**" | 🟡 PARTIAL — rich UI, but not wired as the live vendor landing; some tiles gated off by pilot flags |
| `dashboard-shopping.html` (1426) | Shopping equivalent | 🟡 (shopping — not analyzed) |
| `onboarding.html` (954) | Apply flow (see fields below) | ✅ BUILT |

**Shared:** `shared/api.js`, `shared/auth.js`, `shared/shell.js`, `shared/realtime.js` (536), `shared/analytics.js`, `shared/push.js`.

### Vendor profile fields — present vs missing
From `onboarding.html` + `profile.php`:
- ✅ Present: Full Name, Business/Store Name, Phone, **City/Area (single free-text field)**, **Profile Photo URL (paste-a-URL only, no real upload)**, Category selection, service form (Service Name, Base Price, **Coverage Area in km**, Description).
- ⛔ Missing / weak: **real photo upload** (`SERVICE_PROOF_UPLOAD:false` in config; only URL-paste), **bio / about text**, **multiple/structured service areas** (only one city string + a km radius), **KYC/Aadhaar/document verification** (a cert-upload UI exists in `dashboard-service.html` but is pilot-disabled: `disable_proof_tiles`).

**Vendor verdict:** 🟡 PARTIAL overall. Core apply→login→see-jobs works via the legacy PHP panel; the polished HTML dashboard (availability, portfolio, reviews, performance) is largely built but **not the live landing** and partly pilot-gated. Profile model is thin (no bio, URL-only photo, single service area).

---

## 4. Admin Panel — the 12 Engines (`admin/` + `heart/api/admin/index.php`)

**Reality check on "12 engines":** this is *product framing*, not a 1-file-per-engine layout. The sidebar (`admin/shared/shell.js`) only lists: Dashboard, Morning Brief, Users, Vendors, Products*, Services, Price Control, Follow-up Center, Orders*, Delivery*, Payments*, System Control*, Logs* (`*` = `hiddenInServiceOnly`, and pilot mode is ON). Several "engines" are **tabs inside the 1783-line `admin/services.html`** ("Service Control Room"), and a few have **no admin UI at all** (backend-only or missing). Admin auth token: `wtg_admin_token`. Backend for almost all of this: `heart/api/admin/index.php`.

| # | Engine | Primary file(s) | Backend | Status | Notes |
|---|---|---|---|---|---|
| 1 | **Morning Brief** | `admin/morning.html` (243) | reuses `GET /api/admin/dashboard` | ✅ BUILT (thin) | Action-card "brief" is **computed client-side from dashboard data** — no dedicated brief endpoint. In sidebar. |
| 2 | **Live Booking Pipeline** | `admin/services.html` → **kanban** tab; also dashboard "Service Operations" | `GET /api/admin/service/bookings`, `PATCH .../ops`, `/jobs/{id}/status` | ✅ BUILT | Real bookings pipeline (Kanban + ops board). |
| 3 | **Smart Assignment** | `admin/services.html` → **ops** tab (manual assign) + **`cron/auto_assign.php`** (auto) | `PATCH /api/admin/service/bookings/{id}/assign`, `core/helpers/ServiceVendorEligibility.php`, `api/assignments/accept.php` | ✅ BUILT | Manual assign UI + production-grade lock-safe auto-assign cron (DB-configured radius/timeout, eligibility by category/area). |
| 4 | **Premium Inspection Management** | Customer: `home.js` inspection flow; Admin: `pricing.html`, `settings.html`, `morning.html`, `followup.html` | inspection price setting (`migrations/2026_06_10_001_inspection_price_setting.sql`), `api/payment/*`, `service/bookings/{id}/ops` | ✅ BUILT | Inspection intake + paid checkout (Cashfree) on customer side; admin sets inspection price & inspection-required categories (`inspection_required_categories: painting, waterproofing, cctv`). |
| 5 | **Company Projects** | — | — | ⛔ MISSING | **No file anywhere** matches company/project. Not built. |
| 6 | **Follow-up Center** | `admin/followup.html` (350) | `GET /service/bookings`, `GET /vendors`, `PATCH .../assign` | ✅ BUILT | In sidebar. Lists bookings needing follow-up + reassign. |
| 7 | **Category Control Room** | `admin/services.html` → **categories** tab (+ `banners.html` merged in) | `/api/admin/categories`, `/categories/{id}`, `/categories/{id}/banner`, `/categories/{id}/stats`, `/categories/{id}/vendor/{id}/stats` | ✅ BUILT | Heavily worked recently (git log: filters, drawer, pending approvals, banner upload, commission %). Banner page was folded into this room. |
| 8 | **Area Intelligence** | `admin/dashboard.html` "Area Demand" table; `services.html` area refs | dashboard `area_demand` (bookings/completed/vendors per area, shortage flags) | 🟡 PARTIAL | **Read-only analytics only** — flags "No vendor"/"Shortage"/"OK." No dedicated area *control* surface. |
| 9 | **Vendor Scorecard** | `admin/vendors.html` (349) + vendor detail | `GET /api/admin/vendors`, `GET /vendors/{id}` (completion_rate, active/completed, perf), `/categories/{id}/vendor/{id}/stats`, approve/reject/status/verify/category | 🟡 PARTIAL | Vendor list + per-vendor performance stats + moderation actions exist; there is **no single dedicated "scorecard" ranking view** — stats are spread across vendor detail + dashboard "top vendors." |
| 10 | **Payment Control** | `admin/payments.html` (159), `admin/refunds.html` (520) | `GET /api/admin/payments`; refunds → `heart/index.php`→`RefundController`; `lib/PaymentService`, `CashfreeClient`, `WebhookVerifier`, `api/payment/webhook.php` | 🟡 PARTIAL | Backend is real (Cashfree + refunds + ledger). But **`Payments` is `hiddenInServiceOnly` and pilot mode is ON**, so it's not in the live sidebar; `refunds.html` is an orphan page (not in nav). |
| 11 | **Global Search** | Customer `API.search`; backend `heart/api/search/index.php` | `GET /api/search?q=&type=&limit=` | 🟡 PARTIAL | Search **backend exists** and the customer app uses it; **no admin global-search UI** was found. Backend-only from the admin POV. |
| 12 | **Business Brain** | `brain/` (BrainCore, engine/*, models/*, Controllers/*) | wired via `heart/pipeline/BrainConnector.php`, `PriorityResolver`, `EngineLoader`; `brain/scripts/NightlyJob.php` | 🟡 PARTIAL | Real engine scaffold **wired into the request pipeline**, but **no admin UI** and it leans on `core/FallbackResponse` / placeholder rules (e.g. `brain/rules/video_ranking_placeholder.php`). Present and connected, not surfaced or proven "smart." |

### Other admin pages (context)
- `dashboard.html` (437) ✅ "Intelligence Control Center" — real `/api/admin/dashboard` (orders, service stats, finance, drivers, system health, top vendors, cancelled items, cash-flow deviation, area demand, alerts). This is the true hub and overlaps engines 1/2/8/9.
- `users.html` ✅ (`/users`, block/unblock/role), `services.html` ✅, `pricing.html` ✅ (price control), `system.html` 🟡 (`/admin/settings`, hidden in pilot), `logs.html` 🟡 (hidden), `delivery.html` 🟡 (hidden), `orders.html`/`products.html` 🟡 (shopping, hidden).
- `analytics.html` (562) 🟡 **UI-only with a literal "Sample data — connect API for live data" banner.** Not in sidebar. Mock.
- `banners.html` (531) — superseded/merged into Category Control Room (git: "Merge category overlays into unified control room").

---

## 5. Dead code, duplicates & loose ends (recorded, not fixed)

1. **Duplicate Vendor Panel (biggest issue):** legacy PHP (`dashboard.php`, `bookings.php`, `orders.php`, `products.php`, `profile.php`) **vs** newer HTML (`dashboard-service.html`, `dashboard-shopping.html`, `onboarding.html`). Login lands on the **legacy PHP**, leaving the richer HTML dashboards (availability/portfolio/reviews/performance) built-but-unreached.
2. **`swiftdeliver-main/`** — an entire **separate React/TS app** (old prototype: Rider/Vendor/Admin dashboards, TrackingPage, `server.ts`, `db/database.ts`). Not part of the live PHP system. **Dead/legacy.**
3. **Orphan admin pages not in the sidebar:** `analytics.html` (sample/mock data), `refunds.html`, `banners.html` (merged away), `settings.html`, `system.html`, `logs.html`, `delivery.html` (last several gated by `hiddenInServiceOnly` + pilot mode).
4. **`analytics.html` ships mock data** — explicit "Sample data — connect API for live data" banner.
5. **Business Brain placeholder rules:** `brain/rules/video_ranking_placeholder.php`; Brain output frequently falls back via `core/FallbackResponse.php`.
6. **Guest login** (`AuthController::guestLogin`) — backend only, no UI entry point on any surface.
7. **Two parallel alert systems:** `api/alerts/*` (AlertEngine, create/fetch/mark_seen) + `assets/js/alerts.js` + `brain/engine/AlertEngine.php` — overlapping alert logic in 3 places.
8. **`home.js` monolith** (3647 lines) — every customer flow in one module.
9. **Root-level audit/report `.md` clutter** in `wtg  live/` (`FIXES_APPLIED.md`, `ROOT_CAUSE_AUDIT.md`, `FIX_VERIFICATION_REPORT.md`, `PHASE_0.2_PLAN.md`, `audit-report.md`, `AUTHENTICATED_FLOW_TEST_GUIDE.md`) plus ~100 files in `WTG-SYSTEM-BRAIN/` — historical, many likely stale (May 2026).
10. **13 open feature/fix git branches** (vendor-assign-visibility, category-resolution, booking-mode-routing, cashfree-prod-order-failure…) — active churn around assignment, categories, and Cashfree payments.

---

## 6. Explicitly NOT analyzed (per instructions)
`body/shopping-engine/*`, `body/video-engine/*`, and `app/pages/orders.js` / `vendor/*shopping*` / admin Orders — **noted as existing only.** The shopping stack is real and sizable (cart/orders/products controllers + services) but intentionally left unexplored; it is hidden across the pilot via `SERVICE_ONLY_MODE`.

---

## 7. One-paragraph state of the system
WorkToGo is a **service-booking pilot** (Haldwani, ~5 categories) built on framework-less PHP with three thin JS/PHP front-ends over one JWT backend. **Auth is the strongest layer** (OTP-widget + SMS + Google + email, all working). The **Customer booking + paid-inspection flow is fully built** but crammed into a 3647-line monolith. **Admin is genuinely capable** where it counts — dashboard intelligence, live pipeline, manual + cron auto-assignment, category control room, follow-up, inspection pricing — but the "12 engines" framing oversells it: **Company Projects doesn't exist**, **Global Search and Business Brain are backend-only with no admin UI**, **Area Intelligence and Vendor Scorecard are read-only analytics rather than control surfaces**, and **Payment Control is hidden by pilot mode**. The **weakest, most confusing area is the Vendor Panel**, which ships two competing generations where the richer one isn't even the login target, over a thin profile model (no bio, URL-only photo, single service area).

---
*Generated by read-only inventory pass. No source files were modified.*
