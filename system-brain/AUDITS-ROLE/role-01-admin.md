# Role Audit 01 — ADMIN (deep, top-engineer stress-test)

> **Scope:** Launch-readiness deep-check of the **admin role** only, grounded in the real `wtg-v2` code (git branch `v2`) as of **2026-09-02**.
> **Method:** Became the admin. Walked every admin surface (`wtg-v2/admin/*`), traced each control to its backend handler (`heart/api/admin/index.php` 2 967 lines, `commerce.php`, `agent.php`, `heart/api/delivery/index.php`), and stress-tested each capability for "does it actually work end-to-end?" and "is it truly data-driven / zero-code?".
> **Read-only.** Nothing was edited, moved, renamed, or deleted. `shopping-engine`, `video-engine`, `orders.js` read only. All "where to fix X" citations reference `MAP/system-map.md` Section C.
> **Verdict up front:** For the **launch scope (service-only, one super-admin)** the admin role is **~85% ready** — the day-one control surfaces genuinely work and are genuinely data-driven. The one large gap (RBAC delegation) is honestly a *foundation-only* layer that does not affect a single-admin launch. Must-fix-before-launch items are small guardrails, not rebuilds.

---

## 0. How admin auth actually works (the ground truth everything rests on)

| Fact | Where | Consequence |
|---|---|---|
| Admin logs in with **email + password** via the shared `/heart/api/auth/email/login`; JWT `role` claim = `users.role`. | `heart/api/auth/AuthController.php:145-197`, `admin/shared/auth.js:37` | There is **no separate admin login**. The panel front-end simply refuses the session if `data.role !== 'admin'` (`auth.js:37`). |
| The backend gate for **every** admin route is `AuthMiddleware::requireRole(ROLE_ADMIN)`. | `heart/api/admin/index.php:66`, `heart/middleware/AuthMiddleware.php:28-47` | You are an admin **iff** your JWT role is the legacy enum `'admin'`. Nothing else grants entry. |
| Login is **rate-limited** 5 attempts / 300 s (`login_email`). | `AuthController.php:127` | Brute-force protection is present ✅. |
| JWT lifetime is **30 days**; the panel's localStorage expiry is **8 hours**. | `AuthController.php:178` vs `admin/config.js:13` + `auth.js:44` | **Mismatch** — the token stays valid 30 days server-side even though the UI logs out at 8 h. A leaked/stolen admin token is usable for a month. (Edge case §4-E1.) |
| Minting an admin = set `users.role='admin'` (self-serve from the panel). | `POST /api/admin/users/{id}/role` → `index.php:1745-1788` (whitelist includes `admin`) | Promotion works, but it is **all-or-nothing** (full legacy all-access) and has **no self-demotion / last-admin guard**. (§4-E2.) |

**Single most important structural fact:** the elaborate RBAC system (roles, permissions, scopes) is **built and seeded but dormant** — see §1 (Access Control) and §3. It cannot yet restrict who reaches the panel because (1) only legacy `role='admin'` passes the login + `requireRole` gate, and (2) that role **bypasses every `Permission::enforce()` check** (`core/helpers/Permission.php:47-50`). Every `Permission::enforce('…')` you see inside the handlers is a **no-op for today's admin** — an additive gate waiting for a future non-admin panel login that does not exist yet.

---

## 1. ADMIN CAPABILITY MATRIX

Legend: **BUILT** = works end-to-end today · **PARTIAL** = works but with a real caveat · **FOUNDATION** = data + endpoints exist but not wired to a live effect · **HIDDEN** = fully built but not reachable in the launch nav (`SERVICE_ONLY_MODE`).

