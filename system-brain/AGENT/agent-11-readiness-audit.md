# WorkToGo Agent — Readiness & Security Audit (banking-grade, read-only)

> **What this is:** A rigorous, honest, code-grounded audit of the whole agent (Phases 1–9, commits `c1a33af`→`5016b81` on branch `v2`) before launch. Every finding cites real code. Nothing was changed — this is one report.
> **Method:** Read the full agent scope (`core/agent/**`, `heart/api/agent`, `heart/api/admin/agent.php`, `app/agent/**`, the two crons) plus the FCM/alerts/settings/rate-limit primitives it reuses. Tried to BREAK the wall, the AI control, the money path, and the id-scoping on paper.
> **Bottom line up front:** The agent is **genuinely engineered, not a demo** — it has real structural guarantees. But it is **not yet safe to point at a real external AI with real customer data** at the founder's "no PII ever" bar: the privacy wall's *architecture* is sound and fail-closed, yet its *PII detection is regex-only* and misses free-text names, street addresses, and separator-formatted phone numbers. That, plus a replayable confirm-token, are the launch blockers. The **money path is safe today** (COD, server-authoritative, agent never moves money).
> **Date:** 2026-08-31. **Verified against:** live code on `v2` (not executed — no PHP runtime locally; logic + self-tests read directly).
>
> **▶ UPDATE 2026-09-01 — Security Fix Phase 1 landed:** both launch blockers are fixed on `v2`. **CRITICAL-1** (wall PII detectors) → `d865674`; **HIGH-1** (confirm-token single-use) → `7c54864`. Fixed items are marked ✅ below.
>
> **▶ UPDATE 2026-09-01 — Security Fix Phase 2 landed:** all four MEDIUM findings fixed on `v2` — **M1** safe default budget cap (`$20`, auto-pause) → `a66144c`; **M2** deterministic backend-composed confirm text → `300c6ee`; **M3** the mock/echo can't reach production UX (+ **L2** legacy orchestrator marked TEST-ONLY) → `8244b59`; **M4** engine-side IDOR verified safe (in-repo proof `docs/agent-engine-idor.md`) → `5009767`. **Code-side the agent is now launch-ready;** only the staging tasks remain (real key, run migrations `…001–009`, live round-trip incl. a cross-user 404 negative test, zero-retention terms).

---

## 0. Verdict & counts

| | |
|---|---|
| **Agent readiness** | **~90% built**, **~80% launch-ready** (pending must-fixes + staging validation) |
| **Privacy wall** | **Architecture: PASS** (single fail-closed choke-point). **Detector coverage: FAIL → ✅ FIXED (`d865674`)** — names/addresses/spaced-phones are now detected, tokenized, and re-scanned by the tripwire (bare city words remain a documented residual). |
| **AI control / safety** | **PASS** — allow-list + RBAC + HMAC confirm-gate + governance are enforced *and* regression-tested; the AI cannot invoke an unexposed tool, self-confirm a write, or escalate. One soft edge (AI-authored confirm text). |
| **Money safety** | **PASS** — the agent never sets an amount and never moves money; checkout/booking are server-authoritative COD through the real engines. |
| **Findings** | **1 CRITICAL (✅ fixed) · 1 HIGH (✅ fixed) · 4 MEDIUM (✅ all fixed) · 4 LOW (L2 fixed; L1/L3/L4 documented)** (+ info notes) |
| **Launch verdict** | **Safe for real money at launch. All code blockers + all MEDIUM findings are FIXED on `v2` — the agent is now CODE-SIDE launch-ready. Remaining before pointing at a real external AI with real customer data is staging validation only: a real key, run migrations `…001–009`, a live round-trip (incl. a cross-user 404 negative test per M4), and zero-retention provider terms.** |

---

## 1. Feature readiness matrix

