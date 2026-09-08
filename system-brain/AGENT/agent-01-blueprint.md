# WorkToGo — Agent Layer, Architecture Blueprint

> **Purpose:** The complete pre-build plan for the **Agent Layer** — an AI assistant ("dost") that sits **on top of** the existing 3-engine motherboard as the main front-door. Every user talks to it by text or voice; it *understands* language and *does things* across service + commerce, while all real data, decisions, search, pricing and payment stay inside our own system.
> **Status:** READ-ONLY planning. **No feature code written. No live files touched. Never-touch engines (shopping-engine, video-engine, orders.js, service-engine) read for planning only.** Workspace: `wtg-v2` worktree, branch `v2`.
> **Grounded in:** the real code read for this document — `heart/router.php` (intent pipeline), `heart/pipeline/BrainConnector.php` (the optional/circuit-broken external-service pattern), `heart/api/shop/index.php` (the clean-adapter pattern), `lib/payment/*` + `WTG-SYSTEM-BRAIN/V2/V2-PAYMENT-ABSTRACTION.md` (the provider-abstraction pattern to mirror), `brain/` (the existing personalization/decision engine), `core/helpers/Permission.php` + `heart/middleware/AuthMiddleware.php` (RBAC), `core/helpers/CityResolver.php` + `ContextBuilder.php` (identity/city/language).
> **Date:** 2026-08-27.

---

## 0. The one idea this whole document rests on

**The AI is a translator, not the doer.**

WorkToGo already has a working "doing" machine: the **Heart** takes a structured request `{intent, data, user, location, language}`, routes it, and the engines execute it — booking a service, ordering a product, taking a payment, tracking a job. That machine works today with buttons and forms.

The Agent Layer adds **one new ability in front of that machine: turning natural human language into those structured requests, and turning the results back into friendly words.** That single translation step — and *only* that step — is where an external AI (OpenAI / Claude / Gemini) is used. Everything else that matters — who the user is, what city they're in, what a painter costs, which vendor is free, whether a payment succeeded — is decided by **our own backend, exactly as it is today.**

This is why the two hardest founder requirements are actually the *easy* part here:

- **"The external AI must never get our data"** → easy, because the AI's job is language, not data. It never needs a phone number, an address, an order history, or a price to do its job. We built the wall by giving it a job that doesn't require the secrets.
- **"If the AI is removed, the system still works"** → easy, because the AI sits *in front of* a system that already works without it. Remove the AI and the app degrades to menus and buttons — it does not break. This is the same pattern the Heart already uses for its existing "Brain" (`BrainConnector.php`: *"Brain is OPTIONAL… Heart pipeline continues normally with safe defaults"*).

Read the rest of the document with that sentence in mind: **AI understands, the system decides and acts.**

---

## 1. PLAIN-LANGUAGE OVERVIEW (for the founder)

### What it is

Imagine every WorkToGo user has a friend inside the app. They can **type or talk** to this friend in **their own language** — English, Hindi, Hinglish, or a regional language — in a normal, casual way, not with robot commands. They can even **give the friend a name**. The friend understands what they want and **does it for them**: finds a painter, opens the right screen, fills in the details, gets them to the pay button, tells them where their booking is. The user never has to "learn the app" — they just ask.

The friend also **remembers** them. If you always book cleaning on weekends, or you asked for a plumber in Rajpur Road last month, it remembers — so next time is faster. A brand-new user with no history still gets a smart, helpful friend, because the AI understands general language out of the box.

### How it works (in three plain steps)

1. **You speak or type.** "Mujhe ek achha painter chahiye ghar ke liye" ("I need a good painter for my house").
2. **The friend understands and figures out the request.** Behind the scenes, an AI helps *only* with understanding the words — it turns your sentence into a clear instruction: *"customer wants → find a painter → in their city."* The AI is told nothing about who you are. It never sees your name, number, address, or money.
3. **WorkToGo does the real work and shows you the result.** Our own system — the same one that runs the app today — finds real painters in your area, opens the painter cards, and walks you to the booking. When money is involved, **you** tap the pay button yourself; the friend just gets you there.

### The most important promise: **the privacy wall**

