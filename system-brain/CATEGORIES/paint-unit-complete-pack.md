# WorkToGo — PAINT UNIT: COMPLETE BUILD PACK (self-contained)

**From:** WORQ (CTO), on CEO (Aakash) direction · **Date:** 2026-10-09
**To:** Claude Code · **This one document is the full pack — nothing else is needed with it.** All GPT research needed is distilled inside (Section F); the misleading parts have been removed on purpose (see F.0).
**Context:** The commerce CORE is already built and proven — S1–S11, 261 local tests, 0 fail, on `feat/commerce-core`, service/OrderService/DeliveryEngine/LedgerEngine never edited. This pack is the FIRST UNIT (Paint) on top of that frozen core, plus a few CORE tweaks the CEO asked for (Section C).

---

## A. WHAT TO DO WITH THIS PACK (task command)

1. **Save** this document to `system-brain/CATEGORIES/paint-unit-complete-pack.md` (commit + push).
2. **PLAN ONLY FIRST — NO CODE YET.** Read this whole pack + the existing commerce core + the engine build-spec/blueprint. Then produce a complete **Paint Unit Plan**:
   - A **P0 paper simulation**: walk ONE real paint order end-to-end on paper (a ready-product order AND a custom-tint order) through every stage — catalogue → discovery(location, seller-hidden) → calculator → cart(single-shop/soft-split) → price(slab + delivery-absorb + loss-proof) → payment(online/COD/advance) → routing → seller accept / stock-out → delivery quote + handoff → deliver(OTP) → settlement → return rules → Customer ID history.
   - The **architecture test**: confirm the core needs **NO paint-specific code inside any `body/` engine or the core**. A Unit must be config + attributes + thin rules only. If any stage forces core surgery, STOP and flag it — that's the single most valuable thing to find now.
   - A **reuse map** (what's already in the core S1–S11 that this Unit uses) + a **gap list** (what's genuinely new).
   - The **engine tweaks** from Section C, planned as their own steps.
   - A **step-by-step build order** (P1, P2…) with dependencies — same rhythm as S1–S11.
   - **Time estimate** per step + an honest readiness note.
   - **Open questions / your own suggestions** — tell the CEO how you'd do it, where you disagree, what you'd change. The CEO explicitly wants your recommendation, not just agreement.
3. **Report the plan back. Do NOT start coding.** The CEO reviews the plan, then says "PASS, start P1". Only then build — one step, one commit, local test proof, PASS/FAIL + hash + push, CEO PASS before each next step.

**Working rules (unchanged doctrine):** read before edit · one task = one commit · build on existing code, never rebuild for taste · never touch shopping-engine/video-engine/orders.js/service/OrderService/DeliveryEngine/LedgerEngine · never fix unrelated · never delete · evidence over assumptions · every value admin-configurable, nothing hardcoded · Unit = config, not core code · `feat/commerce-core` branch, v2 untouched.

---

## B. THE VISION IN ONE PAGE (so nothing drifts)

WorkToGo commerce is a **local commerce operating layer**, not a marketplace. The customer states a need and sees ONE unified WorkToGo store of their nearby supply; the system decides the best source behind the scenes. Hybrid supply: other shops + WorkToGo's own stock, both registered identically as "sellers". Money = **margin/reseller** (seller sets base, WorkToGo adds margin on top, keeps the spread) — NOT commission. Seller is **hidden or shown per Unit** (paint = hidden; food = shown). One CORE built once + frozen → every new category (Unit) = config + attributes + small rules, never new engine code. Rule-based now; Brain/AI later on captured data. The whole system runs founder-free: normal operation automatic, exceptions to an ops/support console, never to the founder.

