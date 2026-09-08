# WorkToGo — Agent Layer, Phase 2: Action / Tool Layer (the agent DOES things, safely)

> **Purpose:** Document the ACTION layer built on the Phase-1 foundation — so the service-agent can actually *do* real work (find vendors, read packages/prices, create bookings, request expert visits) by calling our EXISTING backend, under strict rules. The external AI decides nothing about data or money; it only proposes language→tool. Our backend authorizes and executes.
> **Status:** Built locally in `wtg-v2` (branch `v2`). **No live/deploy. Migration staged, NOT run** (no PHP runtime locally). **No never-touch engine edited** — the agent reaches the service engine only through its real HTTP endpoints via a clean client. Verified by a headless self-test + full logic trace.
> **Builds on:** `agent-01-blueprint.md` (§2 component 6 — the tool/action layer), `agent-02-foundation.md` (the privacy wall + AI-provider layer).
> **Date:** 2026-08-27.

---

## 1. What was built (plain summary)

The agent can now perform **real service-engine actions**. A user types "painter chahiye"; the agent understands, searches our real vendor database, shows the results, and — when the user asks to book — proposes the booking and creates it **only after an explicit confirmation**, through the service engine's own booking endpoint (which does all the validation, RBAC, city-scoping and money-safety, untouched).

The rule the whole layer enforces: **the AI proposes, the backend authorizes and executes.** The AI never touches the database, never decides a price, never moves money, and can never call a capability we didn't hand it.

---

## 2. The tool layer + the flow

### 2.1 Tools (each maps to ONE existing endpoint)

| Tool | Mode | Role | Real endpoint it calls |
|---|---|---|---|
| `find_vendors` | read | customer | `GET /api/vendors` |
| `get_vendor_profile` | read | customer | `GET /api/vendors/{id}` |
| `get_packages` | read | customer | `GET /api/service/packages` |
| `get_expert_visit_price` | read | customer | `GET /api/service/expert-visit-fee` |
| `create_booking` | **write** | customer | `POST /api/service/request` (`direct_vendor`) |
| `request_expert_visit` | **write** | customer | `POST /api/service/request` (`inspection`) |
| `list_my_jobs` | read | vendor_service | `GET /api/vendor/jobs` |
| `get_my_profile` | read | vendor_service | `GET /api/vendor/profile` |

A tool is a small declarative class: `name`, `description`, `mode` (read/write), `allowedRoles` (RBAC), a typed `argSpec` (argument allow-list), and a `run()` that issues exactly one backend call via the EngineClient. It never touches the DB.

### 2.2 The turn flow

```
user message ─▶ ToolPlanner
                 ├─ deterministic (system-first): "painter chahiye" → find_vendors   [no AI, no cost, fully private]
                 └─ else → AI via the PRIVACY WALL → strict-JSON {tool,args,say}      [AI only proposes]
             ─▶ AgentActionOrchestrator  (the single choke point)
                 ├─ ALLOW-LIST + RBAC: is this tool exposed to this role?  no → reject
                 ├─ read  → execute now via the backend (reads run freely)
                 └─ write → DO NOT execute; return a signed confirm_token
             ─▶ (next turn) confirm_token ─▶ verify (same user, unexpired) ─▶ execute write via backend
             ─▶ audit every step ; re-hydrate the reply for the owner
```

### 2.3 How the AI proposes but the backend authorizes/executes

- The AI is shown only the **catalog of tools for the caller's role** (names + descriptions + arg keys — all public) and the sanitized message. It returns strict JSON `{tool, args, say}`. That is a **proposal**, nothing more.
- **Our code** validates the tool against the role allow-list, filters the args through the tool's typed `argSpec` (deny-by-default — the AI can't inject extra fields), and calls the **real endpoint** carrying the **user's own JWT**. The engine then applies its own auth, ownership, city-scoping, validation and writes — exactly as for a normal client. If the AI proposed nonsense, the engine rejects it.
- **Real data never goes back to the AI.** Vendor names, ids, and prices come from the engine to the user; the AI only ever saw the scrubbed language.

### 2.4 Why HTTP to the engine (not include, not DB)

