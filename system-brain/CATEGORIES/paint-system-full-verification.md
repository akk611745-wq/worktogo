# WorkToGo — Paint Unit + System: FULL VERIFICATION (4 lenses)

**Date:** 2026-10-11 · **By:** Claude Code (CTO partner) · **For:** CEO (Aakash)
**Scope:** Paint Unit (P1–P6) + the whole commerce core, verified as real use across Customer / Seller / Rider / Admin. **No code changed — verify only.**
**Where:** local **MariaDB 10.11.19** (port 3307), app served by a throwaway dev router (scratchpad, not the repo), driven in the Claude browser pane where possible, else API/harness.
**Authoritative backend result:** the full commerce suite = **764 PASS / 0 FAIL** on 10.11 (S1–S18 + F1 regression 478, e2e 22, scale 10, full-journey 53, P1 24, P2 39, P3 24, P4 19, P5 34, P6 30, KYC-UI 31).

**Legend:** ✅ working · ⚠️ broken/incomplete · 👁️ = **UI walkthrough** (actually clicked in the browser) · 🧪 = **code-verified** (harness/API, not clicked).

---

## 1. CUSTOMER lens
| Stage | Status | Evidence (file / harness) | How |
|---|---|---|---|
| App opens, city = Haldwani, Painting + Waterproofing categories | ✅ | `app/index.php` (home rendered live) | 👁️ |
| Nearby paint, seller hidden ("Fulfilled by WorkToGo") | ✅ | `StorefrontComposer.php` + `commerce_seller_visibility` (S10) | 🧪 (s10 28/0) |
| Coverage calculator → litres + packs, **"Estimated"** label, no 2-coat double | ✅ | `CoverageCalculator.php` + `paint-calculator/` → `/api/coverage` | 👁️ (400 sq.ft → 3.52L, 1×4L) |
| Single-shop cart (nearest eligible source) | ✅ | `RoutingEngine::planBasket` | 🧪 (s3 21/0, scale 10/0) |
| One server price = base + slab margin + absorbed delivery (no flicker) | ✅ | `MarginEngine` slab + `DeliveryPricing::composeFinalPrice` | 🧪 (s12 30/0, s13 40/0, p3) |
| Payment COD / online | ✅ | `CommercePayment.php` + webhook | 🧪 (s5 23/0) |
| Order → tracking | ✅ | `CommerceDelivery` track + OTP | 🧪 (s6 23/0, full-journey) |
| Reorder + related products | ✅ | `StorefrontComposer` reorder/related (S10) | 🧪 (s10 28/0) |

