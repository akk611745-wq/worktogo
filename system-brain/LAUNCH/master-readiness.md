# WorkToGo — Master Launch-Readiness Scorecard & Fix Plan

> **What this is:** One document that pulls together all five role audits (`AUDITS-ROLE/role-01`…`role-05`) + the agent security audit (`AGENT/agent-11`) into a single plan the founder can drive: **what's ready, what must be fixed, in what order, and the honest launch verdict.**
> **Launch target being scored:** **Service + AI-agent, one city (Haldwani).** Commerce/merchant, delivery, and video are built-but-hidden and are scored *for when they later go live*, not as launch blockers.
> **Grounded in:** real `wtg-v2` code (branch `v2`) @ 2026-09-02. Read-only — nothing changed.
> **How to read the effort tags:** **S** = hours, **M** = 1–2 days, **L** = 3+ days. "CODE" = we can do it now; "STAGING" = needs a live server; "HOSTING" = something to buy; "POST" = after launch.

---

## 1. ONE-PAGE SCORECARD

| Area | Ready | One-line honest state |
|---|:--:|---|
| **Admin control-room** | **85%** | Real, working, data-driven control room a single founder-admin can run the business from. RBAC *delegation* (limited staff logins) is built but dormant — post-launch. |
| **Vendor (service)** | **78%** | Get in → build listing → receive/run jobs → post photos all work and are hardened. **One real gap: a vendor can't see their own earnings/payout anywhere.** |
| **Customer** | **82%** | Browse → book → pay → track is correct, money-safe, IDOR-tight, and never dead-ends. Held back by weak-internet first-load weight + staging-safety config. |
| **AI Agent ("dost")** | **82%** | Strongest engineering in the system — per-role power is correct and enforced in depth; never elevates; every write confirm-gated. Real "understanding" needs a live AI key on staging. |
| **Delivery / rider** | **80%** | *(hidden at launch)* Genuinely well-built engine — lock-safe assignment, full lifecycle cron, COD accounting. Ready-ish for when commerce/delivery launches. |
| **Payment / money layer** | **85% safe / 30% failover** | Money-safety is real today (server-authoritative amount, webhook = sole authority, idempotent). But only ONE live gateway (Cashfree); failover is built, no partner wired. Needs a real ₹1 round-trip on staging. |
| **Auth / identity layer** | **85% / 30% failover** | Hardened OTP (rate-limits, hashed, fails-loud) + Google/email. Smart-auth **failover works end-to-end but has only one real SMS provider** (MSG91) — nothing to divert to yet. |
| **Motherboard (core/lib: identity, city, media, ledger, RBAC data)** | **88%** | Solid shared foundation; V2 tables well-indexed; clean engine plug-points. The reusable, expensive part is genuinely built. |
| **Performance / resilience** | **Resilience 90% · Scale-at-spike 70%** | **Failures are isolated — no cascade** (one thing down ≠ everything down). Spike-readiness pending: verify legacy-table indexes + add caching + a better hosting tier. |

### Overall system readiness for a **service + agent, one-city launch: ~80%.**
Nothing is broken or rewrite-level. The core journeys work and are money-safe. Between here and launch is **a short list of small code fixes + one round of staging validation + a hosting-tier choice** — not new building.

---

## 2. THE COMMON THREADS (fix these first — widest impact)

These four issues each showed up across **multiple** roles. They are the highest-leverage fixes because one change closes many findings:

| # | Thread | Where it appeared | Why it's top-priority |
|---|---|---|---|
| **T1** | **`BASE_URL` hardcoded to production** in the app configs | Customer (role-03), Vendor (role-02) | On staging, testing would hit **live customer data/payments**. Blocks safe testing of everything else. **Fix before any staging run.** |
| **T2** | **Weak-internet first-open weight** (render-blocking Google Maps + Firebase loaded while off + 215 KB `home.js`) | Customer (role-03), Agent (role-04, inherits shell), Perf (role-05) | This is the "customer leaves before it opens" risk on the exact cheap-phone/3G users the pilot targets. |
| **T3** | **Single-provider failover targets** — payment (Cashfree only) & OTP (MSG91 only) | Admin (role-01), Customer (role-03), Vendor (role-02), Perf (role-05) | The failover *machinery is built and correct*; it just has **no second provider wired**. Until then, that one provider is a feature-level single point of failure. Fix = config + credentials, not code. |
| **T4** | **Legacy-table indexes unverified** (`bookings`, `otps`, `jobs`, `auto_assignments` live in the inherited V1 schema, not the repo migrations) | Perf (role-05); underlies every role's hot queries | The first thing that falls over under real traffic. A 30-minute `EXPLAIN` check on staging, then add any missing index. |

