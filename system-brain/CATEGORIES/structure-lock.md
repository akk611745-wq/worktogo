# WorkToGo — Service Structure Lock & Fit-Check (the engineering half)

> **What this is:** The **fit-check of the founder's whole-service-industry vision against the REAL, built `wtg-v2` code.** It answers the one question the founder actually cares about: *does our service engine already carry every category shape by admin config (zero code), or are there gaps — and exactly where?* It then **locks the structure** so any future category plugs in the same way, and lists the honest 2–3% the system does not yet do.
> **Companion:** `CATEGORIES/category-research.md` is the business half (which categories, what demand, what shape). This doc is the code truth behind it.
> **Grounded in** actual files, cited inline: the `packages` / `expert_visit_pricing` / `bookings` migrations, `body/service-engine/api/services/index.php`, `core/helpers/ExpertVisitPricing.php`, `heart/api/admin/index.php`, `config/engines.php`, `heart/router.php`. **READ-ONLY — nothing built, edited, or deployed.**
> **Date:** 2026-09-08.

---

## 1. What is actually built (verified in code)

Every claim below was read in the real repo, not the vision doc. This is the ground the fit-check stands on.

| Building block | Real artifact | What it gives a category |
|---|---|---|
| **Category taxonomy** | `categories` table (`id, parent_id, name, slug, type, status, image_url, module, is_active, sort_order`; cols added by `migrations/2026_04_27_004`). Admin CRUD: create (`heart/api/admin/index.php:650`), update, banner upload. 5 seeded: Electrician, Plumber, Painting, Waterproofing, CCTV (`migrations/2026_06_14_001`). | A category is a **data row**, created from the admin panel. Nested (`parent_id`) and per-module ready. |
| **Vendor ↔ category** | `vendor_categories` M:N junction = **canonical** source of truth (`migrations/2026_07_04_002` unifies the old 3-way mess). | Unambiguous "which categories a karigar serves." Multi-category vendors work. |
| **Fixed price** | `services` table, `base_price` (`0.00` = call-for-quote), `duration_minutes` (`migrations/2026_06_14_001`, `2026_04_27_002`). Vendor/admin owns the number. | **Fixed pricing state.** "AC service ₹499." |
| **Packages** | `packages` table (`migrations/2026_07_04_005`): per `category_id`, nullable `city_id`, `rate_per_sqft` **OR** `base_price` **OR** `inspection_fee`, `min_sqft`, `features` (JSON), `cover_media_id`. Full admin CRUD `GET/POST /api/admin/categories/{id}/packages` via `adminBuildPackageFields()` (`heart/api/admin/index.php:25, 986–1033`) — explicitly *"data-driven, zero hardcoding."* | **Package-per-sqft** and **flat-package** pricing states. Standard/Premium/Luxury, any names, per city. |
| **Structured package selection** | `bookings.package_id / package_name (snapshot) / area_sqft / estimate_amount` (`migrations/2026_07_04_006`). Estimate is **server-recomputed**, not client-trusted. | Live sqft estimate persists as structured, reportable columns. |
| **Expert-visit pricing** | `expert_visit_pricing` table (`migrations/2026_07_04_007`) + `app_settings.expert_visit_fee/free` global default. Precedence resolver `core/helpers/ExpertVisitPricing.php` (`category+city > category > city > global`), `is_free` flag. Admin CRUD (`heart/api/admin/index.php:1194–1210`). | **Inspection-required pricing state**, free-or-paid **per area × per category** — exactly the V2 vision. |
| **Expert-visit booking** | `bookings.visit_type / expert_visit_fee / expert_visit_free / final_quote_amount` (`migrations/2026_07_04_008`). Runs on the existing pay-to-confirm path as `booking_mode='inspection'`. | The visit → quote loop, with estimate-vs-actual (`final_quote_amount`). |
| **Routes / dispatch** | `bookings.booking_mode` ∈ `direct_vendor` \| `inspection` \| `free_lead`/`free_request` \| `paid_inspection`; `bookings.vendor_route` ∈ `direct_vendor` \| `admin_queue` \| `admin_assigned` (`body/service-engine/api/services/index.php:1490–1509, 2050–2261`). | **Quick/Direct, Expert-visit, Managed/Company** routes all exist as data. |
| **Money accounting** | `LedgerEngine` reads `booking_mode/visit_type/expert_visit_fee` for fee accounting (`core/helpers/LedgerEngine.php:64–92, 252`). | Visit/inspection fees flow to the ledger. |
| **Multi-city** | `cities` table + `city_id` foundation (`migrations/2026_07_04_001`); packages & expert-visit pricing already carry nullable `city_id`. | Per-city category enablement + per-city pricing later. |
| **Engine plug-seam** | `config/engines.php` registry + `heart/router.php` intent map (`service:*` intents at lines 211–216). | New *engines* (not categories) plug in via one registry line + routes. |

