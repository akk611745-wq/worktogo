# WorkToGo — Service Category Research (India + Haldwani)

> **What this is:** The founder-and-CTO research pass on *which service categories WorkToGo should carry*, and *what shape each one is*, so that the service engine can be treated as **one engine that runs the entire service industry** — every category a mini business-unit that plugs in by config, not by code.
> **Companion:** `CATEGORIES/structure-lock.md` is the engineering half — the **fit-check against the real v2 code** (does the built engine already handle every shape zero-code, and where are the gaps). Read this doc for *what to sell*; read the lock for *what the engine can actually do today*.
> **Grounded in** the real `wtg-v2` code (branch `v2`), the V2 vision (`V2/V2-VISION.md`), the system map (`MAP/system-map.md`) and naming rule (`MAP/naming-and-structure.md`). **READ-ONLY research — no code was written, edited, or deployed.**
> **Date:** 2026-09-08. **Launch context:** SERVICE-FIRST, one city (Haldwani), customers + karigars only. Material/commerce is **deferred** (Section 6 — future, not launch).

---

## 0. The mental model in one screen (read this first)

WorkToGo is not "an app per service." It is **one service engine** with a small number of **shapes**. A category is just **data** (a row + its packages + its visit-price config), poured into one of these shapes. Learn the shapes once and every category is just a fill-in-the-blanks exercise.

**The 5 customer ROUTES (how the job reaches a karigar):**

| Route | Founder's word | What it is | Built in code as |
|---|---|---|---|
| **Quick / Direct** | "near me vendor" | Customer picks a nearby karigar, job goes straight to them. Blinkit-style, self-serve. | `booking_mode = direct_vendor`, `vendor_route = direct_vendor` |
| **Expert Visit** | "I'm not sure, send someone" | Expert comes, assesses, quotes. Confirmed only after (free-or-paid) visit fee. | `booking_mode = inspection`, `visit_type = expert_visit`, then `final_quote_amount` |
| **Managed / Company** | "company, handle it" | General company request (package optional, **no forced payment**) → admin queue → assigned. | `booking_mode = free_lead` → `vendor_route = admin_queue → admin_assigned` |
| **Project / Quote** | "big job, give me a rate" | Large scoped job (full wiring, full-house paint), negotiated. Runs **through Expert Visit today** (visit → `final_quote_amount`). | expert_visit → `final_quote_amount` (no separate milestone flow yet) |
| **Package** | "pick a plan / tell me the rate" | Standard/Premium/Luxury; live estimate from area × rate. Sits inside the Company view. | `packages` table + `bookings.package_id / area_sqft / estimate_amount` |

> Package is really a *pricing surface* that overlays the Managed and Expert routes, not a separate dispatch route — but the founder thinks of it as its own "path," so it's listed here.

**The 5 PRICING STATES (how "kitna lagega" is answered):**

| Pricing state | Customer sees | Built in code as | Status |
|---|---|---|---|
| **Fixed** | "AC service ₹499" | `services.base_price` (a set number) | ✅ built |
| **Package per-unit** | "Painting ₹18/sqft × your area = ₹live" | `packages.rate_per_sqft × bookings.area_sqft → estimate_amount` | ✅ built — **but sqft-only** |
| **Estimate / range** | "roughly ₹2,000–₹4,000, confirmed on visit" | `estimate_amount` (single) / `final_quote_amount` after visit | 🟡 partial (no structured min–max) |
| **Inspection-required** | "expert visits first, then exact price" | expert_visit fee (free/paid per area×category) → `final_quote_amount` | ✅ built |
| **Hourly / gig** | "maid ₹60/hour × 3 hrs" | *(no field)* | ❌ **gap** |

> The lock doc proves each of these against the actual migrations and PHP. The one that is genuinely missing is **hourly/gig**; the one that is narrower than it looks is **per-unit** (it is hard-wired to *square feet* — no per-hour, per-point, per-panel, per-AC-unit).

**The customer always sees SIMPLE.** Under the hood a job may be fixed / package / estimate / visit; the customer only ever sees "kitna lagega" (a number, a range, or "expert aayega") and "ho sakta hai" (yes we do this). All the shape-complexity is admin config, hidden.

---

## 1. Top service categories — India, tuned for Haldwani

