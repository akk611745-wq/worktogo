# WorkToGo — Commerce Layer Audit (read-only, evidence-based)

**Date:** 2026-10-05 · **Scope:** commerce only · **Method:** code-verified (no runtime; no edits made)
**Companion:** [commerce-launch-plan.md](commerce-launch-plan.md) builds on this. Related: [money-and-earnings-flow.md](money-and-earnings-flow.md), [painting-launch-audit.md](painting-launch-audit.md).

---

## Where commerce lives (confirmed INSIDE `worktogo`, not parallel)

| Layer | Location | Role |
|---|---|---|
| Engine (never-touch) | `body/shopping-engine/` — `ProductService` (299L), `CartService` (385L), `OrderService` (1031L) | Catalogue reads, cart, multi-vendor COD checkout, inventory locks |
| Storefront adapter | `heart/api/shop/index.php` (`/api/shop/*`) | City scoping + dual-store browse ON TOP of the engine, by reuse not fork |
| Admin control-room | `heart/api/admin/commerce.php` | Stores + products CRUD (sets `store_id`/`city_id` explicitly) |
| Customer UI | `app/commerce/shop.js` + `app/commerce/api.js` | Storefront front-end (talks only to `/api/shop/*`) |
| Money/delivery (shared) | `core/helpers/LedgerEngine.php`, `core/delivery/DeliveryEngine.php` | Commission split + distance-priced native delivery |

---

## Item-by-item verdicts (EXISTS / PARTIAL / MISSING)

1. **DATA MODEL — PARTIAL.** `products.vendor_id` is NOT NULL and single (`database-structure.sql:627`) → **no canonical-product/seller-offer split; the product row IS one seller's offer.** Has `price`/`sale_price`/`cost_price`, **no margin column**. Dual-store schema is strong: `store_types` + `stores` (`ownership='company'|'vendor'`, `city_id`, `lat/lng`, `service_radius_km`, `commission_rate`, `priority`) in `migrations/2026_08_22_001_commerce_store_foundation.sql:81-198`. `categories` has no margin/commission column (`database-structure.sql:384`). `inventory` is one row per product (`:480`). `orders`/`order_items` are separate from service `bookings` (`:535`/`:574`/`:118`) and already carry `delivery_fee`/`discount`/`tax` columns.
2. **CATEGORY + MARGIN CONTROL — MISSING (as founder defines it).** System has **commission (deduct from seller), not margin (markup on top)**. `LedgerEngine.php:193-196`: `platform_commission = subtotal * rate/100; vendor_share = subtotal − commission`. Platform tier = hardcoded 10% fallback (`:194`); category tier not applied in the order branch; product tier none.
3. **LOCATION FILTERING — PARTIAL.** Listing is city-scoped (`heart/api/shop/index.php:113`, `s.city_id = :city`), company-first ordering (`:102`), city invisible via `CityResolver`. **No distance/radius filter, no expandable range, no customer range filter.** `stores.lat/lng` + `service_radius_km` exist but browse ignores them; only the delivery fee uses distance (`:174-192`).
4. **CUSTOMER FLOW — EXISTS, but SELLER IS EXPOSED.** Browse→cart→COD checkout→tracking all wired. API returns `vendor_name` + `store_name` (`heart/api/shop/index.php:124,136`; `ProductService.php:263`); detail attaches full store object (`:158`). Only vendor phone was stripped.
5. **SELLER (COMMERCE) PANEL — PARTIAL.** Catalogue CRUD `vendor/products.php` (name/price/stock/image/desc). Accept/Reject + realtime `vendor/orders.php:171` backed by `OrderService::updateVendorOrderStatus:429`. Store ON/OFF: `vendors.is_online` column exists (`database-structure.sql:822`) but storefront filters store `status`, not `is_online` — toggle wiring UNKNOWN. Earnings: `VendorAnalyticsController.php` revenue analytics; self-facing wallet balance historically admin-only — UNKNOWN/verify.
6. **PRICING / ECONOMICS — PARTIAL.** Customer price = seller price, **no margin** (`OrderService.php:261,316`; `total = subtotal`). Delivery computed by distance with a free-above threshold (`DeliveryEngine.php:35-36,78`, rate card) but **written to `orders.delivery_fee` without being added to the order total** (`:184`) — billed separately. Per-order economics tracked via ledger wallet splits (`LedgerEngine.php:193-235`). `products.cost_price` exists but unused.
7. **PAYMENT — COD ONLY (online built, disabled).** Hard-locked: `OrderService.php:49` (`ALLOWED_PAYMENT_METHODS=['cod']`). `online` branch `:191-211` is dead code behind the allowlist. `PaymentService` (Cashfree) ready but off. No advance/partial.
8. **RETURN / REFUND — MISSING (commerce).** Only order cancel + inventory release (`OrderService.php:337`). `orders.status`/`ledger_status` have `refunded` enum but no flow; existing `RefundController` is service-side.
9. **PAINT-SPECIFIC — MISSING (commerce).** No brand/shade-code/pack-size/coverage fields on `products`. `rate_per_sqft`/`area_sqft` live only in the SERVICE engine (`body/service-engine/api/services/index.php:1013,1526`). `product_variations.attributes` JSON (`database-structure.sql:663`) could hold shade/pack but is unreferenced by the engine.
10. **DELIVERY HANDOFF — EXISTS (two paths).** Native: `/api/shop/checkout → DeliveryEngine::createForOrder` (`heart/api/shop/index.php:249`). Legacy/external: `OrderService::dispatchToDelivery:965` → `SWIFTDELIVER_URL` Node service (skipped if unconfigured).
11. **ENTANGLEMENTS — separable tables; shared money/delivery spine.** Commerce owns its tables, separate from service `bookings`. Shared: `LedgerEngine` + `wallet_transactions` (disambiguated by `reference_type='order'` vs `'booking'`), `DeliveryEngine`, `categories`, `vendors`, `users`, `cities`, `media`, RBAC. Also exposed to the agent via `core/agent/tools/CommerceTools.php`.

---

## Honest readiness

- **Generic commerce plumbing: ~70%.** Order core, cart, catalogue, dual-store schema, city scoping, admin control-room, native distance-priced delivery + rider assign, vendor accept/reject, and a commission ledger are real and production-hardened.
- **Founder's specific commerce vision: ~35–40%.** Not built: three-tier **margin/markup** (code does the opposite — commission); config-driven **seller visibility**; **distance/range** product discovery; paint-as-product data; commerce **return/refund**; **online + advance** payment.

## Biggest single finding
The money model is a **commission** model (take a cut from the seller). The founder's vision is a **margin/reseller** model (buy at seller price, sell at WorkToGo price, keep the spread). These are different money flows — the pricing + ledger path needs rework, not a tweak.

## Blocked / UNKNOWN
- No local PHP runtime → all findings are static-code (no runtime proof).
- Store ON/OFF toggle wiring (`vendors.is_online` vs `stores.status`) — unverified.
- Vendor self-facing earnings/wallet view — unverified (prior audit: admin-only).
- `categories.commission_rate` read by the service ledger branch but absent from this schema dump — column origin unverified (likely inherited V1 schema).
