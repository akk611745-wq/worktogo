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
          <div class="item-row muted small"><span>${_esc(_lifecycleLabel(b))}</span></div>
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
            <span>Payment: ${_esc(_paymentLabel(b.payment_method || "cod", b.payment_status, b.booking_mode))}</span>
          </div>
          <div class="item-row muted small">
            <span>ID: ${_esc(String(b.id || "—"))}</span>
          </div>
          <div class="item-row muted small">
            <span>Support available with this booking ID</span>
          </div>
          <div class="item-row muted small">
            <button class="btn-text-inline" onclick="BookingsPage.openSupport('${_esc(String(b.id || ""))}')">Get booking help</button>
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
    UI.openSupport("selector", { bookingId: id });
  }

  function _esc(str) {
    return UI.escapeHtml(str);
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
      payment_status: b.payment_status || "unpaid",
      booking_mode: b.booking_mode || _modeFromNotes(b.notes),
      vendor_route: b.vendor_route || "admin_queue",
    };
  }

  function _paymentLabel(method, status, mode) {
    if (mode === "inspection") {
      if (status === "paid" || status === "verified") return "Inspection payment verified";
      if (status === "failed") return "Inspection payment not completed";
      return "Inspection payment pending verification";
    }
    return String(method).toLowerCase() === "online" ? "Online" : "Pay after service";
  }

  function _lifecycleLabel(b) {
    const mode = b.booking_mode || "free_lead";
    if (mode === "inspection") return "Premium inspection lifecycle · company/agent visit · status tracked here";
    if (mode === "direct_vendor") return `Direct vendor lifecycle · ${b.vendor_name ? "assigned to " + b.vendor_name : "vendor receives lead"}`;
    return "Free lead lifecycle · admin assignment queue · category-wise routing";
  }

  function _modeFromNotes(notes = "") {
    const text = String(notes || "").toLowerCase();
    if (text.includes("lifecycle mode: inspection")) return "inspection";
    if (text.includes("lifecycle mode: direct_vendor")) return "direct_vendor";
    return "free_lead";
  }

  function _shortNotes(notes) {
    return String(notes || "")
      .split("\n")
      .filter(line => !/^(Lifecycle mode|Category slug|Category label|Customer name|Customer mobile|Customer locality|Customer address|Vendor route):/i.test(line.trim()))
      .slice(0, 2)
      .join(" · ")
      .slice(0, 140);
  }

  return { _load, setFilter, openSupport };
})();
