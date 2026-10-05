# WorkToGo — Painting + Waterproofing: Launch-Readiness STATUS (what's now wired)

> **What this is:** the companion to `painting-launch-audit.md` (the 2026-10-04 ≈60 % read-only audit). This records the **fix-round actually built** on branch `v2` to make a **one-city Painting + Waterproofing unit launchable** on the founder's model: **company self-fulfils day-1 (no vendors needed, full margin) · vendors as backup · COD/manual payment · everything recorded, managed from admin with no developer.**
> **Scope held:** Painting + Waterproofing only, on the existing spine. **Not built** (deferred by design, see §5): company-project quote-negotiation/milestones, online job-payment gateway, material/commerce, other categories.
> **Local only — branch `v2`, nothing pushed/deployed.** No PHP runtime locally, so the migration is **staged, not run**, and runtime proof is a staging step (§6). **Date:** 2026-10-05.

---

## 1. STATUS — one line
The launch model now runs **end-to-end in code**: customer books Painting/Waterproofing → admin sees it → admin assigns **the company's own worker OR a vendor** → admin records the **price** and **COD payment** → completes → the ledger books the money **correctly** (company job = 100 % company margin; vendor job = normal commission split) → customer & admin see status + amount. The four P0 audit blockers (PB-1…PB-3 + the reusability R-1) are closed in code; PB-4 is ops (staging = prod dump). **Staging-ready for a Painting sign-off: YES** once the migration is applied on staging and the §6 sequence passes.

## 2. WHAT'S NOW LAUNCH-READY (painting/waterproofing, end-to-end)
- **Customer → booking:** Company View → packages + server-recomputed sqft estimate → request saved (already worked). Package card CTA fixed ("See packages & live estimate →" instead of the false "Packages arriving soon").
- **Booking record:** complete + admin-visible (already worked), now also carries **who fulfilled it** (`fulfilment_type` company/vendor + `company_worker_name`) and **how cash was collected** (`payment_collected_by` / `payment_collected_at`).
- **Company self-fulfilment (the day-1 no-vendor case):** admin can assign a booking to the **company's own worker** — no registered vendor, `vendor_id` NULL — and on completion the ledger books the **whole amount as company revenue (full margin, no commission)**. See §3.
- **Vendor backup:** admin assigns a vendor exactly as before; the vendor now reaches a **working jobs screen** (the dead landing tab was routed to `vendor/bookings.php`) to accept/reject/start/complete; the ledger does the **normal commission split**; earnings recorded.
- **Price + COD money:** admin **"Set final amount"** writes `total` (+ `final_quote_amount`) — the ₹0 bug is gone; the vendor/customer now see the real amount. Admin **"Record payment"** marks COD **collected/pending** with who collected and when.
- **Status lifecycle:** request → **assigned (company or vendor, now sets `status=confirmed`)** → in progress → completed → **paid (COD)** — each step recorded and visible to customer (tracking advances off "Requested") and admin.
- **Admin control, no developer:** assign (company-worker **or** vendor), set amount, record COD payment, force-complete/cancel on the **right** job (PB-3 fixed), all from the Operations Board request detail.
- **Reusability (zero-code next category):** creating a **service category** now auto-creates a company-owned **anchor service** (`vendor_id` NULL), so the next category is bookable without a developer and without being tied to a specific vendor (R-1).

## 3. COMPANY-SELF-FULFILMENT — how it works now (the key addition)
1. **Represent it:** new nullable `bookings.fulfilment_type` = `'company'` | `'vendor'` (+ `company_worker_name`). A company job has **`vendor_id` NULL** — same "company = no vendor" pattern the commerce engine already uses for company dark-stores.
2. **Assign it:** Operations Board → request → **"Assign to company team"** (optional worker name) → `PATCH /ops {action:'assign_company'}` sets `vendor_id=NULL`, `fulfilment_type='company'`, `status='confirmed'`, clears the linked job's vendor.
3. **Money (full margin):** on completion `LedgerEngine::processBookingCompletion` detects the company job (`fulfilment_type='company'`, or defensively any completing non-visit job with no vendor) and books **one platform revenue row for the whole `total`** — no vendor wallet credit, no commission carve-out. (Mirrors the expert-visit-fee 100 %-company path; checked **after** it, so visit fees still route first.) The inner `JOIN vendors` became a **LEFT JOIN** so a NULL-vendor booking is still read and booked (previously it would silently record nothing).
4. **Vendor job unchanged:** `vendor_id` set + `fulfilment_type='vendor'` → the normal category→vendor→10 % split still fires. Both models coexist on the same booking.