**First Unit = Paint.** Reason: the CEO works at a paint shop (can load catalogue, act as test seller, has the shop's tinting software "Prisma Pro"), real demand, large baskets (good economics). **Launch brands: Berger + Indigo first.** First seller/area: the CEO's known shop and/or WorkToGo's own stock — the core treats both identically; decided at go-live, not a build dependency.

---

## C. CORE TWEAKS THE CEO WANTS (do these as part of this work, before/at the Unit)

These are small upgrades to the **existing** core config/rules — NOT rebuilds. Plan each as its own step.

### C.1 Margin = SLAB, not flat percentage (the CEO's central pricing point)
The core has 3-tier margin (product ?? category ?? platform) + loss-proof floor + free-above (S1/S8). Flat % breaks at the extremes, so replace with an admin-configurable **slab** per unit/area:
- **Cheap (e.g. ₹0–500):** a **flat amount** (e.g. ₹15–30), not % — because % earns almost nothing on a small basket, and a ₹50 item can't carry a ₹25 delivery.
- **Mid (e.g. ₹500–5000):** percentage (e.g. 8–12%).
- **High value (e.g. ₹5000+):** **decreasing %** (e.g. 5% → 3% → 2%). CEO's rule verbatim: *"as the price rises, the % falls — only then it's fair."* A ₹2–5 lakh order must not carry a flat 10%.
- **Always:** loss-proof floor enforced (margin ≥ our cost + delivery share; never accept below — unless an explicit, configured loss-leader). High-value orders above a threshold → human-approval hold (already in S4/S7).

### C.2 Growth-mode margin knob
Margin starts **very thin** (even near-zero in some categories) to win transactions + trust early; raised **gradually** as traffic grows, watching the market — all live from admin, no deploy. Early goal = maximum transactions through WorkToGo, not profit. CEO: *"we're not building another shop, we're building a business."*

### C.3 Delivery pricing = quote + absorb (no price flicker)
- Commerce asks the **delivery engine for a quote** (distance, rider cost, availability) BEFORE an item is cartable; builds ONE final server-side price. Location is detected first. The customer sees a **fully-computed final price, once** — never a price changing/assembling on screen while it loads. (Core already prices server-side; enforce this no-flicker discipline in the customer app.)
- **Delivery-absorb rule:** high-margin item → delivery absorbed → show **free delivery**. Cheap item → absorb **part** of delivery into the product price + show a small part — never a jarring ₹25 delivery on a ₹50 item. How much hidden vs shown = per-unit/area config, decided before cart-add, confirmed at checkout, never a surprise.
- **Rider pay = distance-based** (separate from product price; rider sees distance, not product value), shown in the rider panel (S6). **Cache area delivery rates** (S8 config) for speed; live-quote only for new/edge cases.

### C.4 Customer ID system (new thin layer, no PII)
System auto-creates a unique **Customer ID** per customer (the customer may type any name — irrelevant). The seller sees ONLY that ID, never name/phone/address (seller stays blind — builds on S10 seller-hidden). The seller sees that ID's **own order history with them** ("this ID: this shade, this quantity, X times") — for exact repeat-matching + seller motivation ("I sold this much"). A seller sees only their own orders for an ID; no cross-seller leakage; no PII ever. Builds on existing history/signals (S3) + per-seller settlement (S9).

### C.5 "Learning now vs Brain later" — keep distinct
- **Now (rules + counters):** simple stats that update every order (e.g. a product sells more → rank it up; a seller delays → rank down; show what's active in the customer's area; festival/season switch = admin toggle, not AI; related products by same category/brand/bought-together/near-you — already in S10). The system runs fully on this.
- **Now (capture):** every order already records signals (accept/reject, response time, stockouts, distance, margin, complaints — S3). Keep/extend this as the Brain's future fuel.
- **Later (Brain/ML):** real prediction/personalization/"reflex" (Instagram-style), demand heatmaps, dynamic pricing, smart assignment — built on the captured data, behind the same interfaces, no core rewrite. NOT now. (Don't try to build prediction on zero data.)

---

## D. THE PAINT UNIT — WHAT TO BUILD

### D.0 Scope split
**IN (build the structure now):** paint catalogue attributes + seller catalogue entry + verification gate · **ready-products full sell flow** (first money path) · coverage calculator + customer guide · custom-tint STRUCTURE (code field, code→seller, base attribute, advance, no-return, repeat→same-seller, new-vs-repaint toggle, brand-code guard, optional confirm-call) built but DATA-EMPTY + off in config · Customer ID (C.4) · seller "stock not available" button → reroute.
**DEFERRED (hooks/structure only):** custom-tint DATA (shade/base/code from Prisma Pro export, Section G) · base↔shade compatibility rules (with that data) · Brain/personalization (C.5) · anything needing data we don't have yet.
**EXPLICITLY OUT (do NOT build — would mislead):** waterproofing "expert visit / inspection" flow. That is the SERVICE side, not commerce. In this Unit, waterproofing material sells as an ordinary ready product only. (See F.0.)

### D.1 Catalogue — paint attributes
A paint row needs more than a generic product. Add as Unit attributes (use the existing `product_variations.attributes` JSON / unit_config where possible — avoid new engine columns unless unavoidable):
brand · product_line · tier(economy/mid/premium) · product_name · category(7 families: interior-emulsion, exterior-emulsion, enamel, distemper, primer, putty, waterproofing) · surface(interior/exterior/wood/metal) · finish(matt/sheen/gloss/satin) · pack_size + pack_unit · coverage_value + coverage_unit + coverage_basis_coats + recommended_coats · warranty_years + warranty_reference · shade_mode(ready/tintable) · custom_tintable(yes/no) · base_type(for tintable) · shade_name + shade_code(for tintable) · recommended_primer + recommended_putty · search_keywords · source_type + source_verified_at · returnable(yes/no) · price(seller base) · quantity(stock).
**Seller fills:** brand, product_line, pack, their base price, warranty, description/keywords, quantity. **System/CEO sets/verifies:** coverage (labelled "estimated"), margin, final price, live-status.
**Data-quality rules (enforce, from research):** manufacturer coverage shown as "Manufacturer-stated", WorkToGo calc shown as "Estimated requirement"; never multiply a 2-coat coverage again; warranty stored per-SKU not per-brand; seller stock/price separate from catalogue; surface factors are WorkToGo estimates, labelled so; keywords are metadata, not claims.

### D.2 Two product paths (the heart of the Unit)
**Path A — Ready products (FIRST, build fully):** sealed/standard goods — emulsion, enamel, distemper, primer, putty, waterproofing MATERIAL, and tools (roller, brush, tray, scraper, putty knife, sandpaper, masking tape, drop sheet, wire brush, stirrer, strainer, thinner where specified, texture tools, spray, PPE). Instant price, COD-able, returnable if sealed, low risk. **This is the first revenue path.**
**Path B — Custom tint (structure now, data later):** customer picks brand → product → pack → **sees only the bucket** (NOT base/bisan) → chooses White or a shade. For a shade, a window opens → customer enters the **shade code** → system captures it and sends to the seller with the **base + litres** needed. Customer never sees base complexity; the seller gets the full instruction. Rules:
- **Advance required** (50% or full, configurable) before the order submits — custom tint can't be returned (dead stock).
- **Code is the contract:** wrong code shipped = seller's fault (remake); correct code but "looks different on screen" = NOT the seller's fault (screen isn't proof). Show "screen colours are indicative".
- **New vs repaint toggle at order time:** new/whole job → minor shade adjustment allowed (seller's judgement, e.g. made on white base); repaint/match (part of a wall already painted) → **exact only, same base, same seller** — no adjustment, because a small difference shows on the wall.
- **Repeat → same seller:** system remembers "this shade for this Customer ID was made by this seller"; a repeat routes to that seller first (so the shade matches); if unavailable, another seller with a "slight difference possible" note.
- **Wrong-brand/code guard:** the customer picks the brand/bucket first, so a code only matches within that brand; a code not found in that brand is rejected ("this code isn't in this brand"). (Customer may not know Birla's code can't go in a Berger bucket — the flow prevents it.)
- **Optional confirm-call** (admin on/off, for custom/high-value only): team calls to confirm shade/bucket, logs a note, order proceeds. An ops touch (not founder labour), only on custom/large; removable once trust builds.
- **DATA-EMPTY until Prisma:** build the code field + match + routing + advance NOW; the shade/base/code table fills from the Prisma Pro export (Section G). Until then, custom tint stays OFF in config; ready products run.

### D.3 Coverage calculator + customer guide (the moat)
From research (F.2, F.3): area/room → litres (ALWAYS labelled "Estimated requirement — varies with surface/shade/application"); "new wall = clean → putty → primer → emulsion" guidance; situation guide (interior/exterior/wood-metal/damp). This is what makes a confused customer order from us instead of visiting 10 shops. Build the calculator + guide into the customer app.

### D.4 Verification, stock, ops (wire paint into existing core)
- **Product verification gate:** new product/seller → "pending verification" → CEO/team approves → then live. Prevents junk/wrong-warranty/wrong-price. Trusted sellers → auto-approve later. Uses existing admin approve flow.
- **Catalogue once, then quantity:** seller enters catalogue once; on restock just updates **quantity** per pack; system tracks stock as real state (S2).
- **Seller "stock not available" button:** on an accepted order, if the item isn't actually there, seller taps it → system reroutes to next seller + records the signal (reliability). Uses existing reject/reroute (S3).
- **"Nothing available near you" honest state** when range/stock is empty (S8) — no false promise, no blank screen.

---

## E. BUILD ORDER (plan these; same rhythm as S1–S11)
```
P0  PAPER SIMULATION + architecture test + reuse map + gap list  (NO CODE — plan output)
P1  CORE tweak: slab margin + growth-mode knob + loss-proof (upgrade flat-% config)   [C.1, C.2]
P2  CORE tweak: delivery quote + absorb rules + no-flicker server price + rate cache   [C.3]
P3  Paint catalogue attributes + unit_config(paint) + seller catalogue entry + verification gate  [D.1, D.4]
P4  Coverage calculator + customer guide                                               [D.3]
P5  Customer ID system (seller-facing history, no PII)                                 [C.4]
P6  READY-PRODUCTS full sell flow live end-to-end on the Unit  — FIRST MONEY PATH      [D.2 Path A]
P7  Custom-tint STRUCTURE (code field, code→seller, base attr, advance, no-return,
    repeat→same-seller, new-vs-repaint, brand-code guard, confirm-call) — DATA-EMPTY   [D.2 Path B]
P8  Prisma Pro data import → fill shade/base/code → enable custom-tint  (AFTER export)  [Section G]
GATE: staging — real paint orders (ready + custom) run founder-free → Unit proven.
```
Each step: build on existing code, one commit, local test proof, PASS/FAIL + hash + push, CEO PASS before next. P8 waits for the CEO's Prisma export. (You may re-order within reason and recommend a better sequence — say so in the plan.)

---

## F. DISTILLED RESEARCH (use this; do NOT go hunting — the useful facts are here)

### F.0 What to IGNORE from general paint research (so you don't drift)
- **"Waterproofing + expert visit / surface inspection" flow — IGNORE.** That is service, not commerce. Waterproofing material sells here as an ordinary ready product. Building an inspection flow would mislead the Unit.
- **"Haldwani #1 selling / demand ranking" — IGNORE as fact.** No verified local sales data exists. Any ranking is a launch-priority guess, not measured demand. Don't encode it as truth.
- **Exact tint formulas / per-shade coverage numbers — DON'T invent.** Only the Prisma export (real) and the CEO's shop experience are authoritative for shade/base. Research coverage is "estimated", always labelled.
- Don't bulk-load a full 5-brand thousands-SKU catalogue. Start with Berger + Indigo top SKUs (economy+mid+premium).

### F.1 Catalogue thinking
7 commercial families: Interior Emulsion · Exterior Emulsion · Enamel · Distemper · Primer/Undercoat · Putty/Filler · Waterproofing. Under each: brand → product line → product → pack → finish → shade/base → coverage → seller offer. **Product ≠ Seller Offer:** one product (e.g. "Berger Easy Clean 4L") can have several seller offers (Seller A ₹X, Seller B ₹Y, own store ₹Z); customer wants the product, WorkToGo picks the best eligible source. (Core already supports this via `match_key` — finding #4.)

### F.2 Coverage calculator — app-ready formula
1) Wall Area: direct sq-ft if given; from room = 2 × (L + W) × H.
2) Net Area = Wall Area − doors − windows − other openings.
3) If manufacturer coverage is "X sq.ft/L for 2 coats": Base Litres = Net Area ÷ X. (Do NOT multiply by 2 again.)
4) If per-coat: Base Litres = (Net Area × required coats) ÷ coverage-per-L-per-coat.
5) Surface factor (WorkToGo estimate, labelled): Smooth 1.00 · Normal 1.10 · Rough/porous 1.20.
6) Dark shade: add one coat only when product/shade guidance says so — no universal dark-shade promise.
7) Adjusted Litres = Base × Surface Factor, round up to available packs. Display: "Estimated requirement ~N L. Actual consumption varies with surface condition, application method and shade."
Worked examples (verified brand coverage): Berger Easy Clean 120–130 sq.ft/L (2 coats) → 400 sq.ft ÷ 125 × 1.10 ≈ 3.5L → 4L pack. Indigo Exterior Emulsion Gold 6–7 sqm/L (2 coats) ≈ 70 sq.ft/L → 395 sq.ft ÷ 70 × 1.10 ≈ 6.2L.

