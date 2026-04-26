/**
 * WorkToGo — App.js
 * Shared UI utilities: sidebar toggle, toast, modal, rendering helpers.
 * Loaded on every page.
 */

/* ── Sidebar Toggle ─────────────────────────────── */
function initSidebar() {
  const sidebar  = document.getElementById("sidebar");
  const toggle   = document.getElementById("menuToggle");
  const closeBtn = document.getElementById("sidebarClose");
  if (!sidebar) return;

  let overlay = document.getElementById("sidebarOverlay");
  if (!overlay) {
    overlay = document.createElement("div");
    overlay.id = "sidebarOverlay";
    overlay.className = "sidebar-overlay";
    document.body.appendChild(overlay);
  }

  function open()  { sidebar.classList.add("open"); overlay.classList.add("show"); document.body.style.overflow = "hidden"; }
  function close() { sidebar.classList.remove("open"); overlay.classList.remove("show"); document.body.style.overflow = ""; }

  toggle?.addEventListener("click", open);
  closeBtn?.addEventListener("click", close);
  overlay.addEventListener("click", close);
}

/* ── Toast Notifications ────────────────────────── */
function initToasts() {
  if (!document.getElementById("toastContainer")) {
    const c = document.createElement("div");
    c.id = "toastContainer";
    c.className = "toast-container";
    document.body.appendChild(c);
  }
}

function showToast(msg, type = "info", duration = 3500) {
  const container = document.getElementById("toastContainer");
  if (!container) return;

  const icons = { success: "✓", error: "✗", info: "ℹ", warning: "⚠" };
  const t = document.createElement("div");
  t.className = `toast ${type}`;
  t.innerHTML = `
    <span class="toast-icon">${icons[type] || icons.info}</span>
    <span class="toast-msg">${msg}</span>
    <button class="toast-close" onclick="this.parentElement.remove()">×</button>
  `;
  container.appendChild(t);
  setTimeout(() => t.style.animation = "slideInRight 0.25s reverse both", duration - 250);
  setTimeout(() => t.remove(), duration);
}

/* ── Modal Helpers ──────────────────────────────── */
function openModal(id) {
  const m = document.getElementById(id);
  if (m) { m.classList.add("open"); document.body.style.overflow = "hidden"; }
}
function closeModal(id) {
  const m = document.getElementById(id);
  if (m) { m.classList.remove("open"); document.body.style.overflow = ""; }
}
// Close modal on backdrop click
document.addEventListener("click", e => {
  if (e.target.classList.contains("modal-backdrop")) {
    e.target.classList.remove("open");
    document.body.style.overflow = "";
  }
});

/* ── Badge Helper ───────────────────────────────── */
function statusBadge(status) {
  const s = (status || "unknown").toLowerCase().replace(/\s+/g, "_");
  return `<span class="badge badge-dot badge-${s}">${status}</span>`;
}

/* ── Date Format ────────────────────────────────── */
function fmtDate(iso) {
  if (!iso) return "—";
  return new Date(iso).toLocaleDateString("en-IN", { day: "2-digit", month: "short", year: "numeric" });
}
function fmtDateTime(iso) {
  if (!iso) return "—";
  return new Date(iso).toLocaleString("en-IN", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" });
}

/* ── Currency ───────────────────────────────────── */
function fmtCurrency(n) {
  if (n == null) return "—";
  return "₹" + Number(n).toLocaleString("en-IN", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/* ── Render Vendor Info in Sidebar ─────────────── */
function renderVendorInfo() {
  const user = Auth.getUser();
  if (!user) return;
  const nameEl = document.getElementById("vendorName");
  const roleEl = document.getElementById("vendorRole");
  const avatarEl = document.getElementById("vendorAvatar");
  if (nameEl) nameEl.textContent = user.name || user.business_name || "Vendor";
  if (roleEl) roleEl.textContent = (user.role || "").replace("vendor_", "").replace("_", " ");
  if (avatarEl) avatarEl.textContent = (user.name || user.business_name || "V")[0].toUpperCase();
  // Show/hide role-specific nav items
  document.querySelectorAll("[data-role]").forEach(el => {
    const required = el.getAttribute("data-role");
    el.style.display = (user.role === required) ? "" : "none";
  });
  // Role badge
  const roleBadgeEl = document.getElementById("roleBadge");
  if (roleBadgeEl) {
    const isService = user.role === CONFIG.ROLES.SERVICE;
    roleBadgeEl.innerHTML = isService
      ? `<svg viewBox="0 0 16 16" width="12" fill="currentColor"><path d="M8 1a7 7 0 100 14A7 7 0 008 1zM7 4h2v5H7V4zm0 6h2v2H7v-2z"/></svg> Service Vendor`
      : `<svg viewBox="0 0 16 16" width="12" fill="currentColor"><path d="M2 2h1l2 6h6l2-5H5"/><circle cx="6" cy="13" r="1"/><circle cx="12" cy="13" r="1"/></svg> Shopping Vendor`;
  }
}

/* ── Active Nav Link ────────────────────────────── */
function setActiveNav() {
  const page = window.location.pathname.split("/").pop() || "dashboard.html";
  document.querySelectorAll(".nav-item").forEach(a => {
    if (a.getAttribute("href") === page) a.classList.add("active");
  });
}

/* ── Confirm Dialog (simple) ────────────────────── */
function confirmAction(msg) {
  return window.confirm(msg);
}

/* ── Pagination helper ──────────────────────────── */
function paginateArray(arr, page, perPage = 10) {
  const total = arr.length;
  const pages = Math.ceil(total / perPage);
  const items = arr.slice((page - 1) * perPage, page * perPage);
  return { items, total, pages, page };
}

/* ── Local filter ───────────────────────────────── */
function filterItems(arr, query, keys) {
  if (!query.trim()) return arr;
  const q = query.toLowerCase();
  return arr.filter(item => keys.some(k => String(item[k] || "").toLowerCase().includes(q)));
}

/* ── Init on all pages ──────────────────────────── */
document.addEventListener("DOMContentLoaded", () => {
  initSidebar();
  initToasts();
  renderVendorInfo();
  setActiveNav();
});
