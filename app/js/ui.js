/**
 * WorkToGo — Shared UI Utilities
 */

const UI = (() => {

  // ── Toast ──────────────────────────────────────────────────────────────
  let _toastTimer;

  function toast(msg, type = "info", duration = 3000) {
    let el = document.getElementById("wtg-toast");
    if (!el) {
      el = document.createElement("div");
      el.id = "wtg-toast";
      document.body.appendChild(el);
    }
    clearTimeout(_toastTimer);
    el.textContent = msg;
    el.className = `wtg-toast toast-${type} show`;
    _toastTimer = setTimeout(() => el.classList.remove("show"), duration);
  }

  function showNotificationBanner(title, body) {
    toast(`🔔 ${title}: ${body}`, "info", 5000);
  }

  // ── Status Badge ───────────────────────────────────────────────────────
  // Values match backend: pending, accepted, in_progress, completed, cancelled
  // Also handles legacy values gracefully

  const STATUS_MAP = {
    // Backend canonical
    pending:     { label: "Pending",     cls: "status-pending"    },
    open:        { label: "Pending",     cls: "status-pending"    },
    assigned:    { label: "Assigned",    cls: "status-confirmed"  },
    accepted:    { label: "Confirmed",   cls: "status-confirmed"  },
    in_progress: { label: "Assigned",    cls: "status-processing" },
    completed:   { label: "Done",        cls: "status-success"    },
    done:        { label: "Done",        cls: "status-success"    },
    cancelled:   { label: "Cancelled",   cls: "status-cancelled"  },
    // Booking extras
    confirmed:   { label: "Confirmed",   cls: "status-confirmed"  },
    // Legacy aliases (in case backend returns these)
    processing:  { label: "Processing",  cls: "status-processing" },
    shipped:     { label: "Shipped",     cls: "status-shipped"    },
    delivered:   { label: "Delivered",   cls: "status-success"    },
    scheduled:   { label: "Scheduled",   cls: "status-confirmed"  },
    ongoing:     { label: "In Progress", cls: "status-processing" },
    payment_pending:       { label: "Payment pending",       cls: "status-pending"    },
    inspection_requested:  { label: "Inspection requested",  cls: "status-pending"    },
    inspection_assigned:   { label: "Inspection assigned",   cls: "status-confirmed"  },
    inspection_scheduled:  { label: "Inspection scheduled",  cls: "status-confirmed"  },
    inspection_completed:  { label: "Inspection completed",  cls: "status-success"    },
    request_received:      { label: "Request received",      cls: "status-pending"    },
    nearby_matching:       { label: "Matching nearby",       cls: "status-processing" },
    worker_contacting:     { label: "Contacting worker",     cls: "status-processing" },
    worker_assigned:       { label: "Worker assigned",       cls: "status-confirmed"  },
    worker_requested:      { label: "Worker requested",      cls: "status-pending"    },
    awaiting_response:     { label: "Awaiting response",     cls: "status-processing" },
    worker_confirmed:      { label: "Worker confirmed",      cls: "status-confirmed"  },
    service_in_progress:   { label: "Service in progress",   cls: "status-processing" },
  };

  function statusBadge(status = "") {
    const key = (status || "").toLowerCase().replace(/ /g, "_");
    const s = STATUS_MAP[key] || { label: status || "Unknown", cls: "status-default" };
    return `<span class="status-badge ${s.cls}">${s.label}</span>`;
  }

  // ── Skeleton ───────────────────────────────────────────────────────────

  function skeleton(count = 3, type = "card") {
    return Array.from({ length: count }, () =>
      type === "card"
        ? `<div class="skeleton-card"><div class="skel skel-img"></div><div class="skel skel-line"></div><div class="skel skel-line short"></div></div>`
        : `<div class="skeleton-row"><div class="skel" style="height:14px;width:60%;margin-bottom:6px"></div><div class="skel" style="height:14px;width:80%;margin-bottom:6px"></div><div class="skel" style="height:12px;width:40%"></div></div>`
    ).join("");
  }

  // ── Empty / Error States ───────────────────────────────────────────────

  function emptyState(icon, title, subtitle = "") {
    return `<div class="empty-state">
      <div class="empty-icon">${icon}</div>
      <h3>${title}</h3>
      ${subtitle ? `<p>${subtitle}</p>` : ""}
    </div>`;
  }

  function errorState(msg = "Something went wrong.", retryFn = null) {
    const safeMsg = _friendlyErrorMessage(msg);
    return `<div class="error-state">
      <div class="empty-icon">🛠️</div>
      <h3>Something went wrong</h3>
      <p>${safeMsg}</p>
      ${retryFn ? `<button class="btn-retry" onclick="${retryFn}()">Try again</button>` : ""}
    </div>`;
  }

  function _friendlyErrorMessage(msg = "") {
    const text = String(msg || "");
    const lower = text.toLowerCase();
    if (lower.includes("internal server") || lower.includes("500")) return "Server error. Please try again in a moment.";
    if (lower.includes("failed") || lower.includes("network")) return "Connection issue. Check your internet and try again.";
    return text || "Something went wrong. Please try again.";
  }

  // ── Format Helpers ─────────────────────────────────────────────────────

  function formatCurrency(amount, currency = "₹") {
    const n = Number(amount);
    if (isNaN(n)) return `${currency}0`;
    return `${currency}${n.toLocaleString("en-IN")}`;
  }

  function formatDate(dateStr) {
    if (!dateStr) return "—";
    try {
      return new Date(dateStr).toLocaleDateString("en-IN", {
        day: "numeric", month: "short", year: "numeric"
      });
    } catch { return dateStr; }
  }

  function escapeHtml(str = "") {
    return String(str || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function customerProfile(user = null) {
    const currentUser = user || (typeof AUTH !== "undefined" && AUTH.getUser?.()) || {};
    let stored = {};
    try { stored = JSON.parse(localStorage.getItem("wtg_customer_profile") || "{}"); } catch {}
    return {
      ...stored,
      name: stored.name || currentUser?.name || "",
      phone: stored.phone || currentUser?.phone || currentUser?.mobile || "",
      locality: stored.locality || currentUser?.locality || currentUser?.area || "",
      address: stored.address || currentUser?.address || "",
    };
  }

  // ── Refresh Dot ────────────────────────────────────────────────────────

  function pulseRefreshDot() {
    const dot = document.querySelector(".refresh-dot");
    if (dot) {
      dot.classList.add("pulse");
      setTimeout(() => dot.classList.remove("pulse"), 800);
    }
  }

  // ── Smart Support Selector ─────────────────────────────────────────────

  function openSupport(defaultIntent = "selector", context = {}) {
    let overlay = document.getElementById("support-selector-overlay");
    if (!overlay) {
      overlay = document.createElement("div");
      overlay.id = "support-selector-overlay";
      overlay.className = "support-selector-overlay hidden";
      overlay.innerHTML = `
        <div class="support-selector-card" role="dialog" aria-modal="true" aria-label="WorkToGo support options">
          <div class="support-selector-header">
            <div>
              <p class="support-kicker">WorkToGo</p>
              <h3>Contact WorkToGo</h3>
            </div>
            <button class="support-close" onclick="UI.closeSupport()" aria-label="Close support">×</button>
          </div>
          <div class="support-options">
            <button onclick="UI.sendSupport('service')"><strong>Talk on WhatsApp</strong><span>Share your request ID or area</span></button>
            <button onclick="UI.sendSupport('inspection')"><strong>Inspection request</strong><span>₹299 request or visit update</span></button>
            <button onclick="UI.sendSupport('account')"><strong>Account</strong><span>OTP or login help</span></button>
          </div>
        </div>`;
      overlay.addEventListener("click", e => { if (e.target === overlay) closeSupport(); });
      document.body.appendChild(overlay);
    }
    overlay.dataset.context = JSON.stringify(context || {});
    overlay.classList.remove("hidden");
    if (defaultIntent && defaultIntent !== "selector") sendSupport(defaultIntent, context);
  }

  function closeSupport() {
    document.getElementById("support-selector-overlay")?.classList.add("hidden");
  }

  function sendSupport(intent = "service", directContext = null) {
    const overlay = document.getElementById("support-selector-overlay");
    const context = directContext || _safeJSON(overlay?.dataset.context) || {};
    const labels = {
      service: "I need help choosing or booking a local service.",
      inspection: `I need help with my inspection request${context.bookingId ? ` ID ${context.bookingId}` : ""}.`,
      account: "I need help with WorkToGo OTP or account access.",
      request: `I need help with my WorkToGo request${context.bookingId ? ` ID ${context.bookingId}` : ""}.`,
    };
    const category = context.category ? ` Category: ${context.category}.` : "";
    const service = context.service ? ` Service: ${context.service}.` : "";
    const requestType = context.requestType ? ` Request type: ${context.requestType}.` : "";
    const currentState = context.currentState ? ` Current state: ${context.currentState}.` : "";
    const message = `Hi WorkToGo, ${labels[intent] || labels.service}${category}${service}${requestType}${currentState}`;
    const base = CONFIG.SERVICE_ONLY?.WHATSAPP_URL || "";
    closeSupport();
    if (base) {
      const cleanBase = base.split("?text=")[0];
      window.open(`${cleanBase}?text=${encodeURIComponent(message)}`, "_blank", "noopener");
      return;
    }
    toast(`Support: ${CONFIG.SERVICE_ONLY?.SUPPORT_PHONE || "WorkToGo"}`, "info", 4500);
  }

  function _safeJSON(str) {
    try { return str ? JSON.parse(str) : {}; } catch { return {}; }
  }

  // ── Navbar ─────────────────────────────────────────────────────────────

  function buildNav(activePage) {
    const serviceOnly = Boolean(CONFIG.FEATURES?.SERVICE_ONLY_MODE);
    const navItems = serviceOnly ? [
      {
        page: "home",
        icon: `<svg viewBox="0 0 24 24"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9.5z"/><path d="M9 21V12h6v9"/></svg>`,
        label: "Home",
      },
      {
        page: "home",
        navKey: "explore",
        icon: `<svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>`,
        label: "Explore",
        action: "ROUTER.go('home'); setTimeout(() => HomePage?.openExploreOverlay?.(), 120);",
      },
      {
        page: "account",
        icon: `<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>`,
        label: "Account",
      },
    ] : [
      {
        page: "home",
        icon: `<svg viewBox="0 0 24 24"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9.5z"/><path d="M9 21V12h6v9"/></svg>`,
        label: "Home",
      },
      {
        page: "orders",
        icon: `<svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>`,
        label: "Orders",
        feature: "SHOPPING_UI",
      },
      {
        page: "bookings",
        icon: `<svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>`,
        label: "Bookings",
      },
      {
        page: "account",
        icon: `<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>`,
        label: "Account",
      },
    ].filter(n => !n.feature || CONFIG.FEATURES?.[n.feature]);

    return `<nav class="bottom-nav">
      ${navItems.map(n => `
        <button
          class="nav-item ${activePage === n.page && !n.navKey ? "active" : ""}"
          onclick="${n.action || `ROUTER.go('${n.page}')`}"
          aria-label="${n.label}"
          aria-current="${activePage === n.page && !n.navKey ? "page" : "false"}"
        >
          ${n.icon}<span>${n.label}</span>
        </button>
      `).join("")}
    </nav>`;
  }

  return {
    toast, showNotificationBanner,
    statusBadge, skeleton, emptyState, errorState,
    formatCurrency, formatDate, escapeHtml, customerProfile,
    pulseRefreshDot, buildNav,
    openSupport, closeSupport, sendSupport,
  };
})();
