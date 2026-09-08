# Customer Risk Analysis

Generated: 2026-05-12T04:54:00Z

---

## Risk 1 — Hidden cart can create unexpected checkout contents

WHERE: Home order modal calls `addToCart()` and then `createOrder()`, while OrderService creates order from entire server cart.

WHY: Customer UI does not show/review full cart before checkout.

ROOT CAUSE: Quick-order modal layered over persistent cart backend.

CONNECTED SYSTEMS: Home modal, cart API, order API, `cart`, `orders`, `order_items`.

USER IMPACT: User may order previously added cart items unintentionally.

MOBILE IMPACT: Small-screen quick flow hides cart context.

SCALE IMPACT: Increased refund/cancellation/support load.

FUTURE RISK: Harder to introduce true cart without migrating user expectations.

Evidence: `wtg  live/app/pages/home.js:164`, `wtg  live/app/pages/home.js:174`, `wtg  live/body/shopping-engine/api/orders/OrderService.php:111`.

Confidence: High.

---

## Risk 2 — Order status filter mismatch

WHERE: Orders page active filter uses `pending`, `accepted`, `in_progress`, but backend order statuses use `pending`, `confirmed`, `processing`, `shipped`, `delivered`, `cancelled`, `refunded`.

WHY: UI status model mixes service/job aliases with order enum.

ROOT CAUSE: Shared status terminology diverges between modules.

CONNECTED SYSTEMS: Orders page, UI status badges, orders table, vendor order status transitions.

USER IMPACT: Active orders may disappear from Active tab.

MOBILE IMPACT: Mobile users may assume order is missing or complete.

SCALE IMPACT: Trust/support issues as order volume grows.

FUTURE RISK: More statuses will worsen filtering inconsistencies.

Evidence: `wtg  live/app/pages/orders.js:44`, `wtg  live/database-structure.sql:544`, `wtg  live/app/js/ui.js:31`.

Confidence: High.

---

## Risk 3 — Notifications appear placeholder in customer app

WHERE: Home notification button only shows toast; standalone AlertSystem uses wrong localStorage token key for current app.

WHY: Notification module is not integrated into customer SPA.

ROOT CAUSE: Shared alert module and customer auth storage are misaligned.

CONNECTED SYSTEMS: Home UI, `assets/js/alerts.js`, alerts API, auth storage.

USER IMPACT: Customers may not see order/vendor status updates in-app.

MOBILE IMPACT: Mobile users rely heavily on notifications; lack of working alerts increases uncertainty.

SCALE IMPACT: Polling pages become primary status mechanism, increasing backend load.

FUTURE RISK: Notification rollout may fail unless token key and initialization are aligned.

Evidence: `wtg  live/app/pages/home.js:20`, `wtg  live/assets/js/alerts.js:280`, `wtg  live/app/js/config.js:31`.

Confidence: High.

---

## Risk 4 — Saved address table is unused in customer checkout

WHERE: Checkout collects free-text address; `addresses` table exists.

WHY: No customer address management page/API flow was traced.

ROOT CAUSE: Checkout uses direct `shipping_address` string path.

CONNECTED SYSTEMS: Home modal, OrderService, delivery, payments, `addresses`.

USER IMPACT: Repeated address entry and typo risk.

MOBILE IMPACT: High input friction.

SCALE IMPACT: Delivery routing, fraud checks, and area validation are harder.

FUTURE RISK: Migrating free-text historical addresses to structured addresses is costly.

Evidence: `wtg  live/app/pages/home.js:133`, `wtg  live/body/shopping-engine/api/orders/OrderService.php:72`, `wtg  live/database-structure.sql:30`.

Confidence: High.

---

## Risk 5 — Customer cancel/recovery flow not exposed

WHERE: Backend supports order cancellation, but orders page only lists orders.

WHY: No cancel action traced in customer UI.

ROOT CAUSE: Tracking page is read-only.

CONNECTED SYSTEMS: Orders page, OrderController cancel, inventory reservation release.

USER IMPACT: Customer cannot self-cancel from current SPA.

MOBILE IMPACT: Requires contacting support or vendor outside app.

SCALE IMPACT: Operational support burden rises.

FUTURE RISK: Refund/cancellation policies may be inconsistently applied manually.

Evidence: `wtg  live/app/pages/orders.js:90`, `wtg  live/body/shopping-engine/api/orders/OrderController.php:50`.

Confidence: High.

---

## Risk 6 — Polling load scales linearly

WHERE: Orders/bookings poll every 12 seconds per active tab.

WHY: No websocket/push integration traced in customer app.

ROOT CAUSE: Simple interval-based refresh.

CONNECTED SYSTEMS: Router, orders API, bookings API.

USER IMPACT: Status updates are delayed up to 12 seconds or more on mobile suspension.

MOBILE IMPACT: Battery/network consumption from polling.

SCALE IMPACT: Large active user base increases repeated read traffic.

FUTURE RISK: Needs event-driven updates or backoff before growth.

Evidence: `wtg  live/app/js/router.js:110`, `wtg  live/app/js/config.js:36`.

Confidence: High.

---

## Risk 7 — Search/detail/favorites are partially backend-only or absent

WHERE: Product search/filter/detail APIs exist, but current customer router lacks search/detail/favorites pages.

WHY: Frontend customer journey is minimal.

ROOT CAUSE: API capability exceeds SPA journey implementation.

CONNECTED SYSTEMS: ProductService, services API, followers table, Brain feed.

USER IMPACT: Discovery and repeat purchase behavior are constrained.

MOBILE IMPACT: Customers must browse limited Home cards.

SCALE IMPACT: Lower marketplace engagement and personalization data.

FUTURE RISK: Later discovery features must reconcile with existing Home-centric flow.

Evidence: `wtg  live/body/shopping-engine/api/products/ProductService.php:27`, `wtg  live/app/js/router.js:20`, `wtg  live/database-structure.sql:452`.

Confidence: High.

