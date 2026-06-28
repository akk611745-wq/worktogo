<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no"/>
<title>Jobs — WorkToGo Vendor</title>
  <link rel="icon" href="/app/assets/favicon.png"/>
<link rel="stylesheet" href="assets/style.css"/>
<script>
  window.WTG_BASE_URL = "<?php echo rtrim($_ENV['APP_URL'] ?? getenv('APP_URL') ?? 'https://worktogo.in', '/'); ?>";
</script>
<script defer src="config.js"></script>
<script defer src="shared/auth.js"></script>
<script defer src="shared/api.js"></script>
<script defer src="shared/push.js"></script>
<script defer src="shared/shell.js"></script>
<script defer src="assets/app.js"></script>
<script defer src="shared/realtime.js"></script>
<script defer src="shared/analytics.js"></script>
</head>
<body>
<script>
let allBookings   = [];
let currentFilter = "all";
const _localStatusOverride = new Map();
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

  const profileRes = await API.Profile.get();
  const p = profileRes.ok ? (profileRes.data?.data || profileRes.data) : null;
  if (p) {
    let areas;
    try { areas = JSON.parse(p.service_localities || '[]'); } catch (_) { areas = []; }
    if (!p.category_id || !Array.isArray(areas) || areas.length === 0) {
      window.location = 'profile.php';
      return;
    }
  }

  renderPage();
  await loadBookings();
  _seedKnownIds();
  _startPolling();
  document.addEventListener('visibilitychange', _restartPolling);

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
    <div id="newJobBanner" style="display:none;position:sticky;top:0;z-index:50;background:#16a34a;color:#fff;padding:0.75rem 1rem;border-radius:8px;margin-bottom:0.75rem;cursor:pointer;font-weight:600;align-items:center;justify-content:space-between;gap:0.75rem;box-shadow:0 2px 10px rgba(0,0,0,0.18);" onclick="scrollToNewJob()"></div>

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

// ── Poll interval adapts to tab visibility: 15s while active, 60s while
// hidden — keeps the server load down without losing the new-job alert.
let _pollIntervalId = null;
function _startPolling() {
  if (_pollIntervalId) clearInterval(_pollIntervalId);
  const delay = document.hidden ? 60000 : 15000;
  _pollIntervalId = setInterval(_pollForNewJobs, delay);
}
function _restartPolling() { _startPolling(); }

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

// ── Real-time new job alert (background poll every 15s) ────────────────
let _knownBookingIds = new Set();
let _pendingNewJobId = null;

function _seedKnownIds() {
  _knownBookingIds = new Set(allBookings.map(b => String(b.id || b._id)));
}

async function _pollForNewJobs() {
  const res = await API.Bookings.list();
  if (!res.ok) return;
  const fresh = _extractBookings(res).map(normalizeBooking);
  const newOnes = fresh.filter(b => !_knownBookingIds.has(String(b.id || b._id)));
  if (!newOnes.length) return;

  newOnes.forEach(b => allBookings.unshift(b));
  _seedKnownIds();
  _updateAnalytics();
  renderChips();
  applyFilter();
  _setRefreshLabel();
  _announceNewJob(newOnes[0]);
}

function _playBeep() {
  try {
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return;
    const ctx = new Ctx();
    [880, 1100].forEach((freq, i) => {
      const osc  = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.frequency.value = freq;
      gain.gain.value = 0.15;
      const start = ctx.currentTime + i * 0.18;
      osc.start(start);
      osc.stop(start + 0.15);
    });
    setTimeout(() => ctx.close(), 600);
  } catch (_) { /* Web Audio unavailable — banner alone still shows */ }
}

function _announceNewJob(b) {
  _playBeep();
  const id = String(b.id || b._id);
  _pendingNewJobId = id;
  const service  = b.service_name || b.service?.name || 'Service';
  const locality = b.customer_locality || b.locality || '';
  const label = locality ? (service + ' - ' + locality) : service;
  const banner = document.getElementById('newJobBanner');
  if (!banner) return;
  banner.innerHTML =
    '<span>🔔 Naya job aaya! ' + escHtml(label) + '</span>' +
    '<button onclick="event.stopPropagation();document.getElementById(\'newJobBanner\').style.display=\'none\';" style="background:none;border:none;color:#fff;font-size:1rem;cursor:pointer;line-height:1;">&#x2715;</button>';
  banner.style.display = 'flex';
}

