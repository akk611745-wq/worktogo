# WorkToGo — Agent Layer, Phase 8: Connections (order/gift for saved people)

> **Purpose:** "Social-lite" (NOT a full WhatsApp): a user saves their PEOPLE (sister, friend — name/phone/address/relation) so they can order/gift FOR them by talking — "behen ke liye kapde bhej do". New emotional/gift orders that wouldn't happen otherwise. Register-gated; the recipient's data stays INSIDE our system, behind the privacy wall — never sent to the external AI.
> **Status:** Built locally in `wtg-v2` (branch `v2`). **No live/deploy. Migration staged, NOT run.** **No never-touch engine edited** — reuses the existing commerce checkout. Verified end-to-end in a browser against the mock.
> **Builds on:** Phases 1–7 (`agent-01`…`agent-08`).
> **Date:** 2026-08-30.

---

## 1. What was built (plain summary)

The user says *"behen ke liye kapde order karo"* and the agent:
1. opens a **people picker** (their saved family/friends) — or an **inline add-person form** if they're new;
2. once a person is chosen, browses **products** and lets the user add to cart;
3. the checkout button becomes **"Checkout for Priya"** → on confirm, places the order **delivered to that person** (COD, to their saved address).

The recipient's name/phone/address never touch the AI. New gift orders that wouldn't have happened otherwise.

---

## 2. The people store + tools

**Store (agent-owned):** `core/agent/AgentPeople.php` → `agent_people` (name, phone, relation, address, city, optional lat/lng), keyed to the internal user id — the same pattern as memory/reminders.

**Tools (RBAC = customer):**

| Tool | Mode | What |
|---|---|---|
| `list_people` | read | the user's saved recipients → people picker |
| `get_person` | read | one recipient |
| `add_person` | read | opens the inline add-person form |
| `save_person` | read\* | saves a recipient (*light, reversible, no money → no confirm) |
| `order_for` | **write** | checkout the current cart, **delivered to the person** |

`order_for` reuses the real **`POST /api/shop/checkout`** (COD): the person's coords direct the rider and the recipient is recorded in the order note. The engine does the city guard, inventory locking, ledger and delivery hand-off — untouched. **The agent never moves money.**

---

## 3. Privacy — recipient PII stays behind the wall

Three layers keep the recipient's identity out of the AI:
1. **Resolved by id, not by data.** Tools take a `person_id`; the agent resolves the real person INSIDE our system. The AI is never handed a name/number/address.
2. **The wall scrubs saved contact names.** Before any message reaches the AI, the orchestrator feeds the wall the user's saved contact **names** (`AgentPeople::names` → `PiiWall::setContactTerms`); the wall tokenizes them → `<PERSON_n>` (the blueprint's saved-contact tokenization), re-hydrated only for the owner. Phones/addresses are caught by the existing detectors. The fail-closed tripwire still covers everything.
3. **Only an anonymized hint crosses.** When a turn references a relationship, the wall envelope carries the flag `ordering_for_contact` — never the person's data.

So "Priya ke liye kapde bhej do" reaches the AI as "`<PERSON_1>` ke liye kapde bhej do" (+ the `ordering_for_contact` hint). The self-test asserts the name is scrubbed.

---

## 4. Governed like everything else

Connections respect the Phase-7 control-room. `order_for` is mapped to the **commerce** capability and is a **write**, so pausing commerce or pausing all writes blocks gifting; managing your own people (`list/add/get/save`) is **core**. RBAC (customer-only), the allow-list, per-user limits, the AI-budget policy and the counts-only audit all apply unchanged. The self-test proves order_for is blocked when writes or commerce are paused.

---

## 5. City scope (honest)

The storefront resolves the city from the ordering user and takes a delivery drop point — so gifting works to a saved person's **address/coords within the pilot city** (Haldwani today). Cross-city gifting (a sister in Delhi) needs the storefront to operate in the recipient's city; that's a documented **future extension** (the engine's city guard would otherwise reject cross-city cart items). The people store already carries `city` for when that lands.

---

## 6. File map

**New:** `core/agent/AgentPeople.php` (store), `core/agent/tools/ConnectionTools.php` (5 tools), `migrations/2026_08_27_006_agent_people.sql` (staged).
**Changed:** `PiiWall` (`setContactTerms` + name scrub pre-pass), `AgentActionOrchestrator` (feed the wall contact names + the `ordering_for_contact` hint), `ToolPlanner` (deterministic: relation + order → `list_people`), `AgentPolicy` (tool→capability mapping for the new tools), `ToolRegistry` + `bootstrap` (register/load), `core/agent/tests/actions_selftest.php` (I1–I3).
**UI (own scope):** `app/agent/agent.js` (people picker, add-person widget, order-for flow, `Checkout for <name>`, mock people/branches), `app/agent/agent.css` (people/add-person styles).
**Untouched:** `shopping-engine` / `video-engine` / `service-engine` / `orders.js` — read-only; gifting reuses `/api/shop/checkout`.

---

## 7. How to verify

**Standalone demo (no backend/key)** — serve the repo and open `app/agent/demo.html`:
- Type **"behen ke liye kapde order karo"** → the **people picker** (Priya · behen, Rahul · dost, + *Nayi person add karein*).
- Tap **Order for** on Priya → *"Theek hai — Priya ke liye 🎁 …"* → type **"kapde chahiye"** → product cards → **Add to cart** → **"Checkout for Priya"** → confirm *"Priya ke liye order place karein? (COD) · unke saved address pe deliver hoga"* → **🎁 "Order placed for Priya #8802"**.
- **Add a new person:** *Nayi person add karein* → inline form (name/relation/phone/address) → Save → becomes the recipient.

*(The headline flow was verified in-browser during this build; console clean.)*

**Backend (self-test, when PHP is available):** `php core/agent/tests/actions_selftest.php` — I1: a saved contact name is scrubbed to `<PERSON_1>` (the AI never sees "Priya"). I2/I3: `order_for` is blocked when writes or commerce are paused.

---

## 8. What is NOT in this phase (later)
Cross-city gifting (storefront in the recipient's city), service-for-a-person (book a vendor at the recipient's location — the person's address can seed the booking note today), number-hidden live connect between customer and vendor, voice, and an own-model path.

---

*Phase-8 increment only. Built in `wtg-v2` (branch `v2`); no live deploy; migration staged not run; no never-touch engine edited. The recipient's name/phone/address live inside our system and are never sent to the AI — the wall scrubs saved contact names and only an anonymized hint crosses; gifting reuses the real checkout, is confirmed, and respects the control-room.*
