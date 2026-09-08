# WTG System Audit 02 — Database Schema Map (READ-ONLY)

> **Purpose:** A reference map of the real `u904615152_worktogo_main` schema so it doesn't need to be re-queried from prod.
> **Source of truth:** `u904615152_worktogo_main (4).sql` (full prod dump, dated Jun 2026) cross-checked against `wtg  live/database-structure.sql` and `wtg  live/migrations/*` (28 migrations, latest 2026-06-22). **No live DB connection was made** — read from committed schema + code only.
> **Engine:** MariaDB/MySQL, InnoDB, `utf8mb4_unicode_ci`. JSON columns use `longtext … CHECK (json_valid())`.
> **Date:** 2026-07-03 · **READ-ONLY** — nothing modified.

---

## 0. Table inventory (49 tables)

Grouped by domain. "Code refs" = approx. count of `.php`/`.js` references in `wtg  live/` (substring/word match) — a rough liveness signal.

### Identity & Auth
| Table | Key columns | Code refs |
|---|---|---|
| `users` | id, uuid, name, phone*, email*, password, **role** enum(customer/vendor_service/vendor_shopping/admin/delivery), status, google_id, auth_type enum(phone/email/google/guest), is_guest, guest_expires_at, avatar_url, address, deleted_at | 101 |
| `vendors` | id, **user_id**, business_name, slug, type enum(service/shopping), status, commission_rate, rating, is_online, lat, lng, service_localities, service_area_notes, description, logo_url, **category_id int(11)**, is_verified, experience_years, address_id | 222 |
| `refresh_tokens` | id, user_id, token_hash, expires_at | 7 |
| `token_blacklist` | id, token_hash, expires_at | 2 |
| `otps` | id, phone, otp_hash, attempts, used, expires_at | 9 |
| `rate_limits` | key(PK), action, ip_address, attempts, expires_at | 4 |
| `fcm_tokens` | id, user_id, token, device_type | 4 |

### Catalog
| Table | Key columns | Code refs |
|---|---|---|
| `categories` | id, uuid, parent_id, name, slug, type enum(service/product/both), **status** enum, **is_active** tinyint, module, image_url, sort_order, **commission_rate**, **commission_pct**, **inspection_price** | 88 |
| `services` | id, **vendor_id**, **category_id**, name, slug, base_price, status enum(draft/active/inactive), is_featured, rating, duration_minutes, deleted_at | 197 |
| `products` | id, uuid, vendor_id, category_id, name, price, sale_price, **images**(json), **images_json**(text), attributes, status, rating, total_sold, deleted_at | 125 |
| `product_variations` | id, product_id, sku, attributes(json), price, stock_quantity | 8 |
| `vendor_categories` | **vendor_id, category_id** (junction only, no id/PK surrogate) | 5 |
| `vendor_availability_slots` | id, vendor_id, day_of_week, start_time, end_time, max_bookings | 7 |

### Service bookings (the live pilot core)
| Table | Key columns | Code refs |
|---|---|---|
| `bookings` | id, booking_number, client_request_id, **user_id, vendor_id, service_id, category_id, address_id**, status enum(pending/confirmed/in_progress/completed/cancelled), payment_status, payment_method, **booking_mode**, **lifecycle_state**, **assignment_state**, **vendor_response_status**, **vendor_route**, total, scheduled_at, operational_timeline(json), customer_name, customer_mobile, customer_locality, customer_address | 223 |
| `jobs` | id, job_number, **booking_id**, vendor_id, user_id, title, status enum(open/assigned/in_progress/completed/cancelled), priority, assignment_lock_time, started_at, completed_at | 84 |
| `auto_assignments` | id, entity_type enum(order/job), entity_id, vendor_id, status enum(pending/accepted/rejected/timeout) | 11 |
| `booking_slot_reservations` | id, booking_id, vendor_id, slot_date, slot_time, status | 2 |

