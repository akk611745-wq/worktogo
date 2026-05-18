/**
 * WorkToGo — Home Page
 * Products → real POST /orders
 * Services → real POST /bookings
 */

export async function render(container) {
  const user = AUTH.getUser();
  const serviceOnly = Boolean(CONFIG.FEATURES?.SERVICE_ONLY_MODE);
  const isLoggedIn = AUTH.isLoggedIn();
  await _loadPilotConfig();

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
        <button class="support-entry" title="WhatsApp support" onclick="UI.openSupport('selector', { category: HomePage.activeCategoryLabel?.() })"><span>WhatsApp help</span></button>
      </header>

      <div class="home-content">
        <section class="service-hero">
          <div class="service-hero-copy">
            <p class="service-hero-kicker" id="category-kicker">${_esc(_pilotConfig.city)} local services</p>
            <h1 id="category-hero-title">${_esc(_pilotConfig.hero_title)}</h1>
            <p id="category-hero-subtitle">${_esc(_pilotConfig.hero_subtitle)}</p>
            <div class="hero-actions">
              <button class="btn-primary hero-primary" onclick="HomePage.scrollToServices()">Book a local service</button>
              <button class="btn-ghost-inline" onclick="HomePage.setCategory('inspection')">Premium inspection</button>
            </div>
          </div>
          <div class="service-hero-market">
            <div class="hero-market-card primary"><strong>Verified local help</strong><span>Request now · team confirms visit</span></div>
            <div class="hero-market-card"><strong>Pay after service</strong><span>Clear pilot flow for normal jobs</span></div>
            <div class="hero-market-card"><strong>Site inspection</strong><span>For complex work and estimates</span></div>
          </div>
        </section>

        <section class="market-search-section">
          <label for="service-search">Find local service</label>
          <div class="market-search-box">
            <span>⌕</span>
            <input id="service-search" type="search" placeholder="Search electrician, painting, cleaning…" autocomplete="off" oninput="HomePage.searchServices(this.value)" />
            <button onclick="HomePage.clearSearch()" aria-label="Clear search">×</button>
          </div>
          <p id="search-hint" class="section-note">Search works with selected category for faster discovery.</p>
        </section>

        <section class="home-section browse-strip-section">
          <div class="section-header compact">
            <div>
              <p class="section-eyebrow">Browse by need</p>
              <h3>Service categories</h3>
            </div>
            <button class="see-all" onclick="HomePage.toggleMoreCategories()" id="more-categories-btn">More</button>
          </div>
          <div class="category-chips" id="category-chips">
            <button class="active" onclick="HomePage.setCategory('')"><span>🧰</span>All</button>
            ${_categoryChips().slice(0, 7).map(c => `<button onclick="HomePage.setCategory('${_esc(c.slug || '')}')"><span>${c.icon}</span>${_esc(c.label)}</button>`).join("")}
          </div>
        </section>

        <section class="premium-inspection-highlight" onclick="HomePage.setCategory('inspection')">
          <div class="premium-inspection-icon">🛡️</div>
          <div>
            <p class="service-hero-kicker">Premium inspection</p>
            <h3>For painting, waterproofing, AC and complex jobs</h3>
            <p>Choose inspection when you need scope clarity, site check, or an estimate before work starts.</p>
          </div>
          <span>From ₹99</span>
        </section>

        <section class="category-ecosystem" id="category-ecosystem">
          ${_categoryEcosystemHTML("")}
        </section>

        <section class="local-proof-grid">
          <div><strong>Local coordination</strong><span>Team confirms request before visit</span></div>
          <div><strong>No advance payment</strong><span>Pay after service during pilot</span></div>
          <div><strong>Human help</strong><span>Support available when needed</span></div>
        </section>

        <section class="home-section" id="services-section">
          <div class="section-header">
            <h3>${_esc(_pilotConfig.featured_services_label)}</h3>
            <button class="see-all" onclick="HomePage.setCategory('')">All</button>
          </div>
          <div id="services-grid" class="cards-grid horizontal-scroll">
            ${UI.skeleton(4, "card")}
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
    scrollToServices() {
      document.getElementById("services-section")?.scrollIntoView({ behavior: "smooth", block: "start" });
    },
    setCategory(slug = "") {
      _activeCategory = slug;
      _searchQuery = document.getElementById("service-search")?.value?.trim() || "";
      _renderCategoryChips();
      _renderCategoryEcosystem();
      _renderHeroForCategory();
      _renderServices({ ok: true, data: { services: _allServices } });
      HomePage.scrollToServices();
    },
    async searchServices(query = "") {
      _searchQuery = query.trim().toLowerCase();
      clearTimeout(_searchTimer);
      _searchTimer = setTimeout(async () => {
        if (_searchQuery.length < 2) {
          _searchRemoteServices = [];
          _renderServices({ ok: true, data: { services: _allServices } });
          return;
        }
        const res = await API.search(_searchQuery, "services", 20).catch(() => null);
        if (res?.ok) {
          const payload = _unwrapData(res.data);
          _searchRemoteServices = (payload?.results?.services || []).map(_normalizeSearchService);
          _renderServices({ ok: true, data: { services: _allServices } });
        }
      }, 260);
      _renderServices({ ok: true, data: { services: _allServices } });
      const hint = document.getElementById("search-hint");
      if (hint) hint.textContent = _searchQuery ? `Showing matches for “${_esc(_searchQuery)}”` : "Search works with selected category for faster discovery.";
    },
    clearSearch() {
      const inp = document.getElementById("service-search");
      if (inp) inp.value = "";
      _searchQuery = "";
      _searchRemoteServices = [];
      _renderServices({ ok: true, data: { services: _allServices } });
    },
    toggleMoreCategories() {
      _showAllCategories = !_showAllCategories;
      _renderCategoryChips();
    },
    activeCategoryLabel() {
      return _categoryMeta(_activeCategory).label;
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
    const category = _categoryMeta(service.category_slug || service.category || _activeCategory);
    document.getElementById("booking-modal-title").textContent = _esc(service.name || "Book Service");
    document.getElementById("booking-modal-body").innerHTML = `
      <div class="modal-product-info">
        <div class="modal-product-placeholder">${service.icon || "🔧"}</div>
        <div>
          <p class="modal-price">${UI.formatCurrency(_servicePrice(service))}</p>
          ${service.description ? `<p class="modal-desc">${_esc(service.description)}</p>` : ""}
          <p class="service-note">${_esc(category.label)} request · Pay after service. WorkToGo confirms before visit.</p>
        </div>
      </div>
      ${_premiumInspectionPanel(category)}
      <div class="trust-panel booking-trust-panel">
        <span>1. Send request</span>
        <span>2. WorkToGo/provider confirms time and scope</span>
        <span>3. Provider visits · Pay after service unless inspection is selected</span>
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
      <p class="service-note">After submission you can track status in Bookings. Keep your phone available for confirmation.</p>
    `;
    document.getElementById("booking-modal").classList.remove("hidden");
  }

  async function confirmBooking() {
    if (!AUTH.isLoggedIn()) {
      UI.toast("Login with mobile OTP to request this service", "info");
      _savePendingBookingIntent(_currentService);
      closeBooking();
      ROUTER.go("login");
      return;
    }
    if (!_currentService) return;
    const dateVal = document.getElementById("booking-date")?.value;
    const area    = document.getElementById("booking-area")?.value?.trim() || "";
    const address = document.getElementById("booking-address")?.value?.trim() || "";
    const notes   = document.getElementById("booking-notes")?.value?.trim() || "";

    if (!dateVal) { UI.toast("Please choose preferred date and time", "error"); return; }
    if (Number.isNaN(new Date(dateVal).getTime()) || new Date(dateVal).getTime() <= Date.now()) {
      UI.toast("Please choose a future date and time", "error");
      return;
    }
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
    if (e.target !== e.currentTarget) return;
    if (e.currentTarget?.id === "booking-modal") closeBooking();
    else close();
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
  _resumePendingBooking();
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
    const safeMessage = _friendlyServiceError(res.error);
    el.classList.remove("horizontal-scroll");
    el.classList.add("fallback-services-grid");
    el.innerHTML = _fallbackServices().slice(0, 4).map(s => `
      <div class="service-card card fallback-service-card" onclick="UI.openSupport('selector', { category: ${_jsString(s.name)}, service: ${_jsString(s.example)} })">
        <div class="card-icon">${s.icon}</div>
        <h4>${_esc(s.name)}</h4>
        <p class="card-meta">${_esc(s.example)}</p>
        <p class="card-price">${_esc(s.price)}</p>
        <p class="local-service-copy">${_esc(_pilotConfig.city)} assisted booking</p>
        <span class="card-badge">Help</span>
      </div>
    `).join("") + `
      <div class="fallback-help-card service-recovery-card">
        <h3>Services are temporarily slow to load</h3>
        <p>${_esc(safeMessage)} You can still request help and WorkToGo will guide the booking.</p>
        <div class="recovery-actions">
          <button class="btn-ghost-inline" onclick="HomeSections.reloadServices()">Retry</button>
          <button class="btn-ghost-inline" onclick="UI.openSupport('selector', { category: HomePage.activeCategoryLabel?.() })">WhatsApp support</button>
        </div>
      </div>`;
    return;
  }

  const payload = _unwrapData(res.data);
  let list = Array.isArray(payload) ? payload : (payload?.services || payload?.data || []);
  if (payload?.pilot_config) _pilotConfig = { ..._pilotConfig, ...payload.pilot_config };
  if (Array.isArray(payload?.categories) && payload.categories.length) {
    _serviceCategories = payload.categories.map(c => ({ slug: c.slug, label: c.name, icon: c.icon || "🔧" }));
    _renderCategoryChips();
  }
  if (list.length) _allServices = list;
  if (_searchQuery && _searchRemoteServices.length) list = _searchRemoteServices;
    if (_activeCategory) list = list.filter(s => _matchesCategory(s, _activeCategory));
    if (_searchQuery) list = list.filter(s => _searchText(s).includes(_searchQuery));

    if (!list.length) {
      el.classList.remove("horizontal-scroll");
      el.classList.add("fallback-services-grid");
      const fallbackList = _activeCategory ? _categoryFallbackServices(_activeCategory) : _fallbackServices();
      el.innerHTML = fallbackList.map(s => `
      <div class="service-card card fallback-service-card" onclick="UI.openSupport('selector', { category: ${_jsString(_categoryMeta(_activeCategory || s.slug).label)}, service: ${_jsString(s.name)} })">
        <div class="card-icon">${s.icon}</div>
        <h4>${_esc(s.name)}</h4>
        <p class="card-meta">${_esc(s.example)}</p>
        <p class="card-price">${_esc(s.price)}</p>
        <p class="local-service-copy">${_esc(_pilotConfig.city)} request assistance</p>
        <span class="card-badge">Help</span>
      </div>
    `).join("") + `
      <div class="fallback-help-card">
        <h3>${_esc(_activeCategory ? `${_categoryMeta(_activeCategory).label} help` : _pilotConfig.fallback_title)}</h3>
        <p>${_esc(_searchQuery ? "No exact match found yet. WorkToGo can still help route your request." : _pilotConfig.fallback_text)}</p>
        <button class="btn-ghost-inline" onclick="UI.openSupport('selector', { category: HomePage.activeCategoryLabel?.() })">Get guided help</button>
      </div>`;
      return;
  }
  el.classList.add("horizontal-scroll");
  el.classList.remove("fallback-services-grid");

  el.innerHTML = list.map(s => `
    <div class="service-card card" onclick="HomeModals.openBooking(${_jsonAttr(s)})">
      <div class="service-card-top">
        <div class="card-icon">${s.icon || "🔧"}</div>
        <span class="card-badge">Request</span>
      </div>
      <h4>${_esc(s.name || "")}</h4>
      <p class="card-meta">${_esc(s.category_name || s.category || "Local service")}</p>
      <div class="service-card-foot">
        <p class="card-price">${UI.formatCurrency(_servicePrice(s))}</p>
        <p class="local-service-copy">${_esc(_pilotConfig.city)} · confirmed before visit</p>
      </div>
    </div>
  `).join("");
}

