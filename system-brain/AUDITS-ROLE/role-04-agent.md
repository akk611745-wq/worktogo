# Role Audit 04 — AGENT (per-role, deep stress-test)

> **Scope:** Launch-readiness deep-check of the **agent ("dost") as a role experience** — how it serves each kind of user and what power each holds — grounded in real `wtg-v2` code (git branch `v2`) as of **2026-09-02**.
> **Method:** Read the AGENT plan docs (`agent-01`…`agent-11` + `agent-status-report`) then ground everything in code: `core/agent/**` (orchestrator, planner, PII wall, policy, tools, confirm-token, AI providers), `heart/api/agent/index.php`, `app/agent/**`. Cross-read `role-01/02/03` for continuity (the OTP/auth layer, the vendor-agent tools, and the admin agent control-room were already audited there).
> **This is NOT a repeat of the security audit.** `agent-11-readiness-audit.md` already did banking-grade security (wall/AI-control/money) and its two blockers are fixed. **This audit is the ROLE lens:** per-role power correctness, the agent-as-each-user journey, register-gate, real-world messy users, and per-role control/safety.
> **Read-only.** Nothing edited/moved/renamed/deleted. `shopping-engine`, `video-engine`, `orders.js` read only. "Where to fix X" cites `MAP/system-map.md` §C.
> **Verdict up front:** The agent's **role architecture is world-class** — the per-role power split is correct and enforced in depth (the AI can only ever see and reach its own role's tools; the agent never elevates; every write is confirm-gated with a deterministic, single-use token; the backend re-authorizes the *real* user). Role-experience readiness is **~82% (with a real AI key on staging)**. The gaps are **operational, not architectural**: (1) commerce tools are exposed to customers by default even in a service-only launch, (2) messy/low-literacy input only shines with a real AI key (deterministic rules cover the common asks), and (3) it inherits the customer app's heavy shell on weak internet. **Everything material is honestly documented in the team's own `agent-11` audit; this confirms it from the role angle.**

---

## 0. The five structural guarantees every role inherits (grounded)

Before the per-role view, these are the invariants that make the whole thing safe **for every role** (verified in code, and consistent with `agent-11`):

1. **Register-gate.** `POST /api/agent/message` starts with `AuthMiddleware::require()` — no JWT ⇒ 401 (`heart/api/agent/index.php:74`). The chat UI also `requireAuth()`s (`app/agent/agent.js:46`).
2. **Kill-switch first.** `agent_enabled` defaults **OFF** → 503; the agent never spends or calls out until the founder enables it (`index.php:83-85`, `AgentPolicy` `CAP_DEFAULTS`/entry).
3. **The AI never acts — it only proposes `{tool, args}`.** Our code checks the tool against the **role allow-list**, cleans args to a **typed deny-by-default allow-list**, and runs it through the **real backend**, which authorizes (RBAC/ownership/city) (`AgentTool.php:9-21`, `AgentActionOrchestrator.php:123-177`).
4. **No elevation.** The entry forwards the caller's **own JWT** to every backend tool call, so the engine authorizes the *real* user — the agent has no privileges of its own (`index.php:113-123`).
5. **Fail-closed privacy wall.** Every provider call goes through `PiiWall`, which scrubs PII to placeholders and **re-scans the exact outbound bytes**, throwing `PiiLeakException` (safe 422, nothing sent) if anything still matches (`PiiWall.php`, `index.php:136-152`).

---

## 1. PER-ROLE POWER MATRIX

Source of truth: each tool's `allowedRoles()` + `mode()` (`core/agent/tools/*`), registered in `ToolRegistry::defaultRegistry()`. The AI is only ever shown `catalogForRole($role)` and the orchestrator refuses any tool not `isExposedTo($role)` — **twice** (at dispatch and again at confirmed execution).