**Bottom line of §1:** four of the five pricing states (**fixed, package-per-sqft, estimate, inspection-required**) and three routes (**direct, expert-visit, managed**) are **built and admin-configurable today**. The category menu in the research doc is mostly *already carryable with zero code*.

---

## 2. The fit-check — does every category shape fit zero-code?

For each pricing state and route, the honest verdict: **✅ zero-code** (admin config only) · **🟡 partial** (works but with a caveat/limitation) · **❌ gap** (needs schema + code).

### 2.1 Pricing states

| Pricing state | Fits zero-code? | Where it's configured | Verdict |
|---|---|---|---|
| **Fixed** ("AC ₹499") | ✅ Yes | `services.base_price` (vendor/admin) | Set a number. Done. |
| **Package per-unit** ("₹18/sqft") | 🟡 **sqft only** | `packages.rate_per_sqft × area_sqft` | Works for anything measured in **square feet** (painting, waterproofing, tiling, flooring). **Not** per-hour, per-point (wiring), per-panel (CCTV), per-AC-unit, per-seat (sofa). The field is literally named `rate_per_sqft` and the booking captures `area_sqft`. See §3-GAP-2. |
| **Estimate / range** | 🟡 **single value, no range** | `estimate_amount` / `final_quote_amount` | A single estimate and a single final quote exist. A **structured min–max range** ("₹2,000–₹4,000") shown *before* a visit is not a field — today it's package copy or a features string. Minor. See §3-GAP-3. |
| **Inspection-required** | ✅ Yes | `expert_visit_pricing` (+ resolver), `is_free` per area×category | The strongest-built piece. Free/paid, per scope, precedence-resolved. |
| **Hourly / gig** ("₹60/hour") | ❌ **No** | *nowhere* | No `rate_per_hour`, no `hours`/`units`/`quantity` on a booking, no time-metered billing. Cannot be configured; needs schema + pricing code + a customer input. **The one true structural gap.** See §3-GAP-1. |

### 2.2 Routes

| Route | Fits zero-code? | Backed by | Verdict |
|---|---|---|---|
| **Quick / Direct** | ✅ Yes | `booking_mode=direct_vendor` (the only fully-working E2E flow) | Fully built. |
| **Expert Visit** | ✅ Yes | `inspection` + `visit_type=expert_visit` + `final_quote_amount` | Built (pay-to-confirm live). |
| **Managed / Company** | ✅ Yes | `free_lead` → `admin_queue` → `admin_assigned` | Built; general request carries no forced payment. |
| **Package (surface)** | ✅ Yes | `packages` + booking package columns | Built (sqft caveat as above). |
| **Project / Quote** | 🟡 **via expert-visit only** | expert_visit → `final_quote_amount` | The *inspect-then-quote* half is built. Missing: a **structured multi-milestone / progress-payment / customer-accept-quote** flow for big scoped jobs (full wiring, packers, solar). Runs today as one visit + one final number. See §3-GAP-4. |

### 2.3 The "any category plugs in via admin config" promise — verdict

