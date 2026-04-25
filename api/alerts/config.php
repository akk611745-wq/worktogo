<?php
/**
 * Alert System — Configuration
 * -------------------------------------------------------
 * All tunable values live here. No hardcoding anywhere else.
 * Plugs into the existing PHP Heart Pipeline via DB_* constants
 * that are already defined in your main config.
 * -------------------------------------------------------
 */

// ── Polling intervals (seconds) ────────────────────────────
define('ALERT_POLL_VENDOR',     3);   // Vendor panel polls every 3 s
define('ALERT_POLL_USER',       7);   // User panel polls every 7 s
define('ALERT_POLL_MAX_AGE',  300);   // Ignore alerts older than 5 min on first load

// ── Delta window ───────────────────────────────────────────
// How many seconds back the delta query looks when no
// last_ts is provided by the client (cold start).
define('ALERT_DELTA_COLD_START', 60);

// ── Pagination ─────────────────────────────────────────────
define('ALERT_PAGE_SIZE', 20);        // Max alerts returned per request

// ── Auto-expire ────────────────────────────────────────────
// Alerts older than this (days) are deleted by the cleanup job.
define('ALERT_EXPIRE_DAYS', 30);

// ── Response cache header (seconds) ────────────────────────
// Tells the browser/proxy not to cache polling responses.
define('ALERT_CACHE_TTL', 0);

// ── Alert type → UI meta map ───────────────────────────────
// icon   : CSS class or identifier used by alerts.js
// sound  : which sound file to play (false = silent)
// badge  : whether this type increments the badge counter
define('ALERT_TYPE_META', [
    'order_new'          => ['icon' => 'icon-order',   'sound' => 'chime',  'badge' => true],
    'order_accepted'     => ['icon' => 'icon-check',   'sound' => 'chime',  'badge' => true],
    'order_in_progress'  => ['icon' => 'icon-clock',   'sound' => false,    'badge' => false],
    'order_completed'    => ['icon' => 'icon-done',    'sound' => 'success','badge' => true],
    'payment_success'    => ['icon' => 'icon-payment', 'sound' => 'success','badge' => true],
    'payment_failure'    => ['icon' => 'icon-warning', 'sound' => 'error',  'badge' => true],
    'status_update'      => ['icon' => 'icon-info',    'sound' => false,    'badge' => false],
    'system'             => ['icon' => 'icon-system',  'sound' => false,    'badge' => false],
]);

// ── Database table name ─────────────────────────────────────
define('ALERT_TABLE', 'alerts');

// ── Rate limiting ───────────────────────────────────────────
// Max alerts that can be created for a single ref_id + type
// within ALERT_DEDUP_WINDOW seconds. Prevents duplicate bursts.
define('ALERT_DEDUP_WINDOW', 10);     // seconds
define('ALERT_DEDUP_MAX',     1);     // max identical alerts in window
