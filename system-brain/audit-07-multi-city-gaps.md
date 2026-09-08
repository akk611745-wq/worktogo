# WTG System Audit 07 — Multi-City Readiness Gap List (READ-ONLY)

> **Purpose:** Document every Haldwani / single-city assumption so V2 multi-city expansion can be planned. Final audit phase.
> **Method:** Static grep + read across customer app, vendor panel, admin panel, backend, and schema. No live execution, nothing modified.
> **Cross-refs:** Audit 02 (schema), Audit 03 (booking flow), Audit 06 (register).
> **Date:** 2026-07-03 · READ-ONLY.

**One-line headline:** The system is **single-city by construction, not by config**. There is **no `cities` table and no `city_id` anywhere**; city/area is free-text everywhere; "Haldwani" is hardcoded across ~10 files (JS, PHP, HTML, JSON, DB seed); categories, pricing, and the public pilot config are **global, not per-city**; and there is **no admin UI to add or switch a city**.

---

## 1. Hardcoded city / area / location values

### Customer app (`app/`)
| File | What's hardcoded | Line(s) |
|---|---|---|
| `app/js/config.js` | `SERVICE_ONLY.CITY="Haldwani"`, `ENABLED_CITIES:["Haldwani"]`, `ROUTE_MESSAGE:"…Haldwani service pilot."`, single `SUPPORT_PHONE`/`WHATSAPP_URL`. Object is `Object.freeze`d (compile-time constant). | 87-98 |
| `app/index.php` | SEO/OG meta hardwired to Haldwani ("plumber haldwani, electrician haldwani…", og:title/description), canonical `worktogo.in`. | 66-71 |
| `app/manifest.json` | PWA name/description references Haldwani. | 4 |
| `app/pages/home.js` | Default/example localities **"Mukhani", "Kusumkhera", "Nainital"**, "Haldwani" city fallback, area-suggestion seeds. | 631, 2067-2069, 3000-3015, 3633-3638 |

### Vendor panel (`vendor/`)
| File | What's hardcoded | Line(s) |
|---|---|---|
| `vendor/onboarding.html` | City/Area placeholder "Jaise: Haldwani, Mukhani, Kusumkhera". | 427, 481-482 |
| `vendor/profile.php` | Same Haldwani/Mukhani placeholder text. | 338-339 |

### Admin panel (`admin/`)
| File | What's hardcoded | Line(s) |
|---|---|---|
| `admin/config.js` | `PILOT_CONTROLS.enabled_cities:["Haldwani"]` (+ global `enabled_categories`, `inspection_required_categories`). | 20 |
| `admin/morning.html` | "Haldwani" label in the brief header. | 135 |

### Backend (`heart/`, `body/`, `core/`, `brain/`)
| File | What's hardcoded | Line(s) |
|---|---|---|
| `body/service-engine/api/services/index.php` | `servicePilotConfig()` **fallback** hardcodes `city='Haldwani'`, `enabled_cities:['Haldwani']`, `enabled_categories` (5), hero copy "…in Haldwani", support phone/WhatsApp. | 110-134 |
| `core/controllers/SettingsController.php` | `pilotDefaults.pilot_public_config` hardcodes `city='Haldwani'`, hero title/subtitle, "manually coordinating pilot requests in Haldwani". | 15-25 |
| `heart/index.php` | Haldwani references in public-config assembly. | 347-356 |
| `brain/engine/RuleEngine.php`, `SuggestionEngine.php`, `models/HabitModel.php` | `client_id='haldwani'` / location='haldwani' baked into Brain rules, suggestions, habit scoring. | RuleEngine 35-38; SuggestionEngine 21,163-165; HabitModel 42 |

### Database seed
| Where | Value |
|---|---|
| `app_settings` seed row | `('platform_city', 'Haldwani', 'text', 'Primary city', …)` — a **single global** city setting. |
| `app_settings.pilot_public_config` (JSON) | One blob of Haldwani hero/support copy — not keyed by city. |

