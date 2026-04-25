/**
 * WorkToGo Admin — Page Shell Renderer
 * Injects sidebar + topbar into pages.
 * Usage: Shell.init({ title: 'Page Title', active: 'page.html' });
 */
const Shell = {
  NAV: [
    { group: 'Overview' },
    { href: 'dashboard.html', icon: 'grid', label: 'Dashboard' },
    { group: 'People' },
    { href: 'users.html',    icon: 'users', label: 'Users' },
    { href: 'vendors.html',  icon: 'store', label: 'Vendors' },
    { group: 'Catalogue' },
    { href: 'products.html', icon: 'box',   label: 'Products' },
    { href: 'services.html', icon: 'tool',  label: 'Services' },
    { group: 'Operations' },
    { href: 'orders.html',   icon: 'list',  label: 'Orders' },
    { href: 'delivery.html', icon: 'truck', label: 'Delivery' },
    { href: 'payments.html', icon: 'credit-card', label: 'Payments' },
    { group: 'System' },
    { href: 'system.html',   icon: 'settings', label: 'System Control' },
    { href: 'logs.html',     icon: 'terminal', label: 'Logs & Activity' },
  ],

  ICONS: {
    grid: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>`,
    users:`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>`,
    store:`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>`,
    box:  `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>`,
    tool: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>`,
    list: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>`,
    truck:`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>`,
    'credit-card':`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>`,
    settings:`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 1.41 14.14M4.93 19.07A10 10 0 0 1 3.52 4.93"/><path d="M12 2v2M12 20v2M2 12H4M20 12h2"/></svg>`,
    terminal:`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>`,
    menu: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>`,
    logout:`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>`,
  },

  init({ title = 'Admin', active = '' } = {}) {
    // Build nav HTML
    let navHTML = '';
    this.NAV.forEach(item => {
      if (item.group) {
        navHTML += `<div class="nav-group-label">${item.group}</div>`;
      } else {
        const isActive = active && item.href && item.href.includes(active) ? 'active' : '';
        navHTML += `<a class="nav-item ${isActive}" href="${item.href}">
          ${this.ICONS[item.icon] || ''} ${item.label}
        </a>`;
      }
    });

    const sidebarHTML = `
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-header">
        <div class="brand-icon">W</div>
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
      <div class="topbar-actions" id="topbar-actions"></div>
    </header>`;

    // Inject into shell targets
    const sidebarTarget = document.getElementById('shell-sidebar');
    const topbarTarget  = document.getElementById('shell-topbar');
    if (sidebarTarget) sidebarTarget.innerHTML = sidebarHTML;
    if (topbarTarget)  topbarTarget.innerHTML  = topbarHTML;
  },
};