| Tool | Group | Mode | CUSTOMER | VENDOR_SERVICE | VENDOR_SHOPPING | ADMIN |
|---|---|---|:--:|:--:|:--:|:--:|
| find_vendors | service | read | ✅ | — | — | ✅ |
| get_vendor_profile | service | read | ✅ | — | — | ✅ |
| get_packages | service | read | ✅ | — | — | ✅ |
| get_expert_visit_price | service | read | ✅ | — | — | ✅ |
| **create_booking** | service | **write** | ✅ | — | — | ✅ |
| **request_expert_visit** | service | **write** | ✅ | — | — | ✅ |
| browse_products | commerce | read | ✅ | — | — | ✅ |
| get_product | commerce | read | ✅ | — | — | ✅ |
| view_cart | commerce | read | ✅ | — | — | ✅ |
| add_to_cart | commerce | read* | ✅ | — | — | ✅ |
| list_orders | commerce | read | ✅ | — | — | ✅ |
| track_order | commerce | read | ✅ | — | — | ✅ |
| **checkout** | commerce | **write** | ✅ | — | — | ✅ |
| list_people | commerce | read | ✅ | — | — | ✅ |
| get_person | commerce | read | ✅ | — | — | ✅ |
| add_person | commerce | read | ✅ | — | — | ✅ |
| save_person | commerce | read* | ✅ | — | — | ✅ |
| **order_for** (gift) | commerce | **write** | ✅ | — | — | ✅ |
| list_my_jobs | service | read | — | ✅ | — | ✅ |
| get_my_profile | service | read | — | ✅ | — | ✅ |
| start_listing | vendor | read | — | ✅ | ✅ | ✅ |
| **save_listing** | vendor | write | — | ✅ | ✅ | ✅ |
| my_status | vendor | read | — | ✅ | ✅ | ✅ |
| **set_reminder** | vendor | write | — | ✅ | ✅ | ✅ |
| **upload_work_photo** | vendor | write-ish | — | ✅ | ✅ | ✅ |
| list_reminders | (per-tool) | read | ✅ | ✅ | ✅ | ✅ |
| verify_phone | (widget) | read | ✅ | ✅ | ✅ | ✅ |

\* `add_to_cart`/`save_person` are declared `read` on purpose — "light, reversible, no money → runs freely" (no confirm). Only money/booking-shaped writes (`create_booking`, `request_expert_visit`, `checkout`, `order_for`, `save_listing`, `set_reminder`) require confirmation.

**Is the split correct + safe?**
- **Customer** gets discovery + booking + expert-visit + commerce + gifting-for-contacts + reminders. **Cannot** reach any vendor tool (jobs, listing, work-photos) — verified: those tools list only `vendor_*` roles, and `isExposedTo` returns false for `customer`.
- **Vendor** gets jobs, profile, conversational listing, status, reminders, work-photo upload. **Cannot** reach customer booking/commerce/gifting. (Minor asymmetry: `list_my_jobs`/`get_my_profile` are `vendor_service`-only; a `vendor_shopping` gets listing/status/photo but not those two — consistent with shopping vendors not having service jobs.)
- **Admin** sees **everything** (`isExposedTo` returns true for `admin`, `ToolRegistry.php:47`). This is broad, but low-risk: admin already has full power via the panel, every write is still confirm-gated, and the backend authorizes with the admin's own token (a booking would be created as the admin's user). Worth noting, not a blocker.

**RBAC enforcement — CONFIRMED in depth:**
- The AI is only shown the **role catalog** (`catalogForRole`) — it cannot even *name* a tool outside its role.
- `dispatchProposal` refuses a non-exposed tool → "Sorry, I can't do that." + audit `rejected` (`AgentActionOrchestrator.php:129-137`).
- `executeConfirmed` **re-checks** `isExposedTo` and `mode==='write'` at execution time — defence in depth against a token minted for a since-revoked role (`:195-205`).
- The **backend endpoint** re-authorizes with the caller's JWT (a vendor tool hits a `requireRole(vendor)` endpoint; a customer's forwarded token would be rejected there too). Triple-checked: catalog → orchestrator → backend.

---