### Shopping / delivery (⚠ not analyzed per Phase-1 scope — pilot-hidden)
| Table | Key columns | Code refs |
|---|---|---|
| `orders` | id, uuid, order_number, parent_order_id, user_id, vendor_id, delivery_id, status(7-state), ledger_status, payment_status, subtotal…total, shipping_address(json), meta(json) | 226 |
| `order_items` | id, order_id, product_id, vendor_id, product_name(snapshot), quantity, unit_price, line_total | 9 |
| `cart` | id, user_id, session_id, product_id, quantity, unit_price, variation_id, expires_at | 60 |
| `deliveries` | id, order_id, driver_id, status enum(pending/assigned/picked/delivered/cancelled) | 22 |
| `inventory` | id, product_id, warehouse_id, quantity, reserved, low_stock_alert | 21 |
| `warehouses` | id, vendor_id, name, address_id, meta(json) | **0** |

### Money
| Table | Key columns | Code refs |
|---|---|---|
| `transactions` | id, user_id, reference_type enum(order/booking), reference_id, gateway, gateway_ref, amount, status, refund_status enum, refund_amount, refund_gateway_id | 36 |
| `wallet_transactions` | id, entity_type enum(vendor/driver/platform), entity_id, order_id, type enum(credit/debit), amount, status | 14 |
| `vendor_wallets` | id, vendor_id, available_balance, pending_balance | 8 |
| `driver_wallets` | id, driver_id, cash_in_hand, earnings, collection_limit | 9 |

### Content / social (content + video engines)
| Table | Key columns | Code refs |
|---|---|---|
| `stories` | id, vendor_id, media_url, media_type, caption, expires_at | 22 |
| `followers` | id, follower_user_id, target_id, target_type enum(vendor/store/creator) | 5 |
| `cms_banners` | id, title, image_url, link_url, position enum, sort_order, is_active | **0** |

### Notifications & alerts
| Table | Key columns | Code refs |
|---|---|---|
| `notifications` | id, **user_id int(10)**, title, body, type, is_read | 51 |
| `alerts` | alert_id, user_id, vendor_id, type(8-enum), title, message, ref_type, ref_id, channel_push/sms/wa, seen_at | 72 |

### Settings & ops
| Table | Key columns | Code refs |
|---|---|---|
| `platform_settings` | id, **key**, value, type, label | 3 |
| `app_settings` | id, **setting_key**, setting_value, value_type, is_public | 15 |
| `addresses` | id, user_id, label, line1, line2, city, state, postal_code, country, is_default | 3 |
| `logs` | id, level, message, context(json) | 29 |
| `heart_request_log` | id, request_id, intent, user_id | **0** |
| `migrations_log` | id, migration_file, applied_at | 4 |

### Business Brain (self-contained subsystem, `brain/`)
| Table | Purpose | Code refs |
|---|---|---|
| `brain_clients` | id(varchar), api_key, is_active | 5 |
| `brain_rules` | client_id, trigger_exp, condition_exp, is_active | 11 |
| `brain_decisions` | client_id, rule_id, input/output_data(json) | 13 |
| `brain_actions` | decision_id, client_id, action_type, action_data(json) | 16 |
| `brain_events` | client_id, event_type, category, location, payload(json), processed | 38 |
| `brain_feedback` | decision_id, action_id, user_response | 3 |
| `brain_habits` | client_id, location, category, time_of_day, score | 7 |
| `brain_suggestions` | client_id, suggestion_type, suggestion_data(json), status | 11 |
| `brain_improvements` | client_id, event_data(json), fingerprint, status | 13 |
| `brain_user_profiles` | user_id(varchar), client_id, top_category, score_data(json) | 6 |
| `brain_alerts` | client_id, type, severity, message, context_json, status | 20 |
| `brain_rate_limits` | client_id, endpoint, ip_address, hit_count, window_start | 3 |

---

## 1. Foreign-key relationship map

All FKs are declared in the dump (InnoDB). `users` and `vendors` are the two hubs.