> **If the founder does nothing else first, do T1 (before staging), then T2/T3/T4 during the fix round.** They cover the majority of the cross-role risk.

---

## 3. CONSOLIDATED MUST-FIX LIST (deduplicated, prioritized)

### 3A. CODE-FIXES — do now, before hosting (no server needed)

| P | Item | Why it matters | Where to fix (system-map §C) | Effort | From |
|---|---|---|---|:--:|---|
| 🔴 Critical | ✅ **DONE** (`abdf386`) **T1 — Env-drive `BASE_URL`** in `app/js/config.js`, `vendor/config.js` + 7 PHP injectors — now **fail-safe to same-origin**, never prod | Stops staging from mutating production | "config → `config/`" / app configs | S | Cust, Vend |
| 🔴 Critical | ✅ **DONE** (`f80ee49`,`f8ac74c`,`ddbcfb7`,`1026e48`) **T2 — First-open slimming**: Maps now on-demand (`WTG_loadMaps()`, loads at the address step only) · Firebase loads only when FCM configured (off at launch) · fonts via parallel `<link>` (was blocking `@import`, keeps `display=swap`) · real service-worker cache (SWR static, network-first nav, API passthrough). `home.js` is **already** page-level code-split (router uses dynamic `import()`), so with Maps/Firebase off the critical path, further *internal* home.js splitting was deferred as high-risk / low-value. Chart.js pinned separately (`8041da0`). | First paint on a cheap phone/3G; conversion | "customer surfaces → `app/`" | M | Cust, Agent, Perf |
| 🔴 Critical | ✅ **DONE** (`ca7aee3`) **Remove the `CASHFREE_ORDER_DEBUG` ERROR log** (fired on every payment) | Log noise + booking-metadata exposure on every payment | "payment → service inline order (`body/service-engine/api/services/index.php`)" | S | Cust |
| 🟠 High | ✅ **DONE** (`e7d5acd`,`40dc328`) **Vendor earnings view** — new ownership-scoped `GET /api/vendor/earnings` (reuses `PaymentReport::vendorRevenue`) + an Earnings tab in the service dashboard (net/available/pending/jobs, commission line, recent jobs w/ cash-vs-online + "quote pending") | A partner currently can't see their own money → distrust + support load | "commission/payout → `LedgerEngine`/`PaymentReport`" | M | Vend |
| 🟠 High | ✅ **DONE** (`baac240`) **Agent `commerce` capability OFF by default** (`CAP_DEFAULTS`) — admin enables in the control-room when commerce launches | Otherwise the agent offers customers a checkout the app hides | `core/agent/AgentPolicy.php` | S | Agent |
| 🟠 High | ✅ **DONE** (`35439bc`) **Last-admin / self-demotion guard** on `POST /api/admin/users/{id}/role` | An admin can accidentally lock everyone out of the panel | `heart/api/admin/index.php` | S | Admin |
| 🟡 Med | ✅ **DONE** (`c5acfa7`) **New `app_settings` keys default to `is_public=0`** | A typo'd/new governance key becomes world-readable | `core/controllers/SettingsController.php` | S | Admin |
| 🟡 Med | ✅ **DONE** (`f963877`) **Smooth vendor re-login after approval** (clean overlay instead of a blocking `alert()`) | Ugly first impression; reads like an error | `vendor/shared/auth.js` | S–M | Vend |
| 🟡 Med | ✅ **DONE** (`8041da0`, Batch 2) **Pin/self-host Chart.js** on the vendor dashboard | Weak-internet + supply-chain/version-drift risk | `vendor/dashboard-service.html` | S | Vend |
| 🟡 Med | ✅ **DONE** (`a7c332e`) **Shorten admin JWT** (30d → 12h, admin-only; customers/vendors keep 30d) | A leaked admin token stays valid a month | `heart/api/auth/AuthController.php` | M | Admin |
| 🟢 Low | ✅ **DONE** (`a197455`) **Re-enable pinch-zoom** | Accessibility/readability on cheap phones | `app/index.php` | S | Cust |
| 🟢 Low | ✅ **DONE** (`745e621`) **Fix the stale "throttle not enforced" comment** (it *is* enforced) + annotate 2 unwired vendor flags | Prevents a future engineer "re-adding" a working feature | `heart/api/admin/index.php` | S | Admin, Vend |

