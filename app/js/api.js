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
    return _request("POST", "", { intent: intent, data: payload });
  }

  function _firstBlock(response, engine = "service") {
    const blocks = response?.data?.blocks || [];
    return blocks.find(b => b.engine === engine) || blocks[0] || null;
  }

  function _unwrapServicePayload(response) {
    const block = _firstBlock(response, "service");
    const data = block?.data || block?.payload || response?.data?.data || response?.data || {};
    return {
      services: data.services || block?.services || block?.items || [],
      categories: data.categories || block?.categories || [],
      pilot_config: data.pilot_config || block?.pilot_config || {},
      total: data.total ?? block?.total ?? 0,
    };
  }

  function _unwrapBookingsPayload(response) {
    const block = _firstBlock(response, "service");
    const data = block?.data || block?.payload || response?.data?.data || response?.data || {};
    return {
      bookings: data.bookings || block?.bookings || block?.items || [],
      total: data.total ?? block?.total ?? 0,
    };
  }

  function _unwrapBookingPayload(response) {
    const block = _firstBlock(response, "service");
    const data = block?.data || block?.payload || response?.data?.data || response?.data || {};
    return data.booking || data;
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
      return _request("POST", "/api/auth/google", { google_token: credential });
    },

    // ── Catalog (Intent Pipeline) ───────────────────────────────────────
    async getProducts() {
      return _intent("shopping:list_products");
    },

    async getServices() {
      const res = await _intent("service:list_services");
      return res.ok ? { ...res, data: _unwrapServicePayload(res) } : res;
    },

    async getPublicSettings() {
      return _request("GET", "/api/settings");
    },

    async search(query, type = "services", limit = 20) {
      const params = new URLSearchParams({ q: query, type, limit: String(limit) });
      return _request("GET", `/api/search?${params.toString()}`);
    },

    // ── Orders (Intent Pipeline) ────────────────────────────────────────
    async getOrders() {
      return _intent("shopping:list_orders");
    },

    createOrder: (payload) => _intent('shopping:create_order', payload),
    addToCart: (payload) => _intent('shopping:add_to_cart', payload),

    // ── Bookings (Intent Pipeline) ──────────────────────────────────────
    async getBookings() {
      const res = await _intent("service:list_bookings");
      return res.ok ? { ...res, data: _unwrapBookingsPayload(res) } : res;
    },

    async createBooking(payload) {
      const res = await _intent("service:create_booking", payload);
      return res.ok ? { ...res, data: _unwrapBookingPayload(res) } : res;
    },

    async getPaymentStatus(params = {}) {
      const query = new URLSearchParams(params).toString();
      return _request("GET", `/api/payment/status${query ? `?${query}` : ""}`);
    },

    // ── Profile (Intent Pipeline) ───────────────────────────────────────
    async getProfile() {
      return _intent("user:get_profile");
    },
  };
})();
