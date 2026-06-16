# PHASE 0.2 — Vendor/Category Architecture Implementation Plan

**Prepared:** 2026-06-15  
**Status:** READ-ONLY PLAN — do not execute until reviewed  
**Root cause:** `GET /api/services` returns one row per `services` table row. Vendor 11 (Aakash) has 5 rows → 5 duplicate cards on customer home page.  
**Fix summary:** (1) Create `vendor_categories` junction table, (2) add nullable `category_id` to `bookings`, (3) change the API to group by vendor, (4) update home.js to render one card per vendor, (5) patch admin/vendor UIs.

---

## ORDER OF OPERATIONS

1. **Run migration** `migrations/2026_06_15_001_vendor_categories_junction.sql` (schema + backfill, no code changes yet)
2. **Update** `body/service-engine/api/services/index.php` — booking INSERT (safe; column is nullable so old code still works after migration)
3. **Update** `body/service-engine/api/services/index.php` — `GET /api/services` grouping (changes response shape; do this before frontend)
4. **Update** `app/pages/home.js` — `_renderServices()` grouping (must land same deploy as step 3)
5. **Update** `admin/services.html` — category cell (low urgency; admin table will just show first category until patched)
6. **Test** against checklist below

---

## STEP 1 — SQL MIGRATION

**File to create:** `migrations/2026_06_15_001_vendor_categories_junction.sql`

```sql
-- WorkToGo — Vendor/Category Junction + Bookings category_id
-- Phase 0.2: Decouples vendor identity from service rows.
-- Safe: no DROP, no NOT NULL additions to existing rows.
-- Run order: CREATE → INSERT IGNORE → ALTER → UPDATE (backfill)

-- ── 1. Junction table ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `vendor_categories` (
  `vendor_id`   bigint(20) UNSIGNED NOT NULL,
  `category_id` bigint(20) UNSIGNED NOT NULL,
  PRIMARY KEY (`vendor_id`, `category_id`),
  KEY `idx_vc_vendor`   (`vendor_id`),
  KEY `idx_vc_category` (`category_id`),
  CONSTRAINT `vc_ibfk_1` FOREIGN KEY (`vendor_id`)
    REFERENCES `vendors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vc_ibfk_2` FOREIGN KEY (`category_id`)
    REFERENCES `categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Populate from existing services rows ──────────────────
--    Vendor 11 has 5 services (category_id 1–5) → 5 junction rows.
INSERT IGNORE INTO `vendor_categories` (`vendor_id`, `category_id`)
SELECT DISTINCT `vendor_id`, `category_id`
FROM `services`
WHERE `category_id` IS NOT NULL;

-- ── 3. Add category_id to bookings (nullable FK) ─────────────
--    Placed AFTER service_id. NULL means legacy booking (pre-migration).
ALTER TABLE `bookings`
  ADD COLUMN `category_id` bigint(20) UNSIGNED NULL DEFAULT NULL
    AFTER `service_id`,
  ADD KEY `idx_bookings_category` (`category_id`),
  ADD CONSTRAINT `bookings_ibfk_cat`
    FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
    ON DELETE SET NULL;

-- ── 4. Backfill bookings.category_id from services ───────────
UPDATE `bookings` b
JOIN   `services` s ON s.id = b.service_id
SET    b.category_id = s.category_id
WHERE  b.category_id IS NULL
  AND  s.category_id IS NOT NULL;
```

**Verification query (run after migration):**
```sql
SELECT COUNT(*) FROM vendor_categories;          -- expect 5
SELECT COUNT(*) FROM bookings WHERE category_id IS NOT NULL;  -- expect = prior booking count
SHOW COLUMNS FROM bookings LIKE 'category_id';  -- expect one row
```

---

## STEP 2 — `body/service-engine/api/services/index.php`

### 2A — Add `category_id` to booking INSERT (lines 1143–1168)

**Before (lines 1143–1144):**
```php
        $bookingColumns = ['booking_number', 'user_id', 'vendor_id', 'service_id', 'status', 'payment_status', 'payment_method', 'booking_mode', 'scheduled_at', 'duration_minutes', 'total', 'address_id', 'notes', 'customer_name', 'customer_mobile', 'customer_locality', 'customer_address', 'vendor_route', 'created_at'];
        $bookingValues = [':bnum', ':uid', ':vid', ':sid', "'pending'", ':pstatus', ':pmethod', ':booking_mode', ':sched', ':dur', ':price', ':addr', ':notes', ':customer_name', ':customer_mobile', ':customer_locality', ':customer_address', ':vendor_route', 'NOW()'];
