/**
 * WorkToGo — Bookings Page
 * Loads from API. Auto-refresh every 12s. Status matches backend values.
 */

export async function render(container) {
  if (!AUTH.requireAuth()) return;

  container.innerHTML = `
    <div class="page bookings-page">
      <header class="page-header">
        <button class="btn-back-nav" onclick="ROUTER.go('home')" aria-label="Back to home">
          <svg viewBox="0 0 24 24"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
        </button>
        <h2>My Bookings</h2>
        <span class="refresh-dot" title="Auto-refreshing"></span>
      </header>

      <div class="tab-bar">
        <button class="tab active" onclick="BookingsPage.setFilter('all',      this)">All</button>
        <button class="tab"        onclick="BookingsPage.setFilter('upcoming', this)">Upcoming</button>
        <button class="tab"        onclick="BookingsPage.setFilter('done',     this)">Done</button>
      </div>

      <div id="bookings-list" class="list-container">
        ${UI.skeleton(4, "row")}
      </div>

      ${UI.buildNav("bookings")}
    </div>
  `;

  await BookingsPage._load();
}

export async function refresh() {
  await BookingsPage._load(true);
}

window.BookingsPage = (() => {
  let _all    = [];
  let _filter = "all";

  // Upcoming — pending/confirmed/in_progress
  const UPCOMING_STATUSES = ["pending", "confirmed", "in_progress"];
  // Done
  const DONE_STATUSES     = ["completed", "cancelled"];

  async function _load(silent = false) {
    if (!silent) {
      const el = document.getElementById("bookings-list");
      if (el) el.innerHTML = UI.skeleton(4, "row");
    }

    const res = await API.getBookings();

    if (!res.ok) {
      if (!silent) {
        const el = document.getElementById("bookings-list");
        if (el) el.innerHTML = UI.errorState(res.error || "Failed to load bookings.", "BookingsPage._load");
      }
      return;
    }

    _all = (Array.isArray(res.data) ? res.data : (res.data?.bookings || res.data?.data || [])).map(_normalizeBooking);
    _render();
    if (silent) UI.pulseRefreshDot();
  }

  function _render() {
    const el = document.getElementById("bookings-list");
    if (!el) return;

    let list = _all;
    if (_filter === "upcoming") {
      list = _all.filter(b => UPCOMING_STATUSES.includes((b.status || "").toLowerCase()));
    } else if (_filter === "done") {
      list = _all.filter(b => DONE_STATUSES.includes((b.status || "").toLowerCase()));
    }

    if (!list.length) {
      el.innerHTML = UI.emptyState(
        "📅",
        _filter === "all" ? "No bookings yet" : "Nothing here",
        _filter === "all" ? "Book a service from Home" : "No bookings in this category"
      );
      return;
    }

    el.innerHTML = list.map(b => `
      <div class="list-item booking-item">
        <div class="item-icon booking-icon">${_esc(b.service_icon || "🛠️")}</div>
        <div class="item-body">
          <div class="item-row">
            <span class="item-title">${_esc(b.service_name || b.name || "Service")}</span>
            ${UI.statusBadge(b.status || "pending")}
          </div>
          ${b.status === "pending" ? `<div class="item-row muted small"><span>Request sent — WorkToGo will confirm shortly.</span></div>` : ""}
          ${b.status === "confirmed" ? `<div class="item-row muted small"><span>Provider/admin confirmed. Please keep your phone available.</span></div>` : ""}
          ${b.status === "in_progress" ? `<div class="item-row muted small"><span>Service is in progress. Pay after service only.</span></div>` : ""}
          ${b.vendor_name ? `
          <div class="item-row vendor-row">
            <svg viewBox="0 0 24 24" class="vendor-icon"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
            <span class="muted small">${_esc(b.vendor_name)}</span>
          </div>` : ""}
          <div class="item-row muted small">
            <span>${b.scheduled_at
              ? `📅 ${UI.formatDate(b.scheduled_at)}`
              : UI.formatDate(b.created_at)
            }</span>
            ${b.amount ? `<span class="item-amount">${UI.formatCurrency(b.amount)}</span>` : ""}
          </div>
          ${b.notes ? `<div class="item-row muted small"><span>${_esc(_shortNotes(b.notes))}</span></div>` : ""}
          <div class="item-row muted small">
            <span>Payment: ${_esc(_paymentLabel(b.payment_method || "cod"))}</span>
          </div>
          <div class="item-row muted small">
            <span>ID: ${_esc(String(b.id || "—"))}</span>
          </div>
          <div class="item-row muted small">
            <span>Support available with this booking ID</span>
          </div>
          <div class="item-row muted small">
            <button class="btn-text-inline" onclick="BookingsPage.openSupport('${_esc(String(b.id || ""))}')">WhatsApp support</button>
          </div>
        </div>
      </div>
    `).join("");
  }

  function setFilter(f, btn) {
    _filter = f;
    document.querySelectorAll(".tab-bar .tab").forEach(t => t.classList.remove("active"));
    if (btn) btn.classList.add("active");
    _render();
  }

  function openSupport(id) {
    const base = CONFIG.SERVICE_ONLY?.WHATSAPP_URL || "";
    if (base) {
      window.open(base + encodeURIComponent(` Booking ID: ${id}`), "_blank", "noopener");
      return;
    }
    UI.toast("Contact WorkToGo support with your booking ID.", "info");
  }

  function _esc(str) {
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function _normalizeBooking(b) {
    const status = String(b.status || b.job_status || "pending").toLowerCase();
    const map = {
      open: "pending",
      assigned: "confirmed",
      accepted: "confirmed",
      confirmed: "confirmed",
      started: "in_progress",
      ongoing: "in_progress",
      in_progress: "in_progress",
      completed: "completed",
      delivered: "completed",
      rejected: "cancelled",
      cancelled: "cancelled",
    };
    return {
      ...b,
      status: map[status] || status,
      amount: b.amount ?? b.total ?? b.price,
      payment_method: b.payment_method || "cod",
    };
  }

  function _paymentLabel(method) {
    return String(method).toLowerCase() === "online" ? "Online pending" : "Pay after service";
  }

  function _shortNotes(notes) {
    return String(notes || "").split("\n").slice(0, 2).join(" · ").slice(0, 140);
  }

  return { _load, setFilter, openSupport };
})();
