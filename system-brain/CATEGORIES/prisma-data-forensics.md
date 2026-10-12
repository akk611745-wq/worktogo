# PrismaPro data — READ-ONLY forensic discovery (Phase 1–3) + report

**Date:** 2026-10-11 · **By:** Claude Code (CTO partner) · **For:** CEO (Aakash)
**Rule honoured:** READ-ONLY. Original never modified, PrismaPro software never run, no DB writes. All inspection done on a **copy** in the session scratchpad via byte-level string extraction (no Firebird engine). **No importer/extract run** — discovery + proof only.

**Source (untouched):** `C:\Users\akk61\Downloads\costom-20261009T064439Z-1-001\costom\PrismaPro\`
**Working copy (scratchpad, disposable):** `…\scratchpad\prisma_copy\Db\{MAIN.FDB, Customer.fdb, History.fdb}` + `\Backup\*` — sizes verified identical to source; source sizes re-checked after copy = unchanged.

---

## A. DB FORMAT — **Firebird** (embedded), proven not assumed
- `MAIN.FDB` / `Customer.fdb` header bytes = `01 00 39 30` → `0x3039 = 12345`, the **Firebird/InterBase header-page magic**. Both files carry it.
- Config `PrismaProCommon.ini` points at `…\Db\Main.fdb`, `Customer.fdb`, `History.fdb` (a legacy `DataBaseFile=Db\PrismaPro.gdb` line also present — the live files are the `.fdb` trio).
- Vendor = **Fast & Fluid Management / PrismaPro** (Corob tinting software). It runs Firebird **embedded** (no server installed here; no `isql`/client/`pdo_firebird`/`python-fdb`/`strings` on this box — confirmed).
- `Backup\*` = `NN-NN-26 - iDM.bak` files, 3,717 bytes each, **XML `iDMSettings`** (dispenser settings) — **NOT** database backups. There is **no `.fbk`/gbak dump** to restore from; the only real data is in `Db\*.fdb`.

## B. EXACT FILE PATHS (source)
| File | Path | Size |
|---|---|---|
| Main formula/colour DB | `…\PrismaPro\Db\MAIN.FDB` | **177,086,464 B (~169 MB)** |
| History (dispense log) | `…\PrismaPro\Db\History.fdb` | 1,884,160 B |
| Customers | `…\PrismaPro\Db\Customer.fdb` | 1,630,208 B |
| App config | `…\PrismaPro\PrismaProCommon.ini` | — (holds an app auto-login; **not reproduced here** — credential) |
| Dispenser backups (XML) | `…\PrismaPro\Backup\NN-NN-26 - iDM.bak` ×5 | 3,717 B each |

## C. APPROX ROW COUNTS (estimate — exact needs a read-only SELECT at extraction)
MAIN.FDB ~169 MB holding, per shade: LAB values + a **32-band spectral reflectance curve** + a multi-component tint **formula** per base/product. From the collections present (RAL classic ≈215, Asian Royale, Berger palette, Dulux enamel, Indigo, Pantone, …) the realistic order is **thousands of distinct shades** and **tens of thousands of `FORMULA_STANDARD_LINES`** (component-dose rows). Treat as an estimate; `SELECT COUNT(*)` per table is a one-liner once a read-only connection exists (Phase F).

## D. TABLES / FIELDS AVAILABLE (from Firebird metadata strings + the INI's own SQL)
Core relational schema (names seen verbatim in the file):
- **COLLECTIONS** (`COLLECTION_ID`, `COLLECTION_NAME`) — the fandecks / colour cards.
- **PRODUCTS** (`PRODUCT_ID`, `PRODUCT_NAME`) — the base paints a formula is for.
- **FORMULAS_STANDARD** (`FORMULA_STANDARD_ID`, `PRODUCT_ID`, `COLLECTION_ID`) + **FORMULA_STANDARD_LINES** — the standard tint formula + its component doses.
- **COMPONENTS** (`COMPONENT_ID`, `COMPONENT_CODE`, `COMPONENT_NAME`, `COMPONENT_AMOUNT`) — colorants; plus `COMPONENT_PREFILLED_CANS`, `COMPONENT_EMPTY_CANS`, `COMPONENT_FILES`.
- **SUBSTRATES** (`SUBSTRATES_ID`) / **BASE** (`FOREIGN_KEY_BASE`) — base/substrate.
- **UNITS** (`UNIT_ID`, `UNIT_NAME`, `UNIT_TYPE`).
- **FORMULAS_OWN / FORMULA_OWN_LINES**, **FORMULAS_ORDER / FORMULA_ORDER_LINES**, **DISPENSED_FORMULAS / DISPENSED_FORMULA_LINES** (history), **CUSTOMERS**.
- Per-shade fields proven by bilingual column labels inside the DB: **"Color code"** (Codice Colore), **"Color names"** (shade name), **"Collection code"**, **"Spectro LAB values"**, **"Reflectance 1…32"**, **"Formula date"**, **"Colorcard status"**, price fields.
Real sample values seen: shade names like *"Too cool for school"*, *"Peepui's Flower"*; RAL codes `RAL 9004/1018/8002/…`.

## E. CAN WE GET (brand, shade_code, shade_name, base_type, finish)?
| Our field | Source in PrismaPro | Status |
|---|---|---|
| **shade_code** | `FORMULAS_STANDARD` "Color code" (RAL number / brand code) | ✅ present |
| **shade_name** | "Color names" | ✅ present |
| **brand** | **derived** from `COLLECTIONS.COLLECTION_NAME` (e.g. "BERGER COLOR PALETTE"→Berger, "ASIAN ROYALE…"→Asian) — no dedicated brand column | ◑ derivable (parse/map) |
| **base_type** | the `PRODUCTS`/`SUBSTRATES` base the formula targets | ◑ present as product/base; our white/medium/deep sense needs a mapping |
| **finish** | **derived** from `PRODUCT_NAME` (e.g. "DULUX ENAMEL"→enamel; emulsion/sheen in names) — no finish column | ◑ derivable (parse) |
**Bonus available (more than we store):** full tint formula (components + doses + units), LAB values, 32-band reflectance. We can ignore these for v1 or keep them later.
**Verdict:** the two identity fields (code, name) are clean; brand + finish are **derivable** from collection/product names via a small mapping; base_type maps from product/base. Nothing is truly *missing* — it needs a light transform, not more data.

## F. SAFEST EXTRACTION METHOD (for the later, approved phase — NOT run now)
1. Work only on the **copy** (never the original).
2. Install a standalone **Firebird client** (2.5/3.0) OR enable PHP `pdo_firebird`; open the copy **read-only** (Firebird "read-only" attach / `isql -r`). No PrismaPro, no writes.
3. DB password: the app auto-login in the INI is **not** the Firebird password; Corob/PrismaPro `.fdb`s are typically `SYSDBA` (try the Corob/`masterkey` default) — determine at extraction.
4. Run the join `FORMULAS_STANDARD ⋈ PRODUCTS (PRODUCT_ID) ⋈ COLLECTIONS (COLLECTION_ID)` selecting Color code, Color names, collection, product/base → write CSV. (The INI even ships this exact join in `StandardFormulaFilter`.)
5. Byte-level string extraction (what this report used) is the pure-read fallback if we must avoid any engine, but a read-only SQL attach on the copy is cleaner and gives exact rows.

## G. BEST OUTPUT — **CSV** (one row per shade)
`brand, collection, shade_code, shade_name, base_type, finish[, lab_l, lab_a, lab_b]`
CSV maps straight onto the P6 importer (it already parses CSV + JSON). JSON is equally fine.

## H. WHICH BRAND(S) — **multiple** (Indian majors + international standards)
Collection names found in the data: **Berger** ("BERGER COLOR PALETTE"), **Asian Paints** ("ASIAN ROYALE SMALL CARD", "COLOR PALETTE ASIAN"), **Dulux** ("DULUX ENAMEL"), **Indigo**, plus **RAL classic** (hundreds of RAL codes) and **Pantone**; faint hits for Nerolac/Nippon/Jotun/Birla. So it is a **multi-brand fandeck**, strongest on RAL + the big Indian brands. (Per-brand shade counts need the extraction SELECT; brand-token frequency here is indicative, not a row count.)

## I. WILL THE P6 IMPORTER RUN ON THIS DATA?
**Yes — with a thin transform step at extraction.** The importer wants `brand, shade_code, shade_name, base_type, finish`. The extraction query produces shade_code + shade_name directly; a small mapping derives **brand** and **finish** from the collection/product names and **base_type** from the product/base. Feed the resulting CSV to `php scripts/import_paint_shades.php <file.csv>` (idempotent, validated) → `--enable` once loaded. No importer/engine change needed; only the extraction+mapping script, which is the next (CEO-approved) step.

---

## Phase evidence (how this was proven, read-only)
- **Format:** header magic `01 00 39 30` on both `.fdb` (Firebird 12345).
- **Schema:** Firebird stores object names as plain text — extracted `FORMULAS_STANDARD`, `PRODUCTS`, `COLLECTIONS`, `COMPONENTS`, `SUBSTRATES`, `UNITS`, `*_LINES`, `*_ID`/`*_NAME` columns; plus the INI's own `StandardFormulaFilter` SQL names the join.
- **Relationship:** `FORMULAS_STANDARD.PRODUCT_ID = PRODUCTS.PRODUCT_ID AND FORMULAS_STANDARD.COLLECTION_ID = COLLECTIONS.COLLECTION_ID` (from the INI, corroborated by the metadata) + `FORMULA_STANDARD_LINES`→`COMPONENTS` for doses. Joinable end to end.
- **Data present:** real collection names (Berger/Asian/Dulux/Indigo/RAL/Pantone), real shade names, "Color code"/"Color names"/"Spectro LAB"/"Reflectance 1–32" field labels, `Rs.`/VAT 18 (Indian shop).

## Open items / honest flags
- **Exact row counts & per-brand coverage** are estimates until a read-only SELECT runs (Phase F).
- **brand/finish are derived**, not stored as columns → extraction needs a small mapping table (collection-name→brand, product-name→finish). Low effort.
- **DB password** for a read-only SQL attach is still unknown (the INI's login is the app, not Firebird).
- The working **copy lives in the session scratchpad (ephemeral)**; the source in Downloads is untouched. Re-copy before the extraction phase if the session has rotated.

**Next (awaiting CEO):** approve Phase F — build the read-only extraction+mapping script → CSV → run the P6 importer → verify → enable custom tint.

---

## Phase F — extraction status (2026-10-11): BLOCKED on a Firebird client (no force)
Approved Phase F. Established the exact engine needed and the blocker — **no data extracted yet, nothing downloaded/installed, original untouched.**
- **DB version decoded (read-only):** `page_size=4096`, `ods_raw=0x800B` → **Firebird ODS 11 = Firebird 2.5 family**. FB 3/4/5 cannot open ODS 11 without an upgrade (that writes) → a **Firebird 2.5 engine** is required.
- **On this machine:** XAMPP PHP 8.2 x64 has `php_pdo_firebird.dll` (the PDO driver) ✅, but the **Firebird client engine (`fbclient.dll`/`fbembed.dll`) is absent** (not next to PHP, not in System32, no Firebird install, PrismaPro program not installed — only the data folder was downloaded). Without that library, **no SQL connection is possible.**
- **Not done (by design):** did not download or install any engine, did not try the DB password, did not touch the original. (`SYSDBA`/`masterkey` will be tried only once a client exists; never forced.)

**Options to unblock (CEO picks):**
- **A — fetch FB 2.5 x64 embedded into a scratchpad folder** (official firebirdsql release; NOT a system install): drop `fbembed→fbclient.dll` + icu + `firebird.msg` beside a throwaway PHP, enable `pdo_firebird`, attach the **copy** read-only, run the join → CSV. Reversible; original untouched. (Needs OK to download a native lib.)
- **B — run my extraction script on the CEO's PC** where PrismaPro/Firebird 2.5 already lives (zero download here); it outputs the CSV, which I then verify + import.
- **C — CEO provides a dump** from PrismaPro's own "Export standard formulas" (`.sdf`) / a gbak `.fbk` of MAIN.FDB → I parse that instead.
- **D — (not recommended)** hand-rolled ODS-11 binary parser — fragile (RLE records, blob text, charsets); not trustworthy for data we'll import.

Recommendation: **A** (fastest, isolated, reversible) or **B** (nothing fetched here). Awaiting the choice before any extraction.

---

## Phase F — RESULT (2026-10-11): extracted read-only, CSV built, **NOT imported**
CEO chose **A**. Fetched the official **Firebird 2.5.9 x64 embedded** engine into an isolated scratchpad folder (no system install), aliased `fbembed.dll`→`fbclient.dll`, enabled `pdo_firebird`, and attached the **COPY** read-only (read-only transaction). Original untouched; nothing written to the repo; importer NOT run.
- **Login:** the tables are owned by DB user **`PRISMAPRO`**; there is a ROLE named `SYSDBA` (that's why the `SYSDBA` login clashed). Embedded FB ignores the password, so connecting as the owner `PRISMAPRO` gives read access — no password was guessed or forced.
- **Counts (exact, live):** COLLECTIONS 36 · PRODUCTS 72 · COLOR_CODES 20,634 · COLOR_NAMES 15,301 · **FORMULAS_STANDARD 500,878** · FORMULA_STANDARD_LINES 2,249,410 · COMPONENTS 444 · **SUBSTRATES 0** · **SPECTRO_LAB 0** · FORMULAS_OWN 0.
- **Join built → CSV** (`brand, collection, shade_code, shade_name, base_product, finish`): **500,878 rows at formula grain; 100% have a code + a name; DISTINCT (brand|shade_code) = 21,117.** CSV is `…\scratchpad\prisma_export\prisma_shades.csv` (~39 MB, ephemeral scratchpad — NOT committed; it is the user's proprietary data).
- **Rows per brand (formula grain):** Berger 132,840 · Asian Paints 82,456 · Dulux/ICI 75,840 · Birla Opus 61,128 · Sherwin Williams 52,165 · Nerolac 50,454 · Nippon 36,792 · RAL 4,876 · IS Shade Card 2,189 · + house lines (Bison, Luxol, Silk Glamart, Pantone 303, Jotun 82, Indigo 4, …).
- **Sample (proves the join):** `Berger | 7P2452 | LILY BUD`, `Asian | 8532 | EASTER LILY`, `Nerolac | 2359P | SPARKLING DEW`, `Nippon | NP PB 1498A | MARABELLE`, `Dulux | 80YR 57/070 | AU NATURAL`, `RAL 8002 | SIGNAL BROWN`, `Jotun | 1135 | SANDY CORAL`, `Butterfly | 2P8644 | B'FLY JASMINE WISP N`.