| Capability | State | Honest one-line |
|---|---|---|
| Foundation / **privacy wall** | **BUILT · PARTIAL** | Structural choke-point + fail-closed tripwire are real; regex detector coverage is incomplete (names/addresses/spaced-phones). |
| **AI-provider layer** | **BUILT** | Anthropic real + Mock built; OpenAI/Gemini are honest stubs (`isConfigured()=false`); per-task routing, retryable-only failover, APCu circuit breaker. |
| **Actions / tools** | **BUILT** | 25+ allow-listed tools, one endpoint each; reads run, writes confirm; single choke-point orchestrator. |
| **Chat UI** | **BUILT** | Register-gated, mobile-first, real inline cards; verified in-browser (mock) across phases. |
| **Memory / personalization** | **BUILT** | Per-user row in our DB; only 3 anonymized hint tokens may cross the wall. |
| **Commerce tools** | **BUILT** | Browse/cart/checkout (COD) via `/api/shop/*`; online-pay engine-locked; agent never moves money. |
| **Vendor-agent** | **BUILT** | Conversational onboarding → draft (admin approves); status + growth hints; reminders. |
| **Control-room (governance)** | **BUILT** | Kill-switch, capability/writes toggles, limits, budget cap, routing, monitor — read+enforced at entry/orchestrator/planner. |
| **Connections (people/gifting)** | **BUILT** | Own-scoped people store; `order_for` reuses real checkout; recipient PII stays behind the wall. Same-city only. |
| **Reminder delivery** | **BUILT** | Idempotent cron → FCM; recurring reschedules; `list_reminders` in chat. Migration staged. |
| **Demand-detection** | **BUILT** | Capture in orchestrator → aggregate cron → admin growth-signal alerts. Migration staged. |
| **Voice / own-model** | **MISSING** | Out of scope by design (later phases). |

**Code-completeness of the shipped scope: ~95%.** The gap to *launch-ready* is security hardening + a staging run, not missing features.

---

## 2. THE PRIVACY WALL — the most important audit (tried to break it)

### 2.1 What genuinely holds (the architecture is real)
- **Single choke-point, structurally enforced.** A provider is only ever reached via `AiProviderManager::complete(SanitizedPrompt)` (`AiProviderManager.php:154`, the only caller of an adapter at `:214`). A `SanitizedPrompt` has a **private constructor** and a `seal()` factory that does a `debug_backtrace` **caller-class check** — it throws unless the immediate caller is `PiiWall` (`SanitizedPrompt.php:59-74`). `PiiWall` is `final`. Grep confirms the **only** live AI calls are `ToolPlanner.php:214` and the legacy `AgentOrchestrator.php:68`, both via `wall->sanitize()`. **There is no code path to an AI that skips the wall.** ✔
- **Fail-closed tripwire.** After scrubbing, the wall re-scans the *exact outbound bytes* (`$system."\n".$scrubbed."\n".json_encode($meta)`) and **throws `PiiLeakException`** if any detector still matches, forgetting the map (`PiiWall.php:141-143, 239-250`). The entry catches it and returns a safe 422, recording a `pii_blocked` row — it never falls back to sending (`heart/api/agent/index.php:135-151`). ✔
- **The wire is a subset of what's tripwired.** `AnthropicAdapter` sends only `system` + `messages()` (= `userText`) — it does **not** send `meta` at all (`AnthropicAdapter.php:70-75`). The tool catalog + memory hints ride *inside* `system` (`ToolPlanner::systemPrompt`), which is tripwired. So everything that leaves was scanned. ✔
- **Ownership stays inside.** The placeholder→original map is kept in `PiiWall::$maps` keyed by requestId, used only on the return path (`rehydrate`), and dropped via `forget()` at turn end (`PiiWall.php:159-178`). `user_id`/`city_id`/`session` never enter the outbound object (`SanitizedPrompt` carries only system/userText/task/meta). Memory hints are proven anonymized (`AgentMemory::hints` emits only `returning` / `pref_category:<slug>` / `area_known`; regex-guarded `[a-z_]{2,40}`). Saved-contact names are pre-tokenized to `<PERSON_n>` (`PiiWall::scrub:196-205`; proven by `actions_selftest` I1). ✔
- **No history replay.** Each turn sanitizes only the current message; no conversation transcript is accumulated and re-sent — so a prior turn's data can't leak on a later call. ✔ (Also a functional limit: the AI has no multi-turn memory.)

### 2.2 Where it breaks — `CRITICAL-1`: regex-only detection misses real PII  ✅ FIXED (`d865674`)
> **Fixed 2026-09-01:** the PHONE detector now tolerates space/dash/dot separators + a +91/0 prefix; ADDRESS detectors (house/plot/gali keyword, number-led street, capitalised street/locality) were added; NAME is covered by an exact deny-list of the user's own name + saved contacts (applied in scrub **and** re-checked by the tripwire) plus a conservative self-introduction pattern. `foundation_selftest` 6a–6h prove redaction + on-the-wire tripwire blocking. Bare city/locality words remain the one documented residual (wants NER). The original analysis below stands as the record.
The tripwire uses the **same detector list** as the scrubber (`assertOutboundClean` loops `self::DETECTORS`, identical to `scrub`). **So the tripwire can never catch a PII type the scrubber can't see** — detector coverage is the single source of truth, and it has real holes for a booking/delivery app:

