# Commerce 06 — Customer Storefront (Phase 4)

> **Purpose:** A world-class, fast, photo-driven customer shopping experience — browse → product → cart → checkout → order tracking — built as a **commerce-scoped** mini-app that **reuses the production-grade engine as-is** (via a clean `/api/shop/*` adapter) and never touches the never-touch order code. City is auto/invisible; stores are shown company dark-store first.
> **Standard:** Better than Amazon/Blinkit — minimal friction, never a dead screen, mobile-first. Clean separation: commerce UI + CSS + logic live only in commerce-scoped files.
> **Status:** ESM + PHP structure verified (no runtime here); full click-through is a staging step. Committed on `v2`. Builds on `f2faf55` (data), `5d8fb9f` (alignment), `5ab5daa` (admin panel).
> **Date:** 2026-08-22.

## 1. Surfaces (one `#shop` route, self-contained mini-SPA)

`app/commerce/shop.js` renders all views inside the shop scope, with its own bottom tab bar (Shop / Cart / Orders) — it does **not** touch the service nav:
- **Browse** — invisible city header ("Delivering to …"), search, shop-type filter chips, a **company-first store strip**, and a photo-driven product grid with inline **ADD → quantity stepper**.
- **Product detail** — big photo, store (dark-store/local badge), price with discount %, description, sticky add-to-cart.
- **Cart** — line items with steppers, live subtotal, sticky checkout bar (reuses `CartService`).
- **Checkout** — delivery address (prefilled from the user), COD, place order (reuses `OrderService::create`).
- **Orders + tracking** — order list and a per-order **status timeline** (placed → confirmed → preparing → out for delivery → delivered), cancel while pending/confirmed.
- **States everywhere:** skeletons on load, friendly empty states with a CTA, error states with Retry — never a blank/dead screen.

## 2. Backend reuse (clean adapter — engine untouched)

`heart/api/shop/index.php` (dispatched from `heart/index.php` on `/api/shop`) **instantiates the never-touch services and calls their public methods**:
- `ProductService` → product detail + row formatting (`formatProduct`) reused as-is.
- `CartService` → cart get/add/update/remove reused as-is.
- `OrderService` → checkout (multi-vendor split, FOR UPDATE inventory locks, COD), order list/detail/cancel reused as-is.
It adds only what the engine can't: **city scoping + dual-store company-first browse** (a clean read that joins `products`→`stores`, filters `stores.city_id`, orders `ownership='company' DESC, priority DESC`). Browse is public (`AuthMiddleware::optional`); cart/checkout/orders require a customer token (`ROLE_CUSTOMER`/`ROLE_ADMIN`), same as the never-touch `OrderController`.

## 3. City + dual-store

- **City is invisible/auto:** resolved via `CityResolver::resolveFromUser` (logged-in) else the active default (Haldwani). The customer never picks a city — same as the service side.
- **Dual-store, company-first:** every browse/store query is `city_id`-scoped and ordered company dark-stores first (higher `priority`), local vendor stores filling in — surfaced with Dark-store/Local badges.

## 4. Clean separation (no khichdi)

| Concern | Commerce-scoped file | Never mixed into |
|---|---|---|
| Storefront logic | `app/commerce/shop.js` | service `app/pages/*` |
| API client | `app/commerce/api.js` (→ `/api/shop/*`) | service `app/js/api.js` |
| CSS | `app/css/commerce.css` (namespaced `.shop-app`, **lazy-loaded**) | service `app/css/wtg-premium.css` |
| Backend | `heart/api/shop/index.php` | the never-touch engine |

Shared infra only: the app shell, hash router (one route added), and generic `UI`/`AUTH` helpers. `commerce.css` is injected by `shop.js` only when shopping opens — zero overhead for the service pilot.

## 5. NEVER-TOUCH + money-safety

**No engine file was touched.** Checkout reuses `OrderService::create` entirely (split, locking, alignment triggers, and the `LedgerEngine` `reference_type='order'` collision fix all apply unchanged). **Checkout is COD.** Online payment is intentionally NOT enabled here: the engine COD-locks it via one constant (`OrderService::ALLOWED_PAYMENT_METHODS = ['cod']`). Enabling online = a surfaced, one-line founder/CTO decision to un-COD-lock the engine in the activation phase, after which this same adapter passes `payment_method='online'` and the engine's existing Cashfree path + webhook (already money-safe) handle it. I did not make that edit.

## 6. AI-ready (not built)

The browse payload carries ranking inputs (`rating`, `total_sold`, `is_featured`, store `priority`, `is_company`) so a future feed/personalizer can re-rank with no schema or contract change. No AI is built now.

## 7. REUSE vs EDIT vs NEW

- **REUSE AS-IS:** `ProductService`/`CartService`/`OrderService` (instantiated, not edited), `CityResolver`, `Response`, `AuthMiddleware`, shared `UI`/`AUTH`, the router shell.
- **EDIT (permitted):** `heart/index.php` (+1 dispatch for `/api/shop`), `app/js/router.js` (+`shop` route, hidden in service-only).
- **NEW:** `heart/api/shop/index.php`, `app/commerce/{api,shop}.js`, `app/css/commerce.css`.

---

## HOW TO VERIFY (staging, after Phase 1–3 migrations; `#shop` is hidden in `SERVICE_ONLY_MODE` — open `#shop` directly or flip the flag)
1. Open `#shop` → the browse view loads (skeletons → stores strip company-first → product grid), city auto-detected.
2. Tap **ADD** on a product → stepper appears, cart badge increments (login prompt if signed out).
3. Open a product → detail with photo/price/store → add to cart.
4. **Cart** tab → adjust quantities, see live subtotal → **Checkout**.
5. Fill address → **Place order (COD)** → order is created via `OrderService` → lands on the **tracking timeline**.
6. **Orders** tab → the order shows with its status; cancel while pending.

## OPEN QUESTIONS
1. **Online payment** — enable by un-COD-locking the engine (surfaced 1-line change) so this adapter can offer online via the existing Cashfree path? Or COD-only at launch?
2. **Entry point** — `#shop` is standalone + hidden now. At launch, add a "Shop" entry on the home feed / a nav tab (an integration task), or keep the unified-feed approach?
3. **Delivery fee** — the cart shows "calculated at delivery" (native delivery engine is Phase C4). Confirm the COD flow shows the fee post-quote later.
4. **Storefront palette** — commerce uses its own light retail theme (`.shop-app`), separate from the service app. Confirm the visual direction.