### Mapping to `commerce_paint_shades` — status
- **shade_code** ✅ (`COLOR_CODES.COLOR_CODE`) · **shade_name** ✅ (`COLOR_NAMES.COLOR_NAME`) · **brand** ✅ (derived from `COLLECTION_NAME`) · **collection** ✅.
- **finish** ◑ derived from `PRODUCT_NAME` — populated only when the base-product name carries a finish word (enamel/emulsion/…); many base names (e.g. "NEO SHAKTI EXTERIOR") don't, so a small **72-product → finish** map is needed to fill it well.
- **base_type** ⚠️ **not in this data** — `SUBSTRATES` and `SPECTRO_LAB` are empty; "base" here = the base-paint **product**, not a white/medium/deep tint base. So our `base_type` (white/medium/deep) can't be filled from this export; store `base_product` instead, or leave base_type blank.

### Grain decision needed before import
The 500,878 rows are **shade × base-product** combos (one shade repeats across many products). For the customer shade catalogue + brand-code guard we want **DISTINCT shades** (`brand, shade_code, shade_name`) ≈ **~21,000 rows**, not all 500K. Options for the CEO: (1) import the **distinct-shade** set (~21K, recommended), (2) import distinct per **collection** (keeps per-brand duplicates of shared codes), or (3) the full formula grain (500K — overkill for the guard).

