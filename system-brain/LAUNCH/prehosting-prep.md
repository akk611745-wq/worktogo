# WorkToGo — Pre-Hosting Prep (do everything possible NOW, before buying hosting)

> **Purpose:** A founding-CTO, **READ-ONLY** scan + safe plan so that when hosting arrives, **only server-side work remains** — no wasted time. Two goals: (1) a labelled inventory that separates the live **V2** system from old **V1 / dead** stuff **without deleting anything**, and (2) confirmation of what can be prepared before a server exists, plus the exact server-only steps that remain.
> **Grounded in real files** in `wtg-v2` (git branch `v2`) @ 2026-09-05, verified live this session (git state, 52 migrations, `.env.example`, `migrate.php`, `config/engines.php`). Companion to `MAP/system-map.md`, `LAUNCH/final-launch-gate.md`, `LAUNCH/master-readiness.md`.
> **Nothing was moved, renamed, or deleted.** Every "archive" or "remove" below is a **recommendation for the founder to approve** — not an action taken. The V2 system keeps working whether or not the obsolete files are ever removed (verified: nothing in V2 references them).

---

## ⭐ HEADLINE — two things this scan caught that change the prep order

Both are new (not in the earlier launch docs), both are **safe to fix now**, and they are **linked**:

### FINDING A — The real V2 code is **NOT on GitHub yet.**
- The remote (`github.com/akk611745-wq/worktogo.git`) has **only `origin/main`** (that's the old V1 live site). There is **no `origin/v2`**. The entire V2 system — all 85 commits ahead of `main`, including every fix batch 1→3.5 — exists **only as a local worktree** (plus whatever OneDrive syncs).
- **Why this is the #1 pre-server task:** the launch plan's first server step is *"clone `v2` to staging / connect GitHub → server."* That is **impossible until `v2` is pushed.** Pushing needs **no hosting** — it can be done today.
- **It's also a backup/bus-factor risk:** the product currently lives on one disk. OneDrive is a file-sync copy, not a versioned Git remote.
- **Recommended (founder action — I did not push; the task is push-safe/read-only):**
  ```bash
  cd "wtg-v2" && git push -u origin v2
  ```
  Optionally also push the local feature branches (`feat/*`, `fix/*`) if you want them backed up; they are not needed for launch.

### FINDING B — `wtg  live/` physically **hosts the Git repository** that `wtg-v2/` depends on.
- `wtg-v2/.git` is not a folder — it's a pointer file:
  `gitdir: …/wtg  live/.git/worktrees/wtg-v2`. Confirmed by `git worktree list`:
  ```
  wtg  live   0bcd8ad [main]      ← old V1 (live prod today) + holds the shared .git
  wtg-v2      7a1acde [v2]        ← the real system, a linked worktree
  ```
- **Consequence for cleanup:** `wtg  live/` is **not just "old V1 you can zip and delete."** If you naively archive/move/delete it, you **sever V2's Git history, branch, and commit metadata.** This is the single biggest "maximum safety" trap in the whole workspace.
- **The fix ties back to Finding A:** once `v2` is safely on GitHub, the old V1 folder can be retired *safely* (V2 can be re-cloned standalone from GitHub, no longer depending on `wtg  live/.git`). **Push first → then, and only then, is archiving V1 safe.**

> **One line:** *The hard engineering is done and the tree is clean — but the code isn't backed up to GitHub yet, and the old V1 folder secretly holds V2's Git. Push `v2` to GitHub now (no server needed); that single action is both your #1 pre-server prep and the prerequisite that later makes archiving the old code safe.*

---

## 1. REPO / WORKSPACE SCAN — labelled inventory (nothing removed)

### 1A. Desktop top-level (`mastar wtg live/`) — the container

| Item | What it actually is | Current or obsolete? | Recommendation | V2 depends on it? |
|---|---|---|---|:--:|
| **`wtg-v2/`** | **THE REAL SYSTEM** — the product you're launching. Git worktree, branch `v2`, clean tree. | ✅ **CURRENT — the product** | **KEEP.** This is the house. | — (is the system) |
| **`WTG-SYSTEM-BRAIN/`** | The documentation library (~157 md docs: MAP, V2, COMMERCE, AGENT, AUDITS-ROLE, LAUNCH). Loose files, **not** under Git. | ✅ **CURRENT — the doc brain** | **KEEP.** This report lives in `LAUNCH/`. | No (docs only) |
| **`wtg  live/`** (double space) | **OLD V1**, the site running on Hostinger today. **Also physically hosts the shared `.git`** for both worktrees (Finding B). Full app tree + `database-structure.sql` + `logs/`. | 🟡 **OBSOLETE after V2 cutover** — but live prod today **and** the Git host | **KEEP until (a) `v2` is on GitHub AND (b) V2 cutover is done.** Then archive — but **only via the special Git-safe procedure in §5.** Never a naive delete. | ⚠️ **YES — via Git** (not code) |
| **`swiftdeliver-main/`** | A **discarded** standalone delivery app — totally different stack (TypeScript/Vite/Railway: `server.ts`, `nginx.conf`, `railway.toml`, `vite.config.ts`). Delivery is being built *inside* V2 instead. | ⛔ **OBSOLETE — dead prototype** | **Archive-in-place now; safe to remove later (founder approves).** | **No** (verified: 0 refs) |
| **`WTG-HARDENING/`** | Old **V1 planning archive** — 4 folders (`SERVICE-ACTIVATION`, `SERVICE-ACTIVATION-EXECUTION`, `SERVICE-GO-LIVE`, `SERVICE-LAUNCH-EXECUTION`). Pre-dates the V2 build; superseded by `WTG-SYSTEM-BRAIN/V2/`. | 🟡 **OBSOLETE as a plan** — historical value only | **Archive-in-place** (move under `WTG-SYSTEM-BRAIN/_archive/` later). Keep, don't delete — it's cheap history. | **No** (verified: 0 refs) |
| **`.claude/`** | Claude Code's own working files (memory/session). Not your product. | ✅ Tooling | **KEEP, ignore.** | No |

> **The V1 `.sql` dump** that older docs mention as loose on the desktop is **no longer there** (verified: no `*.sql` at desktop root). Nothing to secure at the top level. (A committed `database-structure.sql` schema-reference file exists *inside* both `wtg-v2/` and `wtg  live/` — that's fine, it's a schema snapshot, not a customer-data dump.)

### 1B. Inside `wtg-v2/` — CURRENT vs DORMANT vs harmless cruft

Everything in `wtg-v2` is committed on branch `v2` (working tree clean). Nothing here is "old V1." The distinction inside the product is **live-at-launch** vs **built-but-dormant** vs **helper files**:

| Group | Items | Status | Action |
|---|---|---|---|
| **Live-at-launch (service + agent)** | `heart/` `core/` `lib/` `config/` `migrations/` `cron/` `app/` `admin/` `vendor/` `api/` `body/service-engine/` `assets/` `uploads/` `index.php` `.htaccess` `migrate.php` `.env.example` `.gitignore` | ✅ **CURRENT** | Keep. This is the launch surface. |
| **Built-but-dormant (ship the code, feature OFF)** | `body/shopping-engine/` (commerce), `body/video-engine/`, delivery (`core/delivery/` + `heart/api/delivery/` + `rider/`), `brain/`, `body/content-engine/`, agent commerce tools | 🟡 **CURRENT code, dormant feature** (hidden by `SERVICE_ONLY_MODE`; `config/engines.php` has `delivery`/`video` = empty). **Never-touch / READ-ONLY** per project rules. | **Keep as-is. Do NOT remove** — these are post-launch products, not obsolete. |
| **In-repo helper / reference files (harmless)** | `PHASE_0.2_PLAN.md`, `audit-report.md`, `database-structure.sql`, `plans/`, `docs/`, `deploy-hook.php`, `diag.php` | 🟢 Committed helpers/notes. Not loaded by the request pipeline. | **Archive-in-place** (harmless). **But review `diag.php` + `deploy-hook.php` before they're public** — see §3 (small pre-server hygiene). |

### 1C. Is V2 safe/independent of the obsolete files? — **YES, verified.**
- **Code independence:** `grep` across all V2 `.php`/`.js`/`.html` for `swiftdeliver`, `WTG-HARDENING`, and the `wtg  live` path → **zero references.** V2 does not load, include, or read anything from those folders. Deleting all three tomorrow would not change V2's runtime behaviour.
- **The one non-code dependency** is the Git plumbing in Finding B (`wtg-v2/.git` → `wtg  live/.git/...`). That is a *tooling* link, not a runtime link — the app runs fine without Git — but it means the **folder** `wtg  live/` must be retired carefully (§5), not the app's runtime.
- **Doc library (`WTG-SYSTEM-BRAIN/`)** is pure documentation; the app never reads it.

**Net:** the live V2 system keeps working whether or not the obsolete folders are ever removed. They are inert.

---

## 2. WHAT'S ALREADY PREP-READY (no server needed) — confirmed

| Prep item | State | Evidence (verified this session) |
|---|:--:|---|
| **Code on `v2`, clean & committed** | ✅ **DONE** | `git status` clean; branch `v2` @ `7a1acde`; all 19 named fix commits present in `git log` (batches 1, 2, 3, 3.5). |
| **`.env.example` complete** | ✅ **DONE (one small gap)** | Present (6.2 KB, `1ee9fb2`). Covers app/DB/JWT/Razorpay/Cashfree/Anthropic/MSG91+2nd SMS/OTP-tuning/Maps/OAuth/FCM/engines/Heart/Brain, with REQUIRED markers. **Gap:** it omits `MIGRATE_SECRET`, which `migrate.php` *requires* — see §3. |
| **All migrations present + in correct order** | ✅ **DONE — 52 files** | `migrations/` holds **52** dated `.sql` files, `2026_04_25_…` → `2026_08_27_010_auth_channels.sql`. `migrate.php` sorts by filename (`sort()`), so date-prefix order = run order. (Full ordered list in the appendix.) |
| **`migrate.php` tool ready** | ✅ **DONE** | Idempotent: creates `migrations_log` (UNIQUE `migration_file`), skips already-applied, runs pending in sorted order. Auth-gated by `MIGRATE_SECRET`; runs via CLI (`php migrate.php <secret>`) or web (`?secret=`). |
| **Launch docs ready** | ✅ **DONE** | `LAUNCH/final-launch-gate.md`, `LAUNCH/master-readiness.md`, `MAP/system-map.md`, `AUDITS-ROLE/role-01…05`, `AGENT/agent-11`, plus V2/COMMERCE canonical plans — all present and current. |
| **`.gitignore` protects secrets** | ✅ **DONE** | Ignores `.env` and `core/secrets/` (+ local test artifacts). Real credentials won't get committed. |
| **Payment abstraction + failover machinery** | ✅ **BUILT** (needs a 2nd provider *wired*, which is a server/config task) | `lib/payment/` (contract + Cashfree adapter + Razorpay stub + manager) + `config/payment.gateways.php` + migration `2026_08_26_001`. |

**Flagged as missing-but-preparable-now:** see §3 — only two are genuine code prep (`MIGRATE_SECRET` in the template; an optional top-level README), plus the `diag.php`/`deploy-hook.php` review. Everything else that's "missing" is server-only by nature.

---

## 3. THE PRE-SERVER "DONE" CHECKLIST

**Everything that can be finished before hosting:**

- [x] **Code finished** for the service+agent scope (batches 1–3.5). — `git log` on `v2`
- [x] **Working tree clean**, no uncommitted work. — `git status`
- [x] **`.env.example` committed** and comprehensive. — `1ee9fb2`
- [x] **52 migrations present, correctly ordered**, tool ready. — `migrations/` + `migrate.php`
- [x] **`.gitignore` guards `.env` / secrets.**
- [x] **Launch/plan docs written and current.**
- [ ] **⭐ Push `v2` to GitHub** (`git push -u origin v2`). **Highest-value pre-server action; needs no hosting.** (Finding A) — *founder action.*
- [ ] **Add `MIGRATE_SECRET` to `.env.example`** (see below) — *small, safe code prep.*
- [ ] *(optional)* **Add a top-level `README.md` to `wtg-v2`** with the heart/brain/body legend + "the real system is branch `v2`" (system-map §E) — helps any future engineer/host.
- [ ] *(optional)* **Review `diag.php` and `deploy-hook.php`** — confirm they're gated (or plan to remove/protect them before prod), since they'll be web-reachable on a real host.

### Small pre-server code prep worth flagging (do NOT do now — founder approves)

1. **`MIGRATE_SECRET` is missing from `.env.example` but required by `migrate.php`.**
   `migrate.php` returns **HTTP 403 "Invalid or missing MIGRATE_SECRET"** when the key is empty (lines 22–28). A founder who fills in `.env` from the template alone would be **unable to run migrations.** Safe one-line fix: add to the template, e.g.
   ```
   # ── Migrations ──────────────────────────────────────────────
   MIGRATE_SECRET=change-me-random-string   # REQUIRED to run php migrate.php
   ```
   *Purely additive to a template file; zero runtime risk.*

2. **`diag.php` / `deploy-hook.php` (repo root)** — deploy-time helpers that will be publicly reachable once hosted. Recommend a quick read to confirm each is behind a secret/`ADMIN_KEY` or is deleted/blocked in `.htaccess` before prod. *(Flag only — not reviewed in depth this pass.)*

3. **`uploads/` not in `.gitignore`** (minor) — runtime-uploaded media could get committed. Optional: add `uploads/*` (keep a `.gitkeep`) so deploys stay clean. Not a launch blocker.

> Everything above is **flagged, not applied.** No code was changed.

---

## 4. THE SERVER-ONLY REMAINING STEPS — "when hosting comes, only THIS is left"

These **cannot** be done until a server/credentials exist. Ordered.

**Step 0 — Buy + create accounts (founder, ~1 day)**
- VPS (2–4 GB) or managed PHP host + **managed MySQL/MariaDB** (slow-query log ON, automated backups).
- DNS via Cloudflare: `worktogo.in` + `staging.worktogo.in` (free CDN + SSL).
- Credentials: Razorpay (test+live), Cashfree (have), **Anthropic API key**, MSG91 (have) + a **2nd SMS provider**, Google Maps key, Google OAuth client id, FCM (only if enabling push — off at launch).

**Step 1 — Connect GitHub → server & stand up STAGING (~1 day)**
1. **Prereq: `v2` must be on GitHub** (Finding A). Then `git clone` the repo and `git checkout v2` onto `staging.worktogo.in`. The repo is the deploy artifact — **never edit code on the server.**
2. Create `.env` from `.env.example`: `APP_URL=https://staging.worktogo.in`, `APP_ENV=staging`, `CASHFREE_ENV=SANDBOX`, and **set `MIGRATE_SECRET`.**
3. **Back up the DB, then run `php migrate.php <secret>`** — applies all 52 in order, tracked in `migrations_log`. Verify the tail (RBAC, `agent_used_tokens`, vendor V2 fields, delivery, indexes).
4. Flags: `SERVICE_ONLY_MODE` on; agent kill-switch **OFF**; commerce capability off (code default).
5. Cron runner: `auto_assign.php` + `delivery_lifecycle.php` (dormant ok) + `agent_reminders.php` + `agent_demand_detect.php`, with missed-run alerting.

**Step 2 — Staging validation gates (all must go green)**
- **`EXPLAIN` the hot legacy indexes** → add any missing as a new dated migration: `bookings.user_id`, `bookings.client_request_id`, `otps.phone`, `jobs.booking_id`, `auto_assignments(entity_type,entity_id)`, `wallet_transactions(entity_type,entity_id)`. *(These tables come from the inherited V1 schema, not the repo migrations — hence the check.)*
- **Real ₹1 payment round-trip** (sandbox + one live ₹1): webhook flips `paid`, return page never self-authorizes, blank-creds shows the friendly "gateway not configured" error.
- **Real AI key + live agent round-trip:** a booking via chat + PII-block **422** + cross-user **404** negative tests; confirm zero-retention terms.
- **Wire the 2nd OTP provider** (+ `routing.sms.secondary`) and **Razorpay as primary / Cashfree secondary** → simulate a primary outage, confirm auto-diversion. *(This is what finally lights up the built-in failover.)*
- **Full role tests** (admin / vendor / customer) incl. the IDOR/negative tests from the launch gate.

**Step 3 — Cutover to PUBLIC (one city: service + agent)**
- Repeat Step 1 on prod (`APP_URL=https://worktogo.in`, `APP_ENV=production`, `CASHFREE_ENV=PRODUCTION` / Razorpay live), back up, `migrate.php`.
- Zero fake data; seed only Haldwani + real categories + real expert-visit pricing.
- Turn agent kill-switch **ON per-capability** (service first; commerce stays off).
- Soft-launch a small real cohort; watch slow-query log, admin dashboard, agent monitor.

**Step 4 — Testing while live**
- Keep staging permanently up as a prod mirror (same code, separate DB + `APP_URL`). Every change: `v2` → staging → full role test → tag → prod. Pre-deploy DB backup every time. Rollback = `git revert`/previous tag + restore the dump; feature-level = kill-switch / `SERVICE_ONLY_MODE`.

*(Full detail: `LAUNCH/final-launch-gate.md` Part 3.)*

---

## 5. SAFE CLEANUP PLAN (for the founder to approve LATER — nothing now)

Reversible, ordered, zero-risk to V2. **Do none of this until after V2 cutover and `v2` is safely on GitHub.**

### Rule 0 — The prerequisite that makes cleanup safe
**Push `v2` to GitHub first** (Finding A). Until the V2 code is backed up to a real remote, do not touch any folder — the old V1 folder is currently V2's only Git home (Finding B).

### The safe pattern for the two truly-dead folders (`swiftdeliver-main/`, `WTG-HARDENING/`)
These are the low-risk ones (V2 has zero references; not Git-entangled):
1. **Back up:** zip each folder to an off-desktop location (or OneDrive backup).
2. **Move, don't delete:** create `WTG-SYSTEM-BRAIN/_archive/` and move `swiftdeliver-main/ → _archive/swiftdeliver/` and `WTG-HARDENING/ → _archive/HARDENING-V1/`.
3. **Verify:** open the app / run the service flow → confirm nothing changed (it won't — they're inert).
4. **Only then, optionally delete** the zips much later. `WTG-HARDENING` has historical value → recommend **keep archived, don't delete.**

### The careful pattern for `wtg  live/` (the Git-hosting V1 folder) — **highest care**
Do this **only after** V2 is live, stable, and `origin/v2` exists on GitHub:
1. **Confirm `v2` is fully pushed:** `git push origin v2` shows "up to date"; verify `origin/v2` on GitHub.
2. **Detach V2 from the shared Git** (pick one, safest first):
   - **Safest — re-clone V2 standalone:** in a fresh location, `git clone …/worktogo.git && git checkout v2`. This new copy has its **own** `.git` and no longer depends on `wtg  live/`. Point future work at it.
   - *(Alternative, more advanced: `git worktree remove` / `git worktree repair` to relocate the metadata — only if comfortable with Git worktrees.)*
3. **Take a final V1 DB backup** and confirm the live site is fully cut over to V2.
4. **Back up, then move** `wtg  live/ → _archive-v1/` (zip first). **Never delete before** the V2 standalone clone is verified working (step 2) and V1 is decommissioned.
5. **Verify** the V2 clone builds/runs and its Git works (`git log`, `git status`) independent of the old folder.
6. Delete the V1 archive only much later, if ever (it's your rollback net).

> **Reversibility guarantee:** every step is move-then-verify. At no point is anything deleted before a verified backup/replacement exists. If anything looks off, the move is trivially undone.

---

## Appendix — verified facts this report stands on (2026-09-05)

- **Branch/tree:** `wtg-v2` on branch `v2`, working tree **clean**, HEAD `7a1acde`. 19 fix commits (batches 1–3.5) all present.
- **Remote:** `origin` = `github.com/akk611745-wq/worktogo.git`; remote has **only `main`** — **no `origin/v2`** (V2 unpushed; 85 commits ahead of `origin/main`).
- **Worktrees (one repo):** `wtg  live` [main] hosts `.git`; `wtg-v2` [v2] is a linked worktree pointing into `wtg  live/.git/worktrees/wtg-v2`.
- **Migrations:** 52 `.sql`, `2026_04_25 → 2026_08_27_010`, filename-sorted by `migrate.php`, tracked in `migrations_log`, idempotent (skip-applied).
- **`migrate.php` requires `MIGRATE_SECRET`** — which is **absent from `.env.example`** (the one template gap).
- **`config/engines.php`:** `service`/`shopping` = local folders, `delivery`/`video` = empty (dormant) — the pluggable seam confirmed.
- **Obsolete-folder independence:** `grep` of all V2 source for `swiftdeliver` / `WTG-HARDENING` / `wtg  live` → **0 references.** V2 runs regardless.
- **No loose `.sql` at desktop root** (old V1 dump no longer present).
- **This scan changed nothing** — no file moved, renamed, or deleted; `v2` not pushed; live untouched.

*End of pre-hosting prep. When budget/hosting arrive, work through §4 only — §1–§3 confirm everything else is already as ready as it can be off-server, and §5 waits for your approval.*
