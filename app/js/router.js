/**
 * WorkToGo — SPA Router
 * Hash-based routing. No heavy framework needed.
 *
 * FIXES:
 *  1. CRITICAL — Added hashchange listener.
 *     Original code only listened for 'popstate'. But <a href="#page"> clicks
 *     fire 'hashchange', NOT 'popstate'. This meant ALL nav link clicks were
 *     completely ignored. Now both events trigger routing.
 *
 *  2. Smart refresh — Added visibilitychange listener.
 *     When user switches back to the tab, an immediate refresh is triggered
 *     on pollable pages instead of waiting up to 12s for the next interval.
 *
 *  3. Improved router error state with a retry button.
 */

const ROUTER = (() => {

  const PAGES = {
    login:    () => import("../pages/login.js"),
    home:     () => import("../pages/home.js"),
    account:  () => import("../pages/account.js"),
    orders:   () => import("../pages/orders.js"),
    bookings: () => import("../pages/bookings.js"),
    // Phase 2: vendor: () => import('../pages/vendor.js'),
    // Phase 3: creator: () => import('../pages/creator.js'),
  };

  const PUBLIC_PAGES = ["login", "home", "register", "services", "products", "search"];
  const POLLABLE_PAGES = ["orders", "bookings"];

  let _currentPage = null;
  let _pollTimer   = null;
  let _isRendering = false; // prevent concurrent renders

  // ── Navigate ─────────────────────────────────────────────────────────

  function go(page, replace = false) {
    const hash = `#${page}`;
    if (replace) {
      history.replaceState(null, "", hash);
    } else {
      history.pushState(null, "", hash);
    }
    _render(page);
  }

  function _currentHash() {
    return (location.hash.slice(1) || "home");
  }

  // ── Render ────────────────────────────────────────────────────────────

  async function _render(page) {
    // Prevent double renders (e.g. rapid clicks)
    if (_isRendering) return;
    _isRendering = true;

    _stopPolling();

    const target = PAGES[page] ? page : "home";

    // Auth guard
    if (!PUBLIC_PAGES.includes(target) && !AUTH.isLoggedIn()) {
      _isRendering = false;
      go("login", true);
      return;
    }
    if (target === "login" && AUTH.isLoggedIn()) {
      _isRendering = false;
      go("home", true);
      return;
    }

    // Skip re-render if already on this page (e.g. tapping active nav tab)
    if (_currentPage === target && document.querySelector(`.${target}-page`)) {
      _isRendering = false;
      _startPolling(target);
      return;
    }

    _currentPage = target;
    const app = document.getElementById("app");
    app.innerHTML = `<div class="page-loading"><div class="spinner"></div></div>`;

    try {
      const mod = await PAGES[target]();
      app.innerHTML = "";
      await mod.render(app);
      _startPolling(target);
    } catch (err) {
      // FIX: Better error state with retry option
      app.innerHTML = `
        <div class="page" style="align-items:center;justify-content:center;">
          <div class="error-state">
            <div class="empty-icon">😕</div>
            <p>Failed to load page.</p>
            <button class="btn-retry" onclick="ROUTER.go('${target}')">Retry</button>
            <button class="btn-retry" style="margin-top:8px;opacity:0.6" onclick="ROUTER.go('home')">Go Home</button>
          </div>
        </div>`;
    } finally {
      _isRendering = false;
    }
  }

  // ── Auto-Refresh Polling ──────────────────────────────────────────────

  function _startPolling(page) {
    if (!POLLABLE_PAGES.includes(page)) return;

    _pollTimer = setInterval(async () => {
      // FIX: Skip if tab is hidden (already was there but ensure it's correct)
      if (document.hidden) return;
      // Skip if we've navigated away
      if (_currentPage !== page) return;

      try {
        const mod = await PAGES[page]();
        if (typeof mod.refresh === "function") {
          mod.refresh();
        }
      } catch {
        // Silently ignore poll errors — next interval will retry
      }
    }, CONFIG.POLL_INTERVAL_MS);
  }

  function _stopPolling() {
    if (_pollTimer) { clearInterval(_pollTimer); _pollTimer = null; }
  }

  // FIX: Smart refresh — when user returns to a pollable tab, refresh immediately
  // instead of waiting up to 12 seconds for the next interval
  function _onVisibilityChange() {
    if (document.hidden) return;
    if (!_currentPage || !POLLABLE_PAGES.includes(_currentPage)) return;
    if (!AUTH.isLoggedIn()) return;

    PAGES[_currentPage]().then(mod => {
      if (typeof mod.refresh === "function") {
        mod.refresh();
      }
    }).catch(() => {});
  }

  // ── Init ──────────────────────────────────────────────────────────────

  function init() {
    // FIX: Listen for BOTH popstate (back/forward button, history.pushState)
    // and hashchange (<a href="#page"> clicks fire hashchange, not popstate).
    // Without hashchange listener, ALL nav link clicks were silently ignored.
    window.addEventListener("popstate",    () => _render(_currentHash()));
    window.addEventListener("hashchange",  () => _render(_currentHash()));

    // FIX: Smart refresh when switching back to this tab
    document.addEventListener("visibilitychange", _onVisibilityChange);

    _render(_currentHash());
  }

  return { go, init };
})();
