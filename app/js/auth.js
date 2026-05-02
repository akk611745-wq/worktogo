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

  function _sessionFromResult(result) {
    const data = result?.data?.data || result?.data || {};
    return {
      token: data.token || data.access_token || data.jwt || "",
      user: data.user || data.profile || null,
    };
  }

  function _saveAuthResult(result, fallbackError) {
    if (result.ok) {
      const { token, user } = _sessionFromResult(result);
      if (token && user) {
        saveSession(token, user);
        return { ok: true, user };
      }
    }
    return { ok: false, error: result.error || result.data?.message || fallbackError };
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
    if (result.ok && result.data?.data?.token) {
      const { token, user } = result.data.data;
      saveSession(token, user);
      return { ok: true, user };
    }
    return { ok: false, error: result.error || result.data?.message || "Login failed" };
  }

  // ── Email / Google Login Flow ──────────────────────────────────────────

  async function emailLogin(email, password) {
    const result = await API.emailLogin(email, password);
    return _saveAuthResult(result, "Email login failed");
  }

  async function emailRegister(payload) {
    const result = await API.emailRegister(payload);
    return _saveAuthResult(result, "Email registration failed");
  }

  async function googleLogin(credential) {
    const result = await API.googleAuth(credential);
    return _saveAuthResult(result, "Google login failed");
  }

  return {
    sendOtp, verifyAndLogin,
    emailLogin, emailRegister, googleLogin,
    logout, getUser, getToken, isLoggedIn, requireAuth, getRole, hasRole,
  };
})();