Haldwani is a **tier-3 hill-gateway town** (Kumaon, Uttarakhand; ~230k, gateway to Nainital). What that changes vs a metro:

- **Independent houses dominate** (not high-rise flats) → more full-property electrical, plumbing, painting, waterproofing, carpentry work — and more *whole-job* (project) demand, not just apartment call-outs.
- **Hills + heavy monsoon → damp/seepage is a chronic pain** → waterproofing and anti-damp painting punch above their weight.
- **Hard water** → RO/water-purifier and geyser/plumbing scale problems are common.
- **Frequent power cuts** → inverter/battery and electrician demand is steady, not seasonal.
- **Cold winters** → geyser/water-heater install & repair spikes Oct–Feb.
- **Lower AC penetration than the plains** but rising; the town sits on the plains edge so summers are still warm.
- **Gig/domestic-help culture is thinner than a metro** but growing in the newer urban pockets — this is the category to pilot carefully, not lead with.

Demand signal legend: **🔥 High** (steady, everyday) · **📈 Growing** · **🌦 Seasonal** · **🧩 Niche**. *All signals are honest desk estimates — validate in the field before over-investing.*

| # | Category | What it is | Typical jobs | Haldwani demand (estimate) |
|---|---|---|---|---|
| 1 | **Electrician** | Home/shop electrical | Wiring, switchboard, fan/light fit, MCB/inverter point, fault-finding | 🔥 High — power cuts + independent houses |
| 2 | **Plumber** | Water & sanitary | Leak/tap repair, geyser & RO plumbing, tank, bathroom fittings, blockage | 🔥 High — hard water, hill plumbing |
| 3 | **Painting** | Interior/exterior paint | Room repaint, whitewash, texture, exterior, anti-damp coat | 🔥 High (🌦 pre-Diwali & post-monsoon peaks) |
| 4 | **Waterproofing** | Damp/seepage sealing | Terrace/roof, bathroom, wall damp, tank | 🔥 High — **Haldwani's signature pain** (hills+rain); best Company-Service showcase |
| 5 | **Carpenter** | Woodwork | Furniture repair, door/window, modular, polish, assembly | 🔥 High — wood culture, independent homes |
| 6 | **Appliance repair** | White-goods | Fridge, washing machine, microwave, chimney, mixer | 🔥 High, year-round |
| 7 | **Geyser / water heater** | Install + repair | New geyser fit, element/thermostat, descaling | 🔥 High 🌦 (winter Oct–Feb spike) |
| 8 | **RO / water purifier** | Purifier service | Install, filter/membrane change, AMC, leak | 🔥 High — hard water everywhere |
| 9 | **Inverter & battery** | Backup power | Install, battery replace, repair, wiring | 🔥 High — frequent cuts |
| 10 | **CCTV & security** | Surveillance | Camera + DVR install, video door, intercom, config | 📈 Growing-high — rising security demand |
| 11 | **AC service & repair** | Cooling | Service, gas refill, install/uninstall, PCB | 📈 Growing 🌦 (summer) — lower base than plains |
| 12 | **Home deep cleaning** | One-time cleaning | Bathroom, kitchen, full-home, sofa, tank cleaning | 📈 Growing (urban pockets) — **gig-shaped** |
| 13 | **Pest control** | Pest treatment | Cockroach, termite, general spray, AMC | 📈 Moderate — termite real in wood/damp |
| 14 | **Tile / marble / flooring** | Floor & wall | Laying, grouting, polish, repair | 📈 Moderate — construction-linked |
| 15 | **Handyman / general repair** | Small mixed jobs | Mounting, assembly, minor fixes, drilling | 📈 Moderate — **gig-shaped** |
| 16 | **Maid / domestic help (by hour)** | Recurring help | Cleaning-by-hour, cook, dishes | 📈 Emerging — **pure hourly; the stress-test category** |
| 17 | **Packers & movers** | Shifting | Local house/office shift, packing, loading | 📈 Moderate — **project/quote** |
| 18 | **Borewell / submersible pump** | Water source | Pump install/repair, motor, pipe | 🧩 Niche-real — independent houses |
| 19 | **Solar rooftop** | Renewable | Rooftop install, service, subsidy paperwork | 🧩 Emerging — **project/quote** |
| 20 | **Event / tent / light & sound** | Occasions | Wedding/function setup, lights, tent, sound | 🧩 Niche 🌦 (wedding season) — **project** |

