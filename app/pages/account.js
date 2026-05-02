/**
 * WorkToGo — Account Page
 * User profile + navigation. Role-aware structure.
 *
 * FIXES:
 *  - Navigation items now use ROUTER.go() instead of <a href="#page">
 *    (consistent with the routing fix — avoids hashchange edge cases)
 *  - XSS protection on user name/phone from stored data
 */

export async function render(container) {
  if (!AUTH.requireAuth()) return;
  const user = AUTH.getUser();
  const role = AUTH.getRole();

  container.innerHTML = `
    <div class="page account-page">
      <header class="page-header no-back">
        <h2>Account</h2>
      </header>

      <div class="account-content">
        <!-- Profile Card -->
        <div class="profile-card">
          <div class="profile-avatar">${_initials(user)}</div>
          <div class="profile-info">
            <h3>${_escapeHtml(user?.name || "User")}</h3>
            <p class="phone-number">+91 ${_escapeHtml(user?.phone || "—")}</p>
            <span class="role-chip role-${_escapeHtml(role)}">${_roleLabel(role)}</span>
          </div>
        </div>

        <!-- Menu Items -->
        <div class="menu-list">
          <div class="menu-section-title">My Activity</div>

          <!-- FIX: Use onclick ROUTER.go() for consistent routing -->
          <div class="menu-item" onclick="ROUTER.go('orders')">
            <div class="menu-icon orders-icon">📦</div>
            <div class="menu-body">
              <span>My Orders</span>
              <p class="menu-sub">Track &amp; manage orders</p>
            </div>
            <svg class="chevron" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>
          </div>

          <div class="menu-item" onclick="ROUTER.go('bookings')">
            <div class="menu-icon bookings-icon">📅</div>
            <div class="menu-body">
              <span>My Bookings</span>
              <p class="menu-sub">View service bookings</p>
            </div>
            <svg class="chevron" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>
          </div>

          <!-- Role-Based Section — future-ready, shown based on role -->
          <div class="menu-section-title">My Roles</div>

          <div class="menu-item ${role === CONFIG.ROLES.VENDOR ? "" : "menu-item-locked"}"
               onclick="${role === CONFIG.ROLES.VENDOR
                 ? "ROUTER.go('vendor')"
                 : "AccountPage.promptUpgrade('vendor')"}">
            <div class="menu-icon vendor-icon">🏪</div>
            <div class="menu-body">
              <span>Vendor Panel</span>
              <p class="menu-sub">${role === CONFIG.ROLES.VENDOR
                ? "Manage your store"
                : "Apply to become a vendor"}</p>
            </div>
            ${role === CONFIG.ROLES.VENDOR
              ? `<svg class="chevron" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>`
              : `<span class="lock-badge">Soon</span>`}
          </div>

          <div class="menu-item menu-item-locked" onclick="AccountPage.promptUpgrade('creator')">
            <div class="menu-icon creator-icon">🎬</div>
            <div class="menu-body">
              <span>Creator Studio</span>
              <p class="menu-sub">Video &amp; content — Phase 4</p>
            </div>
            <span class="lock-badge">Future</span>
          </div>

          <!-- Settings -->
          <div class="menu-section-title">Settings</div>

          <div class="menu-item" onclick="AccountPage.editProfile()">
            <div class="menu-icon">⚙️</div>
            <div class="menu-body">
              <span>Edit Profile</span>
              <p class="menu-sub">Name, preferences</p>
            </div>
            <svg class="chevron" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>
          </div>

          <div class="menu-item danger" onclick="AccountPage.logout()">
            <div class="menu-icon">🚪</div>
            <div class="menu-body"><span>Logout</span></div>
            <svg class="chevron" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>
          </div>
        </div>

        <p class="version-tag">${CONFIG.APP_NAME} v${CONFIG.APP_VERSION}</p>
      </div>

      ${UI.buildNav("account")}
    </div>
  `;
}

window.AccountPage = {
  logout() {
    if (confirm("Log out of WorkToGo?")) AUTH.logout();
  },
  editProfile() {
    UI.toast("Profile editing coming in Phase 2", "info");
  },
  promptUpgrade(role) {
    VendorApplyModal.show();
  },
};

// ── Helpers ─────────────────────────────────────────────────────────────

function _initials(user) {
  const name = user?.name || "U";
  return name.split(" ").map(w => w[0]).join("").toUpperCase().slice(0, 2);
}

function _roleLabel(role) {
  const map = {
    [CONFIG.ROLES.USER]:    "Customer",
    [CONFIG.ROLES.VENDOR]:  "Vendor",
    [CONFIG.ROLES.CREATOR]: "Creator",
  };
  return map[role] || "Customer";
}

function _escapeHtml(str) {
  return String(str || "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}