The outside AI companies (OpenAI, Google, etc.) are useful for language, but **we do not hand them our business.** Our customers' names, phone numbers, addresses, order history, and every rupee of payment stay **100% inside WorkToGo's own system**. To the outside AI, there is no "customer named X in Haldwani" — there is only one faceless "WorkToGo assistant" asking language questions. **We stay the owners of all our data.** (Section 3 explains exactly how, in detail — it is the top priority of this whole plan.)

### The other promise: **we're never trapped**

The AI is a *plug-in part*, not the engine. We can use OpenAI today, Google tomorrow, or a cheaper one for simple tasks — swapping by a config change, no rebuild — exactly like the multi-gateway payment layer we already built. And because our own system does all the real work, if an AI provider changes its prices, breaks, or disappears, **WorkToGo keeps running.** Over time, as we learn from usage, we can even train **our own** language model and depend on outsiders less and less — dropping cost along the way.

### Two concrete examples

**Example A — "Find me a painter, and let me talk to him."**
> The user says (voice or text): *"Ghar paint karana hai, koi acha painter dikhao."*
> The friend understands → asks WorkToGo's system for painters in the user's auto-detected city → the painter cards slide open on screen, best-rated first. The user says *"pehle wala theek hai"* ("the first one's fine"). The friend opens that painter's profile (real photos of past work) and offers **"Talk now."** WorkToGo connects the two **live, with the vendor's real number hidden** — they speak through a WorkToGo ID (like Instagram handles), so neither the customer nor the vendor takes the deal off-platform. If the user wants the company to manage it, the friend opens the **Company Service** view and gets them to the expert-visit or package screen — the user taps pay themselves.

**Example B — "Order a gift for my sister in Delhi."**
> The user has earlier saved a few people in their private contacts ("Sister — Priya — Delhi — <address>"). They say: *"Order a nice gift for my sister in Delhi."*
> The friend understands the request. **The outside AI is only told:** *"customer wants to send a gift to a saved contact who is in Delhi"* — it is **never** told the sister's name, number, or address. WorkToGo's own system looks up the real saved contact, finds giftable products available in Delhi, shows the cards, and prepares the order to the sister's saved address. The user reviews and confirms; **the user taps pay.** The AI never touched a single piece of the sister's real information.

---

## 2. ARCHITECTURE — how the Agent Layer sits on the motherboard

### 2.1 Where it sits

Today the request flow is:

```
   App (buttons/forms)  ─►  Heart (intent pipeline)  ─►  Engines (service / shopping / delivery / video)
                                    │
                                    └─► Brain (personalization/decisions) + DB (all real data)
```

The Agent Layer adds **one new front-door lane** that produces the same `{intent, data}` the buttons already produce — using the AI only to read language:

```
   User (TEXT / VOICE, any language)
        │
        ▼
 ┌───────────────────────────────── AGENT LAYER (new) ─────────────────────────────────┐
 │                                                                                       │
 │  (1) Agent Gateway ── register-gated (JWT), reuses AuthMiddleware + CityResolver      │
 │        │                                                                              │
 │  (2) Conversation Orchestrator ("Dost runtime")                                       │
 │        │   • holds the turn, decides: can I answer WITHOUT the AI? (system-first)     │
 │        │   • assembles a minimal, ANONYMIZED "understanding request"                  │
 │        ▼                                                                              │
 │  (3) ══════════════ PII WALL / EGRESS FIREWALL ══════════════  ← the critical piece  │
 │        │   • scrubs & tokenizes any text (names→<PERSON_1>, numbers→<PHONE_1>…)       │
 │        │   • deny-by-default allow-list; schema-validates every field that leaves     │
 │        ▼                                                                              │
 │  (4) AI Provider Manager (mirror of PaymentGatewayManager)                            │
 │        │   • routeFor(task) → primary/secondary; cheapest-capable; failover;          │
 │        │     circuit-broken + degrade-don't-die (BrainConnector pattern)              │
 │        ▼                                                                              │
 │     OpenAiAdapter │ ClaudeAdapter │ GeminiAdapter  → return {intent, slots} ONLY      │
 │        │                                                                              │
 │  (5) Re-hydrate (placeholders → real refs INSIDE the wall) → build {intent, data}     │
 │        │                                                                              │
 │  (6) Tool / Action layer  ── maps agent tools → EXISTING intents & clean adapters     │
 │        │                                                                              │
 │  (7) Memory / Personalization  ── reuses Brain (brain_events, habits, suggestions)    │
 │        │      + small agent tables (sessions, structured memory, saved contacts)      │
 │  (8) UI-Control directives  ── "open this screen / render these cards / scroll to X"  │
 │                                                                                       │
 └───────────────────────────────────────┬───────────────────────────────────────────────┘
                                          ▼
                    EXISTING Heart intent pipeline  ─►  Engines + DB + Payment
                    (unchanged: heart/router.php AppRouter → EngineLoader → PriorityResolver)
```

