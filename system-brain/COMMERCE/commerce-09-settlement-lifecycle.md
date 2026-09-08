# Commerce 09 — Settlement Wiring + Delivery Lifecycle Fixes (Fix Phase 1)

> **Purpose:** Close the CRITICAL settlement gap and the delivery lifecycle/edge-cases from `commerce-08-readiness-audit.md` (§2, §5), so commerce **moves money correctly** and delivery **holds under real-world unhappy paths**. Three separate commits, each in its own logical area.
> **Standard:** precise, defensive, transactional, no jugaad. Delivery stays in its own scope; the never-touch order engine is untouched.
> **Status:** PHP + JS verified (no runtime here); migration for staging (not run). Committed on `v2`.
> **Date:** 2026-08-22. Builds on Phases 1–5 (`…b5b0049`).

## Area 1 — Order settlement wiring (CRITICAL) · commit `eebad1a`

The audit's #1 gap: `processOrderCompletion` had **no caller**, so commerce orders booked no money and the COD cash-limit guard was dead.

- **New `LedgerEngine::processCommerceOrderSettlement($orderId, $db)`** — settles a delivered order for the commerce **parent + sub-order** model (which the legacy flat `processOrderCompletion` doesn't fit):
  - **per vendor sub-order:** `vendor_wallets.pending += subtotal − commission`; commission rate priority **store override > vendor override > 10%**.
  - **rider:** `driver_wallets.earnings += rider_payout`; if **COD**, `cash_in_hand += (items + delivery − rider_payout)` = the cash the rider owes the platform → this is what makes `isDriverBlocked` actually fire.
  - **platform:** items commission + delivery margin.
  - Every row is **`reference_type='order'`, `reference_id` = the PARENT order id** — can never collide with a service booking (`'booking'`). One transaction, `FOR UPDATE` on the parent, **idempotent** (skips if `ledger_status='processed'` or any non-refunded `'order'` row exists) → retry / double-delivery safe.
- **Wired into `DeliveryEngine::riderDeliver`** (the authoritative completion event), non-fatal so a settlement hiccup can't un-deliver the order (the lifecycle cron retries unsettled ones). Vendor `pending → available` still advances via the existing admin `runSettlement` (shared with service).
- **Money-safe with both engines live:** service (`processBookingCompletion`, `'booking'`) and commerce (`'order'`) never cross; no double-count. `LedgerEngine` is `core/helpers` (permitted) — the never-touch engine is untouched. No migration.

## Area 2 — Delivery lifecycle watchdog cron · commit `9fe949a`

The audit's §2: no-rider-forever, stuck/abandoned deliveries, no late detection, no settlement retry.

- **Migration `2026_08_22_004`:** `deliveries.flag (none|no_rider|stuck|late)`, `flagged_at`, `assign_attempts`, `last_attempt_at` (+indexes); 7 **admin-tunable** thresholds in `platform_settings`.
- **`DeliveryEngine` sets `eta_minutes`** at creation (prep + distance).
- **`cron/delivery_lifecycle.php`** (flock-locked, defensive, secret-gated over HTTP, disable-able in settings):
  - **A** pending/no-rider → retry `assignNearest` every N sec; **flag `no_rider` + alert** after N min (no longer stuck forever);
  - **B/C** assigned but not accepted / not picked in time → **flag `stuck` + alert**;
  - **D** picked but not delivered in time → **flag `stuck` + alert** (backup rider);
  - **E** past ETA → **flag `late` + alert**;
  - **F** delivered-but-unsettled → **retry settlement** (idempotent belt-and-braces).
- **Admin Delivery Control surfaces it:** coloured flag pills, flagged-first ordering, a **"Needs attention only"** filter (`GET /delivery/admin/deliveries?attention=1`). Alerts go to the admin `alerts` stream (reused, no new table).

## Area 3 — Reassignment & support riders · commit `61450d6`

The audit's §2: reject could bounce to the same rider; no backup path for an in-trouble delivery.

- **`assignNearest($db, $id, $excludeRiderId)`** — reassignment skips a given rider.
- **`riderReject`** now excludes the rejecter (hands to a **different** nearest rider) and clears the flag.
- **`assignSpecific`** — admin assigns a chosen backup rider (lock-safe; validates online / capacity / not cash-blocked).
- **`adminReassign`** — frees the current rider, resets the delivery, then assigns a chosen backup (`{rider_id}`) or the nearest **different** rider; works for pending / assigned / **picked** (physical handover of a picked order is operational). The control-room Reassign button now covers picked deliveries.

## NEVER-TOUCH FLAG
**Nothing in `shopping-engine`/`video-engine`/`orders.js` was edited.** Settlement lives in `LedgerEngine` (core/helpers — permitted) and is triggered from `DeliveryEngine` (delivery scope). Money is recorded through the ledger's own primitives with `reference_type='order'`; no order-engine code was changed.

## HOW TO VERIFY EACH (staging, after migrations 001–004)
- **Settlement:** place a COD order → rider delivers → check `wallet_transactions` has `reference_type='order'` rows (vendor `pending`, driver `settled`, platform `settled`); `driver_wallets.cash_in_hand` increases; `orders.ledger_status='processed'`; re-running settlement is a no-op. Push a rider's `cash_in_hand` past `collection_limit` → they stop being assigned (`isDriverBlocked` now live).
- **Lifecycle cron:** run `php cron/delivery_lifecycle.php`. With all riders offline, a new order's delivery flags `no_rider` after the threshold + an admin alert; the "Needs attention" filter shows it. Bring a rider online → next run assigns it. Leave one assigned-unaccepted / picked-too-long → flags `stuck`. Kill a settlement then re-run the cron → it settles (F).
- **Reassign:** rider rejects an assignment → it goes to a *different* rider. Admin clicks Reassign on a stuck/picked delivery → nearest different rider (or POST `{rider_id}` for a specific backup) takes it; the old rider's `active_count` frees.

## REMAINING (next task — from audit §4)
Security **HIGH H1** (delivery-track IDOR — scope `track` to the order's owner) and the **MEDIUM** set (M1 fee-tamper via client drop coords, M2 city guard at checkout, M3 cancel→orphan-delivery cascade, M5 rate-limits) are **not** in this task — they're the next fix phase.

## OPEN QUESTIONS
1. **Cron schedule** — recommend every 30–60s (thresholds assume that). Confirm the scheduler + a `CRON_SECRET` for the HTTP guard.
2. **Cash remittance** — `cash_in_hand` now accrues; the rider→platform remittance/clear flow (admin marks cash collected) is not built. Needed before COD at volume.
3. **Picked-delivery backup** — `adminReassign` on a `picked` delivery resets it for a new rider; the physical handover of goods is operational (documented). Confirm the ops rule.
4. **Vendor `pending→available`** for commerce uses the shared `runSettlement` (all vendor pending). Confirm commerce + service settle from the same admin action.
