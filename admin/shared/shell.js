/**
 * WorkToGo Admin — Page Shell Renderer
 * Injects sidebar + topbar into pages.
 * Usage: Shell.init({ title: 'Page Title', active: 'page.html' });
 */
const Shell = {
  SERVICE_ONLY_MODE: true,
  NAV: [
    { group: 'Overview' },
    { href: 'dashboard.html', icon: 'grid', label: 'Dashboard' },
    { href: 'morning.html',   icon: 'sun',  label: 'Morning Brief' },
    { group: 'People' },
    { href: 'users.html',    icon: 'users', label: 'Users' },
    { href: 'vendors.html',  icon: 'store', label: 'Vendors' },
    { group: 'Catalogue' },
    { href: 'products.html', icon: 'box',   label: 'Products', hiddenInServiceOnly: true },
    { href: 'services.html', icon: 'tool',  label: 'Services' },
    { group: 'Operations' },
    { href: 'pricing.html',  icon: 'tag',   label: 'Price Control' },
    { href: 'followup.html', icon: 'bell',  label: 'Follow-up Center' },
    { href: 'orders.html',   icon: 'list',  label: 'Orders', hiddenInServiceOnly: true },
    { href: 'delivery.html', icon: 'truck', label: 'Delivery', hiddenInServiceOnly: true },
    { href: 'payments.html', icon: 'credit-card', label: 'Payments', hiddenInServiceOnly: true },
    { group: 'System' },
    { href: 'system.html',   icon: 'settings', label: 'System Control', hiddenInServiceOnly: true },
    { href: 'logs.html',     icon: 'terminal', label: 'Logs & Activity', hiddenInServiceOnly: true },
  ],

  ICONS: {
    grid: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>`,
    sun:  `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>`,
    users:`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>`,
    store:`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>`,
    box:  `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>`,
    tool: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>`,
    list: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>`,
    truck:`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>`,
    'credit-card':`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>`,
    settings:`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 1.41 14.14M4.93 19.07A10 10 0 0 1 3.52 4.93"/><path d="M12 2v2M12 20v2M2 12H4M20 12h2"/></svg>`,
    terminal:`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>`,
    bell:    `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>`,
    tag:     `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>`,
    menu: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>`,
    logout:`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>`,
  },

  init({ title = 'Admin', active = '' } = {}) {
    // Build nav HTML
    let navHTML = '';
    for (let i = 0; i < this.NAV.length; i++) {
      const item = this.NAV[i];
      if (item.hiddenInServiceOnly && this.SERVICE_ONLY_MODE) continue;
      if (item.group) {
        let hasVisible = false;
        for (let j = i + 1; j < this.NAV.length && !this.NAV[j].group; j++) {
          if (!(this.NAV[j].hiddenInServiceOnly && this.SERVICE_ONLY_MODE)) { hasVisible = true; break; }
        }
        if (!hasVisible) continue;
        navHTML += `<div class="nav-group-label">${item.group}</div>`;
      } else {
        const isActive = active && item.href && item.href.includes(active) ? 'active' : '';
        navHTML += `<a class="nav-item ${isActive}" href="${item.href}">
          ${this.ICONS[item.icon] || ''} ${item.label}
        </a>`;
      }
    }

    const sidebarHTML = `
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-header">
        <img src="/app/assets/icon-192.png" style="width:32px;height:32px;border-radius:8px" alt="WorkToGo"/>
        <div class="brand-name">Work<span>ToGo</span></div>
        <div class="brand-badge">ADMIN</div>
      </div>
      <nav class="sidebar-nav">${navHTML}</nav>
      <div class="sidebar-footer">
        <div class="admin-user">
          <div class="admin-avatar" id="admin-avatar">A</div>
          <div class="admin-info">
            <div class="admin-name" id="admin-name">Admin</div>
            <div class="admin-role" id="admin-role">Super Admin</div>
          </div>
          <button class="logout-btn" id="logout-btn" title="Logout">${this.ICONS.logout}</button>
        </div>
      </div>
    </aside>`;

    const topbarHTML = `
    <header class="topbar">
      <button class="menu-toggle" id="menu-toggle">${this.ICONS.menu}</button>
      <div class="topbar-title">${title}</div>
      <div class="topbar-actions" id="topbar-actions">
        <div class="notif-bell-wrap" id="adminNotifBellWrap">
          <button class="notif-bell-btn" id="adminNotifBellBtn" title="Notifications">
            🔔<span class="notif-badge" id="adminNotifBadge" style="display:none;">0</span>
          </button>
          <div class="notif-panel" id="adminNotifPanel">
            <div class="notif-panel-header"><span>Notifications</span></div>
            <div class="notif-list" id="adminNotifList"><div class="notif-empty">No notifications yet</div></div>
          </div>
        </div>
      </div>
    </header>`;

    // Inject into shell targets
    const sidebarTarget = document.getElementById('shell-sidebar');
    const topbarTarget  = document.getElementById('shell-topbar');
    if (sidebarTarget) sidebarTarget.innerHTML = sidebarHTML;
    if (topbarTarget)  topbarTarget.innerHTML  = topbarHTML;

    this._injectNotifStyles();
    this._wireNotifBell();

    if (typeof Auth !== 'undefined' && Auth.isLoggedIn() && typeof WTGPush !== 'undefined') {
      WTGPush.register();
    }
  },

  // ── Notification Bell ───────────────────────────────────────
  _notifPolling: null,

  async _fetchNotifications() {
    try {
      const res = await API.get('/admin/notifications');
      return res?.data || {};
    } catch (e) { return {}; }
  },

  async _markNotificationsRead() {
    try { await API.post('/admin/notifications/read'); } catch (e) {}
  },

  _renderNotifBadge(count) {
    const badge = document.getElementById('adminNotifBadge');
    if (!badge) return;
    if (count > 0) {
      badge.style.display = 'flex';
      badge.textContent = count > 99 ? '99+' : count;
    } else {
      badge.style.display = 'none';
    }
  },

  _escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
  },

  _fmtNotifTime(dateStr) {
    try {
      return new Date(dateStr.replace(' ', 'T')).toLocaleString('en-IN', {
        day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: true,
      });
    } catch (e) { return dateStr; }
  },

  _renderNotifList(items) {
    const list = document.getElementById('adminNotifList');
    if (!list) return;
    if (!items || !items.length) {
      list.innerHTML = '<div class="notif-empty">No notifications yet</div>';
      return;
    }
    list.innerHTML = items.map(n => `
      <div class="notif-item ${n.is_read ? '' : 'unread'}">
        <div class="notif-content">
          <div class="notif-msg">${this._escapeHtml(n.title)}</div>
          <div class="notif-time">${this._fmtNotifTime(n.created_at)}</div>
        </div>
      </div>
    `).join('');
  },

  _wireNotifBell() {
    const btn = document.getElementById('adminNotifBellBtn');
    const panel = document.getElementById('adminNotifPanel');
    if (!btn || !panel) return;

    btn.addEventListener('click', async (e) => {
      e.stopPropagation();
      const isOpen = panel.classList.toggle('open');
      if (isOpen) {
        const data = await this._fetchNotifications();
        this._renderNotifList(data.notifications);
        if ((data.unread_count || 0) > 0) {
          await this._markNotificationsRead();
          this._renderNotifBadge(0);
        }
      }
    });

    document.addEventListener('click', (e) => {
      if (!panel.contains(e.target) && e.target !== btn) panel.classList.remove('open');
    });

    const poll = async () => {
      const data = await this._fetchNotifications();
      this._renderNotifBadge(data.unread_count || 0);
    };
    poll();
    if (this._notifPolling) clearInterval(this._notifPolling);
    this._notifPolling = setInterval(poll, 60000);
  },

  _injectNotifStyles() {
    if (document.getElementById('admin-notif-styles')) return;
    const style = document.createElement('style');
    style.id = 'admin-notif-styles';
    style.textContent = `
      .notif-bell-wrap { position:relative; }
      .notif-bell-btn {
        width:38px;height:38px;border-radius:10px;
        background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.1);
        cursor:pointer;font-size:1.1rem;
        display:flex;align-items:center;justify-content:center;position:relative;
      }
      .notif-badge {
        position:absolute;top:-4px;right:-4px;
        background:#ef4444;color:#fff;border-radius:20px;
        min-width:16px;height:16px;font-size:9px;font-weight:700;
        display:flex;align-items:center;justify-content:center;padding:0 3px;
      }
      .notif-panel {
        display:none;position:absolute;top:calc(100% + 8px);right:0;
        width:320px;max-height:420px;
        background:var(--bg-card,#1a2030);border:1px solid var(--border,#2a3348);
        border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,0.4);
        z-index:9999;overflow:hidden;flex-direction:column;
      }
      .notif-panel.open { display:flex; }
      .notif-panel-header {
        padding:0.75rem 1rem;border-bottom:1px solid var(--border,#2a3348);
        font-weight:600;font-size:0.85rem;
      }
      .notif-list { overflow-y:auto;max-height:340px; }
      .notif-empty { padding:2rem;text-align:center;color:#6b7280;font-size:0.82rem; }
      .notif-item { padding:0.75rem 1rem;border-bottom:1px solid rgba(255,255,255,0.04); }
      .notif-item.unread { background:rgba(245,166,35,0.08); }
      .notif-msg { font-size:0.82rem;line-height:1.4; }
      .notif-time { font-size:0.7rem;color:#6b7280;margin-top:2px; }
    `;
    document.head.appendChild(style);
  },
};