> **No hardcoded lat/lng coordinates were found** — geo defaults don't exist in code (the only geo math is Haversine on live `vendors.lat/lng`, see §2).

---

## 2. Data-model single-city assumptions

**There is NO `cities` table and NO `city_id` column anywhere** (grep for `CREATE TABLE cities` / `FROM cities` / `city_id` returns nothing). Location is free text end to end:

| Table | Location fields | Gap |
|---|---|---|
| `users` | none (only `address` text) | No city association at all. |
| `vendors` | `service_localities` (text), `service_area_notes` (text), `lat`/`lng` | No `city_id`; coverage is a comma/newline text list. |
| `bookings` | `customer_locality` (varchar), `customer_address` (text) | **No `city` column** — the booking payload's `selected_city` is dropped (not persisted to a column; at most lands in `operational_timeline` JSON). |
| `addresses` | `city` varchar(100), `state`, `postal_code` (free text) | Free text, no FK to a cities table (shopping-oriented). |
| `categories`, `services` | none | Global catalog — no city scoping. |
| `app_settings` | `platform_city` (single value) | One city for the whole platform. |

**How area matching actually works (single-city-safe only by luck):**
- Live path — `core/helpers/ServiceVendorEligibility::localityCovered()` does **pure substring text matching** (`str_contains` both directions, `*` wildcard) between `vendors.service_localities` and `bookings.customer_locality`, **with no city dimension**. A locality named "Mukhani" would match regardless of which city it's in — cross-city false matches are possible once >1 city exists.
- Geo path — `cron/auto_assign.php` uses Haversine distance on `vendors.lat/lng` vs the address lat/lng (city-agnostic, would actually scale), **but it's dead for bookings** (Audit 03 Broken Link #2). So the effective live matching is the text path, which has no city boundary.
- `admin/dashboard.html` "Area Demand" groups bookings by **area text** — same-named areas in different cities would merge into one row.

**Net:** every location relationship (customer→area, vendor→coverage, booking→locality, demand analytics) is unscoped free text. Adding a second city risks cross-city mismatches with zero schema-level guardrails.

---

## 3. Global config that must become per-city in V2

| Thing | Today (global) | Where | V2 need |
|---|---|---|---|
| **Enabled categories** | one array of 5 (`electrician, plumber, painting, waterproofing, cctv`) applied everywhere | `app/js/config.js`, `admin/config.js`, `servicePilotConfig()` | Per-city category availability. |
| **Inspection-required categories** | global list (`painting, waterproofing, cctv`) | same three places | Per-city (a category may need inspection in one city, not another). |
| **Inspection price (₹299)** | global (`SERVICE_ONLY.INSPECTION_PRICE`, `inspection_price` setting; `categories.inspection_price` is per-category but not per-city) | config + `app_settings` + `categories` | Per-city (or per city×category) pricing. |
| **Public pilot config** (hero copy, support phone, WhatsApp) | one JSON blob | `SettingsController.pilotDefaults`, `app_settings.pilot_public_config` | Per-city hero/support/contact. |
| **Support phone / WhatsApp** | single number `+91 95285 44548` | config.js, SettingsController, servicePilotConfig | Per-city ops contact. |
| **Commission rate** | per-category / per-vendor / 10% default (Audit 05) | `categories.commission_rate`, `vendors.commission_rate` | Likely per-city category economics. |
| **Service catalogue** (`services`, `categories`) | global rows, no city column | schema | City-scoped catalogue or city×category join. |
| **Brain client** | `client_id='haldwani'` baked into rules/suggestions/habits | `brain/engine/*`, `brain/models/HabitModel.php` | Per-city Brain client/tenant. |

---

## 4. What an admin "city switch" would require that doesn't exist today

