# WorkToGo — Agent Layer, Phase 3: Chat UI (text conversation, cards, actions)

> **Purpose:** Document the TEXT chat screen built on the Phase-1 foundation (privacy wall + AI-provider layer) and the Phase-2 action/tool layer. A registered user can now actually talk to the agent by text and watch it work: it replies naturally and **opens real UI inline** — vendor cards, package cards, an expert-visit price, a booking confirmation — and takes actions on confirm.
> **Status:** Built locally in `wtg-v2` (branch `v2`). **No live/deploy.** Verified end-to-end in a real browser against the built-in mock (no backend, no AI key). Voice / memory / commerce / connections are NOT in this phase.
> **Builds on:** `agent-01-blueprint.md` (§2 component 8 — UI-control), `agent-02-foundation.md`, `agent-03-actions.md`.
> **Date:** 2026-08-27.

---

## 1. What was built (plain summary)

A clean, friendly, mobile-first chat screen — WhatsApp/ChatGPT-like — where the user types and the agent replies in a warm, India-friendly voice (Hinglish). Crucially, the agent doesn't just chat: when the user says *"painter chahiye"*, real **vendor cards** slide in; tapping **"Book now"** shows an inline **"Book this? Yes/No"** confirmation; on **Yes**, the real backend creates the booking and a **"Booking confirmed"** card appears. Packages and the expert-visit price render as real cards too.

It lives entirely in its own scope (`app/agent/`, own CSS), register-gated, and reuses the app's existing auth/API/router plumbing. All real work still goes through the agent orchestrator on the server (privacy wall → tools → backend); this phase is the face on top.

---

## 2. Design — the chat UI + inline cards + confirmation

### 2.1 The screen
- **Header** — back button, assistant avatar, name, online status.
- **Messages** — user bubbles (right) and agent bubbles (left), plus inline **cards** rendered right in the conversation.
- **Composer** — auto-growing text box + send; **suggestion chips** in the empty state ("painter chahiye", "plumber chahiye", "painting packages", "expert visit").
- **States** — friendly greeting (empty), animated typing dots (loading), and graceful error bubbles (never a dead screen). The agent talks like a friend, not a form.

### 2.2 How it renders REAL inline UI
Each turn calls `POST /api/agent/message`; the server returns `{ reply, tool, executed, data, requires_confirmation, confirm_token, … }`. The UI renders by `tool` + shape:

| Result | Rendered as |
|---|---|
| `find_vendors` → `data.vendors[]` | **Vendor cards** — name, category, ⭐ rating, experience, ✓verified, **Book now** |
| `get_packages` → `data.packages[]` | **Package cards** — name, description, ₹/sqft, features |
| `get_expert_visit_price` → `{is_free, amount}` | **Expert-visit price card** — FREE/₹amount + **Request visit** |
| `requires_confirmation` + `confirm_token` | **Confirmation card** — "Book this? · Tomorrow 11:00 AM" + **Yes, confirm / Cancel** |
| `executed` (`create_booking` / `request_expert_visit`) → `data.booking_id` | **Success card** — ✅ confirmed, Ref #, **Track my bookings** |

The real data (vendor names, ids, prices) comes from our backend straight to the card — it **never went to the AI** (the AI only saw scrubbed language in the planner step).

### 2.3 The confirmation flow (writes)
Reads render immediately. A **write** never fires on the first tap:
1. Tapping **Book now** on a vendor card sends `{action:{tool:"create_booking", args:{vendor_id, scheduled_at}}}` — the real `vendor_id` comes from the tapped card, a sensible default slot (tomorrow 11:00) from the client.
2. The backend returns `requires_confirmation` + a signed `confirm_token` (nothing written yet). The UI shows a clear **"Book Sharma Painters? · Tomorrow, 11:00 AM"** card.
3. On **Yes, confirm**, the UI sends `{confirm_token}` and the backend executes the booking via the real service engine; a success card appears. **Cancel** dismisses it.

