# Phase 12 — Critical Risk Register

Generated: 2026-05-14T06:36:00Z

---

## 1. Executive Risk Summary

The dominant WorkToGo risk is not one isolated bug. It is systemic state fragmentation across route models, shared database tables, admin overrides, external delivery/payment callbacks, local filesystem assumptions, and polling-based synchronization. The system can run at small scale, but production incidents will be difficult to diagnose unless state ownership, contracts, and recovery paths are hardened.

---

## 2. Consolidated Critical Risks

| ID | Risk | Severity | Probability | First Impact | Hardening Priority |
|---|---|---:|---:|---|---:|
| R1 | MySQL-centered collapse | Critical | High | Auth, checkout, polling, admin, webhooks slow/fail | 1 |
| R2 | Financial domain fragmentation | Critical | Medium-high | Reconciliation/refund/payment incidents | 2 |
| R3 | API/intent contract drift | High | High | Vendor/customer/admin workflows break | 1 |
| R4 | Delivery state split | High | High | Tracking/order/delivery mismatch | 3 |
| R5 | Service booking/job/slot divergence | High | High | Provider/customer service ops mismatch | 3 |
| R6 | Admin monolith/global authority | High | Medium-high | Broad blast radius and lifecycle bypass | 4 |
| R7 | Local filesystem infrastructure | High | Medium-high | Horizontal scaling and disaster recovery failure | 5 |
| R8 | No durable event/outbox layer | High | High | Side effects fail after user-visible success | 2 |
| R9 | Stale JWT role/status claims | High | Medium | Deactivated/changed roles retain access until refresh/revoke | 4 |
| R10 | Polling realtime read amplification | Medium-high | High | DB load, mobile battery/network drain | 6 |
| R11 | Schema vocabulary drift | High | Medium-high | Migration and admin query failures | 4 |
| R12 | Cron/local lock scaling | Medium-high | Medium | Duplicate assignment or missed assignment | 6 |

---

## 3. Major Systemic Issue Analysis

### R1 — MySQL-Centered Collapse

- Root cause: MySQL is used as auth dependency, operational state store, polling source, admin analytics source, and webhook transaction substrate.
- Connected systems: auth, admin, cart, orders, services, bookings, payments, delivery, alerts, cron, Brain.
- Failure propagation: DB latency slows token blacklist checks, protected APIs, checkout locks, alert polling, admin dashboards, webhook processing, and cron assignment.
- Operational impact: users cannot login or complete flows; admins cannot reliably observe or recover incidents.
- Scale impact: polling and hot writes amplify load linearly or worse.
- Long-term impact: schema evolution and feature work become increasingly dangerous.
- Hardening priority: 1.
- Future evolution strategy: read models for admin/polling, event/outbox for side effects, DB index/constraint review, cache strategy, queue-backed workloads.

### R2 — Financial Domain Fragmentation

- Root cause: payment, refund, ledger, transactions, COD, and Cashfree behavior are split across multiple helpers/controllers/services.
- Connected systems: orders, bookings, transactions, refunds, ledgers, wallets, Cashfree, admin, delivery dispatch.
- Failure propagation: webhook or refund mismatch creates order status/payment status/transaction/ledger divergence.
- Operational impact: manual reconciliation and customer/vendor disputes.
- Scale impact: higher volume increases mismatch and support burden.
- Long-term impact: future settlement, payouts, wallet, and tax features become high-risk.
- Hardening priority: 2.
- Future evolution strategy: finance domain ownership map, canonical transaction ledger, idempotency/event ledger, reconciliation dashboard, refund lifecycle state machine.

### R3 — API/Intent Contract Drift

- Root cause: frontend calls, Heart router mappings, body engine switches, direct REST routes, and response envelopes are manually duplicated.
- Connected systems: customer SPA, vendor panel, admin panel, Heart, Body engines.
- Failure propagation: a feature can work in direct REST but fail through intent pipeline or vice versa.
- Operational impact: workflows break with confusing errors or fallback behavior.
- Scale impact: more pages/features multiply drift.
- Long-term impact: engineering velocity collapses because every change needs route archaeology.
- Hardening priority: 1.
- Future evolution strategy: canonical endpoint/intent registry, contract tests, shared client definitions, response envelope standardization.

### R4 — Delivery State Split

- Root cause: delivery state exists in WorkToGo MySQL, SwiftDeliver SQLite, in-memory WebSockets, and webhook mirrors without a traced durable event ledger.
- Connected systems: orders, deliveries, SwiftDeliver, admin, riders, vendors, customers.
- Failure propagation: dispatch failure or out-of-order callback creates tracking/status divergence.
- Operational impact: customers/admins may see wrong delivery status or no tracking.
- Scale impact: retry storms and multiple riders/nodes increase race probability.
- Long-term impact: multi-city delivery becomes unsafe without partitioning and event ordering.
- Hardening priority: 3.
- Future evolution strategy: delivery event ledger, monotonic transition guard, idempotency keys, persisted tracking token, city/zone partitioning.

### R5 — Service Booking/Job/Slot Divergence

- Root cause: main service request flow and alternate slot reservation flow coexist with different guarantees.
- Connected systems: services, vendors, bookings, jobs, slots, auto_assignments, alerts, payments.
- Failure propagation: job assignment/completion/cancellation may not mirror booking or slot state.
- Operational impact: providers and customers disagree about accepted/completed/cancelled work.
- Scale impact: provider workload and availability become unreliable.
- Long-term impact: Urban Company-style service scaling requires rewrite pressure if not unified.
- Hardening priority: 3.
- Future evolution strategy: canonical service scheduling aggregate, provider capability matching, slot reservation integration, booking/job status machine.

### R6 — Admin Monolith and Global Authority

- Root cause: admin backend is one broad admin role/file with direct SQL control across domains.
- Connected systems: users, vendors, orders, services, deliveries, payments, settings, logs.
- Failure propagation: admin mutation can bypass domain-specific side effects such as inventory release, audit, refund workflow, or status transitions.
- Operational impact: high power, weak blast-radius containment.
- Scale impact: live dashboard aggregates can stress DB during incidents.
- Long-term impact: scoped admin roles and operational audit will be hard to retrofit.
- Hardening priority: 4.
- Future evolution strategy: admin domain decomposition map, immutable audit trail, scoped permissions, command wrappers invoking domain services.

### R7 — Local Filesystem Infrastructure Boundary

- Root cause: logs, file cache, uploads, and cron locks rely on local disk.
- Connected systems: observability, media, Reflex cache, cron auto assignment, deployment.
- Failure propagation: disk permission/capacity/node divergence affects logs, uploads, cache, and lock safety.
- Operational impact: missing logs, failed uploads, duplicate cron, unrecoverable local media.
- Scale impact: horizontal scaling is unsafe without shared substrate.
- Long-term impact: disaster recovery remains incomplete.
- Hardening priority: 5.
- Future evolution strategy: object storage, centralized logs, Redis/shared cache, DB/distributed locks, backup policy.

