# Phase 06 — Runtime Query Analysis

Generated: 2026-05-12T16:20:00Z

## High-Write Runtime Tables

| Table | Write Patterns | Connected Systems | Bottleneck / Integrity Risk | Confidence |
|---|---|---|---|---|
| `orders` | Checkout inserts parent + sub-orders; vendor/admin/payment/delivery update statuses; rate-limit counts recent orders. | Customer, vendor, admin, payment, delivery, inventory. | Parent/child state drift; direct admin updates bypass side effects; separate indexes may not satisfy composite rate/status queries. | High |
| `order_items` | Checkout inserts snapshots for sub-orders. | Checkout, vendor list, customer detail, analytics, refunds/inventory release. | Items attach to child orders only; queries must include child IDs. | High |
| `inventory` | Product create/update, cart availability reads, checkout `FOR UPDATE`, reserve increment, cancel/deliver decrement/release. | Product, cart, order, delivery. | Counter corruption possible without ledger; high contention on popular products. | High |
| `cart` | Atomic upsert, update, delete, clear after checkout. | Customer checkout. | Unique `(user_id, product_id)` protects auth cart but nullable/session cart semantics uncertain. | High |
| `bookings` | Service request inserts; job status mirrors updates; payment updates; list reads. | Service booking, jobs, analytics. | Main booking path does not reserve slots; alternate controller may insert incomplete rows. | High |
| `jobs` | Service request inserts; assignment cron updates locks/status; accept endpoint updates vendor/status; vendor status patch updates. | Service fulfillment, auto assignment. | Lock/status transitions split across cron/accept/vendor. | High |
| `alerts` | Assignment, status, system errors, alert engine inserts; fetch/seen updates; cleanup deletes. | Users/vendors/admin/ops. | Polymorphic refs can orphan; high read-poll potential. | High |
| `brain_events` | Event model inserts; dashboards aggregate; nightly cleanup deletes. | Brain, analytics/feed. | Some runtime code references missing columns; high aggregation read cost. | Medium |
| `transactions` | Payment/refund updates and analytics joins. | Payments, refunds, vendor analytics. | Polymorphic ref no FK; missing composite indexes for `(reference_type, reference_id, status, created_at)`. | High |

## Runtime Read/Join Patterns

### Cart Read

Query shape:

- `cart c JOIN products p ON p.id = c.product_id JOIN inventory i ON i.product_id = p.id LEFT JOIN vendors v ON v.id = p.vendor_id`
- Filters by `c.user_id`, product not deleted, cart not expired.

Impact:

- Requires every product in cart to have inventory row.
- Product without inventory disappears from cart read, causing hidden corruption behavior.
- Uses availability formula `i.quantity - i.reserved` without historical reservation ledger.

Confidence: High.

### Checkout Create

Runtime sequence:

1. Count recent parent orders for rate limit.
2. Load cart with products/inventory/vendors.
3. Begin transaction.
4. For each item: `inventory JOIN products ... FOR UPDATE`.
5. Insert parent `orders` row.
6. Insert vendor sub-orders.
7. Insert `order_items` for sub-orders.
8. Increment `inventory.reserved`.
9. Increment `products.total_sold`.
10. Clear cart.
11. Commit.
12. Non-transactional event hook and delivery dispatch.

Impact:

- Checkout protects inventory rows with `FOR UPDATE`, but no ledger records why counters changed.
- Event and delivery happen after commit and can fail independently.
- Parent order status remains independent from child order statuses.

Confidence: High.

### Vendor Order List

Query shape:

- `orders` filtered by `vendor_id`, `parent_order_id IS NOT NULL`, payment gate, status filters.
- Batch fetch `order_items` by order IDs with `LEFT JOIN products` for images.

Impact:

- Good N+1 avoidance for items.
- Depends on every vendor order being a child row.
- Status vocabulary includes values not in `orders.status` enum in some comments/filters (`failed` appears in NOT IN but enum lacks `failed`).

Confidence: High.

### Customer Order History/Detail

Query shape:

- Parent orders only: `o.user_id = :uid AND o.parent_order_id IS NULL`.
- Detail fetches vendor sub-orders separately and their items.

Impact:

- Customer sees parent-level status even though vendor statuses are on child orders.
- No traced aggregation updates parent after child progress.

