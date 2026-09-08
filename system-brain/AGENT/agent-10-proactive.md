# WorkToGo — Agent Layer, Phase 9: Proactive Nervous-System (reminder delivery + demand alerts)

> **Purpose:** Turn the agent from reactive into **proactive**. Two reflexes make it a real assistant, not just a chat box: (1) reminders it set actually **FIRE** and reach the user at the right time; (2) the system watches its **own data** and tells the admin/team **where to grow** — "X category ki demand hai, but few/no vendors here." Both are entirely OUR-system-side: the external AI is never involved and no PII leaves the building.
> **Status:** Built locally in `wtg-v2` (branch `v2`). **No live/deploy. Migration staged, NOT run.** **No never-touch engine edited** — reuses the existing FCM/alerts/cron mechanisms via clean adapters. Logic verified by a committed self-test; the reminder chat-surface verified in a real browser (mock).
> **Builds on:** Phases 1–8 (`agent-01`…`agent-09`).
> **Date:** 2026-08-31.

---

## 1. Reminder delivery (the store finally fires)

Phase 6 let a vendor say *"weekly work-photo upload"* / *"kal yaad dilana"* and stored it in `agent_reminders` — but nothing delivered it. Phase 9 wires delivery.

- **`cron/agent_reminders.php`** — a small watchdog (mirrors `cron/delivery_lifecycle.php`: CLI or `CRON_SECRET`, `flock`, JSON summary). Runs every ~1–5 min, finds **due** reminders and delivers each through the **existing** notification channel — **`FcmNotifier::notifyUser()`** (in-app bell feed **+** FCM push, log-mode when no service account) — then reschedules it.
- **`core/agent/ReminderDelivery.php`** — the pure, injectable worker (DB + a "notify one user" callable passed in; the cron wires the real `FcmNotifier`, the self-test a mock). Guarantees:
  - **Idempotent** — each due row is **claimed** with a conditional `UPDATE` that advances `next_at` (or deactivates a `once`) **before** delivery; a claim that updates 0 rows (another run took it) is skipped, so a reminder **never fires twice** — even without the cron's `flock`.
  - **Recurring reschedules** — `weekly` → +7 days, `daily` → +1 day, computed **from NOW** so a backlog never bursts; `once` is completed (deactivated).
  - **Safe** — a delivery failure is recorded in `last_error` and never throws; a missing table degrades to "nothing to do".
- **`list_reminders`** (new read tool, `core/agent/tools/ReminderTools.php`) — the agent can also **surface** the user's upcoming reminders in chat ("mere reminders" → cards with cadence + next-due). Own-data read, RBAC = customer + vendor, ungated `core` capability. The planner gets a deterministic rule (a reminder word **+** a listing verb like *mere/list/dikha/show*) that beats the vendor set-reminder rule.

**Migration adds** the bookkeeping columns delivery needs: `agent_reminders.last_fired_at`, `fire_count`, `last_error` (idempotent `ADD COLUMN IF NOT EXISTS`).

---

## 2. Demand-detection → admin growth alerts ("the system tells us where to grow")

The agent is the **only** thing that sees a user **ask** for something and get **few or no** results — a booking can't record a category with zero vendors, but a **search can**. Phase 9 captures those asks and turns them into growth intel.

- **Capture (in the orchestrator, our scope):** after a real `find_vendors` (service) or `browse_products` (commerce) read, `AgentActionOrchestrator::captureDemand()` records **metadata only** into `agent_demand_events`: `scope`, `category_key`, INTERNAL `city_id`, optional `area` text, and `result_count` — **how many the engine returned = a live supply proxy** — plus the INTERNAL `user_id` (for distinct-user counting; it never leaves the system). Fail-open: it never breaks a turn. **`core/agent/DemandSignals.php::record()`** does the write.
- **Detect (cron):** **`cron/agent_demand_detect.php`** → `DemandSignals::detect()` aggregates recent events per `(scope, category, city)`, **upserts** `agent_demand_signals` (ask_count / distinct_users / supply_estimate / status), and when a group is **high-demand AND low-supply** it raises an **admin alert** through the **existing** mechanisms — `FcmNotifier::notifyRole('admin', …)` (feed + push) **plus** a row in the shared **`alerts`** stream (same table the delivery watchdog uses). A **cooldown** stops it re-alerting the same signal.
- **Thresholds are data-driven** (`app_settings.agent_demand_thresholds`, no hardcoding): `window_days`, `min_asks`, `min_distinct_users`, `max_supply`, `cooldown_hours`. Default: **≥ 5 asks from ≥ 3 users with ≤ 2 vendors/stores in 14 days** → alert.
- **Admin surface:** a **🌱 Growth Signals** card in the **Agent Control-Room** (`admin/agent.html`), backed by a new read-only endpoint **`GET /api/admin/agent/growth-signals`** (`heart/api/admin/agent.php`, under the existing `ROLE_ADMIN` gate). It lists each signal (scope · category · where · asks · users · supply · signal-state), flagging the ones that qualify as **"grow here" / "alerted"**. Metadata only — no PII, no message text.

