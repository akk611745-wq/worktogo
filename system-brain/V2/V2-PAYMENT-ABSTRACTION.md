# V2 — Payment Abstraction Layer (multi-gateway · failover · engine-independent)

> **Purpose:** One clean payment abstraction so ANY gateway (Cashfree, Razorpay, …) plugs in by config; automatic primary→secondary failover when a gateway is down; and each engine's payment flow stands on its own. Built without touching any never-touch engine, without weakening existing money-safety.
> **Scope:** `wtg-v2` worktree (branch `v2`). Staging migration written, NOT run.
> **Date:** 2026-08-26.

---

## 1. Why (founder intent)

1. **Pluggable** — swap/add a gateway by config + one adapter, never a system rewrite.
2. **Failover** — if the primary gateway (Cashfree) is down/erroring (server issue), route the payment to a secondary (Razorpay) automatically; customer isn't stuck. Admin-configurable per engine.
3. **Engine-independent** — service, commerce, video each have their own payment flow. Commerce breaking must not break service, and vice-versa. No cross-engine single point.
4. **Money-safe** — server-authoritative amounts, signature-verified webhooks, `reference_type` collision-safety (booking vs order), idempotent, `FOR UPDATE` locks. No jugaad.

---

## 2. The shape of the layer

```
                         ┌─────────────────────────────────────────────┐
  commerce (orders) ──►  │              PaymentService                  │
  service  (bookings)*   │  (orchestrator: amount check, idempotency,   │
                         │   FOR UPDATE, reference_type, webhook flow)  │
                         └───────────────┬─────────────────────────────┘
                                         │ engine key ('commerce'|'service'|'video')
                                         ▼
                         ┌─────────────────────────────────────────────┐
                         │           PaymentGatewayManager             │
                         │  routeFor(engine) → primary/secondary       │
                         │  createOrderWithFailover(engine, request)   │
                         │  adapter(name) → verify webhooks            │
                         └───────┬───────────────────────┬─────────────┘
                                 ▼                       ▼
                     ┌──────────────────┐     ┌──────────────────────┐
                     │ CashfreeAdapter  │     │ RazorpayAdapter (STUB │
                     │ (wraps existing  │     │  /slot, guarded off   │
                     │  CashfreeClient +│     │  until creds arrive)  │
                     │  WebhookVerifier)│     └──────────────────────┘
                     └──────────────────┘
```
\* the service ₹299 inspection still creates its Cashfree order **inline** in the service engine today — see §7 (never-touch flag). Everything else is engine-independent by construction.

### Files (all NEW unless noted)
| File | Role |
|---|---|
| `lib/payment/PaymentGateway.php` | The contract every gateway implements: `name`, `isConfigured`, `createOrder`, `verifyWebhook`, `parseWebhook`. Normalised array shapes documented in the header. |
| `lib/payment/CashfreeAdapter.php` | Cashfree implementation — a thin, faithful wrapper around the **existing** `CashfreeClient` + `WebhookVerifier` + the exact payload/status logic. Adds only the retryable-vs-terminal failure classification. |
| `lib/payment/RazorpayAdapter.php` | **STUB / slot.** Full contract implemented, every money path gated behind `isConfigured()`. Never routes/settles until creds are set + it's enabled in config. Clean drop-in. |
| `lib/payment/PaymentGatewayManager.php` | Factory + per-engine router + failover engine + admin-override loader. |
| `config/payment.gateways.php` | Declarative registry (`enabled`) + per-engine `routing` (primary/secondary). DB-free, side-effect-free. **The only file you edit to add/route a gateway.** |
| `lib/PaymentService.php` *(edited)* | Now routes create-order through the manager (with an engine arg) and processes webhooks via a gateway-agnostic `processWebhookEvent`. Money-safety unchanged. |
| `api/payment/webhook.php` *(edited)* | Gateway-aware: picks the adapter from `?gateway=` (default `cashfree`), verifies the raw body with THAT adapter, normalises, then processes. |
| `api/payment/create-order.php` *(edited)* | Response is now a **superset** — old Cashfree keys plus generic `gateway`/`mode`/`failover`. |
| `migrations/2026_08_26_001_payment_gateway_abstraction.sql` | Adds `orders.payment_gateway` + `bookings.payment_gateway` (nullable) and seeds the admin routing setting. Staging only. |