## 2. THE AGENT AS EACH USER (journeys)

### 2A. CUSTOMER talking — **works, friend-like, safe**
- *"painter chahiye"* → the **deterministic** planner maps the category keyword → `find_vendors` with **no AI call** (`ToolPlanner.php:120-136`), returns real vendors (OUR data, never sent to the AI). *"expert visit book karo"* → `request_expert_visit` (write) → a **deterministic confirm card** ("Book a paid expert visit for ₹299?") → on confirm, the real booking + Cashfree path runs (server-authoritative amount, role-03 §1C). *Commerce* (`browse_products`→`checkout`) and *gift-for-contact* (`list_people`→`order_for`) work the same way: read tools run, the money write is confirm-gated, **the agent never sets an amount** (COD/server-authoritative, `agent-11` money PASS). *Memory/personalization*: a returning user gets a personalized Hindi greeting composed **from our DB** (`AgentMemory::personalizedGreeting`), and only **3 anonymized hint tokens** (`returning`, `pref_category:<slug>`, `area_known`) may ever cross the wall.
- **Gift/recipient privacy:** `order_for` is **same-city only** and the recipient's name is pushed onto the wall's deny-list (`setContactTerms`) so it is tokenized to `<PERSON_n>` before any AI sees the turn (`AgentActionOrchestrator.php:64-71`). Recipient PII stays behind the wall.

### 2B. VENDOR talking — **works, admin-approval intact** (audited role-02 §1E)
- `start_listing` (Hindi stepper) → `save_listing` writes a **draft** the admin still approves; `my_status` composes profile+analytics+photos; `set_reminder`; `upload_work_photo` (inline widget). The agent proposes; the human/admin decides. Confirmed there and here.

### 2C. Dead-ends / confusion?
- On a tool/backend failure the orchestrator returns a **friendly error + `degraded:true`** ("couldn't do that right now"), never a stack trace or silent hang (`respondFromResult:236-243`). On budget-pause it degrades to deterministic-only. The one genuinely weak spot is **fully garbled input with the AI paused/unavailable** → a generic "How can I help?" (§4). Not a crash, but not helpful.

---

## 3. REGISTER / LOGIN VIA AGENT

- **The agent is register-GATED, not a register-CHANNEL.** You need a JWT to talk to it at all (`index.php:74`); the chat UI bounces an unauthenticated user to login (`agent.js:46`). So **first-touch signup still happens in the app** (OTP/Google/email, role-03 §1A) — the agent is for *already-identified* users.
- **Inline phone verification exists** for the *within-a-flow* case: `verify_phone` (any registered role) returns an **inline OTP widget** (`type:otp`) wired to the **existing** `/api/auth/otp/send` + `/verify` routes (`WidgetTools.php:48-70`). The deterministic planner even triggers it on "verify"/"otp" phrasing (`ToolPlanner.php:76`). So if a step needs a verified number, the agent guides the user through OTP **inline in chat** — no page bounce, no dead-end.
- **Failure recovery (OTP fails / provider down):** the OTP widget calls the same hardened endpoints audited in role-02/03 — per-phone + per-IP limits, **fails loud** in prod. In chat that surfaces as the widget's error state ("couldn't send, try again"); the customer can also fall back to the app's Google/email login. *Caveat:* the recovery is the widget/login offering alternates, not an automatic channel switch (same root as role-02 §4-E1).

---

## 4. REAL-WORLD USER VARIETY (the founder's point)

**Does it genuinely reduce complexity for confused / low-literacy / impatient / non-tech users?**