async function _loadPilotConfig() {
  if (_pilotLoaded) return;
  _pilotLoaded = true;
  const res = await API.getPublicSettings().catch(() => null);
  const data = res?.ok ? _unwrapData(res.data) : null;
  if (data?.pilot_public_config) _pilotConfig = { ..._pilotConfig, ...data.pilot_public_config };
}

function _renderCategoryChips() {
  const el = document.getElementById("category-chips");
  if (!el) return;
  const categories = _categoryChips();
  const visible = _showAllCategories ? categories : categories.slice(0, 7);
  const moreBtn = document.getElementById("more-categories-btn");
  if (moreBtn) moreBtn.textContent = _showAllCategories ? "Less" : "More";
  el.innerHTML = `<button class="${_activeCategory ? '' : 'active'}" onclick="HomePage.setCategory('')"><span>🧰</span>All</button>` +
    visible.map(c => `<button class="${_activeCategory === c.slug ? 'active' : ''}" onclick="HomePage.setCategory('${_esc(c.slug || '')}')"><span>${c.icon}</span>${_esc(c.label)}</button>`).join("");
}

function _renderCategoryEcosystem() {
  const el = document.getElementById("category-ecosystem");
  if (el) el.innerHTML = _categoryEcosystemHTML(_activeCategory);
}

