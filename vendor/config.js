/**
 * WorkToGo — Vendor Panel Configuration
 * ─────────────────────────────────────
 * Set BASE_URL to your backend before deploying.
 * All other files import from here — single source of truth.
 */
const CONFIG = {
  // Environment-driven. The vendor pages inject window.WTG_BASE_URL from the
  // APP_URL env var (see vendor/*.php). We honor an explicitly-injected value —
  // INCLUDING "" — and fall back to same-origin ("") when unset. Never hardcode
  // production: a same-origin default keeps staging pointed at staging and can
  // never hit prod by accident. Set APP_URL to use a cross-origin API host.
  BASE_URL: (typeof window !== "undefined" && typeof window.WTG_BASE_URL === "string")
    ? window.WTG_BASE_URL.replace(/\/+$/, "")
    : "",

  // Token storage key
  TOKEN_KEY: "wtg_vendor_token",
  USER_KEY:  "wtg_vendor_user",

  // Request timeout in ms
  TIMEOUT: 15000,

  // App meta
  APP_NAME: "WorkToGo Vendor",
  VERSION:  "1.0.0",

  FEATURES: {
    SERVICE_ONLY_MODE: true,
    SHOPPING_VENDOR_UI: false,
    VENDOR_ANALYTICS: false,
    VENDOR_REALTIME_LABEL: false,
    VENDOR_ONBOARDING: false,    // reserved: declared but not yet wired to any gate
    SERVICE_PROOF_UPLOAD: false, // reserved: declared but not yet wired to any gate
  },

  // Role constants
  ROLES: {
    SERVICE:  "vendor_service",
    SHOPPING: "vendor_shopping",
  },

  // Order status options (shopping)
  ORDER_STATUSES: ["pending", "confirmed", "in_progress", "delivered", "cancelled"],

  // Job status options (service)
  JOB_STATUSES: ["confirmed", "in_progress", "completed", "cancelled"],
};

// Freeze so nothing accidentally mutates config
Object.freeze(CONFIG);
Object.freeze(CONFIG.ROLES);
Object.freeze(CONFIG.FEATURES);