- **The deterministic layer is the hero for the common case.** "painter", "plumber", "bijli"/"electrician", "cart", "order", "status", "reminder", "photo" — all map to the right tool with **zero AI**, in **Hinglish**, with warm Hindi confirmations (`ToolPlanner.php:69-150`). A non-tech user typing one word in Roman Hindi gets a real result. This is the founder's "system-first, AI optional" made concrete and it's genuinely good.
- **Messy/garbled/ambiguous input is where the real AI key matters.** If the deterministic rules don't match and the AI is available, the AI interprets the mess (through the wall) and proposes a tool. If the AI is **paused (budget) or unconfigured (no key / Mock)**, an unmatched message falls to a generic "How can I help?" — **not a dead-end, but not the hand-holding a confused user needs.** So: *common asks are robust without AI; genuinely messy asks need a live key.*
- **Multi-language:** the deterministic keywords are Hindi/English/Hinglish; replies are Hindi-first (emoji-warm). **Beyond Hindi/English (e.g. Kumaoni, Punjabi free-text) real understanding relies on the AI provider.** No translation layer of its own.
- **Where it can get "stuck":** a low-literacy user who types a long, unstructured sentence with the AI paused; or a user whose intent isn't a registered category keyword. It won't crash or loop — it will under-serve. The mitigation is a live AI key + budget, plus (post-launch) a few more deterministic synonyms.

---

## 5. CONTROL & SAFETY (per-role recap — all roles governed by one control-room)

- **Kill-switch** (`agent_enabled`) gates **every** role at the entry (§0). **Off by default.**
- **Capability toggles** pause a whole group for **all** roles: `service` / `commerce` / `vendor` / `writes` (`AgentPolicy` `CAP_DEFAULTS`, `groupForTool`, `policyBlock` at both dispatch and confirmed-execution). A paused group returns a friendly Hindi "paused, try later" — no error.
- **Budget** has a **safe non-zero default** — `monthly_cap_usd = $20, auto_pause = true` (audit M1) — so a real key **cannot** run away; at the cap the planner drops to **deterministic-only** (`aiAllowed=false`), which still serves the common asks.
- **Rate limits** per-user (`msg_per_min=30`) and per-session (`actions_per_session=20`); `max_message_chars=2000`.
- **Monitor** (read-only) surfaces per-role activity, PII-block counts, provider usability, budget spend (admin `agent.php`, audited role-01 §1-11).
- **Privacy wall holds per role**: phone/email/UPI/address + the user's **own name and saved-contact names** (recipient/vendor names) are scrubbed to placeholders and re-checked by the fail-closed tripwire; the AI reasons over `<PERSON_n>`/`<PHONE_n>`. **Residual (documented):** bare city/locality *words* aren't tokenized (wants NER) — `agent-11` L-level, low risk for a single-city pilot.
- **Writes confirmed for every role**: HMAC-signed, uid-bound, **10-minute TTL**, **single-use** (`agent_used_tokens` atomic claim → replay-proof), with a **deterministic confirm summary composed by our code, not the AI's wording** (`ConfirmToken.php`, `AgentActionOrchestrator.php:149-165`).
- **AI can't act uncontrolled for any role:** allow-list + RBAC (×2) + deny-by-default args + backend re-auth + no elevation. **Confirmed.**

