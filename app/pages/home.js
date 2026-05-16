/**
 * WorkToGo — Home Page
 * Products → real POST /orders
 * Services → real POST /bookings
 */

export async function render(container) {
  const user = AUTH.getUser();
  const serviceOnly = Boolean(CONFIG.FEATURES?.SERVICE_ONLY_MODE);
  const isLoggedIn = AUTH.isLoggedIn();

  container.innerHTML = `
    <div class="page home-page">
      <header class="top-bar">
        <div class="top-bar-left">
          <div class="user-avatar">${_initial(user)}</div>
          <div>
            <p class="greeting">${isLoggedIn ? `Good ${_timeGreeting()}` : "Browse local services"}</p>
            <h2 class="user-name">${_esc(user?.name || "Haldwani")}</h2>
          </div>
        </div>
        <button class="icon-btn support-btn whatsapp-icon-btn" title="WhatsApp support" onclick="HomePage.openWhatsApp('header')">
          <span>☘</span>
        </button>
      </header>

      <div class="home-content">
        <section class="service-hero">
          <p class="service-hero-kicker">${_esc(CONFIG.SERVICE_ONLY?.CITY || "Haldwani")} local services</p>
          <h1>Explore trusted help near you</h1>
          <p>Browse services freely. Login is needed only when you request or track a booking.</p>
          <div class="service-trust-row">
            <span>WhatsApp support</span>
            <span>Local providers</span>
            <span>Pay after service</span>
            <span>Manual confirmation</span>
          </div>
          <div class="hero-actions">
            <button class="btn-whatsapp" onclick="HomePage.openWhatsApp('hero')">Chat on WhatsApp</button>
            <button class="btn-ghost-inline" onclick="HomePage.scrollToServices()">Explore services</button>
          </div>
        </section>

        <section class="home-section browse-strip-section">
          <div class="section-header compact">
            <h3>Popular in Haldwani</h3>
            <span class="section-note">Browse first, book when ready</span>
          </div>
          <div class="category-chips">
            ${_categoryChips().map(c => `<button onclick="HomePage.scrollToServices()"><span>${c.icon}</span>${_esc(c.label)}</button>`).join("")}
          </div>
        </section>

        <section class="local-proof-grid">
          <div><strong>Local coordination</strong><span>Team confirms request before visit</span></div>
          <div><strong>No advance payment</strong><span>Pay after service during pilot</span></div>
          <div><strong>Human help</strong><span>Ask on WhatsApp before booking</span></div>
        </section>

        <section class="home-section" id="services-section">
          <div class="section-header">
            <h3>Services near you</h3>
            <button class="see-all" onclick="HomePage.openWhatsApp('service-help')">Need help?</button>
          </div>
          <div id="services-grid" class="cards-grid horizontal-scroll">
            ${UI.skeleton(4, "card")}
          </div>
        </section>

        <section class="home-section trust-story-section">
          <div class="trust-story-card">
            <span class="trust-story-icon">💬</span>
            <div>
              <h3>Not sure what to book?</h3>
              <p>Send a WhatsApp message. WorkToGo support will guide you during the Haldwani pilot.</p>
            </div>
            <button class="btn-whatsapp compact" onclick="HomePage.openWhatsApp('trust-card')">Ask</button>
          </div>
        </section>

        <section class="home-section ${serviceOnly ? "feature-hidden" : ""}" data-feature="shopping-ui">
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
      <button class="floating-whatsapp" onclick="HomePage.openWhatsApp('floating')" aria-label="WhatsApp support">💬</button>
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
            <span class="btn-label">Request Service</span>
          </button>
        </div>
      </div>
    </div>
  `;

  window.HomePage = {
    openWhatsApp(source = "home") {
      const url = CONFIG.SERVICE_ONLY?.WHATSAPP_URL;
      if (url) {
        window.open(url + (url.includes("?") ? "%20" : "?text=") + encodeURIComponent(`Source: ${source}`), "_blank", "noopener");
        return;
      }
      UI.toast(`WhatsApp support: ${CONFIG.SERVICE_ONLY?.SUPPORT_PHONE || "WorkToGo"}`, "info", 4500);
    },
    scrollToServices() {
      document.getElementById("services-section")?.scrollIntoView({ behavior: "smooth", block: "start" });
    }
  };

  window.HomeSections = {
    async reloadServices() {
      const el = document.getElementById("services-grid");
      if (!el) return;
      el.innerHTML = UI.skeleton(4, "card");
      const res = await API.getServices();
      _renderServices(res);
    },
    async reloadProducts() {
      if (CONFIG.FEATURES?.SERVICE_ONLY_MODE || !CONFIG.FEATURES?.SHOPPING_UI) return;
      const el = document.getElementById("products-grid");
      if (!el) return;
      el.innerHTML = UI.skeleton(6, "card");
      const res = await API.getProducts();
      _renderProducts(res);
    },
  };

  _loadServices();
  if (!serviceOnly && CONFIG.FEATURES?.SHOPPING_UI) _loadProducts();
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
      <div class="modal-field">
        <label for="order-address-input">Delivery Address</label>
        <textarea id="order-address-input" class="modal-textarea" placeholder="Enter full delivery address" required></textarea>
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
    if (!AUTH.isLoggedIn()) {
      UI.toast("Please login to place an order", "error");
      close();
      ROUTER.go("login");
      return;
    }
    if (!_currentProduct) return;
    const qty = parseInt(document.getElementById("order-qty-val")?.textContent) || 1;
    const notes = document.getElementById("order-notes")?.value?.trim() || "";
    const address = document.getElementById('order-address-input').value.trim();
    if (!address) { UI.toast("Please enter delivery address", "error"); return; }

    const btn = document.getElementById("btn-confirm-order");
    if (btn) { btn.disabled = true; btn.classList.add("loading"); }

    const cartResult = await API.addToCart({
      product_id: _currentProduct.id,
      quantity: qty
    });
    if (!cartResult.ok) {
      if (btn) { btn.disabled = false; btn.classList.remove("loading"); }
      UI.toast(cartResult.error || "Failed to add to cart. Try again.", "error");
      return;
    }

    const orderResult = await API.createOrder({
      shipping_address: address,
      payment_method: 'cod',
      notes: notes || ''
    });

    if (btn) { btn.disabled = false; btn.classList.remove("loading"); }

    if (orderResult.ok) {
      close();
      UI.toast("Order placed successfully!", "success");
      setTimeout(() => ROUTER.go("orders"), 800);
    } else {
      UI.toast(orderResult.error || "Failed to place order. Try again.", "error");
    }
  }

  function openBooking(service) {
    _currentService = service;
    document.getElementById("booking-modal-title").textContent = _esc(service.name || "Book Service");
    document.getElementById("booking-modal-body").innerHTML = `
      <div class="modal-product-info">
        <div class="modal-product-placeholder">${service.icon || "🔧"}</div>
        <div>
          <p class="modal-price">${UI.formatCurrency(_servicePrice(service))}</p>
          ${service.description ? `<p class="modal-desc">${_esc(service.description)}</p>` : ""}
          <p class="service-note">Pay after service. WorkToGo will confirm your request by phone/WhatsApp if needed.</p>
        </div>
      </div>
      <div class="trust-panel booking-trust-panel">
        <span>1. Send request</span>
        <span>2. Team/provider confirms</span>
        <span>3. Pay after service</span>
      </div>
      <div class="modal-field">
        <label for="booking-date">Preferred Date &amp; Time</label>
        <input type="datetime-local" id="booking-date" class="modal-input"
          min="${_isoNow()}"
        />
      </div>
      <div class="modal-field">
        <label for="booking-area">Area / Landmark</label>
        <input type="text" id="booking-area" class="modal-input" placeholder="e.g. Mukhani, Kusumkhera, near canal road" autocomplete="address-level2" />
      </div>
      <div class="modal-field">
        <label for="booking-address">Full Address</label>
        <textarea id="booking-address" class="modal-textarea" placeholder="House number, street, nearby landmark" rows="2" autocomplete="street-address"></textarea>
      </div>
      <div class="modal-field">
        <label for="booking-notes">Notes (optional)</label>
        <textarea id="booking-notes" class="modal-textarea" placeholder="Example: fan not working, pipe leakage, call before coming…" rows="2"></textarea>
      </div>
      <p class="service-note">Need help? Use Help tab or WhatsApp support after sending request.</p>
    `;
    document.getElementById("booking-modal").classList.remove("hidden");
  }

  async function confirmBooking() {
    if (!AUTH.isLoggedIn()) {
      UI.toast("Login with mobile OTP to request this service", "info");
      closeBooking();
      ROUTER.go("login");
      return;
    }
    if (!_currentService) return;
    const dateVal = document.getElementById("booking-date")?.value;
    const area    = document.getElementById("booking-area")?.value?.trim() || "";
    const address = document.getElementById("booking-address")?.value?.trim() || "";
    const notes   = document.getElementById("booking-notes")?.value?.trim() || "";

    if (!area) { UI.toast("Please enter area or landmark", "error"); return; }
    if (!address) { UI.toast("Please enter full address", "error"); return; }

    const btn = document.getElementById("btn-confirm-booking");
    if (btn) { btn.disabled = true; btn.classList.add("loading"); }

    const res = await API.createBooking({
      service_id: _currentService.id,
      ...(dateVal ? { scheduled_at: new Date(dateVal).toISOString() } : {}),
      notes: [`Area/Landmark: ${area}`, `Address: ${address}`, notes ? `Notes: ${notes}` : ""].filter(Boolean).join("\n"),
    });

    if (btn) { btn.disabled = false; btn.classList.remove("loading"); }

    if (res.ok) {
      closeBooking();
      UI.toast("Request sent — we will confirm shortly.", "success");
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
  if (CONFIG.FEATURES?.SERVICE_ONLY_MODE || !CONFIG.FEATURES?.SHOPPING_UI) return;
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
    el.classList.remove("horizontal-scroll");
    el.classList.add("fallback-services-grid");
    el.innerHTML = _fallbackServices().map(s => `
      <div class="service-card card fallback-service-card" onclick="HomePage.openWhatsApp('${_esc(s.slug)}')">
        <div class="card-icon">${s.icon}</div>
        <h4>${_esc(s.name)}</h4>
        <p class="card-meta">${_esc(s.example)}</p>
        <p class="card-price">${_esc(s.price)}</p>
        <p class="local-service-copy">Popular in Haldwani</p>
        <span class="card-badge whatsapp-badge">WhatsApp</span>
      </div>
    `).join("") + `
      <div class="fallback-help-card">
        <h3>Need another service?</h3>
        <p>Tell us on WhatsApp. We are manually coordinating pilot requests in Haldwani.</p>
        <button class="btn-whatsapp" onclick="HomePage.openWhatsApp('empty-state')">Ask WorkToGo</button>
      </div>`;
    return;
  }
  el.classList.add("horizontal-scroll");
  el.classList.remove("fallback-services-grid");

  el.innerHTML = list.map(s => `
    <div class="service-card card" onclick="HomeModals.openBooking(${_jsonAttr(s)})">
      <div class="card-icon">${s.icon || "🔧"}</div>
      <h4>${_esc(s.name || "")}</h4>
      <p class="card-meta">${_esc(s.category || "")}</p>
      <p class="card-price">${UI.formatCurrency(_servicePrice(s))}</p>
      <p class="local-service-copy">Haldwani local</p>
      <span class="card-badge">Request</span>
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

function _servicePrice(service) {
  return service?.price ?? service?.base_price ?? service?.amount ?? service?.starting_price ?? 0;
}

function _categoryChips() {
  return [
    { icon: "⚡", label: "Electrician" },
    { icon: "🚰", label: "Plumber" },
    { icon: "❄️", label: "AC repair" },
    { icon: "🧹", label: "Cleaning" },
    { icon: "🔧", label: "Appliance" },
    { icon: "📚", label: "Tutor" },
  ];
}

function _fallbackServices() {
  return [
    { slug: "electrician", icon: "⚡", name: "Electrician", example: "Fan, switch, MCB repair", price: "From ₹199" },
    { slug: "plumber", icon: "🚰", name: "Plumber", example: "Leakage, tap, fitting", price: "From ₹199" },
    { slug: "ac-repair", icon: "❄️", name: "AC Repair", example: "Service and checkup", price: "From ₹299" },
    { slug: "cleaning", icon: "🧹", name: "Cleaning", example: "Home/shop basic cleaning", price: "From ₹399" },
    { slug: "appliance", icon: "🔧", name: "Appliance Repair", example: "Fridge, washer, RO check", price: "From ₹299" },
    { slug: "custom", icon: "💬", name: "Other local help", example: "Ask WorkToGo support", price: "WhatsApp" },
  ];
}

// Safe JSON embed for onclick attribute — encode as single-quoted JS object
function _jsonAttr(obj) {
  return "JSON.parse(decodeURIComponent('" + encodeURIComponent(JSON.stringify(obj)) + "'))";
}
