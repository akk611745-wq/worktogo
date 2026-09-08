# Phase 07 — Operational Service Risks

Generated: 2026-05-12T16:40:00Z

## Mandatory Risk Register

### 1. Main booking bypasses availability and slot reservations

- WHERE: customer booking path inserts `bookings` and `jobs` without checking `vendor_availability_slots` or inserting `booking_slot_reservations`.
- WHY: scheduling logic exists in separate slot controller, not integrated into main booking endpoint.
- ROOT CAUSE: booking lifecycle split between simple booking flow and slot-reservation flow.
- CONNECTED SYSTEMS: customer booking modal, service engine, vendor availability, provider workload.
- OPERATIONAL IMPACT: double-booking and scheduling conflicts are possible.
- SCALE IMPACT: provider calendar conflicts increase with volume.
- MAINTAINABILITY IMPACT: future scheduling must reconcile existing bookings without reservations.
- FUTURE RISK: exact time slots and capacity guarantees cannot be trusted.
- Confidence: High.

### 2. Booking/job divergence

- WHERE: assignment accept and auto-assignment no-vendor paths mutate `jobs` without booking mirror.
- WHY: only `/api/jobs/{id}/status` mirrors booking status.
- ROOT CAUSE: duplicated lifecycle mutation paths.
- CONNECTED SYSTEMS: bookings, jobs, assignment, alerts, customer/vendor UI.
- OPERATIONAL IMPACT: stale customer statuses and inconsistent provider dashboard.
- SCALE IMPACT: support/escalation grows as mismatches accumulate.
- MAINTAINABILITY IMPACT: every new status path must update two tables.
- FUTURE RISK: SLA, refund, payout, and review triggers fire from wrong state.
- Confidence: High.

### 3. Provider assignment model conflict

- WHERE: booking creation assigns service owner; auto assignment may assign nearest active online vendor.
- WHY: vendor-owned service model and marketplace assignment model coexist.
- ROOT CAUSE: undefined meaning of `jobs.vendor_id` and `bookings.vendor_id`.
- CONNECTED SYSTEMS: service booking, auto assignment, vendor job list, alerts.
- OPERATIONAL IMPACT: wrong provider can receive job; original provider/customer may see mismatched ownership.
- SCALE IMPACT: provider pool dispatch cannot scale reliably.
- MAINTAINABILITY IMPACT: assignment features need conceptual cleanup before extension.
- FUTURE RISK: provider payouts, accountability, and compliance break.
- Confidence: High.

### 4. Status vocabulary inconsistency

- WHERE: frontend uses `accepted/rejected/confirmed/delivered`; backend job endpoint uses `assigned/in_progress/completed/cancelled`; booking enum uses `confirmed/completed/cancelled`.
- WHY: UI labels and backend states are not canonicalized.
- ROOT CAUSE: no shared service state contract.
- CONNECTED SYSTEMS: vendor bookings page, service APIs, customer bookings page.
- OPERATIONAL IMPACT: accept/reject/update actions can fail or display wrong status.
- SCALE IMPACT: provider app reliability degrades.
- MAINTAINABILITY IMPACT: future statuses are hard to add safely.
- FUTURE RISK: complex field-service milestones become unmanageable.
- Confidence: High.

### 5. Service payment/refund weakness

- WHERE: service booking supports online payment but no traced full booking transaction/refund lifecycle.
- WHY: payment/refund logic is order-centric in traced refund controller; analytics assumes booking transactions.
- ROOT CAUSE: fragmented financial contract.
- CONNECTED SYSTEMS: bookings, transactions, Cashfree, vendor analytics, refunds.
- OPERATIONAL IMPACT: booking payment status, revenue, and refunds may be inconsistent.
- SCALE IMPACT: provider payouts and customer refund support become risky.
- MAINTAINABILITY IMPACT: finance logic must be unified before growth.
- FUTURE RISK: service cancellation policies cannot be enforced financially.
- Confidence: High.

### 6. Retry/recovery gaps after provider acceptance

- WHERE: assignment cron retries pending timeouts, but no traced recovery after provider accepts and then stalls.
- WHY: assignment lifecycle ends at acceptance; no SLA monitor for job progress.
- ROOT CAUSE: no operational state monitor beyond assignment lock timeout.
- CONNECTED SYSTEMS: jobs, bookings, alerts, vendor dashboard.
- OPERATIONAL IMPACT: accepted jobs can remain in progress indefinitely.
- SCALE IMPACT: delayed/no-show providers create customer dissatisfaction.
- MAINTAINABILITY IMPACT: future escalation needs new monitors and timestamps.
- FUTURE RISK: no-show/reassignment workflows are not supported.
- Confidence: High.

### 7. Notification inconsistency

- WHERE: alerts are inserted for assignment phases, but not for main booking creation or every job status update.
- WHY: notification logic is scattered.
- ROOT CAUSE: no centralized service lifecycle event bus.
- CONNECTED SYSTEMS: alerts, customer app, vendor realtime, admin.
- OPERATIONAL IMPACT: customers/providers may miss lifecycle changes.
- SCALE IMPACT: polling and manual refresh become operational bottlenecks.
- MAINTAINABILITY IMPACT: every lifecycle change must remember alert side effects.
- FUTURE RISK: push/SMS/WhatsApp integration becomes inconsistent.
- Confidence: High.

## Operational Bottlenecks
- Manual support needed when customer booking says pending but job is cancelled/in_progress.
- Provider cannot reliably manage jobs if intent mapping is broken.
- No admin escalation queue for failed/timed-out jobs.
- No service cancellation/refund path means support must intervene manually.
- No immutable audit of service status changes was traced.

## Areas Needing Deeper Inspection
- Production behavior of vendor intent pipeline for job actions.
- Actual cron schedule and assignment volume.
- Payment webhook effect on bookings.
- Whether service provider availability is exposed anywhere in vendor UI.