This is the same two-step, confirm-gated contract Phase 2 enforces server-side — the UI simply gives it a friendly face. The agent never completes payment; a paid expert visit still sends the user to pay themselves later.

### 2.4 Clean separation
- All UI code is under **`app/agent/`** (`agent.js` + `agent.css`), namespaced `ag-*`, with **zero** dependency on service/commerce styles — it renders identically in the app and in the standalone demo. This mirrors the existing self-contained `app/commerce/` precedent.
- The only shared-file touches are minimal and non-invasive: **one route line** in `router.js` and a **small launcher button** in `index.php`.

---

## 3. Register-gate & wiring
- The `agent` route is added to `ROUTER.PAGES` and is **not** in `PUBLIC_PAGES`, so the router's existing auth guard redirects a logged-out user to login and returns them to the chat after login. `render()` also re-checks `AUTH.isLoggedIn()` defensively.
- Requests go through the shared `API` client (`API.agentMessage` / `API.agentStatus`), which attaches the user's `Authorization: Bearer` token automatically — so the server authorizes the real user. A 503 (kill-switch off) becomes a friendly "assistant not enabled yet" bubble.
- An app-wide 🤖 launcher (fixed button) opens `#agent`; `agent.css` hides it while the chat is open.

---

## 4. File map

**New (own scope, `app/agent/`):**
- `agent.js` — the chat page (ES module, `export render(app, opts)`): shell, bubbles, typing, suggestion chips, card renderers (vendor/package/expert/confirm/success), the propose→confirm write flow, real transport (`API.agentMessage`) **and** a built-in mock transport that mirrors the orchestrator's response shapes.
- `agent.css` — self-contained, mobile-first, dark, `ag-*` namespaced styles.
- `demo.html` — a standalone, backend-free harness (`WTG_AGENT_MOCK=true`) that runs the real UI against the mock — openable in any browser to preview the whole flow with no login/key/server.

**Changed (minimal, shared):**
- `app/js/router.js` — one line registering the `agent` route (register-gated by omission from `PUBLIC_PAGES`).
- `app/index.php` — a small, scoped app-wide assistant launcher (FAB).

**Untouched:** `shopping-engine`, `video-engine`, `service-engine`, `orders.js`, and all service/commerce pages — no edits.

---

## 5. How to verify

**A. Standalone demo (no backend, no key) — recommended:**
Serve the app folder over HTTP (ES modules need `http://`, not `file://`) and open the demo:
```bash
npx http-server "wtg-v2" -p 8801   # or any static server rooted at the repo
```
Open `http://127.0.0.1:8801/app/agent/demo.html` and:
1. Tap **"painter chahiye"** → agent replies + **3 real vendor cards** render.
2. Tap **Book now** on a vendor → agent shows **"Book Sharma Painters? · Tomorrow, 11:00 AM"** with **Yes/Cancel** (nothing booked yet).
3. Tap **Yes, confirm** → **"Booking confirmed · Ref #5501"** success card.
4. Type **"painting packages"** → **package cards**; type **"expert visit"** → **₹299 expert-visit card** with **Request visit** (→ confirm → success).

*(Verified in-browser during this build: all four steps render and behave as above.)*

**B. Live app (real backend):** with the user logged in, `agent_enabled=1`, and the AI provider/mock configured, tap the 🤖 launcher (or open `#agent`). The same UI now drives the real orchestrator — `find_vendors` hits the real vendor DB, and confirming a booking creates it through the real service engine.

---

## 6. What is NOT in this phase (later)
Voice input/output, conversation memory/personalization, commerce tools, connections/social-lite, a rich schedule picker (a default slot is used for now), and multi-tool turns. The AI is exercised through the existing mock/provider layer — no live key is required to see the flow.

---

*Phase-3 increment only. Built in `wtg-v2` (branch `v2`); no live deploy; no never-touch engine or service/commerce page edited. The chat is a thin, friendly face over the Phase-1/2 backend — the AI proposes language, our backend authorizes and executes, and the user confirms every write.*
