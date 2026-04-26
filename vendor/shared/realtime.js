/**
 * WorkToGo — Real-Time Engine (v2 Upgrade)
 * ─────────────────────────────────────────
 * Polling-based real-time order/booking detection.
 * Plays sound, shows popup, updates notification bell.
 * Zero dependencies. Drop-in enhancement.
 */

const RealtimeEngine = (() => {
  /* ── State ─────────────────────────────────────── */
  let _timer         = null;
  let _knownIds      = new Set();
  let _notifications = [];   // { id, type, message, time, read }
  let _unreadCount   = 0;
  let _onNewItem     = null; // callback(items)
  let _fetchFn       = null; // async () => array of items
  let _interval      = 7000; // ms
  let _initialized   = false;
  let _popupQueue    = [];
  let _popupShowing  = false;

  /* ── Audio ─────────────────────────────────────── */
  function _playAlert() {
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      const times = [0, 0.15, 0.30];
      times.forEach(t => {
        const osc  = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.frequency.value = 880;
        osc.type = 'sine';
        gain.gain.setValueAtTime(0.4, ctx.currentTime + t);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + t + 0.12);
        osc.start(ctx.currentTime + t);
        osc.stop(ctx.currentTime + t + 0.15);
      });
    } catch (e) { /* audio blocked — silent fallback */ }
  }

  /* ── Popup Alert ───────────────────────────────── */
  function _ensurePopupContainer() {
    if (!document.getElementById('rtPopupContainer')) {
      const div = document.createElement('div');
      div.id = 'rtPopupContainer';
      div.style.cssText = `
        position:fixed;top:72px;right:1rem;z-index:10000;
        display:flex;flex-direction:column;gap:0.5rem;
        pointer-events:none;
      `;
      document.body.appendChild(div);
    }
  }

  function _showPopup(items) {
    _popupQueue.push(items);
    _drainPopupQueue();
  }

  function _drainPopupQueue() {
    if (_popupShowing || !_popupQueue.length) return;
    const items = _popupQueue.shift();
    _popupShowing = true;

    const container = document.getElementById('rtPopupContainer');
    if (!container) { _popupShowing = false; return; }

    const count = items.length;
    const label = count === 1
      ? `New ${items[0]._rtType || 'Order'} #${items[0].id || items[0]._id}`
      : `${count} new ${items[0]._rtType || 'order'}s received!`;

    const popup = document.createElement('div');
    popup.className = 'rt-popup';
    popup.style.cssText = `
      pointer-events:all;
      background:#161b24;
      border:1.5px solid #f5a623;
      border-radius:12px;
      padding:0.9rem 1.2rem;
      min-width:270px;max-width:340px;
      box-shadow:0 8px 32px rgba(0,0,0,0.35);
      display:flex;align-items:flex-start;gap:0.75rem;
      animation:rtSlideIn 0.3s cubic-bezier(.175,.885,.32,1.275) both;
      cursor:pointer;
    `;
    popup.innerHTML = `
      <div style="font-size:1.5rem;line-height:1;flex-shrink:0;">🔔</div>
      <div style="flex:1;">
        <div style="color:#f5a623;font-weight:700;font-size:0.88rem;margin-bottom:2px;">New Order Received!</div>
        <div style="color:#e2e8f0;font-size:0.82rem;line-height:1.4;">${label}</div>
        <div style="color:#6b7280;font-size:0.72rem;margin-top:4px;">${_fmtTime(new Date())}</div>
      </div>
      <button style="color:#6b7280;font-size:1.1rem;flex-shrink:0;background:none;border:none;cursor:pointer;line-height:1;" onclick="this.parentElement.remove()">✕</button>
    `;

    // Click to navigate
    popup.addEventListener('click', e => {
      if (e.target.tagName === 'BUTTON') return;
      const page = window.location.pathname.split('/').pop();
      if (page !== 'orders.html' && page !== 'bookings.html') {
        const user = Auth.getUser();
        if (user?.role === CONFIG.ROLES.SERVICE) window.location.href = 'bookings.html';
        else window.location.href = 'orders.html';
      }
      popup.remove();
    });

    container.appendChild(popup);

    const removeTimeout = setTimeout(() => {
      popup.style.animation = 'rtSlideOut 0.25s ease both';
      setTimeout(() => { popup.remove(); _popupShowing = false; _drainPopupQueue(); }, 250);
    }, 5000);

    popup.querySelector('button').addEventListener('click', () => {
      clearTimeout(removeTimeout);
      popup.remove();
      _popupShowing = false;
      _drainPopupQueue();
    });
  }

  /* ── Notification Bell ─────────────────────────── */
  function _buildBellHTML() {
    return `
      <div class="notif-bell-wrap" id="notifBellWrap">
        <button class="notif-bell-btn" id="notifBellBtn" title="Notifications" onclick="RealtimeEngine.togglePanel()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20">
            <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 01-3.46 0"/>
          </svg>
          <span class="notif-badge" id="notifBadge" style="display:none;">0</span>
        </button>
        <div class="notif-panel" id="notifPanel">
          <div class="notif-panel-header">
            <span>Notifications</span>
            <button class="notif-clear-btn" onclick="RealtimeEngine.clearAll()">Clear all</button>
          </div>
          <div class="notif-list" id="notifList">
            <div class="notif-empty">No notifications yet</div>
          </div>
        </div>
      </div>`;
  }

  function _injectBell() {
    const actions = document.querySelector('.topbar-actions');
    if (!actions || document.getElementById('notifBellWrap')) return;
    actions.insertAdjacentHTML('afterbegin', _buildBellHTML());

    // Close panel on outside click
    document.addEventListener('click', e => {
      const wrap = document.getElementById('notifBellWrap');
      if (wrap && !wrap.contains(e.target)) _closePanel();
    });
  }

  function _closePanel() {
    document.getElementById('notifPanel')?.classList.remove('open');
  }

  function _addNotification(type, message, itemId) {
    const notif = {
      id: Date.now() + Math.random(),
      type,
      message,
      itemId,
      time: new Date(),
      read: false,
    };
    _notifications.unshift(notif);
    if (_notifications.length > 50) _notifications = _notifications.slice(0, 50);
    _unreadCount++;
    _renderBadge();
    _renderNotifList();
    return notif;
  }

  function _renderBadge() {
    const badge = document.getElementById('notifBadge');
    if (!badge) return;
    if (_unreadCount > 0) {
      badge.style.display = 'flex';
      badge.textContent = _unreadCount > 99 ? '99+' : _unreadCount;
    } else {
      badge.style.display = 'none';
    }
    // pulse bell
    const btn = document.getElementById('notifBellBtn');
    if (btn && _unreadCount > 0) {
      btn.classList.add('has-unread');
    }
  }

  function _renderNotifList() {
    const list = document.getElementById('notifList');
    if (!list) return;
    if (!_notifications.length) {
      list.innerHTML = '<div class="notif-empty">No notifications yet</div>';
      return;
    }
    list.innerHTML = _notifications.map(n => `
      <div class="notif-item ${n.read ? '' : 'unread'}" onclick="RealtimeEngine.markRead('${n.id}')">
        <div class="notif-dot ${n.type}"></div>
        <div class="notif-content">
          <div class="notif-msg">${n.message}</div>
          <div class="notif-time">${_fmtTime(n.time)}</div>
        </div>
      </div>
    `).join('');
  }

  function _fmtTime(d) {
    return d.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: true });
  }

  /* ── Polling Core ──────────────────────────────── */
  async function _poll() {
    if (!_fetchFn) return;
    try {
      const items = await _fetchFn();
      if (!Array.isArray(items)) return;

      const newItems = items.filter(item => {
        const id = String(item.id || item._id);
        return !_knownIds.has(id);
      });

      // Seed known IDs on first run
      if (!_initialized) {
        items.forEach(item => _knownIds.add(String(item.id || item._id)));
        _initialized = true;
        // Also add pending count badge to dashboard
        _updateDashboardBadges(items);
        return;
      }

      if (newItems.length > 0) {
        newItems.forEach(item => {
          const id = String(item.id || item._id);
          _knownIds.add(id);
          const label = item._rtType === 'Booking'
            ? `New booking #${id} from ${item.customer_name || 'customer'}`
            : `New order #${id} — ${item.items_count || ''} item(s)`;
          _addNotification('new-order', label, id);
        });

        _playAlert();
        _showPopup(newItems);
        if (_onNewItem) _onNewItem(newItems);
        _updateDashboardBadges(items);
      }

      // Always refresh pending count
      _updateDashboardBadges(items);

    } catch (e) { /* silent — network error is OK */ }
  }

  function _updateDashboardBadges(items) {
    const pending = items.filter(i => i.status === 'pending').length;
    // Update sidebar Orders/Bookings badge
    document.querySelectorAll('.nav-item-badge').forEach(b => b.remove());
    if (pending > 0) {
      document.querySelectorAll('.nav-item[href="orders.html"], .nav-item[href="bookings.html"]').forEach(a => {
        if (!a.querySelector('.nav-item-badge')) {
          const sp = document.createElement('span');
          sp.className = 'nav-item-badge';
          sp.textContent = pending;
          a.appendChild(sp);
        }
      });
    }
  }

  /* ── Public API ────────────────────────────────── */
  function start({ fetchFn, onNew, interval = 7000, type = 'Order' }) {
    _fetchFn  = async () => {
      const items = await fetchFn();
      return items.map(i => ({ ...i, _rtType: type }));
    };
    _onNewItem = onNew;
    _interval  = interval;
    _initialized = false;

    _ensurePopupContainer();
    _injectStyles();

    // Wait for shell to inject topbar, then inject bell
    const tryInject = setInterval(() => {
      if (document.querySelector('.topbar-actions')) {
        _injectBell();
        clearInterval(tryInject);
      }
    }, 100);

    // First poll immediately, then schedule
    setTimeout(async () => {
      await _poll();
      _timer = setInterval(_poll, _interval);
    }, 800);
  }

  function stop() {
    clearInterval(_timer);
    _timer = null;
  }

  function togglePanel() {
    const panel = document.getElementById('notifPanel');
    if (!panel) return;
    const isOpen = panel.classList.toggle('open');
    if (isOpen) {
      // Mark all as read when opening
      _notifications.forEach(n => n.read = true);
      _unreadCount = 0;
      _renderBadge();
      _renderNotifList();
      document.getElementById('notifBellBtn')?.classList.remove('has-unread');
    }
  }

  function markRead(id) {
    const n = _notifications.find(x => String(x.id) === String(id));
    if (n && !n.read) {
      n.read = true;
      _unreadCount = Math.max(0, _unreadCount - 1);
      _renderBadge();
      _renderNotifList();
    }
  }

  function clearAll() {
    _notifications = [];
    _unreadCount = 0;
    _renderBadge();
    _renderNotifList();
    document.getElementById('notifBellBtn')?.classList.remove('has-unread');
  }

  function addPendingAlert(pendingCount) {
    if (pendingCount > 0) {
      _addNotification('pending', `${pendingCount} order(s) pending your action`, null);
    }
  }

  /* ── Inject CSS ────────────────────────────────── */
  function _injectStyles() {
    if (document.getElementById('rt-styles')) return;
    const style = document.createElement('style');
    style.id = 'rt-styles';
    style.textContent = `
      /* Notification Bell */
      .notif-bell-wrap { position:relative; }
      .notif-bell-btn {
        width:38px;height:38px;border-radius:10px;
        background:rgba(255,255,255,0.07);
        border:1px solid rgba(255,255,255,0.1);
        color:#e2e8f0;
        display:flex;align-items:center;justify-content:center;
        cursor:pointer;transition:background 0.18s;
        position:relative;
      }
      .notif-bell-btn:hover { background:rgba(245,166,35,0.15);color:#f5a623; }
      .notif-bell-btn.has-unread { color:#f5a623;animation:bellShake 0.5s ease; }
      .notif-badge {
        position:absolute;top:-4px;right:-4px;
        background:#ef4444;color:#fff;
        border-radius:20px;min-width:16px;height:16px;
        font-size:9px;font-weight:700;
        display:flex;align-items:center;justify-content:center;
        padding:0 3px;border:1.5px solid #0e1117;
        pointer-events:none;
      }

      /* Panel */
      .notif-panel {
        display:none;position:absolute;top:calc(100% + 8px);right:0;
        width:320px;max-height:420px;
        background:#1a2030;border:1px solid #2a3348;
        border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,0.4);
        z-index:9999;overflow:hidden;
        flex-direction:column;
      }
      .notif-panel.open { display:flex;animation:fadeIn 0.15s ease both; }
      .notif-panel-header {
        padding:0.75rem 1rem;border-bottom:1px solid #2a3348;
        display:flex;justify-content:space-between;align-items:center;
        color:#e2e8f0;font-weight:600;font-size:0.85rem;flex-shrink:0;
      }
      .notif-clear-btn { color:#6b7280;font-size:0.75rem;cursor:pointer;background:none;border:none; }
      .notif-clear-btn:hover { color:#f5a623; }
      .notif-list { overflow-y:auto;flex:1; }
      .notif-empty { padding:2rem;text-align:center;color:#6b7280;font-size:0.82rem; }
      .notif-item {
        display:flex;gap:0.65rem;padding:0.75rem 1rem;
        cursor:pointer;border-bottom:1px solid rgba(255,255,255,0.04);
        transition:background 0.15s;
      }
      .notif-item:hover { background:rgba(255,255,255,0.04); }
      .notif-item.unread { background:rgba(245,166,35,0.05); }
      .notif-dot {
        width:8px;height:8px;border-radius:50%;
        flex-shrink:0;margin-top:5px;
      }
      .notif-dot.new-order { background:#f5a623; }
      .notif-dot.pending   { background:#3b82f6; }
      .notif-dot.alert     { background:#ef4444; }
      .notif-content { flex:1; }
      .notif-msg  { color:#e2e8f0;font-size:0.8rem;line-height:1.4; }
      .notif-time { color:#6b7280;font-size:0.7rem;margin-top:2px; }

      /* Popup animations */
      @keyframes rtSlideIn {
        from { opacity:0;transform:translateX(20px) scale(0.96); }
        to   { opacity:1;transform:translateX(0) scale(1); }
      }
      @keyframes rtSlideOut {
        from { opacity:1;transform:translateX(0); }
        to   { opacity:0;transform:translateX(20px); }
      }
      @keyframes bellShake {
        0%,100% { transform:rotate(0); }
        20% { transform:rotate(-15deg); }
        40% { transform:rotate(15deg); }
        60% { transform:rotate(-10deg); }
        80% { transform:rotate(10deg); }
      }

      /* Sidebar order count badge */
      .nav-item-badge {
        margin-left:auto;
        background:#ef4444;color:#fff;
        border-radius:20px;min-width:18px;height:18px;
        font-size:10px;font-weight:700;
        display:inline-flex;align-items:center;justify-content:center;
        padding:0 4px;
      }

      /* RT status row highlight */
      tr.rt-new-row {
        animation:rtRowPulse 1.2s ease both;
      }
      @keyframes rtRowPulse {
        0%   { background:rgba(245,166,35,0.18); }
        100% { background:transparent; }
      }

      /* Order lifecycle timeline */
      .order-timeline {
        display:flex;align-items:center;gap:0;
        margin:0.75rem 0;flex-wrap:wrap;
      }
      .timeline-step {
        display:flex;flex-direction:column;align-items:center;
        flex:1;min-width:60px;
      }
      .timeline-step .step-dot {
        width:28px;height:28px;border-radius:50%;
        border:2px solid var(--border);
        background:var(--surface-2);
        display:flex;align-items:center;justify-content:center;
        font-size:0.7rem;font-weight:700;
        color:var(--text-3);
        transition:all 0.25s;
        position:relative;z-index:1;
      }
      .timeline-step .step-label {
        font-size:0.62rem;color:var(--text-3);margin-top:4px;
        text-align:center;text-transform:capitalize;
        white-space:nowrap;
      }
      .timeline-step.active .step-dot {
        border-color:var(--accent);background:var(--accent);color:#fff;
      }
      .timeline-step.done .step-dot {
        border-color:var(--success);background:var(--success);color:#fff;
      }
      .timeline-step.active .step-label,
      .timeline-step.done .step-label { color:var(--text); font-weight:600; }
      .timeline-connector {
        flex:1;height:2px;background:var(--border);
        min-width:12px;margin-bottom:20px;
      }
      .timeline-connector.done { background:var(--success); }
      .timeline-connector.active { background:var(--accent); }

      /* Dashboard mini-analytics */
      .analytics-row {
        display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));
        gap:1rem;margin-bottom:1.25rem;
      }
      .analytics-card {
        background:var(--surface);border:1px solid var(--border);
        border-radius:var(--radius);padding:1rem 1.2rem;
        box-shadow:var(--shadow);
      }
      .analytics-label { font-size:0.75rem;color:var(--text-2);text-transform:uppercase;letter-spacing:0.05em; }
      .analytics-value { font-size:1.6rem;font-weight:800;font-family:var(--font-display);margin:0.2rem 0;color:var(--text); }
      .analytics-trend { font-size:0.75rem;color:var(--text-2); }
      .analytics-trend.up   { color:var(--success); }
      .analytics-trend.down { color:var(--danger); }

      /* Auto-refresh indicator */
      .refresh-indicator {
        display:inline-flex;align-items:center;gap:0.4rem;
        font-size:0.72rem;color:var(--text-3);
        background:var(--surface-2);
        border:1px solid var(--border);
        border-radius:20px;padding:0.2rem 0.65rem;
      }
      .refresh-dot {
        width:7px;height:7px;border-radius:50%;
        background:var(--success);
        animation:refreshPulse 2s infinite;
      }
      @keyframes refreshPulse {
        0%,100% { opacity:1; }
        50%      { opacity:0.3; }
      }

      /* Quick action buttons enhancement */
      .btn-accept  { background:#10b981!important;color:#fff!important;border-color:#10b981!important; }
      .btn-reject  { background:#ef4444!important;color:#fff!important;border-color:#ef4444!important; }
      .btn-ready   { background:#f59e0b!important;color:#fff!important;border-color:#f59e0b!important; }
      .btn-accept:hover { background:#059669!important; }
      .btn-reject:hover { background:#dc2626!important; }
      .btn-ready:hover  { background:#d97706!important; }
    `;
    document.head.appendChild(style);
  }

  return { start, stop, togglePanel, markRead, clearAll, addPendingAlert };
})();
