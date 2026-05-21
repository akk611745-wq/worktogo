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
  const profile = _customerProfile(user);

  container.innerHTML = `
    <div class="page account-page">
      <header class="page-header no-back">
        <h2>My Account</h2>
      </header>

      <div class="account-content">
        <!-- Profile Card -->
        <div class="profile-card">
          <div class="profile-avatar">${_initials(user)}</div>
          <div class="profile-info">
            <h3>${_escapeHtml(profile.name || "WorkToGo Customer")}</h3>
            <p class="phone-number">${_escapeHtml(_phoneLabel(profile.phone))}</p>
          </div>
        </div>

        <!-- Menu Items -->
        <div class="menu-list">
          <div class="menu-item" onclick="ROUTER.go('bookings')">
            <div class="menu-icon bookings-icon">📅</div>
            <div class="menu-body">
              <span>My Bookings</span>
              <p class="menu-sub">Track requests and booking status</p>
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
};

// ── Helpers ─────────────────────────────────────────────────────────────

function _initials(user) {
  const name = user?.name || "U";
  return name.split(" ").map(w => w[0]).join("").toUpperCase().slice(0, 2);
}

function _escapeHtml(str) {
  return UI.escapeHtml(str);
}

function _phoneLabel(phone = "") {
  const digits = String(phone || "").replace(/\D/g, "");
  if (!digits) return "Phone not available";
  return `+91 ${digits.slice(-10)}`;
}

function _customerProfile(user = {}) {
  return UI.customerProfile(user);
}
