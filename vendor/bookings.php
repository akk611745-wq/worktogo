<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no"/>
<title>Jobs — WorkToGo Vendor</title>
<link rel="stylesheet" href="assets/style.css"/>
<script>
  window.WTG_BASE_URL = "<?php echo rtrim($_ENV['APP_URL'] ?? getenv('APP_URL') ?? 'https://worktogo.in', '/'); ?>";
</script>
<script src="config.js"></script>
<script src="shared/auth.js"></script>
<script src="shared/api.js"></script>
<script src="shared/shell.js"></script>
<script src="assets/app.js"></script>
<script src="shared/realtime.js"></script>
<script src="shared/analytics.js"></script>
</head>
<body>
<script>
let allBookings   = [];
let currentFilter = "all";
const STATUS_ALIASES = { open:'pending', pending:'pending', assigned:'confirmed', accepted:'confirmed', confirmed:'confirmed', vendor_accepted:'confirmed', started:'in_progress', ongoing:'in_progress', in_progress:'in_progress', delivered:'completed', completed:'completed', rejected:'requeued', requeued:'requeued', cancelled:'cancelled' };

document.addEventListener("DOMContentLoaded", async () => {
  const user = initShell("Bookings");
  if (!user) return;

  if (user.role !== CONFIG.ROLES.SERVICE) {
    document.getElementById("pageContent").innerHTML = `
      <div class="empty-state"><div class="empty-icon">🚫</div>
      <div class="empty-text">This section is only available for Service Vendors.</div></div>`;
    return;
  }

  renderPage();
  await loadBookings();

  if (CONFIG.FEATURES?.VENDOR_REALTIME_LABEL) {
    RealtimeEngine.start({
      fetchFn: async () => {
        const res = await API.Bookings.list();
        return res.ok ? _extractBookings(res).map(normalizeBooking) : [];
      },
      onNew: (newItems) => {
        newItems.forEach(item => {
          const id = String(item.id || item._id);
          if (!allBookings.find(b => String(b.id || b._id) === id)) {
            allBookings.unshift(item);
          }
        });
        _updateAnalytics();
        renderChips();
        applyFilter();
        _setRefreshLabel();
      },
      type: 'Booking',
      interval: 8000,
    });
    const ri = document.getElementById('refreshIndicator');
    if (ri) ri.style.display = 'flex';
  }
});

function renderPage() {
  const el = document.getElementById("pageContent");
  el.innerHTML = `
    <div class="page-header">
      <div>
        <h1 class="page-title">Jobs</h1>
        <p class="page-sub">Check new requests, contact customer, then update status &middot; <span id="lastRefreshLabel" class="text-muted">Loading&hellip;</span></p>
      </div>
      <button class="btn btn-ghost btn-sm" onclick="loadBookings()">Refresh</button>
    </div>

    <div id="analyticsStrip"></div>
    <div id="bookingChips" class="flex-gap" style="flex-wrap:wrap;margin-bottom:1rem;"></div>

    <div class="filter-bar" style="gap:0.4rem;margin-bottom:0.75rem;">
      <button class="btn btn-sm btn-primary"   id="tab-all"         onclick="setFilter('all')">All</button>
      <button class="btn btn-sm btn-ghost"      id="tab-pending"     onclick="setFilter('pending')">Pending</button>
      <button class="btn btn-sm btn-ghost"      id="tab-confirmed"   onclick="setFilter('confirmed')">Confirmed</button>
      <button class="btn btn-sm btn-ghost"      id="tab-in_progress" onclick="setFilter('in_progress')">In Progress</button>
      <button class="btn btn-sm btn-ghost"      id="tab-completed"   onclick="setFilter('completed')">Completed</button>
      <button class="btn btn-sm btn-ghost"      id="tab-cancelled"   onclick="setFilter('cancelled')">Cancelled</button>
      <input type="text" class="search-input" id="searchInput" placeholder="Search&hellip;" oninput="applyFilter()" style="margin-left:auto;"/>
    </div>

    <div class="card">
      <div class="card-body table-wrap" style="padding:0;">
        <div id="bookingCards" class="vendor-job-cards" style="display:none;padding:0.75rem;gap:0.75rem;flex-direction:column;"></div>
        <table>
          <thead>
            <tr>
              <th>Booking ID</th><th>Customer</th><th>Service</th>
              <th>Scheduled</th><th>Status</th><th>Actions</th>
            </tr>
          </thead>
          <tbody id="bookingTbody">
            <tr class="loading-row"><td colspan="6"><div class="spinner"></div></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Booking Detail Modal -->
    <div class="modal-backdrop" id="bookingModal">
      <div class="modal">
        <div class="modal-header">
          <span class="modal-title">Booking Details</span>
          <button class="modal-close" onclick="closeModal('bookingModal')">&#x2715;</button>
        </div>
        <div class="modal-body" id="bookingDetailBody">&#8212;</div>
        <div class="modal-footer" id="bookingModalActions">
          <button class="btn btn-ghost" onclick="closeModal('bookingModal')">Close</button>
        </div>
      </div>
    </div>

    <!-- Status Update Modal -->
    <div class="modal-backdrop" id="statusModal">
      <div class="modal" style="max-width:380px;">
        <div class="modal-header">
          <span class="modal-title">Update Status</span>
          <button class="modal-close" onclick="closeModal('statusModal')">&#x2715;</button>
        </div>
        <div class="modal-body">
          <div class="field">
            <label for="statusSelect">New Status</label>
            <select id="statusSelect">
              ${CONFIG.JOB_STATUSES.map(s => '<option value="' + s + '">' + cap(s.replace('_',' ')) + '</option>').join('')}
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost" onclick="closeModal('statusModal')">Cancel</button>
          <button class="btn btn-primary" onclick="submitStatusUpdate()">Update</button>
        </div>
      </div>
    </div>
  `;
}

