/**
 * AlertSystem — Frontend Module
 * ─────────────────────────────────────────────────────────
 * Drop-in module for both User Panel and Vendor Panel.
 * No external dependencies. No framework required.
 *
 * Quick start (User Panel):
 *   import AlertSystem from './alerts.js';
 *   const alerts = new AlertSystem({ role: 'user' });
 *   alerts.start();
 *
 * Events emitted on window:
 *   alert:new     — { detail: alert }
 *   alert:seen    — { detail: { alertIds } }
 *   alert:count   — { detail: { count } }
 * ─────────────────────────────────────────────────────────
 */

class AlertSystem {

    // ── Default configuration ──────────────────────────────
    static DEFAULTS = {
        role          : 'user',           // 'user' | 'vendor'
        pollInterval  : null,             // null = auto (3s vendor / 7s user)
        apiBase       : '/api/alerts',
        soundEnabled  : true,
        toastDuration : 4000,             // ms
        badgeSelector : '#alert-badge',   // element showing unseen count
        listSelector  : '#alert-list',    // container for alert items (optional)
        onNew         : null,             // callback(alert) — fires on each new alert
    };

    // ── Sound definitions (base64 Web Audio tones — no external files) ──
    static SOUNDS = {
        chime   : { freq: 880, type: 'sine',    dur: 0.18, gain: 0.3 },
        success : { freq: 660, type: 'triangle',dur: 0.25, gain: 0.28 },
        error   : { freq: 280, type: 'sawtooth', dur: 0.22, gain: 0.2 },
    };

    constructor(options = {}) {
        this.cfg        = { ...AlertSystem.DEFAULTS, ...options };
        this.lastTs     = null;
        this.timer      = null;
        this.seenBuffer = [];           // IDs queued to mark seen
        this.seenTimer  = null;
        this.audioCtx  = null;
        this.toastEl   = null;

        this._injectStyles();
        this._buildToastContainer();
    }

    // ══════════════════════════════════════════════════════
    //  PUBLIC API
    // ══════════════════════════════════════════════════════

    /** Start polling. Call once after the page is ready. */
    start() {
        if (this.timer) return;
        this._poll();  // immediate first fetch
        const interval = this.cfg.pollInterval
            ?? (this.cfg.role === 'vendor' ? 3000 : 7000);
        this.timer = setInterval(() => this._poll(), interval);
    }

    /** Stop polling (e.g. when user navigates away). */
    stop() {
        clearInterval(this.timer);
        this.timer = null;
    }

    /** Manually trigger a toast (can be called externally). */
    showToast(message, type = 'info', title = '') {
        const toast = document.createElement('div');
        toast.className = `as-toast as-toast--${this._typeClass(type)}`;

        const icon = document.createElement('span');
        icon.className = 'as-toast__icon';
        icon.setAttribute('aria-hidden', 'true');

        const body = document.createElement('div');
        body.className = 'as-toast__body';

        if (title) {
            const t = document.createElement('strong');
            t.className = 'as-toast__title';
            t.textContent = title;
            body.appendChild(t);
        }

        const msg = document.createElement('p');
        msg.className = 'as-toast__msg';
        msg.textContent = message;
        body.appendChild(msg);

        const close = document.createElement('button');
        close.className = 'as-toast__close';
        close.innerHTML = '&times;';
        close.setAttribute('aria-label', 'Dismiss');
        close.onclick = () => this._dismissToast(toast);

        toast.append(icon, body, close);
        this.toastEl.appendChild(toast);

        // Animate in
        requestAnimationFrame(() => toast.classList.add('as-toast--visible'));

        // Auto dismiss
        setTimeout(() => this._dismissToast(toast), this.cfg.toastDuration);
        return toast;
    }

    /** Update the badge counter in the DOM. */
    updateBadge(count) {
        const badge = document.querySelector(this.cfg.badgeSelector);
        if (!badge) return;

        badge.textContent = count > 99 ? '99+' : count > 0 ? String(count) : '';
        badge.classList.toggle('as-badge--hidden', count === 0);
        badge.setAttribute('aria-label', count + ' unread alerts');

        window.dispatchEvent(new CustomEvent('alert:count', { detail: { count } }));
    }