### Phase F open items
- **finish** needs the 72-product→finish mapping (one-time).
- **base_type** (white/medium/deep) is absent — decide to omit or source elsewhere.
- Confirm the **import grain** (recommend distinct ~21K).
- The 39 MB CSV lives in ephemeral scratchpad; regenerate from the saved read-only scripts (or move to a persistent path) at import time. **Not committed to the repo** (proprietary data).

## Phase F — LIVE IMPORT + ARCHIVE (2026-10-11, CEO-directed) — custom tint still OFF
CEO approved: (1) load the distinct shades live now, (2) archive everything to safe files, (3) drop the rest. Done; **custom tint NOT enabled yet — awaiting CEO after reviewing the sample.** No engine/commerce code change.

**1) LIVE IMPORT (commerce_paint_shades on 10.11) — DONE + VERIFIED**
- Built the import CSV = **DISTINCT shades deduped on (brand, shade_code)** → 21,117 rows (17,598 with a finish, 3,519 blank). Columns: brand, product_line(=collection), shade_code, shade_name, base_type(blank), finish.
- Ran the real P6 importer (`scripts/import_paint_shades.php`, no `--enable`): **inserted 21,115, updated 2, invalid 0**. `commerce_paint_shades` now holds **21,115 rows**.
- Verified live: per-brand — Berger 6,376 · Asian 3,282 · Dulux 2,920 · Birla Opus 2,522 · Sherwin 1,670 · Nerolac 1,589 · Nippon 1,205 · RAL 445 · + house lines. Brand-code guard `CustomTint::brandHasCode`: Berger `7P2452`→FOUND (LILY BUD, emulsion), Asian `8532`→FOUND, Nerolac `2359P`→FOUND, RAL `RAL 8002`→FOUND, bogus→not found. `custom_tint_enabled = false` (still OFF). base_type blank (not in source).

