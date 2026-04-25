# WorkToGo — Service Engine

Handles services, bookings, and jobs.
Depends on CORE — deploy and run CORE schema first.

## Deployment

1. Upload this folder to the path SERVICE_PATH points to in CORE's router.
2. Run `database/schema_service.sql` **after** running CORE's `schema_final.sql`.

## API Routes (handled by CORE router)

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | /api/services | — | List active services |
| GET | /api/services/{id} | — | Service detail |
| POST | /api/service/request | User JWT | Create booking + auto-create job |
| GET | /api/service/bookings | JWT | List bookings (scoped by role) |
| GET | /api/service/bookings/{id} | JWT | Booking detail + linked job |
| PATCH | /api/jobs/{id}/status | Vendor/Admin JWT | Update job status |
| GET | /api/vendors | — | Paginated vendor list |
| GET | /api/vendors/{id} | — | Vendor detail |
| GET | /api/vendor/jobs | Vendor/Admin JWT | Vendor's own jobs |
| GET | /api/vendor/jobs/{id} | Vendor/Admin JWT | Single job detail |

## Security & Bug Fixes Applied

### 1 — IDOR on `GET /api/service/bookings/{id}`
**Before:** Any authenticated user could read any booking by ID.  
**After:** Regular users can only see their own bookings (`user_id` match). Vendors can only see bookings assigned to their vendor profile. Admins are unrestricted.

### 2 — Vendor ownership not validated on `PATCH /api/jobs/{id}/status`
**Before:** Any vendor could update any job's status.  
**After:** The vendor's `vendors.id` is resolved from the JWT `user_id` and compared to `jobs.vendor_id`. Mismatches return 403.

### 3 — `jobs.job_number` NOT NULL crash on booking creation
**Before:** Booking creation did not create a job; any external job insert without `job_number` would crash.  
**After:** Booking creation opens a DB transaction and atomically inserts both the booking and its linked job, both with application-generated unique reference numbers (`bin2hex(random_bytes(4))`). Transaction rolls back cleanly on any failure.

### 4 — `vendor_id` vs `user_id` mismatch
**Before:** Vendor-scoped queries (job list, job detail, booking list, status patch) used the JWT's `user_id` directly as `vendor_id`, returning wrong or empty results for every vendor.  
**After:** A `resolveVendorId()` helper looks up `vendors.id WHERE user_id = :jwt_user_id`, used consistently across all vendor-scoped paths.

### 5 — Missing input validation on booking creation
**Before:** `scheduled_at` was passed to the DB unvalidated; a non-datetime value would silently insert `0000-00-00`.  
**After:** `strtotime()` validates the value and rejects past timestamps. The value is normalised to `Y-m-d H:i:s` before insert.

**Before:** `address_id` accepted any integer, enabling IDOR against other users' addresses.  
**After:** Address ownership is verified (`addresses WHERE id = ? AND user_id = ?`) before use.

### 6 — Dead / broken count query in `GET /api/vendors`
**Before:** A broken ternary expression ran the COUNT query twice (once as a dead side-effect, once correctly).  
**After:** Single clean `$countStmt` block; dead code removed.

### 7 — PII leak in `GET /api/vendors/{id}`
**Before:** `u.email AS owner_email` was exposed on a public endpoint.  
**After:** Owner email removed from the public vendor detail response.