```
users (hub)
 ├─< addresses.user_id            (CASCADE)
 ├─< vendors.user_id              (CASCADE)
 ├─< refresh_tokens.user_id       (CASCADE)
 ├─< fcm_tokens.user_id           (CASCADE)
 ├─< followers.follower_user_id   (CASCADE)
 ├─< driver_wallets.driver_id     (CASCADE)
 ├─< deliveries.driver_id         (SET NULL)
 ├─< bookings.user_id / jobs.user_id / orders.user_id / transactions.user_id
 └─< alerts.user_id               (CASCADE)

vendors (hub)
 ├─< services.vendor_id           (CASCADE)
 ├─< products.vendor_id           (CASCADE)
 ├─< bookings.vendor_id / jobs.vendor_id / orders.vendor_id / order_items.vendor_id
 ├─< stories.vendor_id            (CASCADE)
 ├─< warehouses.vendor_id         (CASCADE)
 ├─< vendor_availability_slots.vendor_id (CASCADE)
 ├─< vendor_categories.vendor_id  (CASCADE)
 ├─< vendor_wallets.vendor_id     (CASCADE)
 ├─< booking_slot_reservations.vendor_id (CASCADE)
 └─< alerts.vendor_id             (CASCADE)

categories
 ├─< categories.parent_id (self, SET NULL)
 ├─< services.category_id         (SET NULL)
 ├─< products.category_id         (SET NULL)
 ├─< bookings.category_id         (SET NULL)
 └─< vendor_categories.category_id (CASCADE)

bookings ──< jobs.booking_id (SET NULL) ; ──< booking_slot_reservations.booking_id (CASCADE)
orders   ──< order_items.order_id (CASCADE) ; ──< deliveries.order_id (CASCADE) ; ──< orders.parent_order_id (self, CASCADE)
products ──< inventory.product_id (CASCADE) ; ──< product_variations.product_id (CASCADE) ; ──< cart.product_id (CASCADE)
warehouses ──< inventory.warehouse_id (SET NULL) ; addresses ──< warehouses.address_id (SET NULL)
```

**No-FK tables (loose / app-enforced only):** `notifications`, `transactions.reference_id` (polymorphic order/booking), `wallet_transactions` (polymorphic entity), `auto_assignments` (polymorphic entity), `vendors.category_id`, `vendors.address_id`, `bookings.payment_id`, all `brain_*`, `app_settings`, `platform_settings`, `otps`, `rate_limits`, `logs`, `heart_request_log`, `cms_banners`, `stories`, `followers.target_id`, `token_blacklist`. Polymorphic/loose FKs mean referential integrity for payments, wallets, and auto-assignment is **enforced in PHP, not the DB.**

---

## 2. Orphaned / unused tables (flagged, not removed)

| Table | Signal | Verdict |
|---|---|---|
| `cms_banners` | **0 code refs.** Banner uploads go through `POST /categories/{id}/banner` → `UploadHelper::save(..,'categories')` and write to **categories**, not `cms_banners`. | 🔴 **Orphaned** — superseded by the category-banner system. |
| `heart_request_log` | **0 code refs.** Intended idempotency/request log for the intent pipeline; never wired. | 🔴 **Orphaned** — dead scaffold. |
| `warehouses` | **0 code refs** in live code. Shopping/inventory infra. | 🟠 Orphaned for the service pilot (shopping-engine dormant). |
| `inventory`, `product_variations`, `cart`, `order_items`, `deliveries`, `orders`, `products` | Shopping/delivery stack | 🟠 Present & referenced but **dormant** — hidden across the app by `SERVICE_ONLY_MODE`. Not orphaned, just inactive. |
| `stories`, `followers` | content/video engines | 🟠 Dormant (video-engine not in pilot). |
| `booking_slot_reservations` | Only 2 refs (written by `SlotController`). | 🟡 Barely used; availability/slot feature is thin. |
| `brain_*` (12 tables) | Referenced by `brain/` only; Brain is wired into the pipeline but leans on fallbacks (see Audit 01). | 🟡 Live-but-underused subsystem. |

---

## 3. Dead / duplicate / confusing columns

**Duplicate columns doing the same job (schema drift from repeated migrations):**
- `categories.commission_rate` **and** `categories.commission_pct` — two commission fields (admin category edit writes `commission_pct`, default 10.00; `commission_rate` is the older nullable one). 🔴
- `categories.status` (enum active/inactive) **and** `categories.is_active` (tinyint) — two "is this category on?" flags that can disagree. 🔴
- `products.images` (json) **and** `products.images_json` (text) — two image stores on the same row. 🔴
- `platform_settings` **and** `app_settings` — **two settings tables** with different column names (`key/value` vs `setting_key/setting_value`). `cron/auto_assign.php` reads `platform_settings`; public settings read `app_settings`. 🔴 Split-brain config.
- `rate_limits` **and** `brain_rate_limits` — separate limiter tables for app vs brain (intentional isolation, noted).

