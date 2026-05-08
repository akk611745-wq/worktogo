/**
 * WorkToGo — Auth Helper
 * Manages JWT token storage, retrieval, and session state.
 */

const Auth = (() => {
  /** Save token + user object after login */
  function setSession(token, user) {
    localStorage.setItem(CONFIG.TOKEN_KEY, token);
    localStorage.setItem(CONFIG.USER_KEY, JSON.stringify(user));
  }

  /** Get stored JWT (or null) */
  function getToken() {
    return localStorage.getItem(CONFIG.TOKEN_KEY);
  }

  /** Get stored user object (or null) */
  function getUser() {
    try {
      const raw = localStorage.getItem(CONFIG.USER_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  }

  /** Check if a valid token exists (does NOT verify server-side) */
  function isLoggedIn() {
    return !!getToken();
  }

  /** Get vendor role */
  function getRole() {
    const user = getUser();
    return user?.role || null;
  }

  /** Check if service vendor */
  function isService() {
    return getRole() === CONFIG.ROLES.SERVICE;
  }

  /** Check if shopping vendor */
  function isShopping() {
    return getRole() === CONFIG.ROLES.SHOPPING;
  }

  /** Clear session + redirect to login */
  function logout() {
    localStorage.removeItem(CONFIG.TOKEN_KEY);
    localStorage.removeItem(CONFIG.USER_KEY);
    window.location.href = "index.php";
  }

  /**
   * Guard — call at top of every protected page.
   * Redirects to login if not authenticated.
   * Returns user object if authenticated.
   */
  function guard() {
    if (!isLoggedIn()) {
      window.location.href = "index.php";
      return null;
    }
    
    const user = getUser();
    const role = user?.role;
    
    // BUG FIX: Check if user has vendor role
    if (role === 'customer') {
      // User is logged in but role is still customer (vendor application approved but not relogged in)
      alert('Your vendor application is approved. Please logout and login again.');
      logout();
      return null;
    }
    
    // BUG FIX: Ensure user has vendor role (vendor_service or vendor_shopping)
    if (role !== CONFIG.ROLES.SERVICE && role !== CONFIG.ROLES.SHOPPING) {
      alert('Access denied. Please logout and login again to access vendor panel.');
      logout();
      return null;
    }
    
    return user;
  }

  /** Auth header for API requests */
  function headers(extra = {}) {
    const token = getToken();
    return {
      "Content-Type": "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...extra,
    };
  }

  return { setSession, getToken, getUser, isLoggedIn, getRole, isService, isShopping, logout, guard, headers };
})();