### 3B. STAGING-ONLY — needs a live server (validation gates)

| P | Item | Why it matters | Where | Effort | From |
|---|---|---|---|:--:|---|
| 🔴 Critical | **Run ALL 52 migrations on the target env + verify** (vendor V2 fields, agent tables incl. `agent_used_tokens`, RBAC, delivery, indexes) | Defensive code *hides* a missed migration (fields silently drop, single-use token degrades) | "schema → `migrations/` via `migrate.php`" | M | Vend, Agent, Perf |
| 🔴 Critical | **T4 — `EXPLAIN` the hot legacy queries + add any missing indexes** (`bookings.user_id`, `bookings.client_request_id`, `otps.phone`, `jobs.booking_id`, `auto_assignments(entity_type,entity_id)`) | First bottleneck under real traffic | "schema → `migrations/`" (new dated migration) | M | Perf |
| 🔴 Critical | **Real ₹1 Cashfree round-trip** (sandbox + one live ₹1): confirm webhook flips `paid`, return page never self-authorizes, credential-guard shows the friendly error | Proves the money path end-to-end on real infra | "payment → `lib/payment` + webhook" | M | Cust |
| 🔴 Critical | **Real AI key on staging + live agent round-trip**: a real booking via chat, a **PII-block negative test** (send a phone number → expect 422), a **cross-user negative test** (can't act on another user's data), and **zero-retention provider terms** | The agent only "understands" with a real key; these prove the wall + no-elevation on real infra | "AI → `core/agent` / `PiiWall`" | M | Agent |
| 🟠 High | **T3 — Wire a real 2nd OTP provider** (+ set `routing.sms.secondary`); enable the payment secondary (Razorpay) if ready | Gives the built-in failover somewhere to divert to | "OTP → `lib/auth` / `config/auth.channels.php`"; "payment → `config/payment.gateways.php`" | M | Auth, Pay, Perf |
| 🟠 High | **Confirm all env creds set**: `GOOGLE_MAPS_API_KEY`, `CASHFREE_*` (live), `MSG91_*` | Missing keys silently degrade (address entry, payments, OTP) | "config → env" | S | Cust, all |

### 3C. HOSTING / INFRA — what to buy (a tier choice, not building)

| Item | Why | Priority |
|---|---|:--:|
| **App tier:** move off shared hosting → a small **VPS or managed PHP app platform** | Predictable CPU + worker count; the concurrency ceiling today is shared-hosting | 🔴 |
| **Managed MySQL/MariaDB** with tunable `max_connections`, automated backups, **slow-query log ON** | Removes the DB single-point fragility; makes T4 easy to monitor | 🔴 |
| **CDN** in front of `/app` static assets + `/uploads` media | First-load speed + the "provider-agnostic media" switch the code already anticipates | 🟠 |
| **Real cron runner** (not a fragile web-cron) for `auto_assign` + `delivery_lifecycle` + agent crons, with missed-run alerting | Assignment/settlement/reminders depend on crons actually running | 🟠 |
| **Small read cache** (APCu already present; Redis later) for the catalog | Shields MySQL on a traffic spike | 🟡 |

### 3D. POST-LAUNCH — deliberately deferred (not launch blockers)

- **RBAC delegation** — wire the built-and-seeded roles to real limited-admin logins (login gate + `requireRole` + JWT role source). *The moment you need a 2nd, limited operator.* (Admin)
- **Vendor/project earnings depth & project-based work** — richer earnings, multi-visit/project tracking and payout for project-style jobs beyond single bookings. (Vendor)
- **Commerce / merchant self-serve** — Amazon-seller-style store/product/inventory/payout (today commerce is admin-managed and hidden). (Vendor, Admin)
- **Notifications + realtime tracking** — FCM + live status for customer & rider (off at launch). (Cust, Delivery)
- **Delivery go-live tasks** — rider self-serve onboarding + in-app cash-deposit reconciliation + tracking-token check. (Delivery)
- **Agent depth** — voice, more languages, NER-grade PII detection, a 2nd AI provider for understanding-failover. (Agent)
- **Customer account depth** — address book, receipts/invoice history, self-serve refund, refresh-token session continuity. (Cust)
- **Housekeeping** — resolve the two product-systems ambiguity, remove dead feature-flags, upper-clamp expert-visit amount, optimistic-concurrency on settings, N+1 cleanups, video engine. (Various)