### F.3 Customer guide
New interior wall: clean/scrape → wall putty (level) → primer/sealer → interior emulsion → 2–3 coats where specified. Old/repaint: sound coating → clean + spot repair + primer + topcoat (full putty not always needed); loose/peeling → remove → repair → primer → topcoat; nail holes/cracks → putty where needed; damp/efflorescence → treat moisture first, compatible system after (normal emulsion is NOT a damp cure). Situation: interior wall → interior emulsion; exterior → exterior emulsion; wood/metal → enamel/PU enamel; rough/new → putty+primer+topcoat; damp-prone → compatible waterproofing system + topcoat.

### F.4 Berger — launch-priority catalogue (WorkToGo priority, not measured demand)
Economy: Bison Acrylic Emulsion (interior/value; numeric coverage verify PDS). Mid: Easy Clean (washable; 120–130 sq.ft/L 2 coats; ~6y warranty). Premium: Silk Glamor Matt/Soft/Sheen/Dazzle (150–170 sq.ft/L 2 coats; 7–8y). Distemper: Bison Acrylic Distemper / Bison Plus. Exterior: WeatherCoat Anti-Dustt (6y; 2-coat; darker shades cover less). Enamel: Luxol PU / Hi-Gloss / Satin (wood/metal). Putty: HomeShield Waterproof Putty (white cement-based).
Keywords (metadata, not claims): Easy Clean → "Berger Easy Clean, washable paint, stain resistant, interior emulsion"; Silk Glamor → "luxury interior paint, washable luxury, silk emulsion, premium wall paint"; WeatherCoat → "exterior paint, anti dust, weatherproof"; Luxol PU → "PU enamel, wood paint, metal paint, glossy enamel".

