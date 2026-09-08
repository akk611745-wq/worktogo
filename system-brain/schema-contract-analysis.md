# Phase 06 — Schema Contract Analysis

Generated: 2026-05-12T16:20:00Z

## Executive Contract Reality

WorkToGo's runtime schema contract is not represented by a single authoritative artifact. The live contract appears to be the combination of:

1. Baseline dump `database-structure.sql`.
2. Later migrations that add or rename required runtime columns.
3. Runtime SQL embedded directly in controllers/services.
4. Admin legacy surfaces that still reference old table/column vocabulary.

The most dangerous contract issue is not missing normalization; it is hidden reliance on migrations and embedded SQL vocabulary. Future migrations become dangerous because changing a column or table can break many direct query strings without repository-level abstraction.

## Major Contract Findings

### 1. `vendors.vendor_type` vs `vendors.type` is a central drift point

- Where: baseline dump defines `vendors.vendor_type`; migrations rename it to `type`; runtime vendor/admin/service/product code expects `type`.
- Why: the schema contract evolved after the dump, but not all artifacts were updated.
- Root cause: direct SQL across modules and no central vendor model/repository.
- Connected systems: auth role approval, vendor application, admin vendor list, services, products, stories, analytics, feed ranking.
- Runtime dependency: vendor APIs query and insert `vendors.type`; admin approval reads `type` to set `users.role`.
- Operational impact: if migration is missing, vendor registration/admin list/approval fails. If old code uses `vendor_type`, it fails after migration.
- Scale impact: operational onboarding can halt for all vendors after a schema mismatch.
- Maintainability impact: any future vendor schema change requires broad grep-based changes.
- Confidence: High.

### 2. Baseline `services` table is not enough for current service runtime

- Where: runtime uses `services.slug`, `short_desc`, `description`, `is_featured`, `rating`, `duration_minutes`, `deleted_at`, `updated_at`; baseline only has `vendor_id`, `category_id`, `name`, `base_price`, `status`, `created_at`.
- Why: migrations add service fields after baseline.
- Root cause: code assumes migration state and does not consistently feature-detect except search API.
- Connected systems: customer service list/detail/search, vendor service CRUD, booking creation, admin services.
- Runtime dependency: service list filters `deleted_at IS NULL` and sorts by `is_featured`, `rating`; service create inserts `duration_minutes`, `updated_at`.
- Operational impact: incomplete migration breaks customer service browsing and vendor service creation.
- Scale impact: service discovery queries sort by unindexed `is_featured/rating` after status/deleted filters.
- Maintainability impact: future service schema changes are risky because different endpoints assume different field sets.
- Confidence: High.

### 3. Admin module references legacy schema not present in baseline

- Where: admin endpoints reference `customer_id`, `rider_id`, `payments`, `merchants`, `riders`, `system_config`, `merchant_id`.
- Why: mixed legacy/admin implementation was carried forward while active schema uses `user_id`, `driver_id`, `transactions`, `vendors`.
- Root cause: monolithic admin router contains both current and legacy operational surfaces.
- Connected systems: admin order detail, delivery list, payments, products, riders, merchants, system config.
- Runtime dependency: admin pages may call these endpoints depending frontend route configuration.
- Operational impact: endpoint failures in operations dashboard; misleading admin visibility during incidents.
- Scale impact: emergency/admin workflows degrade first when data volume and incident pressure increase.
- Maintainability impact: developers cannot infer active contract from admin code without cross-checking schema.
- Confidence: High.

### 4. Product inventory contract is split between base inventory and variation stock

- Where: `inventory` has `quantity/reserved`; `product_variations` has `stock_quantity`; checkout reserves/deducts only `inventory`.
- Why: variations were added without integrating checkout reservation at variation granularity.
- Root cause: parallel stock models with no unified inventory ledger.
- Connected systems: vendor product variations, product detail, cart, checkout, inventory reservation, delivery.
- Runtime dependency: cart and checkout join `inventory`; variation stock is read/update in product controller only.
- Operational impact: customer may order product regardless of selected variation stock if variation selection becomes active.
- Scale impact: variation-level catalog expansion becomes dangerous without stock contract rewrite.
- Maintainability impact: future variation checkout must reconcile historical orders that did not capture `variation_id` in `order_items`.
- Confidence: High.

### 5. Parent/child order model is implicit and fragile

- Where: `orders` stores both parent customer orders and vendor sub-orders; `order_items` attach to sub-orders.
- Why: multi-vendor checkout split was implemented in one table.
- Root cause: implicit row-type determined by `parent_order_id` and `vendor_id`, not explicit `order_type`.
- Connected systems: customer order history, vendor order list, admin order controls, delivery dispatch, payment/refund, inventory.
- Runtime dependency: customer reads parent rows; vendors read child rows; delivery dispatch targets parent.
- Operational impact: direct admin updates to parent do not update children or inventory; vendor child completion does not trace to parent completion.
- Scale impact: status aggregation becomes more expensive and error-prone as vendor count/order volume grows.
- Maintainability impact: every new order query must understand parent/child split.
- Confidence: High.

