# Commerce 03 — Data Model: Store Foundation (dual-store, flexible shop-types, motherboard fit)

> **Purpose:** Document the commerce data-model foundation delivered by migration `2026_08_22_001_commerce_store_foundation.sql` — the first build step of the Commerce engine (per `commerce-02-gap-map.md` Phase C0). This is the schema-of-record narrative so a separate commerce team/CTO can manage the model without re-reading the code.
> **Scope of the migration:** SCHEMA ONLY — new `store_types` + `stores` tables, `store_id`/`city_id` alignment on the existing commerce tables, RBAC for a commerce team, and idempotent backfills. **No UI, no endpoints, no changes to the never-touch order code.**
> **Standard:** No jugaad. Reuse the production-grade order core as-is; add only what's missing; keep everything data-driven and future-flexible.
> **Status:** Migration written for **STAGING** (not run here; no PHP runtime). Committed on branch `v2`. Applied via `migrate.php` (filename-date order) or phpMyAdmin, on a restored prod-dump copy first.
> **Date:** 2026-08-22.

---

## 1. What was added (two new tables)

### `store_types` — the flexible, admin-addable shop-type taxonomy
The answer to *"any shop type must work without going into code."* A shop kind is a **row**, not a code branch. `name`/`slug` are `varchar` (never an enum), so a new type never needs an `ALTER` or a deploy — an admin inserts it from the panel (permission `store_types.manage`). Columns: `name`, `slug`, `description`, optional `icon_media_id` (→ `media`), `default_commission_rate`, `is_active`, `sort_order`, `meta`. Seeded with 12 common Indian retail types (Grocery/Kirana, Vegetables & Fruits, Dairy & Bakery, Meat & Fish, Pharmacy, Clothing, Electronics, Home & Kitchen, Stationery, Beauty, Hardware, Food) — all **editable/removable data**, just starter examples.