1. **Free-text names and street addresses reach the AI.** There is **no NER / name / address detector** — only *saved-contact* names are scrubbed (`setContactTerms`). A fall-through message like *"I'm Rajesh Kumar, deliver to 45 MG Road, Haldwani"* → `Rajesh Kumar`, `MG Road`, `Haldwani` all pass to the provider (only a 6-digit pincode would be caught by the `NUM` detector). In a delivery/booking product, users type names and addresses constantly. **This is a PII leak by the founder's "no PII ever" bar.**
2. **Separator-formatted phone numbers evade both phone detectors.** `PHONE` requires 10 *contiguous* digits (`[6-9]\d{9}`) and `NUM` requires 6+ *contiguous* digits (`PiiWall.php:65,67`). So a very common way Indians write mobiles — `"98765 43210"` or `"987-654-3210"` (or `"+91 98765 43210"`) — splits into sub-6-digit groups that **no detector matches** (`CARD` needs ≥13 digits; `AADHAAR` needs 4-4-4). The number reaches the AI. Contiguous forms (`9876543210`, `+919876543210`, `09876543210`) *are* caught.

**Why this is the #1 finding:** the structural guarantee ("nothing bypasses the wall") is intact, but the wall's *promise* ("no PII leaves") depends entirely on detector completeness, and today it is a hand-rolled regex set applied identically for scrub and tripwire. The doc already flags NER as "drops in later" (`PiiWall.php:30-34`) — that "later" is a launch blocker for the real-AI path.

**Mitigating context (honest):** (a) the **deterministic planner handles the highest-frequency intents with no AI call at all** (`painter chahiye`, `kapde chahiye`, cart, orders, listing, status, reminders) — only fall-through phrasings reach the AI; (b) today the live provider is the **mock**, so nothing actually leaves the building; (c) a zero-retention provider contract limits downstream exposure. But none of these make the leak *not a leak*.

**Fix (must, before real AI):** add an independent second-stage detector the tripwire runs *in addition to* the scrubber's regexes — (i) digit-run **normalization** (strip spaces/dashes before phone/number matching), (ii) a **name/address NER or heuristic** (or a stricter policy: refuse-to-send any fall-through message that still contains ≥N alpha tokens adjacent to a number), and (iii) keep routing the common asks deterministically so the AI sees as little raw text as possible. Re-run the wall self-tests with spaced-phone and address fixtures.

### 2.3 Wall verdict
**Architecture PASS, detector coverage FAIL.** The wall is the right design and fails closed on what it knows; it does not yet *know* enough to honour "no PII to the AI" for free-typed names, addresses, and punctuated phones. **On the mock provider (today) this is latent; the day a real key is set, it is live.**

---

## 3. AI control / safety — can the AI act uncontrolled? (No.)

Stress-tested; the model can only ever *propose* — our code authorizes and executes.

- **Allow-list can't be bypassed.** `dispatchProposal` refuses any tool not `registry->isExposedTo($tool, $role)` before running anything (`AgentActionOrchestrator.php:124-132`); re-checked again at confirm-time (`:183-186`). An AI that names `delete_all` is rejected with no backend call (`actions_selftest` E1-E3). ✔
- **No field injection / smuggling.** Every tool cleans args against a typed `argSpec` allow-list and **drops unknown keys** (`AgentTool.php:74-97`). The AI can't add `amount`, `user_id`, etc. ✔
- **Writes always confirm; the AI can't self-confirm.** Write tools never execute on propose — the orchestrator issues an **HMAC-signed** `ConfirmToken` and requires a fresh client round-trip (`AgentActionOrchestrator.php:144-156`). The AI cannot mint a valid token (it never sees the secret), so prompt-injection tops out at *a proposal the human must still tap "confirm" on*. ✔
- **Confirm-token can't be forged/tampered/cross-user-replayed.** HMAC-SHA256 over the payload with a JWT-derived sub-key; `hash_equals` constant-time check; binds `uid` (verified against the authenticated user), `exp`, `tool`, `args`, version (`ConfirmToken.php`). Tampering the body breaks the signature (`actions_selftest` G1-G2); user B can't use user A's token (uid bind). ✔ *(Replay-within-TTL is a separate gap — see HIGH-1.)*
- **RBAC per role holds.** Customer tools vs vendor tools are enforced by the registry and re-checked by the real endpoints; a customer is blocked from `list_my_jobs`, a vendor is allowed (`actions_selftest` F1-F2). Admin sees all tools but the agent surface is user-JWT-scoped. ✔
- **Control-room is actually enforced everywhere.** `AgentPolicy` is loaded fresh per request and read at three points: **entry** (kill-switch → 503; rate + session limits; message length), **orchestrator** (`policyBlock` refuses a paused capability or all-writes, at propose *and* confirm), **planner** (budget auto-pause → skip the AI call) — proven by `actions_selftest` H1-H6. Settings are admin-only (`heart/api/admin/index.php:65` `requireRole(ROLE_ADMIN)` gates the whole router, including the agent endpoints dispatched at `:78`). ✔
- **Prompt-injection outcome:** a malicious message can make the AI *propose* a booking/checkout, but it cannot execute one (confirmation gate), reach an unexposed tool (allow-list), alter amounts (server-authoritative), or act for another user (engine authorizes the real JWT). **The backend always authorizes.** ✔

