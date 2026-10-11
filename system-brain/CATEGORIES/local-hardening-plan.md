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

## II. ≥10.5 VERIFY SEQUENCE (I run these once you say "10.5 is up")
```bash
# B3 collation + whole engine on prod-parity collation:
for t in tests/commerce/s*_vectors.php; do php "$t"; done      # expect 449 PASS, 0 FAIL, no "illegal mix of collations"
php tests/commerce/verify_engine_e2e.php                        # 22/22; check the [4] step shows NO "SKIP LOCKED" line  (= B1)
php tests/commerce/verify_scale_stress.php                      # bulk 50/500, routing, stock-gate, reroute
```
| Item | What confirms it | PASS criterion |
|---|---|---|
| **B1** SKIP LOCKED / rider re-pick | e2e `[4]` + stress `[4]` on 10.11 | no `1064 … SKIP LOCKED` line; gated accept dispatches a rider; reroute re-picks |
| **B2** COMMENT-after-CHECK migrations | `migrate.php` on raw files | 005 + 009 apply unpatched, migrate completes |
| **B3** collation | 449 regression + e2e on `utf8mb4_unicode_ci` DB | all green, zero "illegal mix of collations" |

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

**This turn's status:** setup guide ready (yours to run) · KYC gap audited (S18 scoped) · scale/stress harness ready + 10.4 preview PASS + F1 finding raised. ≥10.5 verify (B1/B2/B3) runs the moment your new server is up.
