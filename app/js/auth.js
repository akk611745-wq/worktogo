/**
 * WorkToGo — Auth & Session Manager
 */

const AUTH = (() => {

  function saveSession(token, user) {
    localStorage.setItem(CONFIG.TOKEN_KEY, token);
    localStorage.setItem(CONFIG.USER_KEY, JSON.stringify(user));
  }

  function getUser() {
    try { return JSON.parse(localStorage.getItem(CONFIG.USER_KEY)) || null; }
    catch { return null; }
  }

  function getToken() {
    return localStorage.getItem(CONFIG.TOKEN_KEY) || null;
  }

  function isLoggedIn() {
    return !!getToken() && !!getUser();
  }

  function logout() {
    localStorage.removeItem(CONFIG.TOKEN_KEY);
    localStorage.removeItem(CONFIG.USER_KEY);
    localStorage.removeItem(CONFIG.SESSION_KEY);
    ROUTER.go("login");
  }

  function getRole() {
    const user = getUser();
    return user?.role || CONFIG.ROLES.USER;
  }

  function hasRole(role) {
    return getRole() === role;
  }

  function requireAuth() {
    if (!isLoggedIn()) {
      ROUTER.go("login");
      return false;
    }
    return true;
  }

  // ── OTP Login Flow ─────────────────────────────────────────────────────

  async function sendOtp(phone) {
    return API.sendOtp(phone);
  }

  async function verifyAndLogin(phone, otp) {
    const result = await API.verifyOtp(phone, otp);
    if (result.ok && result.data?.token) {
      const { token, user } = result.data;
      saveSession(token, user);
      return { ok: true, user };
    }
    return { ok: false, error: result.error || result.data?.message || "Login failed" };
  }

  return {
    sendOtp, verifyAndLogin,
    logout, getUser, getToken, isLoggedIn, requireAuth, getRole, hasRole,
  };
})();
