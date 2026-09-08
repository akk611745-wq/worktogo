# Phase 10 — Mobile Performance Analysis

## Mobile Reality

The traced runtime is mobile-capable as browser-based pages, but not mobile-optimized at infrastructure level. Mobile performance risk comes from polling, repeated authenticated requests, large media, synchronous checkout/payment/delivery side effects, and localStorage token reliance.

## Findings

### M1 — Polling drains mobile resources and multiplies server reads

- WHERE: AlertSystem defaults to 3 seconds for vendors and 7 seconds for users.
- WHY: Simulated realtime without push.
- ROOT CAUSE: Timer-based polling.
- CONNECTED SYSTEMS: alerts API, auth middleware, DB.
- SECURITY IMPACT: Frequent token transmission increases exposure window.
- PERFORMANCE IMPACT: Mobile radio/battery/network overhead.
- SCALE IMPACT: Active tabs create constant DB load.
- FUTURE RISK: Mobile vendor operations become expensive with many vendors.
- Evidence: `wtg  live/assets/js/alerts.js:57-64`, `wtg  live/api/alerts/fetch.php:19-58`.
- Confidence: High.

### M2 — Story/media upload and feed can stress mobile networks

- WHERE: Story upload accepts image/video files and stores local media URLs.
- WHY: Vendor promotional stories.
- ROOT CAUSE: Direct upload to PHP/local disk; no traced transcoding/CDN/thumbnails.
- CONNECTED SYSTEMS: vendor account, stories, uploads filesystem, feed stories.
- SECURITY IMPACT: Uploaded content validation uncertainty.
- PERFORMANCE IMPACT: Large upload/download on mobile.
- SCALE IMPACT: Local disk and bandwidth grow with media adoption.
- FUTURE RISK: Mobile feed performance depends on media optimization not traced.
- Evidence: `wtg  live/body/content-engine/api/StoryController.php:24-79`, `wtg  live/body/content-engine/api/StoryController.php:82-151`.
- Confidence: Medium-high.

### M3 — Checkout can feel slow due to synchronous writes and dispatch

- WHERE: Order creation performs DB transaction and post-commit dispatch in same request lifecycle.
- WHY: Immediate order creation/fulfillment workflow.
- ROOT CAUSE: No async queue/outbox traced.
- CONNECTED SYSTEMS: cart, inventory, orders, delivery.
- SECURITY IMPACT: Retried taps can increase abuse/duplicate pressure despite rate limit.
- PERFORMANCE IMPACT: Mobile users may see slow checkout if DB or SwiftDeliver is slow.
- SCALE IMPACT: Checkout throughput tied to DB locks and PHP workers.
- FUTURE RISK: Mobile UX degrades during peak ordering.
- Evidence: `wtg  live/body/shopping-engine/api/orders/OrderService.php:141-309`, `wtg  live/body/shopping-engine/api/orders/OrderService.php:965-1030`.
- Confidence: High.

## Mobile Uncertainties

- Frontend asset bundling/minification/cache headers were not deeply re-traced in this phase.
- Actual image/video compression and browser caching behavior for `/uploads/stories` were not traced.
- Offline retry/state persistence behavior is uncertain beyond prior SPA local state maps.
- Mobile admin/vendor layouts were documented in prior phases but not fully re-read here.