**One soft edge — see MEDIUM-2:** for an *AI-proposed* write, the human-readable confirmation text shown on the confirm card is the AI's `$say` string (`AgentActionOrchestrator.php:147`, widget `question`), while the bound tool/args live server-side in the token. The action is safe, but the *description* isn't guaranteed to match it — a crafted `say` could nudge a user to confirm a mislabeled action.

---

## 4. Money safety — the agent never moves money

- **No amount ever originates from the agent.** `checkout` args = `{address_id?}`; `order_for` = `{person_id, gift_note?}` (+ the saved person's coords/notes); `create_booking` = `{vendor_id, scheduled_at, notes?, address_id?}`. **No price/total/amount field exists in any tool's `argSpec`.** The engines compute totals server-side. ✔
- **Real backend does the money-critical work.** Checkout/booking go through `/api/shop/checkout` and `/api/service/request` — the never-touch engines that already own inventory locking, ledger, city-guard, delivery hand-off, and (per the payment-architecture memory) server-authoritative amounts, signature-verified webhooks, idempotency guards, `FOR UPDATE` locks and `reference_type` collision-safety. The agent generates **no** payment refs. ✔
- **COD today; online-pay is engine-locked.** Commerce online pay is COD-locked by the storefront (a surfaced engine decision); when enabled it flows through the existing payment-abstraction layer with the **user** completing payment. The agent only *places* the order. ✔
- **`order_for` delivery target** passes the saved person's `drop_lat/lng` + a note to the real checkout; the engine quotes delivery server-side and city-guards it. No fund movement, no amount tampering. ✔

**Money verdict: PASS.** The only money-adjacent robustness gap is duplicate submission (HIGH-1), not under-pay/tamper.

---

## 5. IDOR / data access

- **Agent-owned resources are independently scoped to the authenticated user.** `AgentPeople::get/list/findByTerm/add` all constrain `WHERE ... user_id = ?` (`AgentPeople.php:47-63, 30-45, 98-117`); `order_for`/`get_person` resolve under `ctx->userId` and 404 on a foreign id (`ConnectionTools.php:48-56, 128-131`). `AgentMemory` and `agent_reminders`/`list_reminders` are keyed to `ctx->userId` (from the verified JWT). **A user cannot read/act on another user's people, memory, or reminders by changing an id.** ✔
- **Register-gate holds.** Both `/api/agent/message` and `/api/agent/status` call `AuthMiddleware::require()`; `AgentContext::fromAuth` throws if `user_id<=0`. No anonymous path. ✔
- **Engine-backed resources delegate ownership to the engines (MEDIUM-4).** For `track_order`/`list_orders`/`get_vendor_profile`/`create_booking`/`checkout`, the agent forwards the user's **real JWT** to `/api/*` and relies on the engine to authorize ownership/city/RBAC (`EngineClient` sends `Authorization: Bearer <user JWT>`). This is the documented trust model and is correct **iff** each engine enforces ownership — which is the never-touch boundary and should be re-confirmed on staging (e.g. that `GET /api/shop/orders/{id}` rejects another user's order). The agent adds no independent check here, and by design must not weaken the engine's.

---

## 6. Abuse / cost