async function loadBookings() {
  const res = await API.Bookings.list();
  if (!res.ok) { showToast(res.data?.message || "Failed to load bookings.", "error"); renderBookingRows([]); return; }
  allBookings = _extractBookings(res).map(normalizeBooking);
  _updateAnalytics();
  renderChips();
  applyFilter();
  _setRefreshLabel();
}

function _updateAnalytics() {
  const strip = document.getElementById('analyticsStrip');
  if (!strip) return;
  if (!allBookings.length) { strip.innerHTML = ''; return; }
  if (!CONFIG.FEATURES?.VENDOR_ANALYTICS) { strip.innerHTML = ''; return; }
  const stats = Analytics.compute(allBookings, 'amount');
  strip.innerHTML = Analytics.renderHTML(stats, true);
}

function _setRefreshLabel() {
  const el = document.getElementById('lastRefreshLabel');
  if (el) el.textContent = 'Updated ' + new Date().toLocaleTimeString('en-IN', {hour:'2-digit',minute:'2-digit',second:'2-digit'});
}

function renderChips() {
  const counts = {};
  allBookings.forEach(b => { counts[normalizeStatus(b.status)] = (counts[normalizeStatus(b.status)] || 0) + 1; });
  const chipsEl = document.getElementById("bookingChips");
  if (!chipsEl) return;
  chipsEl.innerHTML = Object.entries(counts).map(([s, c]) =>
    '<span class="badge badge-dot badge-' + s + '" style="font-size:0.75rem;padding:0.3rem 0.7rem;">' + cap(s.replace('_',' ')) + ' (' + c + ')</span>'
  ).join('');
}

function setFilter(status) {
  currentFilter = status;
  document.querySelectorAll("[id^='tab-']").forEach(b => {
    b.classList.toggle("btn-primary", b.id === 'tab-' + status);
    b.classList.toggle("btn-ghost",   b.id !== 'tab-' + status);
  });
  applyFilter();
}

function applyFilter() {
  const q = document.getElementById("searchInput")?.value || '';
  let list = currentFilter === "all" ? allBookings : allBookings.filter(b => normalizeStatus(b.status) === currentFilter);
  if (q.trim()) list = filterItems(list, q, ['id', '_id', 'customer_name', 'service_name']);
  renderBookingRows(list);
}

const BOOKING_STATUS_COLOR = { pending:'#f59e0b', confirmed:'#3b82f6', in_progress:'#f97316', completed:'#10b981', cancelled:'#6b7280' };
const BOOKING_STATUS_LABEL = { pending:'Pending', confirmed:'Confirmed', in_progress:'In Progress', completed:'Completed', cancelled:'Cancelled', requeued:'Requeued' };

function _chip(status) {
  status = normalizeStatus(status);
  const c     = BOOKING_STATUS_COLOR[status] || '#6b7280';
  const label = BOOKING_STATUS_LABEL[status] || (status||'').replace(/_/g,' ').replace(/\b\w/g, l => l.toUpperCase());
  return '<span style="display:inline-flex;align-items:center;gap:4px;padding:0.2rem 0.6rem;border-radius:20px;font-size:0.72rem;font-weight:600;background:' + c + '1a;color:' + c + ';border:1px solid ' + c + '40;"><span style="width:6px;height:6px;border-radius:50%;background:' + c + ';flex-shrink:0;"></span>' + label + '</span>';
}

