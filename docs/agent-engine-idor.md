# Agent → Engine IDOR / ownership proof (audit M4)

**Verdict: VERIFIED SAFE — no code change needed.** Every agent tool that touches
user- or vendor-owned data reaches the backend through the `EngineClient`, which
forwards the **caller's own JWT**; the engine then resolves identity from that
token and scopes the query by it. **No client-supplied owner id is ever trusted
for ownership.** Public reads (marketing catalog / config) are public by design.

The agent adds its own scoping for its OWN tables (people/memory/reminders — see
`AgentPeople`, `AgentMemory`, `list_reminders`, all `WHERE user_id = ?`); for the
never-touch engines it correctly relies on their JWT authorization, verified below.

## Ownership-sensitive calls (scoped engine-side)

| Agent tool | Endpoint | Engine ownership check |
|---|---|---|
| `list_orders` | `GET /api/shop/orders` | `OrderService::list($uid, …)` → `WHERE o.user_id = :uid` — `body/shopping-engine/api/orders/OrderService.php:562`; `$uid = $shopRequireUser()` (JWT) — `heart/api/shop/index.php:259` |
| `track_order` | `GET /api/shop/orders/{id}` | `OrderService::detail($uid, $id)` → `WHERE o.id = :id AND o.user_id = :uid` — `OrderService.php:623`; `$uid` from JWT — `shop/index.php:267` |
| `view_cart` / `add_to_cart` | `GET`/`POST /api/shop/cart` | `$shopRequireUser()` = `AuthMiddleware::requireRole(ROLE_CUSTOMER, ROLE_ADMIN)` (JWT) — `shop/index.php:56-59`; `CartService` scoped by that uid |
| `checkout` / `order_for` | `POST /api/shop/checkout` | `$shopRequireUser()` (JWT) → `OrderService::create` from the caller's own server-side cart; amount server-authoritative. `order_for` first resolves the recipient with `AgentPeople::get($ctx->userId, id)` (owner-scoped) |
| `create_booking` / `request_expert_visit` | `POST /api/service/request` | Creates for the authenticated caller; the service engine authorizes the real user (JWT). Write — also confirmation-gated agent-side |
| `list_my_jobs` | `GET /api/vendor/jobs` | `resolveVendorId($db, $auth['user_id'])` (vendor id from the **JWT**, not the client) → `WHERE j.vendor_id = :vid` — `body/service-engine/api/vendors/index.php:255,258` |
| `get_my_profile` / `my_status` | `GET /api/vendor/profile` (+ analytics/work-photos) | `requireRole(vendor)` + `WHERE user_id = ?` — `service-engine/api/vendors/index.php:332,371`; analytics/photos scoped via `resolveVendorId` |
| `save_listing` | `PATCH /api/vendor/profile` | `requireRole(vendor)` + `UPDATE vendors … WHERE user_id = ?` — `service-engine/api/vendors/index.php:483`; row kept a draft (admin approves) |
| `upload_work_photo` | `POST /api/vendor/work-photo` | `requireRole(vendor)` + `resolveVendorId($db, $auth['user_id'])` — `service-engine/api/vendors/index.php` |

## Public-by-design reads (no ownership; not IDOR)

| Agent tool | Endpoint | Why public |
|---|---|---|
| `find_vendors` | `GET /api/vendors` | Active vendors' public marketing listing (city-scoped) — meant for any customer to browse |
| `get_vendor_profile` | `GET /api/vendors/{id}` | A vendor's public marketing profile |
| `get_packages` | `GET /api/service/packages` | Public / config (city-scoped price cards) |
| `get_expert_visit_price` | `GET /api/service/expert-visit-fee` | Public / config |
| `browse_products` / `get_product` | `GET /api/shop/products[/{id}]` | Public city-scoped catalog |

## Agent-owned tables (scoped in agent code, not an engine)
`list_people`/`get_person`/`add_person`/`save_person` → `agent_people` `WHERE user_id = ?`
(`AgentPeople::get/list/findByTerm/add`); `list_reminders` → `agent_reminders WHERE user_id`;
memory → `agent_memory WHERE user_id`. All keyed to `AgentContext::userId` (verified JWT).

## Residual (staging re-confirm — not a code gap)
On staging, run the negative test per surface: user A requests user B's order id /
booking id via the agent and confirm a **404** (not another user's row). The reads
above show the scoping is present in code; a live DB run is the final belt-and-braces.

---
*Companion to `WTG-SYSTEM-BRAIN/AGENT/agent-11-readiness-audit.md` (finding M4). Grounded in `v2` engine code; the never-touch engines were read only, never edited.*