> **20 is not a cap.** The point of the structure lock is that a 21st, 30th, 50th category (salon-at-home, laptop repair, gardening, car wash-at-home, tutor…) plugs in the same way. This list is the *demand-ranked starting menu for Haldwani*, not the ceiling.

---

## 2. Per-category structure — mapping each category to the engine

For each category: the **primary route(s)**, the **pricing state**, the **skill level**, whether it is **simple-book vs needs-expert-visit**, and **how the customer sees it simply**. This is the config a category-head would fill in per category.

**Skill legend:** L1 = quick/gig (low training) · L2 = trade skill · L3 = specialist/licensed.

| # | Category | Primary route(s) | Pricing state | Skill | Simple-book vs Expert-visit | Customer sees ("simple") |
|---|---|---|---|---|---|---|
| 1 | Electrician | Quick/Direct; Project (full wiring) | Fixed (call-out) → Estimate; Project via visit | L2 | Simple for repairs; **Expert-visit** for rewiring | "Electrician ₹149 visit / repair" · "Full wiring? expert aayega" |
| 2 | Plumber | Quick/Direct; Project | Fixed → Estimate | L2 | Simple for leaks; Expert-visit for bathroom redo | "Plumber ₹149" · "Bathroom banwana? expert" |
| 3 | Painting | **Company/Package**; Expert-visit | **Package per-sqft** + Estimate | L2 | **Expert-visit** (measure) or self sqft estimate | "₹18/sqft — apne kamre ka area daalein" |
| 4 | Waterproofing | **Company/Expert-visit**; Package | Inspection-required → `final_quote` | L2/L3 | **Expert-visit** (must inspect damp) | "Expert aakar dekhega, phir exact rate" |
| 5 | Carpenter | Quick/Direct; Project | Fixed (small) → Estimate (furniture) | L2 | Simple for repair; Expert-visit for modular | "Carpenter ₹199" · "Almari banwana? quote" |
| 6 | Appliance repair | Quick/Direct | **Fixed** (per-appliance) + parts extra | L2 | Simple-book | "Fridge repair ₹299 visit" |
| 7 | Geyser / water heater | Quick/Direct | **Fixed** (service) / Estimate (install) | L2 | Simple-book | "Geyser service ₹399" |
| 8 | RO / water purifier | Quick/Direct; AMC | **Fixed** (service) / package (AMC) | L1/L2 | Simple-book | "RO service ₹349 · filter extra" |
| 9 | Inverter & battery | Quick/Direct | Fixed (service) / Estimate (install) | L2 | Simple-book | "Inverter check ₹199" |
| 10 | CCTV & security | Company/Package; Expert-visit | **Package** (per-camera) / Estimate | L2 | **Expert-visit** for layout, or per-camera pack | "2 camera setup ₹X" · "expert aayega" |
| 11 | AC service & repair | Quick/Direct | **Fixed** (service) / Estimate (gas/PCB) | L2 | Simple-book | "AC service ₹499" |
| 12 | Home deep cleaning | Quick/Direct; **Package** | **Package per-unit** (per bathroom/BHK) *or* **hourly** | L1 | Simple-book | "2BHK deep clean ₹1,499" |
| 13 | Pest control | Quick/Direct; Package (AMC) | **Fixed** / package (area or BHK) | L2 | Simple-book | "1BHK pest ₹899" |
| 14 | Tile / marble / flooring | Company/Package; Project | **Package per-sqft** + Estimate | L2/L3 | **Expert-visit** (measure) | "₹X/sqft laying" |
| 15 | Handyman | **Quick/Direct** | Fixed (per-task) *or* **hourly** | L1 | Simple-book | "Handyman ₹199/visit" |
| 16 | Maid / domestic (by hour) | Quick/Direct; recurring | **Hourly / gig** ⛔ | L1 | Simple-book | "Maid ₹60/hour" |
| 17 | Packers & movers | **Project/Quote**; Expert-visit | **Estimate / quote** (visit) | L1/L2 | **Expert-visit** (survey goods) | "Ghar shift? survey karke rate" |
| 18 | Borewell / pump | Quick/Direct; Project | Fixed (service) / Estimate (install) | L2/L3 | Expert-visit for install | "Motor repair ₹399" |
| 19 | Solar rooftop | **Project/Quote**; Expert-visit | **Estimate / quote** (visit) | L3 | **Expert-visit** (site survey) | "Site dekh kar plan + quote" |
| 20 | Event / tent | **Project/Quote** | **Estimate / quote** | L1/L2 | Expert-visit / call | "Function? hum plan kar denge" |