The service engine is procedural (top-level functions) and its `Response` helper `exit`s or throws under `HEART_INTERNAL_INC`, so re-including it in-process would redeclare functions and hijack the agent's own response. The clean, no-edit path is an **internal HTTP sub-request** to the engine's real endpoint (mirroring `heart/pipeline/EngineLoader`'s HTTP path), forwarding the user's JWT. Every call runs in the engine's own request, fully isolated, with the engine's rules intact. Base URL = `APP_URL`.

---

## 3. Guardrails (how the AI stays controlled)

| Guardrail | Where | Effect |
|---|---|---|
| **Register-gate** | entry `AuthMiddleware::require()` | Only a logged-in user can reach the agent at all. |
| **Kill-switch** | `app_settings.agent_enabled` (default OFF) | The agent does nothing until the founder enables it. |
| **Rate limit** | entry `RateLimiter::check` (per user) | Caps turns/minute; writes also hit the engine's own booking throttle. |
| **Privacy wall** | `PiiWall` (Phase 1) | No PII reaches the AI; fail-closed. |
| **Allow-list** | `ToolRegistry::isExposedTo()` | The AI can only run a tool we exposed to the role; anything else → refused + audited. A tool it invents (`delete_all`) simply doesn't exist. |
| **RBAC / per-role toolset** | each tool's `allowedRoles()` | Customer-agent sees find/book; vendor-agent sees its own jobs/profile; a customer calling a vendor tool is refused. Admin sees all. |
| **Typed arg allow-list** | `AbstractTool::clean()` | Only declared args, correctly typed, reach the backend — no field injection. |
| **Write confirmation** | `AgentActionOrchestrator` + `ConfirmToken` | A write is never executed on the turn it's proposed; a signed, user-bound, short-lived token must be sent back to execute. Tamper/expiry/other-user → rejected. |
| **Backend is the authority** | the engine endpoints | Ownership, city, pricing, money-safety, validation all run in the engine, untouched — the agent can't bypass them. |
| **Audit** | `agent_action_log` | Every step (proposed/executed/rejected/failed) is logged with tool + mode + status + arg **keys** (never values, never PII). |

**On money:** a write that initiates payment (`request_expert_visit`, the ₹299 inspection) only *creates* the booking/order via the engine; the user still completes the payment themselves on the client (Cashfree SDK). The agent never completes a payment or moves money.

---

## 4. File map

**New (agent scope, `core/agent/`):**
- `tools/EngineClient.php` — `EngineResult` + `EngineClient` interface + `HttpEngineClient` (internal HTTP to the real backend, forwards the user's JWT; never throws).
- `tools/AgentTool.php` — `ToolContext`, the `AgentTool` contract, and `AbstractTool` (strict arg cleaning + required checks).
- `tools/ServiceTools.php` — the 8 concrete service tools + a category synonym map.
- `tools/ToolRegistry.php` — the allow-list authority: per-role toolset, `isExposedTo()`, and the AI catalog; `defaultRegistry()` registers all service tools.
- `tools/ConfirmToken.php` — stateless, HMAC-signed, user-bound, expiring write-confirmation token.
- `ToolPlanner.php` — deterministic (system-first) + AI-via-wall planning → a `ToolPlan` proposal.
- `AgentActionOrchestrator.php` — the runtime: allow-list/RBAC enforcement, read-execute, write-confirm gate, audit, re-hydrate.
- `tests/actions_selftest.php` — headless proof (no key/DB/HTTP).

**Changed:**
- `core/agent/bootstrap.php` — loads the Phase-2 files.
- `heart/api/agent/index.php` — `/api/agent/message` now runs the action orchestrator; accepts `message` **and/or** `action` (a tapped-card tool) **and/or** `confirm_token`; forwards the caller's JWT to the engine client.

**New migration (staged, NOT run):**
- `migrations/2026_08_27_002_agent_action_log.sql` — `agent_action_log` (metadata-only action audit). `CREATE TABLE IF NOT EXISTS`; no ALTER/DROP of any existing table.

**Untouched engines:** `shopping-engine`, `video-engine`, `service-engine`, `orders.js` — read-only. The agent calls the service engine only through its real HTTP endpoints.

---

## 5. How to verify

**A. Headless self-test (no key, no DB, no HTTP):**
```bash
php core/agent/tests/actions_selftest.php
```
Asserts, with PASS/FAIL per line:
- **A** "painter chahiye" → deterministic → `find_vendors` → real vendor search returns 2 vendors (backend `GET /api/vendors` called).
- **B** an AI-proposed tool goes **through the wall** (the mock provider never sees the raw phone, only `<PHONE_1>`) and is then executed.
- **C** `create_booking` **requires confirmation** — not executed, returns a `confirm_token`, backend NOT called.
- **D** sending the token back **executes** the booking via the backend (`POST /api/service/request`, `booking_mode=direct_vendor`, booking id 555).
- **E** an AI proposal for an **unexposed tool** (`delete_all`) is **rejected**, backend never called.
- **F** **RBAC**: a customer is blocked from a vendor tool; a vendor can list its own jobs.
- **G** a **tampered confirm token** is rejected, no booking created.

**B. On staging (after `migrate.php`, `agent_enabled=1`, `APP_URL` set):**
`POST /api/agent/message` with a valid customer JWT:
- `{"message":"painter chahiye"}` → real painters from `/api/vendors`.
- `{"action":{"tool":"create_booking","args":{"vendor_id":<id>,"scheduled_at":"2026-09-01 11:00:00"}}}` → `requires_confirmation:true` + `confirm_token`.
- `{"confirm_token":"<token>"}` → booking created via the real service engine.
- Inspect `agent_action_log` → one row per step (proposed → executed), arg **keys** only.

---

## 6. What is NOT in this phase (later)

No UI, no voice, no memory/personalization, no commerce tools, no connections. One tool per turn (multi-tool turns come with the internal-HTTP client that already isolates each call). Area-based vendor filtering is passed through but not yet a server filter (the engine matches by category `type` today). These are deliberately deferred.

---

*Phase-2 increment only. Built in `wtg-v2` (branch `v2`); no live deploy; migration staged not run; no never-touch engine edited. Control is structural: allow-list + typed args + write-confirmation + the backend as the sole authority — the AI proposes, it never decides or executes.*
