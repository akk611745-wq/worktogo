# Admin + Vendor Mobile Operational Analysis

Generated: 2026-05-12T08:15:00Z

---

## 1. Admin Mobile Reality

Admin pages include mobile viewport metadata, but traced UI patterns are operational tables, sidebars, filters, and modals. This indicates responsive intent but not a dedicated mobile operations workflow.

Evidence: `wtg  live/admin/orders.html:4`, `wtg  live/admin/vendors.html:4`, `wtg  live/admin/delivery.html:4`, `wtg  live/admin/shared/shell.js:54`.

Operational impact: high-risk actions such as vendor approval, order cancellation, and emergency stop are available through modal/table UI that may be error-prone on mobile.

Scale implication: mobile admins may struggle with dense tables, hidden columns, or accidental action taps during incidents.

Confidence: Medium because CSS was not deeply audited in Phase 05.

---

## 2. Vendor Mobile Operational Reality

Vendor panel uses role-specific navigation and local auth guard. Mobile behavior was not deeply traced beyond the shared shell/auth and config. Operational access depends on role in localStorage and token presence.

Evidence: `wtg  live/vendor/shared/shell.js:38`, `wtg  live/vendor/shared/shell.js:82`, `wtg  live/vendor/shared/auth.js:28`, `wtg  live/vendor/shared/auth.js:78`.

Operational impact: after admin approval/restriction, mobile vendor experience depends on relogin and local role state.

Confidence: Medium.

---

## 3. Mobile Operational Risks

| Risk | WHERE | WHY | Impact | Evidence |
|---|---|---|---|---|
| Accidental destructive admin actions | Orders/vendors/products pages | Table actions and modal confirmations on small screens | Wrong vendor/order/product intervention | `wtg  live/admin/orders.html:158`, `wtg  live/admin/vendors.html:163`, `wtg  live/admin/products.html:130` |
| Emergency stop placebo | System page | Mobile-accessible emergency UI without traced backend route | Incident operators may believe halt worked | `wtg  live/admin/system.html:254`, `wtg  live/heart/api/admin/index.php:900` |
| Stale vendor role on mobile | Vendor guard | Local role, no live vendor status recheck | Approved/restricted vendor confusion | `wtg  live/vendor/shared/auth.js:67`, `wtg  live/vendor/shared/auth.js:78` |

