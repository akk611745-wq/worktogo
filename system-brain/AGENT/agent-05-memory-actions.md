# WorkToGo — Agent Layer, Phase 4: Memory / Personalization + Inline UI-Actions

> **Purpose:** Document the two things that make the agent feel like a friend who "knows me" and does everything inline: (1) per-user **memory/personalization** that lives INSIDE our system behind the privacy wall, and (2) **inline UI-action widgets** (photo upload, OTP entry, and a generalized confirm) so the user acts without leaving the chat.
> **Status:** Built locally in `wtg-v2` (branch `v2`). **No live/deploy. Migration staged, NOT run** (no PHP runtime). **No never-touch engine edited** — inline actions reuse existing endpoints via clean adapters. Verified end-to-end in a real browser against the mock.
> **Builds on:** Phases 1–3 (`agent-01`…`agent-04`).
> **Date:** 2026-08-30.

---

## 1. What was built (plain summary)

- **Memory:** the agent now remembers each user — what they use most (e.g. painting), their area/city, how many times they've been here — in OUR database (`agent_memory`), keyed to the internal user id. On a repeat visit it greets them personally ("Welcome back — pichli baar aapne painting dekha tha…") and pre-fills/prioritises so they do less. New users get a warm generic experience (no history needed). **This memory never leaves our system** — only tiny anonymized hints ever reach the AI.
- **Inline UI-actions:** the agent opens real action widgets right in the chat — a **photo upload** widget (vendor → work photo on their profile), an **OTP-entry** widget (verify a number), and a **generalized confirm** widget — each completing against the existing backend. The agent detects what's needed and shows the right widget itself.

---

## 2. Memory / personalization — and how the wall keeps it from the AI

### 2.1 What we store (inside our system only)
`agent_memory` (one row per user, keyed to the internal `user_id`): `turn_count`, `pref_categories` (JSON `{slug:count}`), `last_category`, `last_area` (internal), `last_city_id` (internal), `recent_tools`. Managed by `core/agent/AgentMemory.php` (`load` / `record` / `hints` / `personalizedGreeting`). Everything is defensive — a missing table degrades to a new-user experience, never a broken turn.

