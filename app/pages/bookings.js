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

    _all = Array.isArray(res.data) ? res.data : (res.data?.bookings || res.data?.data || []);
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
          <div class="item-row muted small">
            <span>ID: ${_esc(String(b.id || "—"))}</span>
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

  function _esc(str) {
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  return { _load, setFilter };
})();
