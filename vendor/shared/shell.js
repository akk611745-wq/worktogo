/**
 * WorkToGo — Sidebar HTML template
 * Injected into every page via initShell().
 */
function getSidebarHTML() {
  const serviceOnly = Boolean(CONFIG.FEATURES?.SERVICE_ONLY_MODE);
  return `
<aside class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <div class="sidebar-logo">
      <div class="logo-mark">W</div>
      <div>
        <div class="logo-name">WorkToGo</div>
        <span class="logo-tag">Vendor Panel</span>
      </div>
    </div>
    <button class="sidebar-close" id="sidebarClose" title="Close menu">✕</button>
  </div>

  <!-- Vendor info -->
  <div class="vendor-strip">
    <div class="vendor-avatar" id="vendorAvatar">V</div>
    <div>
      <div class="vendor-name" id="vendorName">—</div>
      <div class="vendor-role" id="vendorRole">—</div>
    </div>
  </div>

  <!-- Role badge -->
  <div class="role-badge mt-1" id="roleBadge" style="margin-top:0.5rem;"></div>

  <nav class="sidebar-nav">
    <div class="nav-section">Main</div>
    <a href="dashboard.php" class="nav-item">
      <svg viewBox="0 0 20 20" fill="currentColor"><path d="M3 4a1 1 0 011-1h4a1 1 0 010 2H4a1 1 0 01-1-1zm0 5a1 1 0 011-1h4a1 1 0 010 2H4a1 1 0 01-1-1zm0 5a1 1 0 011-1h4a1 1 0 010 2H4a1 1 0 01-1-1zm8-10a1 1 0 011-1h4a1 1 0 010 2h-4a1 1 0 01-1-1zm0 5a1 1 0 011-1h4a1 1 0 010 2h-4a1 1 0 01-1-1zm0 5a1 1 0 011-1h4a1 1 0 010 2h-4a1 1 0 01-1-1z"/></svg>
      Dashboard
    </a>

    <!-- SERVICE VENDOR -->
    <div data-role="vendor_service">
      <div class="nav-section">Service</div>
      <a href="bookings.php" class="nav-item">
        <svg viewBox="0 0 20 20" fill="currentColor"><path d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zM4 8h12v8H4V8z"/></svg>
        Jobs
      </a>
    </div>

    <!-- SHOPPING VENDOR -->
    <div data-role="vendor_shopping" data-feature="shopping-vendor-ui" ${serviceOnly ? 'style="display:none"' : ''}>
      <div class="nav-section">Shop</div>
      <a href="products.php" class="nav-item">
        <svg viewBox="0 0 20 20" fill="currentColor"><path d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V5a2 2 0 00-2-2H4zm0 2h12v10H4V5zm2 2v2h8V7H6zm0 4v2h5v-2H6z"/></svg>
        Products
      </a>
      <a href="orders.php" class="nav-item">
        <svg viewBox="0 0 20 20" fill="currentColor"><path d="M3 1a1 1 0 000 2h1.22l.305 1.222a.997.997 0 00.01.042l1.358 5.43-.893.892C3.74 11.846 4.632 14 6.414 14H15a1 1 0 000-2H6.414l1-1H14a1 1 0 00.894-.553l3-6A1 1 0 0017 3H6.28l-.31-1.243A1 1 0 005 1H3zm12 15a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM6.5 16a1.5 1.5 0 100-3 1.5 1.5 0 000 3z"/></svg>
        Orders
      </a>
    </div>

    <div class="nav-section">Account</div>
    <a href="profile.php" class="nav-item">
      <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z"/></svg>
      Profile
    </a>
  </nav>

  <div class="sidebar-footer">
    <button class="nav-item" onclick="Auth.logout()" style="width:100%;color:#ef4444;">
      <svg viewBox="0 0 20 20" fill="currentColor" style="width:16px;height:16px;"><path fill-rule="evenodd" d="M3 3a1 1 0 00-1 1v12a1 1 0 102 0V4a1 1 0 00-1-1zm10.293 9.293a1 1 0 001.414 1.414l3-3a1 1 0 000-1.414l-3-3a1 1 0 10-1.414 1.414L14.586 9H7a1 1 0 100 2h7.586l-1.293 1.293z"/></svg>
      Log Out
    </button>
  </div>
</aside>`;
}