---

## 3. The contract (normalised shapes)

`createOrder($request)` input: `order_ref` (our gateway-neutral ref), `amount` (server-verified), `currency`, `customer{id,name,email,phone}`, `return_url`, `notify_url`, `tags`.

`createOrder()` output: `success`, `gateway`, `gateway_order_id`, `payment_session_id`, `order_token`, `mode` (`production|sandbox`), `http_code`, **`retryable`** (⇒ failover allowed), `error`, `raw`.

`parseWebhook()` output (one engine-agnostic event): `gateway`, `order_ref`, `gateway_txn_id`, `amount`, **`internal_status`** (already mapped to `paid|failed|unpaid`), `event_type`, `is_payment_event`.

Status mapping lives in **each adapter** (`CashfreeAdapter::mapStatus`) — the single source of truth. The duplicated `PaymentService::mapCashfreeStatus` was removed, closing the drift risk flagged in `audit-05-payments.md §1`.

---

## 4. Failover (money-safe)

`PaymentGatewayManager::createOrderWithFailover($engine, $request)`:

1. Resolve `primary`/`secondary` for the engine.
2. Try **primary**. On success → return (`failover=false`).
3. On failure, branch on `retryable`:
   - **Retryable** = network error (`http_code 0`), gateway 5xx, or 401/403 (our creds broken) → the gateway is **down/erroring**. Try the **secondary** if it's enabled + configured.
   - **Not retryable** = 400/422/404/409 (payload/amount/validation) — a legitimate rejection that would fail identically anywhere → **do not** fail over; surface the error.
4. The chosen adapter's result carries `gateway` (who handled it) + `failover` + a per-attempt `attempts[]` trail (logged).

**No double-charge:** money only moves when the customer completes the hosted checkout, and we hand back **exactly one** payable session — the successful gateway's. A failed primary returns no session the customer can pay; even a primary timeout that secretly created an order is safe, because that session is never delivered to the customer. Which gateway handled the payment is stored on the order/booking (`payment_gateway`), so the webhook settles with the right gateway.

---

## 5. Engine independence

- **Per-engine routing.** `config/payment.gateways.php → routing` has one entry per engine (`commerce`, `service`, `video`, plus `default`). An admin can re-route or add a secondary for one engine without touching another.
- **No shared mutable state.** Each `routeFor()` / `createOrderWithFailover()` call stands alone; one engine's outage or misconfig cannot alter another engine's resolution.
- **Separate reconciliation.** Orders (commerce) and bookings (service) are already separated by `reference_type` and by collision-safe references (`WTG_{id}` vs `WTG_B{id}`).
- **Degrade-don't-die.** Config/DB problems are caught and surfaced as a clean failure (503 at create-order), never a fatal that could cascade across engines. The config file does no DB I/O and never `die()`s at include time.
- The only shared surface is **stateless library code** with degrade-don't-die semantics — which is exactly the founder's ask ("if commerce payment breaks, service must not break").

---

## 6. Money-safety preserved (nothing weakened)