function scrollToNewJob() {
  const id = _pendingNewJobId;
  const banner = document.getElementById('newJobBanner');
  if (banner) banner.style.display = 'none';
  if (!id) return;
  setFilter('all');
  setTimeout(() => {
    const target = document.getElementById('card-' + id) || document.getElementById('row-' + id);
    if (!target) return;
    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    const detail = document.getElementById('detail-' + id);
    if (detail && detail.style.display === 'none') toggleCardDetail('detail-' + id, 'arrow-' + id);
    target.style.outline = '2px solid var(--accent)';
    setTimeout(() => { target.style.outline = ''; }, 2000);
  }, 50);
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
  if (q.trim()) list = filterItems(list, q, ['id', '_id', 'booking_number', 'customer_name', 'customer_phone', 'customer_mobile', 'service_name']);
  renderBookingRows(list);
}

// ── A vendor's Accept/Reject is applied to the server inside a DB
// transaction, but a Refresh or background poll firing immediately after can
// still read a connection that hasn't seen the commit yet (or a CDN/proxy
// cache in front of the API). This override keeps the optimistic local
// status authoritative for 60s so the action never visibly "reverts".
function _applyStatusOverrides(list) {
  list.forEach(b => {
    const id = String(b.id || b._id);
    if (_localStatusOverride.has(id)) b.status = _localStatusOverride.get(id);
  });
  return list;
}

const BOOKING_STATUS_COLOR = { pending:'#f59e0b', confirmed:'#3b82f6', in_progress:'#8b5cf6', completed:'#10b981', cancelled:'#6b7280' };
const BOOKING_STATUS_LABEL = { pending:'Pending', confirmed:'Confirmed', in_progress:'In Progress', completed:'Completed', cancelled:'Cancelled', requeued:'Requeued' };

function _chip(status) {
  status = normalizeStatus(status);
  const c     = BOOKING_STATUS_COLOR[status] || '#6b7280';
  const label = BOOKING_STATUS_LABEL[status] || (status||'').replace(/_/g,' ').replace(/\b\w/g, l => l.toUpperCase());
  return '<span style="display:inline-flex;align-items:center;gap:4px;padding:0.2rem 0.6rem;border-radius:20px;font-size:0.72rem;font-weight:600;background:' + c + '1a;color:' + c + ';border:1px solid ' + c + '40;"><span style="width:6px;height:6px;border-radius:50%;background:' + c + ';flex-shrink:0;"></span>' + label + '</span>';
}

function renderBookingRows(list) {
  list = _applyStatusOverrides(list);
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
    const collapsedByDefault = (status === 'completed' || status === 'cancelled');
    const detailId = 'detail-' + id;
    const arrowId = 'arrow-' + id;
    const detail =
      '<div class="text-sm" style="font-size:0.95rem;"><strong style="font-weight:700;">' + escHtml(b.customer_name || b.user?.name || '—') + '</strong> · ' + escHtml(b.customer_phone || b.user?.phone || '—') + '</div>' +
      (b.customer_locality ? '<div style="font-size:13px;color:#666;margin-top:2px;">📍 ' + escHtml(b.customer_locality) + '</div>' : '') +
      ((b.customer_phone || b.customer_mobile) ? '<div class="job-actions-split" style="margin-top:8px;"><a href="' + _waLink(b) + '" target="_blank" rel="noopener noreferrer" class="btn" style="background:#25D366;color:#fff;text-decoration:none;font-weight:600;">WhatsApp</a><a href="tel:+' + _e164(b.customer_phone || b.customer_mobile || '') + '" class="btn btn-call-outline" style="text-decoration:none;font-weight:600;">Call</a></div>' : '') +
      (cleanNotes(b.notes) ? '<div class="text-sm" style="background:var(--surface-2);padding:0.5rem;border-radius:6px;white-space:pre-wrap;">' + escHtml(cleanNotes(b.notes)).slice(0,220) + '</div>' : '') +
      '<div class="td-actions" style="display:flex;gap:0.4rem;flex-wrap:wrap;margin-top:0.3rem;">' +
      (status === 'pending'
        ? '<div class="job-actions-split" style="flex:1;"><button class="btn btn-accept" onclick="event.stopPropagation();quickAccept(\'' + id + '\',\'' + jobId + '\',this)">Accept</button><button class="btn btn-reject" onclick="event.stopPropagation();quickReject(\'' + id + '\',\'' + jobId + '\',this)">Reject</button></div>'
        : (status === 'requeued' ? '<span class="text-muted text-sm">Returned to WorkToGo queue</span>' : '<button class="btn btn-primary btn-sm" onclick="event.stopPropagation();openStatusModal(\'' + id + '\',\'' + jobId + '\')">Update</button>')) +
      '<button class="btn btn-ghost btn-sm" onclick="event.stopPropagation();viewBooking(\'' + id + '\')">View</button>' +
      '</div>';
    return '<div class="card job-' + status + '" id="card-' + id + '" style="border:1px solid var(--border);"><div class="card-body" style="display:grid;gap:0.45rem;">' +
      '<div style="display:flex;justify-content:space-between;gap:0.5rem;align-items:flex-start;' + (collapsedByDefault ? 'cursor:pointer;' : '') + '"' + (collapsedByDefault ? ' onclick="toggleCardDetail(\'' + detailId + '\',\'' + arrowId + '\')"' : '') + '>' +
      '<div><div class="fw-bold">#' + id + ' · ' + escHtml(b.service_name || b.service?.name || 'Service') + '</div><div class="text-muted text-sm job-date">' + fmtDateTime(b.booking_date || b.date || b.scheduled_at) + '</div></div>' +
      '<div style="display:flex;align-items:center;gap:6px;">' + _chip(status) + (collapsedByDefault ? '<span id="' + arrowId + '" class="text-muted" style="font-size:0.8rem;">▸</span>' : '') + '</div>' +
      '</div>' +
      '<div id="' + detailId + '" style="display:' + (collapsedByDefault ? 'none' : 'grid') + ';gap:0.45rem;">' + detail + '</div>' +
      '</div></div>';
  }).join('');
  tbody.innerHTML = list.map(b => {
    const id = b.id || b._id;
    const status = normalizeStatus(b.status);
    const jobId = b.job_id || b.id || b._id;
    const actionBtns = status === 'requeued' ? '<span class="text-muted text-sm">Returned to queue</span>' : status === 'pending'
      ? '<button class="btn btn-accept btn-sm" onclick="quickAccept(\'' + id + '\',\'' + jobId + '\',this)">Accept</button><button class="btn btn-reject btn-sm" onclick="quickReject(\'' + id + '\',\'' + jobId + '\',this)">Reject</button>'
      : '<button class="btn btn-ghost btn-sm" onclick="openStatusModal(\'' + id + '\',\'' + jobId + '\')">Update</button>';
    return '<tr id="row-' + id + '">' +
      '<td class="text-sm fw-bold" style="cursor:pointer;color:var(--accent);" onclick="viewBooking(\'' + id + '\')">#' + id + '</td>' +
      '<td>' + escHtml(b.customer_name || b.user?.name || '—') + '</td>' +
      '<td>' + escHtml(b.service_name || b.service?.name || '—') + '</td>' +
      '<td class="text-sm">' + fmtDateTime(b.booking_date || b.date || b.scheduled_at) + '</td>' +
      '<td>' + _chip(status) + '</td>' +
      '<td><div class="td-actions" style="gap:0.3rem;flex-wrap:wrap;"><button class="btn btn-ghost btn-sm" onclick="viewBooking(\'' + id + '\')">View</button>' + actionBtns + '</div></td>' +
      '</tr>';
  }).join('');
}