function _renderHeroForCategory() {
  const meta = _categoryMeta(_activeCategory);
  const title = document.getElementById("category-hero-title");
  const subtitle = document.getElementById("category-hero-subtitle");
  const kicker = document.getElementById("category-kicker");
  if (title) title.textContent = meta.hero || _pilotConfig.hero_title;
  if (subtitle) subtitle.textContent = meta.subtitle || _pilotConfig.hero_subtitle;
  if (kicker) kicker.textContent = _activeCategory ? `${_pilotConfig.city} ${meta.label} ecosystem` : `${_pilotConfig.city} local services`;
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

function _unwrapData(data) {
  return data?.data && typeof data.data === "object" && !Array.isArray(data.data) ? data.data : data;
}

function _servicePrice(service) {
  return service?.price ?? service?.base_price ?? service?.amount ?? service?.starting_price ?? 0;
}

function _categoryChips() {
  const dynamic = _serviceCategories.length ? _serviceCategories : [];
  const base = [
    { slug: "electrician", icon: "⚡", label: "Electrician" },
    { slug: "plumber", icon: "🚰", label: "Plumber" },
    { slug: "painting", icon: "🎨", label: "Painting" },
    { slug: "waterproofing", icon: "💧", label: "Waterproofing" },
    { slug: "ac-repair", icon: "❄️", label: "AC repair" },
    { slug: "cleaning", icon: "🧹", label: "Cleaning" },
    { slug: "appliance", icon: "🔧", label: "Appliance" },
    { slug: "tutor", icon: "📚", label: "Tutor" },
    { slug: "inspection", icon: "🛡️", label: "Inspection" },
  ];
  return _mergeCategories(dynamic, base);
}

function _mergeCategories(dynamic, base) {
  const map = new Map();
  [...dynamic, ...base].forEach(c => {
    const slug = _slug(c.slug || c.label || c.name);
    if (!slug || map.has(slug)) return;
    const meta = CATEGORY_META[slug] || {};
    map.set(slug, { slug, icon: c.icon || meta.icon || "🔧", label: c.label || c.name || meta.label || slug });
  });
  return [...map.values()];
}

function _categoryMeta(slug = "") {
  const key = _slug(slug);
  if (!key) return CATEGORY_META.all;
  return CATEGORY_META[key] || { slug: key, icon: "🔧", label: _title(key), hero: `${_title(key)} services in ${_pilotConfig.city}`, subtitle: "Choose a local service and WorkToGo will confirm before visit.", examples: ["Inspection", "Repair", "Installation"], trust: "Verified local support", vendors: "Local provider assignment", inspection: true };
}

function _categoryEcosystemHTML(slug = "") {
  const meta = _categoryMeta(slug);
  return `
    <div class="ecosystem-card">
      <div class="ecosystem-banner">
        <span class="ecosystem-icon">${meta.icon}</span>
        <div>
          <p class="service-hero-kicker">${_esc(meta.label)} marketplace</p>
          <h3>${_esc(meta.ecosystemTitle || `${meta.label} in ${_pilotConfig.city}`)}</h3>
          <p>${_esc(meta.trust || "Verified local providers · pay after service · human confirmation")}</p>
        </div>
      </div>
      <div class="ecosystem-grid">
        ${(meta.examples || []).slice(0, 4).map(x => `<div><strong>${_esc(x)}</strong><span>Local availability</span></div>`).join("")}
      </div>
      <div class="ecosystem-proof-row">
        <span>${_esc(meta.vendors || "Provider assigned after confirmation")}</span>
        <span>${meta.inspection ? "Premium inspection available" : "Simple request flow"}</span>
      </div>
    </div>`;
}

function _matchesCategory(service, slug) {
  const wanted = _slug(slug);
  const values = [service.category_slug, service.category, service.category_name, service.name].map(_slug);
  return values.some(v => v === wanted || v.includes(wanted) || wanted.includes(v));
}

function _searchText(service) {
  return [service.name, service.description, service.category, service.category_name, service.short_desc].filter(Boolean).join(" ").toLowerCase();
}

function _normalizeSearchService(s) {
  return {
    ...s,
    id: s.id,
    name: s.name || "Service",
    description: s.short_desc || s.description || "",
    base_price: s.base_price || s.price || 0,
    category_name: s.category_name || s.result_type || "Service",
    category_slug: s.category_slug || _slug(s.category_name || s.result_type || ""),
    icon: s.icon || _categoryMeta(s.category_slug || s.category_name).icon,
  };
}

function _premiumInspectionPanel(category) {
  if (!category.inspection) return "";
  return `<div class="premium-inspection-panel">
    <div class="premium-inspection-mark">🛡️</div>
    <div><strong>Premium inspection available</strong><p>Best for ${_esc(category.label.toLowerCase())} jobs where scope, estimate, or site condition must be checked first.</p></div>
    <span>From ₹99</span>
  </div>`;
}

function _friendlyServiceError(error = "") {
  const msg = String(error || "").toLowerCase();
  if (msg.includes("internal server") || msg.includes("500")) return "Live service data is being refreshed.";
  if (msg.includes("network") || msg.includes("failed")) return "Network connection looks unstable.";
  return "We could not refresh live services right now.";
}

function _savePendingBookingIntent(service) {
  try { sessionStorage.setItem("wtg_pending_booking", JSON.stringify({ service, category: _activeCategory, ts: Date.now() })); } catch {}
}

function _resumePendingBooking() {
  if (!AUTH.isLoggedIn()) return;
  try {
    const raw = sessionStorage.getItem("wtg_pending_booking");
    if (!raw) return;
    const pending = JSON.parse(raw);
    sessionStorage.removeItem("wtg_pending_booking");
    if (!pending?.service || Date.now() - Number(pending.ts || 0) > 30 * 60 * 1000) return;
    if (pending.category) {
      _activeCategory = pending.category;
      _renderCategoryChips();
      _renderCategoryEcosystem();
      _renderHeroForCategory();
    }
    setTimeout(() => HomeModals.openBooking(pending.service), 350);
  } catch {}
}

function _categoryFallbackServices(slug) {
  const meta = _categoryMeta(slug);
  return (meta.examples || []).slice(0, 4).map((name, i) => ({ slug, icon: meta.icon, name, example: `${meta.label} local request`, price: i === 0 && meta.inspection ? "Inspection from ₹99" : "Request quote" }));
}

function _slug(v = "") { return String(v || "").toLowerCase().trim().replace(/&/g, "and").replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, ""); }
function _title(v = "") { return String(v || "service").replace(/-/g, " ").replace(/\b\w/g, m => m.toUpperCase()); }
function _jsString(v = "") { return JSON.stringify(String(v || "")); }

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