    /** Play a named sound ('chime' | 'success' | 'error'). */
    playSound(name = 'chime') {
        if (!this.cfg.soundEnabled) return;
        const def = AlertSystem.SOUNDS[name];
        if (!def) return;

        try {
            if (!this.audioCtx) {
                this.audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            }
            const ctx  = this.audioCtx;
            const osc  = ctx.createOscillator();
            const gain = ctx.createGain();

            osc.connect(gain);
            gain.connect(ctx.destination);

            osc.type      = def.type;
            osc.frequency.setValueAtTime(def.freq, ctx.currentTime);
            gain.gain.setValueAtTime(def.gain, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + def.dur);

            osc.start(ctx.currentTime);
            osc.stop(ctx.currentTime + def.dur);
        } catch (_) {
            // Audio not available — silent fail
        }
    }

    /**
     * Highlight a newly arrived item in a list.
     * @param {string|number} refId  — value of data-ref-id attribute
     * @param {string}        refType — value of data-ref-type attribute
     */
    highlightNewItem(refId, refType) {
        if (!this.cfg.listSelector) return;
        const list = document.querySelector(this.cfg.listSelector);
        if (!list) return;

        const target = list.querySelector(
            `[data-ref-type="${refType}"][data-ref-id="${refId}"]`
        );
        if (!target) return;

        target.classList.add('as-item--new');
        target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        setTimeout(() => target.classList.remove('as-item--new'), 3000);
    }

    /**
     * Queue an alert as seen. Batched and flushed after 800ms
     * to prevent flooding the server when multiple alerts arrive.
     */
    markSeen(alertId) {
        if (!this.seenBuffer.includes(alertId)) {
            this.seenBuffer.push(alertId);
        }
        clearTimeout(this.seenTimer);
        this.seenTimer = setTimeout(() => this._flushSeen(), 800);
    }

    /** Mark ALL unseen alerts as seen. */
    markAllSeen() {
        this._callApi('mark_seen.php', 'POST', {
            role     : this.cfg.role,
            alert_ids: [],
        }).then(data => {
            if (data?.success) {
                this.updateBadge(0);
                window.dispatchEvent(new CustomEvent('alert:seen', { detail: { alertIds: 'all' } }));
            }
        });
    }

    // ══════════════════════════════════════════════════════
    //  PRIVATE — POLLING
    // ══════════════════════════════════════════════════════

    async _poll() {
        const params = new URLSearchParams({
            role : this.cfg.role
        });
        if (this.lastTs) params.set('last_ts', this.lastTs);

        try {
            const data = await this._callApi('fetch.php', 'GET', params);
            if (!data?.success) return;

            // Always advance timestamp so next poll fetches only newer
            this.lastTs = data.server_ts;

            this.updateBadge(data.unseen_count ?? 0);

            if (data.has_new && Array.isArray(data.alerts)) {
                data.alerts.forEach(alert => this._handleAlert(alert));
            }
        } catch (_) {
            // Network hiccup — will retry on next interval
        }
    }

    _handleAlert(alert) {
        // Toast
        this.showToast(alert.message, alert.type, alert.title);

        // Sound
        if (alert.play_sound) {
            this.playSound(alert.play_sound);
        }

        // Highlight in list
        if (alert.ref_id && alert.ref_type && alert.ref_type !== 'none') {
            this.highlightNewItem(alert.ref_id, alert.ref_type);
        }

        // External callback
        if (typeof this.cfg.onNew === 'function') {
            this.cfg.onNew(alert);
        }

        // Emit DOM event
        window.dispatchEvent(new CustomEvent('alert:new', { detail: alert }));
    }

    // ══════════════════════════════════════════════════════
    //  PRIVATE — SEEN FLUSH
    // ══════════════════════════════════════════════════════

    async _flushSeen() {
        if (!this.seenBuffer.length) return;
        const ids = [...this.seenBuffer];
        this.seenBuffer = [];

        const data = await this._callApi('mark_seen.php', 'POST', {
            role      : this.cfg.role,
            alert_ids : ids,
        });

        if (data?.success) {
            this.updateBadge(data.unseen_count ?? 0);
            window.dispatchEvent(new CustomEvent('alert:seen', { detail: { alertIds: ids } }));
        }
    }

    // ══════════════════════════════════════════════════════
    //  PRIVATE — HTTP
    // ══════════════════════════════════════════════════════

    async _callApi(endpoint, method, payload) {
        const base = this.cfg.apiBase.replace(/\/$/, '');
        let url    = `${base}/${endpoint}`;
        let init   = { 
            method, 
            headers: {
                'Authorization': `Bearer ${localStorage.getItem('token')}` // Standard for this project
            } 
        };

        if (method === 'GET') {
            url += '?' + payload.toString();
        } else {
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(payload);
        }

        const res = await fetch(url, init);
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
    }