- **Rate limiting is real and DB-backed.** The entry applies a per-user+IP msg/min limit and a per-session action cap from `AgentPolicy` (`heart/api/agent/index.php:88-111`) via `RateLimiter` (`rate_limits` table, `ON DUPLICATE KEY` increment). Message length is capped (`max_message_chars`, default 2000). ✔
- **AI-budget auto-pause exists and is enforced** in the planner (`ToolPlanner` skips the AI when `policy->aiAllowed()` is false) — but the **default cap is `0` = unlimited** (`AgentPolicy::BUDGET_DEFAULTS`). Runaway-bill protection only exists once an admin sets a cap → **MEDIUM-1**.
- **OTP / upload abuse via widgets:** the widgets only return static descriptors (paths + labels); the actual OTP-send/verify and photo-upload happen client-side against the **existing** `/api/auth/otp/*` and `/api/vendor/work-photo` endpoints with the user's JWT (`WidgetTools.php`). So that abuse surface is the pre-existing auth/upload endpoints' own throttling — the agent doesn't newly expose it, but those endpoints' limits should be confirmed. ℹ
- **Hammering:** the register-gate + per-user/-session limits + budget pause bound cost. `RateLimiter::check` runs a full `DELETE ... WHERE expires_at <= NOW()` on every call — minor perf (LOW-1), not a hole.

---

## 7. Edge cases (checked against code)

| Case | Behaviour | OK? |
|---|---|---|
| AI returns invalid/garbage JSON | `ToolPlanner::parseJson` → null → treated as chat text; no crash | ✔ |
| AI proposes a non-catalog tool | allow-list rejects, no backend call | ✔ |
| Provider down / no key | manager fails over primary→secondary(mock) on *retryable* only; terminal errors don't fail over | ✔ (but see M3) |
| **Mock is the fallback** | mock **succeeds** with an echo string; planner can't parse it as JSON → surfaces the echo **to the user** as the reply | ✖ **MEDIUM-3** |
| Tool's backend call fails mid-flow | `respondFromResult` returns a friendly degrade, audited `failed`; nothing half-writes on our side | ✔ |
| Confirm-token expired | `verify` returns null → "expired or invalid"; no execution | ✔ |
| Confirm-token replayed within 10 min | **executes again** — no single-use store | ✖ **HIGH-1** |
| Capability paused mid-conversation | re-checked at confirm-time; a proposed-then-paused write is blocked at execute | ✔ (H2) |
| Concurrent duplicate confirms | both can execute (see HIGH-1) | ✖ |

---

## 8. Findings (ranked) & what "launch-ready" needs

### CRITICAL
- **C1 — ✅ FIXED (`d865674`, 2026-09-01) — Privacy-wall PII detection missed names, street addresses, and separator-formatted phones.** *(§2.2)* **Done:** separator-tolerant PHONE; house/plot/street/locality ADDRESS detectors; user-own-name + saved-contact exact deny-list in scrub AND tripwire; conservative self-introduction NAME pattern; over-redaction by design. Residual: bare city words (wants NER) — tracked, not a blocker. Proven by `foundation_selftest` 6a–6h.

### HIGH
- **H1 — ✅ FIXED (`7c54864`, 2026-09-01) — Confirm-token was replayable within its TTL.** **Done:** tokens are now single-use — `ConfirmToken::consume()` atomically claims the token's 128-bit nonce (`jti`) in `agent_used_tokens` (PK gate) right before the write, so a replay/concurrent duplicate is refused (at-most-once). Degrades safely with no DB / missing table; fails safe on ambiguous DB errors. Migration `2026_08_27_008` (staged). Proven by `token_selftest` T1–T7. *Activate on staging by running the migration.*