### F.5 Indigo — launch-priority catalogue
Economy: Interior Emulsion Silver (1/4/10/20L; 4y). Mid: Premium Interior Emulsion Gold (6y); Premium Interior Sheen Gold (6y). Premium: Luxury Interior Emulsion Platinum (8y). Exterior: Exterior Emulsion Gold (500ml/1/4/10/20L; 4y; 6–7 sqm/L 2 coats); Premium XT Exterior (6y). Acrylic Laminate interior/exterior (8y). Enamel: PU Super Gloss (wide packs; 17–19 sqm/L 1 coat or 8–9 sqm/L 2 coats). Putty: Waterproof Polymer Putty (20/40kg). Primer: Water Thinnable Sealer Primer (5y).
**Indigo base concept (important for custom-tint attribute design):** Indigo uses White / Medium / Deep / Neutral bases — light/pastel shades from white base, dark/brilliant from deeper/neutral bases. This is brand-specific, so store `base_type` as a brand/product attribute, NOT a universal rule.

### F.6 Shade code + base (for custom-tint structure)
Brands give each shade a code in their fandeck (e.g. Nerolac "Sweet Jasmine — 2003"). Store brand + product + shade_name + shade_code + base_type + finish — never just "Blue". Base types (general): White (pastels) · Medium · Deep · Neutral (very deep/vibrant). Make base_type a brand/product-specific attribute. Custom-tinted paint is normally non-returnable (made to order, hard to resell) — confirm with a checkbox "I understand this is a custom shade" + advance before order.

