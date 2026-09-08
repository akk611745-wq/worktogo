# WorkToGo — Agent Layer, Phase 7: Admin Control-Room (govern the agent)

> **Purpose:** The agent is now powerful (service + commerce + vendor). It must NEVER be uncontrolled. This phase gives the founder/admin a control-room to govern it — decide what it may/may not do, set limits, cap the AI budget, pick the provider, monitor what it's doing, and stop it instantly. Everything is data-driven (app_settings); the orchestrator/planner/entry READ and ENFORCE it, so the admin changes behaviour with zero code and it takes effect on the next turn.
> **Status:** Built locally in `wtg-v2` (branch `v2`). **No live/deploy. Migration staged, NOT run.** **No never-touch engine edited.** Enforcement verified by the self-test; the page verified in a browser (stubbed).
> **Builds on:** Phases 1–6 (`agent-01`…`agent-07`).
> **Date:** 2026-08-30.

---

## 1. What was built (plain summary)

An admin page — **Admin → System → Agent Control** — that governs the assistant:

- **⏻ Global kill-switch** — turn the whole agent off instantly (the app keeps working).
- **🧩 Per-capability toggles** — pause a whole class of tools (service / commerce / vendor) or pause **all writes** with one flag (users can still browse; no bookings/orders/changes).
- **💸 AI budget** — a monthly cost cap (USD) with **auto-pause**: when the cap is hit, the agent stops calling the external AI (it degrades to deterministic understanding) — it does not break. Protects against runaway API bills; shows live spend.
- **🚦 Limits & guardrails** — per-user messages/minute, max actions/session, max message length.
- **🔌 Provider & routing** — which providers are usable + the per-task routing (the flexible AI layer). Swap the active provider by changing the primary; route away to disable one.
- **📊 Monitor** — a 24h summary (turns, AI calls, tokens, blocked / PII-blocked / rejected, top tools) + a recent-activity feed, all from the existing agent audit tables — **metadata only, no PII, no message text**.

The AI is a tool under our control, always.

---

## 2. Governance is data-driven — one policy, enforced everywhere

A single class, **`core/agent/AgentPolicy.php`**, is loaded fresh each request from `app_settings` and is the sole source of truth for what the agent may do right now. The entry, orchestrator and planner consult it:

| Setting (`app_settings`) | Read by | Enforced as |
|---|---|---|
| `agent_enabled` (bool, kill-switch) | entry | off → `503`, the agent does nothing |
| `agent_capabilities` (json) | orchestrator | a paused capability's tools **refuse** ("blocked"); `writes:false` refuses every write, reads still run |
| `agent_limits` (json) | entry | per-user msg/min rate limit + per-session action cap |
| `agent_guardrails` (json) | entry | max message length |
| `agent_budget` (json) | planner | cap hit + auto-pause → **the AI is not called** (deterministic-only degrade); spend computed live from `agent_ai_log` |
| `agent_ai_routing` (json) | AI provider manager | which provider/model handles each task (swap/failover) |

Enforcement points, concretely:
- **Entry** (`heart/api/agent/index.php`): kill-switch → 503; rate + session limits from the policy; message-length guardrail.
- **Orchestrator** (`AgentActionOrchestrator::policyBlock`): after RBAC, every tool is checked against its capability group and (for writes) the writes flag — a paused one is refused and audited as `blocked`. Re-checked at confirm-time too.
- **Planner** (`ToolPlanner::plan`): when the budget is paused, the AI fallback is skipped — deterministic intents still work, anything else degrades to a friendly nudge (no spend).

A tool's capability group is a small governance mapping in `AgentPolicy` (service / commerce / vendor / core); `core` (e.g. phone-verify) is never gated.

---

## 3. The control-room surfaces (data flow)

- **Read:** the page loads `GET /api/admin/agent/overview` (settings snapshot + provider usability + routing + 24h summary) and `GET /api/admin/agent/activity` (recent `agent_action_log` rows). Both are new **read-only** admin endpoints (`heart/api/admin/agent.php`), reached under the existing `ROLE_ADMIN` gate, reading only the existing audit tables — metadata only.
- **Write:** every toggle/save uses the **existing** `PUT /api/admin/settings/{key}` (no new write endpoint). The change lands in `app_settings` and the next agent turn reads it. Kill-switch and capability toggles apply **instantly**; budget/limits/routing apply on save.
- **PII-blocked visibility:** the entry now records a metadata-only `agent_action_log` row (`status=pii_blocked`) when the wall fails closed, so the monitor can show those events (still no content).

---

## 4. Reuse / never-touch

- **Reuses:** `app_settings` + `SettingsController` (existing settings PUT/GET), the `agent_ai_log` + `agent_action_log` audit tables, the AI-provider config/routing (the flexible layer), the existing kill-switch (`agent_enabled`), and the admin shell/auth/api conventions.
- **Never-touch:** no engine edited (`shopping-engine` / `video-engine` / `service-engine` / `orders.js` read-only). The new admin endpoint only reads agent tables; the page is a standard admin page.

---

## 5. File map

**New:** `core/agent/AgentPolicy.php` (the governance authority), `heart/api/admin/agent.php` (monitor endpoints), `admin/agent.html` (the control-room page), `migrations/2026_08_27_005_agent_governance.sql` (seeds `agent_capabilities` / `agent_limits` / `agent_budget` / `agent_guardrails`; staged).
**Changed:** `heart/api/agent/index.php` (build + enforce the policy; record PII-blocked), `AgentActionOrchestrator` (+policy, `policyBlock`), `ToolPlanner` (+`aiAllowed` — budget auto-pause), `core/agent/bootstrap.php` (load `AgentPolicy`), `heart/api/admin/index.php` (dispatch `/api/admin/agent`), `admin/shared/shell.js` (nav link + icon), `core/agent/tests/actions_selftest.php` (governance tests H1–H6).

---

## 6. How to verify

**Backend enforcement (self-test, when PHP is available):** `php core/agent/tests/actions_selftest.php` — H1: commerce paused → `browse_products` refuses, backend never called. H2: writes paused → a booking refuses. H3: writes paused → reads still work. H4/H5: AI budget paused → deterministic intents still work, non-deterministic ones make no AI call. H6: cap 0 = unlimited (never paused).

**The page (browser, stubbed):** opening `admin/agent.html` renders the kill-switch (LIVE), the four capability toggles, the AI-budget card (cap + live spend + OK/PAUSED), limits & guardrails, provider/routing, the 24h summary, and the recent-activity table; toggling a control fires the settings `PUT` and re-reads. *(Verified in-browser during this build.)*

**Live:** admin logs in → **Agent Control**. Turn the kill-switch off → the next `/api/agent/message` returns 503. Turn **Commerce** off → commerce tools reply "paused". Set a small budget → once spend passes it, the agent stops calling the AI (deterministic-only). The monitor updates every 15s.

---

## 7. What is NOT in this phase (later)
Cost is estimated from token counts × a model price map (good enough for a cap; a precise per-call ledger can come later). Reminder delivery (Phase 6's cron), connections ("gift for my sister"), voice, an own-model path, and content-safety classifiers beyond message-length are future work.

---

*Phase-7 increment only. Built in `wtg-v2` (branch `v2`); no live deploy; migration staged not run; no never-touch engine edited. Governance is one data-driven policy, enforced at the entry, orchestrator and planner — the AI is always a tool under our control.*
