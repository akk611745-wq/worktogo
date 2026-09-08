# Phase 12 — Engineering Operating Manual

Generated: 2026-05-14T06:36:00Z

---

## 1. Purpose

This manual is the onboarding and operating guide for future WorkToGo engineers, AI systems, and production operators. It explains how to reason about the platform without blindly opening random files.

---

## 2. How to Read WorkToGo Safely

1. Start from the domain, not the file.
2. Identify route model: direct REST or Heart intent pipeline.
3. Identify auth boundary and role/vendor/admin checks.
4. Identify primary table writes and all secondary side effects.
5. Check admin override paths for the same state.
6. Check payment/delivery/webhook/cron side effects.
7. Check frontend token, intent, response, and status assumptions.
8. Check whether SwiftDeliver or Cashfree has competing truth.

---

## 3. Debugging Intelligence Layer

| Symptom | First Places to Reason | Likely Root Pattern |
|---|---|---|
| Vendor page action fails | Vendor frontend API, Heart intent registry, service/shopping engine switches | Intent contract drift |
| User has wrong access after role change | JWT claims, token blacklist, users.role, vendors.status | Stale token/vendor eligibility |
| Order paid but not dispatched | Payment webhook, transactions, order status, delivery dispatch logs | Post-commit side-effect failure |
| Delivery status wrong | MySQL deliveries/orders, SwiftDeliver SQLite/status route, webhook | Split delivery truth/out-of-order update |
| Booking visible but job wrong | bookings, jobs, auto_assignments, slot controller | Booking/job/slot divergence |
| Admin change causes weird state | Admin direct SQL path versus domain service path | Lifecycle bypass |
| Production slows under load | DB, polling endpoints, admin aggregates, PHP worker saturation | DB-centered synchronous runtime |
| Missing alert/popup | alerts table, polling timestamp, vendor realtime known IDs | Polling/non-durable events |
| Migration breaks page | Schema drift, direct SQL references, admin legacy vocabulary | Shared DB contract drift |

---

## 4. Operational Runbook Priorities

### During DB latency

- Expect auth, polling, admin, checkout, payment webhooks, alerts, and cron to degrade together.
- Reduce admin dashboard load and polling-heavy operations where possible.
- Inspect recent checkout/payment/cron lock contention.

### During payment incident

- Compare Cashfree event, transactions, order payment_status, order status, refund rows, and ledger/wallet records.
- Do not rely on frontend return URL as payment proof.
- Watch for webhook processing failures acknowledged as HTTP 200.

### During delivery incident

- Compare WorkToGo order/delivery state with SwiftDeliver order/status/tracking state.
- Check whether dispatch happened after order/payment success.
- Look for duplicate/out-of-order callbacks.

### During vendor/service incident

- Confirm vendor users.role, vendors.status/type, JWT freshness, and vendor frontend intent mapping.
- For services, inspect both bookings and jobs; check auto_assignment side effects.

### During admin incident

- Identify whether admin changed state directly.
- Determine whether domain side effects were skipped.
- Preserve logs before purge/rotation.

---

## 5. Engineering Change Review Checklist

Before changing code, require answers to:

- Which domain owns the state being changed?
- Is there an admin route that changes the same state?
- Does direct REST and intent pipeline both touch this behavior?
- Are frontend status names aligned with backend status names?
- Does the change affect shared tables used by other modules?
- Does the change affect payment/refund/ledger reconciliation?
- Does the change affect delivery dispatch or webhook state?
- Does the change create or consume events? Are they durable?
- Does the change assume a single server or local filesystem?
- Does the change remain safe under multi-city or mobile reconnect behavior?

---

## 6. Canonical Documentation Index

| Need | Start Here |
|---|---|
| Full overview | [`master-system-brain.md`](WTG-SYSTEM-BRAIN/master-system-brain.md) |
| Architecture structure | [`architecture-dna.md`](WTG-SYSTEM-BRAIN/architecture-dna.md) |
| State truth | [`canonical-state-map.md`](WTG-SYSTEM-BRAIN/canonical-state-map.md) |
| Risks | [`critical-risk-register.md`](WTG-SYSTEM-BRAIN/critical-risk-register.md) |
| Production launch | [`production-readiness-report.md`](WTG-SYSTEM-BRAIN/production-readiness-report.md) |
| Stabilization | [`stabilization-blueprint.md`](WTG-SYSTEM-BRAIN/stabilization-blueprint.md) |
| Scaling | [`scaling-evolution-roadmap.md`](WTG-SYSTEM-BRAIN/scaling-evolution-roadmap.md) |
| Future features | [`future-expansion-guide.md`](WTG-SYSTEM-BRAIN/future-expansion-guide.md) |
| Scores | [`final-production-readiness-score.md`](WTG-SYSTEM-BRAIN/final-production-readiness-score.md) |

