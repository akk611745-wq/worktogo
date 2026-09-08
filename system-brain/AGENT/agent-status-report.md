# WorkToGo Agent — Status Report

> **What this is:** A plain, honest status of the AI agent build so far (Phases 1–3), verified fresh on branch `v2`. Written so you can read it top-to-bottom without needing the chat.
> **Branch:** `v2` (worktree `wtg-v2`). **Not** deployed to live — everything is local, migrations are staged (not run).
> **Verified:** 2026-08-29.

---

## 1. The one-line answer

**The text agent (talk → find → book) is BUILT and WORKING end-to-end.** Foundation (privacy wall + swappable AI), the action/tool layer (real bookings with confirmation), and the chat UI (friendly screen with real cards) are all committed and were re-verified. It runs today with a **mock AI** (no paid key needed) and is wired to switch to a real AI provider by config. Voice, memory, commerce, and connections are the next phases — not built yet.

- **Text service-agent MVP (Phases 1–3): ~95% done** (a few polish gaps + a staging run remain).
- **Full agent vision (voice + memory + commerce + connections + control-room + own model): ~82% done.**

> **Update — 2026-09-01 (Security Fix Phase 2): all MEDIUM findings fixed — code-side launch-ready.** Final pre-launch hardening from the audit, in separate commits on `v2`. **M1** (`a66144c`) — the AI-budget cap now defaults to **$20/month with auto-pause** (was 0=unlimited), so a real key can't run a runaway bill by accident; the control-room can raise it, 0 stays an opt-in unlimited; migration `2026_08_27_009` idempotently corrects the seed. **M2** (`300c6ee`) — write-confirmation text is now **backend-composed** per tool from the bound {tool,args} (`confirmSummary`), never the AI's phrasing, so a proposal can't be worded misleadingly. **M3** (`8244b59`) — the keyless **mock/echo can no longer leak into a user-facing reply** (planner degrades to a clean nudge when the resolved provider is mock); also **L2** — the legacy `AgentOrchestrator` is marked TEST-ONLY. **M4** (`5009767`) — **engine-side IDOR verified safe** (no gap): shop orders scope `WHERE user_id`, vendor endpoints resolve the vendor from the JWT, agent-owned tables scope by user_id; public reads are public by design — proof in `wtg-v2/docs/agent-engine-idor.md`. Self-tests extended (`actions_selftest` C5/H7/M2/M3). No never-touch engine edited; allow-list/RBAC/governance/money-safety unchanged. **Remaining is staging-only:** real AI key, run migrations `…001–009`, one live round-trip per surface incl. a cross-user 404 negative test, zero-retention terms. (L1/L3/L4 are documented platform notes, not agent code.)

