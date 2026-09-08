# WorkToGo — Complete GitHub Setup Plan (new clean repo for the real system)

> **What this is:** The full A-to-Z plan to back up and properly home the **real** WorkToGo system on GitHub — in a clean new repository, cleanly separated from the old V1 repo (which stays parked). Written for a non-technical founder to follow click-by-click, precise enough for a future engineer.
> **Grounded in** the live git state @ 2026-09-06 (evidence below) + `MAP/system-map.md`, `MAP/naming-and-structure.md`, `LAUNCH/prehosting-prep.md`, `LAUNCH/final-launch-gate.md`, `CHANGELOG.md`.
> **READ-ONLY / PLANNING ONLY.** Nothing was pushed, committed, pulled, renamed, deleted, or created. Every command below is for **the founder to run and approve** — Claude Code executed none of them. Pushing only ever **copies commits up** to GitHub; it never moves or endangers a single local file.

---

## ⭐ THE ONE-PARAGRAPH ANSWER

Your real system (509 commits, the whole motherboard + service + commerce + delivery + agent + this month's 19 fix commits) **is confirmed local-only — it is NOT on GitHub yet.** The GitHub repo you connected months ago (`worktogo`) is a **frozen snapshot of the OLD V1**, stuck at 3 July 2026 (424 commits). **Recommendation: create ONE clean new private repo (`worktogo-platform`), add it as a second remote, and push your real code up.** This takes ~5 minutes, is **100% safe** (push only copies up — the worktree "caveat" does not matter for pushing), gives you an off-machine **backup** immediately, and sets up a **world-class team structure**. The old repo stays parked, untouched. After this, only the server-side steps remain for when hosting arrives.

---

# PART A — EVERY DOUBT RESOLVED (from git, with evidence)

## A1. Is the real recent work local-only? — **YES. Proven.**

| Question | Answer | Evidence (verified 2026-09-06) |
|---|---|---|
| Is the real system (`v2`) on GitHub? | **No — local only** | `git branch -r` shows **only** `origin/main`. There is no `origin/v2`. |
| Are the real fix commits on any remote? | **No** | `git branch -r --contains 7a1acde` → **empty**; `git branch -r --contains abdf386` → **empty**. (v2 tip + a Batch-1 fix — on zero remotes.) |
| How much work is local-only? | **85 commits**, spanning **2026-07-04 → 2026-09-05** | `git rev-list --count origin/main..v2` = **85**; oldest `0b56743` (2026-07-04), newest `7a1acde` (2026-09-05). That's the entire V2 build + all this month's audits/fixes. |
| Where does it physically exist? | **One disk** (this folder) + OneDrive sync | `git worktree list` → `wtg-v2` [v2]. No Git remote holds it. |

> **Plain English:** everything you and Claude built and fixed this summer lives **only on your computer** right now. If the disk died, it's gone. That's why this backup is the #1 thing to do — and it needs no hosting.

## A2. What is the old `worktogo` repo on GitHub? — **The OLD V1, frozen.**

| Fact | Value | Evidence |
|---|---|---|
| Remote URL | `github.com/akk611745-wq/worktogo.git` | `git remote -v` |
| Branch on it | only `main` | `git branch -r` |
| Commit count | **424** | `git rev-list --count origin/main` = 424 |
| Frozen at | **2026-07-03** (~2 months old) | tip `0bcd8ad`, dated 2026-07-03 |
| Relationship to real system | it's the **ancestor** of `v2` | `git merge-base v2 origin/main` = `0bcd8ad` = exactly origin/main's tip → **`v2` = all 424 old commits + 85 new ones on top** |

> **Plain English — "what is GitHub / what's obsolete":** GitHub is an online, versioned backup + collaboration home for your code. The `worktogo` repo there is a **photograph of the old V1 taken on 3 July** — it is **obsolete**. You'll **leave it parked** (don't touch it now; delete or rename it much later). The **new repo** will hold your **real, current system** and become its permanent home and backup. Because your real code (`v2`) was built *on top of* that old V1, the new repo's history will actually **include the old V1 too** — so V1 ends up safe in three places (old repo + new repo's history + your disk).