> **The one control-room gap for a service-only launch:** capability defaults are **all `true`** (`service/commerce/vendor/writes`, `AgentPolicy.php:30`). So on day one the agent will happily offer a customer **`browse_products`/`checkout`/`order_for`** even though the storefront UI is hidden by `SERVICE_ONLY_MODE`. The backend `/api/shop/*` would likely return empty (dormant engine) → a soft "no products" rather than a broken flow, **but** exposing a checkout path the rest of the product hides is a consistency risk. **Fix is one setting:** set `agent_capabilities = {"commerce": false}` (and `vendor:false` if the vendor-agent isn't launching) in the control-room for a service-only launch. (§7-E7.)

---

## 6. DEVICE / UX REALITY (India)

- **The chat itself is light:** `agent.js` (~59 KB) + `agent.css` (~12 KB), **no extra CDN libs** (no chart/map lib of its own). Inline cards (confirm/otp/upload) are hand-rolled. Good.
- **But it lives inside the customer SPA**, so it inherits that shell's weight — render-blocking Google Maps + Firebase + GSI + Cashfree (role-03 §5). On a cheap phone/weak net the **first load** is the app's, not the chat's.
- **Per-turn cost:** each message is one `POST /api/agent/message` round-trip; the shared API layer has a 10 s timeout + offline detection, so a bad network shows "Request timed out / No internet", not a hang. Deterministic planning is server-side and fast (no external call) — so common asks stay snappy even without the AI.
- **Degrades well:** AI down/unconfigured → Mock secondary → deterministic reply (never an error); budget paused → deterministic-only; tool fails → friendly `degraded` message.
- **⚠ Staging-only:** to see the agent *understand* (not just keyword-match), staging needs a **real AI key** (Anthropic is the live adapter; OpenAI/Gemini are honest stubs). With no key it runs on the **Mock** (echo) — perfect for proving the wall/pipeline, useless for real language. This is the founder's staging flag.

**Device verdict:** the chat is a light layer on a heavy shell; it degrades gracefully and stays fast for the deterministic common case, but the *AI experience* is only real with a live key + budget on staging, and the first-load weight is the app's (fix in role-03 §M4).

---

## 7. EDGE CASES & FAILURE MODES (broken on paper)

- **E1 — AI provider down / garbage output.** *Symptom:* failover to secondary → **Mock** → deterministic reply; a malformed AI proposal that names an unexposed/unknown tool is **refused** by the allow-list (it can't smuggle a capability). *Fix:* n/a (degrade-don't-die by design); add a real secondary provider for genuine understanding failover (system-map §C "AI wrong/leaks → PiiWall / core/agent").
- **E2 — Budget pause mid-chat.** *Symptom:* `aiAllowed=false` → deterministic-only; the current write's confirm still executes (token already issued), but **new** AI planning stops. Re-checked at confirm too (`policyBlock`). No dead-end for common asks. *Fix:* raise the cap in the control-room (role-01 §1-11).
- **E3 — A tool's backend fails.** *Symptom:* `respondFromResult` returns a friendly error + `degraded:true`, audited `failed` with the HTTP status. The user sees "couldn't do that right now", not a crash. *Fix:* the underlying engine (system-map §C by symptom).
- **E4 — Confirm-token expiry / replay.** *Symptom:* expired (>10 min) or tampered → "That confirmation has expired or is invalid." Replay of a used token → "Yeh action pehle hi ho chuka hai" (single-use, `agent_used_tokens`). **No double booking/charge.** *Fix:* ensure migration for `agent_used_tokens` is applied (else single-use degrades to stateless — `agent-11` H1) (system-map §C "schema → migrations/").
- **E5 — Role mismatch (token minted, role changed).** *Symptom:* `executeConfirmed` re-checks `isExposedTo` at execution → refused if the role no longer allows it. Backend re-auth is the final gate. Handled.
- **E6 — Concurrent actions / double-tap confirm.** *Symptom:* the single-use token PK claim makes the second click a no-op ("already done"); booking/commerce also have their own dedup (role-03 §4-E3). Handled.
- **E7 — Commerce exposed in a service-only launch.** *Symptom:* a customer asks the agent to "order/checkout" and reaches a commerce path the app hides. *Fix:* set `agent_capabilities={"commerce":false}` in the control-room (§5) — `heart/api/admin` settings / role-01 §1-11.
- **E8 — Register-gate bypass attempt.** *Symptom:* no JWT → 401 at entry; the chat UI also gates. No anonymous agent access. Handled.
- **E9 — PII in a message (phone/address/contact name).** *Symptom:* scrubbed to placeholders; if a variant slips scrub, the **tripwire** blocks the whole turn (safe 422 "try again without sensitive details"), logged as `pii_blocked`. Residual: bare city words (documented). *Fix:* `core/agent/PiiWall.php` (system-map §C "AI leaks → PiiWall").
- **E10 — No real AI key on prod/staging.** *Symptom:* runs on Mock → deterministic-only; messy input under-served. *Fix (staging):* set a real key + zero-retention terms; run the live round-trip.

---

## 8. HONEST VERDICT

### Is the agent role-experience launch-ready (with a real AI key on staging)?

**Yes for the architecture and the common-case journey — ~82% role-ready, pending staging validation.** The per-role power model is, frankly, **exemplary**: the AI can only see and reach its own role's tools; it never elevates; every write is confirm-gated with a deterministic, single-use, uid-bound token; the backend re-authorizes the real user; and the whole thing degrades to deterministic-only rather than dying. The team's own `agent-11` audit already closed the security blockers; nothing in the **role** lens contradicts that — it reinforces it.

What keeps it from "done" is **operational, not structural**: capability defaults expose commerce in a service-only launch, messy/low-literacy input needs a live key to shine, and the chat rides the app's heavy shell.

### Readiness by dimension
- Per-role power correctness + RBAC enforcement: **~95%**
- Customer journey (service/expert-visit/commerce/gift/memory): **~85%**
- Vendor journey (listing/status/reminders/photo): **~85%** (role-02)
- Register-gate + inline OTP: **~85%**
- Real-world messy/multi-language handling: **~70%** (deterministic strong; messy needs live AI)
- Control & safety (all roles, one control-room): **~92%**
- Device/UX on cheap phone + weak net: **~68%** (inherits app shell)

### Biggest gaps / risks (ranked)
1. **Commerce (and maybe vendor) capability exposed by default** in a service-only launch (§5, §7-E7) — a one-setting control-room fix, but a real day-one inconsistency.
2. **Messy/low-literacy input depends on a live AI key** (§4) — without it, only keyword asks are served well.
3. **No real AI understanding until staging has a key + budget + zero-retention terms** (§6, §7-E10) — the founder's staging flag.
4. **Chat inherits the app's heavy first load** (§6) — fixed via role-03 §M4.
5. **Bare city-word PII residual** (§5, §7-E9) — documented, low risk for a single-city pilot; wants NER later.
6. **`agent_used_tokens` migration must be applied** or single-use silently degrades (§7-E4).

### Must-fix **before** launch
- **M1.** In the control-room set `agent_capabilities` to match the launch surface — `{"commerce": false}` for a service-only launch (and `vendor:false` if the vendor-agent isn't launching). (§5)
- **M2.** **Staging with a real AI key**: point at the live Anthropic adapter, confirm zero-retention provider terms, and run a **live round-trip** — a real booking via chat, a PII-block negative test (send a phone number → expect the 422), and a **cross-user 404** (confirm a tool can't act on another user's data). (§6, §7)
- **M3.** Apply the agent migrations (`agent_used_tokens`, memory, reminders, demand — `…001–009` per `agent-11`) so single-use, memory, and reminders are live — verify, don't assume.
- **M4.** Keep the kill-switch OFF until M1–M3 pass; enable per-capability as each is validated.

### Fine to defer **post-launch**
- More deterministic synonyms + a light clarify-question path for unmatched input (reduce reliance on the AI for messy asks).
- NER-grade name/locality detection in the wall (close the bare-city residual).
- Additional languages beyond Hindi/English; a real secondary AI provider for understanding-failover.
- Trim the shared app shell (role-03 §M4) so the chat's first paint is light on weak internet.

**One-line verdict:** *The agent's role model is the strongest engineering in the system — correct per-role power, enforced in depth, never elevating, every write confirm-gated and replay-proof, and it degrades instead of dying. It is launch-ready in architecture; before flipping the switch, match its capabilities to the service-only surface, prove it on staging with a real key (live round-trip + PII-block + cross-user negative tests), and run the migrations. The messy-user magic and multi-language depth arrive with that live key.*

---
*Grounded in `wtg-v2` @ 2026-09-02. Read-only audit — nothing modified. Companion to `MAP/system-map.md`, `role-01-admin.md`, `role-02-vendor.md`, `role-03-customer.md`, and the team's `AGENT/agent-11-readiness-audit.md`. Next: role-05 (rider/delivery) completes the 5-role stress-test.*
