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
        <h2>My Requests</h2>
        <span class="refresh-dot" title="Auto-refreshing"></span>
      </header>

      <div class="tab-bar">
        <button class="tab active" onclick="BookingsPage.setFilter('all',      this)">All</button>
        <button class="tab"        onclick="BookingsPage.setFilter('upcoming', this)">Upcoming</button>
        <button class="tab"        onclick="BookingsPage.setFilter('done',     this)">Completed</button>
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
  const UPCOMING_STATUSES = ["pending", "confirmed", "assigned", "in_progress"];
  // Done
  const DONE_STATUSES     = ["completed", "done", "cancelled"];

  async function _load(silent = false) {
    if (!silent) {
      const el = document.getElementById("bookings-list");
      if (el) el.innerHTML = UI.skeleton(4, "row");
    }

    const res = await API.getBookings();

    if (!res.ok) {
      if (!silent) {
        const el = document.getElementById("bookings-list");
        if (el) el.innerHTML = _bookingRecoveryState(res.error);
      }
      return;
    }

    _all = _extractBookings(res.data).map(_normalizeBooking);
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
      el.innerHTML = _bookingEmptyState(_filter);
      return;
    }

    el.innerHTML = list.map(b => {
      const state = _trackingState(b);
      return `
      <div class="list-item booking-item">
        <div class="item-icon booking-icon">${_esc(b.service_icon || "🛠️")}</div>
        <div class="item-body">
          <div class="item-row">
            <span class="item-title">${_esc(b.category_label || b.category_name || b.service_name || b.name || "Service")}</span>
            ${UI.statusBadge(state.key)}
          </div>
          <div class="item-row muted small"><span>Category: ${_esc(b.category_label || b.category_name || b.service_name || "Service")}</span></div>
          ${b.subservice ? `<div class="item-row muted small"><span>Service: ${_esc(b.subservice)}</span></div>` : ""}
          ${b.locality ? `<div class="item-row muted small"><span>Area routing: ${_esc(b.locality)}${b.locality_source ? ` · ${_esc(_localitySourceLabel(b.locality_source))}` : ""}</span></div>` : ""}
          <div class="item-row muted small"><span>Mode: ${_esc(_modeLabel(b.booking_mode))}</span></div>
          <div class="item-row muted small"><span>Now: ${_esc(state.label)}</span></div>
          <div class="item-row muted small"><span>Next: ${_esc(state.next)}</span></div>
          <div class="item-row muted small"><span>Date: ${_esc(b.scheduled_at ? UI.formatDate(b.scheduled_at) : UI.formatDate(b.created_at))}</span></div>
          <div class="item-row muted small"><span>${_esc(_lifecycleLabel(b))}</span></div>
          ${state.message ? `<div class="item-row muted small"><span>${_esc(state.message)}</span></div>` : ""}
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
    `;
    }).join("");
  }

  function setFilter(f, btn) {
    _filter = f;
    document.querySelectorAll(".tab-bar .tab").forEach(t => t.classList.remove("active"));
    if (btn) btn.classList.add("active");
    _render();
  }

  function openSupport(id) {
    const booking = _all.find(b => String(b.id || "") === String(id || "")) || {};
    const state = _trackingState(booking);
    UI.openSupport("selector", {
      bookingId: id,
      requestType: booking.request_type || _requestTypeFromMode(booking.booking_mode),
      category: booking.category_label || booking.category_name || booking.service_name || "Service",
      service: booking.subservice || booking.service_name || booking.name || "Service request",
      currentState: state.label,
    });
  }

  function _bookingEmptyState(filter) {
    const title = filter === "all" ? "Request tracking ready" : "No matching requests here";
    const text = filter === "all"
      ? "Book a service to see matching, worker confirmation, inspection and completion updates here."
      : "When a request reaches this step, WorkToGo will show the update here.";
    return `<div class="empty-state operational-empty-state">
      <div class="empty-icon">📍</div>
      <h3>${_esc(title)}</h3>
      <p>${_esc(text)}</p>
      <button class="btn-retry" onclick="ROUTER.go('home')">Open services</button>
    </div>`;
  }

  function _bookingRecoveryState(error = "") {
    return `<div class="empty-state operational-empty-state">
      <div class="empty-icon">🛠️</div>
      <h3>Booking tracking is active</h3>
      <p>${_esc(_friendlyBookingMessage(error))}</p>
      <button class="btn-retry" onclick="BookingsPage._load()">Refresh tracking</button>
    </div>`;
  }

  function _friendlyBookingMessage(error = "") {
    const text = String(error || "").toLowerCase();
    if (text.includes("network") || text.includes("failed")) return "Your requests remain in WorkToGo coordination. Refresh when your connection is stable.";
    return "Requests are tracked after booking confirmation. Support can help with any booking ID.";
  }

  function _esc(str) {
    return UI.escapeHtml(str);
  }

  function _normalizeBooking(b) {
    const status = String(b.status || b.job_status || "pending").toLowerCase();
    const map = {
      open: "pending",
      assigned: "assigned",
      accepted: "confirmed",
      confirmed: "confirmed",
      started: "in_progress",
      ongoing: "in_progress",
      in_progress: "in_progress",
      completed: "done",
      done: "done",
      delivered: "done",
      rejected: "cancelled",
      cancelled: "cancelled",
    };
    return {
      ...b,
      status: map[status] || status,
      request_type: b.request_type || _requestTypeFromMode(b.booking_mode || _modeFromNotes(b.notes)),
      subservice: b.subservice || b.selected_service || _fieldFromNotes(b.notes, "Subservice") || _fieldFromNotes(b.notes, "Selected service"),
      locality: b.customer_locality || b.locality || b.selected_nearby_area || _fieldFromNotes(b.notes, "Locality") || _fieldFromNotes(b.notes, "Selected nearby area"),
      locality_source: b.locality_source || _fieldFromNotes(b.notes, "Locality source"),
      request_source: b.request_source || _fieldFromNotes(b.notes, "Request source"),
      operational_tracking_state: b.operational_tracking_state || b.tracking_state || _fieldFromNotes(b.notes, "Tracking state"),
      amount: b.amount ?? b.total ?? b.price,
      payment_method: b.payment_method || "cod",
      payment_status: b.payment_status || "unpaid",
      booking_mode: b.booking_mode || _modeFromNotes(b.notes),
      vendor_route: b.vendor_route || "admin_queue",
    };
  }

  function _extractBookings(data) {
    if (Array.isArray(data)) return data;
    if (Array.isArray(data?.bookings)) return data.bookings;
    if (Array.isArray(data?.data)) return data.data;
    const block = (data?.blocks || []).find(b => b.engine === "service") || data?.blocks?.[0];
    const payload = block?.data || block?.payload || block || {};
    return payload.bookings || payload.items || [];
  }

  function _statusLabel(status = "") {
    const map = { pending: "Request received", confirmed: "Worker confirmed", assigned: "Worker assigned", done: "Completed", in_progress: "Service in progress", completed: "Completed" };
    return map[String(status || "").toLowerCase()] || _title(status || "pending");
  }

  function _modeLabel(mode = "") {
    const map = { inspection: "Inspection", free_lead: "Free worker match", free_match: "Free worker match", direct_vendor: "Direct worker request", direct_worker: "Direct worker request" };
    return map[String(mode || "free_lead").toLowerCase()] || _title(mode);
  }

  function _title(v = "") {
    return String(v || "").replace(/_/g, " ").replace(/\b\w/g, m => m.toUpperCase());
  }

  function _paymentLabel(method, status, mode) {
    if (mode === "inspection") {
      if (status === "paid" || status === "verified") return "Inspection payment verified";
      if (status === "failed") return "Inspection payment not completed";
      return "Inspection payment pending verification";
    }
    return String(method).toLowerCase() === "online" ? "Online" : "Pay after service";
  }

  function _localitySourceLabel(source = "") {
    const map = { manual_selected: "manual selection", manual_typed: "typed area", typed: "typed area", saved: "saved area", browser_hint: "browser hint", fallback: "fallback city" };
    return map[String(source || "").toLowerCase()] || _title(source);
  }

  function _lifecycleLabel(b) {
    const mode = b.booking_mode || "free_lead";
    if (mode === "inspection") return "Inspection request · payment check · visit update tracked here";
    if (mode === "direct_vendor") return b.vendor_name ? `Selected worker request · ${b.vendor_name}` : "Selected worker request · WorkToGo will confirm availability";
    return "Free worker match · WorkToGo will contact nearby workers";
  }

  function _trackingState(b) {
    const mode = String(b.booking_mode || "free_lead").toLowerCase();
    const raw = String(b.operational_tracking_state || b.status || "pending").toLowerCase();
    const paid = ["paid", "verified"].includes(String(b.payment_status || "").toLowerCase());
    const key = mode === "inspection" && !paid && ["pending", "open", "payment_pending"].includes(raw) ? "payment_pending" : raw;
    const table = {
      payment_pending: ["Payment pending", "Complete inspection payment so the visit can be confirmed", "Inspection request is saved. Payment must be verified before visit assignment."],
      inspection_requested: ["Inspection requested", "WorkToGo will assign an inspection technician", "Your issue details and address are with WorkToGo."],
      inspection_assigned: ["Inspection worker assigned", "Keep your phone available for timing confirmation", "A technician has been selected for the inspection."],
      inspection_scheduled: ["Inspection visit scheduled", "Technician will visit at the confirmed time", "Your inspection timing is confirmed."],
      inspection_completed: ["Inspection completed", "WorkToGo will share next work steps", "Diagnosis is complete and next work can be confirmed."],
      request_received: ["Request received", "Nearby worker matching will start", "WorkToGo has your service, area and issue note."],
      nearby_matching: ["Nearby worker matching started", "WorkToGo is checking suitable local workers", "Your request is being matched by area and service type."],
      worker_contacting: ["Worker being contacted", "A worker will confirm availability", "WorkToGo is checking timing with a local worker."],
      worker_assigned: ["Worker assigned", "Keep your phone available for visit coordination", "A worker has been assigned to your request."],
      worker_requested: ["Worker requested", "WorkToGo will ask the selected worker", "Your selected worker request has been sent."],
      awaiting_response: ["Waiting for worker response", "WorkToGo will confirm or route another suitable worker", "The selected worker is being checked for availability."],
      worker_confirmed: ["Worker confirmed", "Worker visit is being coordinated", "The worker has confirmed your request."],
      service_in_progress: ["Service in progress", "Pay after service when work is complete", "Your service work has started."],
      completed: ["Completed", "Use booking help if anything needs correction", "This request is marked complete."],
      done: ["Completed", "Use booking help if anything needs correction", "This request is marked complete."],
      cancelled: ["Cancelled", "Book again or contact support if needed", "This request is not active."],
      pending: mode === "direct_vendor" ? ["Worker requested", "WorkToGo will ask the selected worker", "Your selected worker request has been sent."] : mode === "inspection" ? ["Inspection requested", "WorkToGo will assign an inspection technician", "Your issue details and address are with WorkToGo."] : ["Request received", "Nearby worker matching will start", "WorkToGo has your service, area and issue note."],
      assigned: ["Worker assigned", "Keep your phone available for visit coordination", "A worker has been assigned to your request."],
      confirmed: mode === "direct_vendor" ? ["Worker confirmed", "Worker visit is being coordinated", "The worker has confirmed your request."] : ["Worker assigned", "Keep your phone available for visit coordination", "A worker has been assigned to your request."],
      in_progress: ["Service in progress", "Pay after service when work is complete", "Your service work has started."],
    };
    const row = table[key] || table.pending;
    return { key, label: row[0], next: row[1], message: row[2] };
  }

  function _requestTypeFromMode(mode = "") {
    if (mode === "inspection") return "inspection";
    if (mode === "direct_vendor" || mode === "direct_worker") return "direct_worker";
    return "free_match";
  }

  function _modeFromNotes(notes = "") {
    const text = String(notes || "").toLowerCase();
    if (text.includes("lifecycle mode: inspection")) return "inspection";
    if (text.includes("lifecycle mode: direct_vendor")) return "direct_vendor";
    if (text.includes("booking mode: inspection")) return "inspection";
    if (text.includes("booking mode: direct_vendor")) return "direct_vendor";
    return "free_lead";
  }

  function _fieldFromNotes(notes = "", field = "") {
    const prefix = `${field}:`;
    return String(notes || "").split("\n").map(line => line.trim()).find(line => line.toLowerCase().startsWith(prefix.toLowerCase()))?.slice(prefix.length).trim() || "";
  }

  function _shortNotes(notes) {
    return String(notes || "")
      .split("\n")
      .filter(line => !/^(Lifecycle mode|Category slug|Category label|Customer name|Customer mobile|Customer locality|Customer address|Vendor route|Request type|Booking mode|Booking mode meaning|Tracking state|Request source|Mobile|Locality|Selected nearby area|Locality source|Address|Preferred time|Selected worker):/i.test(line.trim()))
      .slice(0, 2)
      .join(" · ")
      .slice(0, 140);
  }

  return { _load, setFilter, openSupport };
})();
