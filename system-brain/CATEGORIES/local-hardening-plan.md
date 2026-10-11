# WorkToGo — LOCAL HARDENING (MariaDB ≥10.5) + KYC GAP + SCALE/STRESS

**Date:** 2026-10-10 · **Branch:** `feat/commerce-core` · **Goal:** lock down everything lockable **locally** (on a real MariaDB ≥10.5) before paying for hosting. **Verify-only; product code unchanged.**

---

## I. SET UP A REAL MariaDB ≥10.5 LOCALLY (you run this)

XAMPP ships MariaDB **10.4** (too old — rejects `SKIP LOCKED` + `COMMENT`-after-`CHECK`). Install a real **10.11 LTS** (or 11.4) **alongside** XAMPP on port **3307** so nothing existing breaks. Two options — pick ONE.

### Option A — Standalone MariaDB MSI (recommended, no Docker)
1. **Download:** https://mariadb.org/download/ → pick **MariaDB Server 10.11 (LTS)**, OS **Windows**, arch **x86_64**, package **MSI**. (~70 MB.)
2. **Install:** run the MSI → set a **root password** (write it down) → **"Use UTF8 as default"** = yes → **port = 3307** (not 3306, so XAMPP's 10.4 stays untouched) → install as a **service** (e.g. `MariaDB`). Finish. (The MSI also installs **HeidiSQL** — a GUI you can use instead of the CLI.)
3. **Verify it's up:**
   ```bash
   "C:/Program Files/MariaDB 10.11/bin/mysql.exe" -u root -p -P 3307 -e "SELECT VERSION();"
   ```
   Expect `10.11.x-MariaDB`.

### Option B — Docker (if you prefer containers)
```bash
docker run --name wtg-mariadb -e MARIADB_ROOT_PASSWORD=wtgroot -p 3307:3306 -v wtgdata:/var/lib/mysql -d mariadb:10.11
docker exec -it wtg-mariadb mariadb -uroot -pwtgroot -e "SELECT VERSION();"
```

### Build the DB on the new server (either option)
Use **prod-parity collation** `utf8mb4_unicode_ci` — this is exactly what confirms finding B3.
```bash
MY="C:/Program Files/MariaDB 10.11/bin/mysql.exe"   # or: docker exec -i wtg-mariadb mariadb
"$MY" -u root -p -P 3307 -e "CREATE DATABASE IF NOT EXISTS worktogo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"$MY" -u root -p -P 3307 worktogo < database-structure.sql
```

### Point the app at it (edit `.env` in the repo root — local only, gitignored)
```
DB_HOST=127.0.0.1
DB_PORT=3307
DB_NAME=worktogo
DB_USER=root
DB_PASS=<the root password you set>
MIGRATE_SECRET=local_dev_migrate_8f3a2c9d1b6e4f7a0c5d
```
> ✅ Verified: `config/database.php` reads exactly these keys — `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` (via `getenv()`/`$_ENV`). The `.env` loader in each test bootstrap and `migrate.php` reads the repo-root `.env`.

### Migrate **RAW** (this is finding **B2**) + verify
```bash
php migrate.php local_dev_migrate_8f3a2c9d1b6e4f7a0c5d
```
On 10.11 the migrations `005` + `009` apply **without any perl patch** → **B2 PASS** if it completes clean.

---

## II. ≥10.5 LOCAL RUN — RESULTS (RAN 2026-10-11 on MariaDB **10.11.19**, port 3307) ✅
Server installed + running; DB built; **449 regression PASS / 0 FAIL / 0 errored**, **e2e 22/22 (no SKIP LOCKED line)**, **stress 10/10**.

| Item | Result | Detail |
|---|---|---|
| **B1** SKIP LOCKED / rider re-pick | ✅ **PASS** | the `[DeliveryEngine::assignNearest] … SKIP LOCKED` error that appears on 10.4 is **gone** on 10.11; gated accept dispatches; reroute 10/10. |
| **B2** COMMENT-after-CHECK migrations | ⚠️ **NOT fixed by ≥10.5 — reclassified CRITICAL** | `2026_07_04_005` + `2026_07_04_009` put `COMMENT` **after** `CHECK(json_valid())`; **10.11 rejects it too** (it's malformed clause-order, not a version gap). Needs a 1-line-each migration fix (move COMMENT before CHECK). For this run I transiently stripped + `git`-reverted to prove the rest. |
| **B3** collation | ⚠️ **runs clean only when standardized** | base schema = `unicode_ci`, commerce migrations default to `general_ci` (`DEFAULT CHARSET=utf8mb4`) → they clash on 10.11. Verified **449 green once the DB is one collation** (I standardized to `general_ci`). Prod needs a single documented collation standard (and ideally fixing the migration `CHARSET` clauses). |
| **B4** fresh-DB seed | ✅ handled | `2026_06_14_001_seed_initial_services` still FK-fails on a fresh DB (vendor 11) → mark-skip, as documented. |
| **STRICT mode** (new) | ✅ engine strict-safe | 10.11 runs `STRICT_TRANS_TABLES`; it caught invalid `role='driver'` (enum is `'delivery'`) in 5 **old test fixtures** (10.4 silently truncated) — fixed (test-only). The **engine** passed all 449/22/10 under strict, so product code is strict-safe. |

**Re-run sequence (anytime):**
```bash
for t in tests/commerce/s*_vectors.php; do php "$t"; done   # 449 PASS, 0 FAIL
php tests/commerce/verify_engine_e2e.php                     # 22/22, no SKIP LOCKED  (B1)
php tests/commerce/verify_scale_stress.php                   # 10/10
```
> **Two required code fixes surfaced (deferred to CEO approval — not product/engine code):** (1) **B2** — fix the COMMENT/CHECK order in migrations `2026_07_04_005` + `_009` so a fresh DB migrates cleanly anywhere (incl. prod); (2) **B3** — standardize one collation across the schema (recommend `utf8mb4_unicode_ci`; drop the `DEFAULT CHARSET=utf8mb4` from commerce migration `CREATE TABLE`s so they inherit the DB default). Both are tiny + additive.

---

## III. SCALE / STRESS — harness ready (`verify_scale_stress.php`), 10.4 PREVIEW already PASS
Runs on any version; re-run on 10.11 for the final record.
| Check | What | 10.4 preview result |
|---|---|---|
| Bulk throughput | create + route **50**, then **500** orders | ✅ 500 routed in ~4.5s (~111/s), all offered |
| Multi-seller same product | 3 sellers, prices 90/100/110 → which source | ✅ deterministic — **see finding F1 below** |
| Last-stock gate | 1 unit → reserve it → next blocked | ✅ no oversell at the gate |
| Reroute under many | 10 rejects → next seller | ✅ 10/10 rerouted |

**Still needs a real load tool / HTTP on staging (not CLI-testable):**
- True **parallel** oversell race on the same last unit (OrderService `FOR UPDATE` lock — enforced by the engine + MariaDB; prove with a concurrent load tool on ≥10.5).
- **Repeated login/logout** token/session + **gate/password under load** + **timeout→reroute under load** — these are HTTP/JWT-layer and belong to a load test (e.g. k6/JMeter) against staging. The commerce-layer invariants they depend on (reroute, blacklist) are unit-proven; the concurrency behaviour is a staging check.

### 🔎 F1 (new finding) — ROUTING IS NOT PRICE-AWARE
For the same product at 3 sellers (base ₹90 / ₹100 / ₹110), the router chose the **₹110 one** because it ranks **company-first → nearest → priority** (S3) — **price is not a factor.** The CEO asked "cheapest/best source?" → today the answer is *nearest/priority, not cheapest*. **Decision needed:** should routing prefer the cheapest eligible base (better customer price / better margin)? It's an additive config/knob in `RoutingEngine::rank` — **not built; flagging for your call** (could be a small step, e.g. S-routing-price or folded into a Unit).

---

## IV. KYC / ONBOARDING GAP AUDIT (read-only — done now) → **S18 scope**

**What exists:** phone-OTP + password auth (`/api/auth/register` + `send-otp`/`verify-otp`/`logout`/`refresh`), `users` (name, phone, email, password, role, `phone_verified_at`, avatar). Register accepts roles **customer / vendor_service / vendor_shopping** and, for a vendor, creates a `vendors` row as **`status='pending'`** (admin approve gate). Login works.

### SELLER (vendor_shopping) — can register + login, but **KYC is almost entirely missing**
| Field | Status |
|---|---|
| name, phone (OTP), email, password, business_name | ✅ present (register.php) |
| pending→active admin approval gate | ✅ present (`vendors.status`) |
| **shop address + pincode + geo (lat/lng)** | ❌ missing (vendors has lat/lng columns but register never collects them) |
| **bank account + IFSC + UPI (PAYOUT TARGET)** | ❌ **missing — critical**: S9 computes payouts but there is **nowhere to pay** |
| **GST number / PAN** | ❌ missing |
| owner/proprietor name | ❌ missing |
| **shop photo + ID/address proof (document upload)** | ❌ missing (no doc fields; `media` table exists to reuse) |
| product category / store_type at onboard | ❌ missing |
| document-verification workflow (submit→review→approve/reject + reason) | ❌ only a bare status flip |

### RIDER (delivery) — **cannot self-register at all**
| Field | Status |
|---|---|
| self-registration | ❌ **`delivery` role is NOT allowed in `register.php`** — no rider signup path (riders exist only if an admin/seed makes them) |
| `delivery_riders` row creation on signup | ❌ never created by registration |
| phone OTP | ⚠️ users supports it, but no rider register uses it |
| **driving licence / vehicle RC / vehicle type+number** | ❌ missing (delivery_riders has only vehicle_type default 'bike') |
| **bank/UPI (rider PAYOUT target)** | ❌ missing |
| **profile photo / ID proof (upload)** | ❌ missing |
| verification status / approval | ❌ missing |

### → **S18 — Onboarding & KYC layer** (build only when CEO says)
1. Seller KYC: address/pincode/geo, bank+IFSC+UPI (payout), GST/PAN (optional per rules), owner name, shop photo + ID/address proof (reuse `media`), category; submit→review→approve/reject workflow.
2. Rider onboarding: allow `delivery` self-register (or admin-invite) + phone OTP; licence, RC, vehicle type+number, bank/UPI, photo, ID proof; create `delivery_riders` on approval; verification workflow.
3. Shared: a document-upload + verification sub-system + **payout-destination storage** (bank/UPI) that S9 payouts read.
This is **additive** (new columns/tables + endpoints + admin review UI); no engine edit expected.

---

## V. WHAT'S LEFT ONLY ON HOSTING (after all the above)
- **B6** real Cashfree **sandbox ₹1** round-trip (needs creds) · **B7** ledger-namespace alias in the live bootstrap · **B8** advance/partial payment in sandbox · **B9** **~20 real orders founder-free on staging** with a real external seller — the DONE gate.

**Status (2026-10-11):** MariaDB **10.11.19** installed + system runs on it (port 3307). **≥10.5 verification DONE:** B1 ✅ (SKIP LOCKED gone) · B3 ✅ once single-collation · B4 ✅ (seed skip) · STRICT-mode engine-safe ✅ · **B2 ⚠️ reclassified CRITICAL** (COMMENT-after-CHECK not a version gap — needs a migration fix) · regression **449/0**, e2e **22/0**, stress **10/10** on 10.11. KYC gap audited (S18) · F1 routing-not-price-aware raised. **Two tiny deferred fixes surfaced (B2 + B3) — awaiting CEO go.** Left for hosting: Cashfree ₹1 (B6), ledger shim (B7), advance (B8), ~20 founder-free orders (B9).
