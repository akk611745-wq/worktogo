/**
 * WorkToGo — Account Page
 * User profile + navigation. Role-aware structure.
 *
 * FIXES:
 *  - Navigation items now use ROUTER.go() instead of <a href="#page">
 *    (consistent with the routing fix — avoids hashchange edge cases)
 *  - XSS protection on user name/phone from stored data
 */

let _container = null;

export async function render(container) {
  _container = container;
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
            <div style="display:flex;align-items:center;gap:8px">
              <h3 style="margin:0">${_escapeHtml(profile.name || _phoneLabel(profile.phone))}</h3>
              <button onclick="AccountPage.editProfile()" style="padding:3px 10px;font-size:12px;border:1px solid var(--clr-border);border-radius:6px;background:transparent;color:var(--clr-text-2);cursor:pointer;line-height:1.4">Edit</button>
            </div>
            ${profile.name ? `<p class="phone-number">${_escapeHtml(_phoneLabel(profile.phone))}</p>` : ""}
          </div>
        </div>

        <!-- Menu Items -->
        <div class="menu-list">
          <div class="menu-item" onclick="ROUTER.go('bookings')">
            <div class="menu-icon bookings-icon">📅</div>
            <div class="menu-body">
              <span>Bookings & request tracking</span>
              <p class="menu-sub">Worker confirmation, inspection and status updates</p>
            </div>
            <svg class="chevron" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>
          </div>

          <div class="menu-item" onclick="ROUTER.go('home')">
            <div class="menu-icon">🧰</div>
            <div class="menu-body">
              <span>Start a local request</span>
              <p class="menu-sub">Free booking or inspection from Home</p>
            </div>
            <svg class="chevron" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>
          </div>

          <div class="menu-item" onclick="UI.openSupport('selector')">
            <div class="menu-icon">💬</div>
            <div class="menu-body">
              <span>Help &amp; Support</span>
              <p class="menu-sub">Help with booking ID, worker timing or saved details</p>
            </div>
            <svg class="chevron" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>
          </div>

          ${AUTH.hasRole(CONFIG.ROLES.VENDOR) ? `
          <div class="menu-item" onclick="window.location.href='/vendor/'">
            <div class="menu-icon">🔧</div>
            <div class="menu-body">
              <span>Vendor Panel</span>
              <p class="menu-sub">Manage your jobs and availability</p>
            </div>
            <svg class="chevron" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>
          </div>` : ""}

          ${!AUTH.hasRole(CONFIG.ROLES.VENDOR) ? `
          <div class="menu-item" onclick="window.location.href='/vendor/'">
            <div class="menu-icon">🔧</div>
            <div class="menu-body">
              <span>Become a WorkToGo vendor</span>
              <p class="menu-sub">Register to receive service jobs</p>
            </div>
            <svg class="chevron" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>
          </div>` : ""}

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
  editProfile() {
    const profile = _customerProfile(AUTH.getUser());
    const card = document.querySelector('.profile-card');
    if (!card) return;
    card.innerHTML = `
      <div class="profile-edit-form" style="width:100%;padding:4px 0">
        <div style="display:flex;justify-content:flex-end;margin-bottom:8px">
          <button onclick="AccountPage._rerender()" title="Close" style="background:none;border:none;cursor:pointer;font-size:22px;color:var(--clr-text-2);padding:0;line-height:1">✕</button>
        </div>
        <label style="display:block;margin-bottom:10px;font-size:14px;color:var(--clr-text-2)">Name
          <input id="edit-name" type="text" value="${_escapeHtml(profile.name)}" placeholder="Your name"
            autocomplete="name"
            style="display:block;width:100%;margin-top:4px;padding:10px 12px;border:1.5px solid var(--clr-border);border-radius:8px;font-size:15px;background:var(--clr-surface-2);color:var(--clr-text-1);box-sizing:border-box"/>
        </label>
        <label style="display:block;margin-bottom:14px;font-size:14px;color:var(--clr-text-2)">Address
          <input id="edit-address" type="text" value="${_escapeHtml(profile.address)}" placeholder="Area / address for booking pre-fill"
            autocomplete="street-address"
            style="display:block;width:100%;margin-top:4px;padding:10px 12px;border:1.5px solid var(--clr-border);border-radius:8px;font-size:15px;background:var(--clr-surface-2);color:var(--clr-text-1);box-sizing:border-box"/>
        </label>
        <div style="display:flex;gap:10px">
          <button id="save-profile-btn" onclick="AccountPage.saveProfile()"
            style="flex:1;padding:11px;border-radius:8px;border:none;background:var(--clr-accent);color:#fff;font-size:15px;font-weight:600;cursor:pointer">Save</button>
          <button onclick="AccountPage._rerender()"
            style="flex:1;padding:11px;border-radius:8px;border:1px solid var(--clr-border);background:transparent;color:var(--clr-text-1);font-size:15px;cursor:pointer">Cancel</button>
        </div>
      </div>`;
  },
  async saveProfile() {
    const name    = document.getElementById('edit-name')?.value?.trim() || '';
    const address = document.getElementById('edit-address')?.value?.trim() || '';

    if (!name && !address) {
      UI.toast('Enter a name or address to save.', 'error');
      return;
    }

    const btn = document.getElementById('save-profile-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }

    const payload = {};
    if (name)    payload.name    = name;
    if (address) payload.address = address;

    const res = await API.updateProfile(payload).catch(() => ({ ok: false, error: 'Network error.' }));

    if (btn) { btn.disabled = false; btn.textContent = 'Save'; }

    if (!res.ok) {
      UI.toast(res.error || 'Could not save. Try again.', 'error');
      return;
    }

    // Keep localStorage in sync for booking form pre-fill
    try {
      const existing = JSON.parse(localStorage.getItem('wtg_customer_profile') || '{}');
      localStorage.setItem('wtg_customer_profile', JSON.stringify({ ...existing, name, address }));
    } catch {}

    // Sync name into the in-memory auth user so the header updates immediately
    try {
      const stored = JSON.parse(localStorage.getItem(CONFIG.USER_KEY) || '{}');
      if (stored && name) {
        stored.name = name;
        localStorage.setItem(CONFIG.USER_KEY, JSON.stringify(stored));
      }
    } catch {}

    UI.toast('Profile saved', 'success');
    AccountPage._rerender();
  },
  _rerender() { if (_container) render(_container); else ROUTER.go('account', true); },
  logout() {
    const modal = document.createElement('div');
    modal.innerHTML = `
      <div style="position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:9999;display:flex;align-items:center;justify-content:center;">
        <div style="background:var(--clr-surface-1);border-radius:16px;padding:24px;margin:16px;max-width:320px;width:100%;">
          <p style="color:var(--clr-text-1);font-size:16px;margin:0 0 20px;text-align:center;">Log out of WorkToGo?</p>
          <div style="display:flex;gap:12px;">
            <button id="_wtg_cancel" style="flex:1;padding:12px;border-radius:8px;border:1px solid var(--clr-border);background:transparent;color:var(--clr-text-1);font-size:15px;">Cancel</button>
            <button id="_wtg_confirm" style="flex:1;padding:12px;border-radius:8px;border:none;background:var(--clr-accent);color:#fff;font-size:15px;">Log out</button>
          </div>
        </div>
      </div>`;
    document.body.appendChild(modal);
    modal.querySelector('#_wtg_cancel').onclick = () => modal.remove();
    modal.querySelector('#_wtg_confirm').onclick = () => { modal.remove(); AUTH.logout(); };
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