// ── Collapse/expand: completed & cancelled jobs start collapsed (Active/
// Pending stay expanded since the vendor needs to act on them immediately).
function toggleCardDetail(detailId, arrowId) {
  const el = document.getElementById(detailId);
  if (!el) return;
  const collapsed = el.style.display === 'none';
  el.style.display = collapsed ? 'grid' : 'none';
  const arrow = arrowId && document.getElementById(arrowId);
  if (arrow) arrow.textContent = collapsed ? '▾' : '▸';
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
    '<div><div class="text-muted text-sm">Phone / WhatsApp</div><div>' + _phoneLinks(b.customer_phone||b.customer_mobile||b.user?.phone) + '</div></div>' +
    '<div><div class="text-muted text-sm">Service</div><div>' + escHtml(b.service_name||b.service?.name||'—') + '</div></div>' +
    '<div><div class="text-muted text-sm">Scheduled</div><div>' + fmtDateTime(b.booking_date||b.date||b.scheduled_at) + '</div></div>' +
    '<div><div class="text-muted text-sm">Amount</div><div class="fw-bold">' + fmtCurrency(b.amount||b.price) + '</div></div>' +
    '<div><div class="text-muted text-sm">Payment</div><div>' + (b.payment_status||'—') + '</div></div>' +
    '</div>' +
    _addrBlock(b) +
    '<div style="margin-bottom:0.75rem;"><div class="text-muted text-sm">Customer Notes</div><div class="text-sm" style="background:var(--surface-2);padding:0.6rem;border-radius:6px;margin-top:4px;">' + escHtml(cleanNotes(b.customer_note || b.notes) || 'No notes from customer.') + '</div></div>';

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

function _setBtnLoading(btnEl, label) {
  if (!btnEl) return null;
  const original = btnEl.innerHTML;
  btnEl.disabled = true;
  btnEl.innerHTML = '<span class="spinner" style="width:12px;height:12px;border-width:2px;vertical-align:middle;margin-right:4px;"></span>' + label;
  return original;
}
function _restoreBtn(btnEl, original) {
  if (!btnEl) return;
  btnEl.disabled = false;
  btnEl.innerHTML = original;
}

function _setStatusOverride(id, status) {
  const key = String(id);
  _localStatusOverride.set(key, status);
  setTimeout(() => _localStatusOverride.delete(key), 60000);
}

async function quickAccept(id, jobId = null, btnEl = null) {
  const original = _setBtnLoading(btnEl, 'Accepting…');
  const res = await API.Bookings.accept(jobId || id);
  if (!res.ok) { _restoreBtn(btnEl, original); showToast(res.data?.message || "Failed to accept.", "error"); return; }
  showToast("Booking accepted!", "success");
  _setStatusOverride(id, "confirmed");
  const idx = allBookings.findIndex(x => (x.id || x._id) == id);
  if (idx !== -1) allBookings[idx].status = "confirmed";
  _updateAnalytics(); renderChips(); applyFilter();
}
async function quickReject(id, jobId = null, btnEl = null) {
  if (!confirmAction("Reject this booking?")) return;
  const original = _setBtnLoading(btnEl, 'Rejecting…');
  const res = await API.Bookings.reject(jobId || id);
  if (!res.ok) { _restoreBtn(btnEl, original); showToast(res.data?.message || "Failed to reject.", "error"); return; }
  showToast("Request returned to WorkToGo queue.", "info");
  _setStatusOverride(id, "cancelled");
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
  const val = String(raw || '').trim();
  if (!val) return null;
  // Suppress internal routing/metadata — vendor should never see these
  const INTERNAL_SIGNALS = [
    'routing_context',
    'Request ID:',
    'Client request ID:',
    'Request schema version:',
    'Request type:',
    'Booking mode:',
    'Booking mode label:',
    'Lifecycle state:',
    'Assignment state:',
    'Request source:',
    'Priority:',
    'Priority score:',
    'Payment required:',
    'Payment route:',
    'Operational tags:',
    'Timeline:',
    'Sorting keys:',
    'Issue note:',
    'Issue summary:',
    'Issue list:',
    'Vendor route:',
    'Selected worker:',
    'wtg-free-lead',
    'wtg-inspection',
    'wtg-direct',
  ];
  if (INTERNAL_SIGNALS.some(s => val.includes(s))) return null;
  return val;
}

// Pre-filled WhatsApp message — only shown once the job is past acceptance
// (in_progress/completed), since that's when the vendor actually needs to
// coordinate arrival time with the customer.
function _waLink(b) {
  const phone = b.customer_phone || b.customer_mobile || '';
  const status = normalizeStatus(b.status);
  if (status !== 'in_progress' && status !== 'completed') return 'https://wa.me/' + _e164(phone);
  const service = b.service_name || b.service?.name || 'Service';
  const msg = encodeURIComponent(
    'Namaste! Main WorkToGo se hoon.\n' +
    'Aapki ' + service + ' booking (#' + (b.booking_number || b.id || b._id) + ') mili hai.\n' +
    'Kab aana theek rahega?'
  );
  return 'https://wa.me/' + _e164(phone) + '?text=' + msg;
}

function _e164(phone) {
  const digits = String(phone || '').replace(/\D/g, '');
  if (!digits) return '';
  return (digits.length === 12 && digits.startsWith('91')) ? digits : '91' + digits.slice(-10);
}

function _phoneLinks(phone) {
  if (!phone) return '&mdash;';
  const digits = String(phone).replace(/\D/g, '');
  // Normalise to E.164 with 91 prefix — use last 10 digits if no country code
  const e164 = (digits.length === 12 && digits.startsWith('91'))
    ? digits
    : '91' + digits.slice(-10);
  const display = escHtml(phone);
  const wa  = 'https://wa.me/' + e164;
  const tel = 'tel:+' + e164;
  return display +
    ' <a href="' + wa + '" target="_blank" rel="noopener noreferrer" style="display:inline-flex;align-items:center;gap:3px;margin-left:8px;padding:2px 9px;border-radius:12px;font-size:0.7rem;font-weight:600;background:#25d36622;color:#25d366;border:1px solid #25d36645;text-decoration:none;">WhatsApp</a>' +
    ' <a href="' + tel + '" style="display:inline-flex;align-items:center;gap:3px;margin-left:4px;padding:2px 9px;border-radius:12px;font-size:0.7rem;font-weight:600;background:#3b82f622;color:#3b82f6;border:1px solid #3b82f645;text-decoration:none;">Call</a>';
}

function _addrBlock(b) {
  const locality = (b.customer_locality || '').trim();
  const address  = (b.customer_address  || b.address || '').trim();
  if (!locality && !address) return '';
  let html = '<div style="margin-bottom:0.75rem;"><div class="text-muted text-sm">Address</div>';
  if (locality) html += '<div class="text-sm fw-bold" style="margin-top:3px;">' + escHtml(locality) + '</div>';
  if (address)  html += '<div class="text-sm" style="margin-top:2px;">' + escHtml(address) + '</div>';
  html += '</div>';
  return html;
}
</script>
</body>
</html>
