# WorkToGo Commerce — Build Handoff / Resume Note

**Purpose:** single "resume here" doc so any session (post-compaction / fresh) can continue without re-deriving. **Updated:** 2026-10-08.
**Status:** **S1–S11 done + CORE-COMPLETION in progress** (S12 done; 291 local tests PASS). Core-completion plan = [core-completion-plan.md](core-completion-plan.md) (S12 slab margin ✅ · S13 delivery absorb ✅ · S14 Customer-ID ✅ · S15 seller backend + accept-gates-dispatch ✅ · S16 seller catalogue · S17 seller PWA → staging gate). **384 local tests PASS.** Seller API = `/api/commerce-seller` (vendor_shopping-scoped), lib `SellerConsole`; accept-gate flag `commerce_accept_gates_dispatch` (default on). Model locked: **no own stock, seller-based from day one**; Paint Unit deferred until core complete. Rider dispatch = **(A) accept-gates-dispatch** (decided, for S15).
**Reads with:** [commerce-system-blueprint.md](commerce-system-blueprint.md) (design SoT) · [commerce-v1-build-spec.md](commerce-v1-build-spec.md) (step order) · CHANGELOG.

## Git / branch
- Branch **`feat/commerce-core`**, off `v2`, pushed to remote **`platform`** (`worktogo-platform`). Latest commit **`fd61a4f`** (S9). Local only — NOT merged to v2/main. Painting launch stays safe on v2.
- Per-step hashes: S1 `2d44b70` · S2 `5d189c6` · S3 `1048392` · S4 `bca0272` · S5 `9cdc2e6` · S6 `01ab387` · S7 `c639232` · S8 `2e99ab4` · S9 `fd61a4f`. (Docs backup `b72a32a`.)

