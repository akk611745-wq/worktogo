# Phase 06 — DB Scaling Risks

Generated: 2026-05-12T16:20:00Z

## Highest Scaling Risk Tables

| Table | Growth Driver | Current Contract Risk | Scale Impact | Confidence |
|---|---|---|---|---|
| `orders` | Every checkout creates parent + one row per vendor. | Parent/child split doubles/triples rows and requires careful filtering. | Query load grows faster than order count; status aggregation expensive. | High |
| `order_items` | Every ordered product. | Child-order attachment requires IN queries for customer details. | Large history reads become costly without pagination/archival. | High |
| `inventory` | Every product plus high write contention on popular items. | Mutable counters without ledger. | Hot-row locks during flash demand. | High |
| `cart` | Active users and abandoned carts. | Cleanup depends on runtime filters; no traced scheduled purge. | Stale rows increase reads/writes. | Medium |
| `alerts` | Assignment/status/system events. | Polling/fetch unseen can grow quickly; cleanup only seen old alerts. | Notification table can become high-volume operational table. | High |
| `brain_events` | Every behavior/event. | Aggregations and cleanup; schema mismatch in legacy code. | Largest analytics table risk. | Medium |
| `transactions` | Payments/refunds/revenue analytics. | Missing composite indexes for reference joins. | Finance/admin analytics degrade. | High |
| `bookings` / `jobs` | Service marketplace growth. | Status/job/slot coupling and vendor/date analytics. | Vendor dashboards and assignment slow down. | High |
| `auto_assignments` | Each assignment attempt per job/order/vendor. | Polymorphic rows and timeout polling. | Assignment cron scans grow under demand. | High |

## Query Bottleneck Observations

1. Order history and vendor lists order by `created_at` while filtering by user/vendor/status/parent status. Composite indexes are not visible.
2. Transactions analytics joins use `reference_type/reference_id/status/created_at`; only `user_id` index appears in baseline.
3. Auto assignment uses Haversine calculation on all active/online vendors within radius; no geospatial index is visible.
4. Brain dashboards aggregate by multiple dimensions and time windows; only `client_id` index is visible on `brain_events`.
5. Admin list endpoints use broad `COUNT(*)` plus paginated list; some use raw query string filters and legacy joins.

## Multi-City Scaling DB Risks

- Baseline has `addresses.city` and `app_settings.platform_city`, but vendors do not have a normalized `city` column in baseline. Some Brain/feed code references `vendors.city`, which is not present.
- Vendor assignment relies on lat/lng distance, not city-partitioned routing.
- Categories/products/services are global; no city/service-area partitioning traced.
- Warehouses exist but runtime inventory mostly ignores warehouse dimension.
- Multi-city expansion would need city/service-area contracts before scaling; current schema does not enforce city scoping for orders/bookings/vendors/products/services.

## Operational Scaling Risks

- Polling pages (orders/bookings/alerts) create linear read amplification with active users.
- Admin dashboards aggregate operational totals directly from OLTP tables.
- Inventory reservation hot rows can block concurrent checkouts for popular products.
- Lack of immutable audit/ledger for inventory and admin changes makes debugging scale incidents harder.

## Confidence and Uncertainty

- Index risk is based on traced schema and query shapes, not EXPLAIN plans.
- Real production data volume is unknown.
- Some performance comments in code assert index assumptions that are not fully present as composite indexes.
