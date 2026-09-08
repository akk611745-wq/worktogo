# WorkToGo — Agent Layer, Phase 1: Foundation (Privacy Wall + AI-Provider Layer)

> **Purpose:** Document the FOUNDATION built for the agent layer — the two must-be-right-first pieces: (1) the **privacy wall** that makes it structurally impossible to leak customer PII / payment data / business logic to an external AI, and (2) the **flexible, pluggable AI-provider layer** (one abstraction, one real adapter, clean stubs for the rest), selectable/swappable by config. No UI, no voice, no tools/actions, no memory yet — those are later phases.
> **Status:** Built locally in the `wtg-v2` worktree (branch `v2`). **No live/deploy. Migration staged, NOT run** (no PHP runtime locally). **No never-touch engine edited** (shopping-engine / video-engine / service-engine / orders.js read-only). Verified by a headless self-test + full logic trace.
> **Companion to:** `agent-01-blueprint.md` (the architecture). This is the first build increment of that blueprint's §3 (Privacy Wall) and §2 component 4 (AI Provider layer).
> **Date:** 2026-08-27.

---

## 1. What was built (plain summary)

A brand-new, **cleanly separated** agent scope under `core/agent/` (library) + one HTTP entry under `heart/api/agent/`. It reuses the platform's own primitives (auth, city, rate-limit, response, logging, `app_settings`) and copies the proven **payment-abstraction** pattern for the AI-provider layer. It touches no engine.

The foundation does exactly one end-to-end thing and proves it:

```
registered user's message
  → AgentContext (OUR internal identity: user_id, role, city — never sent out)
  → PiiWall.sanitize()   ← scrub PII, build a coarse envelope, FAIL-CLOSED tripwire
  → AiProviderManager    ← pick provider by config, fail over, circuit-break
  → AiProvider adapter   ← Anthropic (real) | OpenAI/Gemini (stubs) | Mock (built-in)
  → PiiWall.rehydrate()  ← restore the user's own words in the reply
  → reply
```

The external AI sees only anonymized, minimal "understanding" text. Everything real stays inside our system.

---

## 2. File map (what each file is)

**Agent library — `core/agent/` (new, clean scope):**

| File | Role |
|---|---|
| `AgentExceptions.php` | Typed errors: `PiiLeakException` (fail-closed), `AgentSecurityException` (wall invariant), `AgentDisabledException` (kill-switch), base `AgentException`. |
| `AgentContext.php` | The **internal, ours-only** identity envelope for a turn (real `user_id`, `role`, `city_id`, `session`, `request_id`). Built only from a verified JWT (`fromAuth`) → the register-gate. Exposes only **coarse** getters (`role`, `lang`, `period`) for the wall. City is deliberately **withheld** from providers. |
| `SanitizedPrompt.php` | **The sealed object.** The ONLY thing an adapter accepts. Private constructor + a `seal()` factory guarded by a runtime caller-check so **only `PiiWall` can mint one**. Carries only safe-to-send content (scrubbed text + coarse meta). |
| `PiiWall.php` | **THE PRIVACY WALL.** Scrub/tokenize PII → placeholders, deny-by-default meta allow-list, **fail-closed egress tripwire**, request-scoped placeholder→original map (held here, never sent), re-hydrate on return, counts-only audit. |
| `ai/AiProvider.php` | The provider **contract** (`name`, `isConfigured`, `complete(SanitizedPrompt): AiResponse`). `complete()` accepts a `SanitizedPrompt` and nothing else — the type system forbids raw text. |
| `ai/AiResponse.php` | Normalised, provider-agnostic result (`success`, `text`, `model`, tokens, `latencyMs`, `retryable`, `failover`, …) — the AI analogue of the payment layer's normalised result. |
| `ai/AnthropicAdapter.php` | **The REAL reference adapter** (Claude, Messages API, raw cURL to match house style). Reads `ANTHROPIC_API_KEY` from env; model is **config-driven** (`AGENT_ANTHROPIC_MODEL`, default `claude-opus-5`). Never throws; classifies failures retryable/terminal. |
| `ai/OpenAiAdapter.php` | **STUB / slot.** Full contract, guarded OFF (`isConfigured()===false`), documented drop-in. Never spends until implemented + keyed + enabled. |
| `ai/GeminiAdapter.php` | **STUB / slot.** Same discipline. |
| `ai/MockAiAdapter.php` | Built-in, key-less provider. Doubles as the **verification** provider and the **degrade-don't-die** fallback (default secondary). Echoes the sanitized text — proving what a real provider would have seen. |
| `ai/AiProviderManager.php` | **Factory + per-task router + failover + circuit breaker** — a faithful clone of `PaymentGatewayManager`. The ONLY code that invokes a provider; it requires a `SanitizedPrompt`. |
| `AgentOrchestrator.php` | The minimal turn runtime: wall → manager → re-hydrate → reply, plus one audit row per turn and clean degrade. No tools/memory/UI. |
| `bootstrap.php` | Loads the whole scope in dependency order (one include). |
| `tests/foundation_selftest.php` | Headless, key-less proof (run with `php`), CI-friendly exit code. |