## A3. The known caveat, resolved: does the "worktree" thing endanger the push?
You may recall that your local code's Git physically lives inside the old **`wtg  live`** folder (`wtg-v2/.git` → `wtg  live/.git/worktrees/wtg-v2`). **This does NOT matter for pushing, and creates zero risk:**
- `git remote add` + `git push` only **read** your local commits and **copy them up** to GitHub. They never move files, never rename folders, never alter the worktree, never touch the old repo.
- The worktree caveat only matters if you ever **rename/move/delete the folders** (covered separately in `prehosting-prep.md §5`). **Pushing is completely safe regardless.**

---

# PART B — THE A-TO-Z PLAN

## B1. Recommendation (honest partner call): **new repo — yes.**

**Your lean (new, separate repo, old parked) is the right call.** Here's the honest reasoning, including the simpler alternative so you can decide with open eyes:

| Option | What it is | Verdict |
|---|---|---|
| **New repo** (recommended) | A fresh, clean repository that is *the real system's* home; old `worktogo` stays parked. | ✅ **Best for your goals** — zero old/new confusion, clean `main`, branch protection + team invites on a repo that means "the real thing", proper backup. |
| Push into the *existing* `worktogo` repo as a branch | 1 command, instant backup. | ⚠️ Works as a *backup*, but **mixes the real system into the repo everyone thinks of as "the old V1"** — perpetuates the exact confusion you're eliminating. Fine only as a 10-second emergency backup if you can't set up the new repo today. |
| Keep it local, "back up later" | do nothing | ❌ **Not acceptable** — 85 commits on one disk. |

### The clean name — **`worktogo-platform`** (my pick)
Your system is a *platform* (a motherboard + multiple engines + an agent), so the name is honest and instantly distinct from the parked `worktogo`.

