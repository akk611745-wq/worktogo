# Commerce 05 — Admin/Seller Control-Room (Phase 3)

> **Purpose:** A dedicated, RBAC-gated commerce control-room to manage the dual-store model, flexible shop types, products, and inventory — all data-driven (any shop type / product / category is addable from the panel, zero code), city-scoped, and built cleanly on the Phase-1 schema + Phase-2 alignment, **without touching the never-touch order engine.**
> **Standard:** Better than Amazon, no jugaad. Reuse the proven schema + media/upload infra; build clean where missing; a separate commerce team owns its own file.
> **Status:** Frontend JS syntax-verified (Node); backend written for staging (no PHP runtime here — apply the Phase-1/2 migrations first, then smoke-test on staging). Committed on `v2`.
> **Date:** 2026-08-22. Builds on commit `f2faf55` (data model) + `5d8fb9f` (order alignment).

---

## 1. Panel surfaces

Two dedicated, mobile-friendly admin pages (same shell/design system as `cities.html`/`roles.html`), reachable from a new **Commerce** nav group (hidden in `SERVICE_ONLY_MODE`, like the other dormant-engine pages):

- **`admin/commerce-stores.html` — Stores & Shop Types**
  - **Shop types:** add / edit / activate / deactivate any shop type (chips). Vegetable, kirana, clothing, electronics, pharmacy… each is a row.
  - **Stores:** create/edit **company dark-stores** and **local vendor stores** (segmented ownership control), set shop type, city, status, geo, locality, priority, radius, min-order, and upload a logo (→ `media`). Filter by ownership / city / status. Each card links to its catalog.
- **`admin/commerce-products.html` — Catalog & Stock**
  - Pick a store → manage its products (create/edit, price, sale price, category, SKU, unit, status), upload photos (→ `media`, mirrored into `products.images`), and edit inventory (quantity, low-stock alert, backorder). Filter by status / search. Deep-linkable via `?store_id=`.

## 2. Backend (dedicated file, RBAC-gated, data-driven)

`heart/api/admin/commerce.php` — a self-contained commerce API, included from `heart/api/admin/index.php` with **one** `require_once` after the shared `AuthMiddleware::requireRole(ROLE_ADMIN)` gate. Every route enforces a granular commerce permission (seeded in Phase 1):

| Route | Perm |
|---|---|
| `GET /commerce/meta` (dropdown data) | `stores.view` |
| `GET/POST/PATCH /commerce/store-types[/{id}]` | `stores.view` / `store_types.manage` |
| `GET /commerce/stores[/{id}]`, `POST/PATCH /commerce/stores[/{id}]`, `POST …/logo` | `stores.view` / `stores.manage` |
| `GET /commerce/products[/{id}]`, `POST/PATCH …`, `POST …/photo` | `products.view` / `products.manage` |
| `PATCH /commerce/products/{id}/inventory` | `inventory.manage` |

Commerce roles `commerce_admin` / `catalog_manager` (Phase 1) hold these; the legacy admin / `super_admin` keep full access. Non-matching requests fall through to the rest of the admin router (no `notFound` in the commerce file).

## 3. How "any shop type / product" works with ZERO code

- **Shop type** = a `store_types` row (`name`/`slug` varchar, never an enum). Add one from the panel → it instantly appears in the store form's type dropdown. No deploy.
- **Product category** = the existing data-driven `categories` table (`type='product'|'both'`). Admin-managed already.
- **Product** = a `products` row created via the clean admin adapter, tied to a store + city.
- Nothing about a vertical is hardcoded: `store_type → store → product → category`, all data.

## 4. Dual-store + city scoping

- **Ownership** is one column (`stores.ownership = company|vendor`). Company dark-stores get a higher default `priority`; the store list/filters and the `idx_stores_city_own_pri` index are shaped for the customer-side discovery query (company-first, vendor-fill, city-correct) that the next task's storefront will use.
- **Every store is `city_id`-scoped**; products/inventory inherit the store's city (set explicitly here + the Phase-2 triggers as a safety net).
- **Company seller identity (resolves data-model OQ#1):** `products.vendor_id` is `NOT NULL` and the ledger is vendor-keyed, so **every store backs onto a vendor**. Local stores use the chosen seller; **company dark-stores use one canonical company vendor** (`slug='worktogo-company'`, 0% commission — the house keeps 100% of its own sales), find-or-created on first company-store save. So company products create cleanly and their earnings have a wallet home.

## 5. REUSE vs EDIT vs NEW (no jugaad)

- **REUSE AS-IS:** the `stores`/`store_types`/`products`/`inventory`/`media` schema (Phase 1), the Phase-2 alignment triggers (safety net for store_id/city_id), `Media::add` + `UploadHelper::save` (photo/logo upload — same pattern as the package-cover endpoint), `Permission::enforce`, the admin shell/design system.
- **EDIT (permitted files only):** `heart/api/admin/index.php` (+1 `require_once`), `admin/shared/shell.js` (+ Commerce nav group).
- **NEW:** `heart/api/admin/commerce.php` (the commerce API), `admin/commerce-stores.html`, `admin/commerce-products.html`.

## 6. NEVER-TOUCH honored

**No engine file was touched.** Product/inventory **writes** are a clean admin-side adapter in `commerce.php` (a focused `INSERT` mirroring the engine's shape) that sets `store_id`/`city_id`/`vendor_id` explicitly from the chosen store — not `ProductController`. Product photos use `media` (`owner_type='product'`) **and** are mirrored into `products.images` (JSON) so the never-touch `ProductService`/catalog renders them unchanged. Money paths (`wallet_transactions`, ledger) are not written here; the order/booking `reference_type` collision fix stays intact.

## 7. Reuse of the catalog READ engine (next task)

The customer-facing storefront (next task) will reuse the production-grade `ProductService` (active + in-stock reads) via the intent pipeline. The admin panel deliberately uses its own broader read (all statuses, per store) because admins must see drafts / out-of-stock — forcing reuse there would be jugaad.

## 8. Self-documentation

This doc + the commented `commerce.php` (in-repo) let a separate commerce team run stores, shop types, catalog, and stock without re-reading the engine. Joins `commerce-01…04` in `WTG-SYSTEM-BRAIN/COMMERCE/`.

---

## OPEN QUESTIONS (founder)

1. **Company vendor identity** — I auto-provision one canonical `worktogo-company` vendor (0% commission) to back all company dark-stores. Confirm the name/commission, or if each dark-store should be its own vendor.
2. **Commerce nav visibility** — the Commerce group is `hiddenInServiceOnly` (hidden during the pilot, like Products/Orders/Delivery). To test now, open `admin/commerce-stores.html` directly or flip `Shell.SERVICE_ONLY_MODE`. Confirm you want it hidden until commerce launches.
3. **Product image storage** — photos are `media` rows + mirrored into `products.images` for the never-touch reader. When the storefront/read-path is aligned to `media` (a later engine-code phase), the mirror can retire. Confirm the bridge is acceptable meanwhile.
4. **Per-store inventory** — still one row per product (protects the order core's locking). Fine for launch; per-store stock is a later extension.