/**
 * Build the full page shell.
 * Call at top of each protected page body.
 * @param {string} pageTitle  - Shown in topbar
 * @param {string} contentId  - ID to give the page-content div
 */
function initShell(pageTitle, contentId = "pageContent") {
  const user = Auth.guard();
  if (!user) return null;

  if (typeof WTGPush !== 'undefined') WTGPush.register();

  const topbar = `
<header class="topbar">
  <button class="menu-toggle" id="menuToggle" title="Menu">
    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z"/></svg>
  </button>
  <span class="topbar-title">${pageTitle}</span>
  <div class="topbar-spacer"></div>
  <div class="topbar-actions">
    <!-- Account/system notification bell (FCM-backed, separate from RealtimeEngine's live-order bell) -->
    <div class="wtg-notif-wrap" id="wtgNotifWrap">
      <button class="wtg-notif-btn" id="wtgNotifBtn" title="Notifications">
        🔔<span class="wtg-notif-badge" id="wtgNotifBadge" style="display:none;">0</span>
      </button>
      <div class="wtg-notif-panel" id="wtgNotifPanel">
        <div class="wtg-notif-panel-header">Notifications</div>
        <div class="wtg-notif-list" id="wtgNotifList"><div class="wtg-notif-empty">No notifications yet</div></div>
      </div>
    </div>
    <!-- Notification bell injected here by RealtimeEngine -->
    <div class="refresh-indicator" id="refreshIndicator" style="display:none;">
      <span class="refresh-dot"></span>
      <span>${CONFIG.FEATURES?.VENDOR_REALTIME_LABEL ? 'Live' : 'Refresh'}</span>
    </div>
    <button class="topbar-logout" onclick="Auth.logout()">
      <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 3a1 1 0 00-1 1v12a1 1 0 102 0V4a1 1 0 00-1-1zm10.293 9.293a1 1 0 001.414 1.414l3-3a1 1 0 000-1.414l-3-3a1 1 0 10-1.414 1.414L14.586 9H7a1 1 0 100 2h7.586l-1.293 1.293z"/></svg>
      Logout
    </button>
  </div>
</header>`;

  document.body.innerHTML = `
<div class="shell">
  ${getSidebarHTML()}
  <div class="main">
    ${topbar}
    <div class="page-content" id="${contentId}">
      <!-- Page content injected here -->
    </div>
  </div>
</div>`;

  // Init UI components NOW — DOM exists at this point.
  // app.js DOMContentLoaded fires before this function runs,
  // so sidebar, toasts, vendor info and active nav must be
  // wired here, not there.
  if (typeof initSidebar      === 'function') initSidebar();
  if (typeof initToasts       === 'function') initToasts();
  if (typeof renderVendorInfo === 'function') renderVendorInfo();
  if (typeof setActiveNav     === 'function') setActiveNav();

  _initWtgNotifBell();

  return user;
}

/**
 * Account/system notification bell (FCM-backed feed from the
 * notifications table) — distinct from RealtimeEngine's live
 * new-order/booking popup bell.
 */
let _wtgNotifPolling = null;

function _wtgEscapeHtml(s) {
  const d = document.createElement('div');
  d.textContent = s || '';
  return d.innerHTML;
}

function _wtgFmtNotifTime(dateStr) {
  try {
    return new Date(dateStr.replace(' ', 'T')).toLocaleString('en-IN', {
      day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: true,
    });
  } catch (e) { return dateStr; }
}

async function _wtgFetchNotifications() {
  try {
    const res = await API.get('/api/auth/notifications');
    return res?.data?.data || {};
  } catch (e) { return {}; }
}

