/**
 * WorkToGo — Home Page
 * Products → real POST /orders
 * Services → real POST /bookings
 */

export async function render(container) {
  if (!AUTH.requireAuth()) return;
  const user = AUTH.getUser();

  container.innerHTML = `
    <div class="page home-page">
      <header class="top-bar">
        <div class="top-bar-left">
          <div class="user-avatar">${_initial(user)}</div>
          <div>
            <p class="greeting">Good ${_timeGreeting()}</p>
            <h2 class="user-name">${_esc(user?.name || "Guest")}</h2>
          </div>
        </div>
        <button class="icon-btn notif-btn" title="Notifications" onclick="UI.toast('Notifications coming soon!', 'info') ">
          <svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 1 0 6 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        </button>
      </header>

      <div class="home-content">
        <section class="home-section">
          <div class="section-header">
            <h3>Services</h3>
            <button class="see-all" onclick="ROUTER.go('bookings')">See all</button>
          </div>
          <div id="services-grid" class="cards-grid horizontal-scroll">
            ${UI.skeleton(4, "card")}
          </div>
        </section>

        <section class="home-section">
          <div class="section-header">
            <h3>Products</h3>
            <button class="see-all" onclick="ROUTER.go('orders')">See all</button>
          </div>
          <div id="products-grid" class="cards-grid">
            ${UI.skeleton(6, "card")}
          </div>
        </section>
      </div>

      ${UI.buildNav("home")}
    </div>

    <!-- Order Modal -->
    <div id="order-modal" class="modal-overlay hidden" onclick="HomeModals.closeOnOverlay(event)">
      <div class="modal-sheet">
        <div class="modal-handle"></div>
        <h3 id="order-modal-title">Place Order</h3>
        <div id="order-modal-body"></div>
        <div class="modal-actions">
          <button class="btn-secondary" onclick="HomeModals.close()">Cancel</button>
          <button class="btn-primary" id="btn-confirm-order" onclick="HomeModals.confirmOrder()">
            <span class="btn-label">Confirm Order</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Booking Modal -->
    <div id="booking-modal" class="modal-overlay hidden" onclick="HomeModals.closeOnOverlay(event)">
      <div class="modal-sheet">
        <div class="modal-handle"></div>
        <h3 id="booking-modal-title">Book Service</h3>
        <div id="booking-modal-body"></div>
        <div class="modal-actions">
          <button class="btn-secondary" onclick="HomeModals.closeBooking()">Cancel</button>
          <button class="btn-primary" id="btn-confirm-booking" onclick="HomeModals.confirmBooking()">
            <span class="btn-label">Confirm Booking</span>
          </button>
        </div>
      </div>
    </div>
  `;

  window.HomeSections = {
    async reloadServices() {
      const el = document.getElementById("services-grid");
      if (!el) return;
      el.innerHTML = UI.skeleton(4, "card");
      const res = await API.getServices();
      _renderServices(res);
    },
    async reloadProducts() {
      const el = document.getElementById("products-grid");
      if (!el) return;
      el.innerHTML = UI.skeleton(6, "card");
      const res = await API.getProducts();
      _renderProducts(res);
    },
  };

  _loadServices();
  _loadProducts();
}

// ── Modal Controller ────────────────────────────────────────────────────────