---

## 4. LAUNCH-READINESS VERDICT

**Is the system launch-ready after the code-fixes + staging validation? — Yes, for a service + agent, one-city pilot.**

The honest picture: **the hard, expensive, easy-to-get-wrong things are already done right** — money-safety (server-authoritative amounts, webhook-as-sole-authority, idempotency, single-use tokens), identity & IDOR (every surface scopes to the real user), the agent's safety model (never elevates, confirm-gated writes, fail-closed privacy wall), lock-safe assignment, and a control-room the founder can actually run the business from. **Failures are isolated — one provider or component going down does not take the platform down.** That is the genuinely hard part, and it holds.

What remains is **not building — it's finishing and proving:** a dozen small code fixes (staging-safety, first-open speed, a vendor earnings screen, a few guards), then one disciplined staging pass (migrations, index check, a real ₹1 payment, a real AI-key round-trip, a second provider wired), on a proper hosting tier.

**Realistic path & rough effort to "service + agent live in one city":**
- **Code fix round (3A):** ~**3–5 focused engineering days.**
- **Stand up staging + validate (3B) on the bought tier (3C):** ~**2–4 days**, plus the founder's external sign-ups (a backup SMS provider, Cashfree live account, an AI key, the hosting/DB).
- **Total: ~1.5–2 weeks of focused work** to a confident one-city launch, gated by the staging checklist going green.

**Confidence:** high that the pilot will run well and stay up under pilot load; the open items are known, small, and mostly verification. The main things that could still surprise are only visible on real infrastructure (payment round-trip, index behavior, AI understanding) — which is exactly why the staging pass is the gate.

---

## 5. RECOMMENDED FIX SEQUENCE (batched — approve and we execute)

**Batch 1 — Staging-safety + quick hygiene (½–1 day, all code, low-risk).**
✅ **Non-behavioral subset LANDED on `v2` (2026-09-04):** T1 `BASE_URL` env-drive → same-origin (`abdf386`) · remove Cashfree debug log (`ca7aee3`) · fix stale throttle comment + annotate unwired vendor flags (`745e621`).
⏳ **Still pending (behavioral guards — a separate, small "guards" pass, deliberately not bundled with the no-behavior-change hygiene commits):** `is_public=0` default for new settings · last-admin/self-demotion guard · `agent_capabilities={"commerce":false}` (a control-room setting, done on the running system, not in repo) · re-enable pinch-zoom · shorter admin JWT.
→ *Staging is now safe to point at and the sharp edges are gone; the guards land next.*

**Batch 2 — First-open speed (1–2 days, code) [T2]. ✅ LANDED on `v2` (2026-09-04).**
Maps on-demand (`f80ee49`) · Firebase-only-when-configured (`f8ac74c`) · fonts via parallel `<link>` (`ddbcfb7`) · pin+guard Chart.js (`8041da0`) · real SW cache (`1026e48`). Pages were already dynamically imported, so `home.js` internal splitting was deferred (high-risk, low-value once Maps/Firebase are off the critical path).
→ *The app now opens fast on a cheap phone: no render-blocking Maps, no Firebase weight at launch, text paints immediately, repeat opens are cached.*

**Batch 3 — Vendor trust + polish (1–2 days, code). ✅ LANDED on `v2` (2026-09-04).**
Vendor earnings endpoint + dashboard tab (`e7d5acd`,`40dc328`) · re-login overlay (`f963877`) · the 5 deferred guards: last-admin (`35439bc`), is_public=0 (`c5acfa7`), agent-commerce-off (`baac240`), pinch-zoom (`a197455`), admin-JWT-12h (`a7c332e`).
→ *Partners can see their money; the panel can't be locked out; new settings can't leak; the agent won't offer hidden commerce; the vendor first-run feels right.*

**Batch 4 — Stand up staging & validate (needs the hosting tier) [T3, T4].**
Provision app tier + managed DB (3C) · run **all** migrations · `EXPLAIN` + add indexes · set all env creds · **real ₹1 payment round-trip** · **real AI-key round-trip + PII/cross-user tests** · wire a **2nd OTP provider**.
→ *Every "only-provable-on-a-server" gate is green.*

