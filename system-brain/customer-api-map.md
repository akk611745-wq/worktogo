# Customer API Map

Generated: 2026-05-12T04:54:00Z

---

## 1. Customer Frontend API Calls

| Frontend method | Called from | Backend path/intent | Auth required | Evidence | Confidence |
|---|---|---|---|---|---|
| `sendOtp(phone)` | Login OTP | `/api/auth/otp/send` | No | `wtg  live/app/js/api.js:87`, `wtg  live/app/pages/login.js:346` | High |
| `verifyOtp(phone, otp)` | Login OTP | `/api/auth/otp/verify` | No | `wtg  live/app/js/api.js:92`, `wtg  live/app/pages/login.js:379` | High |
| `emailLogin(email,password)` | Login email | `/api/auth/email/login` | No | `wtg  live/app/js/api.js:97`, `wtg  live/app/pages/login.js:254` | High |
| `emailRegister(payload)` | Register step | `/api/auth/email/register` | No | `wtg  live/app/js/api.js:101`, `wtg  live/app/pages/login.js:270` | High |
| `googleAuth(credential)` | Google button | `/api/auth/google` | No | `wtg  live/app/js/api.js:105`, `wtg  live/app/pages/login.js:295` | High |
| `getProducts()` | Home | Heart intent `shopping:list_products` | Public by frontend; downstream public list | `wtg  live/app/js/api.js:109`, `wtg  live/app/pages/home.js:275` | Medium |
| `getServices()` | Home | Heart intent `service:list_services` | Public by frontend; downstream public list | `wtg  live/app/js/api.js:114`, `wtg  live/app/pages/home.js:270` | Medium |
| `addToCart(payload)` | Order modal | Heart intent `shopping:add_to_cart` | Yes downstream | `wtg  live/app/js/api.js:123`, `wtg  live/app/pages/home.js:164`, `wtg  live/body/shopping-engine/api/cart/CartController.php:30` | Medium |
| `createOrder(payload)` | Order modal | Heart intent `shopping:create_order` | Yes downstream | `wtg  live/app/js/api.js:123`, `wtg  live/app/pages/home.js:174`, `wtg  live/body/shopping-engine/api/orders/OrderController.php:27` | Medium |
| `getOrders()` | Orders page | Heart intent `shopping:list_orders` | Yes downstream | `wtg  live/app/js/api.js:118`, `wtg  live/app/pages/orders.js:55` | Medium |
| `createBooking(payload)` | Booking modal | Heart intent `service:create_booking` | Yes downstream | `wtg  live/app/js/api.js:131`, `wtg  live/app/pages/home.js:230` | Medium |
| `getBookings()` | Bookings page | Heart intent `service:list_bookings` | Yes downstream | `wtg  live/app/js/api.js:126`, `wtg  live/app/pages/bookings.js:55` | Medium |
| `getProfile()` | API module only | Heart intent `user:get_profile` | Unknown; no traced route | `wtg  live/app/js/api.js:135` | Low |

Confidence is Medium for Heart intent calls because frontend calls were traced, but engine dispatch internals were not revalidated in this phase.

---

## 2. Direct Customer-Relevant Backend APIs

| Route | Purpose | Auth | Tables/systems | Evidence |
|---|---|---|---|---|
| `GET /api/products` | Product listing | Public | `products`, `inventory`, `vendors` | `wtg  live/body/shopping-engine/api/products/ProductController.php:24` |
| `GET /api/products/{id}` | Product detail | Public | `products`, `inventory`, `vendors` | `wtg  live/body/shopping-engine/api/products/ProductController.php:35` |
| `GET /api/services` | Service listing | Public | `services`, `vendors`, `categories` | `wtg  live/body/service-engine/api/services/index.php:48` |
| `GET /api/services/{id}` | Service detail | Public | `services`, `vendors`, `categories` | `wtg  live/body/service-engine/api/services/index.php:114` |
| `GET /api/cart` | Load customer cart | Auth | `cart`, `products`, `inventory`, `vendors` | `wtg  live/body/shopping-engine/api/cart/CartController.php:18` |
| `POST /api/cart/add` | Add product to cart | Auth | `cart`, `products`, `inventory` | `wtg  live/body/shopping-engine/api/cart/CartController.php:30` |
| `POST /api/cart/update` | Update quantity | Auth | `cart`, `inventory` | `wtg  live/body/shopping-engine/api/cart/CartController.php:88` |
| `POST /api/cart/remove` | Remove cart item | Auth | `cart` | `wtg  live/body/shopping-engine/api/cart/CartController.php:61` |
| `POST /api/orders` | Create customer order | Customer/admin role | `orders`, `order_items`, `cart`, `inventory`, `products` | `wtg  live/body/shopping-engine/api/orders/OrderController.php:26` |
| `GET /api/orders` | Customer order history | Customer/admin role | `orders` | `wtg  live/body/shopping-engine/api/orders/OrderController.php:69` |
| `GET /api/orders/{id}` | Customer order detail | Customer/admin role | `orders`, sub-orders | `wtg  live/body/shopping-engine/api/orders/OrderController.php:82` |
| `POST /api/orders/{id}/cancel` | Cancel customer order | Customer/admin role | `orders`, `inventory` | `wtg  live/body/shopping-engine/api/orders/OrderController.php:50` |
| `POST /api/service/request` | Create service booking | Auth | `bookings`, `jobs`, `services` | `wtg  live/body/service-engine/api/services/index.php:204` |
| `GET /api/service/bookings` | Customer/vendor/admin booking list | Auth + scoped | `bookings`, `services`, `vendors` | `wtg  live/body/service-engine/api/services/index.php:327` |
| `GET /api/service/bookings/{id}` | Booking detail | Auth + owner/vendor/admin | `bookings`, `jobs` | `wtg  live/body/service-engine/api/services/index.php:368` |
| `POST /api/payment/create-order` | Payment session/COD confirmation | Auth | `orders`, `PaymentService` | `wtg  live/api/payment/create-order.php:3` |
| `GET /api/feed/stories` | Story feed | Optional auth | `stories`, `vendors`, `followers` | `wtg  live/body/content-engine/api/StoryController.php:85` |
| `GET /api/vendors` | Vendor listing | Public | `vendors`, `users` | `wtg  live/body/service-engine/api/vendors/index.php:114` |

---

## 3. Customer-Facing API Gaps

| Gap | Evidence | Confidence |
|---|---|---|
| No customer SPA page for cart management despite cart APIs existing | `wtg  live/app/js/router.js:20`, `wtg  live/body/shopping-engine/api/cart/CartController.php:18` | High |
| No frontend call to payment create-order in traced customer app | `wtg  live/app/js/api.js:85`, `wtg  live/api/payment/create-order.php:3` | High |
| No wishlist/favorites API surfaced; `followers` exists but not customer favorites UI | `wtg  live/database-structure.sql:452`, `wtg  live/app/js/router.js:20` | High |
| `API.getProfile()` calls `user:get_profile`, but no matching router intent was seen in the traced Heart route list | `wtg  live/app/js/api.js:135`, `wtg  live/heart/router.php:195` | Medium |