This complements the admin dashboard's existing booking-based `area_demand` block: bookings show realized demand where supply exists; **this** shows the demand the platform is **failing to serve**.

---

## 3. Privacy wall intact

All of Phase 9 is **our-system-side**. Reminder delivery and demand computation run entirely in cron/PHP over our own DB. The **external AI is never called** for either, and **no PII leaves the building** — demand rows hold market metadata (category / area / counts) plus an INTERNAL `user_id` used only for distinct-user counting. Governance still applies: the reminder cron respects the global **kill-switch** (`agent_enabled`) and its own toggle (`agent_reminders_enabled`); demand detection is gated by `agent_demand_detect_enabled`. Everything is tunable from the control-room.

---

## 4. File map

**New:**
`core/agent/ReminderDelivery.php` (delivery worker), `core/agent/DemandSignals.php` (capture + detect), `core/agent/tools/ReminderTools.php` (`list_reminders`), `cron/agent_reminders.php`, `cron/agent_demand_detect.php`, `core/agent/tests/proactive_selftest.php`, `migrations/2026_08_27_007_agent_proactive.sql` (staged).

**Changed (agent scope + admin surface):**
`AgentActionOrchestrator` (+`captureDemand`, +optional `DemandSignals`), `ToolPlanner` (+"show my reminders" rule), `ToolRegistry` + `bootstrap` (register/load), `AgentPolicy` (`list_reminders` = `core`), `heart/api/agent/index.php` (inject `DemandSignals`), `heart/api/admin/agent.php` (+growth-signals endpoint), `admin/agent.html` (+Growth Signals card), `app/agent/agent.js` (list_reminders handler + renderer + mock + chip).

**Untouched:** `shopping-engine`, `video-engine`, `service-engine`, `orders.js` — read-only; reminders/demand reach the world only through the existing FCM/alerts/cron mechanisms.

---

## 5. How to verify

**Self-test (logic, when PHP is available):** `php core/agent/tests/proactive_selftest.php` —
R1 a due reminder is delivered (mock notification fires); R2 a **weekly** reminder reschedules to **now + 7 days**; R3 a **once** completes (deactivates); R4 **idempotent** — a lost claim does **not** re-deliver; R5 a failed delivery is counted + noted.
D1 `record()` writes one clean event (no PII); **D2 a 5-ask / 3-user / 0-supply signal raises ONE admin alert** and marks it alerted; D3 below-threshold does not alert; D4 alerted-within-cooldown does not re-alert.

**Browser (mock, verified this build):** `app/agent/demo.html?role=vendor_service` → tap **"mere reminders"** → the agent replies *"Yeh rahe aapke reminders ⏰"* and renders the reminder cards (title · cadence · next-due). Console clean.

**Live app:** apply `2026_08_27_007_agent_proactive.sql` on staging, then schedule the two crons (`agent_reminders.php` every ~1–5 min, `agent_demand_detect.php` hourly). A due reminder fires a real notification; five asks for a category with no vendors in a city raises an admin alert visible in **Agent Control → Growth Signals** (and the admin bell).

---

## 6. What is NOT in this phase (later)
Customer-set reminders (only vendor `set_reminder` exists today; the store/delivery are now ready for a customer write tool); natural-language reminder times ("kal 11 baje" → an exact `next_at` parser); cross-city demand rollups and a vendor-facing "demand near you" feed; auto-resolving a growth signal once supply is added; voice; the own-model path.

---

*Phase-9 increment only. Built in `wtg-v2` (branch `v2`); no live deploy; migration staged not run; no never-touch engine edited. Reminder delivery and demand detection are entirely our-system-side — the external AI is never involved and no PII leaves the building; both reuse the existing FCM / alerts / cron mechanisms through clean adapters.*
