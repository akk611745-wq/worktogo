/**
 * WorkToGo — Sidebar HTML template
 * Injected into every page via initShell().
 */
function getSidebarHTML() {
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
    <a href="dashboard.html" class="nav-item">
      <svg viewBox="0 0 20 20" fill="currentColor"><path d="M3 4a1 1 0 011-1h4a1 1 0 010 2H4a1 1 0 01-1-1zm0 5a1 1 0 011-1h4a1 1 0 010 2H4a1 1 0 01-1-1zm0 5a1 1 0 011-1h4a1 1 0 010 2H4a1 1 0 01-1-1zm8-10a1 1 0 011-1h4a1 1 0 010 2h-4a1 1 0 01-1-1zm0 5a1 1 0 011-1h4a1 1 0 010 2h-4a1 1 0 01-1-1zm0 5a1 1 0 011-1h4a1 1 0 010 2h-4a1 1 0 01-1-1z"/></svg>
      Dashboard
    </a>

    <!-- SERVICE VENDOR -->
    <div data-role="vendor_service">
      <div class="nav-section">Service</div>
      <a href="bookings.html" class="nav-item">
        <svg viewBox="0 0 20 20" fill="currentColor"><path d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zM4 8h12v8H4V8z"/></svg>
        Jobs
      </a>
    </div>

    <!-- SHOPPING VENDOR -->
    <div data-role="vendor_shopping">
      <div class="nav-section">Shop</div>
      <a href="products.html" class="nav-item">
        <svg viewBox="0 0 20 20" fill="currentColor"><path d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V5a2 2 0 00-2-2H4zm0 2h12v10H4V5zm2 2v2h8V7H6zm0 4v2h5v-2H6z"/></svg>
        Products
      </a>
      <a href="orders.html" class="nav-item">
        <svg viewBox="0 0 20 20" fill="currentColor"><path d="M3 1a1 1 0 000 2h1.22l.305 1.222a.997.997 0 00.01.042l1.358 5.43-.893.892C3.74 11.846 4.632 14 6.414 14H15a1 1 0 000-2H6.414l1-1H14a1 1 0 00.894-.553l3-6A1 1 0 0017 3H6.28l-.31-1.243A1 1 0 005 1H3zm12 15a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM6.5 16a1.5 1.5 0 100-3 1.5 1.5 0 000 3z"/></svg>
        Orders
      </a>
    </div>

    <div class="nav-section">Account</div>
    <a href="profile.html" class="nav-item">
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

  const topbar = `
<header class="topbar">
  <button class="menu-toggle" id="menuToggle" title="Menu">
    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z"/></svg>
  </button>
  <span class="topbar-title">${pageTitle}</span>
  <div class="topbar-spacer"></div>
  <div class="topbar-actions">
    <!-- Notification bell injected here by RealtimeEngine -->
    <div class="refresh-indicator" id="refreshIndicator" style="display:none;">
      <span class="refresh-dot"></span>
      <span>Live</span>
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

  return user;
}