### 6. Polymorphic references lack referential integrity

- Where: `transactions.reference_type/reference_id`, `auto_assignments.entity_type/entity_id`, `alerts.ref_type/ref_id`, `followers.target_type/target_id`, brain target references.
- Why: flexible event/reference model.
- Root cause: polymorphic pattern with no database-level FK.
- Connected systems: payments/refunds, assignment, alerts, feed/social, analytics.
- Runtime dependency: joins filter by type and assume referenced records exist.
- Operational impact: orphaned records can produce false notifications, failed refunds, duplicate assignments, or wrong analytics.
- Scale impact: cleanup, archival, and migration require application-aware sweeps.
- Maintainability impact: future migrations must include custom validation scripts for every polymorphic relationship.
- Confidence: High.

### 7. Payment contract is fragmented

- Where: product checkout is COD-only in `OrderService`; separate online payment APIs exist; services support `online`; transactions table is generic; admin legacy queries `payments` table not in baseline.
- Why: payment evolution occurred in parallel modules.
- Root cause: no single payment contract/service model enforced across order/booking/admin.
- Connected systems: orders, bookings, transactions, refunds, Cashfree, admin payments, payment webhooks.
- Runtime dependency: refunds use `transactions`; product order online init can still appear in code path but allowed methods block online in create.
- Operational impact: admin may look for payment rows that do not exist; refunds may not exist for COD orders.
- Scale impact: finance reconciliation becomes difficult as payment methods expand.
- Maintainability impact: adding a gateway requires harmonizing orders/bookings/transactions/admin.
- Confidence: High.

### 8. Delivery contract has driver/rider naming drift

- Where: baseline `deliveries.driver_id`; admin/delivery code references `rider_id`; admin lists `riders` table.
- Why: delivery terminology changed or merged from another schema.
- Root cause: legacy code remains active in admin router.
- Connected systems: delivery assignment, delivery API, admin delivery list/detail, driver wallets, SwiftDeliver.
- Runtime dependency: some assignment code writes `driver_id`, some status code writes `rider_id`.
- Operational impact: delivery status updates and admin views can fail or detach from assigned drivers.
- Scale impact: rider fleet management cannot scale on ambiguous identity fields.
- Maintainability impact: future delivery migrations are high-risk until terminology is standardized in docs and code.
- Confidence: High.

## Contract Confidence Matrix

| Contract | Confidence | Why |
|---|---:|---|
| Users as identity root | High | Schema/FKs/runtime all align, except nullable phone migration. |
| Vendor owns products/services/orders/bookings/jobs | High | FKs and runtime ownership checks align, but column name drift exists. |
| Product checkout uses inventory reservation | High | Runtime row locks and updates traced. |
| Variation stock participates in checkout | Low | Variation stock updates traced, but checkout ignores variations. |
| Service booking creates linked job | High | Main service request inserts booking then job in transaction. |
| Slot reservation is part of main booking | Low | Separate controller reserves slots; main request does not. |
| Online product payment active in main checkout | Low | COD-only allowed in order service; payment APIs exist separately. |
| Admin all endpoints match schema | Low | Many legacy references are absent in baseline. |
| Brain schema matches all brain code | Medium-Low | Current models align partly; legacy BrainCore/feed references missing columns. |

## Future Migration Dangers

1. Renaming `vendors.type` or changing enum values will break vendor registration, admin approval, feed, product/service filters, and role mapping.
2. Tightening `users.phone` to NOT NULL will break email, Google, and guest auth flows.
3. Adding FKs to polymorphic tables without cleanup will fail if orphaned rows exist.
4. Splitting parent/child orders into separate tables would require careful migration of `order_items`, delivery pointers, payment refs, and admin/customer/vendor history.
5. Converting delivery `driver_id` to `rider_id` without auditing all references will break assignment/status/admin views.
6. Enforcing service `deleted_at`/`updated_at` assumptions before migration verification will break service APIs.
7. Introducing variation-level checkout will require order item contract changes, cart variation identity, inventory reservation changes, and historical compatibility.

## Uncertainty Reporting

- The true production schema may include out-of-band tables not in the traced dump.
- Migrations use `IF NOT EXISTS` and information schema checks, but actual applied order is unknown.
- Root workspace SQL dump may represent another snapshot; not relied on as active `wtg  live` schema in this phase.
- Some admin frontend pages may not call all legacy endpoints during normal use, but the backend routes are present and dangerous.