```

**After (lines 1143–1144, then insert new line after 1163):**
```php
        $bookingColumns = ['booking_number', 'user_id', 'vendor_id', 'service_id', 'status', 'payment_status', 'payment_method', 'booking_mode', 'scheduled_at', 'duration_minutes', 'total', 'address_id', 'notes', 'customer_name', 'customer_mobile', 'customer_locality', 'customer_address', 'vendor_route', 'created_at'];
        $bookingValues = [':bnum', ':uid', ':vid', ':sid', "'pending'", ':pstatus', ':pmethod', ':booking_mode', ':sched', ':dur', ':price', ':addr', ':notes', ':customer_name', ':customer_mobile', ':customer_locality', ':customer_address', ':vendor_route', 'NOW()'];
```
_(those two lines are unchanged — the fix is a NEW line added at line 1164, before the existing `serviceColumnOrNull` calls)_

**Insert at line 1164 (before the existing `if (serviceColumnOrNull($db, 'bookings', 'client_request_id'...` line):**
```php
        if (serviceColumnOrNull($db, 'bookings', 'category_id', ':category_id', $service['category_id'] ?? null, $bookingColumns, $bookingBind)) $bookingValues[] = ':category_id';
```

**Why `serviceColumnOrNull`:** This is the existing pattern (lines 1164–1168) for columns that may not exist in older schema versions. The function checks `serviceTableHasColumn()` at runtime before inserting, so the code is forward- and backward-safe.

**Net diff — 1 line added at line 1164:**
```diff
+        if (serviceColumnOrNull($db, 'bookings', 'category_id', ':category_id', $service['category_id'] ?? null, $bookingColumns, $bookingBind)) $bookingValues[] = ':category_id';
         if (serviceColumnOrNull($db, 'bookings', 'client_request_id', ':client_request_id', $operationalRequest['client_request_id'], $bookingColumns, $bookingBind)) $bookingValues[] = ':client_request_id';
```

---

### 2B — `GET /api/services` — group by vendor (lines 821–871)

This is the root-cause fix. The SQL query stays the same (we still need one row per service to know which categories a vendor covers). The grouping happens in PHP before the response is built.

**Before (lines 848–870):**
```php
    $stmt = $db->prepare($sql);
    $stmt->execute($bind);
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $categories = [];
    foreach ($services as $service) {
        $slug = $service['category_slug'] ?? '';
        if ($slug && !isset($categories[$slug])) {
            $categories[$slug] = [
                'slug' => $slug,
                'name' => $service['category_name'] ?? $slug,
                'icon' => $service['category_icon'] ?: '🔧',
                'image' => $service['category_image'] ?? null,
            ];
        }
    }

    Response::success([
        'services' => $services,
        'categories' => array_values($categories),
        'pilot_config' => servicePilotConfig($db),
        'total' => count($services)
    ]);
```

**After (same line range, full replacement):**
```php
    $stmt = $db->prepare($sql);
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group raw service rows by vendor so the app shows one card per vendor.
    $vendorMap = [];
    $categories = [];
    foreach ($rows as $row) {
        $vid  = $row['vendor_id'];
        $slug = $row['category_slug'] ?? '';

        if (!isset($vendorMap[$vid])) {
            $vendorMap[$vid] = [
                'vendor_id'       => $vid,
                'vendor_name'     => $row['vendor_name'] ?? '',
                'rating'          => $row['rating'] ?? null,
                'rating_is_verified' => $row['rating_is_verified'] ?? false,
                'is_featured'     => $row['is_featured'] ?? false,
                'available_today' => $row['available_today'] ?? null,
                'image'           => $row['image'] ?? null,
                'photo'           => $row['photo'] ?? null,
                'jobs_done'       => $row['jobs_done'] ?? 0,
                'vendor_services' => [],          // category names for chips
                '_service_ids'    => [],          // first service_id per category (for booking)
                'id'              => $vid,
            ];
        }

        if ($slug) {
            // Add category name to chips (deduplicated)
            $catName = $row['category_name'] ?? $slug;
            if (!in_array($catName, $vendorMap[$vid]['vendor_services'], true)) {
                $vendorMap[$vid]['vendor_services'][] = $catName;
                $vendorMap[$vid]['_service_ids'][$row['category_id']] = $row['id'];
            }

            if (!isset($categories[$slug])) {
                $categories[$slug] = [
                    'slug'  => $slug,
                    'name'  => $catName,
                    'icon'  => $row['category_icon'] ?: '🔧',
                    'image' => $row['category_image'] ?? null,
                ];
            }
        }
    }

    $vendors = array_values($vendorMap);

    Response::success([
        'services'     => $vendors,
        'categories'   => array_values($categories),
        'pilot_config' => servicePilotConfig($db),
        'total'        => count($vendors)
    ]);
```

**Key shape change:** `services` array now contains one object per vendor (not one per service row). Each vendor object has a `vendor_services` array of category name strings, which `_vendorCardHTML()` in home.js already reads at lines 2167–2169.

**Risk:** Any other consumer of `GET /api/services` that iterates expecting one-row-per-service will receive vendor-grouped data instead. Known affected consumers: `admin/services.html` catalog tab (see Step 4), `_renderInstantSearch` in home.js (line 2206 — also calls `_vendorCardHTML`, safe after grouping).

---

## STEP 3 — `app/pages/home.js`

### 3A — `_renderServices()` grouping (lines 1743–1769)

`_vendorCardHTML(s)` already reads `s.vendor_services` (line 2167) for the chips, and `s.vendor_id` (line 2157 as `s.id || s.vendor_id`) for the card ID. After the API change in Step 2B, each item in `list` is already a vendor-grouped object with `vendor_services[]`. **No change is needed to `_vendorCardHTML`.**

The only required change is in `_renderServices` to ensure that when `_activeCategory` is set, the filter still works against the vendor's categories.

**Before (line 1753):**
```javascript
    if (categoryFilter) list = list.filter(s => _matchesCategory(s, categoryFilter));
```

**After (line 1753):**
```javascript
    if (categoryFilter) list = list.filter(s => _matchesCategory(s, categoryFilter) || (Array.isArray(s.vendor_services) && s.vendor_services.some(name => name.toLowerCase().replace(/\s+/g,'-') === categoryFilter)));
```

**Additionally:** update `WtgSheet.setVendors` call if it needs service_id for booking. Check `WtgSheet.open()` implementation — it likely uses `_allServices` to find a service by vendor_id. After this change, `_allServices` holds vendor-grouped objects. Search for `WtgSheet` in the codebase and verify `open()` derives `service_id` from the stored list; if so, it must be updated to pick a `service_id` from `_service_ids[category_id]` based on the selected category chip in the sheet.

**This is a secondary risk — verify WtgSheet.open() before deploying steps 3–4.**

---

## STEP 4 — `admin/services.html`

### 4A — Service Catalog tab (lines 751–778)

After the API returns vendor-grouped objects, `s.category_name` will be `undefined` (it no longer exists at the top level — categories are in `vendor_services[]`). The category `<td>` at line 768 will render `—`.

**Before (line 768):**
```javascript
      <td><span class="badge badge-purple" style="cursor:pointer" title="Click for category detail" onclick="openCategoryDetail(${parseInt(s.category_id)||0})">${escHtml(s.category_name||s.category_slug||s.category||'—')}</span></td>
```

**After (line 768):**
```javascript
      <td>${Array.isArray(s.vendor_services) && s.vendor_services.length
          ? s.vendor_services.map(n => `<span class="badge badge-purple">${escHtml(n)}</span>`).join(' ')
          : `<span class="badge badge-purple" onclick="openCategoryDetail(${parseInt(s.category_id)||0})">${escHtml(s.category_name||s.category_slug||s.category||'—')}</span>`
      }</td>
```

**Note:** The `onclick="openCategoryDetail()"` link is lossy in vendor-grouped mode because a vendor may cover multiple categories. For now, removing the click is acceptable — the badge becomes display-only. If category drill-down from admin catalog is needed, it requires a separate design decision.

---

## STEP 5 — `vendor/dashboard-service.html`

**Risk level: LOW.** The vendor dashboard hits `/vendor/bookings` not `/api/services`, so it is not affected by the grouping change. After the migration adds `category_id` to the `bookings` table, the bookings list API may return `category_id` as an extra field — the vendor dashboard ignores unknown fields, so no breakage.

**The one risk:** line 939 renders `b.service_type`. This field is not in the `bookings` schema; it must be aliased in the vendor bookings API endpoint (not in `index.php`). Verify that endpoint returns `service_type` as a JOIN alias — it is unaffected by this migration and requires no change.

**Action required:** None for this phase. Flag for review if `service_type` rendering breaks during testing.

---

## ROLLBACK PLAN

### If migration causes issues (Step 1)

Run in order:

```sql
-- Remove backfilled data (idempotent if re-run)
UPDATE bookings SET category_id = NULL;

-- Drop bookings FK and column
ALTER TABLE bookings
  DROP FOREIGN KEY bookings_ibfk_cat,
  DROP KEY idx_bookings_category,
  DROP COLUMN category_id;

-- Drop junction table
DROP TABLE IF EXISTS vendor_categories;
```

No data is lost — `services.category_id` and all existing booking rows are untouched.

### If API/frontend changes cause issues (Steps 2–4)

These are PHP/JS file changes. Rollback = revert the changed lines to the BEFORE versions shown above. The schema change (Step 1) is backward-compatible with the old API code, so schema can stay while code is reverted.

### Partial rollback order

1. Revert `home.js` (Step 3) — customer app returns to broken-5-cards state but nothing crashes
2. Revert `index.php` GET grouping (Step 2B) — API returns per-service rows again
3. Revert `index.php` booking INSERT (Step 2A) — category_id column stays in DB but stops being written (null, which is the column default — fine)
4. Leave migration in place — it is safe in all revert scenarios

---

## PASS / FAIL VERIFICATION CHECKLIST

### Schema (run immediately after migration)

- [ ] `SHOW TABLES LIKE 'vendor_categories'` → 1 row
- [ ] `SELECT COUNT(*) FROM vendor_categories` → 5
- [ ] `SHOW COLUMNS FROM bookings LIKE 'category_id'` → 1 row, Type=bigint unsigned, Null=YES, Default=NULL
- [ ] `SELECT COUNT(*) FROM bookings WHERE category_id IS NULL` → equals total bookings (no pre-existing bookings to backfill in pilot, or shows correctly backfilled count)

### API (after Step 2)

- [ ] `GET /api/services` returns `services` array with **1 item** (vendor 11), not 5
- [ ] That 1 item has `vendor_services: ["Electrician Service", "Plumbing Service", "Painting Service", "Waterproofing Service", "CCTV Installation"]`
- [ ] `categories` array in response still has 5 items (for filter chips)
- [ ] `POST /api/service/request` with a valid service_id creates a booking row where `category_id` matches the service's category

### Customer app (`app/pages/home.js`)

- [ ] Home page shows **1 vendor card** for Aakash, not 5
- [ ] Vendor card chips show all 5 category names (or first 3 + "+2 more")
- [ ] Category filter chips (All / Electrician / Plumber / …) still render correctly
- [ ] Clicking a category chip filters to that vendor (because vendor has that category)
- [ ] BOOK NOW opens bottom sheet correctly
- [ ] Booking submitted from sheet creates booking with correct category_id in DB

### Admin panel (`admin/services.html`)

- [ ] Service Catalog tab shows **1 row** for Aakash with multiple category badges
- [ ] No JS errors in console on catalog tab load

### Vendor dashboard (`vendor/dashboard-service.html`)

- [ ] Bookings list loads without errors
- [ ] `service_type` column renders correctly in bookings table

---

## FILES CHANGED SUMMARY

| File | Lines | Change type | Risk |
|------|-------|-------------|------|
| `migrations/2026_06_15_001_vendor_categories_junction.sql` | NEW | Schema + backfill | Low — additive only |
| `body/service-engine/api/services/index.php` | 1164 (insert 1 line) | Add category_id to booking INSERT | Low — nullable column, existing pattern |
| `body/service-engine/api/services/index.php` | 848–870 (replace ~22 lines) | Group API response by vendor | **Medium** — changes response shape |
| `app/pages/home.js` | 1753 (replace 1 line) | Category filter for grouped vendor objects | Low |
| `app/pages/home.js` | 2091–2195 | No change needed — already reads `vendor_services[]` | — |
| `admin/services.html` | 768 (replace 1 line) | Category cell → multi-badge rendering | Low |
| `vendor/dashboard-service.html` | — | No change needed | — |

**Total lines changed:** ~26 lines across 3 existing files + 1 new migration file.

---

## SECONDARY INVESTIGATION REQUIRED BEFORE STEP 3

Before deploying the frontend changes, verify `WtgSheet.open()`:

```
grep -n "WtgSheet" app/pages/home.js | head -40
grep -n "setVendors\|open(" app/pages/home.js | head -20
```

The sheet needs to know which `service_id` to pass to the booking API when the user taps BOOK NOW. In the new grouped model, the vendor has `_service_ids: { category_id: service_id }`. The sheet must pick the correct `service_id` based on which category the user selects in the sheet. If `WtgSheet.open()` currently stores and uses `service_id` directly from the vendor card data, it will need to be updated to handle the `_service_ids` map — this could add scope to Step 3.