### F.7 Warranty
Product-specific, store per-SKU (warranty_years + warranty_reference), never one blanket brand warranty. Examples: Berger Silk Glamor 7–8y, Easy Clean 6–7y, WeatherCoat 6y; Indigo Silver 4y, Gold 6y, Platinum 8y, Exterior Gold 4y, Sealer Primer 5y. Verify exact terms before showing a strong promise.

---

## G. WHAT THE CEO PROVIDES (not buildable by Claude Code)
1. **Prisma Pro export** — the paint shop's tinting software holds real shade/base/code data. The CEO will look for Export/Report/Backup in it and share the file → fills custom-tint (P8). (If no export, custom-tint stays off; ready products run regardless.)
2. **Coverage verify** — research gives estimates; the CEO's shop experience confirms/corrects.
3. **Berger + Indigo catalogue data + seller prices** — entered live after staging passes.
4. **Hosting with MariaDB ≥10.5 + Cashfree sandbox creds** — the engine's launch-blockers; the Unit's staging gate needs them too.

---

## H. CARRY-OVER FINDINGS FROM THE ENGINE (keep in the report; close at the staging gate)
1. MariaDB **≥10.5** (10.4 rejected 2 migrations + `SKIP LOCKED`). 2. collation consistency (prod unicode_ci vs local general_ci). 3. fresh-DB seed (`2026_06_14_001` hardcodes vendor 11). 4. `products.sku` UNIQUE → `match_key` is the cross-seller key (full canonical catalogue deferred). 5. Cashfree sandbox ₹1 live round-trip (needs test creds). 6. ledger namespace shim (alias in bootstrap or add `$db` param).

---

## I. CEO'S PRINCIPLES (decision frame)
Zero code dependency (admin controls everything) · manual first, automate later · 30-second rule · one admin = one city · system thinks, admin approves · every customer a long-term asset · every vendor a long-term partner · launch over perfection · revenue first · evidence over assumptions. And: the CEO wants you to **think like a real CTO partner — predict problems, challenge what's wrong, give your own recommendation**, not just agree.

---

*End of pack. First action: save it, then produce the P0 paper simulation + full Paint Unit plan + your recommendations. NO code until the CEO approves the plan.*
