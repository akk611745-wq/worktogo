# WorkToGo — User Panel (Production Ready)

## Quick Start

1. **Set your API URL** — edit `env.js`:
   ```js
   window.WTG_BASE_URL = "https://your-real-api.com";
   ```

2. **Deploy** — serve the folder from any static host (Nginx, Apache, Vercel, Netlify, S3).

3. **No build step required.** Pure HTML/CSS/JS with ES module pages.

---

## API Contract Expected

### Auth
| Method | Endpoint | Body | Response |
|--------|----------|------|----------|
| POST | `/auth/otp/send` | `{ phone }` | `{ message }` |
| POST | `/auth/otp/verify` | `{ phone, otp }` | `{ token, user }` |

`user` object must contain at minimum: `{ id, name, phone, role? }`

### Catalog
| Method | Endpoint | Response |
|--------|----------|----------|
| GET | `/products` | Array of products or `{ products: [...] }` |
| GET | `/services` | Array of services or `{ services: [...] }` |

Product fields: `id, name, price, image?, category?, description?`  
Service fields: `id, name, price, icon?, category?, description?`

### Orders
| Method | Endpoint | Body | Response |
|--------|----------|------|----------|
| GET | `/orders` | — | Array or `{ orders: [...] }` |
| POST | `/orders` | `{ product_id, quantity, notes? }` | Created order |

### Bookings
| Method | Endpoint | Body | Response |
|--------|----------|------|----------|
| GET | `/bookings` | — | Array or `{ bookings: [...] }` |
| POST | `/bookings` | `{ service_id, scheduled_at?, notes? }` | Created booking |

All authenticated endpoints require: `Authorization: Bearer <token>`

---

## Status Values (must match backend)

**Orders:** `pending` → `accepted` → `in_progress` → `completed` / `cancelled`  
**Bookings:** `pending` → `confirmed` → `in_progress` → `completed` / `cancelled`

---

## File Structure

```
worktogo-user-panel/
├── env.js              ← SET YOUR API URL HERE
├── index.html
├── css/
│   └── main.css
├── js/
│   ├── config.js       ← endpoints, status enums, feature flags
│   ├── api.js          ← all HTTP calls (fetch wrapper)
│   ├── auth.js         ← OTP login, JWT session
│   ├── ui.js           ← shared UI helpers, status badges
│   └── router.js       ← SPA hash router + polling
└── pages/
    ├── login.js        ← OTP send + verify flow
    ├── home.js         ← products + services + order/booking modals
    ├── orders.js       ← orders list, tabs, auto-refresh
    ├── bookings.js     ← bookings list, tabs, auto-refresh
    └── account.js      ← profile, navigation
```

---

## What Was Fixed (vs original)

| # | Issue | Fix |
|---|-------|-----|
| 1 | OTP was simulated with `setTimeout` | Real `POST /auth/otp/send` + `POST /auth/otp/verify` |
| 2 | `AUTH.login()` called wrong endpoint | Replaced with `AUTH.sendOtp()` + `AUTH.verifyAndLogin()` |
| 3 | Products showed "coming soon" toast | Real order modal → `POST /orders` |
| 4 | Services showed "coming soon" toast | Real booking modal → `POST /bookings` |
| 5 | Order status used `delivered` (wrong) | Fixed to `completed`, `in_progress`, `accepted` |
| 6 | Booking status used `scheduled/ongoing` | Fixed to `pending`, `confirmed`, `in_progress` |
| 7 | `BASE_URL` was a placeholder string | `env.js` with `window.WTG_BASE_URL` |
| 8 | API errors showed raw status codes | Extracts `message`/`error` from response body |
| 9 | No `env.js` for clean URL config | Added `env.js`, loaded first in `index.html` |
| 10 | Modal CSS missing | Full modal sheet styles added to `main.css` |