| Name | Note |
|---|---|
| **`worktogo-platform`** ✅ **pick** | Accurate (it *is* a platform), clearly ≠ old `worktogo`. |
| `worktogo-app` | Simpler, but understates it (it's more than an app). |
| `worktogo-system` / `worktogo-core` | Fine alternates if you prefer. |

> **Even-cleaner option (optional, your call):** if you'd rather the real system own the *plain* name `worktogo`, you can **rename the old repo** on GitHub to `worktogo-legacy-v1` (GitHub auto-redirects the old link — safe, reversible), then name the new repo simply **`worktogo`**. This is the most elegant end-state, but it "touches" the old repo. Since you asked to leave the old one parked, the steps below default to **`worktogo-platform`**. *(Whichever you choose, the steps are identical — just swap the name.)*

---

## B2. THE EXACT SAFE STEPS (do these in order)

### STEP 1 — Create the new empty repo on GitHub *(founder, ~2 min)*
1. Go to **github.com** and sign in (account **akk611745-wq**).
2. Top-right **`+`** → **New repository**.
3. **Owner:** your account. **Repository name:** `worktogo-platform`.
4. **Visibility:** **Private** (recommended — it's your business code).
5. **⚠️ IMPORTANT — leave it EMPTY:** do **NOT** tick *Add a README*, *Add .gitignore*, or *Choose a license*. (An empty repo lets the first push land cleanly.)
6. Click **Create repository**.
7. On the next page, copy the **HTTPS** URL — it will be:
   `https://github.com/akk611745-wq/worktogo-platform.git`

### STEP 2 — Point your local code at the new repo & push *(founder, ~2 min)*
Open **PowerShell**, then run these (copy-paste, one block). The first line moves into your real-system folder; the path has spaces, so keep the quotes:

```bash
cd "C:\Users\akk61\OneDrive\Desktop\mastar wtg live\wtg-v2"
git remote add platform https://github.com/akk611745-wq/worktogo-platform.git
git push -u platform v2:main
```

**What each line does (nothing destructive):**
- `cd …\wtg-v2` — go to the real system.
- `git remote add platform …` — add the new repo under the nickname **`platform`**. Your old `origin` (the parked V1) is **left exactly as-is**.
- `git push -u platform v2:main` — copy your local **`v2`** branch **up** to the new repo, naming it **`main`** there (so the new repo's production branch = your real system). `-u` remembers this link so future pushes are just `git push`.

> **Why `v2:main`?** You can't rename `v2`→`main` locally (a `main` already exists — the parked V1 in the other folder). This one command maps it at push time: local `v2` → remote `main`. Clean, safe, no local rename needed.

### STEP 3 — The login prompt (handle it simply) *(founder)*
When you run `git push`, one of these happens:
- **Most likely:** nothing — your machine already has your GitHub login cached from months ago, and it just pushes. ✅
- **A browser window pops up** (Git Credential Manager) → click **Sign in with your browser** / authorize. Done. ✅
- **It asks for username/password in the terminal** → GitHub no longer accepts your account password here. Use a **Personal Access Token (PAT)** as the "password":
  1. GitHub → your avatar → **Settings** → **Developer settings** → **Personal access tokens** → **Tokens (classic)** → **Generate new token (classic)**.
  2. Note: "worktogo push"; **Expiration:** 90 days; **Scope:** tick **`repo`**. Generate. **Copy the token** (shown once).
  3. Back in PowerShell, when prompted: **Username** = `akk611745-wq`, **Password** = *paste the token*.

> A PAT is just a long one-time password for tools. Keep it private; it is **not** put in any file and never committed.

### STEP 4 — Verify the push succeeded *(founder can eyeball; Claude Code can confirm precisely)*
- **On GitHub:** refresh the new repo page — you should see all your folders (`heart/ body/ core/ lib/ …`) and **“509 commits”** on the **main** branch. ✅
- **In PowerShell (optional):**
  ```bash
  git ls-remote platform
  ```
  You should see a line ending in `refs/heads/main`. ✅

That's it — **your real system is now backed up on GitHub.**

---

## B3. WORLD-CLASS STRUCTURE (branch model + how it maps to hosting)

### Branch model on the new repo
| Branch | Role | Set up |
|---|---|---|
| **`main`** | **Production** — the live, tested code. Protected (no direct pushes; changes arrive via reviewed Pull Requests). | Created by Step 2 (from your `v2`). |
| **`staging`** | **Rehearsal mirror** — deployed to `staging.worktogo.in`; every change tested here first. | Create later, when the staging server exists: `git push platform v2:staging` (or branch from main). |
| **`dev`** *(optional)* | Integration branch for work-in-progress. A solo founder can skip this. | Optional. |
| **`feature/<name>` · `fix/<name>`** | One short-lived branch per change (e.g. `feature/vendor-payouts`). Merged via PR → `staging` → `main`, then deleted. | Per task. |

> **Legacy V1** does **not** need a branch here — the **old `worktogo` repo is** your parked V1. (And it's already inside this repo's history anyway.)

### Team workflow (so people don't step on each other)
1. In GitHub → the new repo → **Settings → Branches**, add **branch protection** on `main` (and later `staging`): require a Pull Request before merging.
2. Invite teammates: **Settings → Collaborators**.
3. Everyone works on their own `feature/*` branch → opens a PR → you review → merge to `staging` → test → merge to `main`. Nobody edits `main` directly.

### How local → GitHub → server maps (the deploy flow)
```
Your PC (wtg-v2, branch v2)  ──push──▶  GitHub: worktogo-platform (main)
                                              │  (server clones/pulls)
                                              ▼
                              staging.worktogo.in  ──test──▶  worktogo.in (prod)
```
- **Deploy:** the server runs `git pull` (or checks out a release tag). **Never edit code on the server** — the repo is the single source of truth.
- **Release tags:** tag each go-live, e.g. `git tag v2.0-launch && git push platform v2.0-launch`.
- **Rollback:** `git revert <bad-commit>` then redeploy (each fix is a clean separate commit, so any one reverts cleanly); or check out the previous tag. Data: restore the pre-migration DB backup. Feature-level: flip the kill-switch / `SERVICE_ONLY_MODE` — no deploy needed.

---

## B4. WHAT GOES UP vs STAYS LOCAL

Your `.gitignore` already protects secrets (verified). Pushing respects it automatically.

| ✅ Goes UP to GitHub (safe, wanted) | 🔒 STAYS LOCAL (never leaves your machine) |
|---|---|
| All code: `heart/ body/ core/ lib/ brain/ app/ admin/ vendor/ rider/ api/ config/ cron/ assets/` | **`.env`** (real passwords/keys) — ignored ✅ |
| `.env.example` (the key **template**, blanks only) | **`core/secrets/`** — ignored ✅ |
| `migrations/` + `migrate.php` + `.htaccess` + `index.php` | **`logs/`** + local test artifacts — ignored ✅ |
| In-repo docs (`docs/`, `plans/`) + a recommended `README.md` | *(recommend adding `uploads/*` to `.gitignore` so runtime media isn't committed)* |

> **Golden rule:** real credentials live **only** in the server's `.env`, never on GitHub. If a real secret ever lands on GitHub, rotate it immediately. *(Two safe, additive pre-push tidy-ups from `prehosting-prep.md §3`: add `MIGRATE_SECRET` to `.env.example`, add `uploads/*` to `.gitignore`. Optional — recommend, not required, for the backup.)*

---

## B5. THE FULL PICTURE — what's done after this, and what remains

### ✅ Done the moment the push succeeds
- **Off-machine backup exists** — the 85 local-only commits are safe on GitHub.
- **Clean home + identity** — the real system lives in `worktogo-platform`, old V1 parked separately.
- **Team-ready** — branch protection + collaborators + PR flow available.
- **Server-ready source** — the deploy step ("clone GitHub → server") is now *possible* (it was blocked before, because the code wasn't on GitHub).

### ⏳ Server-only steps that remain (for when hosting arrives — from `prehosting-prep.md §4`)
1. Buy hosting (VPS/managed PHP + managed MySQL, slow-query log on) + DNS via Cloudflare.
2. Obtain credentials: Razorpay, Cashfree (have), Anthropic key, MSG91 (have) + 2nd SMS provider, Google Maps, Google OAuth, FCM (only if push).
3. **Clone the new repo's `main` to staging**, create `.env` (real values incl. `MIGRATE_SECRET`), `APP_ENV=staging`.
4. **Back up DB → run `php migrate.php`** (52 migrations) → verify.
5. `EXPLAIN` the hot legacy indexes; add any missing as a dated migration.
6. Real **₹1 payment** round-trip; real **AI key** round-trip + PII/cross-user tests; wire the **2nd SMS/payment provider** (lights up failover).
7. Full role tests green → **cutover to prod** (`worktogo.in`, `APP_ENV=production`), seed real Haldwani data, turn agent kill-switch on per-capability, soft-launch.

> **The finish line, in one line:** *push now = backup + clean home + team-ready + server-ready. After that, the only work left is the server-side checklist above — no more building.*

---

## B6. WHAT CLAUDE CODE CAN DO vs WHAT YOU MUST DO

| Task | Who | Why |
|---|---|---|
| Confirm what's local vs on GitHub, cite evidence | ✅ **Claude Code** (done, Part A) | read-only git inspection |
| Write this plan + exact commands | ✅ **Claude Code** (done) | — |
| Prepare safe pre-push tidy-ups (`.env.example` `MIGRATE_SECRET`, `.gitignore uploads/*`, a `README.md`) | ✅ **Claude Code** (on your approval) | local edits, additive, no push |
| **Create the new GitHub repo** | 👤 **You** | needs *your* GitHub account (an account action Claude can't and shouldn't do) |
| **Authenticate the push** (browser / PAT) | 👤 **You** | it's your identity/credential |
| **Run the actual `git push`** | 👤 **You** *(Claude will not push)* | pushing is an outward action on your account; kept in your hands for safety |
| Verify the push afterwards (`git ls-remote`, commit count) | ✅ **Claude Code** (or you) | read-only check |
| Set branch protection + invite team | 👤 **You** | GitHub account settings |

> **In short:** Claude Code prepares everything and hands you the exact, verified commands; **you** click "New repository", sign in, and run the two push commands. Once the repo exists and you're signed in, a future session *can* run `git remote add` + `git push` **with your explicit go-ahead** — but creating the repo and signing in are always yours.

---

## Appendix — verified git evidence (2026-09-06, read-only)
- `git remote -v` → `origin = github.com/akk611745-wq/worktogo.git` (the parked V1).
- `git branch -r` → only `origin/main` (no `origin/v2`, no feature branches on GitHub).
- `origin/main`: **424 commits**, tip `0bcd8ad`, **2026-07-03** (the frozen V1).
- `v2`: **509 commits**, tip `7a1acde`, **2026-09-05**; **85 commits ahead of origin/main** (2026-07-04 → 2026-09-05) = all local-only.
- `git branch -r --contains 7a1acde` and `… abdf386` → **empty** (real commits on no remote).
- `git merge-base v2 origin/main` = `0bcd8ad` → `v2` = the full V1 history + 85 new commits (so the new repo will also preserve V1 as ancestry).
- `git worktree list` → `wtg  live`[main] (hosts `.git`) + `wtg-v2`[v2]; pushing is unaffected by this.
- **No push, commit, pull, remote change, repo creation, or file change was performed.** All commands above await the founder.

*End of plan. Companion to `MAP/naming-and-structure.md`, `LAUNCH/prehosting-prep.md`, and `CHANGELOG.md`.*
