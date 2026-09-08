# Phase 06 — Schema Drift Analysis

Generated: 2026-05-12T16:20:00Z

## Drift Register

| Drift | Where | Root Cause | Connected Systems | Operational Impact | Confidence |
|---|---|---|---|---|---|
| `vendors.vendor_type` vs `vendors.type` | Baseline dump vs migrations/runtime | Rename migration after dump | Vendor onboarding, admin, product/service ownership | Hard failures if migration not applied; inconsistent API aliases. | High |
| `users.phone` NOT NULL vs nullable auth flows | Baseline vs migrations/auth runtime | Email/Google/guest auth added after phone-only schema | Auth, vendor application, admin users | Registration/login failures if nullable migration missing. | High |
| `services` baseline minimal vs runtime extended | Baseline vs migrations/service APIs | Service UX expanded after dump | Customer services, vendor services, search | CRUD/list/search failures if columns missing. | High |
| `categories.status/type` vs migrated `module/is_active/image_url/sort_order` | Baseline vs migrations/frontend expectations | Category model expanded | Home/search/admin/product/service category | Queries may mix `status` and `is_active` semantics. | Medium |
| `deliveries.driver_id` vs runtime `rider_id` | Baseline vs admin/delivery code | Legacy rider vocabulary | Admin delivery, delivery status, wallets | Some endpoints update/select nonexistent column. | High |
| `orders.user_id` vs admin `customer_id` | Baseline vs admin detail/list legacy queries | Legacy customer vocabulary | Admin order detail, delivery/payment joins | Admin order detail can fail or return null joins. | High |
| `transactions` vs runtime `payments` | Baseline vs admin payment endpoints | Payment table renamed/legacy | Admin payments, refunds, Cashfree | Admin payment visibility likely broken. | High |
| `vendors/products` vs legacy `merchants/merchant_id` | Baseline vs admin products/merchants endpoints | Legacy merchant model retained | Admin products/merchants | Legacy endpoints likely fail. | High |
| `users.role='delivery'` + `driver_wallets` vs `riders` table | Baseline vs admin riders endpoint | Delivery model terminology split | Admin riders, delivery assignment | Fleet visibility likely broken. | High |
| Brain baseline columns vs BrainCore/feed references | `brain_events` lacks `user_id/target_id/target_type`; vendors lacks `city/total_orders` baseline | Legacy/research brain code retained | Brain ranking/analytics | Queries can fail if legacy classes active. | Medium |
| `products.name` vs runtime analytics `p.title`; `order_items.unit_price` vs `oi.price` | Shopping analytics controller | Legacy product/order item vocabulary | Vendor analytics top products | Analytics query can fail or fallback. | High |

## Important Issues

### Admin legacy schema drift

- Where: admin monolithic router later endpoints.
- Why: active schema moved to `users/vendors/transactions/driver_id`, but admin endpoints still use `customer_id/payments/merchants/riders/rider_id`.
- Root cause: legacy endpoint blocks retained in same router as current endpoints.
- Connected systems: admin orders, deliveries, payments, products, riders, merchants, system config.
- Operational impact: admins may lose visibility or control during incidents.
- Scale impact: operational tooling becomes unreliable as data grows.
- Maintainability impact: developers must manually classify each admin route as current or legacy.
- Future risk: schema migration attempts may accidentally preserve legacy structures instead of active contracts.
- Confidence: High.

### Vendor type migration drift

- Where: vendor schema and runtime vendor code.
- Why: runtime now expects `type`, baseline shows `vendor_type`.
- Root cause: rename migration and partial compatibility aliasing.
- Connected systems: vendor registration, approval, admin list, service/product vendors, feed.
- Operational impact: vendor creation/listing/approval breaks if schema state differs.
- Scale impact: onboarding bottleneck.
- Maintainability impact: high grep burden for any vendor type change.
- Future risk: enum expansion beyond `service/shopping` will require coordinated role and module changes.
- Confidence: High.

### Delivery naming drift

- Where: `deliveries.driver_id` vs `rider_id`; `users.role='delivery'` vs `riders` table.
- Why: delivery subsystem appears integrated from or planned with rider vocabulary.
- Root cause: no canonical delivery identity contract.
- Connected systems: admin assignment, delivery API, SwiftDeliver, driver wallets.
- Operational impact: delivery assignment/status endpoints can disagree or fail.
- Scale impact: fleet dispatch and COD cash tracking cannot scale safely.
- Maintainability impact: every delivery migration risks wrong column/table.
- Future risk: multi-city delivery expansion will magnify identity drift.
- Confidence: High.

## Drift That Is Intentionally Handled

- Search API checks whether `services.deleted_at` exists before choosing query variant. This is a rare feature-detection pattern and reduces service migration risk for that endpoint only.
- Service analytics disables reviews query because `reviews` table does not exist in traced schema.
- Vendor list APIs often select `v.type AS vendor_type` for frontend compatibility, mitigating response contract drift but not schema drift.

## Uncertainty Reporting

- Some missing tables may exist in production outside the traced dump; this is unverified.
- Some legacy admin routes may be unused by current frontend pages; backend exposure remains documented as risk.
- Migration application status is unknown.