**Confirmed for the launch menu, with two named exceptions.** A new category that is **fixed, package-per-sqft, expert-visit, general-lead, or direct-vendor** is created entirely from the admin panel — new `categories` row, its `packages`, its `expert_visit_pricing` — **zero code, zero deploy.** That is a real, verified promise for ~17 of the 20 researched categories.

The promise **breaks** for exactly two shapes:
1. **Hourly/gig** categories (maid-by-hour, true hourly cleaning/handyman) — no field exists.
2. **Non-sqft per-unit** categories priced cleanly per hour/point/panel/unit/seat — the per-unit field is sqft-hardwired.

Everything else is config.

---

## 3. The gaps, precisely (each with where it'd be fixed)

> These are stated as the founder asked: *exactly what's missing and where it would be configured or coded.* Cited to real files so a future session can act without re-deriving.

### GAP-1 — Hourly / gig pricing (the only true structural gap) ❌
- **Symptom:** cannot price or book "₹60/hour × 3 hrs." No `rate_per_hour`, no hours/quantity on `bookings`.
- **Where it'd be fixed:** new migration adding `packages.rate_per_hour` (or, better, generalise — see GAP-2) + `bookings.units` / `bookings.unit_type`; extend the server-side estimate recompute (the same path that computes `estimate_amount` from `rate_per_sqft × area_sqft` in the package read/booking write, `body/service-engine/api/services/index.php` + `heart/api/admin/index.php` package fields) to handle `rate × units`; add a "hours/units" input on the customer surface.
- **Effort:** schema + pricing-code + one UI input. Small-to-medium, but **it is code, not config.**
- **Launch workaround:** sell hourly categories as **fixed packages** ("2BHK deep clean ₹1,499"). Defer true hourly to post-launch.

