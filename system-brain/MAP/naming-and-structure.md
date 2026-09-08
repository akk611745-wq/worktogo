# WorkToGo — Naming & Structure Guide (the "clean names" rulebook)

> **What this is:** The single place that decides **what everything is called and where it lives**, so that the founder *and* any future team member can (1) understand the whole system in minutes, (2) find where a problem is by name, and (3) add a new engine/service later just by following the naming rule.
> **Grounded in** the real repo (`wtg-v2`, branch `v2`) @ 2026-09-06 and its docs (`MAP/system-map.md`, `LAUNCH/prehosting-prep.md`, `LAUNCH/final-launch-gate.md`). **READ-ONLY** — nothing was renamed, moved, deleted, or pushed. Everything below is a **recommendation the founder approves**; the mechanics are described but not executed.
> **The key idea:** *"v2" is not the product's name — it's just an internal version tag* ("this is the 2nd-generation codebase"). The product is **WorkToGo**: a **motherboard + engines + an AI agent**. Those parts deserve clean, self-explaining names, and this guide gives them.

---

## 0. TL;DR (the decisions, one screen)

| Thing | Today | Recommended clean name | Do it when |
|---|---|---|---|
| **GitHub repo** | `worktogo` ✅ (already correct!) | **`worktogo`** — keep it | Nothing to do |
| **Working folder** | `wtg-v2/` | **`worktogo/`** (via a fresh clone, not an in-place rename) | At GitHub push / re-clone |
| **Old V1 folder** | `wtg  live/` | **`worktogo-legacy-v1/`** | After V2 cutover (Git-safe steps in `prehosting-prep.md §5`) |
| **Production branch** | `v2` (unpushed) → will become prod | **`main`** = production | At cutover |
| **Old V1 on `main`** | `main` (= live V1 today) | preserve as **`legacy-v1`** (branch or tag) | At cutover |
| **Testing branch** | — | **`staging`** | When staging server exists |
| **Folder metaphor** | heart / body / core / lib / brain / agent | **Keep — it's an asset.** Add short READMEs. | Anytime (additive) |
| **"Shopping" engine** | code key `shopping` | human name **"Commerce Engine"**, code key stays `shopping` (never-touch) | Documented here |

> **Biggest myth to kill:** the founder does **not** need to create a new repo or rename the product. The repo is already `worktogo`. The only real cleanup is: **push `v2` to GitHub, then re-clone it into a folder called `worktogo/`, and (at cutover) promote it to `main`.**

---

## 1. RECOMMENDED NAMES

### 1.1 The GitHub repo — **`worktogo`** (already correct; keep it)

The remote is already `github.com/akk611745-wq/worktogo.git`. The word "v2" is **not** in the repo name — it's only in the *branch* (`v2`) and the *desktop folder* (`wtg-v2`). So there is nothing to fix at the repo level.

| Option | Verdict |
|---|---|
| **`worktogo`** ✅ **(pick — already the name)** | Clean, matches the product, zero migration. A repo is the *product*; it holds all branches (prod, staging, dev). This is exactly right. |
| `worktogo-platform` | Only if you ever split engines into **separate repos** (true microservices). The architecture already allows this (engines can be remote URLs), but for one founder, one repo is simpler and better. |
| `worktogo-app` | Avoid — implies "just the app", but this is a platform (motherboard + many engines). |

> **Rule:** the repo name = the product name = **WorkToGo**. Versions/generations live in **branches and tags**, never in the repo name.

### 1.2 The Git branches — a world-class, simple model

**Target model** (what every professional team uses, sized for a solo/small founder):

| Branch | Plain-English role | Protected? |
|---|---|---|
| **`main`** | **Production.** The exact code running live at worktogo.in. The source of truth. Only tested code lands here. | ✅ yes |
| **`staging`** | **The rehearsal stage.** A mirror of prod on `staging.worktogo.in` where every change is tested first. | ✅ yes |
| **`dev`** *(optional)* | **The workbench.** Where in-progress work integrates before staging. A solo founder can skip this and use short-lived feature branches instead. | no |
| **`feature/<name>`, `fix/<name>`** | One branch per change (e.g. `feature/vendor-earnings`, `fix/otp-timeout`). Merged via Pull Request → `staging` → `main`. | no |
| **`legacy-v1`** | The **old V1 code**, frozen for rollback/history. Never developed on again. | ✅ (frozen) |