| # | Surface / control-room | Front-end | Backend handler | Status | Works E2E? | Truly data-driven / zero-code? |
|---|---|---|---|---|---|---|
| 1 | **Operations board** (Dashboard) | `admin/dashboard.html` | `GET /api/admin/dashboard` `index.php:119-436` | **BUILT** | ✅ Aggregates orders + service bookings + revenue + pending-assignment + follow-up, defensively guarded for absent tables. | N/A (read model) |
| 1b | Morning Brief / Follow-up Center | `morning.html`, `followup.html` | dashboard/followup routes | **BUILT** | ✅ ops daily views | N/A |
| 2 | **Add-a-city** (multi-city) | `admin/cities.html` (wired: `API.get/post/patch('/cities')`) | `index.php:2024-2108` | **BUILT** | ✅ Add / edit / enable / disable; counts vendors+bookings; **no DELETE by design** (FK-safe, disable via `is_active=0`). `CityResolver` reads `cities WHERE is_active=1`, so a new city is picked up by resolution end-to-end. | ✅ **Zero-code.** One DB row; slug auto-derived; `Permission`-gated `settings.manage`. |
| 3 | **Add shop-type** (commerce taxonomy) | `commerce-stores.html` | `commerce.php:126-177` | **HIDDEN** (built) | ✅ Add / edit / activate; store-count per type; unique-slug. | ✅ **Zero-code** (`store_types` row). Hidden by `SERVICE_ONLY_MODE`. |
| 4 | **RBAC / roles & permissions** | `admin/roles.html` (fully wired) | `index.php:1333-1588` + `Permission.php` | **FOUNDATION** | ⚠️ You can create roles, edit them, set their permission set (transactional replace-all), and assign roles to users with scope (global/category/city) — **all these writes succeed**. But **none of it changes who can access the admin panel or what they can do**, because only legacy `role='admin'` can log in and that role bypasses all checks. See §3. | ✅ to *define* a role zero-code; ❌ to *use* a role as a limited admin login (needs code). |
| 5a | **Payment config — expert-visit / inspection pricing** | `admin/services.html` (calls `expert-visit-pricing`), `pricing.html` (`/settings/inspection_price`) | `index.php:1127-1249` + `SettingsController` | **BUILT** | ✅ Global default (`app_settings.expert_visit_fee/_free`) + **category- and/or city-scoped overrides** with dedup upsert + free/amount + activate/delete. Inspection price clamped ₹1–₹10 000. | ✅ **Zero-code**, data-driven at global + category + city granularity. |
| 5b | **Payment config — gateways** | *(none)* | `config/payment.gateways.php` + `lib/payment/PaymentGatewayManager` | **PARTIAL** | ⚠️ **No admin UI and no admin API for gateways.** Cashfree live; Razorpay is a **stub adapter** (`enabled=false`). Per-engine *routing* (primary/secondary) is runtime-overridable via `app_settings.payment_gateway_routing` (JSON), but only by hand-editing that raw setting. | ❌ Adding a *new* gateway = write an adapter class + flip the config file + set creds + deploy. Re-pointing existing = data (raw JSON), no friendly UI. |
| 6 | **Payment Room** (revenue control) | `admin/payments.html` (wired) | `index.php:2391-2485` + `PaymentReport.php` | **BUILT** | ✅ Read-only room (`/payments/room?period&city_id`), per-vendor revenue, and **`POST /payments/settle`** (real-money pending→available, idempotent, `SELECT…FOR UPDATE`, transactional, before/after snapshot). Note: settle advances the ledger; it does **not** wire a bank payout (that step is separate/out of scope). | ✅ period + city filters are data; settlement is code-correct. |
| 7 | **Media approval** | `admin/media.html` (wired) | `index.php:1251-1319` + `Media.php` | **BUILT** | ✅ Pending queue (vendor work-photos) with vendor names, approve/reject with notes, and an **auto-approve toggle** (`app_settings.media_auto_approve`). | ✅ Zero-code; approval mode is a data toggle. |
| 8 | **Vendor management** | `admin/vendors.html` (wired) | `index.php:1791-1927` | **BUILT** | ✅ Approve (flips `vendors.status` + promotes `users.role` + FCM notify), reject, enable/disable, verify, re-category (writes `vendor_categories` junction), full detail + perf. | ✅ Data-driven. |
| 8b | **Vendor accountability / throttle** | `admin/vendors.html` (`/vendors/flagged`, `/accountability`, `/accountability/config`) | `index.php:1929-2014` + `VendorAccountability.php` | **BUILT** | ✅ System auto-flags (worst-first) from jobs/assignments; admin acts (manual flag, review-ack, note, **work_throttle normal/reduced/paused**); thresholds are `app_settings`-driven with sane clamps. **Correction to an inline comment:** `index.php:1948` says throttle is "not yet enforced by assignment" — **it IS enforced**: `cron/auto_assign.php:97-98` excludes `paused` and de-prioritises `reduced`, and `body/service-engine/api/vendors/index.php:161-163` + `ServiceVendorEligibility.php:149` do the same for the vendor list. The comment is **stale doc-drift**; the feature works. | ✅ Thresholds + throttle are data-driven and live. |
| 9 | **Commerce admin** (stores / products / inventory) | `commerce-stores.html`, `commerce-products.html` | `commerce.php` (whole file) | **HIDDEN** (built) | ✅ Store CRUD (company dark-store *and* local vendor store, city-scoped, logo upload), product CRUD (photo, SKU 409-dedup), **inventory PATCH**. Each route `Permission`-gated (`stores.*`, `products.*`, `inventory.manage`). | ✅ Zero-code. Hidden by `SERVICE_ONLY_MODE`. |
| 10 | **Delivery control-room** | `admin/delivery-control.html` (wired to `/api/delivery/admin/*`) | `heart/api/delivery/index.php:88-190` | **HIDDEN** (built) | ✅ Rate-cards CRUD (base/per-km/min/rider-share/free-above/max-distance, city-scoped), deliveries board with attention filter, **reassign**, riders board (online, load, cash-in-hand vs limit). | ✅ Rate cards are data. Hidden by `SERVICE_ONLY_MODE`. (Delivery is post-launch per system-map.) |
| 11 | **Agent control-room** | `admin/agent.html` (wired) | `agent.php` (monitor) + `PUT /settings/{key}` (control) | **BUILT** | ✅ **Kill-switch** (`agent_enabled`), capability pauses, **AI budget cap**, provider pick — all write `app_settings` and are **enforced at runtime** (`AgentPolicy.php:84,105` reads them; `AgentDisabledException` thrown when off). Monitor is read-only from audit tables (metadata only, no PII): 24 h summary, activity feed, provider usability, routing, growth signals. | ✅ Zero-code; everything is a data setting taking effect next turn. |
| 12 | System Control / Logs & Activity | `system.html`, `logs.html` | `/system-config`, `/logs`, `/logs/activity` | **HIDDEN** (built) | ✅ Generic `system_config` key/value upsert; log tail + activity. | ✅ Data. Hidden by `SERVICE_ONLY_MODE`. |
| 13 | Users / Products (legacy) | `users.html`, `products.html` | `/users*`, `/products*` | **BUILT / mixed** | ✅ user list/detail/block/unblock/role; products legacy list. | Data. |

