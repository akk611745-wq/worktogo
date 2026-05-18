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
      <header class="top-bar marketplace-top-bar">
        <button class="location-pill" onclick="HomePage.focusSearch('near me')" aria-label="Choose location">
          <span>📍</span>
          <strong>${_esc(_pilotConfig.city)}</strong>
          <small>near you</small>
        </button>
        <button class="support-entry" title="WhatsApp support" onclick="UI.openSupport('selector', { category: HomePage.activeCategoryLabel?.() })"><span>Help</span></button>
      </header>

      <div class="home-content">
        <section class="market-search-section">
          <div class="market-search-box">
            <span>⌕</span>
            <input id="service-search" type="search" placeholder="Search painter, leakage, fan, CCTV…" autocomplete="off" oninput="HomePage.searchServices(this.value)" />
            <button onclick="HomePage.clearSearch()" aria-label="Clear search">×</button>
          </div>
          <div id="search-results-panel" class="instant-search-panel hidden"></div>
        </section>

        <section class="service-hero marketplace-hero">
          <div class="service-hero-copy">
            <p class="service-hero-kicker" id="category-kicker">${_esc(_pilotConfig.city)} live marketplace</p>
            <h1 id="category-hero-title">Trusted Haldwani Services</h1>
            <p id="category-hero-subtitle">Nearby verified workers for repairs, painting, waterproofing, CCTV and home jobs.</p>
            <button class="btn-primary hero-primary marketplace-cta" onclick="HomePage.setCategory('inspection')">Book ₹299 Expert Visit</button>
          </div>
          <div class="hero-live-strip" aria-label="live trust signals">
            <span><b>42</b> workers nearby</span>
            <span><b>12 min</b> avg response</span>
            <span><b>4.8★</b> local rating</span>
          </div>
        </section>

        <section class="home-section browse-strip-section">
          <div class="section-header compact">
            <div>
              <p class="section-eyebrow">Tap to switch feed</p>
              <h3>What do you need?</h3>
            </div>
            <button class="see-all" onclick="HomePage.toggleMoreCategories()" id="more-categories-btn">More</button>
          </div>
          <div class="category-chips" id="category-chips">
            <button class="active" onclick="HomePage.setCategory('')"><span>🧰</span>All</button>
            ${_categoryChips().slice(0, 7).map(c => `<button onclick="HomePage.setCategory('${_esc(c.slug || '')}')"><span>${c.icon}</span>${_esc(c.label)}</button>`).join("")}
          </div>
        </section>

        <section class="category-ecosystem" id="category-ecosystem">
          ${_categoryEcosystemHTML("")}
        </section>

        <section class="home-section" id="services-section">
          <div class="section-header">
            <div>
              <p class="section-eyebrow">Live vendor feed</p>
              <h3 id="vendor-feed-title">Workers near you</h3>
            </div>
            <button class="see-all" onclick="HomePage.setCategory('')">All</button>
          </div>
          <div id="services-grid" class="vendor-feed">
            ${UI.skeleton(4, "card")}
          </div>
        </section>

        <section class="category-visual-proof" id="visual-proof-section">
          ${_visualProofHTML("")}
        </section>

        <section class="local-proof-grid marketplace-proof-grid">
          <div><strong>Verified nearby</strong><span>Local workers with WorkToGo confirmation</span></div>
          <div><strong>Fast booking</strong><span>Request now, confirm before visit</span></div>
          <div><strong>Pay after service</strong><span>No confusing advance flow for normal jobs</span></div>
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
    focusSearch(seed = "") {
      const inp = document.getElementById("service-search");
      if (!inp) return;
      if (seed && !inp.value) inp.value = seed;
      inp.focus();
      HomePage.searchServices(inp.value || seed);
    },
    setCategory(slug = "") {
      _activeCategory = slug;
      _searchQuery = document.getElementById("service-search")?.value?.trim() || "";
      _renderCategoryChips();
      _renderCategoryEcosystem();
      _renderVisualProof();
      _renderHeroForCategory();
      _renderServices({ ok: true, data: { services: _allServices } });
    },
    async searchServices(query = "") {
      _searchQuery = query.trim().toLowerCase();
      const inp = document.getElementById("service-search");
      if (inp && inp.value !== query) inp.value = query;
      _syncCategoryFromSearch();
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
      _renderInstantSearch();
    },
    clearSearch() {
      const inp = document.getElementById("service-search");
      if (inp) inp.value = "";
      _searchQuery = "";
      _searchRemoteServices = [];
      _renderInstantSearch();
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
  const title = document.getElementById("vendor-feed-title");
  if (title) title.textContent = _activeCategory ? `${_categoryMeta(_activeCategory).label} workers nearby` : "Workers near you";

  if (!res.ok) {
    const safeMessage = _friendlyServiceError(res.error);
    el.classList.add("fallback-services-grid");
    el.innerHTML = _fallbackServices().slice(0, 4).map(s => `
      ${_vendorCardHTML(s, true)}
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
      el.classList.add("fallback-services-grid");
      const fallbackList = _activeCategory ? _categoryFallbackServices(_activeCategory) : _fallbackServices();
      el.innerHTML = fallbackList.map(s => `
      ${_vendorCardHTML(s, true)}
    `).join("") + `
      <div class="fallback-help-card">
        <h3>${_esc(_activeCategory ? `${_categoryMeta(_activeCategory).label} help` : _pilotConfig.fallback_title)}</h3>
        <p>${_esc(_searchQuery ? "No exact match found yet. WorkToGo can still help route your request." : _pilotConfig.fallback_text)}</p>
        <button class="btn-ghost-inline" onclick="UI.openSupport('selector', { category: HomePage.activeCategoryLabel?.() })">Get guided help</button>
      </div>`;
      return;
  }
  el.classList.remove("fallback-services-grid");

  el.innerHTML = list.map(s => _vendorCardHTML(s)).join("");
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

function _renderVisualProof() {
  const el = document.getElementById("visual-proof-section");
  if (el) el.innerHTML = _visualProofHTML(_activeCategory);
}

function _renderHeroForCategory() {
  const meta = _categoryMeta(_activeCategory);
  const title = document.getElementById("category-hero-title");
  const subtitle = document.getElementById("category-hero-subtitle");
  const kicker = document.getElementById("category-kicker");
  if (title) title.textContent = meta.hero || _pilotConfig.hero_title;
  if (subtitle) subtitle.textContent = meta.subtitle || _pilotConfig.hero_subtitle;
  if (kicker) kicker.textContent = _activeCategory ? `${_pilotConfig.city} ${meta.label} ecosystem` : `${_pilotConfig.city} live marketplace`;
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
    { slug: "cctv", icon: "📹", label: "CCTV" },
    { slug: "carpentry", icon: "🪚", label: "Carpentry" },
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
  const visuals = meta.visuals || CATEGORY_META.all.visuals || [];
  const tags = meta.tags || meta.examples || [];
  return `
    <div class="ecosystem-card ecosystem-world">
      <div class="ecosystem-banner">
        <span class="ecosystem-icon">${meta.icon}</span>
        <div>
          <p class="service-hero-kicker">${_esc(meta.label)} mini-world</p>
          <h3>${_esc(meta.ecosystemTitle || `${meta.label} in ${_pilotConfig.city}`)}</h3>
          <p>${_esc(meta.trust || "Verified local providers · pay after service · human confirmation")}</p>
        </div>
      </div>
      <div class="ecosystem-visual-rail">
        ${visuals.slice(0, 3).map(v => `<button onclick="HomePage.searchServices('${_esc(v.query || v.label)}')"><span>${_esc(v.emoji || meta.icon)}</span><strong>${_esc(v.label)}</strong><small>${_esc(v.note || "nearby")}</small></button>`).join("")}
      </div>
      <div class="ecosystem-tag-row">
        ${tags.slice(0, 6).map(x => `<button onclick="HomePage.searchServices('${_esc(x)}')">${_esc(x)}</button>`).join("")}
      </div>
    </div>`;
}

function _visualProofHTML(slug = "") {
  const meta = _categoryMeta(slug);
  const beforeAfter = meta.beforeAfter || CATEGORY_META.all.beforeAfter;
  return `
    <div class="section-header">
      <div>
        <p class="section-eyebrow">Visual proof</p>
        <h3>${_esc(meta.label)} work examples</h3>
      </div>
    </div>
    <div class="proof-rail">
      ${beforeAfter.map((item, i) => `
        <div class="proof-tile proof-tone-${i % 3}">
          <div class="proof-split">
            <span>Before</span>
            <span>After</span>
          </div>
          <strong>${_esc(item.title)}</strong>
          <p>${_esc(item.note)}</p>
        </div>
      `).join("")}
    </div>`;
}

function _vendorCardHTML(service, support = false) {
  const meta = _categoryMeta(service.category_slug || service.slug || service.category || _activeCategory);
  const name = service.name || service.example || meta.examples?.[0] || meta.label;
  const price = service.price ? _esc(service.price) : UI.formatCurrency(_servicePrice(service) || (meta.inspection ? 299 : 199));
  const rating = service.rating || (4.6 + (String(name).length % 4) / 10).toFixed(1);
  const locality = service.locality || ["Mukhani", "Kusumkhera", "Kaladhungi Road", "Lalpur Nayak", "Dahariya"][String(name).length % 5];
  const exp = service.experience || `${3 + (String(name).length % 8)} yrs`;
  const photo = service.image || service.photo || "";
  const action = support
    ? `UI.openSupport('selector', { category: ${_jsString(meta.label)}, service: ${_jsString(name)} })`
    : `HomeModals.openBooking(${_jsonAttr({ ...service, category_slug: service.category_slug || service.slug || _activeCategory, icon: service.icon || meta.icon })})`;
  return `
    <article class="vendor-card" onclick="${action}">
      <div class="vendor-media ${photo ? "has-img" : ""}">
        ${photo ? `<img src="${_esc(photo)}" alt="${_esc(name)}" loading="lazy"/>` : `<span>${service.icon || meta.icon || "🔧"}</span>`}
        <em>Quick response</em>
      </div>
      <div class="vendor-body">
        <div class="vendor-head">
          <div class="vendor-avatar">${service.icon || meta.icon || "🔧"}</div>
          <div>
            <h4>${_esc(name)}</h4>
            <p>${_esc(locality)} · ${_esc(exp)} exp</p>
          </div>
        </div>
        <div class="vendor-stats">
          <span>★ ${_esc(rating)}</span>
          <span>${_esc(meta.label)}</span>
          <span>${price}</span>
        </div>
        <button class="vendor-book-btn">Book Now</button>
      </div>
    </article>`;
}

function _renderInstantSearch() {
  const panel = document.getElementById("search-results-panel");
  if (!panel) return;
  if (!_searchQuery) {
    panel.classList.add("hidden");
    panel.innerHTML = "";
    return;
  }
  const meta = _inferSearchMeta(_searchQuery);
  const candidates = (_searchRemoteServices.length ? _searchRemoteServices : _allServices).filter(s => _searchText(s).includes(_searchQuery)).slice(0, 3);
  panel.classList.remove("hidden");
  panel.innerHTML = `
    <div class="instant-search-head"><strong>${_esc(meta.label)} near ${_esc(_pilotConfig.city)}</strong><span>instant results</span></div>
    <div class="instant-result-list">
      ${(candidates.length ? candidates : _categoryFallbackServices(meta.slug)).slice(0, 3).map(s => `
        <button onclick="${s.id ? `HomeModals.openBooking(${_jsonAttr(s)})` : `UI.openSupport('selector', { category: ${_jsString(meta.label)}, service: ${_jsString(s.name)} })`}">
          <span>${s.icon || meta.icon}</span><strong>${_esc(s.name)}</strong><small>${_esc(s.price || "quick price")}</small>
        </button>
      `).join("")}
    </div>`;
}

function _syncCategoryFromSearch() {
  if (_searchQuery.length < 2) return;
  const inferred = _inferSearchMeta(_searchQuery);
  const inferredSlug = inferred.slug || "";
  if (!inferredSlug || inferredSlug === _activeCategory) return;
  _activeCategory = inferredSlug;
  _renderCategoryChips();
  _renderCategoryEcosystem();
  _renderVisualProof();
  _renderHeroForCategory();
}

function _inferSearchMeta(query) {
  const q = _slug(query);
  if (q.includes("paint") || q.includes("wall")) return _categoryMeta("painting");
  if (q.includes("leak") || q.includes("seep") || q.includes("water")) return _categoryMeta("waterproofing");
  if (q.includes("fan") || q.includes("light") || q.includes("mcb")) return _categoryMeta("electrician");
  if (q.includes("cctv") || q.includes("camera")) return _categoryMeta("cctv");
  if (q.includes("wood") || q.includes("door") || q.includes("carp")) return _categoryMeta("carpentry");
  if (q.includes("tap") || q.includes("pipe") || q.includes("plumb")) return _categoryMeta("plumber");
  return _categoryMeta(_activeCategory);
}

function _matchesCategory(service, slug) {
  const wanted = _slug(slug);
  const values = [service.category_slug, service.category, service.category_name, service.name].map(_slug).filter(Boolean);
  return values.some(v => v === wanted || v.includes(wanted) || wanted.includes(v));
}

function _searchText(service) {
  return [service.name, service.description, service.category, service.category_name, service.category_slug, service.slug, service.short_desc, service.example].filter(Boolean).join(" ").toLowerCase();
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
      _renderVisualProof();
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
  all: { slug: "", icon: "🧰", label: "All services", hero: "Trusted Haldwani Services", subtitle: "Nearby verified workers for repairs, painting, waterproofing, CCTV and home jobs.", ecosystemTitle: "Local workers available now", examples: ["Electrician", "Plumber", "Painting", "CCTV"], tags: ["fan", "leakage", "painter", "CCTV", "carpenter", "waterproofing"], visuals: [{ emoji: "⚡", label: "Fan fixed", note: "from ₹199" }, { emoji: "🎨", label: "Room painted", note: "estimate visit" }, { emoji: "💧", label: "Leak stopped", note: "inspection" }], beforeAfter: [{ title: "Damp wall restored", note: "Seepage inspection, repair and repaint flow" }, { title: "Old room refresh", note: "Putty, primer and clean finish by local painters" }], trust: "Local coordination · pay after service · human confirmation", vendors: "Verified local provider network", inspection: true },
  electrician: { icon: "⚡", label: "Electrical", hero: "Electrical workers near you", subtitle: "Fan, switch, MCB, wiring and light installation with quick local confirmation.", examples: ["Fan repair", "Switch board", "MCB issue", "Light installation"], tags: ["fan", "switch", "MCB", "wiring", "geyser", "inverter"], visuals: [{ emoji: "🌀", label: "Fan repair", note: "from ₹199" }, { emoji: "🔌", label: "Switch board", note: "same day" }, { emoji: "💡", label: "Lights", note: "install" }], beforeAfter: [{ title: "Dead fan running", note: "Local electrician visit with quick diagnosis" }, { title: "Unsafe board cleaned", note: "Switch replacement and wiring check" }], trust: "Safety-first local electricians · pay after service", vendors: "Electrician provider assignment", inspection: false },
  plumber: { icon: "🚰", label: "Plumbing", hero: "Plumbers for leakage and fittings", subtitle: "Tap, pipe, bathroom and kitchen plumbing help from nearby workers.", examples: ["Leakage repair", "Tap fitting", "Pipe blockage", "Bathroom fitting"], tags: ["tap leak", "pipe", "flush", "basin", "bathroom", "motor"], visuals: [{ emoji: "🚿", label: "Tap leak", note: "from ₹199" }, { emoji: "🧰", label: "Pipe fix", note: "nearby" }, { emoji: "🚽", label: "Bathroom", note: "fitting" }], beforeAfter: [{ title: "Leakage stopped", note: "Tap and joint repair by local plumber" }, { title: "Bathroom fitting done", note: "Clear scope confirmation before visit" }], trust: "Local plumbers · clear visit confirmation", vendors: "Plumber provider assignment", inspection: false },
  painting: { icon: "🎨", label: "Painting", hero: "Painting ecosystem for homes and shops", subtitle: "Painters, wall textures, putty, before/after work and expert visit for estimates.", examples: ["Room painting", "Wall putty", "Exterior painting", "Color consultation"], tags: ["texture", "putty", "primer", "room paint", "exterior", "rental repaint"], visuals: [{ emoji: "🧱", label: "Wall texture", note: "trending" }, { emoji: "🏠", label: "Room paint", note: "quote" }, { emoji: "🪣", label: "Putty repair", note: "before/after" }], beforeAfter: [{ title: "Bedroom repaint", note: "Old patches to clean warm finish" }, { title: "Texture wall upgrade", note: "Accent wall with painter estimate" }, { title: "Exterior refresh", note: "Weather coat and crack prep" }], trust: "Site inspection option · local painters · estimate before work", vendors: "Painting teams and local contractors", inspection: true },
  waterproofing: { icon: "💧", label: "Waterproofing", hero: "Leakage and seepage protection", subtitle: "Terrace, wall seepage, bathroom leakage and monsoon protection with inspection offers.", examples: ["Roof seepage", "Wall dampness", "Bathroom leakage", "Crack sealing"], tags: ["terrace", "seepage", "monsoon", "bathroom leak", "damp wall", "crack seal"], visuals: [{ emoji: "🌧️", label: "Monsoon cover", note: "inspection" }, { emoji: "🏚️", label: "Damp wall", note: "diagnosis" }, { emoji: "🧪", label: "Coating", note: "terrace" }], beforeAfter: [{ title: "Terrace leakage sealed", note: "Inspection-led waterproof coating" }, { title: "Seepage wall treated", note: "Dampness source checked before repair" }, { title: "Bathroom leak fixed", note: "Joint sealing and slope check" }], trust: "Inspection-led scope · local repair teams", vendors: "Waterproofing specialists", inspection: true },
  cctv: { icon: "📹", label: "CCTV", hero: "CCTV installation near you", subtitle: "Camera setup, wiring, DVR/NVR, shop and home security visits by local technicians.", examples: ["Camera install", "DVR setup", "Wiring", "Shop security"], tags: ["camera", "DVR", "NVR", "home CCTV", "shop CCTV", "wiring"], visuals: [{ emoji: "📹", label: "Camera install", note: "quote" }, { emoji: "🖥️", label: "DVR setup", note: "fast" }, { emoji: "🏪", label: "Shop CCTV", note: "nearby" }], beforeAfter: [{ title: "Shop camera live", note: "Camera angle and DVR configured" }, { title: "Home entry covered", note: "Wiring and mobile view setup" }], trust: "Security technician confirmation · clear install scope", vendors: "CCTV installers", inspection: true },
  carpentry: { icon: "🪚", label: "Carpentry", hero: "Carpenters for repair and renovation", subtitle: "Door, wardrobe, modular fixes, polish and furniture repair from local carpenters.", examples: ["Door repair", "Wardrobe", "Furniture fix", "Polish work"], tags: ["door", "wardrobe", "hinge", "modular", "polish", "furniture"], visuals: [{ emoji: "🚪", label: "Door repair", note: "from ₹249" }, { emoji: "🪵", label: "Furniture", note: "fix" }, { emoji: "🧱", label: "Wardrobe", note: "quote" }], beforeAfter: [{ title: "Door alignment fixed", note: "Hinge repair and smooth closing" }, { title: "Furniture restored", note: "Polish and repair work proof" }, { title: "Wardrobe repair", note: "Local carpenter estimate and visit" }], trust: "Local carpenters · inspection for custom work", vendors: "Carpentry workers", inspection: true },
  cleaning: { icon: "🧹", label: "Cleaning", hero: "Cleaning services in Haldwani", subtitle: "Home, shop, kitchen and deep cleaning requests with local coordination.", examples: ["Home cleaning", "Kitchen cleaning", "Shop cleaning", "Move-in cleaning"], trust: "Clear scope confirmation · pay after service", vendors: "Cleaning partners", inspection: false },
  "ac-repair": { icon: "❄️", label: "AC repair", hero: "AC service and repair", subtitle: "AC checkup, service, cooling issue and installation support with verified local help.", examples: ["AC service", "Cooling issue", "Gas check", "Installation"], trust: "Technician confirmation · pay after service", vendors: "AC technicians", inspection: true },
  appliance: { icon: "🔧", label: "Appliance", hero: "Appliance repair support", subtitle: "Fridge, washing machine, RO and common appliance checks coordinated locally.", examples: ["RO service", "Washer issue", "Fridge check", "Geyser repair"], trust: "Diagnosis-first support · local technicians", vendors: "Appliance technicians", inspection: true },
  tutor: { icon: "📚", label: "Tutor", hero: "Local tutor requests", subtitle: "Tell WorkToGo your class, subject and area. Team will help connect locally.", examples: ["Math tutor", "Science tutor", "Home tuition", "Spoken English"], trust: "Manual matching during pilot", vendors: "Local tutor coordination", inspection: false },
  inspection: { icon: "🛡️", label: "Inspection", hero: "Premium inspection before big work", subtitle: "For painting, waterproofing, AC, appliance and complex jobs where a site check builds trust.", examples: ["Site visit", "Problem diagnosis", "Estimate support", "Scope clarity"], trust: "Clear visit · clear scope · better estimate", vendors: "Specialist inspection coordination", inspection: true },
};
