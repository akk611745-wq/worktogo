# WorkToGo

**WorkToGo** is a multi-service local-commerce platform for India (launching in Haldwani). One app, one login, one payment layer — and **pluggable "engines"** for each business (services today; commerce, delivery, and video ready behind flags). A natural-language **AI agent ("dost")** sits in front so customers and vendors can just *ask*.

> **This repo is the real, current system.** (Historically the working branch was called `v2`; that's a version tag, not the product's name — the product is WorkToGo.)

---

## The architecture in one metaphor (learn this first)

The folder names *are* the architecture — a **body**:

| Part | Folder | What it is |
|---|---|---|
| ❤️ **Heart** | `heart/` | The front door. **Every request is one `POST {intent}`** (e.g. `service:create_booking`). The Heart validates it, figures out who/where, and routes it to the right engine. |
| 🫀 **Body** | `body/` | The **engines** — the actual businesses. One subfolder each: `service-engine/` (live), `shopping-engine/` (commerce), `video-engine/`, `content-engine/`. |
| 🧠 **Brain** | `brain/` | Optional personalization/ranking. Circuit-broken — the app runs fine without it. |
| 🤖 **Agent** | `core/agent/` | The AI assistant. Turns text/voice into intents across engines. A *translator* only — it never owns data, every write is confirm-gated, and a privacy wall scrubs PII. |
| 🔩 **Motherboard** | `core/` + `lib/` + `config/` | The shared foundation every engine plugs into: identity/JWT, city, media, permissions, ledger, and the payment layer. **Built once, reused by every engine.** |

**Request flow:** `app / vendor / admin` → `POST {intent}` → **Heart** (`heart/router.php` maps intent → engine) → **Engine** (in `body/`) → **Motherboard** (`core/`, `lib/`) → **MySQL/MariaDB**.

```
heart/     ← API gateway (router, pipeline, per-surface API endpoints)
body/      ← the engines (service | shopping | video | content)
core/      ← platform helpers (JWT, City, Media, Permission, Ledger) + agent/ + delivery/
lib/       ← self-contained service libraries: payment/ (multi-gateway), auth/ (OTP channels)
brain/     ← optional personalization
app/       ← customer web app (PWA)
vendor/    ← vendor panel
admin/     ← admin control room
rider/     ← rider app (delivery, dormant at launch)
api/       ← direct edges that bypass the intent pipeline (payment webhook, alerts, assignments)
config/    ← app/DB/payment/auth/engines config  (engines.php = the pluggable registry)
migrations/← every dated DB change, run in order by migrate.php
cron/      ← scheduled jobs (auto_assign, delivery_lifecycle, agent_*)
uploads/   ← user-uploaded media (runtime; git-ignored)
```

## Where do I fix X? (the shortcut)
| Problem | Go to |
|---|---|
| 💳 Payment | `lib/payment/` + `lib/PaymentService.php`; webhook `api/payment/webhook.php` (sole authority) |
| 🔑 Login / OTP | `lib/auth/` + `heart/api/auth/` |
| 📅 Booking | `body/service-engine/api/services/index.php`; customer `app/pages/home.js` |
| 🧑‍🔧 Vendor assignment | `heart/api/admin/index.php` + `cron/auto_assign.php` + `api/assignments/accept.php` |
| 🔔 Notifications | `core/lib/FcmNotifier.php` (in-app + push) + `api/alerts/` (auto-assign alerts) |
| 🤖 AI safety | `core/agent/PiiWall.php` + `core/agent/AgentOrchestrator.php` |
| 🚦 Routing / new engine | `heart/router.php` + `config/engines.php` |
| 🗄️ Schema change | add a dated file in `migrations/`, run `php migrate.php` |

*(Full "where do I fix X" table: see the System Map in the docs library.)*

## Adding a new engine (the founder's "plug it in" vision)
Pick **one lowercase noun** (e.g. `tuition`) and use it everywhere: `body/tuition-engine/`, registry key `tuition` in `config/engines.php`, intents `tuition:book_class`, env `ENGINE_TUITION_URL`, tables `tuition_*` (with `city_id`). Reuse identity, payment, city, media from the Motherboard — never rebuild them. Full rule: the Naming & Structure guide in the docs.

---

## Running it

**Requirements:** PHP 8.1+ (with `pdo_mysql`, `curl`, `openssl`), MySQL/MariaDB, a web server pointing at the repo root (`index.php` → `heart/`).

1. **Configure:** `cp .env.example .env` and fill in the values. `.env.example` documents every key; `REQUIRED`-marked ones are the minimum. **Never commit `.env`.**
2. **Database:** back up first, then run migrations:
   ```bash
   php migrate.php "$MIGRATE_SECRET"     # applies migrations/ in order, tracked in migrations_log (idempotent)
   ```
3. **Crons** (schedule these — assignment & reminders depend on them):
   `cron/auto_assign.php`, `cron/delivery_lifecycle.php`, `cron/agent_reminders.php`, `cron/agent_demand_detect.php`.
4. **Flags:** `SERVICE_ONLY_MODE` on (commerce/delivery/video hidden); agent kill-switch OFF until validated.

## Branches (world-class model)
- **`main`** — production (protected). · **`staging`** — pre-prod mirror (protected). · **`feature/*` / `fix/*`** — short-lived, merged via PR → `staging` → `main`.
- Deploy = server `git pull` a tag. Rollback = `git revert <commit>` + redeploy, or restore the pre-migration DB backup. Never edit code on servers — the repo is the source of truth.

## Safety rules (do not break)
- **Never-touch (read-only, use clean adapters):** `body/shopping-engine/`, `body/video-engine/`, `app/pages/orders.js`.
- **Money is server-authoritative:** amounts come from the DB, the **webhook is the sole payment authority** (return pages never self-authorize), writes are idempotent. Never call a gateway directly — go through `lib/PaymentService.php`.
- **One shared identity, one payment layer, one media table, `city_id` on every domain table.**

## Documentation
A large, current documentation library ("the System Brain") lives alongside this repo — start with the **System Map**, the **Naming & Structure guide**, and the **LAUNCH/** docs (final launch gate, server-day checklist). A few in-repo technical notes live in `docs/` and `plans/`.

---
*WorkToGo — one platform, many engines. Built to plug new businesses into a shared motherboard.*
