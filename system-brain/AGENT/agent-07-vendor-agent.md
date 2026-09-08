# WorkToGo — Agent Layer, Phase 6: Vendor-Agent (onboard & manage by talking)

> **Purpose:** Document the vendor-agent — a core growth lever. A worker / shopkeeper / contractor must NEVER have to learn the app; instead the agent does their work for them, conversationally: "aap apni skill batao, main aapke liye premium customers laa dunga." The vendor just talks; the agent builds their listing, uploads photos, sets reminders, and reports their status.
> **Status:** Built locally in `wtg-v2` (branch `v2`). **No live/deploy. Migration staged, NOT run.** **No never-touch engine edited** — reuses the existing vendor endpoints via clean adapters. Verified end-to-end in a real browser against the mock.
> **Builds on:** Phases 1–5 (`agent-01`…`agent-06`).
> **Date:** 2026-08-30.

---

## 1. What was built (plain summary)

The vendor opens the chat and is greeted with the pitch — *"Aap apni skill bataiye — main aapke liye premium customers laa dunga. Kuch seekhna nahi, bas baat kijiye 🙂"* — and four taps: **listing banao · mera status · photo upload · weekly reminder**. From there:

- **"listing banao"** → the agent asks **one friendly question at a time** (name → skill → area → experience → languages), then reviews the answers and, on confirm, saves the listing as a **draft** (admin approval unchanged).
- **"photo upload"** → the inline upload widget (reused) → the real work-photo endpoint (admin-approval intact).
- **"mera status"** → a short, friend-like **status card**: jobs, rating, photos, followers, plus **real, data-driven growth hints** ("abhi koi work photo nahi — 3 add karo, trust 2x badhta hai").
- **"weekly reminder"** → a recurring reminder (e.g. weekly work-photo upload), confirmed.

The vendor never touches a dashboard. This is how vendors are won at scale — the agent works *for* them.

---

## 2. Vendor tools (RBAC = vendor role, → existing endpoints)

| Tool | Mode | Maps to |
|---|---|---|
| `start_listing` | read | opens the inline **onboard stepper** (fetches real categories from `GET /api/service/categories`) |
| `save_listing` | **write** | `PATCH /api/vendor/profile` (draft; admin approves — unchanged) |
| `my_status` | read | composes `GET /api/vendor/profile` + `…/service-analytics/stats` + `…/work-photos` → status + hints |
| `set_reminder` | **write** | agent-owned `agent_reminders` (delivery via existing notifications/FCM later) |
| `upload_work_photo` | read (widget) | **reused from Phase 4** → `POST /api/vendor/work-photo` |

All are RBAC-scoped to the vendor role by the registry **and** re-checked by the real endpoints. The onboarding write is **confirmation-gated** (the stepper's review, then the confirm card). The `save_listing` maps the collected answers to the profile fields (`name`, `category_id`, `service_localities`, `experience_years`, `languages`) — and the endpoint keeps the row a **draft**, so the agent proposes and the admin approves, exactly as before.

*(A new `patch()` method was added to the EngineClient so profile updates use the real `PATCH /api/vendor/profile`; the test mock was updated to match.)*

---

## 3. Conversational onboarding (no dashboard to learn)

`start_listing` returns an **`onboard` widget**: an ordered list of typed steps (`text` / `number` / `select` / `multiselect`), the category step pre-filled with the **real** categories so the vendor **taps, never types an id**. The chat UI renders one question at a time with Next; on the last step it shows a **review** ("Naam: … · Bhasha: …", labels not ids), then submits `save_listing` — which the orchestrator gates with a confirm before writing the draft. This is the founder's "agent asks one at a time, friendly" made real, and it's robust (no fragile free-text slot-filling), multilingual-friendly, and reuses the real endpoint.

---

## 4. Status & growth hints (from the vendor's own data)

`my_status` composes the vendor's real profile + booking stats + photo count into a small status object, and derives **honest, data-driven** growth hints — never invented: no work photos → "add 3 photos"; no languages → "add your language"; no experience → "add experience"; not yet active → "listing in review". The status card shows jobs / rating / photos / followers and offers **Add work photo** and **Weekly reminder** as one-tap next actions.

---

## 5. Guardrails & privacy (unchanged)

Register + RBAC-gated (vendor tools are vendor-only; a customer can't reach them, and the endpoints re-check). Writes (`save_listing`, `set_reminder`) are confirmation-gated. The allow-list, per-user rate limit, and counts-only audit all apply. The **privacy wall** is untouched — no vendor PII, profile, or status data ever reaches the AI; the AI only ever saw the scrubbed language, and the vendor's own data flows engine → agent → the vendor. **Draft-then-admin-approval stays**: the agent proposes; admin approves.

---

## 6. File map

**New (agent scope):** `core/agent/tools/VendorTools.php` (`start_listing`, `save_listing`, `my_status`, `set_reminder`); `migrations/2026_08_27_004_agent_reminders.sql` (staged).
**Changed (agent scope):** `EngineClient` (+`patch()`), `ToolRegistry` + `bootstrap` (register/load), `ToolPlanner` (deterministic vendor rules: listing / status / reminder), `AgentMemory` + `AgentActionOrchestrator` (role-aware vendor greeting), `actions_selftest` (mock `patch()`).
**Changed (UI, own scope):** `app/agent/agent.js` (onboard stepper, status card, reminder/listing renderers, vendor greeting + mocks), `app/agent/agent.css` (stepper + status styles).
**Untouched:** `shopping-engine`, `video-engine`, `service-engine`, `orders.js` — read-only; vendor flows reached only through existing endpoints.

---

## 7. How to verify

**Standalone demo (no backend/key)** — serve the repo and open `app/agent/demo.html?role=vendor_service`:
- Greeting shows the recruiting pitch + vendor chips.
- **"listing banao"** → Step 1/5 … Step 5/5 (one question at a time; category = tap, languages = multi-select) → **Review** → **confirm gate** → **"🎉 Listing ban gayi! Admin approve karte hi customers ko dikhne lagegi."**
- **"mera status"** → status card (jobs / rating / photos / followers + growth hints + Add-photo / Weekly-reminder).
- **"photo upload"** → the inline upload widget (vendor-only).
- **"weekly reminder"** → confirm → "Reminder set ⏰".

*(All verified in-browser during this build; console clean.)*

**Live app:** with `agent_enabled=1` (+ the reminders migration for `set_reminder`), the same UI drives the real vendor endpoints — the listing PATCHes the real profile as a draft, status reads real analytics, and the upload hits the real work-photo route. All under RBAC + admin approval.

---

## 8. What is NOT in this phase (later)
Reminder **delivery** (a cron turning due `agent_reminders` into FCM pushes — the store is here, the cron is the follow-up); customer→vendor application (`POST /api/vendors`) for a not-yet-vendor user; area-demand growth hints (needs a vendor-facing demand feed); the self-job ledger (Vision §4.2); voice; and the admin control-room.

---

*Phase-6 increment only. Built in `wtg-v2` (branch `v2`); no live deploy; migration staged not run; no never-touch engine edited. The vendor never learns a dashboard — the agent onboards and manages by talking; writes are confirmed, draft→admin-approval stays, and no vendor data reaches the AI.*