**2) ARCHIVE (one-time, OFFLINE — NOT in repo, NOT live)** at `…\mastar wtg live\PRISMA_ARCHIVE\` (outside the git repo):
- `catalogue/` collections 36 · products 72 (+finish map) · components 444 · color_codes 20,634 · color_names 15,301 · distinct_shades_21k 21,117.
- `formulas/` **formulas_standard 500,878** · **formula_standard_lines 2,249,410** (the tinting recipe: component + dose per formula).
- `prisma_catalogue.zip` (0.48 MB) + `prisma_formulas.zip` (12.5 MB) + `README.txt` (manifest + **IP note**).
- **IP note:** the formula recipes are the paint manufacturers' IP — archive only, NOT for live use/publishing/commercial dispensing without legal advice (e.g. a future WorkToGo tinting machine). The shade catalogue (brand+code+name) is reference metadata; recipes are the sensitive part.

**3) DROPPED (not useful):** 32-band reflectance curves, LAB (empty), SUBSTRATES (empty), dispenser settings, cache, logs.

**Still awaiting CEO:** review the live sample + archive → **approve ENABLE** → I flip `custom_tint_enabled` on (`import --enable` / AdminControlRoom). Nothing else pending. Original PrismaPro untouched; the FB engine + copies live only in the ephemeral scratchpad.
