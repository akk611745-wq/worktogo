# Customer Dependency Map

Generated: 2026-05-12T04:54:00Z

---

## 1. Customer Flow Dependency Graph

```mermaid
flowchart TD
  CustomerApp[app SPA] --> Config[app/js/config.js]
  CustomerApp --> Router[app/js/router.js]
  CustomerApp --> Auth[app/js/auth.js]
  CustomerApp --> API[app/js/api.js]
  CustomerApp --> UI[app/js/ui.js]
  Router --> Home[pages/home.js]
  Router --> Login[pages/login.js]
  Router --> OrdersPage[pages/orders.js]
  Router --> BookingsPage[pages/bookings.js]
  Router --> Account[pages/account.js]
  API --> AuthAPI[/api/auth/*]
  API --> Heart[Heart intent pipeline]
  Heart --> Shopping[shopping engine]
  Heart --> Service[service engine]
  Shopping --> Cart[(cart)]
  Shopping --> Orders[(orders/order_items)]
  Shopping --> Products[(products/inventory)]
  Service --> Bookings[(bookings/jobs)]
  Service --> Services[(services/vendors)]
  Orders --> Delivery[delivery dispatch]
```

Evidence: `wtg  live/app/js/api.js:6`, `wtg  live/app/js/router.js:18`, `wtg  live/heart/router.php:195`, `wtg  live/body/shopping-engine/api/orders/OrderService.php:308`.

---

## 2. Runtime Dependencies by Journey

| Journey | Frontend dependencies | Backend dependencies | DB dependencies | Confidence |
|---|---|---|---|---|
| Onboarding | Login page, AUTH, API, UI | Auth routes, OTP/SMS/Google/JWT | `users`, `otps`, `refresh_tokens` | High |
| Guest browsing | Router, Home, API, UI | Heart pipeline, product/service list endpoints | `products`, `inventory`, `services`, `vendors` | High |
| Product ordering | Home modal, AUTH, API, UI | CartController, OrderController, OrderService | `cart`, `orders`, `order_items`, `inventory`, `products` | High |
| Service booking | Home modal, AUTH, API, UI | service request endpoint | `bookings`, `jobs`, `services`, `vendors` | High |
| Order tracking | Orders page, Router polling | order list endpoint | `orders` | High |
| Booking tracking | Bookings page, Router polling | booking list endpoint | `bookings`, `services`, `vendors` | High |
| Vendor application | Account page, modal, AUTH token | `POST /api/vendors` | `vendors`, `users` | High |
| Notifications | Placeholder Home button; optional AlertSystem not integrated | alerts fetch/mark seen | `alerts` | Medium |

---

## 3. Customer Couplings

### Customer ↔ Product
Customer product browsing and checkout depend on product active status and inventory availability.

Evidence: `wtg  live/body/shopping-engine/api/products/ProductService.php:36`, `wtg  live/body/shopping-engine/api/orders/OrderService.php:144`.

### Customer ↔ Cart
Order modal uses cart as an intermediate persistence layer, even though customer UI has no cart page.

Evidence: `wtg  live/app/pages/home.js:164`, `wtg  live/body/shopping-engine/api/cart/CartService.php:323`.

### Customer ↔ Order
Order creation updates order, order_items, inventory reservation, product total sold, cart clearing, and delivery dispatch.

Evidence: `wtg  live/body/shopping-engine/api/orders/OrderService.php:165`, `wtg  live/body/shopping-engine/api/orders/OrderService.php:243`, `wtg  live/body/shopping-engine/api/orders/OrderService.php:265`, `wtg  live/body/shopping-engine/api/orders/OrderService.php:300`.

### Customer ↔ Service
Service booking creates both a booking and a job for vendor execution.

Evidence: `wtg  live/body/service-engine/api/services/index.php:257`, `wtg  live/body/service-engine/api/services/index.php:290`.

### Customer ↔ Auth
Protected customer pages rely on localStorage checks, while protected APIs rely on backend JWT middleware.

Evidence: `wtg  live/app/js/auth.js:22`, `wtg  live/heart/middleware/AuthMiddleware.php:13`.

---

## 4. Mandatory Uncertainty Register

| Area | Uncertainty | Evidence boundary | Confidence |
|---|---|---|---|
| Heart intent-to-engine payload details | Frontend intent calls traced, but engine dispatch request transformation not deeply re-traced | `wtg  live/app/js/api.js:81`, `wtg  live/heart/router.php:156` | Medium |
| Customer payment UI | Payment API exists, but no customer SPA payment call traced | `wtg  live/api/payment/create-order.php:3`, `wtg  live/app/js/api.js:85` | High for nonuse in traced SPA |
| Notifications | AlertSystem exists but not integrated in customer app and uses different token key | `wtg  live/assets/js/alerts.js:280` | Medium |
| Wishlist/favorites | `followers` table supports follow-like behavior; customer UI/API not traced | `wtg  live/database-structure.sql:452` | Medium |
| Address management | Address table exists; no customer address API/page traced | `wtg  live/database-structure.sql:30` | Medium |
| Mobile CSS behavior | Structural mobile patterns traced, detailed CSS responsiveness not audited | `wtg  live/app/css/main.css` not deeply analyzed | Medium |