### `stores` — the shop (dual model + flexible type + city-scoped + shared identity)
Every shop on the platform is one `stores` row. Key design:
- **Dual model in one column:** `ownership enum('company','vendor')`. `company` = a company **dark-store** (own inventory, `owner_vendor_id` NULL); `vendor` = a **local seller/shopkeeper** store (`owner_vendor_id` set). *"is_company_store" === `ownership='company'`* — kept as a single source of truth (no redundant boolean) to avoid the status-vs-flag drift audit-02 flagged. (If the founder later wants a franchise/hybrid tier, it's one more enum value — no restructure.)
- **Flexible type:** `store_type_id → store_types.id` (the data-driven kind).
- **Shared identity:** `owner_vendor_id → vendors.id`, `owner_user_id → users.id` — many independent sellers, each with their own store, all on the motherboard's identity. No separate seller-account table.
- **City-scoped:** `city_id → cities.id` (customer sees stores in their area).
- **Presentation via `media`:** `logo_media_id`, `banner_media_id → media.id` (the unified media table; no per-store image columns).
- **Commercial + geo:** `status` (draft→pending→active→suspended→closed), `commission_rate` override, `min_order_amount`, `service_radius_km`, `lat`/`lng`, address, `priority` (rank company dark-stores first), `opens_at`/`closes_at`, `meta`.
- **Scale indexes:** notably `idx_stores_city_own_pri (city_id, ownership, status, priority)` — the exact shape of the dual-store discovery query.

---

## 2. How ANY shop type works without code changes

```
store_type (DATA: store_types)  →  store (stores)  →  product (products)  →  category (DATA: categories)
```

- **Shop kind** = `store_types` row (admin-addable).
- **Product category** = the existing `categories` table (already data-driven: `type='product'|'both'`, hierarchical via `parent_id`, admin-addable) — reused as-is, no change.
- Nothing about "vegetable seller vs electronics" is hardcoded anywhere. A new vertical = insert a `store_types` row (+ optionally some `categories`), create a `stores` row of that type, add `products`. **Zero code, zero deploy, zero migration.**
- *Future (documented, not built, so we don't box it in):* an optional `store_type_categories` junction (which product categories apply to which shop type) can be added later with no restructure — trivial additive table.

---

## 3. How dual-store + city scoping works

Discovery is a **query**, not a code branch:
- A customer in city `C` sees products whose `store` is `status='active'` and `city_id = C`.
- **Company dark-stores** (`ownership='company'`) that cover the area are ranked first via `stores.priority` (better discounts / more control).
- **Where no dark-store covers the area** (rural), **local vendor stores** (`ownership='vendor'`) fill in — the customer "sees what's available in their area."
- Coverage can use `service_radius_km` + store `lat`/`lng` (Haversine, reusing `DeliveryPricingEngine`'s distance math) for fine-grained dark-store reach.
- **One delivery team serves both** store types — `deliveries.store_id` names the pickup store regardless of ownership.

All of this rides on `stores.city_id` / `ownership` / `priority` — indexed by `idx_stores_city_own_pri`. No per-type branching in code.

---

## 4. Motherboard fit (what connects, and the schema gap this closes)

| Motherboard pillar | Connection | Status after this migration |
|---|---|---|
| **users / vendors identity** | `stores.owner_vendor_id`, `owner_user_id`; products keep `vendor_id` | ✅ Shared, no fork |
| **city_id** | added to `products`, `orders`, `order_items`, `inventory`, `warehouses`, `cart`, `deliveries` (+ `stores.city_id`) — the tables migration `2026_07_04_001` **skipped** | ✅ **Gap closed** — commerce is now city-scoped end-to-end |
| **media** | `stores.logo/banner_media_id`, `store_types.icon_media_id`; product photos = `media` rows `owner_type='product'` (polymorphic, migration `2026_07_04_003`) | ✅ No media schema change needed |
| **wallet reference_type** | the order/booking collision fix (migration `2026_07_04_004`) is already wired in `LedgerEngine` (`reference_type='order'` vs `'booking'`) | ✅ Untouched — money stays collision-safe |
| **RBAC** | new perms `stores.*`, `store_types.manage`, `inventory.*`; roles `commerce_admin` / `catalog_manager` / `dispatch_ops`; super_admin re-asserted all-access | ✅ Commerce team can be scoped from the panel |

---

## 5. REUSE vs NEW (no jugaad)

**Reused AS-IS (production-grade — not touched):**
- `products`, `orders`, `order_items`, `inventory`, `warehouses`, `cart` tables and the `OrderService`/`CartService`/`ProductService` code that reads them (only nullable columns were *added*; grain and existing columns are unchanged, so the code behaves identically).
- `categories` (data-driven product taxonomy), `media` (polymorphic, product photos plug in), `cities`, `vendors`/`users` identity, `wallet_transactions.reference_type` fix, the RBAC tables + engine-tagged permission catalog.

**Newly added:**
- `store_types` (flexible shop-type taxonomy) + seed.
- `stores` (dual-model, city-scoped shop) + 7 FKs.
- `store_id` + `city_id` alignment columns (nullable, indexed, FK'd, backfilled) on 7 existing tables.
- Commerce RBAC: 5 permissions + 3 roles + wiring.

**Backfilled (idempotent, single-city today):** one vendor store per existing shopping vendor; products linked to their store; every commerce row set to Haldwani `city_id`.

---

## 6. Minimal safe alignment the never-touch code will need LATER (flagged, NOT done here)

These are **future** tasks (customer-UI / activation phases), surfaced now so nothing is forgotten — **not** done in this migration, so the never-touch files stay untouched:
1. `OrderService::create` and `ProductController::create/update` should start **writing** `store_id` + `city_id` (columns exist, currently left NULL by the dormant code — harmless until activation).
2. `ProductService` discovery should **filter by `city_id`** and prefer company stores by `priority` (the dual-store query).
3. Product images should **read from `media`** (`owner_type='product'`); the duplicate `products.images_json` column can be dropped **only after** that read-path change (dropping it now would break `ProductService`).
4. Per-store inventory (a `store_id` grain on `inventory`) is a documented future extension — today `inventory` stays one row per product so `OrderService`'s `WHERE product_id = ?` locking is unchanged.

Each of these is an engine-code change that belongs to a later commerce phase and must be surfaced before it's made — never a silent edit to the order core.

---

## 7. Future-flexibility (don't box it in — confirmed)

- **Separate seller app / API:** stores + products hang off shared `users`/`vendors` identity and the `{intent,data}` seam, so an external seller app can CRUD its store/catalog via API using the same identity + RBAC — no schema reshape.
- **Cross-engine feed (later):** `products`/`stores` carry `city_id` + `media` + shared identity, so the unified feed can mix product/service/video, and flows like video→long-press→buy slot in without restructuring.
- **Multi-city:** every commerce table now has `city_id`; adding a city is data, not code.
- **New store verticals / franchise tier / per-type commission:** all data (`store_types`, `ownership` enum, `commission_rate` overrides).

---

## 8. Self-documentation

- This file + `commerce-audit-01-inventory.md` + `commerce-02-gap-map.md` are the Commerce engine's doc home in `WTG-SYSTEM-BRAIN/COMMERCE/`.
- The **migration file itself is the in-repo schema-of-record** — heavily commented, committed on `v2` alongside the engine, so the schema travels with the code (closing the gap that the shopping engine had no `database/` schema file).
- The plug into the motherboard is explicit and documented: shared `users`/`vendors`/`cities`/`media`/RBAC/ledger + the intent pipeline — nothing else — so a separate commerce team owns the rest.

---

## OPEN QUESTIONS (founder)

1. **`ownership` enum vs a plain `is_company_store` boolean** — I used a single `ownership enum('company','vendor')` as the clean source of truth (extensible to franchise/hybrid later). Confirm that's the preferred shape (a boolean is trivially derivable if you'd rather).
2. **Company dark-store seed** — not seeded (needs real inventory/geo/config). Confirm you'll create the first company store from the admin panel once that UI lands (Phase C3), or want a placeholder seeded on staging for testing.
3. **One store per vendor** — the backfill creates exactly one store per existing shopping vendor. The schema allows a vendor to run **multiple** stores (no unique constraint on `owner_vendor_id`). Confirm one-per-vendor is the launch rule (or keep it open).
4. **Per-store inventory** — deferred (kept one-row-per-product to protect the order core). Confirm dark-stores can share the single-inventory model at launch, with per-store stock as a later extension.

---

*Migration is additive, nullable, backfilled, idempotent, and does not modify the never-touch order engine or its code. Written for staging; verify with the migration's VERIFICATION block on a prod-dump copy before prod.*