function renderBookingRows(list) {
  const tbody = document.getElementById("bookingTbody");
  const cards = document.getElementById("bookingCards");
  if (!list.length) {
    tbody.innerHTML = '<tr><td colspan="6"><div class="empty-state"><div class="empty-icon">&#x1F4C5;</div><div class="empty-text">No jobs right now. Keep this page ready and tap Refresh after WorkToGo confirms a booking.</div></div></td></tr>';
    if (cards) cards.innerHTML = '';
    return;
  }
  if (cards) cards.innerHTML = list.map(b => {
    const id = b.id || b._id;
    const status = normalizeStatus(b.status);
    const jobId = b.job_id || id;
    return '<div class="card" style="border:1px solid var(--border);"><div class="card-body" style="display:grid;gap:0.45rem;">' +
      '<div style="display:flex;justify-content:space-between;gap:0.5rem;align-items:flex-start"><div><div class="fw-bold">#' + id + ' · ' + escHtml(b.service_name || b.service?.name || 'Service') + '</div><div class="text-muted text-sm">' + fmtDateTime(b.booking_date || b.date || b.scheduled_at) + '</div></div>' + _chip(status) + '</div>' +
      '<div class="text-sm"><strong>Customer:</strong> ' + escHtml(b.customer_name || b.user?.name || '—') + ' · ' + escHtml(b.customer_phone || b.user?.phone || '—') + '</div>' +
      (cleanNotes(b.notes) ? '<div class="text-sm" style="background:var(--surface-2);padding:0.5rem;border-radius:6px;white-space:pre-wrap;">' + escHtml(cleanNotes(b.notes)).slice(0,220) + '</div>' : '') +
      '<div class="td-actions" style="display:flex;gap:0.4rem;flex-wrap:wrap;margin-top:0.3rem;"><button class="btn btn-ghost btn-sm" onclick="viewBooking(\'' + id + '\')">View</button>' +
      (status === 'pending' ? '<button class="btn btn-accept btn-sm" onclick="quickAccept(\'' + id + '\',\'' + jobId + '\')">Accept</button><button class="btn btn-reject btn-sm" onclick="quickReject(\'' + id + '\',\'' + jobId + '\')">Reject</button>' : (status === 'requeued' ? '<span class="text-muted text-sm">Returned to WorkToGo queue</span>' : '<button class="btn btn-primary btn-sm" onclick="openStatusModal(\'' + id + '\',\'' + jobId + '\')">Update</button>')) +
      '</div></div></div>';
  }).join('');
  tbody.innerHTML = list.map(b => {
    const id = b.id || b._id;
    const status = normalizeStatus(b.status);
    const jobId = b.job_id || b.id || b._id;
    const actionBtns = status === 'requeued' ? '<span class="text-muted text-sm">Returned to queue</span>' : status === 'pending'
      ? '<button class="btn btn-accept btn-sm" onclick="quickAccept(\'' + id + '\',\'' + jobId + '\')">Accept</button><button class="btn btn-reject btn-sm" onclick="quickReject(\'' + id + '\',\'' + jobId + '\')">Reject</button>'
      : '<button class="btn btn-ghost btn-sm" onclick="openStatusModal(\'' + id + '\',\'' + jobId + '\')">Update</button>';
    return '<tr>' +
      '<td class="text-sm fw-bold" style="cursor:pointer;color:var(--accent);" onclick="viewBooking(\'' + id + '\')">#' + id + '</td>' +
      '<td>' + escHtml(b.customer_name || b.user?.name || '—') + '</td>' +
      '<td>' + escHtml(b.service_name || b.service?.name || '—') + '</td>' +
      '<td class="text-sm">' + fmtDateTime(b.booking_date || b.date || b.scheduled_at) + '</td>' +
      '<td>' + _chip(status) + '</td>' +
      '<td><div class="td-actions" style="gap:0.3rem;flex-wrap:wrap;"><button class="btn btn-ghost btn-sm" onclick="viewBooking(\'' + id + '\')">View</button>' + actionBtns + '</div></td>' +
      '</tr>';
  }).join('');
}