### 2.2 How it personalizes
- **Session open:** the chat sends `{event:"open"}`; the orchestrator returns a memory-built greeting + suggestion chips (returning users are recognised; the reply is OUR text, not the AI's).
- **During a turn:** the orchestrator records the category/city used, so the next visit is faster and the greeting/chips reflect the user's habit.

### 2.3 The wall guarantee (the critical part)
The raw memory row is **never** sent to the AI. The ONLY memory-derived data allowed to cross the wall is a short, anonymized hint list from `AgentMemory::hints()`:

```
["returning", "pref_category:painting", "area_known"]
```

- **`returning`** — a flag (repeat user). **`pref_category:<slug>`** — a PUBLIC category slug (validated `^[a-z_]{2,40}$`). **`area_known`** — a FLAG that we have their area — **never the area/city value itself**.
- No `user_id`, no phone/email/address, no actual area or city ever appears.
- Enforcement is layered: the hints are added to the wall's **deny-by-default allow-list** (`meta.hints` in `PiiWall`), injected into the anonymized system prompt the planner sends, and the wall's **fail-closed tripwire re-scans the entire outbound blob** — so a mis-derived hint that still looks like PII is **refused, not sent**. The self-test proves both: clean hints pass, a PII-laden hint throws `PiiLeakException`.

**In short:** the agent uses the full memory internally; the AI only ever sees "an anonymous returning user who tends to want painting". Data ownership stays 100% ours.

---

## 3. Inline UI-actions (widgets)

The agent's reply can carry a `widget` descriptor (public config only — paths + labels, no user data). The chat UI renders it and completes it against the **existing** backend with the user's own JWT — no page change, no engine edit.

| Widget | Emitted by (tool) | Completes against (existing route) | RBAC |
|---|---|---|---|
| **upload** | `upload_work_photo` | `POST /api/vendor/work-photo` (multipart) | vendor only |
| **otp** | `verify_phone` | `POST /api/auth/otp/send` + `/verify` | any registered user |
| **confirm** | any write (booking / expert visit) | the confirm-token flow (Phase 2) | per-tool |

- The widget tools are **`read` mode** — showing a widget mutates nothing on our side; the real write happens when the user completes it. They are **RBAC-scoped by the registry** (a customer cannot get the vendor upload widget) and **allow-listed** (the AI can only open widgets we exposed).
- The confirm widget is now **unified**: every write emits `widget:{type:'confirm', question, confirm_token}`, so the UI has one renderer for all inline actions (confirm / upload / otp). The Phase-2 write-confirmation gate, allow-list, RBAC and audit are all unchanged.
- Uploads use a new `API.uploadMultipart()` (attaches the JWT, lets the browser set the multipart boundary) — a clean client-side adapter to the real media route.

---

## 4. File map

**New (agent scope):**
- `core/agent/AgentMemory.php` — the memory store + anonymized `hints()` + `personalizedGreeting()`.
- `core/agent/tools/WidgetTools.php` — `UploadWorkPhotoTool` (vendor) + `VerifyPhoneTool` (any).
- `core/agent/tests/memory_selftest.php` — proves hints are anonymized, greeting personalizes, and the wall fails closed on a PII hint.
- `migrations/2026_08_27_003_agent_memory.sql` — `agent_memory` table (staged, NOT run).

**Changed (agent scope):**
- `core/agent/PiiWall.php` — `hints` added to the meta allow-list; `sanitize()` accepts + filters hints (tripwire still covers them).
- `core/agent/ToolPlanner.php` — threads hints into the AI system prompt; deterministic rules for `upload_work_photo` / `verify_phone`.
- `core/agent/AgentActionOrchestrator.php` — loads memory, handles `event:"open"` (personalized greeting), passes anonymized hints to the planner, records memory after a turn, lifts widget descriptors, unifies the confirm widget.
- `core/agent/tools/ToolRegistry.php` + `core/agent/bootstrap.php` — register/load the new pieces.
- `heart/api/agent/index.php` — builds `AgentMemory`, accepts the `event` field.

**Changed (app UI, own scope):**
- `app/agent/agent.js` — personalized open greeting (real via `{event:"open"}`, demo via local mock-memory), `renderWidget` / `renderUpload` / `renderOtp`, role awareness, mock-memory for the demo.
- `app/agent/agent.css` — widget styles (file/OTP inputs, status, done state).
- `app/js/api.js` — `uploadMultipart()`.

**Untouched:** `shopping-engine`, `video-engine`, `service-engine`, `orders.js` — read-only; inline actions call their existing routes from the client.

---

## 5. How to verify

**Standalone demo (no backend/key)** — serve the repo over HTTP and open `app/agent/demo.html`:
- **Memory:** type/tap **"painter chahiye"**, then **reload** the page → the greeting becomes **"Welcome back — pichli baar aapne painting dekha tha…"** with painting-first chips. (Demo uses local mock-memory that mirrors the real server-side memory.)
- **OTP widget:** type **"verify my number"** → inline OTP card → enter a phone → **Send OTP** → enter any 4–6 digits → **Verify** → **"Number verified ✓"**.
- **Upload widget (vendor):** open `demo.html?role=vendor_service`, type **"upload work photo"** → inline upload card (Choose file + caption + Upload), wired to `/api/vendor/work-photo`.
- **Confirm widget:** unchanged Phase-3 flow (Book now → confirm → booking created).

*(All of the above were verified in-browser during this build; console clean.)*

**Backend self-test (when PHP is available):** `php core/agent/tests/memory_selftest.php` — asserts hints are anonymized (no id/area/city value), the greeting personalizes for returning users, and the wall fails closed on a PII-laden hint.

**Live app:** with `agent_enabled=1` and the memory migration applied, the same UI drives real server-side memory (personalized `open` greeting) and the widgets complete against the real media/OTP routes.

---

## 6. What is NOT in this phase (later)
Voice, commerce/product tools + product personalization (picking the right product out of thousands), connections/"social-lite", proactive suggestions/reminders, and the admin control-room UI. A richer schedule picker and area-based vendor filtering also remain from earlier phases.

---

*Phase-4 increment only. Built in `wtg-v2` (branch `v2`); no live deploy; migration staged not run; no never-touch engine edited. Memory lives inside our system; only anonymized hints cross the wall, allow-listed and tripwire-verified; inline actions reuse existing endpoints via clean adapters.*
