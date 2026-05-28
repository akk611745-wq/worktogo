/**
 * WorkToGo — Central Configuration
 * ─────────────────────────────────────────────
 * Change BASE_URL before deploy. All other settings are production-ready.
 * You can also set window.WTG_BASE_URL in a separate env config file.
 */

const CONFIG = {
  // ─── API ───────────────────────────────────────
  // Set your real backend URL here — no trailing slash
  BASE_URL: (typeof window !== "undefined" && window.WTG_BASE_URL) || "https://worktogo.in",

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
  REFRESH_TOKEN_KEY: "wtg_refresh_token",
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
    USER:             "customer",
    VENDOR:           "vendor",
    VENDOR_SERVICE:   "vendor_service",
    VENDOR_SHOPPING:  "vendor_shopping",
    ADMIN:            "admin",
    DELIVERY:         "delivery",
  },

  // ─── FEATURE FLAGS ─────────────────────────────
  FEATURES: {
    SERVICE_ONLY_MODE: true,
    SHOPPING_UI:       false,
    DEAD_ROUTE_GUARD:  true,
    VIDEO_SYSTEM:    false,
    FOLLOW_SYSTEM:   false,
    BRAIN_LAYOUT:    false,
    PAYMENT_LAYER:   false,
    REALTIME_WS:     false,
    NOTIFICATIONS:   false,
    VENDOR_APPLY:    false,
  },

  SERVICE_ONLY: {
    PILOT_MODE: true,
    CITY: "Haldwani",
    ENABLED_CITIES: ["Haldwani"],
    ENABLED_CATEGORIES: ["electrician", "plumber", "painting", "waterproofing", "cctv"],
    INSPECTION_REQUIRED_CATEGORIES: ["painting", "waterproofing", "cctv"],
    DISABLE_FAKE_NETWORKS: true,
    DISABLE_PROOF_TILES: true,
    DISABLE_MATERIAL_ACTIONS: true,
    INSPECTION_PRICE: 299,
    SUPPORT_LABEL: "Help",
    ROUTE_MESSAGE: "This feature is hidden during the Haldwani service pilot.",
    SUPPORT_PHONE: "+91 95285 44548",
    WHATSAPP_URL: "https://wa.me/919528544548?text=Hi%20WorkToGo%2C%20I%20need%20help%20with%20a%20service%20booking.",
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
Object.freeze(CONFIG.SERVICE_ONLY);
Object.freeze(CONFIG.ORDER_STATUS);
Object.freeze(CONFIG.BOOKING_STATUS);
