/**
 * WorkToGo Admin Panel — API Client
 * Central HTTP layer. All modules import from here.
 */

class APIClient {
  constructor() {
    this.baseURL = CONFIG.API_BASE_URL;
    this.adminPrefix = CONFIG.ADMIN_PREFIX;
    this.timeout = CONFIG.REQUEST_TIMEOUT;
  }

  _getHeaders() {
    const token = localStorage.getItem(CONFIG.TOKEN_KEY);
    return {
      "Content-Type": "application/json",
      "Accept": "application/json",
      ...(token ? { "Authorization": `Bearer ${token}` } : {}),
    };
  }

  async _request(method, endpoint, body = null, params = {}) {
    // Build query string
    const qs = Object.keys(params).length
      ? "?" + new URLSearchParams(params).toString()
      : "";

    // Intelligently apply admin prefix.
    // Skip prefix when:
    //   - endpoint starts with /auth  (auth routes have no admin prefix)
    //   - endpoint starts with /api/  (already fully-qualified path — adding
    //     adminPrefix would produce /heart/api/admin/api/admin/... double-prefix)
    const isAuth    = endpoint.startsWith('/auth');
    const isFullApi = endpoint.startsWith('/api/');
    const url = this.baseURL + (isAuth || isFullApi ? '' : this.adminPrefix) + endpoint + qs;

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), this.timeout);

    try {
      const res = await fetch(url, {
        method,
        headers: this._getHeaders(),
        signal: controller.signal,
        ...(body ? { body: JSON.stringify(body) } : {}),
      });

      clearTimeout(timer);

      // Handle 401/403 — token expired or access denied
      if (res.status === 401 || res.status === 403) {
        Auth.clearSession();
        if (!window.IS_LOGIN_PAGE) {
          window.location.href = "/admin/index.html";
          return;
        }
      }

      const data = await res.json().catch(() => ({}));

      if (!res.ok) {
        throw { status: res.status, message: data.message || "Request failed", data };
      }

      return data;
    } catch (err) {
      clearTimeout(timer);
      if (err.name === "AbortError") {
        throw { status: 408, message: "Request timed out" };
      }
      throw err;
    }
  }

  get(endpoint, params = {})        { return this._request("GET",    endpoint, null,  params); }
  post(endpoint, body = {})         { return this._request("POST",   endpoint, body); }
  put(endpoint, body = {})          { return this._request("PUT",    endpoint, body); }
  patch(endpoint, body = {})        { return this._request("PATCH",  endpoint, body); }
  delete(endpoint)                  { return this._request("DELETE", endpoint); }
}

const API = new APIClient();
