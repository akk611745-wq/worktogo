# Customer Mobile Analysis

Generated: 2026-05-12T04:54:00Z

---

## 1. Mobile-Oriented Behaviors Traced

| Behavior | Evidence | Confidence |
|---|---|---|
| Bottom navigation optimized for app-like mobile flow | `wtg  live/app/js/ui.js:111` | High |
| OTP phone input uses numeric/tel input modes | `wtg  live/app/pages/login.js:38` | High |
| OTP entry uses six single-digit inputs with paste support | `wtg  live/app/pages/login.js:107`, `wtg  live/app/pages/login.js:177` | High |
| Service cards use horizontal scroll class | `wtg  live/app/pages/home.js:31` | High |
| Product images use lazy loading | `wtg  live/app/pages/home.js:328` | High |
| Booking uses native `datetime-local` input | `wtg  live/app/pages/home.js:202` | High |
| Polling pauses if document hidden | `wtg  live/app/js/router.js:113` | High |

---

## 2. Mobile Pain Points

### Manual address entry
WHERE: Order modal asks for free-text delivery address.

WHY: No saved address picker or structured address UI is used.

ROOT CAUSE: Frontend checkout sends `shipping_address` string rather than using `addresses` table.

CONNECTED SYSTEMS: Home modal, OrderService, `orders.shipping_address`, `addresses` table.

USER IMPACT: Customers retype address each order.

MOBILE IMPACT: Long text entry on phone increases abandonment and typo risk.

SCALE IMPACT: Unstructured addresses complicate delivery automation.

FUTURE RISK: Harder to add geocoding, saved addresses, delivery zones, and fraud checks.

Evidence: `wtg  live/app/pages/home.js:133`, `wtg  live/body/shopping-engine/api/orders/OrderService.php:89`, `wtg  live/database-structure.sql:30`.

Confidence: High.

### No visible cart review screen
WHERE: Customer routes include no `cart` page; order modal performs add-to-cart then create-order.

WHY: Checkout is designed as immediate modal flow.

ROOT CAUSE: UI does not expose cart lifecycle despite backend cart persistence.

CONNECTED SYSTEMS: Home modal, CartService, OrderService.

USER IMPACT: Hidden existing cart items may be included in order.

MOBILE IMPACT: Fast flow can become confusing if prior cart contents exist.

SCALE IMPACT: Support tickets may rise from unexpected multi-item orders.

FUTURE RISK: Adding cart page later must reconcile existing hidden cart behavior.

Evidence: `wtg  live/app/js/router.js:20`, `wtg  live/app/pages/home.js:164`, `wtg  live/body/shopping-engine/api/orders/OrderService.php:111`.

Confidence: High.

### Limited product/service details
WHERE: Product/service cards open action modals directly; no routed detail pages.

WHY: SPA page registry lacks product/service detail modules.

ROOT CAUSE: Current customer app is minimal and card-action oriented.

CONNECTED SYSTEMS: Home, product/service APIs.

USER IMPACT: Less information before purchase/booking.

MOBILE IMPACT: Quick modal is efficient but may reduce trust on small screens.

SCALE IMPACT: Conversion for complex products/services may suffer.

FUTURE RISK: Detail pages must be added without breaking modal quick action.

Evidence: `wtg  live/app/js/router.js:20`, `wtg  live/app/pages/home.js:298`, `wtg  live/app/pages/home.js:325`.

Confidence: High.

---

## 3. Mobile Uncertainty Zones

- Actual CSS responsiveness was not exhaustively audited in this phase.
- Device-specific behavior of `datetime-local` varies across mobile browsers.
- Mobile push notifications were not traced; notification button is placeholder.
- PWA/offline behavior exists in project-adjacent files only outside target or not tied to current customer flow.

Confidence: Medium.