## 4. FIXED — files + commits (branch `v2`)
| Audit item | Fix | Where | Commit |
|---|---|---|---|
| **PB-1** job price never recorded (₹0) | admin **set_amount** writes `total`+`final_quote_amount`; **record_payment** (COD collected/pending + who/when) | `heart/api/admin/index.php` (/ops), `admin/services.html` | `1a66fc2`, `831b023` |
| **Company self-fulfilment + full-margin money** (new model req) | `fulfilment_type`; `assign_company` ops action; ledger 100 %-company branch + LEFT JOIN vendors | migration `2026_10_05_001`, `core/helpers/LedgerEngine.php`, `heart/api/admin/index.php`, `admin/services.html` | `1a66fc2`, `831b023` |
| **PB-2** vendor landing Bookings tab dead | `showSection('bookings')` routes to the working `vendor/bookings.php`; Jobs↔Earnings cross-links; dashboard honors `#section` hash | `vendor/dashboard-service.html`, `vendor/bookings.php` | `536db3e` |
| **PB-3** admin force-status used booking id as job id | list returns the **real** `jobs.id` (correlated subquery) | `heart/api/admin/index.php` | `1a66fc2` |
| assign silent / customer steps stale | vendor-assign + company-assign now set `status=confirmed` and `fulfilment_type` | `heart/api/admin/index.php` | `1a66fc2` |
| **R-1** new category needs an admin-uncreatable anchor service | category-create auto-makes a company-owned anchor service (`vendor_id` NULL); `services.vendor_id` made nullable | migration `2026_10_05_001`, `heart/api/admin/index.php` | `1a66fc2` |
| **C-1** false "Packages arriving soon" copy | real CTA | `app/pages/home.js`, `app/css/wtg-premium.css` (class removed in markup) | `536db3e` |

## 5. DEFERRED — noted, NOT built (out of scope for this launch unit)
- **Company-project negotiation module** — structured quote → customer accept-quote → project price → milestones / progress payments. Launch uses the admin **"Set final amount"** (one number) + COD instead. (Audit model-(b) project half; `structure-lock` GAP-4.)
- **Online job-payment gateway** for the job itself — launch is **COD/manual** (the expert-visit fee online path is untouched and still works). The multi-gateway abstraction exists but the service-job online charge stays unwired.
- **Material / commerce**, and **all other categories** — no work done; painting/waterproofing only.
- **Vendor cash-owed ledger (`cash_in_hand`)** for cash commission the vendor holds — the headline money gap in `money-and-earnings-flow.md §5`; only meaningful now that PB-1 gives it a number. Still identify-only.
- **PH-1 contact-hidden-until-start** on the used endpoint (phone still visible to vendor on assignment), **PH-3** painter sees package/issue/notes, **PH-5** `escalated` not in the status enum, **PH-6** vendor can jump job states, customer self-cancel + booking refund record, webhook reconciliation, admin create-booking for phone/WhatsApp leads, per-job completion photos, FCM push on admin-assign. All pre-existing; none blocks the COD-coordinated pilot. **R-2** (customer category content hardcoded in `home.js` `CATEGORY_META`) left for before category #3.

## 6. MIGRATION — staged, NOT run
`migrations/2026_10_05_001_painting_company_fulfilment_cod.sql` (house style: `ADD COLUMN IF NOT EXISTS`, information_schema-guarded `MODIFY`, re-run safe, nullable — existing rows untouched):
- `bookings`: `fulfilment_type`, `company_worker_name`, `payment_collected_by`, `payment_collected_at` (+ index).
- `services.vendor_id` → **nullable** (for the company anchor service; FK kept — it tolerates NULL).
- `total` + `final_quote_amount` already exist (migration `2026_07_04_008`).
Apply on **staging** (built from a prod dump, per PB-4), verify with the SQL in the migration's footer, then prod. All new code is schema-defensive, so it runs safely even before the migration is applied (new fields simply stay NULL and the ledger falls back to the vendor-id check).

## 7. STAGING sign-off sequence (Painting/Waterproofing)
1. Apply `2026_10_05_001` on the prod-dump staging DB; verify columns.
2. **Company day-1 (no vendors):** customer books Painting package → admin **Assign to company team** (worker "Ramesh") → **Set amount ₹11,000** → **Record COD collected (by company)** → **Force status → Completed**. Expect: one platform ledger row ₹11,000, **zero vendor credit**; customer sees confirmed→completed + ₹11,000; admin shows Fulfilment=Company team, Payment=Collected.
3. **Vendor backup:** onboard 1 painter → admin assigns the vendor → vendor logs in → lands on dashboard → **Bookings → working jobs page** → Accept → (admin Set amount ₹9,000) → vendor Complete. Expect: ledger split 9,000×rate → vendor pending + platform commission; vendor Earnings shows pending.
4. **Waterproofing expert visit:** paid ₹1 live/sandbox → webhook flips paid → admin schedules → completes → fee 100 % company (unchanged).
5. **Recovery:** Force-complete/cancel on the prod-dump DB changes **only** the right booking (PB-3).
6. **Next-category reuse:** admin creates a new service category → confirm an anchor service appears → a Company request for it is accepted (no 404).

**Staging-ready (painting)? YES** — pending (a) applying the migration on staging, (b) the §7 run, (c) the ops bootstrap (prod dump), which are staging/ops steps, not code.

*Companion to `painting-launch-audit.md`, `money-and-earnings-flow.md`, `category-job-flows.md`. Grounded in real `wtg-v2` code. Local only — nothing pushed or deployed.*
