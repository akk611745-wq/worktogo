# Phase 12 — Stabilization Blueprint

Generated: 2026-05-14T06:36:00Z

---

## 1. Stabilization Principle

Do not begin stabilization with rewrites. WorkToGo must first stabilize intelligence, contracts, ownership, and recovery visibility. The safest path is: map truth, freeze contracts, harden side effects, then evolve modules.

---

## 2. Stabilization Priority Matrix

| Priority | Zone | Reason | Expected Outcome |
|---:|---|---|---|
| 1 | API/intent/status contract | Prevents workflow breakage and engineering velocity collapse | Canonical route/intent/status registry |
| 2 | Finance/payment/refund/ledger | Money correctness has highest business risk | Reconciliation-safe finance domain map |
| 3 | Delivery synchronization | Customer-visible state split across PHP and SwiftDeliver | Idempotent delivery event state model |
| 4 | Service booking/job/slot lifecycle | Service scaling impossible with divergent flows | Unified booking lifecycle map |
| 5 | Admin operational boundary | Admin bypass can create hidden state damage | Admin command/audit map |
| 6 | Config/deployment/filesystem | Production behavior depends on hidden environment/substrate | Runtime assumption register and deployment checklist |
| 7 | DB ownership/schema contract | Tables are cross-module APIs | Safe migration and ownership model |
| 8 | Event/recovery model | Side effects are not replayable | Outbox/dead-letter blueprint |

---

## 3. Issue-Level Stabilization Requirements

| Issue | Root Cause | Connected Systems | Failure Propagation | Operational Impact | Scale Impact | Long-Term Impact | Priority | Future Strategy |
|---|---|---|---|---|---|---|---:|---|
| Contract drift | Manual string duplication | Frontends, Heart, Body | Calls hit missing/wrong handlers | Broken workflows | Drift multiplies with features | Velocity collapse | 1 | Shared contract registry and tests |
| Finance fragmentation | Domain split by file/feature | Orders, bookings, transactions, refunds, ledger | Payment/refund/status mismatch | Reconciliation incidents | Volume amplifies disputes | Finance rewrite pressure | 2 | Finance aggregate and ledger ownership |
| Delivery split truth | External Node state plus PHP mirror | Orders, deliveries, riders, admin | Out-of-order or missing status | Tracking confusion | Multi-node/city unsafe | Delivery architecture rewrite pressure | 3 | Event ledger and monotonic transitions |
| Service lifecycle drift | Duplicate booking paths | Bookings, jobs, slots, assignment | Status not mirrored | Provider/customer mismatch | Provider scheduling unreliable | Service rewrite pressure | 4 | Unified scheduling state machine |
| Admin lifecycle bypass | Direct SQL controls | All operational domains | Side effects skipped | Manual recovery errors | Incident load worsens | Governance/audit debt | 5 | Scoped admin commands and audit |
| Local filesystem | Disk as infra | Logs, cache, uploads, cron | Node divergence/failures | Missing evidence/media | Horizontal scaling blocked | DR weakness | 6 | Central logs/object storage/shared locks |
| Event fragmentation | No durable bus/outbox | Alerts, Brain, webhooks, cron, WS | Missed/duplicated events | Manual cleanup | Polling/retry storms | Async consistency debt | 7 | Outbox/dead-letter/replay model |

---

## 4. Safest Stabilization Sequence

### Stage 0 — Freeze Knowledge

- Maintain this final system brain as the engineering source of truth.
- Require every future change to identify affected contracts, tables, state machines, and side effects.

### Stage 1 — Contract Discipline

- Create canonical route/intent/status/response vocabulary.
- Track direct REST versus pipeline coverage.
- Identify frontend token key and response envelope assumptions.

### Stage 2 — Financial Safety

- Identify canonical payment state transitions.
- Separate COD, online, refund, ledger, wallet, and settlement semantics.
- Define webhook idempotency and reconciliation reporting.

### Stage 3 — State Synchronization Safety

- Define canonical owner for order, booking, delivery, payment, alert, and vendor eligibility state.
- Document allowed state transitions and admin override side effects.

### Stage 4 — Operational Recovery

- Define side-effect replay model for delivery dispatch, payment webhook, alerts, Brain events, and cron tasks.
- Require logs/metrics around all post-commit failures.

### Stage 5 — Infrastructure Hardening

- Replace local-only assumptions before horizontal growth.
- Centralize logs, move uploads to object storage, use shared cache/distributed locks.

### Stage 6 — Modular Evolution

- Decompose only after domain contracts are stable.
- Start with finance, service scheduling, admin operations, and delivery synchronization.

---

## 5. Stabilization Non-Negotiables

- Do not rewrite payment/refund/ledger without reconciliation truth.
- Do not change order statuses without parent/child/delivery impact analysis.
- Do not change booking/job/slot statuses without service lifecycle mapping.
- Do not scale horizontally while local lock/upload/cache/log assumptions remain unresolved.
- Do not add multi-city features without city/zone scoping in queries and operations.
- Do not add vendor/admin features without contract registry validation.

