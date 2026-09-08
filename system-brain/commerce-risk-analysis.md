# Commerce + Fulfillment Risk Analysis

Generated: 2026-05-12T05:36:00Z

---

## 1. Highest-Risk Findings

### 1.1 Vendor panel intent drift can block fulfillment operations

Where: `wtg  live/vendor/shared/api.js`, `wtg  live/heart/router.php`, `wtg  live/body/shopping-engine/index.php`, `wtg  live/body/service-engine/index.php`.

Why: Vendor frontend calls intents such as `vendor:list_orders`, `vendor:update_order_status`, `vendor:list_jobs`, and `vendor:update_job_status`. AppRouter only maps some of these, and engine switches expect different service-specific intent names such as `shopping:vendor_orders`, `shopping:vendor_update_order`, `service:vendor_bookings`, and `service:vendor_update_booking`.

Impact: Vendor order/job pages may receive default engine responses or unmapped-intent errors instead of operational data/actions.

Evidence: `wtg  live/vendor/shared/api.js:82`, `wtg  live/vendor/shared/api.js:91`, `wtg  live/heart/router.php:218`, `wtg  live/body/shopping-engine/index.php:46`, `wtg  live/body/service-engine/index.php:53`.

Future risk: As vendor traffic grows, support load and order/job stagnation risk increase because fulfillment actions can fail before reaching controllers.

---

### 1.2 Parent and child order lifecycle can diverge

Where: product checkout and vendor order status update.

Why: Checkout creates a parent order plus child vendor orders. Vendor fulfillment updates child order rows. No traced aggregate update pushes child status back to the parent order.

Impact: Customer/admin dashboards using parent orders may display stale status, while vendors see advanced child statuses.

Evidence: `wtg  live/body/shopping-engine/api/orders/OrderService.php:165`, `wtg  live/body/shopping-engine/api/orders/OrderService.php:215`, `wtg  live/body/shopping-engine/api/orders/OrderService.php:537`, `wtg  live/body/shopping-engine/api/orders/OrderService.php:555`.

Future risk: Multi-vendor orders become difficult to summarize, cancel, refund, or dispatch accurately.

---

### 1.3 Product auto-assignment conflicts with vendor sub-order model

Where: cron assignment engine and assignment acceptance.

Why: Auto assignment selects parent product orders with `vendor_id IS NULL`; accepting an order sets the parent `vendor_id`. But product checkout already creates vendor-specific child orders with vendor IDs.

Impact: Parent order assignment can imply a single vendor owns a multi-vendor order, conflicting with child vendor ownership.

Evidence: `wtg  live/cron/auto_assign.php:71`, `wtg  live/api/assignments/accept.php:115`, `wtg  live/body/shopping-engine/api/orders/OrderService.php:215`.

Future risk: Dispatch/alerts/vendor acceptance can become semantically wrong for marketplace orders.

---

### 1.4 Payment and refund lifecycle is fragmented

Where: `OrderService`, `PaymentService`, core `Payment`, `RefundController`.

Why: Product order creation is COD-only; payment API supports online product payments; service booking calls a separate core Payment helper; refunds require transaction rows created by online webhooks.

Impact: Online vs COD vs service payment behavior differs, and refund eligibility can depend on whether a transaction row exists rather than the business order/booking state alone.

Evidence: `wtg  live/body/shopping-engine/api/orders/OrderService.php:44`, `wtg  live/api/payment/create-order.php:3`, `wtg  live/body/service-engine/api/services/index.php:282`, `wtg  live/lib/PaymentService.php:244`, `wtg  live/core/controllers/RefundController.php:44`.

Future risk: Settlement reporting and refund automation will be unreliable without a unified payment/transaction contract.

---

### 1.5 Delivery dispatch is non-fatal and split across paths

Where: product order creation and payment webhook.

Why: COD order creation attempts dispatch after DB commit. Online payment webhook separately attempts dispatch only after payment success and requires an existing delivery row. Headers/secrets also differ between paths.

Impact: Orders can remain valid even if dispatch fails; dispatch failures are logged but not queued for retry in traced paths.

Evidence: `wtg  live/body/shopping-engine/api/orders/OrderService.php:303`, `wtg  live/body/shopping-engine/api/orders/OrderService.php:965`, `wtg  live/lib/PaymentService.php:287`, `wtg  live/lib/PaymentService.php:301`.

Future risk: Delivery SLA depends on log monitoring or external recovery not found in this trace.

---

## 2. Secondary Risks

| Risk | Severity | Evidence | Impact |
|---|---:|---|---|
| Service booking slot logic exists in separate `BookingController` but main service request path does not use it | Medium | `wtg  live/body/service-engine/api/BookingController.php:41`, `wtg  live/body/service-engine/api/services/index.php:204` | Main booking path can bypass slot reservation constraints unless another layer enforces them |
| Assignment geolocation can default to `0,0` | Medium | `wtg  live/cron/auto_assign.php:112`, `wtg  live/cron/auto_assign.php:124` | Nearest vendor selection may be meaningless for missing coordinates |
| Refund status is stored on transactions, not a dedicated refunds table | Medium | `wtg  live/database-structure.sql:755`, `wtg  live/core/controllers/RefundController.php:78` | Complex partial/multi-refund scenarios may be hard to model |
| Rate limit failure comment says controller should map to 429 but service throws generic RuntimeException | Low-medium | `wtg  live/body/shopping-engine/api/orders/OrderService.php:753` | Clients may receive validation/server-style errors instead of true rate-limit semantics |

---

## 3. Recommended Next Phase Candidate

Recommended Phase 05: Vendor + Admin Operations Intelligence Mapping — deeply trace vendor frontend pages, admin operational dashboards, direct admin APIs, vendor profile/product/service management, admin order/refund/payment controls, and resolve the exact intent/API drift map without modifying runtime code.

