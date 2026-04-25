# WorkToGo Admin Panel — Integration Report

## 1. APIs Used & Endpoints Assumed

All endpoints are relative to: `CONFIG.API_BASE_URL + CONFIG.ADMIN_PREFIX`
Default: `https://api.worktogo.com/v1/admin`

---

### Auth
| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/auth/login` | Admin login → returns `{ token, refreshToken, admin }` |
| POST | `/auth/logout` | Invalidate session |
| GET  | `/auth/me` | Get current admin profile |

### Dashboard
| Method | Endpoint | Expected Response |
|--------|----------|-------------------|
| GET | `/dashboard/stats` | `{ stats: { totalUsers, totalVendors, totalOrders, todayRevenue, activeDeliveries, pendingVendors } }` |

### Users
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET    | `/users?page=&limit=&search=&status=` | List users |
| GET    | `/users/:id` | User detail |
| POST   | `/users/:id/block` | Block user |
| POST   | `/users/:id/unblock` | Unblock user |

### Vendors
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET    | `/vendors?page=&limit=&search=&type=&status=` | List vendors |
| GET    | `/vendors/:id` | Vendor detail |
| POST   | `/vendors/:id/approve` | Approve vendor |
| POST   | `/vendors/:id/reject` | Reject with `{ reason }` |
| PATCH  | `/vendors/:id/status` | `{ status: "enabled"|"disabled" }` |

### Products
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET    | `/products?page=&limit=&search=&category=&status=` | List products |
| PATCH  | `/products/:id/status` | `{ status: "active"|"disabled" }` |
| DELETE | `/products/:id` | Delete product |

### Services
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET    | `/services?page=&limit=&search=&status=` | List services |
| PATCH  | `/services/:id/status` | `{ status: "enabled"|"disabled" }` |

### Orders
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET    | `/orders?page=&limit=&search=&status=&type=&sort=` | List orders |
| GET    | `/orders/:id` | Order detail with items |
| PATCH  | `/orders/:id/status` | `{ status, note }` |
| POST   | `/orders/:id/cancel` | Cancel order |

### Deliveries
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET    | `/deliveries?page=&limit=&search=&status=` | List deliveries |
| POST   | `/deliveries/assign` | `{ orderId, agentId?, notes? }` |
| GET    | `/deliveries/:id` | Delivery tracking detail |

### Payments
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET    | `/payments?page=&limit=&search=&status=&method=` | List payments |
| — | — | Response should include `summary: { paidAmount, pendingAmount, failedCount, totalVolume }` |

### System
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET    | `/system/config` | Fetch all config + toggle states |
| PATCH  | `/system/config` | Update config fields |
| POST   | `/system/toggle` | `{ key: string, enabled: boolean }` |
| POST   | `/system/emergency-stop` | Kill switch |

### Logs
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET    | `/logs?page=&limit=&level=&from=&to=&sort=` | Full log history |
| GET    | `/logs/activity?limit=&search=` | User activity feed |

---

## 2. Expected Response Shape (Standard)

All list endpoints should return:
```json
{
  "data": [...],      // OR named key e.g. "users", "orders"
  "total": 1240,
  "page": 1,
  "limit": 25
}
```

All action endpoints should return:
```json
{ "success": true, "message": "..." }
```

Auth login should return:
```json
{
  "token": "eyJ...",
  "refreshToken": "...",
  "admin": { "id": "...", "name": "...", "email": "...", "role": "super_admin" }
}
```

---

## 3. Missing Backend Requirements

These endpoints/fields **must exist** for full panel functionality:

| # | Requirement | Priority |
|---|-------------|----------|
| 1 | `GET /dashboard/stats` must return all 6 stat fields | Critical |
| 2 | Vendor listing must include `pendingCount` in response | High |
| 3 | Order detail must include `items[]` array | High |
| 4 | Payment listing must include `summary{}` object | Medium |
| 5 | `GET /system/config` must return feature flags + engine status object | High |
| 6 | `POST /system/toggle` must accept any string key dynamically | Medium |
| 7 | Logs must include `level`, `module`, `message`, `userId`, `createdAt` fields | Medium |
| 8 | All listing endpoints must support `page`, `limit`, `sort` query params | Critical |
| 9 | All user/vendor/order detail endpoints must exist | High |
| 10 | JWT must be accepted via `Authorization: Bearer <token>` header | Critical |

---

## 4. Integration Steps

### Step 1 — Configure Base URL
Edit `config.js`:
```js
API_BASE_URL: "https://your-actual-api.com/v1",
ADMIN_PREFIX: "/admin",   // or "" if no prefix
```

### Step 2 — Deploy the Panel
```
# Option A: Serve as static files
nginx / Apache — point root to /admin/

# Option B: Serve from Node.js
app.use('/admin', express.static('./admin-panel'));

# Option C: Any static host (Netlify, S3, Vercel)
Upload the entire /admin/ folder
```

### Step 3 — CORS Setup (Backend)
Your backend must allow:
```
Origin: https://your-admin-domain.com
Methods: GET, POST, PUT, PATCH, DELETE
Headers: Content-Type, Authorization
```

### Step 4 — Admin Role Guard (Backend)
Your `/auth/login` endpoint must:
- Accept `{ email, password }`
- Validate the user has `role: "admin"` or `role: "super_admin"`
- Return `401` for non-admins (panel will redirect to login)

### Step 5 — Test Connectivity
Open browser console on `dashboard.html` and check:
```js
API.get('/dashboard/stats').then(console.log)
```

### Step 6 — Optional: Custom Domain
If running on a different domain than API, ensure your backend returns:
```
Access-Control-Allow-Credentials: true
```
And set `credentials: 'include'` in the fetch options in `shared/api.js`.

---

## 5. File Structure
```
/admin/
  index.html          ← Login page
  dashboard.html      ← Stats + recent activity
  users.html          ← User management
  vendors.html        ← Vendor approve/reject/enable
  products.html       ← Product disable/delete
  services.html       ← Service enable/disable
  orders.html         ← Order status + cancel
  delivery.html       ← Delivery assign + track
  payments.html       ← Payment monitor
  system.html         ← Toggle switches + kill switch
  logs.html           ← Live logs + history
  config.js           ← ⚙️ THE ONLY FILE YOU EDIT
  /assets/
    style.css         ← All styles (dark industrial theme)
    app.js            ← Shared utilities (Toast, Modal, Fmt, etc.)
  /shared/
    api.js            ← HTTP client (fetch wrapper)
    auth.js           ← JWT session management
    shell.js          ← Sidebar + topbar renderer
```

---

*Generated for WorkToGo Admin Panel v1.0.0*