Confidence: High.

### Product Management

Runtime sequence:

- Create product -> insert product -> insert inventory -> optional variation rows.
- Update product -> update allowed product fields -> upsert inventory quantity -> optional upsert variations.
- Delete product -> soft-delete and status `archived`.

Impact:

- Inventory creation is mandatory for active product usability.
- Variation upsert has ownership check by SKU; stock is independent of base inventory.
- Soft-delete does not remove cart/order references; cart filters deleted products out.

Confidence: High.

### Service Booking

Runtime sequence:

- Validate optional `addresses.id` belongs to user.
- Fetch active service.
- Insert `bookings` with service vendor and service duration/price.
- Optional online payment call updates `bookings.payment_id`.
- Insert linked `jobs` row.
- Commit.

Impact:

- No slot reservation in main flow.
- Payment support differs from product order flow.
- Booking and job statuses are mirrored only through specific job status endpoint.

Confidence: High.

### Auto Assignment

Query shape:

- Reads pending `auto_assignments`; timeouts update assignments and clear `jobs/orders.assignment_lock_time`.
- Selects jobs/orders with `FOR UPDATE SKIP LOCKED` and payment gating.
- Selects nearby online active vendors by Haversine calculation on `vendors.lat/lng` excluding previously assigned vendors.
- Inserts `auto_assignments` and alerts.

Impact:

- Geo scan over vendors is likely bottleneck without spatial/geohash index.
- Auto-assignment targets both jobs and product parent orders; product orders already have vendor child orders, causing ownership ambiguity.
- Polymorphic assignments lack FK protection.

Confidence: High.

### Admin Queries

Patterns:

- Current list endpoints use `vendors`, `services`, `orders`, `users`.
- Later admin endpoints reference missing/legacy columns/tables: `customer_id`, `rider_id`, `payments`, `merchants`, `riders`, `system_config`.

Impact:

- Admin operational reliability is uneven.
- Some high-privilege direct updates bypass domain services.

Confidence: High.

## Query Bottleneck Risks

| Query / Pattern | Risk | Missing Index Assumption | Confidence |
|---|---|---|---|
| Order rate limit by `user_id`, `parent_order_id`, `created_at` | Separate `user_id` and `created_at` indexes exist, but no composite visible. | `(user_id, parent_order_id, created_at)` | High |
| Vendor order list by `vendor_id`, `parent_order_id`, payment status/method, created sort | Index on vendor only; child/status/sort combined not visible. | `(vendor_id, parent_order_id, status, created_at)` | High |
| Customer order list by `user_id`, `parent_order_id`, status, created sort | Same issue. | `(user_id, parent_order_id, status, created_at)` | High |
| Transactions analytics joins by `reference_type`, `reference_id`, `status`, `created_at` | Only `user_id` index visible. | `(reference_type, reference_id, status, created_at)` | High |
| Bookings analytics by `vendor_id`, `created_at`, `status` | Separate vendor index only visible. | `(vendor_id, status, created_at)` | High |
| Auto assignment vendor geo search by `is_online`, `status`, `lat/lng` Haversine | No geo/spatial index visible. | geospatial/geohash or `(status,is_online,lat,lng)` approximation | High |
| Brain dashboard aggregates by `client_id`, date, category/location/event_type | Only `client_id` visible for events; many aggregates scan. | `(client_id, created_at)`, `(client_id,event_type,created_at)` | Medium |
| Alerts fetch unseen by user/vendor | Existing composite indexes appear aligned. | Existing `idx_user_unseen`, `idx_vendor_unseen` | High positive |

## Runtime Write Pattern Risks

- Inventory counter writes are protected in checkout but admin order cancel/status writes bypass inventory release.
- Product `total_sold` increments at checkout, not delivery; cancelled orders may inflate sales unless decremented elsewhere, not traced.
- Admin direct order cancellation does not trace child cancellation or inventory release.
- Delivery creation can happen after order commit and can fail without rolling back order.
- Payment webhook and create-order paths can mutate payment/order state separately from checkout.

## Uncertainty Reporting

- Actual query plans were not run against a live DB; index risk is inferred from schema indexes and query shapes.
- Admin frontend call frequency and exact route coverage were not re-tested in browser.
- Data volumes are unknown; bottleneck severity is architectural, not measured.
