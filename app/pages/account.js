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
  const isVendor = role === CONFIG.ROLES.VENDOR_SERVICE || role === CONFIG.ROLES.VENDOR_SHOPPING;
  const serviceOnly = Boolean(CONFIG.FEATURES?.SERVICE_ONLY_MODE);

  container.innerHTML = `
    <div class="page account-page">
      <header class="page-header no-back">
        <h2>${serviceOnly ? "Help & Account" : "Account"}</h2>
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
          <div class="menu-section-title">Service Account</div>

          <!-- FIX: Use onclick ROUTER.go() for consistent routing -->
          <div class="menu-item ${serviceOnly ? "feature-hidden" : ""}" onclick="ROUTER.go('orders')">
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
              <p class="menu-sub">Track requests and booking status</p>
            </div>
            <svg class="chevron" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>
          </div>

          <div class="menu-item whatsapp-menu-item" onclick="AccountPage.contactSupport()">
            <div class="menu-icon">💬</div>
            <div class="menu-body">
              <span>Support</span>
              <p class="menu-sub">Get help with booking ID or service questions</p>
            </div>
            <svg class="chevron" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>
          </div>

          <div class="menu-item" onclick="AccountPage.joinPartner()">
            <div class="menu-icon vendor-icon">🤝</div>
            <div class="menu-body">
              <span>Join Partner</span>
              <p class="menu-sub">For local service providers</p>
            </div>
            <svg class="chevron" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>
          </div>

          <!-- Role-Based Section — future-ready, shown based on role -->
          <div class="menu-section-title ${serviceOnly && !isVendor ? "feature-hidden" : ""}">My Roles</div>

          <div class="menu-item ${isVendor ? "" : "menu-item-locked"} ${serviceOnly && !isVendor ? "feature-hidden" : ""}"
               onclick="${isVendor
                 ? "AccountPage.openVendorPanel()"
                 : "AccountPage.promptUpgrade('vendor')"}">
            <div class="menu-icon vendor-icon">🏪</div>
            <div class="menu-body">
              <span>Vendor Panel</span>
              <p class="menu-sub">${isVendor
                ? "Manage your store"
                : "Apply to become a vendor"}</p>
            </div>
            ${isVendor
              ? `<svg class="chevron" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>`
              : `<span class="lock-badge">Soon</span>`}
          </div>

          <div class="menu-item menu-item-locked ${serviceOnly ? "feature-hidden" : ""}" onclick="AccountPage.promptUpgrade('creator')">
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
              <span>Profile</span>
              <p class="menu-sub">Name and basic details</p>
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
    UI.toast("Profile editing is temporarily manual during service launch", "info");
  },
  contactSupport() {
    if (CONFIG.SERVICE_ONLY?.WHATSAPP_URL) {
      window.open(CONFIG.SERVICE_ONLY.WHATSAPP_URL, "_blank", "noopener");
      return;
    }
    UI.toast(`For urgent service help, contact WorkToGo support ${CONFIG.SERVICE_ONLY?.SUPPORT_PHONE || ""} with your booking ID.`, "info", 4500);
  },
  joinPartner() {
    const text = "Hi WorkToGo, I want to join as a local service partner in Haldwani.";
    const base = CONFIG.SERVICE_ONLY?.WHATSAPP_URL;
    if (base) {
      window.open(base + encodeURIComponent(` ${text}`), "_blank", "noopener");
      return;
    }
    UI.toast("Partner onboarding is handled manually during the Haldwani pilot.", "info", 4500);
  },
  openVendorPanel() {
    window.location.href = "/vendor/dashboard.php";
  },
  promptUpgrade(role) {
    if (CONFIG.FEATURES?.SERVICE_ONLY_MODE || !CONFIG.FEATURES?.VENDOR_APPLY) {
      UI.toast("Vendor applications are manually handled during service launch.", "info");
      return;
    }
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
    [CONFIG.ROLES.VENDOR_SERVICE]:  "Service Vendor",
    [CONFIG.ROLES.VENDOR_SHOPPING]: "Shopping Vendor",
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
