/**
 * WorkToGo — Orders Page
 * Loads from API. Auto-refresh every 12s. Status matches backend values.
 */

export async function render(container) {
  if (!AUTH.requireAuth()) return;

  container.innerHTML = `
    <div class="page orders-page">
      <header class="page-header">
        <button class="btn-back-nav" onclick="ROUTER.go('home')" aria-label="Back to home">
          <svg viewBox="0 0 24 24"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
        </button>
        <h2>My Orders</h2>
        <span class="refresh-dot" title="Auto-refreshing"></span>
      </header>

      <div class="tab-bar">
        <button class="tab active" onclick="OrdersPage.setFilter('all',    this)">All</button>
        <button class="tab"        onclick="OrdersPage.setFilter('active', this)">Active</button>
        <button class="tab"        onclick="OrdersPage.setFilter('done',   this)">Completed</button>
      </div>

      <div id="orders-list" class="list-container">
        ${UI.skeleton(4, "row")}
      </div>

      ${UI.buildNav("orders")}
    </div>
  `;

  await OrdersPage._load();
}

export async function refresh() {
  await OrdersPage._load(true);
}

window.OrdersPage = (() => {
  let _all    = [];
  let _filter = "all";

  // Active statuses — matches backend: pending, accepted, in_progress
  const ACTIVE_STATUSES   = ["pending", "accepted", "in_progress"];
  // Done statuses
  const DONE_STATUSES     = ["completed", "cancelled"];

  async function _load(silent = false) {
    if (!silent) {
      const el = document.getElementById("orders-list");
      if (el) el.innerHTML = UI.skeleton(4, "row");
    }

    const res = await API.getOrders();

    if (!res.ok) {
      if (!silent) {
        const el = document.getElementById("orders-list");
        if (el) el.innerHTML = UI.errorState(res.error || "Failed to load orders.", "OrdersPage._load");
      }
      return;
    }

    _all = Array.isArray(res.data) ? res.data : (res.data?.orders || res.data?.data || []);
    _render();
    if (silent) UI.pulseRefreshDot();
  }

  function _render() {
    const el = document.getElementById("orders-list");
    if (!el) return;

    let list = _all;
    if (_filter === "active") {
      list = _all.filter(o => ACTIVE_STATUSES.includes((o.status || "").toLowerCase()));
    } else if (_filter === "done") {
      list = _all.filter(o => DONE_STATUSES.includes((o.status || "").toLowerCase()));
    }

    if (!list.length) {
      el.innerHTML = UI.emptyState(
        "📦",
        _filter === "all" ? "No orders yet" : "Nothing here",
        _filter === "all" ? "Browse products on Home" : "No orders in this category"
      );
      return;
    }

    el.innerHTML = list.map(o => `
      <div class="list-item order-item">
        <div class="item-icon order-icon">${_statusIcon(o.status)}</div>
        <div class="item-body">
          <div class="item-row">
            <span class="item-title">${_esc(o.product_name || o.name || "Order")}</span>
            ${UI.statusBadge(o.status || "pending")}
          </div>
          <div class="item-row muted">
            <span>${UI.formatDate(o.created_at)}</span>
            <span class="item-amount">${UI.formatCurrency(o.amount || o.total || 0)}</span>
          </div>
          ${o.quantity ? `<div class="item-row muted small"><span>Qty: ${_esc(String(o.quantity))}</span></div>` : ""}
          <div class="item-row muted small">
            <span>ID: ${_esc(String(o.id || "—"))}</span>
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

  function _statusIcon(status) {
    const map = {
      completed:   "✅",
      in_progress: "🚚",
      accepted:    "✔️",
      pending:     "⏳",
      cancelled:   "❌",
    };
    return map[(status || "").toLowerCase()] || "📦";
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
