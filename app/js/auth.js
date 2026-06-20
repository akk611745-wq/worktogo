/**
 * WorkToGo — Auth & Session Manager
 */

const AUTH = (() => {

  function saveSession(token, user, refreshToken = null) {
    localStorage.setItem(CONFIG.TOKEN_KEY, token);
    localStorage.setItem(CONFIG.USER_KEY, JSON.stringify(user));
    if (refreshToken) localStorage.setItem(CONFIG.REFRESH_TOKEN_KEY, refreshToken);
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
    localStorage.removeItem(CONFIG.REFRESH_TOKEN_KEY);
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
      refreshToken: data.refresh_token || data.refreshToken || "",
      user: data.user || data.profile || null,
    };
  }

  function _saveAuthResult(result, fallbackError) {
    if (result.ok) {
      const { token, refreshToken, user } = _sessionFromResult(result);
      if (token && user) {
        saveSession(token, user, refreshToken);
        return { ok: true, user };
      }
    }
    return { ok: false, error: result.error || result.data?.message || fallbackError };
  }

  function requireAuth() {
    if (!isLoggedIn()) {
      try {
        const route = (location.hash || "").replace(/^#/, "") || "home";
        if (route && route !== "login" && route !== "home") sessionStorage.setItem("wtg_post_login_route", route);
      } catch {}
      ROUTER.go("login");
      return false;
    }
    return true;
  }

  function resolvePostLogin() {
    let pendingBooking = null;
    let route = "";
    let homeState = null;
    try { pendingBooking = sessionStorage.getItem("wtg_pending_booking"); } catch {}
    try { route = sessionStorage.getItem("wtg_post_login_route") || ""; } catch {}
    try { homeState = sessionStorage.getItem("wtg_home_state"); } catch {}

    if (pendingBooking) {
      try { sessionStorage.setItem("wtg_resume_booking_once", "1"); } catch {}
      ROUTER.go("home", true);
      return;
    }

    if (route && route !== "login") {
      try { sessionStorage.removeItem("wtg_post_login_route"); } catch {}
      ROUTER.go(route, true);
      return;
    }

    if (homeState) {
      ROUTER.go("home", true);
      return;
    }

    ROUTER.go("home", true);
  }

  // ── OTP Login Flow ─────────────────────────────────────────────────────

  async function sendOtp(phone) {
    return API.sendOtp(phone);
  }

  async function verifyAndLogin(phone, otp) {
    const result = await API.verifyOtp(phone, otp);
    if (result.ok && result.data?.data?.token) {
      const { token, refresh_token, refreshToken, user } = result.data.data;
      saveSession(token, user, refresh_token || refreshToken || null);
      return { ok: true, user };
    }
    return { ok: false, error: result.error || result.data?.message || "Login failed" };
  }

  async function verifyWidgetToken(phone, accessToken) {
    const result = await API.verifyWidgetToken(phone, accessToken);
    return _saveAuthResult(result, "OTP verification failed");
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
    sendOtp, verifyAndLogin, verifyWidgetToken,
    emailLogin, emailRegister, googleLogin,
    logout, getUser, getToken, isLoggedIn, requireAuth, resolvePostLogin, getRole, hasRole,
  };
})();