**Batch 5 — Go live, one city.**
Keep the agent kill-switch OFF until Batch 4 passes, then enable **per-capability** as each is validated · soft-launch Haldwani · watch the slow-query log + the delivery/assignment/agent monitors.

> **One-line for the founder:** *The system is ~80% ready and — importantly — the hard 80% (money-safety, identity, agent safety, failure-isolation) is the part that's done. Approve Batches 1–3 (about a week of code), then we prove it on staging (Batch 4) and launch one city. Nothing here is a rewrite; it's finishing, wiring a backup provider, and buying the right hosting.*

---

## FIX LOG

- **2026-09-04 — Batch 1 (staging-safety + hygiene) partial:** landed the three no-behavior-change items on branch `v2` as separate commits — `abdf386` (BASE_URL env-drive → same-origin, 9 files), `ca7aee3` (remove `CASHFREE_ORDER_DEBUG`), `745e621` (stale throttle comment + unwired-flag annotations). Local only — not pushed/deployed. JS syntax-checked; no PHP runtime locally. Behavioral guards from Batch 1 (is_public default, last-admin guard, pinch-zoom, admin JWT) intentionally deferred to a dedicated guards pass.
- **2026-09-04 — Batch 2 (first-open speed) landed:** 5 commits on `v2` — `f80ee49` (Google Maps → on-demand `WTG_loadMaps()`, no longer render-blocking; home.js's 2 Maps consumers await it, keep graceful fallback), `f8ac74c` (Firebase SDK emitted only when FCM keys are set — ~100 KB off first open at launch), `ddbcfb7` (webfonts via parallel `<link>` instead of a blocking `@import`, `display=swap` kept), `8041da0` (pin Chart.js 4.4.1 UMD + defer + CDN-miss guard, both vendor dashboards), `1026e48` (real service-worker: SWR for static, network-first nav, API/auth/cross-origin passthrough). `home.js` was already page-level split via router dynamic `import()`; deeper internal splitting deferred as high-risk/low-value. Local only; JS syntax-checked; **first-open not measured live (no PHP runtime locally — needs staging).** Next up: **Batch 3 — vendor earnings view + the deferred guards + polish**.
- **2026-09-04 — Batch 3.5 (vendor panel stitch) landed:** 3 commits on `v2` closing the final-gate loose end. `32abf62` — unified the vendor panel: both `dashboard-*.html` now read `wtg_vendor_token` (the key login+onboarding set) and bounce to `index.php`, and all login/OTP/revisit/onboarding redirects converge on the coherent dashboard → **Earnings reachable for returning vendors; the post-onboarding `/vendor/login.html` 404 is gone.** `1ee9fb2` — committed a complete `.env.example`. `7a1acde` — removed dead `app/js/map.js`. Verified in-browser (no console errors) + end-to-end code trace. **Code-side is now finished for the service+agent scope.** Next: **Batch 4 — staging on budget/hosting.**
- **2026-09-04 — Batch 3 (vendor earnings + guards + polish) landed:** 8 commits on `v2`. Earnings: `e7d5acd` (new ownership-scoped `GET /api/vendor/earnings`, reuses `PaymentReport::vendorRevenue`, + registered the route prefix in `heart/index.php`) and `40dc328` (Earnings tab in `dashboard-service.html` — verified in-browser: renders, ₹ formats en-IN, degrades gracefully with no backend, no console errors). Guards: `35439bc` (last-admin/self-demotion lockout), `c5acfa7` (new settings `is_public=0`), `baac240` (agent `commerce` default OFF), `a197455` (pinch-zoom re-enabled), `a7c332e` (admin JWT 30d→12h, admin-only). Polish: `f963877` (re-login overlay replaces `alert()`). **No migration needed** — the earnings view reads existing `vendor_wallets`/`wallet_transactions`/`bookings`; the `wallet_transactions(entity_type,entity_id)` index check is folded into Batch 4's `EXPLAIN` pass. Local only; JS syntax-checked. Next up: **Batch 4 — stand up staging & validate**.

---
*Synthesis of `AUDITS-ROLE/role-01…role-05` + `AGENT/agent-11-readiness-audit.md`, grounded in `wtg-v2` @ 2026-09-02. The audit itself changed no code; the FIX LOG above tracks the follow-up fix batches. Companion to `MAP/system-map.md`.*
