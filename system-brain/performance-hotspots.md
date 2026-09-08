# Phase 10 — Performance Hotspots

## Query Hotspots

### P1 — Admin dashboard aggregates

- WHERE: Admin dashboard executes multiple counts, sums, joins, grouping queries, and stuck-order scans in one request.
- WHY: Operational summary is computed live.
- ROOT CAUSE: No traced precomputed dashboard table/cache for direct REST admin endpoints.
- CONNECTED SYSTEMS: orders, wallet_transactions, vendor_wallets, users, driver_wallets, transactions, order_items, vendors.
- SECURITY IMPACT: Admin-only but broad read access.
- PERFORMANCE IMPACT: Slow dashboard as tables grow.
- SCALE IMPACT: Incident-time dashboard can increase DB load.
- FUTURE RISK: Admin observability becomes unavailable under load.
- Evidence: `wtg  live/heart/api/admin/index.php:17-203`.
- Confidence: High.

### P2 — Customer and vendor order list hydration

- WHERE: Customer list paginates parent orders then hydrates each; vendor list batch-fetches items for page order IDs.
- WHY: UI needs order and item detail.
- ROOT CAUSE: Relational joins/hydration in request path.
- CONNECTED SYSTEMS: orders, order_items, products, vendors.
- SECURITY IMPACT: Scoped by user/vendor in traced methods.
- PERFORMANCE IMPACT: Order history grows and pagination queries depend on indexes.
- SCALE IMPACT: Polling order pages multiplies read load.
- FUTURE RISK: More vendor panels open = frequent list queries.
- Evidence: `wtg  live/body/shopping-engine/api/orders/OrderService.php:555-606`, `wtg  live/body/shopping-engine/api/orders/OrderService.php:645-747`.
- Confidence: High.

### P3 — Alerts polling read pattern

- WHERE: Fetch endpoint requires auth, resolves vendor ID if role indicates vendor, fetches delta alerts, and separately counts unseen alerts.
- WHY: Browser polling needs new alerts and badge counts.
- ROOT CAUSE: Polling instead of push/subscription.
- CONNECTED SYSTEMS: alerts table, vendors table, auth blacklist.
- SECURITY IMPACT: Auth-protected; role query parameter can request vendor role but vendor ID is resolved from authenticated user.
- PERFORMANCE IMPACT: Every poll = blacklist query + vendor lookup for vendors + alert select + unseen count.
- SCALE IMPACT: Linear with active browser tabs.
- FUTURE RISK: Vendor poll interval of 3s becomes high DB load.
- Evidence: `wtg  live/api/alerts/fetch.php:19-58`, `wtg  live/api/alerts/AlertEngine.php:135-184`, `wtg  live/assets/js/alerts.js:57-64`.
- Confidence: High.

### P4 — Order checkout transaction

- WHERE: Checkout performs multiple inserts/updates and inventory row locks.
- WHY: Enforce stock reservation and multi-vendor order split.
- ROOT CAUSE: Strong consistency inside synchronous request.
- CONNECTED SYSTEMS: cart, inventory, products, orders, order_items.
- SECURITY IMPACT: Abuse can force expensive transactions.
- PERFORMANCE IMPACT: Lock wait on hot products.
- SCALE IMPACT: Flash-sale style load can collapse checkout throughput.
- FUTURE RISK: Parent/child order fanout increases cost per order.
- Evidence: `wtg  live/body/shopping-engine/api/orders/OrderService.php:141-303`.
- Confidence: High.

### P5 — Booking slot contention

- WHERE: `BookingController::createBooking()` locks slot row and counts reservations with `FOR UPDATE` before inserting reservation.
- WHY: Prevent double-booking.
- ROOT CAUSE: Hot vendor/time slot represented by shared DB rows.
- CONNECTED SYSTEMS: bookings, vendor_availability_slots, booking_slot_reservations.
- SECURITY IMPACT: Endpoint trusts body `user_id` in traced controller; if route reachable, identity abuse risk exists.
- PERFORMANCE IMPACT: Popular slots contend.
- SCALE IMPACT: High-demand vendors hit lock waits.
- FUTURE RISK: Needs robust unique reservation semantics under scale; current route relationship uncertain.
- Evidence: `wtg  live/body/service-engine/api/BookingController.php:9-78`.
- Confidence: Medium-high; route exposure uncertain.

## Mobile Performance Risks

- Customer/vendor/admin pages rely on repeated fetches rather than synchronized push across PHP runtime.
- Alert polling at 3s vendor / 7s user can drain mobile battery and radio time.
- Large story uploads are direct PHP multipart uploads to local disk.
- Home/feed/story endpoints return limited sets, but image/video payload serving and thumbnail optimization were not traced.
- External dispatch/payment delays can appear as slow checkout because cURL occurs in request lifecycle or post-commit same request.

## Performance Uncertainty

- Actual indexes from deployed schema were not revalidated for every query.
- Production PHP/APCu/Redis availability is environment-dependent.
- Static asset caching/CDN behavior for uploads and frontend assets is not traced.
- Real data volume and query plans were not measured; this is code-trace performance intelligence, not benchmark output.

