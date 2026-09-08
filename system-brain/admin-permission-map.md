# Admin Permission Map

Generated: 2026-05-12T08:15:00Z

---

## 1. Permission Hierarchy Reality

There is no traced multi-level admin permission hierarchy. The operational backend uses `ROLE_ADMIN` as a single gate for `/api/admin/*`, while refund admin routes separately call admin role middleware inside `RefundController`.

Evidence: `wtg  live/heart/api/admin/index.php:12`, `wtg  live/heart/index.php:237`, `wtg  live/core/controllers/RefundController.php:176`, `wtg  live/core/controllers/RefundController.php:314`, `wtg  live/core/controllers/RefundController.php:397`.

Connected systems: AuthMiddleware, JWT role claim, admin frontend localStorage, admin API, refund controller.

Runtime dependency: bearer token role `admin`.

Operational impact: all admin users appear equivalent in authority.

Scale implication: adding finance/support/operations teams would require new policy architecture.

Maintainability impact: route-local direct SQL makes scoped permission extraction difficult.

Confidence: High.

---

## 2. Admin Frontend Auth Behavior

Admin login stores token under `wtg_admin_token`, role under `wtg_admin_role`, and user under `wtg_admin_user`. The shared API sends bearer auth from that token and redirects to `/admin/index.html` on 401/403.

Evidence: `wtg  live/admin/shared/auth.js:41`, `wtg  live/admin/shared/api.js:13`, `wtg  live/admin/shared/api.js:62`.

Auth risk: `refunds.html` uses a different token key (`admin_token`) and redirects to `login.html`, which differs from shared admin auth.

Evidence: `wtg  live/admin/refunds.html:284`, `wtg  live/admin/refunds.html:286`, `wtg  live/admin/refunds.html:288`.

---

## 3. Authority Matrix

| Authority | Backend route | Permission gate | Additional validation | Evidence | Risk |
|---|---|---|---|---|---|
| View dashboard | `GET /api/admin/dashboard` | `ROLE_ADMIN` | none | `wtg  live/heart/api/admin/index.php:16` | Broad operational visibility |
| List/update users | `/api/admin/users*` | `ROLE_ADMIN` | enum validation for status/role | `wtg  live/heart/api/admin/index.php:375`, `wtg  live/heart/api/admin/index.php:441` | Admin can alter roles including admin |
| Block/unblock users | `/api/admin/users/{id}/block|unblock` | `ROLE_ADMIN` | none | `wtg  live/heart/api/admin/index.php:607`, `wtg  live/heart/api/admin/index.php:616` | No reason or duration model |
| Approve/reject vendors | `/api/admin/vendors/{id}/approve|reject` | `ROLE_ADMIN` | none beyond row update | `wtg  live/heart/api/admin/index.php:480`, `wtg  live/heart/api/admin/index.php:503` | Role mutation and moderation without structured audit |
| Vendor activate/deactivate | `/api/admin/vendors/{id}/status` | `ROLE_ADMIN` | frontend status map only | `wtg  live/heart/api/admin/index.php:513` | Does not revoke user role/token |
| Order status/cancel | `/api/admin/orders/{id}/status|cancel` | `ROLE_ADMIN` | requires status string | `wtg  live/heart/api/admin/index.php:634`, `wtg  live/heart/api/admin/index.php:648` | Bypasses service-level lifecycle side effects |
| Delivery assign | `/api/admin/deliveries/assign` | `ROLE_ADMIN` | order/driver required; driver block check | `wtg  live/heart/api/admin/index.php:552` | Payload mismatch and duplicate delivery risk |
| Refund approve/reject/list | `/api/admin/refund*` | `ROLE_ADMIN` in controller | transaction state checks | `wtg  live/core/controllers/RefundController.php:176`, `wtg  live/core/controllers/RefundController.php:314`, `wtg  live/core/controllers/RefundController.php:397` | Stronger state checks than generic order admin |
| Purge logs | `DELETE /api/admin/logs` | `ROLE_ADMIN` | age only | `wtg  live/heart/api/admin/index.php:588` | Admin can remove audit evidence |
| Config upsert | `/api/admin/system-config` | `ROLE_ADMIN` | key required only | `wtg  live/heart/api/admin/index.php:857` | Arbitrary config writes |

---

## 4. Hidden Authority Assumptions

- Admin identity equals full authority.
- Frontend page visibility equals operational availability, but several UI routes target endpoints not implemented in admin API.
- Logger entries are sufficient audit evidence for critical actions.
- Vendor deactivation via `vendors.status` is sufficient restriction, despite user role/token persistence.
- Manual order updates are acceptable recovery even when they bypass OrderService side effects.

Evidence: `wtg  live/heart/api/admin/index.php:12`, `wtg  live/admin/config.js:35`, `wtg  live/heart/api/admin/index.php:496`, `wtg  live/heart/api/admin/index.php:531`, `wtg  live/heart/api/admin/index.php:642`.

