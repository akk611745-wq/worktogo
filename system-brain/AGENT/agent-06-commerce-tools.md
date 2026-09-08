# WorkToGo — Agent Layer, Phase 5: Commerce Tools (the agent shops too)

> **Purpose:** Document the commerce capability added to the agent — so a user can shop entirely by talking ("Diwali aa rahi, kapde chahiye" → products → compare → add → order), the same friend-like, register-gated, RBAC-aware, privacy-walled pattern as the service tools.
> **Status:** Built locally in `wtg-v2` (branch `v2`). **No live/deploy. No migration** (reuses existing commerce tables). **No never-touch engine edited** — reaches commerce only through the clean `/api/shop/*` adapter. Verified end-to-end in a real browser against the mock.
> **Builds on:** Phases 1–4 (`agent-01`…`agent-05`).
> **Date:** 2026-08-30.

---

## 1. What was built (plain summary)

The agent already did **service** (find vendors, book, expert visit). Now it does **commerce**: the user says *"kapde chahiye"* and the agent shows **real product cards** (their city, company + local stores), lets them **add to cart**, and **place the order** (Cash on Delivery) after a clear confirmation — all inside the chat. Reads are free; the money step (checkout) is confirmed; the agent only places the order — it never moves money.

---

## 2. The commerce tools (each → one existing storefront endpoint)

Mirrors the service tools: each maps to the clean, city-scoped, dual-store `/api/shop/*` adapter (which already reuses the never-touch shopping engine) via the same `HttpEngineClient` that forwards the user's JWT.

| Tool | Mode | Endpoint |
|---|---|---|
| `browse_products` | read | `GET /api/shop/products` (city-scoped, company-first) |
| `get_product` | read | `GET /api/shop/products/{id}` |
| `view_cart` | read | `GET /api/shop/cart` |
| `add_to_cart` | read\* | `POST /api/shop/cart` |
| `list_orders` | read | `GET /api/shop/orders` |
| `track_order` | read | `GET /api/shop/orders/{id}` |
| `checkout` | **write** | `POST /api/shop/checkout` |

\* **add_to_cart is an intentional *light* write** — it's trivially reversible and moves no money, so it runs freely (confirming every cart-add would be annoying). The engine still applies its own city guard, stock check and rate limit. **checkout is the gated write** — it requires the user's explicit confirmation before placing the order.

**City + dual-store + personalization:** browsing is city-scoped and company-first (the storefront's own ordering), so the user sees their area's company + local-vendor products. Memory (Phase 4) already personalizes the greeting and passes only anonymized hints to the AI; product-level ranking-by-memory is a natural next step (noted below).

---

## 3. Money & the wall

- **Checkout is Cash-on-Delivery** through the storefront's real endpoint. Online pay for commerce is **not enabled in the storefront today** (the engine COD-locks it — a surfaced engine decision, not ours to flip here). The agent **places the order**; when online pay is turned on for commerce it flows through the existing **payment-abstraction layer** and the user completes payment. **The agent never moves money.**
- **Privacy wall unchanged:** product/cart/order data is OUR real data — it goes engine → agent → the user, and **never to the AI** (which only ever saw the scrubbed language). No PII or payment data reaches any provider.
- **Guardrails unchanged:** allow-list (the AI can only call exposed tools), **RBAC** (commerce tools are customer-only), the write-confirmation gate on checkout, per-user rate limits, and the counts-only audit all apply exactly as in Phases 2–4.

---

## 4. Wiring

- **Backend (agent scope):** `core/agent/tools/CommerceTools.php` (7 tools) registered in `ToolRegistry` and loaded in `bootstrap.php`. The orchestrator is generic — reads execute, the write (checkout) is confirmation-gated — so **no orchestrator change was needed**. `ToolPlanner` gained deterministic customer rules: a shopping keyword ("kapde/clothes/kurta/gift/diwali/…") → `browse_products` (with a light stop-word-stripped query); "cart" → `view_cart`; "my orders" → `list_orders`. Anything else still falls to the AI through the wall.
- **UI (own scope, `app/agent/`):** product cards (image/placeholder, name, sale price + strikethrough, store + Company badge, rating, **Add to cart**), a cart-added card (**Checkout (COD)** / **View cart**), a cart view, an **order-placed** success card (**Track order**), and order list/detail. `checkout` uses the same propose→confirm pattern as booking. The mock transport mirrors all commerce response shapes (products, cart, checkout, orders) so the flow runs with no backend/key.

**Untouched:** `shopping-engine`, `video-engine`, `service-engine`, `orders.js` — read-only. Commerce is reached only through the existing `/api/shop/*` adapter.

---

## 5. How to verify

**Standalone demo (no backend/key)** — serve the repo over HTTP and open `app/agent/demo.html`:
- Tap **"kapde chahiye"** → **4 real-shaped product cards** (Kurta / Diwali Saree / Kids set / Kurti) with company badges, sale prices, ratings.
- Tap **Add to cart** → *"Cart mein add ho gaya ✓ · ₹699"* with **Checkout (COD)** / **View cart** (no confirm — light write).
- Tap **Checkout (COD)** → *"Order place karein? (Cash on Delivery) · agent paisa nahi leta"* → **Yes, confirm** → **"Order placed · Order #8801 · Cash on Delivery"** with **Track order**.
- Also: type "cart" → cart view; "my orders" → order list.

*(All of the above were verified in-browser during this build; console clean.)*

**Live app:** with `agent_enabled=1`, the same UI drives the real storefront — `browse_products` hits the real city-scoped catalog and `checkout` places a real COD order via the shopping engine.

---

## 6. What is NOT in this phase (later)
Online payment for commerce (needs the storefront's engine COD-lock lifted, then it flows through the payment layer with the user completing payment), product-level personalization/ranking from memory (pick the right product out of thousands), a richer product detail / variant / quantity UI, address selection at checkout, connections ("gift for my sister"), voice, and the admin control-room.

---

*Phase-5 increment only. Built in `wtg-v2` (branch `v2`); no live deploy; no migration; no never-touch engine edited. Commerce is reached only through the existing `/api/shop/*` adapter; reads run free, checkout is confirmed, the agent never moves money, and no product/cart/order/PII data reaches the AI.*
