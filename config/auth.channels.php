<?php
/**
 * auth.channels.php  —  WorkToGo Smart Auth Layer (config)
 * ----------------------------------------------------------------------------
 * Declarative OTP-channel registry + per-delivery-kind routing + which login
 * methods the platform offers. This is the ONLY place you change to add a
 * channel, re-route SMS/WhatsApp failover, or turn a method on/off — no code.
 * The exact analogue of config/payment.gateways.php.
 *
 * DB-free and side-effect-free (included in many contexts; must never fatal).
 * These are the safe DEFAULTS; an admin can override 'routing' / 'methods' at
 * runtime via app_settings.auth_channel_routing (JSON) — see OtpChannelManager,
 * which merges the DB override on top of these defaults defensively.
 *
 * ── Config-awareness (founder intent) ────────────────────────────────────────
 * Only channels that are BOTH enabled here AND hold real credentials
 * (isConfigured()) are ever used or OFFERED. WhatsApp/sms2 are enabled=false
 * stubs, so today only MSG91 (real) + the dev log channel are usable — WhatsApp
 * is simply not shown until it is connected.
 *
 * ── Failover ─────────────────────────────────────────────────────────────────
 * On a 'retryable' send failure (network / 5xx / auth-config — the provider is
 * DOWN, not a legitimate bad-number reject), the manager routes the SAME OTP to
 * the delivery-kind's 'secondary' if it is enabled + configured.
 */

return [
    // ── Registry: which channel adapters exist and whether they're enabled ─────
    'channels' => [
        'msg91'    => ['adapter' => 'Msg91SmsChannel',   'enabled' => true],   // primary SMS, live
        'sms2'     => ['adapter' => 'Sms2Channel',        'enabled' => false],  // STUB — 2nd SMS provider
        'whatsapp' => ['adapter' => 'WhatsAppOtpChannel', 'enabled' => false],  // STUB — WhatsApp OTP
        'log'      => ['adapter' => 'LogOtpChannel',      'enabled' => true],   // dev-only; fails loud in prod
    ],

    // ── Per-delivery-kind primary/secondary routing ────────────────────────────
    // 'log' as the SMS secondary reproduces the legacy dev behaviour (dev logs the
    // OTP; in prod 'log' is not usable, so it is skipped and delivery fails loudly
    // — exactly as before). Set routing.sms.secondary => 'sms2' once a real 2nd
    // provider is live to get genuine prod SMS failover.
    'routing' => [
        'sms'      => ['primary' => 'msg91',    'secondary' => 'log'],
        'whatsapp' => ['primary' => 'whatsapp', 'secondary' => 'msg91'], // WA → SMS fallback
        'default'  => ['primary' => 'msg91',    'secondary' => 'log'],
    ],

    // ── Which login methods the platform supports (the resolver also verifies the
    //    backing channel/provider is actually configured before offering each). ──
    'methods' => [
        'password'     => true,   // password login (email or phone identifier)
        'phone_otp'    => true,   // OTP via an SMS channel
        'whatsapp_otp' => true,   // OTP via WhatsApp (only offered if 'whatsapp' is configured)
        'google'       => true,   // Google Sign-In (only offered if GOOGLE_CLIENT_ID(S) is set)
    ],

    // ── Enumeration-safety (secure by default) ──────────────────────────────────
    // When false, GET /api/auth/methods returns only PLATFORM-level availability
    // (which methods/channels are configured) and NEVER confirms whether a specific
    // identifier has an account or a password — so it is not an account-enumeration
    // oracle. Flip to true (accepting the standard "does this account exist" signal,
    // rate-limited) to let the UI lead a known user straight to their fastest method.
    'reveal_account_methods' => false,
];
