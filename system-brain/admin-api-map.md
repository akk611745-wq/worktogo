# Admin API Map

Generated: 2026-05-12T08:15:00Z

---

## 1. Admin Route Protection

Heart dispatches `/api/admin*` to `heart/api/admin/index.php` before the refund routing block. All routes inside `heart/api/admin/index.php` require `ROLE_ADMIN` at file entry. Admin refund routes are handled later by `RefundController` only if not consumed by the admin router.

Evidence: `wtg  live/heart/index.php:195`, `wtg  live/heart/api/admin/index.php:12`, `wtg  live/heart/index.php:237`.

Important routing implication: because `/api/admin/refunds` starts with `/api/admin`, it enters `heart/api/admin/index.php` first. That file does not implement `/api/admin/refunds`, then returns `Response::notFound('Admin endpoint')`, preventing the later refund block from handling it.

Evidence: `wtg  live/heart/index.php:195`, `wtg  live/heart/api/admin/index.php:900`, `wtg  live/heart/index.php:263`, `wtg  live/admin/refunds.html:367`.

Confidence: High.

---

## 2. Traced Admin Endpoints

| Endpoint | Methods | Purpose | Implemented | Evidence |
|---|---|---|---|---|
| `/api/admin/dashboard` | GET | Operational dashboard | Yes | `wtg  live/heart/api/admin/index.php:16` |
| `/api/admin/stats` | GET | Counts summary | Yes | `wtg  live/heart/api/admin/index.php:206` |
| `/api/admin/vendors` | GET | Vendor list/moderation queue | Yes | `wtg  live/heart/api/admin/index.php:235` |
| `/api/admin/vendors/{id}` | GET | Vendor detail | Yes | `wtg  live/heart/api/admin/index.php:535` |
| `/api/admin/vendors/{id}/approve` | POST | Vendor approval + user role mutation | Yes | `wtg  live/heart/api/admin/index.php:480` |
| `/api/admin/vendors/{id}/reject` | POST | Vendor rejection | Yes | `wtg  live/heart/api/admin/index.php:503` |
| `/api/admin/vendors/{id}/status` | PATCH | Vendor active/inactive | Yes | `wtg  live/heart/api/admin/index.php:513` |
| `/api/admin/users` | GET | User list | Yes | `wtg  live/heart/api/admin/index.php:375` |
| `/api/admin/users/{id}` | GET/PATCH | Detail/update role/status | Yes | `wtg  live/heart/api/admin/index.php:425`, `wtg  live/heart/api/admin/index.php:441` |
| `/api/admin/users/{id}/block` | POST | Suspend user | Yes | `wtg  live/heart/api/admin/index.php:607` |
| `/api/admin/users/{id}/unblock` | POST | Reactivate user | Yes | `wtg  live/heart/api/admin/index.php:616` |
| `/api/admin/services` | GET | Service list | Yes | `wtg  live/heart/api/admin/index.php:302` |
| `/api/admin/products` | GET | Product list | Yes | `wtg  live/heart/api/admin/index.php:736` |
| `/api/admin/products/{id}` | DELETE | Product archive/delete | Yes | `wtg  live/heart/api/admin/index.php:625` |
| `/api/admin/orders` | GET | Order list | Yes | `wtg  live/heart/api/admin/index.php:336` |
| `/api/admin/orders/{id}` | GET | Order detail | Yes, but likely schema drift | `wtg  live/heart/api/admin/index.php:660` |
| `/api/admin/orders/{id}/status` | PUT/PATCH | Direct order status update | Yes | `wtg  live/heart/api/admin/index.php:634` |
| `/api/admin/orders/{id}/cancel` | POST | Direct order cancel | Yes | `wtg  live/heart/api/admin/index.php:648` |
| `/api/admin/deliveries` | GET | Delivery list | Yes, but schema drift risk | `wtg  live/heart/api/admin/index.php:698` |
| `/api/admin/deliveries/assign` | POST | Manual delivery assignment | Yes | `wtg  live/heart/api/admin/index.php:552` |
| `/api/admin/payments` | GET | Payment records | Yes, but payments table uncertainty | `wtg  live/heart/api/admin/index.php:767` |
| `/api/admin/logs/activity` | GET | Activity logs | Yes | `wtg  live/heart/api/admin/index.php:577` |
| `/api/admin/logs` | DELETE | Purge log files | Yes | `wtg  live/heart/api/admin/index.php:588` |
| `/api/admin/settings` | GET | App settings | Yes | `wtg  live/heart/api/admin/index.php:359` |
| `/api/admin/settings/{key}` | PUT | Update single setting | Yes | `wtg  live/heart/api/admin/index.php:367` |
| `/api/admin/system-config` | GET/POST | system_config read/upsert | Yes | `wtg  live/heart/api/admin/index.php:857` |

---

## 3. Frontend Endpoint Drift

| Frontend endpoint | Frontend caller | Backend reality | Impact | Evidence |
|---|---|---|---|---|
| `/products/{id}/status` | Products enable/disable | No matching admin route traced | Product status toggle fails | `wtg  live/admin/config.js:36`, `wtg  live/admin/products.html:142`, `wtg  live/heart/api/admin/index.php:625` |
| `/services/{id}/status` | Services enable/disable | No matching admin route traced | Service status toggle fails | `wtg  live/admin/config.js:39`, `wtg  live/admin/services.html:122` |
| `/system/toggle` | System toggles | No matching route traced | Toggle UI fails | `wtg  live/admin/config.js:49`, `wtg  live/admin/system.html:210` |
| `/system/emergency-stop` | Emergency stop | No matching route traced | Emergency UI fails | `wtg  live/admin/config.js:50`, `wtg  live/admin/system.html:254` |
| PATCH `/system/config` | Save config fields | Backend supports GET/POST `/system-config`, not PATCH `/system/config` | Config save fails | `wtg  live/admin/config.js:48`, `wtg  live/admin/system.html:236`, `wtg  live/heart/api/admin/index.php:857` |
| POST `/admin/settings` | Delivery settings save | Backend supports GET `/admin/settings` and PUT `/admin/settings/{key}` only | Delivery settings save fails | `wtg  live/admin/system.html:224`, `wtg  live/heart/api/admin/index.php:359` |
| `/deliveries/{id}` | Delivery track | No matching admin delivery detail route traced | Track action fails | `wtg  live/admin/config.js:46`, `wtg  live/admin/delivery.html:145` |
| `/admin/refunds` | Refund list page | Likely swallowed by `/api/admin` router before RefundController | Refund page falls back to stub data | `wtg  live/admin/refunds.html:367`, `wtg  live/heart/api/admin/index.php:900`, `wtg  live/heart/index.php:263` |

---

## 4. Schema Drift Risks in Admin API

Admin queries reference mixed legacy columns/tables:

- `orders.customer_id`, while main order schema uses `user_id`.
- `deliveries.rider_id`, while delivery schema uses `driver_id`.
- `payments` table, not present in the traced main database structure list where `transactions` is the settlement table.
- `merchants` and `merchant_id`, while current commerce model uses `vendors`/`vendor_id`.

Evidence: `wtg  live/heart/api/admin/index.php:669`, `wtg  live/heart/api/admin/index.php:681`, `wtg  live/heart/api/admin/index.php:688`, `wtg  live/heart/api/admin/index.php:748`, `wtg  live/heart/api/admin/index.php:826`, `wtg  live/database-structure.sql:535`, `wtg  live/database-structure.sql:420`, `wtg  live/database-structure.sql:755`.

Operational impact: admin detail/payment/delivery/product views can fail or display incomplete data in the real schema.

Confidence: High for mismatch presence.