async function viewBooking(id) {
  const b = allBookings.find(x => (x.id || x._id) == id);
  if (!b) return;
  const body  = document.getElementById("bookingDetailBody");
  const actEl = document.getElementById("bookingModalActions");

  body.innerHTML =
    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-bottom:1rem;">' +
    '<div><div class="text-muted text-sm">Booking ID</div><div class="fw-bold">#' + (b.id||b._id) + '</div></div>' +
    '<div><div class="text-muted text-sm">Status</div><div>' + _chip(b.status) + '</div></div>' +
    '<div><div class="text-muted text-sm">Customer</div><div>' + escHtml(b.customer_name||b.user?.name||'—') + '</div></div>' +
    '<div><div class="text-muted text-sm">Phone / WhatsApp</div><div>' + escHtml(b.customer_phone||b.user?.phone||'—') + '</div></div>' +
    '<div><div class="text-muted text-sm">Service</div><div>' + escHtml(b.service_name||b.service?.name||'—') + '</div></div>' +
    '<div><div class="text-muted text-sm">Scheduled</div><div>' + fmtDateTime(b.booking_date||b.date||b.scheduled_at) + '</div></div>' +
    '<div><div class="text-muted text-sm">Amount</div><div class="fw-bold">' + fmtCurrency(b.amount||b.price) + '</div></div>' +
    '<div><div class="text-muted text-sm">Payment</div><div>' + (b.payment_status||'—') + '</div></div>' +
    '</div>' +
    (b.address ? '<div style="margin-bottom:0.75rem;"><div class="text-muted text-sm">Address</div><div class="text-sm">' + escHtml(b.address) + '</div></div>' : '') +
    '<div style="margin-bottom:0.75rem;"><div class="text-muted text-sm">Customer Notes</div><div class="text-sm" style="background:var(--surface-2);padding:0.6rem;border-radius:6px;margin-top:4px;">' + escHtml(cleanNotes(b.notes) || 'No notes from customer.') + '</div></div>';

  const bid = b.id || b._id;
  let footer = '<button class="btn btn-ghost" onclick="closeModal(\'bookingModal\')">Close</button>';
  const status = normalizeStatus(b.status);
  if (status === 'pending') {
    const jid = b.job_id || bid;
    footer += '<button class="btn btn-reject" onclick="quickReject(\'' + bid + '\',\'' + jid + '\');closeModal(\'bookingModal\')">Reject</button>' +
              '<button class="btn btn-accept" onclick="quickAccept(\'' + bid + '\',\'' + jid + '\');closeModal(\'bookingModal\')">Accept Booking</button>';
  } else if (!['completed','cancelled'].includes(status)) {
    const jid = b.job_id || bid;
    footer += '<button class="btn btn-primary" onclick="closeModal(\'bookingModal\');openStatusModal(\'' + bid + '\',\'' + jid + '\')">Update Status</button>';
  }
  actEl.innerHTML = footer;
  openModal("bookingModal");
}

let statusTargetId = null;
let statusTargetBookingId = null;
function openStatusModal(id, jobId = null) {
  statusTargetBookingId = id;
  statusTargetId = jobId || id;
  const b = allBookings.find(x => (x.id || x._id) == id);
  if (b) document.getElementById("statusSelect").value = normalizeStatus(b.status);
  openModal("statusModal");
}
async function submitStatusUpdate() {
  const status = document.getElementById("statusSelect").value;
  if (!statusTargetId || !status) return;
  const res = await API.Bookings.updateStatus(statusTargetId, status);
  if (!res.ok) { showToast(res.data?.message || "Update failed.", "error"); return; }
  showToast("Booking status updated!", "success");
  closeModal("statusModal");
  const idx = allBookings.findIndex(x => (x.id || x._id) == (statusTargetBookingId || statusTargetId));
  if (idx !== -1) allBookings[idx].status = normalizeStatus(res.data?.status || status);
  _updateAnalytics(); renderChips(); applyFilter();
}

async function quickAccept(id, jobId = null) {
  const res = await API.Bookings.accept(jobId || id);
  if (!res.ok) { showToast(res.data?.message || "Failed to accept.", "error"); return; }
  showToast("Booking accepted!", "success");
  const idx = allBookings.findIndex(x => (x.id || x._id) == id);
  if (idx !== -1) allBookings[idx].status = "confirmed";
  _updateAnalytics(); renderChips(); applyFilter();
}
async function quickReject(id, jobId = null) {
  if (!confirmAction("Reject this booking?")) return;
  const res = await API.Bookings.reject(jobId || id);
  if (!res.ok) { showToast(res.data?.message || "Failed to reject.", "error"); return; }
  showToast("Request returned to WorkToGo queue.", "info");
  const idx = allBookings.findIndex(x => (x.id || x._id) == id);
  if (idx !== -1) allBookings[idx].status = "requeued";
  _updateAnalytics(); renderChips(); applyFilter();
}

function cap(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
function normalizeStatus(status) { return STATUS_ALIASES[String(status || 'pending').toLowerCase()] || String(status || 'pending').toLowerCase(); }
function normalizeBooking(b) {
  return { ...b, status: normalizeStatus(b.status || b.job_status), amount: b.amount ?? b.total ?? b.price, payment_method: b.payment_method || 'cod' };
}
function _extractBookings(res) {
  const block = res.data?.blocks?.[0];
  return block?.items || block?.bookings || block?.data?.bookings || [];
}
function escHtml(str) {
  return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function cleanNotes(raw) {
  if (!raw) return null;
  const STRIP = ['Request ID:','Client request ID:','Request schema version:','Routing context:','Sorting keys:','Assignment metadata:'];
  const clean = String(raw).split('\n').filter(function(l){ const t = l.trim(); return t && !STRIP.some(function(p){ return t.startsWith(p); }); }).join('\n').trim();
  return clean || null;
}
</script>
</body>
</html>