### 2.2 The components (what each one is, and how much already exists)

| # | Component | What it does | Build status |
|---|---|---|---|
| **1. Agent Gateway** | One entry endpoint (`POST /api/agent/message`). Enforces **register-gate** (valid JWT), resolves identity + city + language. | **Mostly reuse** — `AuthMiddleware::require()`, `CityResolver`, `ContextBuilder` already do identity/city/language. Thin new wrapper. |
| **2. Conversation Orchestrator** ("Dost runtime") | The brain of the turn *(not the AI)*. Decides whether the turn even needs the AI (system-first), keeps short conversation state, assembles the anonymized understanding request, re-hydrates the answer, calls the tool layer, and composes the reply + UI directive. | **New**, but small — it's glue over existing parts. |
| **3. PII Wall / Egress Firewall** | The only path from us to any external AI. Scrubs + tokenizes text, enforces a deny-by-default field allow-list, schema-validates and logs everything that crosses. **See §3.** | **New — the highest-priority build.** |
| **4. AI Provider Manager + adapters** | Pluggable multi-provider layer: `AiProvider` contract, one adapter per provider, a manager that routes per task (cheapest-capable), fails over, and is circuit-broken. | **New**, but a **direct clone of the shipped payment abstraction** (`lib/payment/*`). Same shape, same file layout, same config style. |
| **5. Re-hydrator** | Turns the AI's placeholder answer (`<PERSON_1>`, `<ADDR_1>`) back into real references using the request-scoped map held **inside** the wall. Produces the final `{intent, data}`. | **New**, small. |
| **6. Tool / Action layer** | A registry of "agent tools" (find_service, list_bookings, track, add_to_cart, start_company_request, connect_vendor, …). Each tool = a thin call to an **existing intent or clean adapter** — never a new engine. | **~80% reuse.** The routes already exist in `heart/router.php` (`service:*`, `shopping:*`, `vendor:*`, `delivery:*`) and adapters like `/api/shop`. The tool layer is a mapping + guardrail wrapper. |
| **7. Memory / Personalization** | Remembers preferences & history; powers "less effort on repeat." | **Reuse Brain** (`brain_events`, `UserProfileModel`, `HabitModel`, `SuggestionEngine`) + a few new agent tables. |
| **8. UI-Control layer** | Server returns a structured directive; the PWA client opens the right existing screen, renders cards, auto-scrolls/pre-selects, and stops at the human-confirm control for anything irreversible. | **New client runtime** over the **existing** pages (`app/pages/home.js`, `bookings.js`, `orders.js`) and existing data endpoints. |
| **9. Voice I/O** | Speech→text on the way in, text→speech on the way out. Sits *outside* the AI reasoning; transcript is scrubbed by the wall before any LLM sees it. | **New, later phase.** |
| **10. Agent Control-Room** | Admin surface: capability matrix per role, limits, guardrails, approvals, monitoring, kill-switch. **See §5.** | **New**, but reuses `app_settings`, the data-driven RBAC (`Permission`), `alerts`, `HeartRateLimiter`. |

### 2.3 Why this shape (engineering judgment)

- **The action system is not rebuilt — it is *reached*.** The single biggest reason this is achievable is that the Heart already exposes a uniform `{intent, data}` action bus (`AppRouter::resolve` in `heart/router.php`) plus clean adapters (`heart/api/shop/index.php` shows the exact "instantiate the never-touch engine, call its public methods, add city + auth on top, never fork it" pattern). The agent's "it DOES things" requirement is largely a *mapping* job onto plumbing that already runs in production.
- **The AI layer is a proven pattern, not a new risk.** We already shipped a pluggable, failover, degrade-don't-die abstraction for *payments*. The AI provider layer is the same design with a different contract. The founder has already validated this pattern once.
- **"System-first" is enforced structurally, not by discipline.** Because the orchestrator can answer many turns from cache/rules with **zero** AI calls, and because the AI is confined to filling a schema, the system cannot "drift" into AI-dependence: the AI physically cannot search, price, or act — it has no tools and no data.