### MEDIUM  (all ✅ FIXED 2026-09-01)
- **M1 — ✅ FIXED (`a66144c`) — Default AI budget cap was 0 (unlimited).** Now defaults to **$20/month with auto-pause** (`AgentPolicy`), so a real key can't run away by accident; the control-room can raise it and an admin may set 0 to opt into unlimited. Migration `2026_08_27_009` idempotently corrects the seeded default (only the untouched value). Proven by `actions_selftest` H7 (default cap non-zero; H6 keeps 0=unlimited).
- **M2 — ✅ FIXED (`300c6ee`) — Confirmation text was AI-authored (`$say`).** Now every write's confirm prompt is composed by `AgentActionOrchestrator::confirmSummary(tool,args)` from the bound action + safe args (ids/enums/datetimes; never notes/gift_note; never `$say`), so the shown text always matches what the token binds. Proven by `actions_selftest` C5 + M2 (a misleading AI `$say` is ignored).
- **M3 — ✅ FIXED (`8244b59`) — Mock echo could leak into the UX.** `ToolPlanner::viaAi` now degrades to a clean deterministic nudge whenever the resolved provider is `mock` (or the `[mock:` echo marker appears); a real provider's prose still shows. Proven by `actions_selftest` M3. *(Real-key-for-launch stays a staging item.)*
- **M4 — ✅ VERIFIED SAFE (`5009767`, no code change) — Engine-backed ownership.** Read the never-touch engines: `OrderService::list/detail` scope `WHERE o.user_id = :uid` (`OrderService.php:562,623`); vendor endpoints resolve the vendor from the **JWT** via `resolveVendorId()` then scope by `vendor_id`/`user_id`; cart/checkout use `$shopRequireUser()`; agent-owned people/memory/reminders scope by `user_id`. No client-supplied owner id is trusted; public reads are public by design. Full proof + citations: `docs/agent-engine-idor.md`. Staging: run the cross-user 404 negative test as belt-and-braces.

### LOW
- **L1** — `rate_limits` full-sweep `DELETE` on every agent call (perf under load). *(Shared platform helper — not agent-scope; left as-is.)*
- **L2** — ✅ FIXED (`8244b59`) — Legacy `AgentOrchestrator` now clearly marked **TEST-ONLY / not the production runtime** to prevent a future mis-wire.
- **L3** — Circuit breaker + rate-limit rely on APCu/DB; absent APCu ⇒ no circuit breaking (failover still works).
- **L4** — Recipient PII (`agent_people.phone/address_text`) stored plaintext at rest; ensure DB-at-rest encryption/backups meet the banking-grade bar (platform-wide, not agent-specific).

### Staging-only (not code — do before "on"):
1. Apply the 7 staged agent migrations (`2026_08_27_001…007`) on staging.
2. Run `foundation_selftest.php`, `actions_selftest.php`, `memory_selftest.php`, `proactive_selftest.php` green on real PHP/DB.
3. Configure a **real AI key** + a **budget cap**; keep `mock` as failover only.
4. One real end-to-end round-trip per surface (book, checkout, gift, reminder fire, demand alert).
5. Sign a **zero-retention** provider contract before any real customer text can reach the AI.

---

## 9. Honest verdict

**Is it genuinely secure and ready, or a demo?** It is **genuinely engineered** — this is production-grade scaffolding, not a demo. The hard guarantees a payments-adjacent product needs are actually present and *test-covered*: a single fail-closed wall choke-point, a friend-class sealed-prompt that can't be minted elsewhere, HMAC-signed uid-bound confirm tokens, a deny-by-default tool allow-list with RBAC re-checked at execute, governance enforced at three layers, and a money path where the agent never sets an amount or moves funds. Prompt-injection cannot make it act — it can only make it *propose*, and a human + the backend still gate every write.

**The two things that make it not-yet-ready** are both honest and fixable: (1) the wall's regex PII detection doesn't yet cover the free-text names/addresses/spaced-phones that a delivery app's users actually type — so at the founder's "no PII to the AI, ever" bar it isn't there for the real-AI path; and (2) confirm tokens can be replayed within their TTL, risking duplicate writes.

**% ready:** ~90% built; **~80% launch-ready.** **Biggest risks:** PII detector coverage (C1) and token replay (H1).

**Is it safe to handle real money + real data + a real AI at launch, once must-fixes land?**
- **Real money — yes, today:** the COD path is server-authoritative and the agent never moves money.
- **Real data through our own system — yes:** id-scoping and the register-gate hold for agent-owned data; engine-backed data inherits the engines' JWT authorization (verify on staging, M4).
- **Real *external AI* with real customer data — all code fixes are now in:** the two blockers (**C1** `d865674`, **H1** `7c54864`) and all four MEDIUM findings (**M1** `a66144c`, **M2** `300c6ee`, **M3** `8244b59`, **M4** verified `5009767`) are done on `v2`. What remains is purely **staging validation**: run migrations `…001–009` (incl. `008` to activate single-use), set a real AI key (keep `mock` as failover only), one live end-to-end round-trip per surface incl. the cross-user 404 negative test, and zero-retention provider terms. **The agent is code-side launch-ready.**

---

*Read-only audit — no code changed. Grounded in `v2` @ `5016b81`. Companion docs: `agent-01-blueprint` … `agent-10-proactive`, `agent-status-report`.*
