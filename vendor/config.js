/**
 * WorkToGo — Vendor Panel Configuration
 * ─────────────────────────────────────
 * Set BASE_URL to your backend before deploying.
 * All other files import from here — single source of truth.
 */
const CONFIG = {
  // ✏️  Change this to your actual backend URL
  BASE_URL: (typeof window !== "undefined" && window.WTG_BASE_URL) || "https://worktogo.in",

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
    VENDOR_ONBOARDING: false,
    SERVICE_PROOF_UPLOAD: false,
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