## Doctrine (keep following)
- One task = one commit on `feat/commerce-core`. **Never edit:** `body/shopping-engine`, `body/*-engine` (service), `app/pages/orders.js`, `core/delivery/DeliveryEngine.php`, `core/helpers/LedgerEngine.php` — **reuse via public methods only** (the ONE exception already done: S1 reworked only `LedgerEngine::processCommerceOrderSettlement`'s order branch).
- All new commerce logic lives in **`lib/commerce/*`**. All rule values in **`app_settings`** (configurable, zero-code). Each step: migration + lib + test → prove locally → commit+push+hash → report PASS/FAIL → next on CEO "proceed".

## Local dev env (Windows)
- XAMPP **PHP 8.2** `C:/xampp/php/php.exe` + **MariaDB 10.4** `C:/xampp/mysql/bin/mysql.exe`, `127.0.0.1:3306`, root / no password, DB **`worktogo`**. `.env` (gitignored) has `MIGRATE_SECRET=local_dev_migrate_8f3a2c9d1b6e4f7a0c5d`.
- Run migrations: `php migrate.php <MIGRATE_SECRET>` — needs the loop: skip `*seed*` migrations (mark applied), stream-patch 10.4 `COMMENT`-after-`CHECK` (`perl -0777 -pe "s/\s*COMMENT '[^']*'//g"`) for 005/009.
- Run all tests: `for t in s1_margin_ledger s2_orderability s3_routing s4_events s5_payment s6_delivery s7_ops s8_admin s9_settlement_returns; do php tests/commerce/${t}_vectors.php; done` → **198 PASS, 0 FAIL**.
- **Crash recovery recipe (power-cut InnoDB "LSN in the future"):** mysqld OFF → rename `C:\xampp\mysql\data`→`data_crashed` → copy `C:\xampp\mysql\backup`→`data` → start → rebuild worktogo.
- **Rebuild worktogo recipe:** `CREATE DATABASE worktogo` (utf8mb4_general_ci) → load `database-structure.sql` → convert all tables to `general_ci` → run the migrate loop. (MariaDB 10.4 uses general_ci; prod must be ≥10.5 — finding #1.)

## What S1–S9 built (all in `lib/commerce/`, tests in `tests/commerce/`, migrations `2026_10_06_00X_*`)
- **S1 MarginEngine** — 3-tier margin (product??category??platform) + min-floor + rounding + loss-proof. Reworked `processCommerceOrderSettlement` commission→**margin** (seller base full, platform spread, company=full margin). New `order_items.seller_base_price`, `orders.seller_subtotal`, `categories/products.margin_*`, `app_settings.commerce_margin_policy`.
- **S2 OrderabilityResolver** — 4-layer gate (kill-switch / catalogue / store+city+seller ON·OFF·BUSY / stock) + `sellerAvailableSql()` browse predicate + kill-switch enforcement. `vendors.busy_until`, `commerce_enabled`. Wired into `/api/shop` browse+cart+checkout.
- **S3 RoutingEngine** — deterministic: eligible→company-first→nearest(haversine)→offer/timeout→reroute; single-shop+soft-split; `commerce_routing_signals`. **`products.match_key`** (sku is UNIQUE → match_key = cross-seller equivalence key). Checkout calls `onOrderPlaced`.
- **S4 CommerceEventBus** — `commerce_events` timeline + `commerce_order_flags` (needs_ops/payment_issue/high_value_hold) + notifications (shared `notifications` table). Checkout emits `order_created`; RoutingEngine emits offered/rerouted/timeout/reroute_failed.
- **S5 CommercePayment** — configurable methods (online primary + COD); online via **existing** `PaymentService`/`PaymentGatewayManager` (multi-gateway + failover; Cashfree live, Razorpay stub); webhook = sole paid authority. `commerce_payment_methods`.
- **S6 CommerceDelivery** — handoff enrich (Σweight→vehicle, prepaid/COD) + pickup proof + delivery OTP + `reassignUnaccepted`. Reuses `DeliveryEngine`. Rider **PWA** installable (`rider/manifest.webmanifest` + `service-worker.js`). `deliveries` cols + vehicle thresholds/timeout configs.
- **S7 OpsConsole** — exception queue (flags) + claim/escalate/resolve + approveHold/rejectHold + reassignRider/cancelOrder (reuse adminReassign/OrderService::cancel) + comms (`commerce_messages`) + owner view. `commerce_order_flags` owner/escalated cols, `commerce_ticket_sla_sec`.
- **S8 AdminControlRoom** — live config get/set (allowlist) + `commerce_config_audit` + kill-switches (global/unit/zone/seller/rider, enforced in resolver via `commerce_paused`) + loss-proof + forceStatus + money dashboard (profit/who-we-owe). 
- **S9 CommerceSettlement + CommerceReturns** — settleEligible (return-hold) / runPayout (`commerce_payouts`, bank/UPI weekly) / accountingSplit (own vs facilitated); source-aware returns (window + risk class) → refund (reuse `LedgerEngine::processRefund`; COD = store-credit SOP). `commerce_returns` + configs.

## CEO-decided defaults (in app_settings)
margin override (product??category??platform), online primary + COD fallback, payout bank/UPI **weekly** + return-hold 48h, return-cost platform 48h, offer timeout 2min, high-value hold ₹5000, ticket SLA 15min, vehicle thresholds bike≤20/e_rickshaw≤80/else loader.

## 6 OPEN FINDINGS (before launch)
1. **MariaDB ≥10.5** required — 10.4 rejects migrations 005/009 (`COMMENT` after `CHECK`) AND `DeliveryEngine::assignNearest` (`FOR UPDATE SKIP LOCKED`). Hostinger renew → confirm ≥10.5; auto-resolves. (Rider A→B re-pick verifies on ≥10.5.)
2. **Collation** — artifacts declare utf8mb4_unicode_ci; MariaDB 10.4 default general_ci → `col=@var` clashes. Prod ensure consistent collation.
3. **Fresh-DB seed** — `2026_06_14_001_seed_initial_services` hardcodes vendor 11/cats 1–5; skip on fresh install or build from prod dump.
4. **products.sku UNIQUE** → two sellers can't share a SKU; `match_key` is the equivalence key (seed of canonical-product model; full catalogue deferred).
5. **Cashfree sandbox live-step** — real online ₹1 round-trip needs test creds in `.env` (CASHFREE_APP_ID/SECRET_KEY, CASHFREE_ENV=SANDBOX) + network. COD unblocked meanwhile.
6. **Ledger namespace** — `LedgerEngine::processRefund`/`processOrderCompletion` use namespaced `Core\Helpers\Database`/`Logger` (classes are GLOBAL) → latent runtime fatal. Commerce refund path mitigates with a `class_alias` shim; prod should alias in bootstrap or add a `$db` param.

## REMAINING
- **S10 — Customer app** ✅ DONE (`fd61a4f`→S10 commit): `lib/commerce/StorefrontComposer.php` (seller-hidden / Unit-UI / related) + adapter `/meta`·`/products`·`/products/{id}` compose + `POST /orders/{id}/reorder` + front-end (`app/commerce/shop.js`,`api.js`,`css/commerce.css`): payment chooser (online+COD), seller-hidden rendering, related strip, reorder. Migration `2026_10_06_010`. Live tracking already present (S6 `deliveryTrack`). Config keys: `commerce_seller_visibility` / `commerce_platform_label` / `commerce_unit_ui` / `commerce_related_limit` (all in AdminControlRoom allowlist). 28/28 PASS. Limitation: cart-line seller text uses the GLOBAL visibility default (cart items not category-composed; browse/detail are exact).
- **S11 — RBAC + seats + audit API** ✅ DONE: migration `2026_10_06_011` (11 `commerce.*` perms + 5 seats `commerce_ops/finance/seller_ops/delivery_ops/unit_operator` + super_admin links + `commerce_admin_audit`). HTTP layer `heart/api/commerce-admin/index.php` (dispatched in `heart/index.php` on `/api/commerce-admin`) exposes OpsConsole + AdminControlRoom + CommerceSettlement + CommerceReturns, each gated by `AuthMiddleware::requirePermission` (legacy admin/super_admin bypass; pause scope `unit`=category-scoped; `seller` needs `commerce.seller.manage`); `GET /me` = seat window. `lib/commerce/CommerceAdminAudit.php` = uniform scrubbed trail. 35/35 PASS. RBAC activation point = `requirePermission` calls in that adapter; grant users seats via `user_roles`.
- **DONE gate:** ~20 real orders run **founder-free on staging** (needs MariaDB ≥10.5), every exception handled by system/ops. Then freeze core → grow by Units (config).
- **STILL-NOT-NOW (don't build):** Brain/ML, multi-seller optimization, advanced dark-store, service↔commerce project link, multi-city, native apps, full support UI.
