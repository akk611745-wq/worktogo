/**
 * WorkToGo — Central Configuration
 * ─────────────────────────────────────────────
 * Change BASE_URL before deploy. All other settings are production-ready.
 * You can also set window.WTG_BASE_URL in a separate env config file.
 */

const CONFIG = {
  // ─── API ───────────────────────────────────────
  // Set your real backend URL here — no trailing slash
  BASE_URL: (typeof window !== "undefined" && window.WTG_BASE_URL) || "https://api.worktogo.app",

  ENDPOINTS: {
    // Auth — OTP flow
    OTP_SEND:   "/api/auth/otp/send",
    OTP_VERIFY: "/api/auth/otp/verify",

    // Catalog
    PRODUCTS: "/products",
    SERVICES: "/services",

    // Transactions
    ORDERS:   "/orders",
    BOOKINGS: "/bookings",

    // Profile
    PROFILE: "/user/profile",
  },

  // ─── AUTH ──────────────────────────────────────
  TOKEN_KEY:   "wtg_token",
  USER_KEY:    "wtg_user",
  SESSION_KEY: "wtg_session",

  // ─── POLLING ───────────────────────────────────
  POLL_INTERVAL_MS: 12000,

  // ─── ORDER / BOOKING STATUS VALUES ────────────
  // These must match your backend exactly
  ORDER_STATUS: {
    PENDING:     "pending",
    CONFIRMED:   "confirmed",
    IN_PROGRESS: "in_progress",
    DELIVERED:   "delivered",
    CANCELLED:   "cancelled",
  },
  BOOKING_STATUS: {
    PENDING:     "pending",
    CONFIRMED:   "confirmed",
    IN_PROGRESS: "in_progress",
    DELIVERED:   "delivered",
    CANCELLED:   "cancelled",
  },

  // ─── ROLES ─────────────────────────────────────
  ROLES: {
    USER:    "user",
    VENDOR:  "vendor",
    CREATOR: "creator",
  },

  // ─── FEATURE FLAGS ─────────────────────────────
  FEATURES: {
    VIDEO_SYSTEM:    false,
    FOLLOW_SYSTEM:   false,
    BRAIN_LAYOUT:    false,
    PAYMENT_LAYER:   false,
    REALTIME_WS:     false,
    NOTIFICATIONS:   false,
  },

  NOTIFICATION_TYPES: {
    NEW_ORDER:      "new_order",
    ORDER_UPDATE:   "order_update",
    BOOKING_UPDATE: "booking_update",
    PROMO:          "promo",
  },

  APP_NAME:    "WorkToGo",
  APP_VERSION: "1.0.0",
};

Object.freeze(CONFIG);
Object.freeze(CONFIG.ENDPOINTS);
Object.freeze(CONFIG.ROLES);
Object.freeze(CONFIG.FEATURES);
Object.freeze(CONFIG.ORDER_STATUS);
Object.freeze(CONFIG.BOOKING_STATUS);
