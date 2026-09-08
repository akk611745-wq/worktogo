# Admin Operations Risk Analysis

Generated: 2026-05-12T08:15:00Z

---

## 1. Mandatory Risk Register

### 1.1 Single-role admin overreach

WHERE: `heart/api/admin/index.php`.

WHY: `ROLE_ADMIN` is the only traced backend permission gate for all admin routes.

ROOT CAUSE: no scoped admin roles/policies.

CONNECTED SYSTEMS: users, vendors, orders, delivery, payments, settings, logs.

OPERATIONAL IMPACT: any admin can perform high-impact actions across all domains.

SCALE IMPACT: unsafe for larger operations teams.

MAINTAINABILITY IMPACT: retrofitting permissions requires decomposing monolithic route logic.

FUTURE RISK: compromised/admin-misused token has platform-wide blast radius.

Evidence: `wtg  live/heart/api/admin/index.php:12`.

Confidence: High.

---

### 1.2 Admin order interventions bypass domain lifecycle rules

WHERE: admin order status/cancel routes.

WHY: direct `orders.status` updates do not call `OrderService` cancellation/status transition methods.

ROOT CAUSE: admin controls use direct SQL rather than domain services.

CONNECTED SYSTEMS: orders, inventory, vendor sub-orders, delivery, refunds, customer tracking.

OPERATIONAL IMPACT: manual updates can create state drift.

SCALE IMPACT: every manual intervention increases reconciliation burden.

MAINTAINABILITY IMPACT: duplicate lifecycle rules exist in admin and service layers.

FUTURE RISK: inventory/payment/delivery inconsistencies become systemic.

Evidence: `wtg  live/heart/api/admin/index.php:642`, `wtg  live/heart/api/admin/index.php:651`, `wtg  live/body/shopping-engine/api/orders/OrderService.php:337`.

Confidence: High.

---

### 1.3 Vendor deactivation is not complete access revocation

WHERE: vendor status route and vendor panel guard.

WHY: admin deactivation only changes vendor status; user role/token remains vendor, and panel guard checks local role only.

ROOT CAUSE: vendor authority split between `users.role`, `vendors.status`, JWT, and localStorage.

CONNECTED SYSTEMS: admin vendors page, vendor panel, service/shopping endpoints.

OPERATIONAL IMPACT: inactive vendors may retain UI access and some API access depending endpoint filters.

SCALE IMPACT: moderation enforcement becomes inconsistent across modules.

MAINTAINABILITY IMPACT: every vendor endpoint must remember active-status enforcement.

FUTURE RISK: restricted vendors continue actions in some surfaces.

Evidence: `wtg  live/heart/api/admin/index.php:529`, `wtg  live/vendor/shared/auth.js:78`, `wtg  live/body/service-engine/api/vendors/index.php:31`.

Confidence: High.

---

### 1.4 Admin frontend/backend contract drift

WHERE: admin config endpoints versus admin router.

WHY: multiple UI actions target routes not implemented by backend.

ROOT CAUSE: frontend endpoint constants evolved ahead of PHP admin router.

CONNECTED SYSTEMS: products, services, system config, emergency stop, delivery detail, refunds.

OPERATIONAL IMPACT: operators can see controls that silently fail or show fake fallback data.

SCALE IMPACT: increases support load and operational distrust.

MAINTAINABILITY IMPACT: route drift makes safe admin evolution difficult.

FUTURE RISK: critical emergency/recovery controls unavailable during incidents.

Evidence: `wtg  live/admin/config.js:36`, `wtg  live/admin/config.js:49`, `wtg  live/admin/config.js:50`, `wtg  live/admin/refunds.html:370`, `wtg  live/heart/api/admin/index.php:857`.

Confidence: High.

---

### 1.5 Auditability can be weakened by admin itself

WHERE: log purge endpoint.

WHY: admins can delete old log files.

ROOT CAUSE: logs are operational data, not immutable audit records.

CONNECTED SYSTEMS: Logger, logs page, admin actions.

OPERATIONAL IMPACT: action history can disappear.

SCALE IMPACT: compliance/incident review becomes weaker.

MAINTAINABILITY IMPACT: debugging production issues loses context.

FUTURE RISK: malicious or mistaken admin actions become harder to investigate.

Evidence: `wtg  live/heart/api/admin/index.php:588`, `wtg  live/heart/api/admin/index.php:596`.

Confidence: High.

---

## 2. Operational Chaos Risks

| Risk | Severity | Evidence | Summary |
|---|---:|---|---|
| Mixed schema vocabulary | High | `wtg  live/heart/api/admin/index.php:669`, `wtg  live/heart/api/admin/index.php:681`, `wtg  live/database-structure.sql:535` | Admin routes reference `customer_id/rider_id/payments/merchants` while current schema uses `user_id/driver_id/transactions/vendors` |
| Refund UI stub fallback | High | `wtg  live/admin/refunds.html:370` | Finance page can display generated fake data when API fails |
| Delivery manual assign mismatch | Medium-high | `wtg  live/admin/delivery.html:159`, `wtg  live/heart/api/admin/index.php:555` | UI sends camelCase/agent naming; backend validates snake_case/driver naming |
| System emergency placeholder | High | `wtg  live/admin/system.html:257`, `wtg  live/heart/api/admin/index.php:900` | Emergency stop UI lacks traced backend route |
| Vendor status enforcement inconsistent | High | `wtg  live/heart/api/admin/index.php:529`, `wtg  live/body/service-engine/api/vendors/index.php:31` | `inactive` is not consistently treated as blocked |

---

## 3. Mandatory Uncertainty Report

| Area | Uncertainty | Evidence boundary | Confidence |
|---|---|---|---|
| Full admin analytics | `analytics.html` uses direct fetch; backend route set not fully traced here | `wtg  live/admin/analytics.html:308` | Medium |
| Banners/settings pages | Pages use ad hoc wrappers; related backend routes not deeply traced | `wtg  live/admin/banners.html:355`, `wtg  live/admin/settings.html:263` | Medium |
| Real deployed schema | Migrations may align columns beyond base dump | `wtg  live/migrations/2026_05_11_001_auth_vendor_contract_alignment.sql:29` | Medium |
| Payment records table | Admin payments route references `payments`; Phase 04 traced `transactions` as payment/refund table | `wtg  live/heart/api/admin/index.php:780`, `wtg  live/database-structure.sql:755` | Medium-high |
| Admin mobile behavior | CSS not deeply audited; pages use responsive viewport but table/modal UX | `wtg  live/admin/orders.html:4` | Medium |