**Columns referenced in code but with weak/no UI mapping:**
- `vendors.service_area_notes`, `vendors.experience_years`, `vendors.logo_url` — exist and occasionally read, but the vendor profile UI (Audit 01 §3) has no real editor for bio/experience/logo (photo is URL-paste only). Effectively backend-only. 🟡
- `users.avatar_url` — account UI renders initials, never an avatar image. 🟡
- `bookings` parallel state columns: `status` **plus** `booking_mode`, `lifecycle_state`, `assignment_state`, `vendor_response_status`, `vendor_route`. Multiple overlapping state machines on one row — high confusion risk; `status` is the canonical one the UI reads, the others are ops/assignment metadata of varying liveness. 🟠
- `bookings.duration_minutes` / `services.duration_minutes` — carried but not surfaced in the customer flow.
- `orders.ledger_status`, `orders.order_ref`, `orders.parent_order_id` — shopping ledger fields, dormant with the shopping engine.

---

## 4. Known inconsistencies (confirmed from code)

### 4a. Vendor ↔ Category is stored THREE different ways ⚠ (the vendor-category display bug)
There is no single source of truth for "which categories does this vendor serve":
1. **`services.category_id` + `services.vendor_id`** — the *effective* link the resolver uses. Vendor eligibility queries do `SELECT vendor_id FROM services WHERE category_id = ?` (`heart/api/admin/index.php:678,751`; `body/service-engine/...`). A vendor only "belongs" to a category if they have a `services` row in it.
2. **`vendors.category_id`** — a single `int(11)` on the vendor row, set by admin (`UPDATE vendors SET category_id=? …` line 1175) and read for the eligibility map (lines 1698/1737). **Type mismatch:** `int(11)` vs `categories.id` `bigint(20) UNSIGNED`, and **no FK** — an unconstrained loose pointer.
3. **`vendor_categories` junction** (migration 2026_06_15) — the "correct" many-to-many. But it is **dual-written as a mirror only** (`body/service-engine/api/vendors/index.php:421-422` deletes+inserts to keep it in sync) and **barely read** — the code comment itself notes it "is only kept [in sync]." Resolution still leans on #1/#2.

Result: a vendor can appear in one representation and not the others → the display/assignment bug where a vendor shows the wrong (or no) category. **Any fix must pick one canonical path.**

### 4b. `jobs` needs `job_id`, but code falls back to `booking_id`
`bookings` and `jobs` are separate tables (`jobs.booking_id` is a nullable `SET NULL` FK). A `jobs` row is only created by the vendor accept/reject flow, so **not every booking has a job.** The admin endpoint `PATCH /api/admin/jobs/{id}/status` explicitly handles this ambiguity (`heart/api/admin/index.php:1881-1886`): *"Try jobs table first; if absent, treat job_id as booking id"* — it updates `jobs` by id, else falls back to resolving `booking_id`. So callers conflate the two ID spaces, and status can live on either `bookings.status` or `jobs.status`. Canonical booking status = `bookings.status`; `jobs.status` is a parallel copy for assigned work.

### 4c. Other integrity notes
- **`notifications.user_id` is `int(10) UNSIGNED`** while `users.id` is `bigint(20) UNSIGNED` — width mismatch, no FK. Large user IDs could mis-map.
- **`users.phone` UNIQUE** has a long tail of prod crashes (`logs` table shows dozens of *"Duplicate entry for key 'phone'"* / *"phone cannot be null"* exceptions from `AuthController.php`). Google/guest signups rely on `phone = NULL` to dodge the UNIQUE constraint; empty-string `''` collides. This is a live, recurring failure class.
- **Payments/wallets/auto-assignment use polymorphic references with no FK** (`transactions.reference_type+reference_id`, `wallet_transactions.entity_*`, `auto_assignments.entity_*`) — integrity is code-enforced only.

---

## 5. Not analyzed (per scope)
Shopping-engine and video-engine tables (`orders`, `order_items`, `cart`, `inventory`, `product_variations`, `warehouses`, `deliveries`, `stories`, `followers`) are inventoried above for completeness but **not deep-analyzed** — they are dormant under `SERVICE_ONLY_MODE`.

---
*Read-only schema map built from the committed SQL dump + migrations + code grep. No live database was queried; no files modified.*
