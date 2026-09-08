# WorkToGo Folder Intelligence — Phase 01

Generated: 2026-05-12T03:44:00Z

---

## 1. Full Folder Structure Snapshot Summary

The project contains these major runtime zones under `wtg  live/`:

```text
wtg  live/
├─ admin/                  Admin browser panel
├─ api/                    Direct specialized APIs: alerts, assignments, payment
├─ app/                    Customer SPA/mobile-like web app
├─ assets/                 Shared public browser assets
├─ body/                   Business engines
│  ├─ content-engine/
│  ├─ service-engine/
│  ├─ shopping-engine/
│  └─ video-engine/
├─ brain/                  Intelligence/rules/events/dashboard subsystem
├─ config/                 Backend configuration
├─ core/                   Shared helpers/controllers
├─ cron/                   Scheduled jobs
├─ docs/                   Existing documentation
├─ heart/                  Gateway/orchestration layer
├─ lib/                    Payment library classes
├─ migrations/             SQL migration files
├─ plans/                  Planning documents
└─ vendor/                 Vendor browser panel
```

---

## 2. Folder Responsibility Details

| Folder | Purpose | Connected Modules | Runtime Role | Scale Risk | Maintainability Risk | Future Impact |
|---|---|---|---|---|---|---|
| `admin/` | Admin UI and shared admin JS | Heart admin/auth APIs | Operational browser UI | Medium | Medium-high due many static pages | Needs API contract map |
| `api/alerts/` | Alert creation/fetch/cleanup/seen | DB, AuthMiddleware, frontend alerts JS | Notifications/ops alerts | Medium | Medium: uses `DOCUMENT_ROOT` includes | Standardize include paths later |
| `api/payment/` | Cashfree create/status/return/webhook | `lib`, `core`, auth, orders/transactions | Payment edge API | High | High: payment duplicate layers | Needs lifecycle trace |
| `api/assignments/` | Assignment acceptance | DB, AuthMiddleware | Delivery/service assignment response | Medium | Medium | Needs relation to cron/auto_assign |
| `app/` | Customer-facing SPA | Heart auth + pipeline | Primary customer UI | Medium | Medium: globals/script order | Needs endpoint/intent alignment |
| `body/content-engine/` | Stories/content feed | Heart direct story routes, DB, auth | Content feature | Medium | Medium | May become first-class engine |
| `body/service-engine/` | Services, bookings, vendors/jobs, availability | Heart REST/pipeline, Payment, Auth | Service marketplace | High | High: URI mutation internal dispatch | Booking contract should be traced |
| `body/shopping-engine/` | Products, carts, orders, vendor shopping analytics | Heart REST/pipeline, Payment, Delivery, Auth | Commerce marketplace | High | High: side effects and workflow complexity | Order/payment/delivery lifecycle critical |
| `body/video-engine/` | Placeholder video feed/upload/like | Config + commented Heart routes | Dormant feature | Low now | Medium if activated without design | Activation needs clear module boundary |
| `brain/` | Decisions/events/feedback/rules/dashboard/feed ranking | Heart BrainConnector, feed route, DB | Intelligence layer | Medium | Medium: custom autoload/router | Next phase should inspect controller/model contracts |
| `config/` | App/db/engines/payment configs | Heart/Core/Lib | Global runtime configuration | Medium | Medium: duplicated env load | Should be single source later |
| `core/` | Shared platform helpers and controllers | All backend | Foundation primitives | High | High: global constants/static helpers | Must be versioned as internal API |
| `cron/` | Auto assignment job | DB, assignment tables | Background operations | Medium | Medium: table/key assumptions | Needs job schedule/deployment map |
| `heart/` | Gateway, auth/admin/search/delivery APIs, pipeline | Everything | Core runtime orchestrator | High | High: central monolith | Refactor target later, not now |
| `lib/` | Cashfree client/payment service/webhook verifier | Payment APIs, orders | Payment service library | High | Medium-high | Needs unification with core helper later |
| `migrations/` | SQL database changes | DB schema | Deployment data evolution | Medium | Medium: no migration runner map yet | Need schema ownership map |
| `vendor/` | Vendor UI | Heart auth + pipeline | Vendor operations | Medium-high | High: intent drift | Needs vendor contract audit |

---

## 3. Dangerous Folder Structures

| Structure | Why dangerous | Evidence / Observation | Impact |
|---|---|---|---|
| Sensitive backend folders under public tree | Requires perfect `.htaccess` to protect secrets/libs/docs | `core`, `config`, `brain`, `lib`, `.env`, SQL all inside webroot-like folder | Host/rewrite misconfig can expose internals |
| Multiple API roots | `heart/api`, root `api`, `body/*/api`, `brain/routes` | Different routing conventions and auth expectations | Endpoint discoverability and ownership unclear |
| Monolithic admin backend file | `heart/api/admin/index.php` has many route blocks and feature-table queries | Admin owns cross-platform operations directly | Hard to extend safely |
| Frontend panels as separate stacks | `app`, `admin`, `vendor` each has config/auth/api | Logic duplicated, token keys differ, endpoint building differs | Inconsistent behavior across roles |
| Business engines contain direct API and internal dispatch | Body folders support both direct REST and Heart internal include | Same code can run with different globals/methods | Hidden runtime modes |
| Payment logic split | `api/payment`, `lib`, `core/helpers/Payment.php`, order/service/refund code | No obvious single owner | Payment bugs may be fixed in one path only |

---

## 4. Unclear Ownership Zones

| Zone | Unclear Ownership | Reason |
|---|---|---|
| Delivery | Heart delivery API, admin deliveries, SwiftDeliver external, order dispatch, payment webhook dispatch | No dedicated `body/delivery-engine/` found |
| Vendor operations | Vendor frontend intents, Heart AppRouter vendor routes, shopping/service vendor APIs, admin vendor approval | Crosses app/admin/body/heart |
| Payments/refunds/ledger | Core helper, lib service, payment API, order service, refund controller, ledger engine | Multi-owner financial workflow |
| Search/feed/content | Heart search, Brain feed, content stories, video placeholder | User discovery split across multiple systems |
| Settings/config | `config/*.php`, DB `app_settings`, `system_config`, `core/controllers/SettingsController` | File config and DB config both exist |

---

## 5. Legacy / Dead Structure Signals

| Signal | Location | Interpretation |
|---|---|---|
| Fix/audit reports in root | `FIXES_APPLIED.md`, `FIX_VERIFICATION_REPORT.md`, `audit-report.md`, `ROOT_CAUSE_AUDIT.md` | Project has prior emergency fixes; operational docs live beside runtime |
| Test HTML in admin | `admin/test-auth-flow.html`, `admin/test-url-fix.html` | Production folder includes test tools |
| Video routes commented out | `heart/router.php` comments + video engine placeholder | Video not active despite route/config presence |
| `brain/scripts/NightlyJob.php` references missing env file | `brain/config/env.php` not in snapshot | Cron script may be stale |
| `app/index.html` includes PHP echo | Static file likely cannot run PHP | Duplicate app entry ambiguity |
| Product/merchant/vendor schema mix | Admin products query `merchants`; body engine likely vendor-based | Legacy commerce model remnants likely |

---

## 6. Recommended Folder Governance Targets

1. Keep `WTG-SYSTEM-BRAIN/` as append-only intelligence store.
2. In a later phase, define API ownership matrix: Heart-native vs body-native vs root API vs Brain.
3. In a later phase, define “public static” vs “private backend” deployment split.
4. In a later phase, map all production-facing test/debug files and decide retention/deployment rules.
5. In a later phase, document database table ownership by folder/module.