let _pilotLoaded = false;
let _activeCategory = "";
let _searchQuery = "";
let _searchTimer = null;
let _searchRemoteServices = [];
let _showAllCategories = false;
let _allServices = [];
let _serviceCategories = [];
let _pilotConfig = {
  city: CONFIG.SERVICE_ONLY?.CITY || "Haldwani",
  hero_title: "Book trusted local services in Haldwani",
  hero_subtitle: "Browse local services first. Login only when you request or track a booking.",
  trust_badges: ["Local providers", "Pay after service", "Manual confirmation"],
  support_label: "Need help?",
  support_phone: CONFIG.SERVICE_ONLY?.SUPPORT_PHONE || "",
  whatsapp_url: CONFIG.SERVICE_ONLY?.WHATSAPP_URL || "",
  featured_services_label: "Services near you",
  fallback_title: "Need another service?",
  fallback_text: "Tell us on WhatsApp. We are manually coordinating pilot requests.",
  manual_fallback_label: "Manual assistance",
};

const CATEGORY_META = {
  all: { slug: "", icon: "🧰", label: "All services", hero: "Book trusted local services in Haldwani", subtitle: "Search, choose a category, and request verified local help. Login only when you book.", ecosystemTitle: "Local service marketplace", examples: ["Electrician", "Plumber", "Painting", "Cleaning"], trust: "Local coordination · pay after service · human confirmation", vendors: "Verified local provider network", inspection: true },
  electrician: { icon: "⚡", label: "Electrician", hero: "Electrician services in Haldwani", subtitle: "Fan, switch, MCB, wiring, installation and urgent electrical help with confirmation before visit.", examples: ["Fan repair", "Switch board", "MCB issue", "Light installation"], trust: "Safety-first local electricians · pay after service", vendors: "Electrician provider assignment", inspection: false },
  plumber: { icon: "🚰", label: "Plumbing", hero: "Plumbing services near you", subtitle: "Leakage, taps, fittings, bathroom and kitchen plumbing coordinated locally.", examples: ["Leakage repair", "Tap fitting", "Pipe blockage", "Bathroom fitting"], trust: "Local plumbers · clear visit confirmation", vendors: "Plumber provider assignment", inspection: false },
  painting: { icon: "🎨", label: "Painting", hero: "Painting ecosystem for homes and shops", subtitle: "Room painting, wall repair, waterproof coating and premium inspection for accurate estimates.", examples: ["Room painting", "Wall putty", "Exterior painting", "Color consultation"], trust: "Site inspection option · local painters · estimate before work", vendors: "Painting teams and local contractors", inspection: true },
  waterproofing: { icon: "💧", label: "Waterproofing", hero: "Waterproofing inspection and repair", subtitle: "Roof, wall seepage, bathroom leakage and dampness checks with premium inspection option.", examples: ["Roof seepage", "Wall dampness", "Bathroom leakage", "Crack sealing"], trust: "Inspection-led scope · local repair teams", vendors: "Waterproofing specialists", inspection: true },
  cleaning: { icon: "🧹", label: "Cleaning", hero: "Cleaning services in Haldwani", subtitle: "Home, shop, kitchen and deep cleaning requests with local coordination.", examples: ["Home cleaning", "Kitchen cleaning", "Shop cleaning", "Move-in cleaning"], trust: "Clear scope confirmation · pay after service", vendors: "Cleaning partners", inspection: false },
  "ac-repair": { icon: "❄️", label: "AC repair", hero: "AC service and repair", subtitle: "AC checkup, service, cooling issue and installation support with verified local help.", examples: ["AC service", "Cooling issue", "Gas check", "Installation"], trust: "Technician confirmation · pay after service", vendors: "AC technicians", inspection: true },
  appliance: { icon: "🔧", label: "Appliance", hero: "Appliance repair support", subtitle: "Fridge, washing machine, RO and common appliance checks coordinated locally.", examples: ["RO service", "Washer issue", "Fridge check", "Geyser repair"], trust: "Diagnosis-first support · local technicians", vendors: "Appliance technicians", inspection: true },
  tutor: { icon: "📚", label: "Tutor", hero: "Local tutor requests", subtitle: "Tell WorkToGo your class, subject and area. Team will help connect locally.", examples: ["Math tutor", "Science tutor", "Home tuition", "Spoken English"], trust: "Manual matching during pilot", vendors: "Local tutor coordination", inspection: false },
  inspection: { icon: "🛡️", label: "Inspection", hero: "Premium inspection before big work", subtitle: "For painting, waterproofing, AC, appliance and complex jobs where a site check builds trust.", examples: ["Site visit", "Problem diagnosis", "Estimate support", "Scope clarity"], trust: "Clear visit · clear scope · better estimate", vendors: "Specialist inspection coordination", inspection: true },
};
