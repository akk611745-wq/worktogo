# WorkToGo — CHANGELOG & Running Notes

> **What this is:** The one running record of everything significant that happens to WorkToGo — every code batch, migration, config/credential change, hosting/deploy step, naming decision, and go-live event. So **nothing is ever forgotten** and any future session or team member picks up instantly.
>
> **How to use it (the rule):** add **one dated line per significant thing**, newest at the top:
> ```
> - YYYY-MM-DD — <what changed> — <where / commit or file> — <why / who>.
> ```
> Keep it honest and specific (a commit hash, a file, a branch). This file is the memory of the project. When code + docs unify on GitHub (see `MAP/naming-and-structure.md §4.3`), this same file also lives at the repo root as `CHANGELOG.md`.
>
> **Legend:** 🟢 done · ⏳ pending/next · ⚠️ risk/caution · 🚀 launch milestone.

---

## Pending / next (living to-do — move to the log below once done)
- ✅ ~~Push `v2` to GitHub~~ — DONE: pushed to new repo `worktogo-platform` (main).
- ✅ ~~Add `MIGRATE_SECRET` to `.env.example`~~ — DONE in `4a8a4c3` (+ other missing keys).
- ⏳ **Push the additive commit `4a8a4c3`** to `worktogo-platform` (`git push`) when convenient — the README/.env.example/.gitignore prep is committed locally but not yet on GitHub.
- ⏳ **Buy hosting** → then run `LAUNCH/server-day-checklist.md` end to end (Phase 0→8): connect GitHub→server, set `.env`, backup+migrate, `EXPLAIN` hot indexes, schedule crons, real ₹1 payment, real AI key, wire 2nd SMS/payment provider, role tests, cutover.
- ⏳ **Create `staging` (+ optional `dev`) branch** on `worktogo-platform` + protect `main`/`staging` (recipe in `server-day-checklist.md §8`).
- ⏳ **Post-launch:** wire FCM push **and** add the vendor-push call in `cron/auto_assign.php` (the notification gap in `LAUNCH/alert-notification-flow.md §3`); review `diag.php`/`deploy-hook.php` gating before prod.

---

## 2026-09

