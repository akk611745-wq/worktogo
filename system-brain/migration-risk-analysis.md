# Phase 06 — Migration Risk Analysis

Generated: 2026-05-12T16:20:00Z

## Migration Reality

The project contains a baseline SQL dump plus incremental SQL migrations. Runtime code assumes several migrations have already been applied. The migration runner creates `migrations_log`, but actual applied production state was not verified.

## Dangerous Migration Areas

| Area | Why Dangerous | What Breaks | Confidence |
|---|---|---|---|
| Vendor column rename | Runtime expects `type`; baseline has `vendor_type`. | Vendor registration/list/approval/product/service ownership. | High |
| Nullable user phone/email | Auth flows depend on nullable identity columns. | Email/Google/guest registration. | High |
| Service extended columns | Runtime inserts/filters/sorts by migrated columns. | Service CRUD, search, booking. | High |
| Delivery driver/rider rename | Code references both `driver_id` and `rider_id`. | Assignment, status updates, admin delivery views. | High |
| Orders parent/child split refactor | Many queries depend on implicit row type. | Customer/vendor/admin/payment/delivery. | High |
| Transaction/payment model consolidation | Runtime uses `transactions`; admin uses `payments`. | Refunds, payments dashboard, webhooks. | High |
| Inventory variation integration | Current checkout ignores variation stock. | Historical order item contract, cart uniqueness, inventory counters. | High |
| Adding integrity FKs to polymorphic refs | Existing orphan data may block migration. | Transactions, alerts, auto assignments, followers. | High |

## Required Pre-Migration Intelligence Before Any Future Change

1. Compare actual production `INFORMATION_SCHEMA` against baseline and migrations.
2. Export row counts and orphan counts for every polymorphic table.
3. Validate which admin endpoints are actively used by frontend pages.
4. Trace all direct SQL references to any column targeted for migration.
5. Create read-only compatibility views or aliases before renaming high-use columns.
6. Build data backfill/validation scripts before adding constraints.
7. Define parent/child order aggregation contract before changing order statuses.

## Migration Break Examples

- Removing `vendor_type` without ensuring `type` exists breaks older code; removing `type` breaks current code.
- Enforcing `orders.vendor_id NOT NULL` breaks parent orders.
- Enforcing `bookings.address_id NOT NULL` breaks service bookings without saved address.
- Adding FK from `orders.delivery_id` to `deliveries.id` can fail if pointers are stale.
- Adding FK-like constraints to `transactions.reference_id` is not directly possible without splitting tables or generated constraints.
- Renaming `driver_id` to `rider_id` without dual-write/backfill breaks current admin assignment path.

## Uncertainty Reporting

- No production migration log data was inspected.
- No row-level data quality checks were run.
- Some migrations may have been applied manually or partially.
