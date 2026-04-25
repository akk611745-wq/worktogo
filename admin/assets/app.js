/**
 * WorkToGo Admin Panel — Shared App Utilities
 */

// ── Security: HTML Escaping ──────────────────────────────────
function escHtml(str) {
  if (typeof str !== 'string') return str;
  const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
  return str.replace(/[&<>"']/g, m => map[m]);
}

// ── Toast Notifications ──────────────────────────────────────
const Toast = {
  show(msg, type = 'success', duration = 3500) {
    const icons = { success: '✓', error: '✕', warning: '⚠' };
    const el = document.createElement('div');
    el.className = `toast ${type}`;
    el.innerHTML = `<span>${icons[type] || '•'}</span><span>${escHtml(msg)}</span>`;
    const container = document.getElementById('toast-container');
    if (!container) return;
    container.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity 0.4s'; setTimeout(() => el.remove(), 400); }, duration);
  },
  success(msg) { this.show(msg, 'success'); },
  error(msg)   { this.show(msg, 'error'); },
  warn(msg)    { this.show(msg, 'warning'); },
};

// ── Modal Control ────────────────────────────────────────────
const Modal = {
  open(id)  { document.getElementById(id)?.classList.add('open'); },
  close(id) { document.getElementById(id)?.classList.remove('open'); },
  closeAll(){ document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open')); },
};

// ── Sidebar Toggle (mobile) ──────────────────────────────────
function initSidebar() {
  const toggle = document.getElementById('menu-toggle');
  const sidebar = document.getElementById('sidebar');
  if (!toggle || !sidebar) return;
  toggle.addEventListener('click', () => sidebar.classList.toggle('open'));
  document.addEventListener('click', (e) => {
    if (sidebar.classList.contains('open') && !sidebar.contains(e.target) && e.target !== toggle) {
      sidebar.classList.remove('open');
    }
  });
}

// ── Active Nav Link ──────────────────────────────────────────
function setActiveNav() {
  const page = window.location.pathname.split('/').pop();
  document.querySelectorAll('.nav-item').forEach(link => {
    const href = link.getAttribute('href');
    if (href && href.includes(page)) link.classList.add('active');
  });
}

// ── Confirm Dialog (using native for simplicity) ─────────────
async function confirmAction(msg) {
  return window.confirm(msg);
}

// ── Format Helpers ───────────────────────────────────────────
const Fmt = {
  date(str) {
    if (!str) return '—';
    return new Date(str).toLocaleString('en-IN', { day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' });
  },
  currency(n) {
    if (n == null) return '—';
    return '₹' + Number(n).toLocaleString('en-IN');
  },
  truncate(str, n = 32) {
    if (!str) return '—';
    return str.length > n ? str.slice(0, n) + '…' : str;
  },
  capitalize(str) {
    if (!str) return '';
    return str.charAt(0).toUpperCase() + str.slice(1).toLowerCase();
  },
};

// ── Status Badge HTML ────────────────────────────────────────
function statusBadge(status) {
  const map = {
    active: 'badge-green', enabled: 'badge-green', approved: 'badge-green',
    paid: 'badge-green', delivered: 'badge-green', completed: 'badge-green',
    inactive: 'badge-gray', disabled: 'badge-gray',
    pending: 'badge-amber', processing: 'badge-amber', assigned: 'badge-blue',
    rejected: 'badge-red', blocked: 'badge-red', failed: 'badge-red', cancelled: 'badge-red',
    in_transit: 'badge-blue', out_for_delivery: 'badge-orange',
    service: 'badge-purple', shopping: 'badge-blue',
  };
  const cls = map[(status || '').toLowerCase()] || 'badge-gray';
  return `<span class="badge ${cls}">${Fmt.capitalize(status || 'unknown')}</span>`;
}

// ── Pagination Builder ───────────────────────────────────────
function buildPagination(containerId, currentPage, totalPages, onPageChange) {
  const wrap = document.getElementById(containerId);
  if (!wrap || totalPages <= 1) { if (wrap) wrap.innerHTML = ''; return; }
  let html = `<button class="page-btn" onclick="(${onPageChange.toString()})(${currentPage - 1})" ${currentPage <= 1 ? 'disabled' : ''}>‹</button>`;
  for (let i = 1; i <= totalPages; i++) {
    if (i === 1 || i === totalPages || Math.abs(i - currentPage) <= 1) {
      html += `<button class="page-btn ${i === currentPage ? 'active' : ''}" onclick="(${onPageChange.toString()})(${i})">${i}</button>`;
    } else if (Math.abs(i - currentPage) === 2) {
      html += `<span>…</span>`;
    }
  }
  html += `<button class="page-btn" onclick="(${onPageChange.toString()})(${currentPage + 1})" ${currentPage >= totalPages ? 'disabled' : ''}>›</button>`;
  wrap.innerHTML = html;
}

// ── CSV Export ───────────────────────────────────────────────
function exportCSV(rows, headers, filename) {
  const lines = [headers.join(','), ...rows.map(r => headers.map(h => `"${(r[h] || '')}"`).join(','))];
  const blob = new Blob([lines.join('\n')], { type: 'text/csv' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  a.click();
}

// ── Loading State ────────────────────────────────────────────
function tableLoading(tbodyId, cols) {
  const el = document.getElementById(tbodyId);
  if (el) el.innerHTML = `<tr class="loading-row"><td colspan="${cols}"><div class="spinner" style="margin:auto"></div></td></tr>`;
}
function tableEmpty(tbodyId, cols, msg = 'No records found') {
  const el = document.getElementById(tbodyId);
  if (el) el.innerHTML = `<tr class="empty-row"><td colspan="${cols}">${msg}</td></tr>`;
}

// ── Admin User Display ───────────────────────────────────────
function renderAdminUser() {
  const user = Auth.getUser();
  const nameEl = document.getElementById('admin-name');
  const roleEl = document.getElementById('admin-role');
  const avEl   = document.getElementById('admin-avatar');
  if (nameEl) nameEl.textContent = user.name || 'Admin';
  if (roleEl) roleEl.textContent = user.role || 'Super Admin';
  if (avEl)   avEl.textContent = (user.name || 'A').charAt(0).toUpperCase();
}

// ── Init All Pages ───────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  // Auth guard (skip on login page)
  if (!window.location.pathname.includes('index.html') && !window.IS_LOGIN_PAGE) {
    if (typeof Auth !== 'undefined') Auth.requireAuth();
  }
  initSidebar();
  setActiveNav();
  renderAdminUser();

  // Close modals on overlay click
  document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) overlay.classList.remove('open');
    });
  });

  // Logout
  document.getElementById('logout-btn')?.addEventListener('click', () => {
    if (confirm('Log out of Admin Panel?')) Auth.logout();
  });
});