---

## 3. THE PRIVACY WALL (top priority — designed in detail)

**Goal:** the external AI helps with *language only* and, to it, WorkToGo is a **single anonymous agent**, never our individual customers. No customer PII, no payment data, no business logic ever leaves. We remain 100% data owner.

### 3.1 The core trick: give the AI a job that doesn't need the secrets

The AI is asked to do exactly two things, both language-only:

- **Understand:** "Here is a user utterance and a list of things a WorkToGo assistant can do. Which one do they want, and what are the slot values?" → returns `{intent, slots}`.
- **Phrase:** "Here is a structured result (already computed by our system). Say it back warmly in language L." → returns a sentence.

Neither task needs a name, number, address, order, price, margin, or vendor identity. So the wall's first principle is: **the AI is never in a position where it *needs* real data.**

### 3.2 Four layers of the wall (defense in depth)

**Layer 1 — System-first bypass (the cheapest privacy is no call at all).**
The orchestrator first tries to resolve the turn **without any external AI**:
- a deterministic intent classifier for common phrasings ("track my order", "book a plumber", "cancel"), and
- a **semantic cache** of already-seen utterance→intent mappings (the Heart already has this reflex-cache habit — `ReflexEngine`/`CACHE_TTL` in `heart/index.php`).
If either resolves confidently, **nothing leaves the building.** Only genuinely ambiguous language escalates past the wall.

**Layer 2 — Scrub & tokenize (nothing identifying in the text).**
Any text that *must* go to the AI passes through a scrubber that replaces identifiers with request-scoped placeholders held in a **local** map:

| Real (never leaves) | What the AI sees |
|---|---|
| "Priya", "Ramesh" (names) | `<PERSON_1>`, `<PERSON_2>` |
| phone numbers | `<PHONE_1>` |
| "12, MG Road, Rajpur" (address) | `<ADDR_1>` |
| emails, order ids `WTG_…`, booking ids | `<EMAIL_1>`, `<ORDER_1>`, `<BOOKING_1>` |
| the user's own identity | *(never included at all)* |

