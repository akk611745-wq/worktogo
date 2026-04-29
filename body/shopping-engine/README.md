# WorkToGo — Shopping Engine  (Production v3.0)

> **Status: Production-ready.** All critical security and correctness issues from the v2.0 audit have been resolved.

---

## What was fixed in v3.0

| # | Issue | Fix |
|---|-------|-----|
| 1 | **Payment fraud** — Razorpay/UPI orders created with no verification | Only `cod` accepted. All other methods hard-rejected with HTTP 422. |
| 2 | **Cart duplicate race** — concurrent add-to-cart could create duplicate rows | `UNIQUE KEY uq_cart_user_product (user_id, product_id)` added to schema. `add()` uses `INSERT … ON DUPLICATE KEY UPDATE quantity = LEAST(quantity + ?, 50)`. |
| 3 | **Deleted/inactive product checkout** — `getInventoryForUpdate()` only queried `inventory`, not `products` | Query now JOINs `products` and requires `status = 'active' AND deleted_at IS NULL` inside the transaction. |
| 4 | **No rate limiting** — COD + no throttle = free inventory lockout attack | `checkRateLimit()` in `OrderService` enforces max 5 parent orders per user per 60 seconds (DB-based, no Redis required). HTTP 429 returned when exceeded. |
| 5 | **Negative price on update** | `ProductController::update()` now validates `price >= 0`. |
| 6 | **Vendor phone PII exposure** | `v_phone` removed from `ProductService::detail()` SELECT and response. |
| 7 | **Notes field unbounded** | Notes truncated to 500 characters in `OrderService::create()`. |
| 8 | **Missing indexes** | Added `idx_products_status_deleted (status, deleted_at)` and `idx_orders_created_at (created_at)`. |

---

## Installation

### Fresh install

```bash
# 1. Run core schema first (creates users, vendors, categories, addresses)
mysql -u root -p your_db < /path/to/core/schema_final.sql

# 2. Run shopping engine schema
mysql -u root -p your_db < database/schema_shopping.sql

# 3. Deploy to your web root
cp -r . /var/www/html/shopping-engine
```

### Upgrade from v2.0

```sql
-- Step 1: remove any existing duplicate cart rows
DELETE c1 FROM cart c1
INNER JOIN cart c2
  ON c1.user_id = c2.user_id
  AND c1.product_id = c2.product_id
  AND c1.id > c2.id;

-- Step 2: add the unique constraint
ALTER TABLE cart
  ADD UNIQUE KEY uq_cart_user_product (user_id, product_id);

-- Step 3: add performance indexes
ALTER TABLE products
  ADD INDEX idx_products_status_deleted (status, deleted_at);

ALTER TABLE orders
  ADD INDEX idx_orders_created_at (created_at);
```

---

## Deploy path

Upload the contents of this ZIP to:

```
/body/shopping-engine/
  api/
    cart/
    orders/
    products/
  database/
  helpers/
  README.md
```

The core system must define `CORE_PATH` and `$db` (PDO) before including any shopping engine files.

---

## API reference

### Products

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/api/products` | — | List active products (filter, search, sort, paginate) |
| GET | `/api/products/{id}` | — | Product detail |
| GET | `/api/products/categories` | — | All active categories |
| POST | `/api/products` | Vendor | Create product |
| PUT | `/api/products/{id}` | Vendor | Update own product |
| DELETE | `/api/products/{id}` | Vendor | Soft-delete own product |
| GET | `/api/vendor/products` | Vendor | List own products |

### Cart

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/api/cart` | User | View cart |
| POST | `/api/cart/add` | User | Add item `{ product_id, quantity? }` |
| POST | `/api/cart/update` | User | Update quantity `{ product_id, quantity }` — `0` removes |
| POST | `/api/cart/remove` | User | Remove item `{ product_id }` or `{ cart_item_id }` |

### Orders

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/api/orders` | User | Checkout — COD only |
| GET | `/api/orders` | User | Order history |
| GET | `/api/orders/{id}` | User | Order detail |
| POST | `/api/orders/{id}/cancel` | User | Cancel pending/confirmed order |
| GET | `/api/vendor/orders` | Vendor | Own sub-orders |
| PUT | `/api/vendor/orders/{id}/status` | Vendor | Advance order status |

### Checkout payload

```json
{
  "payment_method": "cod",
  "shipping_address": {
    "name": "Rahul Verma",
    "phone": "9876543210",
    "line1": "12 MG Road",
    "city": "Bangalore",
    "state": "Karnataka",
    "pincode": "560001"
  },
  "notes": "Leave at door"
}
```

---

## Security model

- **Auth**: JWT resolved from `Authorization: Bearer <token>`. All write endpoints require a valid token.
- **Vendor isolation**: `vendor_id` is ALWAYS resolved from the JWT — never accepted from the request body.
- **SQL injection**: 100% parameterised queries. `IN()` clauses use `array_fill` placeholders.
- **Race conditions**: `SELECT … FOR UPDATE` inside `beginTransaction()` for stock checks.
- **Payment**: Only `cod` accepted. No other payment method will be processed.
- **Rate limiting**: Max 5 orders per user per 60 seconds. Returns HTTP 429.

---

## Inventory model

```
available_to_sell = quantity - reserved

checkout    → inventory.reserved  += qty
delivered   → inventory.quantity  -= qty,  inventory.reserved -= qty
cancelled   → inventory.reserved  -= qty
```

Physical stock (`quantity`) is never decremented at checkout time — only at delivery. This ensures accurate available stock visibility without prematurely depleting inventory.

---

## Health check

After deployment, this endpoint (handled by core) should return:

```
GET /api/health
→ { "shopping": "loaded" }
```