### GAP-2 — Per-unit pricing is square-feet-only, not generic 🟡
- **Symptom:** `packages.rate_per_sqft` + `bookings.area_sqft` only model **square feet**. Categories that price per **point** (wiring), **panel/camera** (CCTV), **AC unit**, **running foot** (modular), or **seat** (sofa cleaning) either misuse the sqft field (wrong label to the customer) or fall back to flat price.
- **Where it'd be fixed:** generalise to a `(unit_label, rate_per_unit)` pair — e.g. add `packages.unit_label VARCHAR` + rename/parallel `rate_per_unit`, and `bookings.units` + `bookings.unit_label`. This **also solves GAP-1** (hours are just another unit). Same estimate-recompute path.
- **Why it matters:** this is the difference between "per-unit pricing" (what the vision implies) and "per-sqft pricing" (what's built). One generalising migration makes per-unit truly zero-code for every future category.
- **Effort:** small (additive migration + generalise one formula). **Highest-leverage single fix** — do this and hourly + all per-unit categories become config.

### GAP-3 — Estimate *range* is a single value, not min–max 🟡 (minor)
- **Symptom:** customer can't be shown a structured "₹2,000–₹4,000" band before a visit; only one `estimate_amount`.
- **Where it'd be fixed:** either `packages.est_min/est_max` columns, or (cheapest) treat it as **copy** in the package `description`/`features` for launch. Not a blocker.

### GAP-4 — Project/quote has no milestone / progress-payment / accept-quote flow 🟡
- **Symptom:** big scoped jobs (full wiring, packers, solar) run as one expert visit + one `final_quote_amount`. No structured customer "accept this quote," no milestone stages, no staged payments.
- **Where it'd be fixed:** V2-VISION §6 Engine 5 ("Company Projects") is **⛔ MISSING — no file/route/table** per the vision's own audit. This is the net-new module to build for true project management. For launch, the expert-visit + final-quote path is an honest MVP.

### GAP-5 — Category "business-unit" ownership layer is not modelled 🟡
- **Symptom:** categories cluster their data (packages, visit-pricing, vendors, bookings all keyed by `category_id`) but there is **no category-head, no per-category staff RBAC scope, no per-category P&L rollup surface.** Per-category P&L is a V2-VISION §6.1 *target*, not built.
- **Where it'd be fixed:** see §4 (the recommended business-unit model). Additive — a `category_head_user_id` + RBAC scope + an admin per-category dashboard view.

> **No gap requires touching the money path or the never-touch commerce/video engines.** GAP-1/2/3/5 are additive migrations + config UI. GAP-4 is a net-new managed-projects module. None is a launch blocker for the Wave-1 menu.

---

## 4. Category = business-unit — recommended model

The founder wants each category to be a **self-contained, manageable unit with a category-head later.** Here is how to organise that **without breaking the zero-code, one-engine promise.**

### 4.1 The core principle (lock this)
> **A category is DATA inside the one service engine — never a new engine, never a `body/<x>-engine/` folder.** The naming rule (`MAP/naming-and-structure.md §2`) reserves engine folders for fundamentally different business *shapes* (tuition, rental). A service category (painting, CCTV) is config: a row + its packages + its visit-pricing. This is exactly what makes "plug in a new category with zero code" true — do not undermine it by giving categories folders.

### 4.2 What already makes a category self-contained (today)
Everything domain-specific is **keyed by `category_id`**: `packages.category_id`, `expert_visit_pricing.category_id`, `vendor_categories.category_id`, `bookings.category_id`, `services.category_id`. So a **"Painting business-unit"** is a *filtered view*, assemblable now — its packages, its karigars, its visit config, its bookings, its revenue.

### 4.3 What to ADD for true business-units (additive, post-launch)
| Piece | What | Where |
|---|---|---|
| **Category head** | `categories.head_user_id` (nullable FK → users) — the owner of that unit. | new migration |
| **Per-category RBAC scope** | extend `core/helpers/Permission.php` so a head/staff can be scoped to their `category_id`(s) — they manage only their unit. | RBAC layer (already exists for admin roles) |
| **Per-category dashboard** | an admin view filtered by `category_id`: demand, vendors, bookings, **P&L** (the V2-VISION §6.1 per-category profitability). | admin panel (new view over existing data) |
| **Category config bundle** | treat "a category" as the tuple **{category row + its packages + its expert-visit pricing + its enabled cities}** — one admin screen to manage a unit end-to-end. | admin panel (compose existing CRUD endpoints) |

### 4.4 Naming (per `naming-and-structure.md`)
- Category **slug** = one lowercase noun (`painting`, `waterproofing`, `cctv`), used identically as the `categories.slug`, in package/visit config, and in any admin URL (`admin/category-<slug>.html` if a per-category screen is built).
- **Docs** for a category (blueprints) live under `system-brain/CATEGORIES/` (e.g. `CATEGORIES/painting.md`), **not** a new top-level folder.
- Do **not** invent `body/painting-engine/` — that would violate the lock. Categories are never engines.

---

## 5. THE STRUCTURE LOCK (the contract any new category obeys)

> Freeze this. Any future category — the 21st or the 200th — is defined by filling in this contract. If a category fits it, it plugs in **zero-code**. If it needs a shape outside it, that shape is a platform change (GAP-1/2/4), made once, then every future category inherits it.

**To add a category (zero-code path):**
1. **Create the category row** — admin panel → `categories` (name, slug, parent, banner, cities enabled).
2. **Assign karigars** — `vendor_categories` (the canonical junction).
3. **Choose the pricing state(s)** from the built set:
   - Fixed → `services.base_price`.
   - Package flat → `packages.base_price`.
   - Package per-sqft → `packages.rate_per_sqft` (+ `min_sqft`).
   - Inspection → `expert_visit_pricing` (free/paid per area×category).
4. **Set the route(s)** the customer may use — Direct / Expert-visit / Managed — (data-driven via `booking_mode`; no code).
5. **Write the simple customer line** — a number, a rate, or "expert aayega."

**That's the whole contract.** Steps 1–5 are all admin config against tables that already exist.

**A category needs code ONLY if it requires:**
- **Hourly / true per-unit billing** (GAP-1/GAP-2) → do the one generalising migration, then it's config forever.
- **Structured project milestones / staged payments** (GAP-4) → build the Company Projects module once.
- **Its own business-unit ownership** (GAP-5) → add head + RBAC scope once.

Nothing else about a new category is ever code.

---

## 6. The honest 2–3% — what the system does NOT yet do

Per the founder's expectation, stated plainly, sorted **config vs code vs staging** so it's known before launch:

| # | Gap | Type | Launch impact |
|---|---|---|---|
| 1 | **Hourly / gig pricing** (maid-by-hour, true hourly) | **Code** (schema + pricing + UI) | None if hourly categories launch as fixed packages / deferred (Wave-3). |
| 2 | **Per-unit pricing is sqft-only** (per-point/panel/unit/seat) | **Code** (one generalising migration — solves #1 too) | Low — Wave-1 categories are fixed or sqft; affects Wave-2 CCTV-per-camera niceness only. |
| 3 | **Estimate shown as single value, no min–max range** | **Config/copy** now; code later | None — use package copy. |
| 4 | **Project/quote has no milestone/staged-payment/accept-quote** (Company Projects, V2-VISION §6 Engine 5 = MISSING) | **Code** (net-new module) | None for Wave-1; blocks packers/solar/full-project management (Wave-3). |
| 5 | **Category business-unit ownership** (head, per-category RBAC, P&L rollup) | **Code** (additive) | None for launch; needed when a category-head is appointed. |
| 6 | **Per-area (sub-city) pricing granularity** — visit-pricing is per **city×category**; "per-area" (locality within a city) from V2-VISION §2.2 is city-level today | **Code** (add area scoping) | Low — city-level is fine for one-city Haldwani launch. |
| 7 | **V2 foundation blockers still open** (V2-VISION §9 Step 0: Cashfree prod order B1, admin-assign `jobs.vendor_id` B2, settlement wiring) — these gate *paid* expert-visit + managed dispatch, independent of categories | **Code/staging** (already tracked in audits + CHANGELOG) | **Real** for paid Company-Service; Quick/Direct fixed-price (Wave-1) is unaffected. Validate on staging. |

> **Reading of the 2–3%:** the *category structure* is ~97% there — the taxonomy, packages, expert-visit, routes, and multi-city foundation are built and admin-driven. The missing few percent is **one pricing generalisation (hourly/per-unit)**, **one net-new module (project milestones)**, **one org layer (category-head)**, plus **the already-documented V2 foundation fixes** that gate paid flows. None of it blocks a small Wave-1 launch of fixed-price direct categories + the painting/waterproofing showcase.

---

## 7. Structural findings (the CTO's headline)

1. **The engine is category-agnostic by construction.** Categories are data keyed by `category_id`; packages, expert-visit pricing, vendors, and bookings all hang off it. The "any category plugs in via admin config" promise is **verified true** for the fixed / package-sqft / expert-visit / managed / direct shapes — ~17 of 20 researched categories, zero code.
2. **The single highest-leverage fix is generalising per-sqft → per-unit** (GAP-2). It is a small additive migration and it *simultaneously* unlocks hourly/gig (GAP-1) and every non-sqft category. Do this one thing and the zero-code promise becomes near-total.
3. **"Category = business-unit" is a data view, not a folder.** Lock that a category never becomes an engine. The org/ownership layer (category-head, per-category P&L) is additive and post-launch.
4. **Launch is honestly small and safe:** ~7 direct fixed-price categories + painting/waterproofing as the Company-Service showcase. The paid-flow foundation blockers (V2-VISION §9) must be cleared on staging before the *paid* expert-visit path is trusted; the *free* direct path is ready.

*Companion to `CATEGORIES/category-research.md`. Fit-check grounded in real `wtg-v2` code. Documentation only — no code changed, nothing deployed.*