async function _wtgMarkNotificationsRead() {
  try { await API.post('/api/auth/notifications/read'); } catch (e) {}
}

function _wtgRenderNotifBadge(count) {
  const badge = document.getElementById('wtgNotifBadge');
  if (!badge) return;
  if (count > 0) {
    badge.style.display = 'flex';
    badge.textContent = count > 99 ? '99+' : count;
  } else {
    badge.style.display = 'none';
  }
}

function _wtgRenderNotifList(items) {
  const list = document.getElementById('wtgNotifList');
  if (!list) return;
  if (!items || !items.length) {
    list.innerHTML = '<div class="wtg-notif-empty">No notifications yet</div>';
    return;
  }
  list.innerHTML = items.map(n => `
    <div class="wtg-notif-item ${n.is_read ? '' : 'unread'}">
      <div class="wtg-notif-msg">${_wtgEscapeHtml(n.title)}</div>
      <div class="wtg-notif-time">${_wtgFmtNotifTime(n.created_at)}</div>
    </div>
  `).join('');
}

function _initWtgNotifBell() {
  const btn = document.getElementById('wtgNotifBtn');
  const panel = document.getElementById('wtgNotifPanel');
  if (!btn || !panel) return;

  _injectWtgNotifStyles();

  btn.addEventListener('click', async (e) => {
    e.stopPropagation();
    const isOpen = panel.classList.toggle('open');
    if (isOpen) {
      const data = await _wtgFetchNotifications();
      _wtgRenderNotifList(data.notifications);
      if ((data.unread_count || 0) > 0) {
        await _wtgMarkNotificationsRead();
        _wtgRenderNotifBadge(0);
      }
    }
  });

  document.addEventListener('click', (e) => {
    if (!panel.contains(e.target) && e.target !== btn) panel.classList.remove('open');
  });

  const poll = async () => {
    const data = await _wtgFetchNotifications();
    _wtgRenderNotifBadge(data.unread_count || 0);
  };
  poll();
  if (_wtgNotifPolling) clearInterval(_wtgNotifPolling);
  _wtgNotifPolling = setInterval(poll, 60000);
}

function _injectWtgNotifStyles() {
  if (document.getElementById('wtg-notif-styles')) return;
  const style = document.createElement('style');
  style.id = 'wtg-notif-styles';
  style.textContent = `
    .wtg-notif-wrap { position:relative; }
    .wtg-notif-btn {
      width:38px;height:38px;border-radius:10px;
      background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.1);
      cursor:pointer;font-size:1.1rem;
      display:flex;align-items:center;justify-content:center;position:relative;
    }
    .wtg-notif-badge {
      position:absolute;top:-4px;right:-4px;
      background:#ef4444;color:#fff;border-radius:20px;
      min-width:16px;height:16px;font-size:9px;font-weight:700;
      display:flex;align-items:center;justify-content:center;padding:0 3px;
    }
    .wtg-notif-panel {
      display:none;position:absolute;top:calc(100% + 8px);right:0;
      width:300px;max-height:400px;
      background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);
      border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,0.25);
      z-index:9999;overflow:hidden;flex-direction:column;
    }
    .wtg-notif-panel.open { display:flex; }
    .wtg-notif-panel-header {
      padding:0.75rem 1rem;border-bottom:1px solid var(--border,#e2e8f0);
      font-weight:600;font-size:0.85rem;
    }
    .wtg-notif-list { overflow-y:auto;max-height:320px; }
    .wtg-notif-empty { padding:2rem;text-align:center;color:#6b7280;font-size:0.82rem; }
    .wtg-notif-item { padding:0.75rem 1rem;border-bottom:1px solid rgba(0,0,0,0.05); }
    .wtg-notif-item.unread { background:rgba(245,166,35,0.08); }
    .wtg-notif-msg { font-size:0.82rem;line-height:1.4; }
    .wtg-notif-time { font-size:0.7rem;color:#6b7280;margin-top:2px; }
  `;
  document.head.appendChild(style);
}