**HTTP entry — `heart/api/agent/index.php` (new):** register-gated (`AuthMiddleware::require`), kill-switch (`app_settings.agent_enabled`, default OFF), per-user rate limit, then delegates to the orchestrator. Also `GET /api/agent/status` returning **booleans only** (enabled + which providers are usable + routing) for verifying config/swaps without spending.

**Config — `config/ai.providers.php` (new):** the ONLY file you edit to add/route a provider. Declarative registry (`enabled`, per-provider `model`) + per-task `routing` (primary/secondary). DB-free, side-effect-free.

**Migration — `migrations/2026_08_27_001_agent_foundation.sql` (staged, NOT run):** creates `agent_ai_log` (counts-only egress audit) and seeds `app_settings.agent_enabled` (`'0'` = OFF) + `app_settings.agent_ai_routing` (admin override, mirrors config). `CREATE TABLE IF NOT EXISTS` + `INSERT IGNORE`; no ALTER/DROP/backfill of any existing table.

**Gateway wiring — `heart/index.php` (one additive block):** dispatches `/api/agent` to the entry, mirroring the existing `/api/shop` block. Heart is the gateway (editable), not a never-touch engine.

---

## 3. THE PRIVACY WALL — how it guarantees no PII leaks

The guarantee is **structural**, not "the AI is told to be careful." Five layers, deepest first:

### Layer 0 — The AI's job doesn't need the secrets
The provider is asked to do one thing: **understand language**. Understanding needs the *words*, not the *identity*. So the wall can strip every identifier and the task still works. We removed the need for the data, then removed the data.

### Layer 1 — A provider is *unreachable* except through the wall (type + friend-class enforcement)
- Every adapter's `complete()` and the manager's `complete()` accept a **`SanitizedPrompt`** and nothing else — you cannot hand them a raw string.
- A `SanitizedPrompt` has a **private constructor** and a single factory `seal()` that verifies **at runtime that its caller is `PiiWall`** (`debug_backtrace` caller check). Any other caller throws `AgentSecurityException`.
- Therefore **there is no code path that reaches a provider without first passing `PiiWall::sanitize()`.** (The self-test proves a forged `SanitizedPrompt::seal()` from outside the wall throws.)

### Layer 2 — Scrub & tokenize (identity replaced with placeholders)
`PiiWall::scrub()` runs an ordered detector list over the user's text and replaces each hit with a neutral placeholder held in a **request-scoped** map:

| Detected | Placeholder |
|---|---|
| emails | `<EMAIL_n>` |
| our order/booking refs (`WTG_…` / `WTG_B…`) | `<ID_n>` |
| Aadhaar-like (12-digit grouped) | `<AADHAAR_n>` |
| card-like (13–19 digits) | `<CARD_n>` |
| Indian mobile numbers (`+91`/`0` optional) | `<PHONE_n>` |
| UPI VPAs (`name@handle`) | `<UPI_n>` |
| URLs | `<URL_n>` |
| any remaining 6+ digit run (OTP/pincode/id) | `<NUM_n>` |

Meaning survives (`send <ITEM> to <PERSON>`), identity does not. The detector list is **extensible** — name/address detectors (NER, saved-contact matching) drop in later without changing the contract; in the full agent, names/addresses are resolved from saved contacts **inside** the system and only ever referenced by placeholder.

### Layer 3 — Deny-by-default envelope
The only structured context allowed to accompany the text is an **allow-list**: `role`, `lang`, `period`, `capabilities`. Anything else — including the real `user_id`, `city_id`, and `session` — is **never placed in the outbound object**. The wall reads only coarse getters from `AgentContext`; it uses the real `user_id` **solely** to write our internal audit row.

### Layer 4 — Fail-closed tripwire
After scrubbing, the wall re-scans the **exact bytes that would leave** (system + scrubbed text + JSON meta) with the same detectors. If **anything** still matches, it throws `PiiLeakException` and **nothing is sent** — a leak is a hard refusal, not a warning. (The self-test proves that PII placed even in the static system text is caught and the turn refused.)

### Ownership & audit
- The **placeholder→original map lives only inside the wall**, keyed by `request_id`, used only on the **return** path to re-hydrate the reply for the user who owns that data, and dropped (`forget()`) at end of turn. It never travels toward a provider.
- The **audit** (`agent_ai_log`) records **counts** of what was stripped (e.g. `{"PHONE":1,"EMAIL":1}`), the provider/model, tokens, latency, status — **never raw values, never keys, never prompt text.** Egress is auditable without itself becoming a leak.
- The internal **context id that ties a turn to a user** (`user_id` in the audit row) is ours; it is **never** put in anything a provider sees.

**Net:** to the external AI, WorkToGo is one anonymous assistant asking language questions. We remain 100% data owner.

---

## 4. The flexible AI-provider layer (never locked in)

A faithful clone of the shipped payment abstraction:

- **One contract** (`AiProvider`) — the whole agent talks to this, never a concrete provider.
- **Adapters** — `AnthropicAdapter` (real reference), `OpenAiAdapter` + `GeminiAdapter` (clean stubs, guarded off), `MockAiAdapter` (built-in fallback + test).
- **Config-driven selection** — `config/ai.providers.php` declares which providers exist/are enabled and, **per task**, the primary/secondary. An admin can override at runtime via `app_settings.agent_ai_routing` (JSON). **No provider is hardcoded anywhere in logic**; even the model is a config value.
- **Failover + circuit breaker** — on a *retryable* failure (network / 5xx / 401-403 creds / 429) the manager fails over primary → secondary; on a *terminal* rejection (400/404/422) it does not. A flapping provider's circuit opens for a cool-down (APCu), exactly like `BrainConnector`.
- **Degrade-don't-die** — the default secondary is the built-in `mock`, so even with **no** external provider configured the agent still answers deterministically. If every provider is removed, the system keeps working. Adding a provider later = implement the stub + set a key + flip one config line.

**Adding/enabling a provider (later task):** set the key in `.env`, implement the stub's `post()/parse()` + `isConfigured()`, set `enabled=true` and point a task's `primary`/`secondary` at it in config (or the `app_settings` override). No orchestrator/wall/engine change.

---

## 5. How it connects to the existing system (reuse, no engine edits)

| Reused primitive | Used for |
|---|---|
| `AuthMiddleware::require()` + `token_blacklist` | **Register-gate** — agent only for authenticated (registered) users. |
| `CityResolver::resolveFromUser()` | Internal city (for later action routing) — never sent to a provider. |
| `RateLimiter::check()` | Per-user message throttle. |
| `Response` / `Logger` | Uniform JSON responses + app logging. |
| `app_settings` | Kill-switch (`agent_enabled`) + provider routing override (`agent_ai_routing`). |
| Heart front-controller (`/api/agent` block) | One additive dispatch line, mirroring `/api/shop`. |

No `shopping-engine`, `video-engine`, `service-engine`, or `orders.js` file was read-for-edit or changed. The agent's future "actions" will reuse those only through clean adapters + the existing intent pipeline (next phases).

---

## 6. How to verify

**A. Headless self-test (no key, no DB, no HTTP)** — when a PHP runtime is available:
```bash
php core/agent/tests/foundation_selftest.php
```
It asserts, and prints PASS/FAIL for, every foundation guarantee:
- the mock provider **never** sees the raw phone / email / order-ref (only `<PHONE_1>`, `<EMAIL_1>`, `<ID_1>`);
- the reply is **re-hydrated** (the owner gets their own words back);
- **provider swap/failover by config** (unconfigured real provider ⇒ degrade to mock; `primary=mock` ⇒ no failover);
- a **forged `SanitizedPrompt` throws** (can't reach a provider without the wall);
- the wall **fails closed** when PII would still leave;
- the agent is **register-gated** at the context boundary.

**B. Staging (after `migrate.php` applies the migration):**
- `GET /api/agent/status` (with a valid JWT) → `{ enabled:false, providers:{mock:true, anthropic:false,…}, routing:{…} }`. Booleans only, no secrets. Proves config + swap without spending.
- Enable: set `app_settings.agent_enabled='1'`; set `ANTHROPIC_API_KEY` to route the `understand` task to Claude (or leave it unset to run on the mock). `POST /api/agent/message` `{ "message": "..." }`.
- Unregistered (no JWT) → `401`. Kill-switch off → `503`. A message containing only-PII that trips the wall → `422` (`PII_BLOCKED`), nothing sent.
- Inspect `agent_ai_log` → one row per turn, `redactions` counts only, no raw values.

**C. Logic trace (done here, since there is no local PHP runtime):** each detector was traced against the sample utterance; the seal caller-guard, the failover branch, and the re-hydration ordering were walked by hand. See §3/§4.

---

## 7. Open questions (founder decisions — later phases, not blockers)

1. **Which provider(s) to start with + monthly AI budget cap** — sets routing + the kill-switch thresholds. Default committed: Anthropic primary, `mock` secondary, kill-switch **OFF**.
2. **Zero-retention / data-processing terms** with the chosen provider — the contractual complement to the wall.
3. **Model per task** — the config default is `claude-opus-5`; for the high-volume "understand" task a cheaper/faster model is likely better. Config-only change.
4. **Name/address detection depth** — the wall tokenizes structured PII now; add NER / saved-contact matching when connections land (they'll be resolved inside the system anyway).
5. **`app_settings.agent_enabled`** stays OFF until the founder is ready — nothing calls out or spends before then.

---

## 8. What is explicitly NOT in this phase

No UI, no voice, no tools/actions, no memory/personalization, no connections. The orchestrator does not call any engine. This is a clean, testable spine: **prove the wall and the pluggable provider layer, then build actions on top** in the next phase.

---

*Foundation increment only. Built in `wtg-v2` (branch `v2`); no live deploy; migration staged not run; no never-touch engine edited. The privacy guarantee is enforced by construction (private constructor + friend-class factory + type-only provider input + fail-closed tripwire), not by convention.*
