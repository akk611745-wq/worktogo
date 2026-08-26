<?php
/**
 * payment.gateways.php  —  WorkToGo Payment Abstraction Layer (config)
 * ----------------------------------------------------------------------------
 * Declarative gateway registry + per-ENGINE routing. This is the ONLY place you
 * change to add a gateway or re-route an engine's primary/secondary — no code.
 *
 * This file is intentionally DB-free and side-effect-free (it is included in
 * many contexts and must never fatal). These are the safe DEFAULTS; admins can
 * override 'routing' at runtime via the app_settings row
 * 'payment_gateway_routing' (JSON) — see PaymentGatewayManager, which merges the
 * DB override on top of these defaults defensively.
 *
 * ── Engine independence ──────────────────────────────────────────────────────
 * Each engine has its OWN routing entry. A misconfigured or down gateway for one
 * engine changes only that engine's entry; the others resolve independently.
 *   • commerce → orders   (shopping-engine)
 *   • service  → bookings (service-engine, ₹299 inspection)
 *   • video    → (dormant)
 * 'default' is the fallback used when an engine key is missing.
 *
 * ── Failover ─────────────────────────────────────────────────────────────────
 * On a create-order failure that is 'retryable' (network / 5xx / auth-config,
 * i.e. the gateway is DOWN or erroring — NOT a legitimate decline), the manager
 * routes the SAME order to 'secondary' if it is enabled + configured. The
 * customer is only ever handed one payable session, so there is no double-charge.
 */

return [
    // ── Registry: which adapters exist and whether they're enabled ─────────────
    'gateways' => [
        'cashfree' => [
            'adapter' => 'CashfreeAdapter',
            'enabled' => true,   // primary, live
        ],
        'razorpay' => [
            'adapter' => 'RazorpayAdapter',
            'enabled' => false,  // STUB — flip to true only when creds are set + sandbox-validated
        ],
    ],

    // ── Per-engine primary/secondary routing ───────────────────────────────────
    // secondary = null ⇒ no failover for that engine (fails gracefully).
    // When Razorpay is ready, set the relevant 'secondary' => 'razorpay'.
    'routing' => [
        'default'  => ['primary' => 'cashfree', 'secondary' => null],
        'commerce' => ['primary' => 'cashfree', 'secondary' => null],
        'service'  => ['primary' => 'cashfree', 'secondary' => null],
        'video'    => ['primary' => 'cashfree', 'secondary' => null],
    ],
];
