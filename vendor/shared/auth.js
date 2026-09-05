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
    
    // Vendor approved but the JWT still carries the old 'customer' role — a fresh
    // sign-in mints the vendor token. Guide them with a clean overlay, not a
    // blocking alert() that reads like an error (role-02 polish).
    if (role === 'customer') {
      _showReloginOverlay(
        '🎉 Your vendor account is approved',
        'Please sign in again to open your vendor dashboard.',
        'Sign in again'
      );
      return null;
    }

    // Any non-vendor role reaching the panel: guide a re-login the same clean way.
    if (role !== CONFIG.ROLES.SERVICE && role !== CONFIG.ROLES.SHOPPING) {
      _showReloginOverlay(
        'Please sign in again',
        'Your session needs a refresh to access the vendor panel.',
        'Sign in again'
      );
      return null;
    }

    return user;
  }

  /**
   * Clean, non-blocking re-login prompt (replaces the old alert()+logout()).
   * Self-contained (inline styles), mobile-first, XSS-safe. The button performs
   * the logout → redirect to the login page.
   */
  function _showReloginOverlay(title, message, cta) {
    if (document.getElementById('wtg-relogin-overlay')) return;
    const esc = (s) => { const d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; };
    const o = document.createElement('div');
    o.id = 'wtg-relogin-overlay';
    o.setAttribute('role', 'dialog');
    o.setAttribute('aria-modal', 'true');
    o.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(10,12,20,.72);' +
      'display:flex;align-items:center;justify-content:center;padding:24px;';
    o.innerHTML =
      '<div style="background:#fff;border-radius:16px;max-width:340px;width:100%;padding:24px;' +
      'box-shadow:0 12px 40px rgba(0,0,0,.3);text-align:center;font-family:system-ui,-apple-system,sans-serif;">' +
        '<div style="font-size:17px;font-weight:700;color:#111;margin-bottom:8px;">' + esc(title) + '</div>' +
        '<div style="font-size:14px;color:#555;line-height:1.5;margin-bottom:20px;">' + esc(message) + '</div>' +
        '<button id="wtg-relogin-btn" style="width:100%;background:#e8681a;color:#fff;border:none;' +
        'border-radius:10px;padding:13px;font-size:15px;font-weight:700;cursor:pointer;">' + esc(cta) + '</button>' +
      '</div>';
    document.body.appendChild(o);
    const btn = document.getElementById('wtg-relogin-btn');
    if (btn) btn.addEventListener('click', logout);
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