**Bottom line of the matrix:** every day-one *service-launch* control room is **BUILT and works end-to-end**, and the flexible ones (city, pricing, media mode, accountability thresholds, agent governance) are **genuinely data-driven**. The commerce/delivery/orders/system/logs rooms are **fully built but hidden** behind `SERVICE_ONLY_MODE` (intentional for a service-only launch). The only true *runtime* gap in the day-one set is **gateway config has no UI**, and the only *structural* gap is **RBAC is dormant**.

---

## 2. THE "30-SECOND CONTROL ROOM" CHECK

> *Founder principle: can the admin run the whole business from the panel, fast?*

**Reachable in ≤2 clicks from the sidebar (launch/service-only nav):** Dashboard, Morning Brief, Users, Vendors, Media Approvals, Services (+ expert-visit pricing lives inside Services), Price Control, Follow-up, **Payment Room**, Agent Control, Access Control (roles), Cities. That is a real, coherent control room — the founder can approve a vendor, moderate a photo, change inspection price, add a city, settle payouts, and kill the AI **without leaving the panel**.

**What's reachable but takes too many steps / is unfriendly:**
- **Expert-visit pricing** is buried inside the giant `services.html` (116 KB) rather than being its own "Pricing" room alongside `pricing.html` (which only does inspection price). Two pricing surfaces, split by where the code grew — a founder won't guess that city/category visit-fee overrides live under *Services*.
- **Gateway routing / enable Razorpay / pilot city allowlist / agent thresholds** are only changeable by editing **raw `app_settings` JSON** in `settings.html` (or via `PUT /settings/{key}`). They *are* data-driven, but there is **no form** — you must hand-write JSON. Not a "30-second" action for a non-engineer.

**What's missing from the control room:**
- **No gateway health / "is Cashfree up?" tile** anywhere in the panel. If payments start failing, the admin has no in-panel signal (must read logs).
- **No "who else is logged in / active admin sessions" view**, and no admin-session revocation (JWT is stateless, 30-day). Can't kick a compromised admin from the panel.
- **No one-click "open commerce / exit pilot"** — `SERVICE_ONLY_MODE` is a **hardcoded JS constant** (`admin/shared/shell.js:7`), so flipping the business from service-only to full commerce is a **code edit**, not a panel toggle.