### 2.1 Four worked examples (the shapes, concretely)

**A. AC service — `Fixed` + `Quick/Direct` (the fastest first-rupee shape).**
Admin sets a service row `AC Service`, `base_price = 499`, category `AC`. Customer opens "Near Me Vendors" → AC → picks karigar → books. `booking_mode = direct_vendor`. No visit, no quote, no admin in the loop. Customer sees exactly "AC service ₹499." **This is pehla-paisa simplicity — no new code, one row.**

**B. Painting — `Package per-sqft` + `Company view`.**
Admin creates category `Painting`, then packages: *Standard* `rate_per_sqft = 12`, *Premium* `18`, *Luxury* `28` (all in the `packages` table, admin CRUD `POST /api/admin/categories/{id}/packages`). Customer opens "Get Company Service" → Painting → ticks *Premium*, types `area_sqft = 500` → live estimate `estimate_amount = 9,000` server-recomputed → submits (or submits **without** ticking = general request, no payment). Customer sees "₹18/sqft, aapka ghar ≈ ₹9,000." **Built end-to-end today.**

**C. Full house wiring — `Project` via `Expert Visit → quote`.**
No fixed price is honest here. Admin marks Electrical's expert-visit config (free or ₹199, per area×category via `expert_visit_pricing`). Customer picks Company Service → Expert Visit → pays/free-confirms → `booking_mode = inspection`, `visit_type = expert_visit`. Expert visits, enters the real number into `final_quote_amount`. Customer sees "expert aayega, phir exact rate." **Built — minus a structured milestone/progress-payment schedule (documented as a small gap in the lock).**

**D. Cleaning by the hour — `Hourly/gig` ⛔ (the one that does NOT fit today).**
Maid ₹60/hour × 3 hours = ₹180. There is **no hourly field** in the schema — no `rate_per_hour`, no `hours`/`units` on a booking. Today you can only fake it as a **fixed package** ("2BHK deep clean ₹1,499") or a flat service price. **True metered hourly is the single genuine structural gap** (see lock §3 and §6). Launch recommendation: run cleaning/handyman as **fixed packages**, defer true hourly.

---

## 3. What "simple for the customer" means (design rule to lock)

Whatever the internal shape, the customer surface collapses to one of three sentences:

1. **A number** — "AC service ₹499" (Fixed / Package flat).
2. **A rate they compute** — "₹18/sqft, your room ≈ ₹9,000" (Package per-sqft, live estimate).
3. **"Expert will come"** — "expert aayega, phir exact rate" (Inspection / Project).

Ticking a package is **always optional** and **never forces payment** (V2-VISION §2.2): a package is *an offer to consider*, not a gate. The customer must never fear a hidden "extra charge." This is what makes each category feel like walking into a well-run company, not filling a form — and it is already the built behaviour (`free_lead` bookings carry no payment).

---

## 4. Haldwani launch scope — "pehla paisa" first

Launch **small, fixed/gig, and direct** — the categories that reach the first rupee fastest with the least ops load — and use **one or two Company-Service showcases** to prove the premium path. Everything else is phase-2+.

### 4.1 Launch-first (Wave 1 — go live with these)

**Shape:** Quick/Direct + Fixed price + optional free-lead. Highest frequency, lowest complexity, no expert-visit dependency, karigar-and-customer only.

| Category | Route | Pricing | Why first |
|---|---|---|---|
| Electrician | Quick/Direct | Fixed call-out | Everyday, high volume |
| Plumber | Quick/Direct | Fixed call-out | Everyday, high volume |
| AC service | Quick/Direct | Fixed | Clean fixed price, seasonal push |
| Appliance repair | Quick/Direct | Fixed | Clean fixed price |
| Geyser / water heater | Quick/Direct | Fixed | Winter demand, simple |
| RO / water purifier | Quick/Direct | Fixed | Hard-water everyday |
| Carpenter (small jobs) | Quick/Direct | Fixed | High frequency (repairs) |

