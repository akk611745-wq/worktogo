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
      const paymentBadge = _paymentBadge(b);
      const track = _bookingTrackType(b);
      const typeBadge = _trackTypeBadge(b);
      return `
      <div class="list-item booking-item collapsed ${track}-track" onclick="this.classList.toggle('collapsed')">
        <div class="item-icon booking-icon">${_esc(b.service_icon || "🛠️")}</div>
        <div class="item-body">

          <!-- Always visible: title + status + pills + date/amount -->
          <div class="item-row">
            <span class="item-title">${_esc(b.category_label || b.category_name || b.service_name || b.name || "Service")}</span>
            ${UI.statusBadge(state.key)}
            <span class="booking-chevron"></span>
          </div>
          <div class="request-status-pill-row">
            <span class="request-mini-pill state-${_esc(state.key)}">${_esc(_hindiStatusLabel(state.key, state.short))}</span>
            <span class="request-mini-pill ${_esc(paymentBadge.tone)}">${_esc(paymentBadge.label)}</span>
          </div>
          <div class="track-badge-row">${typeBadge}</div>
          <div class="item-row muted small">
            <span>${b.scheduled_at ? `📅 ${UI.formatDate(b.scheduled_at)}` : UI.formatDate(b.created_at)}</span>
            ${b.booking_mode === "free_lead" ? "" : (b.amount ? `<span class="item-amount">${UI.formatCurrency(b.amount)}</span>` : "")}
          </div>

          <!-- Collapsible detail — customer-facing fields only: Status, Category, Date, Vendor, Area -->
          <div class="booking-detail">
            ${_trackHTML(b)}
            <div class="item-row muted small"><span>Category: ${_esc(b.category_label || b.category_name || b.service_name || "Service")}</span></div>
            <div class="item-row muted small"><span>Date: ${_esc(b.scheduled_at ? UI.formatDate(b.scheduled_at) : UI.formatDate(b.created_at))}</span></div>
            ${b.vendor_name ? `
            <div class="item-row vendor-row">
              <svg viewBox="0 0 24 24" class="vendor-icon"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
              <span class="muted small">${_esc(b.vendor_name)}</span>
            </div>` : ""}
            ${b.locality ? `<div class="item-row muted small"><span>Area: ${_esc(b.locality)}${b.city ? ` · ${_esc(b.city)}` : ""}</span></div>` : ""}
            <div class="item-row muted small">
              <span>ID: ${_esc(b.booking_number ? String(b.booking_number) : (b.id ? `#${b.id}` : "—"))}</span>
            </div>
            <div class="item-row muted small">
              <button class="btn-text-inline" onclick="event.stopPropagation(); BookingsPage.openSupport('${_esc(String(b.id || ""))}')">Get booking help</button>
            </div>
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
      ? "Book a service to see matching, worker confirmation, expert visit and completion updates here."
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
      issue_list: _normalizeIssueList(b.issue_list || b.operational_request?.issue_list || _fieldFromNotes(b.notes, "Issue list") || b.subservice || b.issue_type),
      issue_summary: b.issue_summary || b.operational_request?.issue_summary || _fieldFromNotes(b.notes, "Issue summary") || _issueSummary(b.issue_list || b.operational_request?.issue_list || _fieldFromNotes(b.notes, "Issue list") || b.subservice || b.issue_type),
      priority: b.priority || b.operational_request?.priority || _fieldFromNotes(b.notes, "Priority") || "normal_priority",
      lifecycle_state: b.lifecycle_state || b.operational_request?.lifecycle_state || _fieldFromNotes(b.notes, "Lifecycle state"),
      assignment_state: b.assignment_state || b.operational_request?.assignment_state || _fieldFromNotes(b.notes, "Assignment state"),
      timeline: Array.isArray(b.timeline) ? b.timeline : (Array.isArray(b.operational_request?.timeline) ? b.operational_request.timeline : []),
      subservice: b.subservice || b.selected_service || _fieldFromNotes(b.notes, "Subservice") || _fieldFromNotes(b.notes, "Selected service"),
      locality: b.customer_locality || b.locality || b.selected_nearby_area || _fieldFromNotes(b.notes, "Locality") || _fieldFromNotes(b.notes, "Selected nearby area"),
      city: b.selected_city || b.city || _fieldFromNotes(b.notes, "Selected city"),
      locality_source: b.locality_source || _fieldFromNotes(b.notes, "Locality source"),
      request_source: b.request_source || _fieldFromNotes(b.notes, "Request source"),
      operational_tracking_state: b.operational_tracking_state || b.tracking_state || b.lifecycle_state || _fieldFromNotes(b.notes, "Lifecycle state") || _fieldFromNotes(b.notes, "Tracking state"),
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

  function _title(v = "") {
    return String(v || "").replace(/_/g, " ").replace(/\b\w/g, m => m.toUpperCase());
  }

  function _normalizePaymentState(value = "") {
    const raw = String(value || "").trim().toLowerCase().replace(/[\s-]+/g, "_");
    if (["paid", "success", "successful", "completed", "verified", "captured", "payment_success"].includes(raw)) return "paid";
    if (["failed", "failure", "declined", "error", "payment_failed"].includes(raw)) return "failed";
    if (["cancelled", "canceled", "user_dropped", "aborted", "dropped"].includes(raw)) return "cancelled";
    if (["refunded", "refund", "reversed"].includes(raw)) return "refunded";
    if (["unpaid", "pending", "created", "active", "initiated", "processing", "requires_payment", "payment_pending", "verifying", "flagged", ""].includes(raw)) return "pending";
    return raw.includes("paid") && !raw.includes("un") ? "paid" : raw.includes("fail") ? "failed" : raw.includes("cancel") ? "cancelled" : "pending";
  }

  function _localitySourceLabel(source = "") {
    const map = { manual_selected: "manual selection", manual_city: "manual city", manual_typed: "typed area", typed: "typed area", saved: "saved area", browser_hint: "browser hint", fallback: "fallback city" };
    return map[String(source || "").toLowerCase()] || _title(source);
  }

  function _trackingState(b) {
    const mode = String(b.booking_mode || "free_lead").toLowerCase();
    const raw = String(b.operational_tracking_state || b.status || "pending").toLowerCase();
    const paymentState = _normalizePaymentState(b.payment_status || "");
    const paid = paymentState === "paid";
    const key = mode === "inspection" && ["pending", "open", "payment_pending"].includes(raw)
      ? (paid ? "inspection_queued" : "payment_pending")
      : raw;
    const table = {
      payment_pending: ["Payment pending", "Complete Expert Visit payment so the visit can be confirmed", "Expert Visit request is saved. Payment must be verified before visit assignment."],
      inspection_queued: ["Expert Visit queued", "Coordinator is reviewing your Expert Visit request", "Payment is verified and the Expert Visit request is queued."],
      coordinator_reviewing: ["Coordinator reviewing", "WorkToGo is checking issue details and area", "Your Expert Visit is being reviewed for assignment."],
      inspection_requested: ["Expert Visit requested", "WorkToGo will assign an expert", "Your issue details and address are with WorkToGo."],
      inspection_assigned: ["Expert Visit worker assigned", "Keep your phone available for timing confirmation", "An expert has been selected for your visit."],
      inspection_scheduled: ["Expert Visit scheduled", "The expert will visit at the confirmed time", "Your Expert Visit timing is confirmed."],
      inspection_completed: ["Expert Visit completed", "WorkToGo will share next work steps", "Diagnosis is complete and next work can be confirmed."],
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
      pending: mode === "direct_vendor" ? ["Worker requested", "WorkToGo will ask the selected worker", "Your selected worker request has been sent."] : mode === "inspection" ? ["Expert Visit requested", "WorkToGo will assign an expert", "Your issue details and address are with WorkToGo."] : ["Request received", "Nearby worker matching will start", "WorkToGo has your service, area and issue note."],
      assigned: ["Worker assigned", "Keep your phone available for visit coordination", "A worker has been assigned to your request."],
      confirmed: mode === "direct_vendor" ? ["Worker confirmed", "Worker visit is being coordinated", "The worker has confirmed your request."] : ["Worker assigned", "Keep your phone available for visit coordination", "A worker has been assigned to your request."],
      in_progress: ["Service in progress", "Pay after service when work is complete", "Your service work has started."],
    };
    const row = table[key] || table.pending;
    return { key, label: row[0], short: _statusShortLabel(key, row[0]), next: row[1], message: row[2] };
  }

  function _hindiStatusLabel(key = "", fallback = "Pending") {
    const pendingKeys = ["pending", "payment_pending", "request_received", "nearby_matching", "worker_contacting", "worker_requested", "awaiting_response", "inspection_requested", "coordinator_reviewing", "inspection_queued"];
    const assignedKeys = ["assigned", "confirmed", "worker_assigned", "worker_confirmed", "inspection_assigned", "inspection_scheduled"];
    const inProgressKeys = ["in_progress", "service_in_progress"];
    const completedKeys = ["completed", "done", "inspection_completed"];
    const k = String(key || "").toLowerCase();
    if (pendingKeys.includes(k)) return "Request bheja gaya";
    if (assignedKeys.includes(k)) return "Karigar assign hua";
    if (inProgressKeys.includes(k)) return "Kaam chal raha hai";
    if (completedKeys.includes(k)) return "Kaam complete hua";
    return _statusShortLabel(key, fallback);
  }

  function _statusShortLabel(key = "", fallback = "Pending") {
    const map = {
      payment_pending: "Payment pending",
      request_received: "Pending",
      pending: "Pending",
      nearby_matching: "Searching",
      worker_contacting: "Searching",
      worker_requested: "Pending",
      awaiting_response: "Pending",
      assigned: "Assigned",
      confirmed: "Assigned",
      worker_assigned: "Assigned",
      worker_confirmed: "Assigned",
      inspection_queued: "Expert Visit queued",
      coordinator_reviewing: "Reviewing",
      inspection_requested: "Expert Visit queued",
      inspection_assigned: "Assigned",
      in_progress: "In progress",
      service_in_progress: "In progress",
      done: "Completed",
      completed: "Completed",
      cancelled: "Cancelled",
    };
    return map[String(key || "").toLowerCase()] || fallback;
  }

  function _paymentBadge(b = {}) {
    const mode = String(b.booking_mode || "free_lead").toLowerCase();
    const state = _normalizePaymentState(b.payment_status || "");
    if (mode === "inspection") return state === "paid" ? { label: "Paid Expert Visit", tone: "paid" } : { label: "Payment pending", tone: "payment-pending" };
    return { label: "Free request", tone: "free" };
  }

  function _normalizeIssueList(value = "") {
    const source = Array.isArray(value) ? value : String(value || "").split(/[,|]/);
    const seen = new Set();
    return source.map(item => String(item || "").replace(/\s+/g, " ").trim()).filter(item => {
      const key = item.toLowerCase();
      if (!key || seen.has(key)) return false;
      seen.add(key);
      return true;
    });
  }

  function _requestTypeFromMode(mode = "") {
    if (mode === "inspection") return "inspection";
    if (mode === "direct_vendor" || mode === "direct_worker") return "direct_worker";
    return "free_match";
  }

  function _modeFromNotes(notes = "") {
    const text = String(notes || "").toLowerCase();
    if (text.includes("booking mode: inspection")) return "inspection";
    if (text.includes("booking mode: direct_vendor")) return "direct_vendor";
    return "free_lead";
  }

  function _fieldFromNotes(notes = "", field = "") {
    const prefix = `${field}:`;
    return String(notes || "").split("\n").map(line => line.trim()).find(line => line.toLowerCase().startsWith(prefix.toLowerCase()))?.slice(prefix.length).trim() || "";
  }

  function _issueSummary(value = "") {
    return _normalizeIssueList(value)
      .map(item => item.replace(/\b(issue|problem|service|work)\b$/i, "").replace(/\s+/g, " ").trim())
      .filter(Boolean)
      .slice(0, 4)
      .join(" + ");
  }

  // ── V2 tracking split: Company track vs Direct Vendor track ───────────────
  // Detection is purely presentational, from existing structured fields:
  //   booking_mode === 'direct_vendor'  -> simple Direct Vendor track
  //   everything else (free_lead / inspection / expert_visit / package) -> Company track
  function _bookingTrackType(b) {
    return String(b.booking_mode || "free_lead").toLowerCase() === "direct_vendor" ? "direct" : "company";
  }

  function _trackTypeBadge(b) {
    if (_bookingTrackType(b) === "direct") {
      return `<span class="track-type-badge track-direct">👤 ${_esc(b.vendor_name ? `Direct — ${b.vendor_name}` : "Direct booking")}</span>`;
    }
    if (String(b.visit_type || "").toLowerCase() === "expert_visit") {
      return `<span class="track-type-badge track-company track-expert">🧑‍🔧 Expert Visit</span>`;
    }
    if (b.package_name) {
      return `<span class="track-type-badge track-company track-package">📦 ${_esc(b.package_name)}</span>`;
    }
    return `<span class="track-type-badge track-company">🏢 Company Service</span>`;
  }

  function _trackHTML(b) {
    if (String(b.status || "").toLowerCase() === "cancelled") {
      return `<div class="track-cancelled">This request is not active. Book again or contact support if needed.</div>`;
    }
    return _bookingTrackType(b) === "direct" ? _directTrackHTML(b) : _companyTrackHTML(b);
  }

  function _companyTrackHTML(b) {
    const status = String(b.status || "pending").toLowerCase();
    // Company steps map 1:1 from the normalized status — no internal state names.
    const idx = ({ pending: 0, confirmed: 1, assigned: 2, in_progress: 3, done: 4 })[status] ?? 0;
    const paidVisit = String(b.booking_mode || "").toLowerCase() === "inspection";
    const paid = _normalizePaymentState(b.payment_status || "") === "paid";
    const step2 = paidVisit ? (paid ? "Payment confirmed" : "Awaiting payment") : "Request confirmed";
    const steps = ["Requested", step2, "Expert assigned", "In progress", "Completed"];
    const rows = steps.map((label, i) => {
      const cls = i < idx ? "done" : i === idx ? "active" : "pending";
      return `<div class="company-track-step ${cls}"><span class="company-track-dot"></span><span class="company-track-label">${_esc(label)}</span></div>`;
    }).join("");

    const ctx = [];
    if (b.package_name) {
      const bits = [];
      if (b.area_sqft) bits.push(`${_esc(String(b.area_sqft))} sq ft`);
      if (b.estimate_amount != null && b.estimate_amount !== "") bits.push(`est ${UI.formatCurrency(Number(b.estimate_amount))}`);
      ctx.push(`<div class="company-track-ctx">📦 ${_esc(b.package_name)}${bits.length ? ` · ${bits.join(" · ")}` : ""}</div>`);
    }
    if (String(b.visit_type || "").toLowerCase() === "expert_visit") {
      const free = Number(b.expert_visit_free) === 1;
      const feeTxt = free ? "Free" : (b.expert_visit_fee != null && b.expert_visit_fee !== "" ? UI.formatCurrency(Number(b.expert_visit_fee)) : "paid");
      ctx.push(`<div class="company-track-ctx">🧑‍🔧 Expert visit · ${_esc(feeTxt)}</div>`);
    }
    if (b.final_quote_amount != null && b.final_quote_amount !== "") {
      ctx.push(`<div class="company-track-ctx">💰 Quote after visit: ${UI.formatCurrency(Number(b.final_quote_amount))}</div>`);
    }

    return `
      <div class="company-track">
        <div class="company-track-trust">WorkToGo is handling this for you</div>
        <div class="company-track-steps">${rows}</div>
        ${ctx.join("")}
      </div>`;
  }

  function _directTrackHTML(b) {
    const status = String(b.status || "pending").toLowerCase();
    // -1 = requested (awaiting accept); 0 accepted; 1 started; 2 completed
    const idx = ({ pending: -1, confirmed: 0, assigned: 0, in_progress: 1, done: 2 })[status] ?? -1;
    const labels = ["Accepted", "Started", "Completed"];
    const badges = labels.map((label, i) => {
      const cls = i < idx ? "done" : i === idx ? "active" : "pending";
      return `<span class="direct-track-badge ${cls}">${_esc(label)}</span>`;
    }).join("");
    const vendor = b.vendor_name ? _esc(b.vendor_name) : "your chosen worker";
    const head = idx < 0 ? `Waiting for <b>${vendor}</b> to accept` : `<b>${vendor}</b>`;
    return `
      <div class="direct-track">
        <div class="direct-track-vendor"><span class="direct-track-avatar">👤</span> ${head}</div>
        <div class="direct-track-badges">${badges}</div>
      </div>`;
  }

  return { _load, setFilter, openSupport };
})();