The AI reasons over placeholders. It can still understand *"send `<ITEM>` to `<PERSON_1>` in Delhi"* perfectly — because meaning survives tokenization, but identity does not. Category names, city names, and generic item words are public and may pass (they're already public in the app).

**Layer 3 — Structured, minimal, deny-by-default envelope.**
The AI never receives a free-form dump. It receives a **fixed schema**:
```
{
  "utterance": "<scrubbed text>",
  "capabilities": [ "find_service", "track_booking", "order_product", ... ],   // public verbs only
  "context": { "role": "customer", "lang": "hi", "city_tier": "tier2" }          // coarse, non-identifying
}
```
and must **return a fixed schema** (`{intent, slots}`). A central **egress policy** lists precisely which fields may ever cross; **anything not on the list is stripped**. A new field cannot leak by accident — it has to be explicitly allow-listed and schema-typed. Every crossing is **logged** (field names + sizes, not the user) so egress is auditable.

**Layer 4 — Re-hydration happens inside, resolution happens inside.**
The AI returns `{intent:"order_product", slots:{recipient:"<PERSON_1>", city:"Delhi", item:"gift"}}`. **Inside** the wall, `<PERSON_1>` → the real saved contact row, the address is fetched from our DB, the product search runs on our servers, the price is computed by us. The AI is never told the resolution. It proposed a *shape*; we filled it with reality.

### 3.3 The hard "never" list (explicit)

**Never leaves the system, under any circumstance, to any external AI:**
- Names, phone numbers, addresses, emails, government IDs.
- Order / booking / payment history; amounts paid; wallet balances; refunds.
- Any payment data (card, UPI, gateway tokens, `payment_session_id`).
- Vendor business data, real vendor identity/number, per-vendor performance.
- Pricing, margins, commission rules, assignment logic, eligibility rules — **all business logic stays server-side** (it already does: `ExpertVisitPricing`, `LedgerEngine`, `ServiceVendorEligibility`, `PriorityResolver`).
- The user's own identity or any stable per-user identifier. To the AI, every request is from one anonymous "WorkToGo agent."

**May leave (all already public or non-identifying):**
- The scrubbed utterance (placeholders only).
- The public capability catalog (the verbs the assistant can do).
- Coarse context: role (customer/vendor/guest), language code, city *tier* (not the exact city if we choose to keep even that coarse), time-of-day period.

### 3.4 Data ownership & provider terms

- **All real data stays in our MariaDB behind the Heart** — the AI provider stores nothing of ours because we send nothing of ours.
- **Zero-retention / no-training contract terms** with whichever provider(s) we enable (an enterprise/data-processing agreement) — belt-and-suspenders on top of the wall.
- **Memory/embeddings are ours:** any personalization vectors or preference summaries are computed and stored **by us**, in our DB (Brain), never in the provider.
- **Kill-switch proves ownership:** flip `agent_enabled=false` and the entire external dependency vanishes with zero data cleanup needed on their side — because they never held anything.

### 3.5 Verdict on the wall

This is **genuinely achievable and robust**, precisely because the architecture already forces all real work server-side. The wall is not "trust the AI to be careful" — it is "the AI is structurally unable to see or act." The main engineering care items are (a) the scrubber's recall on Hinglish/regional names and messy addresses (mitigated by tokenizing aggressively and by the fact that slots we care about are resolved from *our* contacts, not from the text), and (b) disciplined egress allow-listing (mitigated by deny-by-default + logging).

---

## 4. HOW IT CONNECTS to the existing system (clean adapters, no engine edits)

The agent reaches the platform **only** through seams that already exist. Nothing in a never-touch engine is edited.

| Existing capability | How the agent uses it | Seam (already exists) |
|---|---|---|
| **Identity / register-gate** | Agent requires a valid JWT; guests get an "understand & browse" agent that must register to act. | `AuthMiddleware::require()` / `requireRole()` / `requirePermission()`; `token_blacklist`. |
| **City auto-detect & scoping** | Every agent action is city-scoped invisibly, like the storefront. | `CityResolver::resolveFromUser()`; `ContextBuilder` location block. |
| **Language / multi-lingual** | Detected language flows through to the AI-phrasing step and the reply. | `ContextBuilder` already validates a BCP-47 language set incl. hi/ta/te/mr/bn/… |
| **Service actions** | book, list, track, company-service request, expert visit. | intents `service:book_service`, `service:create_booking`, `service:list_bookings`, `service:service_status` (in `AppRouter`). |
| **Commerce actions** | browse, cart, reorder, checkout (COD today). | the **clean adapter** `heart/api/shop/*` + `shopping:*` intents — engine reused as-is. |
| **Payment** | agent *prepares* the pay screen; the human confirms; failover/multi-gateway already handled. | `lib/PaymentService.php` + `lib/payment/PaymentGatewayManager.php`. The agent never moves money itself. |
| **RBAC / capabilities** | per-role agent abilities and per-capability gates. | data-driven `Permission::userHas()` + roles/permissions/role_permissions/user_roles (migration `2026_08_13_001_rbac_foundation.sql`). |
| **Memory / personalization** | preferences, habits, "most-used first," suggestions. | `brain/` — `BrainCore::observe()` → `brain_events`, `UserProfileModel`, `HabitModel`, `SuggestionEngine`, `FeedRankingEngine`. |
| **Reminders / proactive push** | festival/season nudges, "your booking is tomorrow." | FCM already wired (`heart/api/auth/device-fcm.php`, `fcm-config.php`, `app/pages/firebase-sw.js`) + `notifications` table + Brain `AlertEngine`. |
| **Bypass-safe live connect** | customer↔vendor talk with the number hidden. | reuse the Vision's "hide customer contact until job started" rule + `VendorAccountability`; add a masked-relay provider for voice (later). |
| **Admin config store** | control-room settings. | `app_settings` (setting_key/value/value_type incl. json) — the pattern every admin engine already uses. |

**The rule (same as the payment layer):** the agent's tool layer **instantiates and calls** existing services/intents; it does **not** fork or edit `shopping-engine`, `video-engine`, `service-engine`, or `orders.js`. Where the service engine's one inline path (the ₹299 expert-visit order created inline at `service-engine/api/services/index.php`) matters, the agent simply drives the *existing* flow and lets the human confirm payment — no engine edit required.

### 4.1 The agent is a sibling of the Brain, not a replacement

Important naming/architecture point: the existing `brain/` is a **rules + personalization + decision** engine that runs **without any external AI** (it's called through `BrainConnector` with a circuit breaker and safe defaults). The Agent Layer is a **new, separate** conversational front-door. They divide cleanly:

- **Brain** = *system-first intelligence* — ranking, habits, suggestions, "what to show first." No LLM. Already built.
- **Agent** = *language front-door* — understand the sentence, drive the action, speak back. Uses the LLM for language only, and **calls the Brain** for personalization/memory/suggestions.

Keeping them separate means the agent inherits a personalization engine for free, and the Brain keeps working whether or not the AI is on.

---

## 5. CAPABILITIES & CONTROL (per-role abilities + the control-room)

### 5.1 Per-role agent capabilities

Capabilities are modeled as **RBAC permission keys** (`agent.cap.*`) on the existing data-driven system, so they're grantable/revocable per role and per scope (city/category) with no code change:

**Customer-agent** (`role: customer`): find/compare services & products, explain options, start a company-service request, pick packages, add to cart, reorder, track bookings/orders, manage saved contacts, request a live connect to a vendor, ask for help/refund *request* (not approval). **Prepares** payment; **never** completes it.

**Vendor-agent** (`role: vendor_service` / `vendor_shopping`): onboard-by-conversation (guided profile/catalog setup), manage jobs (accept/start/complete via existing `vendor:update_job_status`), see own revenue history, log self-jobs/CRM reminders (Vision §4.2), respond to customers. **Cannot** see other vendors' data, change pricing rules, or self-approve.

**Guest** (unregistered): understand + browse + explain **only**. Any action → "please register (number + OTP) to continue." (Register-gate.)

**Admin** keeps full platform authority (existing admin bypass) but the *agent* never performs admin-only irreversible actions autonomously.

### 5.2 The Agent Control-Room (admin surface)

A new admin room (built like the existing admin engines; config in `app_settings` + a few agent tables):

- **Capability matrix** — toggle each `agent.cap.*` per role, per city/category scope.
- **Guardrails** — allowed-intent **allow-list** (the agent can only ever emit intents on this list), content filter, refusal templates for out-of-scope/unsafe asks. The AI physically cannot invent an action outside the tool registry.
- **Human-approval gates** — high-stakes actions (refund approval, vendor payout changes, unusually large orders, bulk actions) are queued for a human, never auto-done. (Aligns with the platform's money-safety posture.)
- **Limits & budgets** — per-user daily message cap; per-user and global **token/cost budget** with hard cutoffs; rate limits (reuse `HeartRateLimiter`, already IP+user dual-layer).
- **Monitoring** — every AI call logged (provider, task, tokens, ₹ cost, latency, cache-hit, failover), every action logged; anomaly alerts via the existing `alerts` table + Brain `AlertEngine`.
- **Kill-switch** — `app_settings.agent_enabled=false` disables the layer instantly; per-provider `enabled` flags disable one provider. **Because the system is system-first, the app keeps working** (menus/buttons) with the agent off. A softer "degrade to deterministic-only (no external AI)" switch is also worth having (keeps the agent, drops the LLM).

### 5.3 Safety defaults (non-negotiable)

- The agent **prepares** irreversible/outward actions (pay, submit, publish, send) and **stops at the human-confirm control** — the user taps the real button. The agent auto-scrolls and pre-fills; it does not auto-commit money or messages.
- The AI **never** decides price, eligibility, assignment, or payment outcome — those are server-authoritative and already are.
- The AI **never** sees or emits real PII (§3).

---

## 6. PHASED BUILD PLAN

> Golden rule, mirroring the V2 build discipline: **ship the cheapest thing that proves the pattern, on top of what already works, then widen.** Each phase is independently useful and independently shippable.

### Phase A — **Text Customer Service-Agent MVP** (build this first)
**What:** register-gated, text-only, customer-facing. One AI provider behind the new abstraction. The PII wall. A small tool set: `find_service`, `explain`, `list_bookings`, `track_booking`, `start_company_request`, `open_screen`. System-first router + semantic cache in front of the AI. Human confirms any payment.
**Why first:** it exercises the entire spine — gateway, orchestrator, **wall**, provider abstraction, tool layer, UI directives — at the smallest scope, on the strongest existing engine (service). It proves the privacy wall and the cost model before any expensive surface.
**Needs:** 1 provider account + **zero-retention terms**; the wall + scrubber; the provider abstraction (clone of payment layer); ~6 tools mapped to existing intents; a chat UI + card renderer in the PWA.
**Effort:** ~4–7 sessions. **Confidence:** Medium-High (most plumbing exists).

### Phase B — **Voice I/O**
**What:** speech→text in, text→speech out, layered on the *same* Phase-A pipeline. Transcript is scrubbed by the wall before any LLM call.
**Why after A:** voice is an I/O shell around a text brain; build the brain first.
**Needs:** STT + TTS (prefer providers with Indian-language + Hinglish support; consider on-device STT for privacy). Same wall.
**Effort:** ~4–6 sessions. **Confidence:** Medium (multilingual noisy-input accuracy is the wildcard).

### Phase C — **Commerce Service-Agent** (+ gift-to-contact)
**What:** order/browse/cart/reorder via the existing `/api/shop` clean adapter; "order a gift for my sister" using saved contacts (Example B). COD today; online pay when the storefront enables it — human confirms either way.
**Needs:** ~4 more tools; `user_contacts` table (encrypted at rest); the wall's placeholder→contact resolution.
**Effort:** ~4–6 sessions. **Confidence:** Medium-High (storefront adapter already exists).

### Phase D — **Connections ("social-lite")**
**What:** save people (name/number/address/relation); WorkToGo-ID public handle (Instagram-style); live customer↔vendor connect with the real number **hidden** via a masked relay; number-hidden calling.
**Needs:** `user_contacts` (from C), a `handle` on identity, a **masked-telephony/relay provider** for voice connect, reuse of the bypass-hide rule.
**Effort:** ~5–8 sessions (external telephony provider is the variable). **Confidence:** Medium.

### Phase E — **Proactive suggestions / reminders / mood**
**What:** festival & season nudges, "your booking is tomorrow," reorder reminders, gentle help — driven by the **Brain SuggestionEngine + habits**, delivered via **existing FCM**. Language-phrased by the AI.
**Needs:** mostly reuse (Brain + FCM + notifications). Tuning, not new plumbing.
**Effort:** ~3–5 sessions. **Confidence:** Medium-High.

### Vendor-agent
Slots in around C–D: onboard-by-conversation + job management via `vendor:*` intents. ~4–6 sessions.

### Do **NOT** build yet (and why)
| Not now | Why |
|---|---|
| **Live video call / face-to-face** | Heavy infra + cost; no proof it's needed pre-launch. Voice connect (D) covers the real need first. |
| **Virtual try-on** | Commerce isn't even online-pay yet; this is a far-future commerce nicety, not a front-door need. |
| **Always-listening wake-word** | Battery/privacy/cost burden and a much bigger privacy surface; press-to-talk (B) delivers voice without it. |
| **Own / self-hosted model** | Premature — needs traffic + labeled data first (§7). Building it now means cost with no volume to amortize it. |
| **Fully autonomous irreversible actions** (auto-pay, auto-send) | Violates the safety posture; always keep the human on the confirm button. |

---

## 7. COST & DEPENDENCY — keeping bills low and avoiding lock-in

**1. System-first = most turns cost ₹0 of AI.** The deterministic router + semantic cache resolve the common, repeated asks (track, book X, cancel, reorder) **without any AI call** — the same reflex-cache instinct the Heart already uses. The AI is spent only on genuinely novel/ambiguous language. This is the single biggest cost lever.

**2. Tiny prompts by design.** Because the wall reduces every request to a scrubbed sentence + a short capability list + a fixed return schema, prompts are small and outputs are small (structured `{intent, slots}`), not long free-form chat. Small tokens = small bills. No replaying long transcripts — memory is **structured** (Brain), not raw chat history.

**3. Cheapest-capable model per task (the routing lever).** The provider manager routes by task: a **small/cheap** model for classification and slot-filling (the bulk), a bigger model only for rare hard cases or nuanced phrasing. Config-driven, like payment routing.

**4. Caching common phrasings + responses.** Utterance→intent and result→phrasing both cache. Popular flows converge to near-zero marginal AI cost.

**5. Pluggable providers = price leverage + no lock-in.** `config/ai.providers.php` + adapters mean we can move volume to whoever is cheapest/best per task, add a provider by config, or drop one, with no rewrite — exactly the payment-layer benefit, now for AI.

**6. Degrade-don't-die = the dependency is never fatal.** Circuit breaker + safe defaults (BrainConnector pattern): if a provider is down/slow/pricey-spiking, the agent falls back to deterministic understanding (menus, best-guess intent, "show me options") — the app never breaks.

**7. The path to our own model.** From **day one** we log the *anonymized, scrubbed* utterance→intent pairs (placeholders only — still no PII). That labeled corpus is exactly the training set for a **small distilled/fine-tuned open model** that handles the high-volume, low-complexity NL→intent step. As volume grows: (a) move the cheap-classification traffic to the owned model → external cost drops sharply for the bulk of requests; (b) keep an external provider only as the rare "hard case" fallback; (c) optionally retire it entirely. **AI-dependency and cost fall over time by design, not by luck.**

---

## 8. HONEST VERDICT

### What's genuinely achievable **now**
- **A text customer service-agent front-door is well within reach and cheap**, because the hard half — a uniform action bus (`{intent,data}` → engines) and clean adapters — **already runs in production.** The agent is mostly a translator + a mapping + a wall, not a new platform.
- **The privacy wall is real and strong**, not aspirational — the architecture already forces all real work server-side, so "the AI can't see or act" is a structural fact, not a promise. This is the standout strength of doing it *on this system*.
- **The pluggable AI layer is low-risk** because it's a second instance of a pattern the founder already shipped (the multi-gateway payment abstraction).
- **Personalization/memory is largely free** — the Brain already has events, habits, and a suggestion engine.

### What's harder / genuinely **later**
- **Reliable voice** in noisy, code-switched (Hinglish + regional) real-world Indian audio is the biggest quality unknown. Ship text first; treat voice accuracy as an experiment, not a given.
- **Hands-free "it does everything" autonomy** is deliberately *not* the goal — the human stays on the confirm button for money and messages. That's a feature (trust), not a limitation.
- **The own-model** is a "later, once we have volume" play, not a launch item.

### Biggest risks (and the mitigation already in the design)
1. **Cost creep** if the AI is over-used → *system-first bypass + cache + cheap-model routing + budget kill-switch.*
2. **Quality / hallucination** → *the AI only fills a fixed schema; it can't invent actions, prices, or data; deny-by-default intent allow-list.*
3. **Provider dependency / lock-in** → *abstraction + failover + degrade-don't-die + own-model path.*
4. **Privacy leakage** → *the four-layer wall, deny-by-default egress, zero-retention terms, egress logging; the AI is structurally blind to PII.*
5. **User trust** → *irreversible actions always human-confirmed; the agent prepares, the user commits.*

### What the founder should decide (the real gates)
1. **Which provider(s) to start with, and the monthly AI budget cap** (this sets the routing + kill-switch thresholds).
2. **Approve zero-retention / data-processing terms** with that provider (the contractual half of the wall).
3. **Voice: build-vs-buy** which STT/TTS, and whether on-device STT is worth it for privacy.
4. **Guest agent scope** — read-only "understand & browse" for the unregistered, or fully register-gated from the first word?
5. **Masked-telephony provider** for the live customer↔vendor voice connect (Phase D).
6. **How much auto-action** the founder is comfortable with (recommended: prepare-only for anything irreversible; the human taps pay/send).

### One-line verdict
**Build the agent as a thin language *front-door* on top of the system that already does the work: the AI understands, the Heart decides and acts, and a hard privacy wall keeps every piece of real data ours. Start with a small, cheap, register-gated text service-agent MVP to prove the wall and the cost model, then widen to voice, commerce, connections, and proactive help — reusing the engines, never editing them, and never letting the AI off its leash.**

---

*Planning document only. No feature code written; no live files modified; never-touch engines (shopping-engine, video-engine, service-engine, orders.js) read for planning and left untouched. Every reuse claim is traceable to a file cited above.*