| Guarantee | Where | Status |
|---|---|---|
| Server-authoritative amount | `createOnlinePaymentOrder` (DB total) + `processWebhookEvent` (re-check vs DB total) | ✅ unchanged |
| Signature-verified webhook, **per gateway** | endpoint calls `adapter->verifyWebhook(rawBody, headers)` **before** any parse/DB write | ✅ + now per-gateway |
| `reference_type` collision-safe | `WTG_{id}` (order) vs `WTG_B{id}` (booking); lookup orders-then-bookings on exact ref | ✅ unchanged |
| Idempotent | already-`paid` short-circuit; `transactions`/`wallet_transactions` guards | ✅ unchanged |
| `FOR UPDATE` where money moves | `fetchPaymentTargetForUpdate`, `ensureSuccessfulTransaction`, booking propagation | ✅ unchanged |
| Gateway attribution | `transactions.gateway` now records the real gateway (was hardcoded `cashfree`); `orders/bookings.payment_gateway` records which gateway; webhook rejects a **gateway mismatch** (a Razorpay event can't settle a Cashfree order) | ✅ new defense |
| Schema-defensive | `payment_gateway` reads/writes are guarded by an `information_schema` column check — works even before the migration runs | ✅ new |

Backward-compatible: `payment_gateway = NULL` is treated as `cashfree` everywhere, so every existing row and the inline service path keep working with no migration and no behavior change.

---

## 7. Never-touch flag (service inspection failover)

The **service ₹299 inspection** creates its Cashfree order **inline** inside `body/service-engine/api/services/index.php` (~L1587), calling `new CashfreeClient()` directly — it does **not** go through `PaymentService`. It was left **untouched** (engine = never-touch). Consequences:

- Service inspection remains **Cashfree-only** and works exactly as before — its webhook is fully handled by the new gateway-aware pipeline (defaults to Cashfree, backfills `payment_gateway='cashfree'`).
- To give the **service** path the same automatic failover as commerce, the one inline block would swap `new CashfreeClient(); $cfClient->post('/orders', …)` for `PaymentService::createOnlinePaymentOrder(..., 'service')` (or a manager call). **That is an engine edit** → flagged, not performed. Recommend doing it deliberately with the founder, since it also removes the second order-creation path the audit flagged.

Commerce needed **no** engine edit: the never-touch `shopping-engine/OrderService` already calls `PaymentService::createOnlinePaymentOrder(...)`, which now transparently gains failover. Its response is a superset (old keys preserved), so `OrderService` (which reads `success`/`error`/`cashfree_order_id` and forwards the array) is unaffected.

---

## 8. Adding a gateway (e.g. turning on Razorpay)

1. `.env`: `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET` (optional `RAZORPAY_ENV`).
2. `config/payment.gateways.php`: set `gateways.razorpay.enabled = true`; point an engine's `secondary` (or `primary`) to `razorpay` — or edit the `app_settings.payment_gateway_routing` JSON from admin.
3. Register the webhook URL `<APP_URL>/api/payment/webhook?gateway=razorpay` in the Razorpay dashboard.
4. **Validate on Razorpay TEST mode first** — the adapter follows Razorpay's documented Orders API + webhook shapes but has not been run against a live account. Confirm one sandbox payment end-to-end, and confirm the webhook↔order correlation key (see Open Questions).

No orchestration, webhook, or engine code changes are required — that is the point of the layer.

---

## 9. Migration (staging — NOT run)

`migrations/2026_08_26_001_payment_gateway_abstraction.sql`:
- `ALTER TABLE orders/bookings ADD COLUMN IF NOT EXISTS payment_gateway varchar(40) NULL` (nullable ⇒ zero risk to existing INSERTs; `NULL` = cashfree).
- `INSERT IGNORE` the `payment_gateway_routing` JSON into `app_settings` (unique `setting_key`; won't overwrite admin edits).
- `transactions.gateway` already exists — no change.
- Idempotent, no DROP, no backfill, no data loss. Apply on staging via `migrate.php`, verify with the commented checks at the bottom.

---

## 10. Open questions (founder decision)

1. **Service failover.** Adopt the layer in the inline service inspection path (one engine edit) so the ₹299 inspection also fails over? (Recommended — also kills the drift-prone second order-creation path.)
2. **Razorpay correlation key.** Razorpay `payment.captured` events carry the rzp `order_id`, not always our `receipt`. When enabling Razorpay, decide: store the rzp `order_id` in `payment_id`, or rely on `receipt` echo. Validate on sandbox.
3. **Frontend SDK switch.** `app/pages/home.js` initialises the Cashfree JS SDK directly. Real Razorpay checkout needs `{key_id, order_id}` (not a `payment_session_id`). A gateway-aware client switch is a separate frontend task (out of scope for this backend layer).
4. **Refunds.** `Payment::initiateRefund` (used by `RefundController`) is already gateway-tagged via `transactions.gateway` and safely throws "unsupported" for non-Cashfree. Fold refund into the adapter contract when Razorpay refunds are needed.

---
*Backend abstraction only; no live requests; migration staged not run; no never-touch engine edited.*