There is **no add-city or switch-city capability** anywhere. To change the city today you must hand-edit and redeploy:
1. `app/js/config.js` (frozen constant) — CITY, ENABLED_CITIES, support/WhatsApp, route message.
2. `admin/config.js` — PILOT_CONTROLS.enabled_cities.
3. `body/service-engine/api/services/index.php` — `servicePilotConfig()` fallback.
4. `core/controllers/SettingsController.php` — pilotDefaults hero/support copy.
5. `app/index.php` + `app/manifest.json` — SEO/PWA strings.
6. `brain/engine/*` + `HabitModel.php` — `client_id='haldwani'`.
7. `app_settings` DB rows — `platform_city`, `pilot_public_config`.

**Missing primitives for a real city switch:**
- No `cities` table / city registry; no `city_id` on `users`, `vendors`, `bookings`, `services`, `categories`.
- No per-city config store (the pilot config is one global JSON blob, not city-keyed).
- No admin CRUD for cities, per-city categories, per-city pricing, or per-city support contacts.
- No city-scoped filtering in admin lists (Dashboard, Follow-up, Services, Vendors all query platform-wide; there's no "viewing city = X" selector).
- No city boundary in vendor↔booking matching (text `localityCovered` is city-blind — §2).
- No data partition / tenancy: all vendors, bookings, categories live in one undivided pool.

---

## 5. V2 Multi-City Readiness Checklist

**Schema / data model**
- [ ] Add a `cities` table (id, name, slug, state, center lat/lng, bounding box/pincode set, status).
- [ ] Add `city_id` FK to `vendors`, `bookings`, `services` (and optionally `users` home city); backfill Haldwani.
- [ ] Persist the booking's `selected_city` to a real `bookings.city_id` (currently dropped).
- [ ] Replace free-text `service_localities` matching with city-scoped areas (localities belonging to a city).

**Config**
- [ ] Move enabled categories, inspection-required categories, inspection price, hero/support copy, and support/WhatsApp contacts into a **per-city** config store (not the single `pilot_public_config` blob / frozen JS constant).
- [ ] Make `platform_city` a selection over the cities table, not a single string.
- [ ] Per-city Brain `client_id` (retire the hardcoded `'haldwani'`).

**Matching / eligibility**
- [ ] Scope `ServiceVendorEligibility::localityCovered()` by `city_id` before locality text match (prevent cross-city false matches).
- [ ] Decide geo vs text matching (the Haversine cron path would scale but is currently dead — Audit 03).

**Admin**
- [ ] Cities CRUD; per-city category/pricing/contact management.
- [ ] A **city selector** on admin Dashboard / Follow-up / Services / Vendors that filters every list by `city_id`.
- [ ] City-scoped Area Intelligence / Vendor Scorecard (today they merge same-named areas across cities).

**Front-end / SEO**
- [ ] De-hardcode `app/index.php`, `manifest.json`, and `home.js` locality seeds; drive city + example areas from API/config.
- [ ] Per-city SEO/OG metadata and canonical URLs.

**Cleanup dependencies (from Audit 06, gate multi-city)**
- [ ] Fix the assignment→acceptance chain (B2/H1) and Cashfree prod failure (B1) first — multi-city multiplies these blockers across markets.

---

## 6. Verdict
WorkToGo is **hard-wired to Haldwani**, not configured for it. "Haldwani" appears as a literal in ~10 code files across all four surfaces plus a DB seed, and — more fundamentally — **the data model has no concept of a city at all** (no `cities` table, no `city_id`, location is free text, and even the booking's chosen city isn't stored). Categories, inspection rules, pricing, support contacts, and the Brain client are all global singletons. A second city today is a **schema project, not a config change**: it needs a cities/city_id foundation, per-city config + catalogue + pricing, city-scoped matching and admin filtering, and an admin city-management UI — none of which exist. This is the largest structural (as opposed to bug) gap in the system, and it compounds the still-open assignment/payment blockers from Audit 06.

---
*Final audit phase. Static read-only pass; no files modified.*