    // ══════════════════════════════════════════════════════
    //  PRIVATE — TOAST HELPERS
    // ══════════════════════════════════════════════════════

    _dismissToast(toast) {
        toast.classList.remove('as-toast--visible');
        toast.classList.add('as-toast--out');
        toast.addEventListener('transitionend', () => toast.remove(), { once: true });
    }

    _typeClass(type) {
        if (type.includes('failure') || type.includes('error')) return 'error';
        if (type.includes('success') || type.includes('completed')) return 'success';
        if (type.includes('warning')) return 'warning';
        return 'info';
    }

    _buildToastContainer() {
        if (document.getElementById('as-toasts')) {
            this.toastEl = document.getElementById('as-toasts');
            return;
        }
        const c = document.createElement('div');
        c.id = 'as-toasts';
        c.setAttribute('aria-live', 'polite');
        c.setAttribute('aria-atomic', 'false');
        c.setAttribute('role', 'status');
        document.body.appendChild(c);
        this.toastEl = c;
    }

    _injectStyles() {
        if (document.getElementById('as-styles')) return;
        const s = document.createElement('style');
        s.id = 'as-styles';
        s.textContent = `
/* ── Toast container ─────────────────────────────── */
#as-toasts {
    position: fixed;
    bottom: 24px;
    right: 24px;
    z-index: 9999;
    display: flex;
    flex-direction: column-reverse;
    gap: 10px;
    max-width: 340px;
    pointer-events: none;
}

/* ── Single toast ────────────────────────────────── */
.as-toast {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 12px 14px;
    border-radius: 10px;
    background: #1e1e1e;
    color: #f0f0f0;
    box-shadow: 0 4px 18px rgba(0,0,0,0.25);
    opacity: 0;
    transform: translateY(12px);
    transition: opacity .22s ease, transform .22s ease;
    pointer-events: auto;
    border-left: 3px solid #555;
    min-width: 260px;
}
.as-toast--visible {
    opacity: 1;
    transform: translateY(0);
}
.as-toast--out {
    opacity: 0;
    transform: translateY(8px);
}

/* ── Type colors ─────────────────────────────────── */
.as-toast--info    { border-left-color: #4da3ff; }
.as-toast--success { border-left-color: #3ecf8e; }
.as-toast--error   { border-left-color: #f87171; }
.as-toast--warning { border-left-color: #fbbf24; }

/* ── Icon dot ────────────────────────────────────── */
.as-toast__icon {
    flex-shrink: 0;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-top: 5px;
    background: #555;
}
.as-toast--info    .as-toast__icon { background: #4da3ff; }
.as-toast--success .as-toast__icon { background: #3ecf8e; }
.as-toast--error   .as-toast__icon { background: #f87171; }
.as-toast--warning .as-toast__icon { background: #fbbf24; }

/* ── Body ────────────────────────────────────────── */
.as-toast__body { flex: 1; }
.as-toast__title {
    display: block;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 2px;
    color: #ffffff;
}
.as-toast__msg {
    font-size: 12px;
    margin: 0;
    color: #c0c0c0;
    line-height: 1.4;
}

/* ── Close button ────────────────────────────────── */
.as-toast__close {
    flex-shrink: 0;
    background: none;
    border: none;
    color: #888;
    font-size: 16px;
    cursor: pointer;
    line-height: 1;
    padding: 0 2px;
    transition: color .15s;
}
.as-toast__close:hover { color: #ccc; }

/* ── Badge ───────────────────────────────────────── */
#alert-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    border-radius: 9px;
    background: #e53e3e;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    line-height: 1;
    transition: transform .2s ease;
}
#alert-badge:not(.as-badge--hidden) {
    animation: as-badge-pop .25s ease;
}
.as-badge--hidden { display: none !important; }

@keyframes as-badge-pop {
    0%   { transform: scale(0.6); }
    60%  { transform: scale(1.2); }
    100% { transform: scale(1); }
}

/* ── List item highlight ─────────────────────────── */
.as-item--new {
    animation: as-highlight 3s ease forwards;
}
@keyframes as-highlight {
    0%   { box-shadow: inset 0 0 0 2px #4da3ff; background: rgba(77,163,255,0.08); }
    80%  { box-shadow: inset 0 0 0 2px #4da3ff; background: rgba(77,163,255,0.08); }
    100% { box-shadow: none; background: transparent; }
}

/* ── Mobile adjustments ──────────────────────────── */
@media (max-width: 480px) {
    #as-toasts { right: 12px; left: 12px; max-width: none; }
}
        `;
        document.head.appendChild(s);
    }
}

export default AlertSystem;