- 2026-09-06 — 🟢 **Pre-server final readiness prep** — additive-only, committed to `v2` as `4a8a4c3` (NOT pushed): new `README.md` (system + body-metaphor + run steps), completed `.env.example` (+`MIGRATE_SECRET`,`CRON_SECRET`,`ALERT_INTERNAL_SECRET`,`ALLOWED_ORIGINS`,`TRUST_PROXY`,`APP_VERSION` + optional Redis/upload/log/retention/swiftdeliver block), hardened `.gitignore` (`uploads/*` w/ structural keeps, `.env.*`,`*.pem`,service-account json,`*.log`,OS noise). New docs: `LAUNCH/alert-notification-flow.md` + `LAUNCH/server-day-checklist.md`.
- 2026-09-06 — 🔔 **Alert/notification flow verified** (`LAUNCH/alert-notification-flow.md`) — in-app flow COMPLETE for admin/vendor/customer via two channels (`notifications`+FCM via `FcmNotifier`; `alerts` table via auto_assign/accept/errors). In-app works with no keys; push needs FCM (post-launch). **One code gap:** `cron/auto_assign.php` writes only an `alerts` row on auto-assign → **no push/bell to the vendor** (manual assign does push); fix = add a `FcmNotifier::notifyUser` call there (flagged, not done). Also: `alerts` table is legacy-schema (not in migrations) — verify on target DB.
- 2026-09-06 — 🚀 **Backup created: real system pushed to GitHub** — new repo `worktogo-platform` (branch `main` = the real system). The 85 local-only commits are now backed up off-machine. *(Founder action, per `LAUNCH/github-new-repo-plan.md`.)*
- 2026-09-06 — 🟢 **GitHub new-repo plan created (PLANNING ONLY)** — `LAUNCH/github-new-repo-plan.md`. Confirmed from git: real system is **local-only** — `v2` = 509 commits (tip `7a1acde`, 2026-09-05), **85 commits ahead of `origin/main`** and on **no remote** (`git branch -r --contains 7a1acde` empty); the GitHub `worktogo` repo is the **frozen V1** (`origin/main` = 424 commits, tip `0bcd8ad`, 2026-07-03). Plan: create clean new **private** repo **`worktogo-platform`**, `git remote add platform …` + `git push -u platform v2:main` (safe — push only copies up; worktree caveat irrelevant to pushing). Old repo left parked. Nothing pushed/created — awaits founder.
- 2026-09-06 — 🟢 **Naming & Structure Guide created** — `MAP/naming-and-structure.md` + this `CHANGELOG.md`. Decisions: repo stays **`worktogo`** (already correct); branches → **`main`=prod / `staging` / `legacy-v1`(frozen V1)**; **`v2` is an internal version tag, not the product** and becomes `main` at cutover; body metaphor kept; **"Commerce Engine" = human name, code key stays `shopping`** (never-touch); a naming rule for future engines (one lowercase noun used identically across folder/registry/intent/env/tables). READ-ONLY — no renames executed.
- 2026-09-05 — 🟢 **Pre-hosting prep report** — `LAUNCH/prehosting-prep.md`. Found: **`v2` not pushed to GitHub** (remote has only `main`=V1); **`wtg  live/` physically hosts the shared `.git`** (worktree entanglement — archiving it naively would sever V2's Git); 52 migrations verified in order; `.env.example` complete except missing `MIGRATE_SECRET`; V2 has **zero** code refs to obsolete siblings (`swiftdeliver-main`, `WTG-HARDENING`). READ-ONLY.
- 2026-09-04 — 🟢 **Final Launch Gate** — `LAUNCH/final-launch-gate.md`. Verdict: code-side **finished** for service+agent one-city scope (~90%); remaining is staging validation + hosting/budget, not building.
- 2026-09-04 — 🟢 **Batch 3.5 — vendor panel stitch** — commits `32abf62` (unify vendor panel on `wtg_vendor_token`; Earnings reachable; dead `/vendor/login.html` 404 gone), `1ee9fb2` (committed `.env.example`), `7a1acde` (removed dead `app/js/map.js`). Branch `v2`, local only.
- 2026-09-04 — 🟢 **Batch 3 — vendor earnings + guards + polish** — 8 commits: `e7d5acd`+`40dc328` (vendor earnings endpoint + dashboard tab), `35439bc` (last-admin/self-demotion guard), `c5acfa7` (new settings `is_public=0`), `baac240` (agent commerce OFF by default), `a197455` (pinch-zoom), `a7c332e` (admin JWT 30d→12h), `f963877` (re-login overlay).
- 2026-09-04 — 🟢 **Batch 2 — first-open speed** — 5 commits: `f80ee49` (Maps on-demand), `f8ac74c` (Firebase only when FCM configured), `ddbcfb7` (parallel webfont `<link>`), `8041da0` (pin Chart.js 4.4.1), `1026e48` (real service-worker cache).
- 2026-09-04 — 🟢 **Batch 1 — staging-safety + hygiene** — commits `abdf386` (env-drive `BASE_URL`→same-origin, never prod), `ca7aee3` (remove `CASHFREE_ORDER_DEBUG` log), `745e621` (stale throttle comment + unwired-flag annotations).
- 2026-09-02 → 09-04 — 🟢 **Role-by-role launch-readiness audits** — `AUDITS-ROLE/role-01…05` + `LAUNCH/master-readiness.md`. Verdict ~80% ready for service+agent one-city (Haldwani). 4 cross-role threads: T1 BASE_URL, T2 first-open weight, T3 single-provider failover, T4 legacy-table indexes.
- 2026-09-02 — 🟢 **System Map (house blueprint)** — `MAP/system-map.md`. The real system = `wtg-v2/` (branch `v2`); everything else on the desktop is docs/old/dead.

## Earlier (pre-2026-09, summarized)
- 2026-08 — 🟢 **Smart Auth Layer** (`95ba76d`) — config-aware login/OTP with channel failover (`lib/auth/`). **Payment abstraction** (`52c7e17`, migration `2026_08_26_001`) — multi-gateway `lib/payment/` (Cashfree live, Razorpay stub). **AI Agent foundation** — `core/agent/` + migrations `2026_08_27_001…010` (foundation, action log, memory, reminders, governance, people, proactive, used-tokens, budget, auth-channels).
- 2026-07 — 🟢 **V2 multi-city + media + packages foundation** — migrations `2026_07_04_001…009` (cities & `city_id`, media table, packages, expert-visit pricing, vendor profile fields).
- 2026-04 → 06 — 🟢 **V2 branched from V1** and hardened — service columns, vendor alignment, eligibility, launch stabilization, categories, FCM/notifications. (Branch `v2` off `main`.)

---
*Seeded 2026-09-06 from the recorded commits and the `WTG-SYSTEM-BRAIN` docs. Keep adding one line per significant change, newest on top.*