### 4.2 Launch showcase (Wave 1 — the Company-Service proof)

**Shape:** Company view + Package-per-sqft + Expert-visit. Proves the premium, managed path with Haldwani's strongest premium demand.

| Category | Route | Pricing | Why the showcase |
|---|---|---|---|
| **Painting** | Company / Package | Package per-sqft (live estimate) | Best per-sqft demo; high ticket |
| **Waterproofing** | Company / Expert-visit | Inspection → quote | Haldwani's signature pain; must-inspect = perfect expert-visit story |

### 4.3 Wave 2 (weeks after launch, once ops is steady)

CCTV (per-camera packages), Inverter/battery, Pest control (BHK packages), Tile/flooring (per-sqft), Handyman (fixed tasks), Home deep cleaning (**as fixed packages, not hourly**).

### 4.4 Wave 3 / deferred

Maid-by-hour (**blocked on the hourly gap — do not launch as true hourly**), Packers & movers (project/quote flow needs the milestone piece), Solar & Event (project/quote, low frequency), Borewell (niche).

> **The launch is honestly small:** ~7 direct fixed-price categories + 2 company showcases. That is enough to earn the first rupee, prove both speed modes, and validate demand per category before widening. Resist launching all 20 on day one.

---

## 5. Category = business-unit (research view)

The founder wants each category to feel like **its own mini-company with a category-head**. Good news: the *data* already clusters that way — a category owns its packages, its expert-visit pricing, its vendors (`vendor_categories`), and its bookings, all keyed by `category_id`. So a "Painting business-unit dashboard" is **assemblable today** from existing tables.

What is **not** yet modelled is the *ownership/org* layer (a `category_head`, per-category staff permissions, per-category P&L rollup). That is the subject of `structure-lock.md §4` — the recommended business-unit model — and one of the honest 2–3% gaps (§6 there). **Important lock:** a category is **not** a new engine and must **not** get its own `body/<x>-engine/` folder — it is *config inside the one service engine*. Only a fundamentally different business *shape* (tuition, rental, laundry-logistics) ever becomes a new engine, per `MAP/naming-and-structure.md §2`.

---

## 6. FUTURE — material / commerce / warehouse / delivery (NOT launch scope)

> **Clearly marked FUTURE.** For the Haldwani service launch, **material = "customer brings it / buys from the shop themselves."** The full material-order → delivery → warehouse flow is documented here **for direction only**; it is being built in parallel and plugged in later. None of it is launch scope, and none of it blocks the categories above.

- **Today (launch):** the karigar and customer sort material informally — customer purchases paint/pipe/parts, or the karigar brings and the customer pays them directly. WorkToGo records the **service** side only.
- **Future connect:** the **Commerce Engine** (code: `shopping`, `body/shopping-engine/`, built but dormant behind `SERVICE_ONLY_MODE`) and the **Delivery Engine** (`core/delivery/`) re-admit material as products, cart, multi-vendor order, and rider delivery — the same `{intent}` pipeline, no rewrite (V2-VISION §8, `MAP/system-map.md`). A future category could then offer **"with material" vs "labour only"** as a toggle, material priced from the product catalog and delivered.
- **Warehouse / inventory** (stock, SKUs, procurement) is a further-future commerce concern, not a service-engine concern. It lives entirely in the deferred commerce track.
- **Design guardrail (already honoured):** the service schema keeps `city_id` on every domain table and everything routes through the Heart, so commerce/delivery reconnect without touching the service categories. Material is a **plug-in later**, exactly as the founder framed it.

---

## 7. Honest caveats

- All Haldwani demand signals are **desk estimates** from the town's known character (hill-gateway, independent houses, hard water, power cuts, monsoon damp). **Validate in the field** — a week of real requests per area will re-rank this list faster than any analysis.
- The category *menu* is a business choice; the *engine's ability to carry each shape* is an engineering fact — proven in `structure-lock.md`. Where the two disagree (hourly/gig, non-sqft units), the lock wins and the category waits.

*Companion to `CATEGORIES/structure-lock.md`. Research/documentation only — no code changed.*