**How today's names map onto this:**

- **`v2` → becomes `main`.** "v2" is the *current generation* of the real product. Once it's the deployed system, it simply **is** production — so it takes the name `main`. The label "v2" then retires (its meaning lives on as a tag, e.g. `v2.0-launch`).
- **today's `main` (the live V1) → becomes `legacy-v1`.** It's preserved, frozen, and kept as the rollback net — not deleted.
- **`staging`** is created new, branched from `main` (the V2 lineage) when the staging server is set up.
- The many local `feat/*` and `fix/*` branches already in the repo are the right *idea* but should be short-lived — merged and deleted after they land, not left lying around.

**The safe promotion recipe (recommend only — do at cutover, founder approves):**
> ⚠️ Depends on the Git-safety steps in `prehosting-prep.md` (push `v2` first; the old folder physically hosts the shared Git).
1. `git push -u origin v2` (back the real code up to GitHub — this is the #1 pre-server task).
2. Preserve V1: `git branch legacy-v1 main` then tag it `git tag v1-final main` (frozen history/rollback).
3. When V2 is the deployed system, make `v2` the new default: on GitHub, push `v2`, set it as the **default branch**, then either rename `v2 → main` (after moving old `main` aside) or fast-forward `main` to `v2`. *(Exact commands to be run together at cutover, with a backup first — not now.)*
4. Create `staging` from `main`. Protect `main` + `staging`.

> **For the founder, in one line:** *the real system (today "v2") becomes `main`; the old site (today "main") becomes `legacy-v1` and is kept as a safety net; `staging` is the rehearsal copy. That's the whole model.*

### 1.3 The folders / modules — **keep the body metaphor** (it's a genuine asset)

The heart/brain/body metaphor is *memorable* — a non-technical founder actually recalls "the heart is the front door." **Keep it.** The only work is adding a one-line README in each so the meaning is self-evident, and documenting the one fuzzy split (`core` vs `lib`).

| Folder | Clean name | What it is (one line) | Keep / improve |
|---|---|---|---|
| `heart/` | **The Heart** (API gateway) | The front door: every request is one `POST {intent}` that enters here, gets validated, and is routed to the right engine. | **Keep.** Add README. |
| `body/` | **The Body** (the engines) | Holds the actual businesses — one subfolder per engine. | **Keep.** Add a 1-line README: *"each subfolder is a business engine."* |
| `core/` | **Core** (platform helpers) | Shared helpers every part uses: identity/JWT, city, media, permissions, ledger, DB, logging, plus `core/agent/` and `core/delivery/`. | **Keep.** Document the core-vs-lib rule below. |
| `lib/` | **Lib** (service libraries) | Self-contained service libraries: `lib/payment/` (multi-gateway), `lib/auth/` (OTP channels), `PaymentService`, `CashfreeClient`, `WebhookVerifier`. | **Keep.** |
| `brain/` | **The Brain** (personalization) | The optional "thinking" layer — ranking, suggestions, alerts. Circuit-broken; the app runs fine without it. | **Keep.** |
| `core/agent/` | **The Agent ("dost")** | The AI assistant that turns natural language (text/voice) into intents across engines. A *translator*, never a data owner. | **Keep.** (See naming note below.) |
| `config/` | **Config** | All backend settings + the engine registry (`engines.php`). | **Keep.** |
| `migrations/` | **Migrations** | Every dated DB change, run in order by `migrate.php`. | **Keep.** |
| `cron/` | **Cron** | Scheduled jobs (auto-assign, delivery lifecycle, agent reminders). | **Keep.** |
| `api/` (root) | **Direct API edges** | The few endpoints that bypass the intent pipeline (payment webhooks, alerts, assignments). | **Keep.** |

**The one rule to write down — `core/` vs `lib/`** (this is the only genuinely confusing split):
> **`lib/`** = *self-contained service libraries* you could lift out and reuse elsewhere (payment gateways, OTP channels).
> **`core/`** = *platform helpers* wired into WorkToGo's own conventions (JWT, CityResolver, Media, Permission, LedgerEngine, Response/Validator/Logger).
> **Known duplicate to consolidate post-launch:** `core/helpers/Payment.php` overlaps `lib/PaymentService.php` — pick one owner (documented in the audits; not a launch blocker).

**Small metaphor gaps to note (fix later, low priority):**
- **Delivery** lives in `core/delivery/` + `heart/` + `cron/` + `rider/`, **not** in `body/` — it breaks the "engines live in `body/`" expectation. *When delivery is finished, move it to `body/delivery-engine/`* for consistency.
- **The Agent** lives under `core/agent/` — fine, but it's a headline feature; a newcomer might expect it more visible. Keep for now; the master index (§3) points to it clearly.

### 1.4 The canonical names — engines + motherboard

| # | Canonical name | Code identifier | Where it lives | What it is | Status at launch |
|---|---|---|---|---|---|
| — | **The Motherboard** (a.k.a. Platform Core) | `core/` + `lib/` + `config/` | those 3 folders | The shared, expensive-to-build foundation every engine plugs into: one identity, one payment layer, city, media, permissions, ledger. **Build once, reuse forever.** | ✅ built |
| 1 | **Service Engine** | `service` | `body/service-engine/` | Book a local service (electrician, plumber, painter…), expert visits, vendor jobs. **The launch product.** | ✅ live |
| 2 | **Commerce Engine** | `shopping` *(code key — never-touch)* | `body/shopping-engine/` | E-commerce: products, cart, multi-vendor orders, settlement. | 🟡 built, hidden |
| 3 | **Delivery Engine** | `delivery` | `core/delivery/` + `heart/api/delivery/` + `heart/api/rider/` + `cron/delivery_lifecycle.php` + `rider/` | Rider assignment + delivery lifecycle + COD accounting. | 🟡 built, dormant |
| 4 | **Video Engine** | `video` | `body/video-engine/` | Short-video feed (Reels-style) of vendor work. | ⛔ scaffold |
| 5 | **Content Engine** | *(none in registry)* | `body/content-engine/` | Expiring stories / content feed. | 🟡 minimal |
| 6 | **The Agent ("dost")** | `agent` | `core/agent/` + `app/agent/` + `heart/api/agent/` | Natural-language front door across all engines; safety-walled, confirm-gated. | 🟢 built |
| 7 | **The Brain** | `brain` | `brain/` | Optional personalization/ranking/alerts. | 🟡 scaffold |

> **Naming decision to lock in — "Commerce" vs "Shopping":** the code, the engine registry key, and the env var all say **`shopping`** (`ENGINE_SHOPPING_URL`, `body/shopping-engine/`), while the admin pages and plans say **"commerce"**. **Human/spoken canonical name = "Commerce Engine". Code identifier stays `shopping`** — it is never-touch; renaming it risks the money path. Always write it as **"Commerce Engine (code: `shopping`)"** so no one is confused. *(This is the "two product systems" ambiguity from the audits — resolved here as a naming convention, not a code change.)*

---

## 2. THE NAMING RULE FOR THE FUTURE (add a new engine by NAME)

This is the founder's "motherboard, plug new things in" vision made into a rule. **Pick one lowercase noun for the new business** (`tuition`, `rental`, `laundry`, `salon`…). That single word then appears — *identically* — in every place the engine touches. Read the name once, and you know everywhere it lives.

| Where | Pattern | Example (`tuition`) |
|---|---|---|
| Engine folder | `body/<name>-engine/` | `body/tuition-engine/` |
| Registry key | `<name>` in `config/engines.php` | `'tuition' => getenv('ENGINE_TUITION_URL') ?: __DIR__.'/../body/tuition-engine/index.php'` |
| Intents (actions) | `<name>:<verb_object>` (snake_case) | `tuition:book_class`, `tuition:list_tutors` |
| Env URL (remote option) | `ENGINE_<NAME>_URL` (UPPERCASE) | `ENGINE_TUITION_URL=` (blank → local folder) |
| DB tables | `<name>_<thing>` (always with `city_id`) | `tuition_classes`, `tuition_enrollments` |
| Migration file | `YYYY_MM_DD_NNN_<name>_<what>.sql` | `2026_10_01_001_tuition_foundation.sql` |
| Admin pages | `admin/<name>-*.html` | `admin/tuition-classes.html` |
| Customer screens | `app/<name>/` (if it needs its own UI) | `app/tuition/` |
| Docs | `WTG-SYSTEM-BRAIN/<NAME>/` | `WTG-SYSTEM-BRAIN/TUITION/` |

**The 6 wires to plug in (unchanged from the architecture):** register in `engines.php` → add routes in `heart/router.php` → build `body/<name>-engine/index.php` → reuse identity/permissions (JWT) → reuse payment/city/media/ledger → add a dated migration. **You never rebuild login, payment, city, or media** — that's the motherboard doing its job.

> **The world-class property:** because the *same word* is the folder, the registry key, the intent prefix, the env var, and the table prefix, anyone (or any future Claude session) can trace a new business end-to-end from its name alone. That is what makes the system "readable by name."

**Two hard rules that keep this clean:**
1. **One word, one meaning.** Don't call it `shopping` in code and `commerce` in docs (learn from the one case we already have). Pick the noun and use it everywhere.
2. **Never fork the shared foundation.** No per-engine login table, no engine talking to Cashfree directly, no second `media` table. Everything domain-specific is prefixed `<name>_`; everything shared stays in the motherboard.

---

## 3. THE MASTER INDEX (open this first — the whole system on one page)

**How to read it:** find the layer → know its clean name, what it does, the folder to open, and where its docs are.

### 3.1 The request path (top to bottom)

| Layer | Clean name | What it does | Folder | Docs |
|---|---|---|---|---|
| Front door | **The Heart** | Receives every `POST {intent}`, validates, routes to engines | `heart/` | `api-architecture-map.md`, `runtime-orchestration-map.md` |
| Optional thinking | **The Agent** / **The Brain** | Agent: NL → intents. Brain: ranking/personalization. Both optional. | `core/agent/`, `brain/` | `AGENT/agent-01…11`, `master-system-brain.md` |
| The businesses | **The Engines** (see 3.2) | Do the actual work of each business | `body/` (+ delivery in core/heart) | per-engine docs |
| The foundation | **The Motherboard** | Shared identity, payment, city, media, permissions, ledger | `core/` + `lib/` + `config/` | `core-foundation-map.md`, `shared-services-map.md` |
| Storage | **Database** | MySQL/MariaDB, changed only via `migrations/` | `migrations/` + `migrate.php` | `database-schema-map.md`, `table-relationship-map.md` |

### 3.2 The engines (the businesses)

| Engine | Folder | Launch status | "Where do I fix it?" docs |
|---|---|---|---|
| **Service Engine** | `body/service-engine/` | ✅ **live product** | `service-engine-map.md`, `booking-lifecycle-map.md` |
| **Commerce Engine** (code: `shopping`) | `body/shopping-engine/` | 🟡 built, hidden | `COMMERCE/commerce-*`, `commerce-fulfillment-map.md` |
| **Delivery Engine** | `core/delivery/` + `heart/api/delivery/` + `rider/` | 🟡 dormant | `delivery-system-map.md`, `dispatch-lifecycle-map.md` |
| **Video Engine** | `body/video-engine/` | ⛔ scaffold | `audit-08-dormant-engines.md` |
| **Content Engine** | `body/content-engine/` | 🟡 minimal | `audit-08-dormant-engines.md` |

### 3.3 The screens people use

| Surface | Who | Folder | Docs |
|---|---|---|---|
| **Customer app** | Customers | `app/` | `customer-flow-map.md`, `customer-api-map.md` |
| **Vendor panel** | Vendors | `vendor/` | `role-02-vendor.md` |
| **Admin control room** | Founder / ops | `admin/` | `admin-operations-map.md`, `role-01-admin.md` |
| **Rider app** | Riders | `rider/` | `delivery-mobile-analysis.md` |

### 3.4 The "where do I fix X?" shortcut
For symptom-based lookup (payment, OTP, booking, permissions, etc.), use the emergency table in **`MAP/system-map.md` §C** — it's the fastest path when something breaks.

### 3.5 The doc library map
- **Start here:** `MAP/system-map.md` (house blueprint), **this file** (names), `LAUNCH/final-launch-gate.md` (launch readiness), `LAUNCH/prehosting-prep.md` (pre-server prep).
- **Canonical plans:** `V2/` (vision/blueprint), `AGENT/`, `COMMERCE/`, `AUDITS-ROLE/` (role-01…05).
- **Ground-truth audits:** `audit-01…08`, `master-system-brain.md`.
- **The running log:** `CHANGELOG.md` (see §5).

---

## 4. WHAT GOES WHERE ON GITHUB

### 4.1 Committed (goes up to GitHub)
All application code (`heart/ core/ lib/ body/ brain/ app/ admin/ vendor/ rider/ api/ config/ cron/ assets/`), **`.env.example`**, `migrations/`, `migrate.php`, `.htaccess`, `index.php`, in-repo notes (`docs/`, `plans/`), and a **new `README.md`** (recommended — see §6).

### 4.2 Local-only — never committed (via `.gitignore`)
- **`.env`** (real secrets) — already ignored ✅
- **`core/secrets/`** — already ignored ✅
- **`logs/`** + local test artifacts — already ignored ✅
- **Recommend adding:** `uploads/*` (keep a `.gitkeep`) so runtime-uploaded media doesn't get committed.

> **Golden rule for the founder:** real passwords/keys live **only** in `.env` on each server and never leave it. GitHub gets the **template** (`.env.example`) that lists *which* keys are needed, with blanks. If a real secret ever lands on GitHub, rotate it immediately.

### 4.3 Where do the docs (WTG-SYSTEM-BRAIN) go?
Today the docs live **outside** the repo (loose on the desktop, not tracked by Git). Two clean options:

| Option | Pros | Recommendation |
|---|---|---|
| **A. Commit docs with the code** — move the *current* docs into the repo as `docs/` (MAP, LAUNCH, V2, AGENT, COMMERCE, this guide, CHANGELOG) | Any clone is self-documenting; docs version alongside code (world-class) | ✅ **Recommended for the canonical/current docs.** |
| **B. Keep the brain separate** — leave the big historical analysis set (~150 files) as an adjacent doc store | Keeps the repo lean; history stays out of the code | Use for the **historical/archive** docs only. |

**Pick:** commit the *living* docs (A) so the repo explains itself; keep the deep historical audits as a separate archive (B). *(Founder decision — recommend only; involves moving files, so do it deliberately, not now.)*

### 4.4 The clean structure once pushed
```
github.com/akk611745-wq/worktogo   (repo — the product)
├── branches:  main (prod) · staging · legacy-v1 (frozen) · feature/* · fix/*
└── on disk, a clone named  worktogo/
    ├── heart/         ← the Heart (API gateway)
    ├── body/          ← the engines (service, shopping=commerce, video, content)
    ├── core/          ← platform helpers (+ agent, + delivery)
    ├── lib/           ← service libraries (payment, auth)
    ├── brain/         ← personalization (optional)
    ├── app/ vendor/ admin/ rider/   ← the screens
    ├── config/ migrations/ cron/ api/
    ├── docs/          ← (recommended) the living docs, versioned with code
    ├── .env.example   ← the key template (real .env stays local)
    ├── README.md      ← (recommended) the metaphor legend + quick start
    └── CHANGELOG.md   ← (recommended) the running log
```

---

## 5. THE ONGOING NOTES / CHANGELOG

**Created:** `WTG-SYSTEM-BRAIN/CHANGELOG.md` — a running, dated record so **nothing is ever forgotten** and any future session (or team member) picks up instantly. It's seeded with the real history so far (audits, fix batches, prep docs).

**The rule (one line per significant thing):**
```
- YYYY-MM-DD — <what changed> — <where / commit or file> — <why / who>.
```
**Log every:** code batch, migration run, config/credential change, hosting/deploy step, naming/structure decision, and go-live event. **Newest at the top.** When the repo unifies with docs (§4.3), this same file also lives at the repo root as `CHANGELOG.md`.

---

## 6. SAFE, REVERSIBLE RECOMMENDATIONS (founder approves — nothing executed)

Ranked by value; each is zero- or low-risk, and none is done now.

| # | Recommendation | Risk | How (safe way) |
|---|---|---|---|
| 1 | **Push `v2` to GitHub** (`git push -u origin v2`) | none | The #1 pre-server task; also unlocks everything below. See `prehosting-prep.md`. |
| 2 | **Get the clean `worktogo/` folder via a fresh clone**, *not* by renaming `wtg-v2/` | none (clone) vs. **high if renamed in place** | After push, `git clone …/worktogo.git worktogo && cd worktogo && git checkout v2`. ⚠️ **Do not rename `wtg-v2/` in place** — it's a Git worktree; renaming breaks its `.git` pointer. Cloning sidesteps that entirely. |
| 3 | **Reorganize the doc library** into subfolders (`AUDITS/ AUTH/ PAYMENTS/ DELIVERY/ DATABASE/ RUNTIME-RISKS/`) | **zero** (docs aren't code or a Git worktree) | Plain file moves inside `WTG-SYSTEM-BRAIN/`. Truly safe — the app never reads these. |
| 4 | **Add short READMEs** — repo root `README.md` (metaphor legend + "real system = branch `main`/`v2`") and a 1-liner in `body/` | none (additive) | New files only. |
| 5 | **Add `MIGRATE_SECRET` to `.env.example`** + `uploads/*` to `.gitignore` | none (additive) | Already flagged in `prehosting-prep.md §3`; template/ignore edits only. |
| 6 | **At cutover:** promote `v2 → main`, freeze old `main → legacy-v1`, create `staging` | low (do with a backup) | The recipe in §1.2, run together at cutover — **not now**. |
| 7 | **Later, when finished:** move Delivery into `body/delivery-engine/`; consolidate the duplicate payment helper | low (post-launch) | Structural tidy-ups; do after launch with tests. |

> **What is NOT recommended:** renaming the repo, renaming the product, renaming any code identifier (`shopping`, engine keys, table names), or renaming/moving `wtg  live/` before V2 is on GitHub and cut over. Those are either unnecessary or Git-unsafe.

---

## Appendix — verified facts (2026-09-06)
- Repo remote: `github.com/akk611745-wq/worktogo.git` — repo already named `worktogo`; remote has only `main` (V1). `v2` is local-only.
- Engines confirmed in `config/engines.php`: `service` + `shopping` = local folders; `delivery` + `video` = empty (dormant). `body/` = `service-engine, shopping-engine, video-engine, content-engine`.
- Motherboard = `core/` (+ `core/agent/`, `core/delivery/`, `core/helpers/`, `core/lib/`) + `lib/` (`payment/`, `auth/`, `PaymentService.php`, `CashfreeClient.php`, `WebhookVerifier.php`) + `config/`.
- Git worktrees: `wtg  live` [main] hosts the shared `.git`; `wtg-v2` [v2] is a linked worktree → renaming/moving either folder needs Git care (see `prehosting-prep.md §5`).
- This guide **changed no code, moved nothing, pushed nothing.** All names above are recommendations awaiting founder approval.

*End of naming & structure guide. Companion to `MAP/system-map.md`, `LAUNCH/prehosting-prep.md`, and `CHANGELOG.md`.*