**Verdict:** For the launch business (service, single admin) the 30-second-control-room principle **largely holds** — the frequent daily actions are all one or two clicks and genuinely work. It falls short only on (a) two split pricing surfaces, (b) config that's data-driven but JSON-only (no form), and (c) no live payment/agent health tiles or session control.

---

## 3. FLEXIBILITY VERIFICATION — do the "no-code" promises hold?

| Promise: add X without code | Reality | Holds? |
|---|---|---|
| **A city** | `POST /api/admin/cities` → one row; `CityResolver` honours `is_active`. | ✅ **Yes, zero-code.** |
| **A shop-type** | `POST /api/admin/commerce/store-types` → one row. | ✅ **Yes, zero-code** (nav hidden in service-only). |
| **A service category / package** | `POST /api/admin/categories` (`index.php:636`) + `POST /api/admin/categories/{id}/packages` (`:1014`); package fields fully data-driven via `adminBuildPackageFields()` (flat-price OR per-sqft, features JSON). | ✅ **Yes, zero-code.** |
| **A role + its permission set** | `POST /api/admin/roles`, `PUT /api/admin/roles/{id}/permissions` — from a fixed 51-permission catalog seeded by `2026_08_13_001_rbac_foundation.sql`. | ⚠️ **Half.** You can **define & assign** a role zero-code, but you **cannot deploy a working limited-access admin** from it — see below. |
| **A payment gateway** | Adapter class (Razorpay = stub) + `config/payment.gateways.php` flip + creds + deploy. Routing re-point is runtime JSON. | ❌ **Secretly needs code** for a *new* gateway. |
| **An auth method / OTP channel** | `config/auth.channels.php` (WhatsApp/Sms2 = stubs) — same pattern: adapter class + flip + creds. Enabling/disabling an *existing* method and routing is runtime JSON (`auth_channel_routing`). | ❌ **Secretly needs code** for a *new* channel; toggling existing = data. |
| **Open a new pilot city to customers / change hero text** | `servicePilotConfig()` = hardcoded fallback **`array_replace`d** with `app_settings.pilot_public_config` (JSON). So enabled_cities/categories/hero/support are **overridable via that one settings key**. | ⚠️ **Data-driven but JSON-only** (no form; the key lives in a never-touch engine file's fallback, but the override path is data). |

### The RBAC "secretly needs code" finding (the big one)

The founder's mental model — *"compose a Finance role, a Category Head, give someone a limited admin login, all from the panel"* — **does not work today**, and the reason is three code-level facts, not a data gap:

1. `admin/shared/auth.js:37` rejects any login whose `role !== 'admin'`. A user holding the `finance` or even `super_admin` RBAC role but `users.role='customer'` **cannot enter the panel at all**.
2. `heart/api/admin/index.php:66` → `AuthMiddleware::requireRole(ROLE_ADMIN)` (`:33`) admits **only** JWT `role==='admin'`.
3. `Permission::userHas()` returns `true` for **anyone** with `users.role==='admin'` before any DB check (`Permission.php:47-50`). So the per-endpoint `Permission::enforce('payments.view')` etc. never actually restrict a real admin.

**Net:** the RBAC tables are fully seeded (super_admin + category_head/ops_manager/expert/finance/moderator, each mapped to the right permissions; every existing admin auto-mapped to super_admin), the UI manages them correctly, and the endpoints are already instrumented with the right `enforce()` calls — but it is a **loaded gun with the safety welded on**. Turning it live is a **contained code change** in exactly three spots (login gate, `requireRole`, and the JWT/role source), after which the existing `enforce()` calls light up automatically. Until then, "limited admin without code" is **not** deliverable. This is honestly documented in the code's own comments (`index.php:1322-1329`).

---

## 4. EDGE CASES & FAILURE MODES (trying to break it on paper)

Format: **symptom → where to fix** (system-map §C unless noted).

- **E1 — Stolen/expired admin token.** Panel "logs out" at 8 h but the JWT is valid **30 days** and stateless; there's no revocation. *Symptom:* a leaked admin token keeps working for a month; logging out doesn't invalidate it server-side. *Fix:* `heart/api/auth/AuthController.php:178` (shorten admin exp) + a token/refresh revocation check in `core/helpers/JWT.php` / `AuthMiddleware` (system-map §C "Token invalid → JWT + AuthMiddleware").
- **E2 — Last-admin / self-demotion lockout.** `POST /users/{id}/role` (`index.php:1745`) lets an admin set **their own** or the **last** admin to `customer`. *Symptom:* nobody can log into the panel; recovery needs raw DB access. *Fix:* add a guard in that handler (block demoting self / the last remaining admin) — `heart/api/admin/index.php:1745`.
- **E3 — Empty/missing config.** Every data-driven room is written **schema-defensively**: cities/commerce/expert-visit/accountability wrap reads in try/catch and return **503 "run the migration"** or an empty set instead of a 500 (e.g. `index.php:2038-2040, 2077-2079`; `commerce.php:97-99`). *Symptom on a fresh env:* rooms show "table not available — run the migration," not a crash. *Fix / prevention:* run `migrate.php` (system-map §C "schema change → migrations/"). **This is a strength**, but it also means a **forgotten migration silently disables a whole room** with only a soft 503 — see the staging script negative tests.
- **E4 — Migration not run for RBAC.** `Permission::hasTable()` fail-closes: non-admins denied, **admin still all-access** (`Permission.php:54-57`). *Symptom:* roles.html lists nothing / 500-safe. *Fix:* apply `2026_08_13_001_rbac_foundation.sql`.
- **E5 — Gateway/provider down.** Payment: manager fails over primary→secondary **only if a secondary is configured** — today every engine's `secondary=null` (`config/payment.gateways.php:46-49`), so a Cashfree outage = **no failover**, service ₹299 + commerce checkout stall. *Symptom:* customers can't pay; **admin has no in-panel health signal** (§2). *Fix:* implement + enable RazorpayAdapter and set `secondary=>'razorpay'` (system-map §C "Payment not completing → lib/payment"). OTP: `msg91` has `log` as secondary (dev-only) — in prod an MSG91 outage = **login OTP fails loudly, no real fallback** until `sms2`/WhatsApp are live (`config/auth.channels.php:42`). *Fix:* `lib/auth/OtpChannelManager` + config (system-map §C "OTP not arriving → lib/auth").
- **E6 — Two admins act at once.** Role-permission set uses a **transactional replace-all** (`index.php:1487-1498`); expert-visit uses **dedup upsert**; settlement uses **`FOR UPDATE`** — all safe. **But** generic settings (`SettingsController::updateSetting`) and city/vendor edits are **last-write-wins**: two admins editing the same setting/city silently overwrite each other with no conflict warning. *Symptom:* one admin's change vanishes. *Fix:* `core/controllers/SettingsController.php:129` (add updated_at optimistic check) — low priority for a small ops team.
- **E7 — Unknown/typo'd setting key becomes public.** `updateSetting` **auto-creates** a missing key with **`is_public = 1`** (`SettingsController.php:88`). *Symptom:* a mistyped or new governance key (e.g. a fat-fingered `agent_budgett`) is world-readable via `GET /api/auth/settings` public. *Fix:* default new keys to `is_public=0` and/or whitelist writable keys — `SettingsController.php:71-129`. (Also: the generic `PUT /settings/{key}` is **not** per-key `Permission`-gated — it rides on `requireRole(admin)`; harmless today, matters when RBAC goes live.)
- **E8 — Bad value entered.** Numeric settings validated; `inspection_price` clamped ₹1–10 000; accountability thresholds clamped to ranges; JSON settings JSON-validated; role slug/city slug normalised & 409 on dup. *Symptom:* most bad input is rejected with a 422, not persisted. **Gap:** expert-visit `amount` only `max(0,…)` — **no upper clamp** (an admin could set a ₹9 999 999 visit fee). *Fix:* `index.php:1186/1227`.
- **E9 — RBAC misconfigured (future).** If/when RBAC goes live and an admin strips `super_admin`'s perms — blocked: system roles are **locked** (`index.php:1458`) and can't be deactivated (`:1434`). Good. But a custom role with **zero** permissions assigned to a future limited-admin = a user who can log in and see nothing; and scoped grants (category/city) are only honoured if the endpoint passes a scope — most admin endpoints call `enforce()` **without** scope, so a "Category Head" would effectively get **global** rights on those routes. *Fix when wiring RBAC:* pass scope into `enforce()` on category/city-scoped routes (`Permission.php:76-81` supports it).
- **E10 — Media queue flooded / auto-approve left on.** If `media_auto_approve=1` is toggled and forgotten, unmoderated vendor photos go live. *Symptom:* bad imagery on the storefront. *Fix:* it's a single data toggle — `index.php:1294` (and consider a dashboard reminder tile).
- **E11 — Add-a-city changes the default city.** `CityResolver` picks the default as the **first active city by `sort_order`** (`CityResolver.php:132`). Adding a new city with `sort_order=0` can silently become the fallback for unresolved requests. *Symptom:* new signups/bookings land in the wrong city. *Fix:* set `sort_order` deliberately when adding a city; guard in `CityResolver` (system-map §C "wrong city → CityResolver").

---

## 5. STAGING TEST SCRIPT (admin) — click-by-click, with "green" and negative tests

Run on **staging** as the seeded super-admin. "Green" = the stated result with **no red toast / 500 / blank room**.

**A. Login & shell**
1. Go to `/admin/index.html`, log in with the admin email/password. **Green:** redirect to `dashboard.html`, sidebar shows the service-only groups (no Commerce/Orders/Delivery/System/Logs). **Negative:** log in with a *vendor/customer* account → "Access denied" (`auth.js:37`). **Negative:** 6 rapid bad passwords → **429** "Too many login attempts".
2. Dashboard renders order + service + revenue tiles (zeros are fine on empty staging). **Green:** no blank/red tiles. Bell polls `/admin/notifications`.

**B. Cities (zero-code flexibility)**
3. Access Control area → **Cities** → Add city "Test Nagar". **Green:** 201, appears in list with vendor/booking counts = 0. **Negative:** add a second city with the same name/slug → **409** "already exists". **Negative:** disable it (`is_active=0`) → it stays listed but greyed; confirm `CityResolver` no longer offers it.

**C. Pricing (data-driven money)**
4. **Services → expert-visit pricing** → set **global default** ₹299. **Green:** 200 "Global default updated". Add a **category override** (e.g. painting = Free) and a **city override**. **Green:** override rows saved, dedup (saving the same scope twice updates, not duplicates). **Negative:** save an override with neither category nor city → 422 "must scope by category and/or city". 
5. **Price Control → inspection price** set ₹299. **Negative:** set ₹0 or ₹99999 → 422 (clamp ₹1–10 000).

**D. Vendors + accountability**
6. **Vendors** → approve a pending vendor. **Green:** status→active, "require re-login" message, vendor's `users.role`→`vendor_service`. → set **work_throttle = paused** on a vendor. **Green:** saved. **Verify enforcement:** run `cron/auto_assign.php` (or the vendor list) and confirm the **paused vendor is excluded** and a `reduced` vendor is de-prioritised.
7. **Accountability config** → tune a threshold (e.g. `min_completion_rate`). **Green:** clamped + saved. **Negative:** send a garbage value → clamped to range, not rejected-to-500.

**E. Media**
8. **Media Approvals** → approve one pending photo, reject another with a note. **Green:** item leaves the queue, status set. Toggle **auto-approve on then off**. **Green:** mode persists (`media_auto_approve`).

**F. Payment Room**
9. **Payment Room** → load room for period=30d, then filter by the new city. **Green:** computed tiles, no writes. → **Settle** one vendor. **Green:** before/after wallet snapshot, `settled_count`, transactional. **Negative:** settle a non-existent vendor_id → 404; settle when wallet tables absent → 503 (not 500).

**G. Agent control room (kill-switch)**
10. **Agent Control** → flip the **kill-switch OFF**. **Green:** "Agent turned OFF"; then hit any agent action as a customer on staging → **blocked** (`AgentDisabledException`). Flip **ON** → works next turn. Set an **AI budget** and a capability pause; confirm `agent.php` overview reflects the new snapshot and the 24 h monitor loads (empty is fine).

**H. RBAC (expectation-setting test — proves the gap)**
11. **Access Control** → create role "Finance", give it the payments perms, assign it to a **non-admin** test user. **Green:** all writes 200. **Now the revealing negative test:** log out, log in as that Finance user → **"Access denied"** (they can't enter the panel). This **confirms RBAC is dormant** and must not be relied on for delegation at launch.

**I. Cross-cutting negative tests**
12. Hit any admin API with **no token / expired token** → 401/403 and the panel bounces to login (`api.js:67`). 
13. On a DB **missing a migration** (e.g. drop `cities` on a throwaway staging), open Cities → **503 "run the migration"**, not a white-screen 500. Re-run `migrate.php` → green.

---

## 6. HONEST VERDICT

### Is the admin role launch-ready?

**For the actual launch scope — service-only business, one (or a few equally-trusted) super-admin(s): YES, ~85% ready.** The daily control room is real, works end-to-end against live handlers, and the flexible parts (city, pricing at 3 granularities, media mode, accountability thresholds + live throttle, agent governance) are **genuinely data-driven**, defensively coded, and money-safe (transactions / `FOR UPDATE` / idempotent settlement / rate-limited login). This is well above typical pre-launch quality.

**For the founder's *fuller* vision — delegated, limited-access admins (Finance, Category Head, City Ops) composed from the panel: ~40% ready.** The data foundation is 100% there and seeded, but it is **dormant**; delegating access still requires a code change.

### Readiness by area
- Day-one service control room: **~90%**
- Data-driven flexibility (city / shop-type / category / package / pricing): **~95%**
- Payments (room + settle + expert-visit config): **~90%**; gateway *management* UI: **~20%**
- RBAC delegation (the multi-role vision): **~40%** (built + seeded, not wired)
- Commerce / delivery / orders rooms: **built ~90%** but **hidden** (correct for launch)
- Admin account security (session/token/lockout): **~70%**

### Biggest gaps / risks (ranked)
1. **RBAC is dormant** — cannot deliver "limited admin without code" today (§3). *Risk: strategic, not launch-blocking for a single admin.*
2. **No gateway failover + no in-panel payment health** — Cashfree outage stalls all payments with no admin signal and `secondary=null` (§4-E5). *Risk: revenue outage.*
3. **Admin session security** — 30-day stateless JWT vs 8 h UI, no revocation, no active-session view (§0, §4-E1). *Risk: token theft.*
4. **Last-admin / self-demotion lockout** — no guard (§4-E2). *Risk: total panel lockout.*
5. **New settings default to `is_public=1`** (§4-E7). *Risk: config leak.*
6. **Config that's data-driven but JSON-only** (gateway routing, pilot city, agent thresholds) — no forms (§2). *Risk: founder can't self-serve; needs an engineer for a "data" change.*

### Must-fix **before** launch (small, targeted)
- **M1.** Guard `POST /users/{id}/role` against demoting self / the last admin (§4-E2) — `index.php:1745`.
- **M2.** Default new `app_settings` keys to `is_public=0` (§4-E7) — `SettingsController.php:88`.
- **M3.** Decide gateway-outage posture: either enable the Razorpay secondary **or** add a visible "payments degraded" banner + on-call runbook (§4-E5).
- **M4.** Shorten the admin JWT `exp` (or add revocation) so logout/expiry is real (§4-E1) — `AuthController.php:178`.
- **M5.** Fix the **stale throttle comment** (`index.php:1948`) so future engineers don't "re-add" an already-working feature.

### Fine to defer **post-launch**
- Wire RBAC live (login gate + `requireRole` + JWT role source; the `enforce()` calls are already in place) — the moment you need a second, limited operator.
- Upper-clamp expert-visit amount (§4-E8); optimistic-concurrency on settings/cities (§4-E6).
- A dedicated **Pricing** room and JSON-config **forms** (gateway routing, pilot config, agent thresholds).
- Payment/agent **health tiles** + active-admin-session view on the dashboard.
- Unhide commerce/delivery when the business expands (turn `SERVICE_ONLY_MODE` into a data setting, not a JS constant).

**One-line verdict:** *The admin panel is a real, working, largely data-driven control room that a single founder-admin can run the launch business from today — ship it after the five small guardrails (M1–M5); treat the RBAC delegation vision as a well-prepared, one-change-away post-launch upgrade, not a launch feature.*

---
*Grounded in `wtg-v2` @ 2026-09-02. Read-only audit — nothing modified. Companion to `MAP/system-map.md`. Next: role-02 (vendor), role-03 (customer), role-04 (rider), role-05 (agent) per the 5-role stress-test.*