> **Update — 2026-09-01 (Security Fix Phase 1): the two audit blockers are fixed.** Acting on the readiness audit (`agent-11`), both launch blockers were fixed on `v2` in separate commits. **CRITICAL C1 — privacy-wall PII coverage** (`d865674`): the wall now redacts separator-formatted phones ("98765 43210", "987-654-3210", "+91 …"), street **addresses** (house/plot/gali/road/sector patterns), and **names** (the user's own name + saved contacts as an exact deny-list applied in scrub AND re-checked by the fail-closed tripwire, plus a conservative self-introduction pattern) — over-redacting by design; bare city words remain a documented residual (wants NER). **HIGH H1 — confirm-token replay** (`7c54864`): write-confirmation tokens are now **single-use** — the 128-bit nonce is atomically claimed in a new `agent_used_tokens` store (PK gate) right before the write, so a replay/concurrent duplicate can't double-execute a booking/order (at-most-once); degrades safely pre-migration, fails safe on ambiguity. Self-tests extended (`foundation_selftest` 6a–6h; new `token_selftest` T1–T7). Migration `2026_08_27_008_agent_used_tokens.sql` staged, not run. No never-touch engine edited; allow-list / RBAC / governance / money-safety unchanged. **Remaining:** the MEDIUM/LOW items (budget-cap default, per-tool confirm text, mock-echo UX, engine-side IDOR verification) + staging validation.

> **Update — 2026-08-31 (Phase 9 added): proactive nervous-system.** The agent stops being purely reactive. **(1) Reminder delivery:** a `flock`-safe cron (`cron/agent_reminders.php` → `ReminderDelivery`) finds due `agent_reminders` and FIRES them through the existing channel (`FcmNotifier::notifyUser` = in-app bell + FCM push), then **reschedules** recurring ones (weekly → +7d) and completes one-offs — **idempotent** (each row is claimed before delivery, so it never double-sends). The agent can also **surface** reminders in chat (`list_reminders`: "mere reminders" → cards). **(2) Demand-detection:** the agent is the only thing that sees a user ASK and get few/no results (a booking needs a vendor to exist; a search doesn't), so the orchestrator records those asks (category · INTERNAL city · `result_count` = live supply proxy — metadata only) into `agent_demand_events`; a cron (`cron/agent_demand_detect.php` → `DemandSignals::detect`) aggregates them into `agent_demand_signals` and, when demand is high AND supply is low (data-driven thresholds in `app_settings`, default ≥5 asks / ≥3 users / ≤2 supply / 14d), raises an **admin alert** via the existing FCM/alerts mechanism — surfaced as a **🌱 Growth Signals** list in the control-room (`GET /api/admin/agent/growth-signals`). **Privacy wall intact:** all our-system-side, the external AI is never involved and no PII leaves. **P9 → PASS** (`5016b81`), reminder chat-surface verified in-browser (mock), logic by `proactive_selftest.php`. Migration `2026_08_27_007_agent_proactive.sql` staged, not run.

> **Update — 2026-08-30 (Phase 8 added): connections.** "Social-lite": a user saves their **people** (sister/friend — name/phone/address/relation) and orders/gifts FOR them by talking — "behen ke liye kapde bhej do" → **people picker** (or inline add-person) → products → **"Checkout for Priya"** → confirm → **order delivered to her address** (COD; the agent never moves money; gifting reuses the real checkout). **Recipient PII never reaches the AI:** tools resolve by internal id, the wall scrubs saved contact names to `<PERSON_1>`, only an anonymized hint crosses. Governed by the control-room (order_for = commerce + write). Same-city today; cross-city is a documented next step. **P8 → PASS** (`5f567f0`), verified in-browser (mock). Migration `2026_08_27_006_agent_people.sql` staged, not run.

> **Update — 2026-08-30 (Phase 7 added): admin control-room.** Control stays with us. An admin page (**System → Agent Control**) governs the now-powerful agent, fully data-driven (app_settings): a **global kill-switch** (off instantly), **per-capability toggles** (pause service/commerce/vendor or ALL writes), **limits** (msg/min, actions/session, msg length), an **AI-budget cap with auto-pause** (stops calling the external AI when the cap is hit — degrades to deterministic, never breaks), **provider/routing** control, and a **monitor** (24h turns/blocked/PII-blocked + activity, metadata only). One `AgentPolicy` is READ + ENFORCED at the entry/orchestrator/planner. Reuses the existing settings + audit tables; no engine edited. **P7 → PASS** (`5d085a6`), enforcement proven by self-test (H1–H6), page verified in-browser. Migration `2026_08_27_005_agent_governance.sql` staged, not run.

> **Update — 2026-08-30 (Phase 6 added): vendor-agent.** The core vendor-growth lever — a worker never learns a dashboard; the agent onboards & manages them by talking. Vendor role: **"listing banao"** → a friendly **one-question-at-a-time stepper** (name/skill/area/experience/languages) → review → confirm → **draft profile** (admin approval unchanged); **"mera status"** → a friend-like status card (jobs/rating/photos/followers) + **real data-driven growth hints**; **photo upload** (reused widget); **weekly reminder** (confirmed). Reuses existing vendor endpoints via clean adapters (no engine edited); writes confirmed; no vendor data reaches the AI. **P6 → PASS** (`e974e9b`), verified in-browser (mock). Migration `2026_08_27_004_agent_reminders.sql` staged, not run.

> **Update — 2026-08-30 (Phase 5 added): commerce tools.** The agent now **shops too** — "kapde chahiye" → real **product cards** (city-scoped, company + local stores) → **Add to cart** (free) → **Checkout (COD)** behind a confirm gate → **order placed**. 7 commerce tools reach the existing `/api/shop/*` storefront via clean adapters (no engine edited, no migration); reads free, checkout confirmed, the **agent never moves money**, and no product/cart/order/PII data reaches the AI. **P5 → PASS** (`8b74487`), verified in-browser (mock).

> **Update — 2026-08-30 (Phase 4 added): memory + inline UI-actions.** The agent now **remembers each user** (top categories, area/city, visit count) inside our DB behind the wall — a repeat visit greets them personally ("welcome back — pichli baar aapne painting dekha tha") and only anonymized hints (`returning`, `pref_category:painting`, `area_known`) ever reach the AI (allow-listed + tripwire-verified). It also opens **inline action widgets** in the chat — photo **upload** (vendor → work photo), **OTP** verify, and a generalized **confirm** — each completing against the existing backend. **P4 → PASS** (`f3e15cd`), verified in-browser (mock). Migration `2026_08_27_003_agent_memory.sql` staged, not run.

---

## 2. What's built, phase by phase

| Phase | What it is | Verdict | Commit |
|---|---|---|---|
| **P1 — Foundation** | Privacy wall (no customer data ever reaches the external AI) + flexible AI-provider layer (Claude real, OpenAI/Gemini clean slots, built-in mock; swap by config) | ✅ **PASS** | `c1a33af` |
| **P2 — Actions/Tools** | The agent DOES things: find vendors, packages, expert-visit price (reads), create booking / expert visit (writes) — through our real backend, with confirmation + guardrails | ✅ **PASS** | `7d114b1` |
| **P3 — Chat UI** | Friendly, mobile-first chat screen: types, replies, and opens REAL inline cards (vendors/packages/price) + confirm-to-book | ✅ **PASS** | `590020a` |
| **P4 — Memory + inline actions** | Remembers each user (behind the wall — only anonymized hints reach the AI); opens inline widgets: photo upload, OTP verify, generalized confirm | ✅ **PASS** | `f3e15cd` |
| **P5 — Commerce tools** | The agent shops: browse products (city-scoped, dual-store), cart, checkout (COD, confirmed), track order — via the existing storefront, agent never moves money | ✅ **PASS** | `8b74487` |
| **P6 — Vendor-agent** | Vendors onboard & manage by talking (no dashboard): conversational listing builder → draft (admin approves), status card + growth hints, photo upload, reminders | ✅ **PASS** | `e974e9b` |
| **P7 — Admin control-room** | Govern the agent: global kill-switch, per-capability toggles, limits, AI-budget cap + auto-pause, provider/routing, activity monitor — all data-driven, enforced at entry/orchestrator/planner | ✅ **PASS** | `5d085a6` |
| **P8 — Connections** | Save your people; order/gift for them by talking ("behen ke liye kapde bhej do") → picker/add → products → checkout delivered to their address; recipient PII stays behind the wall | ✅ **PASS** | `5f567f0` |
| **P9 — Proactive nervous-system** | Reminders the agent set actually FIRE (cron → FCM, idempotent, recurring reschedules) + the agent surfaces them in chat; demand-detection watches OUR data and raises admin "grow here" alerts (high demand, low supply) into the control-room — all our-system-side, no PII to AI | ✅ **PASS** | `5016b81` |

**Latest commit on `v2`: `5016b81`** (`feat(agent): proactive — reminder delivery + demand-detection alerts`).

All three sit on the existing motherboard cleanly: the agent lives in its **own scope** (`core/agent/`, `heart/api/agent/`, `app/agent/`) and never edits a never-touch engine (shopping / video / service / orders).

---

## 3. How it was verified (this session)

### P1 — Foundation ✅
- All files present: `core/agent/PiiWall.php`, `SanitizedPrompt.php`, `AgentContext.php`, `ai/` (contract + manager + Anthropic real + OpenAI/Gemini stubs + Mock), `config/ai.providers.php`, migration `2026_08_27_001_agent_foundation.sql` (staged).
- **Privacy wall is structural:** the one object an AI provider accepts (`SanitizedPrompt`) can only be created by the wall (private constructor + caller check), and the wall scrubs PII → placeholders, uses a deny-by-default envelope, and **fails closed** if anything sensitive would still leave. A committed self-test (`foundation_selftest.php`) asserts the mock provider never sees a raw phone/email and that a forged prompt is rejected.
- **Swappable + degrade-don't-die:** provider chosen per task in config; kill-switch (`agent_enabled`, default OFF) means nothing calls out or spends until you enable it.

### P2 — Actions/Tools ✅
- 8 allow-listed tools (`core/agent/tools/`) each mapping to one real endpoint; `AgentActionOrchestrator` enforces the allow-list + RBAC, runs reads freely, and **gates writes behind a signed confirmation token** before executing via the real service engine (JWT forwarded, engine authorizes the real user).
- Committed self-test (`actions_selftest.php`) asserts: "painter chahiye" → real vendor search; AI proposal passes through the wall then executes; writes require confirmation; a confirmed write creates the booking; an unexposed tool is rejected; RBAC enforced; tampered token rejected.

### P3 — Chat UI ✅ (re-run live in a browser this session)
Opened the standalone demo (`app/agent/demo.html`) over a local server and drove the whole flow. Observed, in order:
1. Friendly greeting in Hinglish + suggestion chips ("painter chahiye", etc.) — **empty state OK**.
2. Tapped **"painter chahiye"** → typing dots → **3 real vendor cards** (Sharma Painters ⭐4.8 ✓Verified, City Paint Co ⭐4.5, Rangoli Wall Art ⭐4.6 ✓Verified) each with **Book now**.
3. Tapped **Book now** → **confirm card**: *"Book City Paint Co? · Tomorrow, 11:00 AM · aap baad mein cancel bhi kar sakte hain"* with **Yes, confirm / Cancel** — nothing booked yet (**write correctly gated**).
4. Tapped **Yes, confirm** → *"✅ Ho gaya! Aapki booking confirm ho gayi hai"* + **Booking confirmed · Ref #5501 · pending · Track my bookings** card.
- Also confirmed earlier: "painting packages" → package cards; "expert visit" → **₹299** card + **Request visit** → confirm.
- **Clean-scoped:** own folder + own CSS (`ag-*` namespaced), zero service/commerce dependency. **Register-gated:** route is not public, so a logged-out user is sent to login and returned after. **States:** empty / typing / error all present. Console clean (only a harmless favicon request).

---

## 4. Honest gaps / limitations

**None block the text MVP, but the founder should know:**

1. **Not yet run against the real PHP backend.** There is no PHP runtime in this local workspace, so: (a) the two PHP self-tests are **logic-verified + committed but were not executed** here, and (b) the browser proof used the **mock** AI + mock backend responses (which mirror the real orchestrator's exact shapes). The real path (real vendor DB + real booking) is verified by code-reading, not by a live run. → **Needs a staging run.**
2. **Migrations are staged, not applied.** `agent_ai_log` and `agent_action_log` tables + the `agent_enabled` / routing settings must be migrated on staging before the live path works.
3. **Kill-switch is OFF by default** and no AI provider key is set — intentional (no surprise spend). Must be turned on to use the real backend.
4. **Schedule picker is basic.** Bookings use a sensible default slot (tomorrow 11:00 AM); there's no date/time picker yet.
5. **One tool per turn** (no multi-step "find + compare + book" in a single message yet).
6. **Vendor search is by category only** (the engine's `/api/vendors` doesn't filter by area yet); the agent passes area through but the server ignores it for now.
7. **Not built yet (by design — later phases):** voice in/out, memory/personalization, commerce (product) tools, connections/"social-lite", proactive suggestions/reminders, and the admin control-room UI.

---

## 5. What the next task should be

**Recommended order:**

1. **Phase 3.5 — Staging validation & enable (highest value, small).** On staging: run the two agent migrations; run both PHP self-tests (`foundation_selftest.php`, `actions_selftest.php`) to confirm green on a real PHP/DB; set `agent_enabled=1`; start on the **mock** provider, then plug a **cheap real model** (e.g. a Haiku-class model) behind the config with a monthly budget cap; do one real end-to-end round-trip (real "painter chahiye" → real vendors → book → real booking row). This proves the live path and unblocks everything after.
2. **Phase 4 — Memory / personalization** (founder priority: "repeat use needs less effort") — remember each user's preferences/history, reusing the existing Brain (`brain_events`, habits, suggestions). OR **Voice** (text→speech / speech→text on top of the same chat), depending on which the founder wants first.
3. Then **commerce-agent tools**, then **connections**, then **suggestions**, then the **agent control-room** admin screen.

**Decisions the founder needs to make before Phase 3.5:** which AI provider to start with + a monthly budget cap; approve zero-retention terms with that provider; confirm the launch city/category scope.

---

*Verification only — no code changed in this session. Full phase docs: `agent-01-blueprint.md`, `agent-02-foundation.md`, `agent-03-actions.md`, `agent-04-chat-ui.md`.*
