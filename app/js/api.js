/**
 * WorkToGo — API Service Layer
 * All HTTP calls go through here. Never call fetch() directly in pages.
 */

const API = (() => {

  const REQUEST_TIMEOUT_MS = 10000;

  function _getToken() {
    return localStorage.getItem(CONFIG.TOKEN_KEY) || "";
  }

  function _headers(extra = {}) {
    const token = _getToken();
    return {
      "Content-Type": "application/json",
      ...(token ? { "Authorization": `Bearer ${token}` } : {}),
      ...extra,
    };
  }

  async function _parseJSON(res) {
    const text = await res.text();
    try { return JSON.parse(text); } catch { return null; }
  }

  async function _request(method, path, body = null) {
    const url = `${CONFIG.BASE_URL}${path}`;
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);

    const opts = {
      method,
      headers: _headers(),
      signal: controller.signal,
    };
    if (body) opts.body = JSON.stringify(body);

    try {
      const res = await fetch(url, opts);
      clearTimeout(timeoutId);

      if (res.status === 401) {
        // If AUTH is available globally, logout on 401
        if (typeof AUTH !== 'undefined' && AUTH.logout) {
            AUTH.logout();
        }
        return { ok: false, error: "Session expired. Please login again." };
      }

      const data = await _parseJSON(res);

      if (!res.ok) {
        return {
          ok: false,
          status: res.status,
          error: data?.message || data?.error || `Server error (${res.status}). Please try again.`,
          data,
        };
      }

      return { ok: true, status: res.status, data };

    } catch (err) {
      clearTimeout(timeoutId);
      if (err.name === "AbortError") {
        return { ok: false, error: "Request timed out. Check your connection." };
      }
      if (!navigator.onLine) {
        return { ok: false, error: "No internet connection." };
      }
      return { ok: false, error: "Network error. Please try again." };
    }
  }

  /**
   * Pipeline Intent Caller
   * All business logic calls go through this.
   */
  async function _intent(intent, payload = {}) {
    return _request("POST", "", { intent, payload });
  }

  return {

    // ── Auth: OTP Flow (REST - Direct) ──────────────────────────────────
    async sendOtp(phone) {
      return _request("POST", CONFIG.ENDPOINTS.OTP_SEND, { phone });
    },

    async verifyOtp(phone, otp) {
      return _request("POST", CONFIG.ENDPOINTS.OTP_VERIFY, { phone, otp });
    },

    // ── Auth: Email / Google Flow (REST - Direct) ───────────────────────
    async emailLogin(email, password) {
      return _request("POST", "/api/auth/email/login", { email, password });
    },

    async emailRegister(payload) {
      return _request("POST", "/api/auth/email/register", payload);
    },

    async googleAuth(credential) {
      return _request("POST", "/api/auth/google", { credential });
    },

    // ── Catalog (Intent Pipeline) ───────────────────────────────────────
    async getProducts() {
      return _intent("shopping:list_products");
    },

    async getServices() {
      return _intent("service:list_services");
    },

    // ── Orders (Intent Pipeline) ────────────────────────────────────────
    async getOrders() {
      return _intent("shopping:list_orders");
    },

    async createOrder(payload) {
      return _intent("shopping:create_order", payload);
    },

    // ── Bookings (Intent Pipeline) ──────────────────────────────────────
    async getBookings() {
      return _intent("service:list_bookings");
    },

    async createBooking(payload) {
      return _intent("service:create_booking", payload);
    },

    // ── Profile (Intent Pipeline) ───────────────────────────────────────
    async getProfile() {
      return _intent("user:get_profile");
    },
  };
})();