**Customer verdict:** ✅ working. The app home + Painting category + the coverage calculator are UI-walked; the commerce storefront browse→cart→price→pay→track→reorder is code-verified end-to-end (s10 + e2e + full-journey), not clicked (needs seeded paint products + a customer session + location).
**⚠️ Minor (known, S10):** a **cart-line** seller label uses the GLOBAL visibility default (cart items aren't category-composed); browse + product detail are exact. Cosmetic, not a leak.

## 2. SELLER lens
| Stage | Status | Evidence | How |
|---|---|---|---|
| Seller PWA opens, gates on login | ✅ | `seller/index.html` ("Please log in") | 👁️ |
| Register (role vendor_shopping) | ✅ | `heart/api/auth/register.php` | 🧪 (full-journey 53/0) |
| KYC form (owner/shop/GST/PAN/payout + doc uploads) → submit | ✅ | `seller/seller.js` + `KycService` + `/api/media/upload` | 🧪 (KYC-UI 31/0, s18 22/0) |
| Admin approve → vendor active | ✅ | `KycService::sellerReview` | 🧪 (s18, full-journey) |
| Catalogue: paint attributes + base price + stock, verify gate | ✅ | `SellerCatalogue.php` + `PaintUnit.php` | 🧪 (s16 22/0, p1 24/0, p3) |
| ON / OFF / BUSY | ✅ | `SellerConsole::setAvailability` | 🧪 (s15 31/0, full-journey) |
| Order inbox — customer = **opaque ID, no PII** | ✅ | `SellerConsole::inbox` + `CustomerId.php` | 🧪 (s14 22/0, s15, full-journey) |
| Accept / reject / stock-out → reroute (accept-gates) | ✅ | `SellerConsole` + `RoutingEngine` | 🧪 (s15, s3, p3) |
| Earnings | ✅ | `SellerConsole::earnings` | 🧪 (s15, full-journey) |
| Customer-ID history (own only, cross-seller isolated) | ✅ | `CustomerId::sellerHistory` | 🧪 (s14, full-journey) |

**Seller verdict:** ✅ working. PWA shell + auth gate UI-walked; the full register→KYC→approve→catalogue→availability→inbox→accept/reject/stock-out→earnings→history journey is code-verified (s15/s16/s18 + full-journey 53/0 strings it end-to-end).

## 3. RIDER lens
| Stage | Status | Evidence | How |
|---|---|---|---|
| Onboarding page (register → OTP → KYC wizard) | ✅ | `rider-onboard/index.html` (Step 1 rendered) | 👁️ |
| Register (role delivery) + KYC + doc upload → submit | ✅ | `/api/commerce-rider` + `KycService::rider*` + `/api/media` | 🧪 (s18, full-journey) |
| Admin approve → `delivery_riders` profile created | ✅ | `KycService::riderReview` | 🧪 (s18 22/0) |
| Login → job → pickup proof → OTP deliver | ✅ | `CommerceDelivery` pickup/issueOtp/deliver | 🧪 (s6 23/0, full-journey, p3) |
| Earnings (rider payout credited on settle) | ✅ | `LedgerEngine::processCommerceOrderSettlement` | 🧪 (s1 25/0, full-journey, p3) |

**Rider verdict:** ✅ working. Onboarding wizard UI-walked; job→pickup→OTP→earnings code-verified (s6 + full-journey rider leg + p3).

## 4. ADMIN lens  (API + RBAC seats — no dedicated web UI in this repo, by design)
| Capability | Status | Evidence | How |
|---|---|---|---|
| Admin API gated (401 without a seat/token) | ✅ | `heart/api/commerce-admin/index.php` + `Permission` | 👁️ (live `GET /api/commerce-admin/me` → 401) |
| Live config / kill-switch — commerce / unit / zone / seller / rider | ✅ | `AdminControlRoom` pause scopes + `OrderabilityResolver` enforcement | 🧪 (s8 27/0, s2 14/0, e2e kill-switch) |
| Margin slab + growth knob | ✅ | `MarginEngine` slab + `commerce_margin_multiplier` | 🧪 (s12 30/0) |
| Loss-proof floor | ✅ | `MarginEngine::lossProofOk` | 🧪 (s8, e2e) |
| Source preference (cheapest / nearest / reliable) | ✅ | `RoutingEngine::rankBy` + `commerce_routing_source_preference` (F1) | 🧪 (sf1 7/0) |
| Ops queue / tickets / human-approval holds | ✅ | `OpsConsole.php` | 🧪 (s7 24/0) |
| Money dashboard (profit + who-we-owe) | ✅ | `AdminControlRoom` dashboard + `CommerceSettlement` | 🧪 (s8, s9 25/0) |
| KYC review queue | ✅ | `KycService::pending` + `/api/commerce-admin/kyc/*` | 🧪 (s18 22/0) |
| Audit log | ✅ | `CommerceAdminAudit.php` + `commerce_config_audit` | 🧪 (s8, s11 35/0) |

**Admin verdict:** ✅ working, **code-verified**. There is **no admin web UI** in this repo — the control room is API + RBAC seats (`/api/commerce-admin`, gated by `Permission`). A full admin dashboard UI is deliberately future work ("still-not-now"). The 401 gate was confirmed live.

---

## 5. Full PAINT order — register → settle **founder-free**  ✅ (🧪 p3 24/0 + e2e 22/0)
External seller → paint catalogue offer priced by the **paint-category slab** (base ₹1000 → 5% → ₹1050) → customer order → `seller_subtotal` ₹1000 → accept-gates → delivery → OTP deliver → **SETTLE: platform earns the margin spread ₹50 + delivery platform-fee ₹10 = ₹60, seller keeps ₹1000, rider paid ₹30 — founder puts in ₹0** (loss-proof ≥ 0) → return/refund + kill-switch covered in e2e.

## 6. Custom-tint **OFF**, ready paint runs  ✅ (👁️ + 🧪 p5 34/0)
`GET /api/tint/config` → `{"enabled":false,...}` live; the customer tint page shows **"Custom tinting isn't available yet — ready shades only"**. Orderability blocks any custom-tint product (`custom_tint_off`); ready paint is orderable. The whole tint spine (brand-code guard, new-vs-repaint, repeat→same-seller, non-returnable, consent, confirm-call) is built and tested but dark until Prisma data + `import --enable`.

---

## 7. Only hosting remains (cannot be closed locally)
1. **Cashfree real ₹1** online round-trip + **advance part-capture** on the live gateway — needs creds + network (finding #5). The webhook seam itself is proven (P4 19/0: part-capture → `partially_paid` + balance; bookings untouched).
2. **~20 founder-free orders on staging** with live ops (the launch gate).
3. **Ledger namespace** bootstrap alias / `$db` param at deploy (finding #6).
4. **MariaDB ≥10.5** — satisfied locally on 10.11; confirm on the host.
5. **Custom-tint ENABLE** — after the CEO's **Prisma export** → `php scripts/import_paint_shades.php <file> --enable`.

## 8. Gaps / honest notes
- **No gap that breaks a role.** Every role's journey is green (UI shell walked + backend code-verified).
- **Not UI-walked (code-verified only):** the commerce storefront browse→cart→pay→track→reorder, the seller/rider deep journeys, and the whole admin control room. All proven by harness (s10/s15/s16/s18/s7/s8/s9/s11 + e2e + full-journey) but not clicked, because they need seeded data, sessions, and (admin) have no web UI.
- **Browser-pane geolocation was denied** → the "nearby" list didn't populate during the walkthrough; environment limitation, not an app defect.
- **S10 cart-line seller label** uses the global visibility default (minor; browse/detail exact).

## 9. Bottom line
Paint Unit **P1–P6 structure complete** and the commerce engine is **complete + verified** on MariaDB 10.11 — **764 tests, 0 fail**; UI surfaces render; the founder-free paint money path and the custom-tint-OFF state are confirmed. What is left is **only hosting** (Cashfree/advance live, ~20 staging orders, ledger alias) and the **Prisma-gated custom-tint enable**.