window.HomeModals = (() => {
  let _currentProduct = null;
  let _currentService = null;

  function openOrder(product) {
    _currentProduct = product;
    document.getElementById("order-modal-title").textContent = _esc(product.name || "Place Order");
    document.getElementById("order-modal-body").innerHTML = `
      <div class="modal-product-info">
        ${product.image
          ? `<img src="${product.image}" alt="${_esc(product.name)}" class="modal-product-img"/>`
          : `<div class="modal-product-placeholder">📦</div>`}
        <div>
          <p class="modal-price">${UI.formatCurrency(product.price || 0)}</p>
          ${product.description ? `<p class="modal-desc">${_esc(product.description)}</p>` : ""}
        </div>
      </div>
      <div class="modal-field">
        <label for="order-qty">Quantity</label>
        <div class="qty-control">
          <button class="qty-btn" onclick="HomeModals.changeQty(-1)">−</button>
          <span id="order-qty-val">1</span>
          <button class="qty-btn" onclick="HomeModals.changeQty(1)">+</button>
        </div>
      </div>
      <div class="modal-field">
        <label for="order-notes">Notes (optional)</label>
        <textarea id="order-notes" class="modal-textarea" placeholder="Any special instructions…" rows="2"></textarea>
      </div>
    `;
    document.getElementById("order-modal").classList.remove("hidden");
  }

  function changeQty(delta) {
    const el = document.getElementById("order-qty-val");
    if (!el) return;
    const cur = parseInt(el.textContent) || 1;
    el.textContent = Math.max(1, cur + delta);
  }

  async function confirmOrder() {
    if (!_currentProduct) return;
    const qty   = parseInt(document.getElementById("order-qty-val")?.textContent) || 1;
    const notes = document.getElementById("order-notes")?.value?.trim() || "";

    const btn = document.getElementById("btn-confirm-order");
    if (btn) { btn.disabled = true; btn.classList.add("loading"); }

    const res = await API.createOrder({
      product_id: _currentProduct.id,
      quantity:   qty,
      ...(notes ? { notes } : {}),
    });

    if (btn) { btn.disabled = false; btn.classList.remove("loading"); }

    if (res.ok) {
      close();
      UI.toast("Order placed successfully!", "success");
      setTimeout(() => ROUTER.go("orders"), 800);
    } else {
      UI.toast(res.error || "Failed to place order. Try again.", "error");
    }
  }

  function openBooking(service) {
    _currentService = service;
    document.getElementById("booking-modal-title").textContent = _esc(service.name || "Book Service");
    document.getElementById("booking-modal-body").innerHTML = `
      <div class="modal-product-info">
        <div class="modal-product-placeholder">${service.icon || "🔧"}</div>
        <div>
          <p class="modal-price">${UI.formatCurrency(service.price || 0)}</p>
          ${service.description ? `<p class="modal-desc">${_esc(service.description)}</p>` : ""}
        </div>
      </div>
      <div class="modal-field">
        <label for="booking-date">Preferred Date &amp; Time</label>
        <input type="datetime-local" id="booking-date" class="modal-input"
          min="${_isoNow()}"
        />
      </div>
      <div class="modal-field">
        <label for="booking-notes">Notes (optional)</label>
        <textarea id="booking-notes" class="modal-textarea" placeholder="Address or any special instructions…" rows="2"></textarea>
      </div>
    `;
    document.getElementById("booking-modal").classList.remove("hidden");
  }

  async function confirmBooking() {
    if (!_currentService) return;
    const dateVal = document.getElementById("booking-date")?.value;
    const notes   = document.getElementById("booking-notes")?.value?.trim() || "";

    const btn = document.getElementById("btn-confirm-booking");
    if (btn) { btn.disabled = true; btn.classList.add("loading"); }

    const res = await API.createBooking({
      service_id: _currentService.id,
      ...(dateVal ? { scheduled_at: new Date(dateVal).toISOString() } : {}),
      ...(notes   ? { notes } : {}),
    });

    if (btn) { btn.disabled = false; btn.classList.remove("loading"); }

    if (res.ok) {
      closeBooking();
      UI.toast("Booking confirmed!", "success");
      setTimeout(() => ROUTER.go("bookings"), 800);
    } else {
      UI.toast(res.error || "Failed to book service. Try again.", "error");
    }
  }

  function close() {
    document.getElementById("order-modal")?.classList.add("hidden");
    _currentProduct = null;
  }

  function closeBooking() {
    document.getElementById("booking-modal")?.classList.add("hidden");
    _currentService = null;
  }

  function closeOnOverlay(e) {
    if (e.target === e.currentTarget) close();
  }

  function _isoNow() {
    return new Date().toISOString().slice(0, 16);
  }

  return { openOrder, changeQty, confirmOrder, openBooking, confirmBooking, close, closeBooking, closeOnOverlay };
})();

// ── Loaders ─────────────────────────────────────────────────────────────────

async function _loadServices() {
  const res = await API.getServices();
  _renderServices(res);
}

async function _loadProducts() {
  const res = await API.getProducts();
  _renderProducts(res);
}

// ── Renderers ────────────────────────────────────────────────────────────────

function _renderServices(res) {
  const el = document.getElementById("services-grid");
  if (!el) return;

  if (!res.ok) {
    el.innerHTML = UI.errorState(res.error || "Couldn't load services.", "HomeSections.reloadServices");
    return;
  }

  const list = Array.isArray(res.data) ? res.data : (res.data?.services || res.data?.data || []);

  if (!list.length) {
    el.innerHTML = UI.emptyState("🛠️", "No services yet", "Check back soon");
    return;
  }

  el.innerHTML = list.map(s => `
    <div class="service-card card" onclick="HomeModals.openBooking(${_jsonAttr(s)})">
      <div class="card-icon">${s.icon || "🔧"}</div>
      <h4>${_esc(s.name || "")}</h4>
      <p class="card-meta">${_esc(s.category || "")}</p>
      <p class="card-price">${UI.formatCurrency(s.price || 0)}</p>
      <span class="card-badge">Book</span>
    </div>
  `).join("");
}

function _renderProducts(res) {
  const el = document.getElementById("products-grid");
  if (!el) return;

  if (!res.ok) {
    el.innerHTML = UI.errorState(res.error || "Couldn't load products.", "HomeSections.reloadProducts");
    return;
  }

  const list = Array.isArray(res.data) ? res.data : (res.data?.products || res.data?.data || []);

  if (!list.length) {
    el.innerHTML = UI.emptyState("📦", "No products yet", "Check back soon");
    return;
  }

  el.innerHTML = list.map(p => `
    <div class="product-card card" onclick="HomeModals.openOrder(${_jsonAttr(p)})">
      <div class="card-img-wrap">
        ${p.image
          ? `<img src="${p.image}" alt="${_esc(p.name || "")}" loading="lazy"/>`
          : `<div class="card-img-placeholder">📦</div>`}
      </div>
      <div class="card-body">
        <h4>${_esc(p.name || "")}</h4>
        <p class="card-meta">${_esc(p.category || "")}</p>
        <p class="card-price">${UI.formatCurrency(p.price || 0)}</p>
        <span class="card-badge order-badge">Order</span>
      </div>
    </div>
  `).join("");
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function _initial(user) {
  return (user?.name || "U").charAt(0).toUpperCase();
}

function _timeGreeting() {
  const h = new Date().getHours();
  if (h < 12) return "morning";
  if (h < 17) return "afternoon";
  return "evening";
}

function _esc(str) {
  return String(str)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

// Safe JSON embed for onclick attribute — encode as single-quoted JS object
function _jsonAttr(obj) {
  return "JSON.parse(decodeURIComponent('" + encodeURIComponent(JSON.stringify(obj)) + "'))";
}
