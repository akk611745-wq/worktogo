/**
 * WorkToGo Admin Panel — Auth Module
 */

const Auth = {
  isLoggedIn() {
    const token = localStorage.getItem(CONFIG.TOKEN_KEY);
    const expiry = localStorage.getItem("wtg_admin_expiry");
    if (!token) return false;
    if (expiry && Date.now() > parseInt(expiry)) {
      this.clearSession();
      return false;
    }
    return true;
  },

  async login(email, password) {
    const data = await API.post(CONFIG.ENDPOINTS.LOGIN, { phone: email, password });
    if (data.token) {
      localStorage.setItem(CONFIG.TOKEN_KEY, data.token);
      if (data.refreshToken) localStorage.setItem(CONFIG.REFRESH_TOKEN_KEY, data.refreshToken);
      const expiry = Date.now() + CONFIG.SESSION_DURATION * 1000;
      localStorage.setItem("wtg_admin_expiry", expiry.toString());
      localStorage.setItem("wtg_admin_user", JSON.stringify(data.admin || {}));
    }
    return data;
  },

  async logout() {
    try { await API.post(CONFIG.ENDPOINTS.LOGOUT); } catch(_) {}
    this.clearSession();
    window.location.href = "/admin/index.html";
  },

  clearSession() {
    localStorage.removeItem(CONFIG.TOKEN_KEY);
    localStorage.removeItem(CONFIG.REFRESH_TOKEN_KEY);
    localStorage.removeItem("wtg_admin_expiry");
    localStorage.removeItem("wtg_admin_user");
  },

  getUser() {
    try { return JSON.parse(localStorage.getItem("wtg_admin_user") || "{}"); } catch(_) { return {}; }
  },

  requireAuth() {
    if (!this.isLoggedIn()) {
      window.location.href = "/admin/index.html";
    }
  },
};
