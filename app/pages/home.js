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
  _restoreHomeState();

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
        <section class="market-search-section top-search-hidden" aria-hidden="true">
          <div class="market-search-box">
            <span>⌕</span>
            <input id="service-search" type="search" placeholder="Search painter, leakage, fan, CCTV…" autocomplete="off" value="${_esc(_searchQuery)}" onfocus="HomePage.openSearch()" oninput="HomePage.searchServices(this.value)" />
            <button onclick="HomePage.clearSearch()" aria-label="Clear search">×</button>
          </div>
          <div id="search-results-panel" class="instant-search-panel hidden"></div>
        </section>

        <section class="home-section browse-strip-section">
          <div class="section-header compact">
            <div>
              <h3>What do you need?</h3>
            </div>
            <button class="see-all" onclick="HomePage.toggleMoreCategories()" id="more-categories-btn">More</button>
          </div>
          <div class="category-chips" id="category-chips">
            <button class="active" onclick="HomePage.setCategory('')"><span>🧰</span>All</button>
            ${_categoryChips().slice(0, 7).map(c => `<button onclick="HomePage.setCategory('${_esc(c.slug || '')}')"><span>${c.icon}</span>${_esc(c.label)}</button>`).join("")}
          </div>
        </section>

        <section class="service-hero marketplace-hero" id="category-hero">
          ${_heroHTML(_activeCategory)}
        </section>

        <section class="quick-services-section" id="quick-services-section">
          ${_serviceCardsHTML(_activeCategory)}
        </section>

        <section class="free-booking-strip" id="free-booking-strip">
          ${_freeBookingStripHTML(_activeCategory)}
        </section>

        <section class="operating-feed" id="operating-feed">
          ${_operatingFeedHTML(_activeCategory)}
        </section>

        <section class="home-section" id="services-section">
          <div class="section-header">
            <div>
              <h3 id="vendor-feed-title">Workers near you</h3>
            </div>
            <button class="see-all" onclick="HomePage.setCategory('')">All</button>
          </div>
          <div id="services-grid" class="vendor-feed">
            ${UI.skeleton(4, "card")}
          </div>
        </section>

        <section class="category-ecosystem" id="category-ecosystem">
          ${_categoryEcosystemHTML(_activeCategory)}
        </section>

        <section class="local-proof-grid marketplace-proof-grid" id="trust-proof-section">
          ${_trustProofHTML(_activeCategory)}

        </section>

        <section class="category-visual-proof" id="visual-proof-section">
          ${_visualProofHTML(_activeCategory)}

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

    <div id="explore-overlay" class="explore-overlay hidden" role="dialog" aria-modal="true" aria-label="Explore services">
      <div class="explore-search-shell">
        <div id="explore-search-slot"></div>
        <button class="explore-close" type="button" onclick="HomePage.closeExploreOverlay()" aria-label="Close explore">×</button>
      </div>
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
      HomePage.openExploreOverlay();
      const inp = document.getElementById("service-search");
      if (!inp) return;
      if (seed && !inp.value) inp.value = seed;
      inp.focus();
      HomePage.searchServices(inp.value || seed);
    },
    focusTopSearch() {
      HomePage.openExploreOverlay();
    },
    openExploreOverlay() {
      _openExploreOverlay();
    },
    closeExploreOverlay() {
      _closeExploreOverlay();
    },
    setCategory(slug = "") {
      _activeCategory = slug;
      _activeChipFilter = "";
      _searchQuery = "";
      _searchRemoteServices = [];
      const inp = document.getElementById("service-search");
      if (inp) inp.value = "";
      _activeDiscoveryKind = "";
      _renderInstantSearch();
      _renderCategoryChips();
      _renderCategoryEcosystem();
      _renderOperatingFeed();
      _renderHeroForCategory();
      _renderQuickServiceCards();
      _renderFreeBookingStrip();
      _renderContextProof();
      _renderServices({ ok: true, data: { services: _allServices } });
      _syncOperatingMode();
      _persistHomeState();
    },
    filterEcosystem(term = "") {
      _activeChipFilter = String(term || "").trim().toLowerCase();
      _activeLocalityFilter = "";
      _searchQuery = "";
      _searchRemoteServices = [];
      const inp = document.getElementById("service-search");
      if (inp) inp.value = "";
      _renderInstantSearch();
      _renderCategoryEcosystem();
      _renderOperatingFeed();
      _renderHeroForCategory();
      _renderQuickServiceCards();
      _renderFreeBookingStrip();
      _renderContextProof();
      _renderServices({ ok: true, data: { services: _allServices } });
      document.getElementById("services-section")?.scrollIntoView({ behavior: "smooth", block: "nearest" });
      _persistHomeState();
    },
    selectLocality(locality = "") {
      _activeLocalityFilter = String(locality || "").trim();
      _activeChipFilter = "";
      _activeDiscoveryKind = "locality";
      _renderOperatingFeed();
      _renderCategoryEcosystem();
      _renderContextProof();
      _renderServices({ ok: true, data: { services: _allServices } });
      _persistHomeState();
      UI.toast(_activeLocalityFilter ? `${_activeLocalityFilter} context selected for routing` : "Local context cleared", "info");
    },
    ecosystemDiscover(kind = "", value = "") {
      const meta = _categoryMeta(_activeCategory);
      const lookup = {
        dealers: meta.dealers || CATEGORY_META.all.dealers || [],
        materials: meta.materials || CATEGORY_META.all.materials || [],
        brands: meta.brands || CATEGORY_META.all.brands || [],
        locality: meta.locality || CATEGORY_META.all.locality || [],
      };
      const seed = value || lookup[kind]?.[0] || meta.label;
      _activeDiscoveryKind = kind;
      HomePage.filterEcosystem(seed);
    },
    selectBookingMode(mode = "") {
      const selectedButton = [...document.querySelectorAll(".booking-mode-option")].find(btn => btn.dataset.mode === mode);
      if (!selectedButton || selectedButton.disabled || selectedButton.getAttribute("aria-disabled") === "true") return;
      const hidden = document.getElementById("booking-mode");
      if (hidden) hidden.value = mode;
      document.querySelectorAll(".booking-mode-option").forEach(btn => btn.classList.remove("active"));
      selectedButton.classList.add("active");
      const confirm = document.getElementById("btn-confirm-booking")?.querySelector(".btn-label");
      if (confirm) confirm.textContent = mode === "inspection" ? `Pay ${UI.formatCurrency(_inspectionPrice())} Inspection` : mode === "direct_vendor" ? "Book Vendor" : "Submit Free Lead";
      _persistPendingBookingForm();
    },
    persistPendingBookingForm() {
      _persistPendingBookingForm();
    },
    openSearch() {
      _renderInstantSearch();
    },
    async searchServices(query = "") {
      _searchQuery = query.trim().toLowerCase();
      _activeDiscoveryKind = "";
      const inp = document.getElementById("service-search");
      if (inp && inp.value !== query) inp.value = query;
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
      _persistHomeState();
    },
    clearSearch() {
      const inp = document.getElementById("service-search");
      if (inp) inp.value = "";
      _searchQuery = "";
      _searchRemoteServices = [];
      _renderInstantSearch();
      _renderServices({ ok: true, data: { services: _allServices } });
      _persistHomeState();
    },
    toggleMoreCategories() {
      _showAllCategories = !_showAllCategories;
      _renderCategoryChips();
    },
    activeCategoryLabel() {
      return _categoryMeta(_activeCategory).label;
    },
    activeCategorySlug() {
      return _activeCategory;
    },
    bookCategoryCta(slug = "", mode = "") {
      const meta = _categoryMeta(slug || _activeCategory);
      const source = mode === "inspection" ? "hero" : "category";
      if (!(slug || _activeCategory)) {
        const inspection = _allServices.find(s => _matchesCategory(s, "inspection"))
          || _allServices.find(s => _categoryMeta(s.category_slug || s.category || "").inspection);
        if (inspection?.id) {
          HomeModals.openBooking({ ...inspection, booking_mode: mode || "inspection", request_source: source, icon: inspection.icon || "🛡️" });
          return;
        }
      }
      const service = _allServices.find(s => _matchesCategory(s, meta.slug) && (!_activeChipFilter || _searchText(s).includes(_activeChipFilter)))
        || _allServices.find(s => _matchesCategory(s, meta.slug));
      if (service?.id) {
        HomeModals.openBooking({ ...service, booking_mode: mode || service.booking_mode, request_source: source, category_slug: service.category_slug || meta.slug, icon: service.icon || meta.icon });
        return;
      }
      UI.openSupport('selector', { category: meta.label, service: meta.examples?.[0] || meta.label });
    },
    bookQuickService(slug = "", serviceName = "") {
      const meta = _categoryMeta(slug || _activeCategory);
      const match = _allServices.find(s => _matchesCategory(s, meta.slug) && _searchText(s).includes(String(serviceName || "").toLowerCase()))
        || _allServices.find(s => _matchesCategory(s, meta.slug));
      if (match?.id) {
        HomeModals.openBooking({ ...match, request_source: "service_chip", category_slug: match.category_slug || meta.slug, icon: match.icon || meta.icon, quick_service: serviceName });
        return;
      }
      _activeChipFilter = String(serviceName || "").trim().toLowerCase();
      HomePage.bookCategoryCta(meta.slug || "", "free_lead");
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
  _setupExploreOverlay();
  _syncOperatingMode();
  _renderInstantSearch();
}

// ── Modal Controller ────────────────────────────────────────────────────────

window.HomeModals = (() => {
  let _currentProduct = null;
  let _currentService = null;
  let _isBookingSubmitting = false;
  let _bookingOpenToken = 0;

  function openOrder(product) {
    if (CONFIG.FEATURES?.SERVICE_ONLY_MODE) {
      UI.toast("Orders are disabled in service-only mode.", "info");
      return;
    }
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

  function openBooking(service = {}) {
    if (!service || typeof service !== "object") return;
    _persistPendingBookingForm();
    _bookingOpenToken += 1;
    _isBookingSubmitting = false;
    const token = _bookingOpenToken;
    const profile = _customerProfile();
    const category = _categoryMeta(service.category_slug || service.category || _activeCategory);
    const selectedService = service.quick_service || service.selected_service || service.name || category.examples?.[0] || category.label;
    _currentService = {
      ...service,
      category_slug: category.slug || _activeCategory || service.category_slug || service.category || "",
      category_label: category.label,
      selected_service: selectedService,
      request_source: service.request_source || "category",
    };
    const restored = _pendingBookingForm();
    const sameRestoredContext = !restored.service_context || restored.service_context === selectedService;
    const defaultMode = _canonicalBookingMode(service.booking_mode || (sameRestoredContext ? restored.booking_mode : "") || (service.vendor_id ? "direct_vendor" : "free_lead"), _currentService, category);
    const inspectionPrice = _inspectionPrice(_currentService, category);
    document.getElementById("booking-modal-title").textContent = defaultMode === "inspection" ? `Inspection for ${category.label}` : `Book ${category.label}`;
    document.getElementById("booking-modal-body").innerHTML = `
      <div class="modal-product-info booking-sheet-summary">
        <div class="modal-product-placeholder">${service.icon || "🔧"}</div>
        <div>
          <p class="modal-price">${_esc(selectedService)}</p>
          <p class="modal-desc">${_esc(category.label)} · ${_esc(defaultMode === "inspection" ? "diagnosis first" : "direct request")}</p>
          ${service.description ? `<p class="modal-desc">${_esc(service.description)}</p>` : ""}
          <p class="service-note">Choose how WorkToGo should route this request before worker arrival.</p>
        </div>
      </div>
      ${_premiumInspectionPanel(category)}
      <div class="booking-mode-picker" role="radiogroup" aria-label="Booking mode">
        ${_bookingModeOption("inspection", `${UI.formatCurrency(inspectionPrice)} inspection`, "Issue unclear: technician visits first, diagnoses, then final work is confirmed", defaultMode, false, "premium")}
        ${_bookingModeOption("free_lead", "Free booking", "Work understood: WorkToGo starts nearby worker matching directly", defaultMode, false, "default")}
        ${_bookingModeOption("direct_vendor", "Request this worker", "Admin routes this request to the shown worker if available", defaultMode, !service.vendor_id, "direct")}
      </div>
      <div class="booking-context-strip">
        <span>${category.icon}</span>
        <strong>${_esc(selectedService)} request</strong>
        <small>${_esc(category.label)} context · WorkToGo confirms worker and timing</small>
      </div>
      ${!AUTH.isLoggedIn() ? `<div class="booking-login-nudge"><strong>Phone verification needed at submit</strong><span>You can fill this now. We will send you to mobile OTP only when you request service.</span></div>` : ""}
      <div class="trust-panel booking-trust-panel fast-booking-trust">
        <span>✓ Inspection: diagnosis before final work or material decision</span>
        <span>✓ Free booking: clear job enters admin worker assignment</span>
        <span>✓ Payment: normal jobs pay after service; inspection starts after payment</span>
      </div>
      <div class="modal-field">
        <label for="booking-name">Name (optional)</label>
        <input type="text" id="booking-name" class="modal-input" placeholder="Your name if available" autocomplete="name" value="${_esc(restored.name || profile.name || "")}" oninput="HomePage.persistPendingBookingForm?.()" />
      </div>
      <div class="modal-field">
        <label for="booking-mobile">Mobile</label>
        <input type="tel" id="booking-mobile" class="modal-input" placeholder="Mobile number for confirmation" autocomplete="tel" value="${_esc(restored.phone || profile.phone || "")}" oninput="HomePage.persistPendingBookingForm?.()" />
      </div>
      <div class="modal-field">
        <label for="booking-date">When can worker visit?</label>
        <input type="datetime-local" id="booking-date" class="modal-input"
          min="${_isoNow()}"
          value="${_esc((sameRestoredContext && restored.scheduled_at) || _defaultScheduledLocal())}"
          onchange="HomePage.persistPendingBookingForm?.()"
        />
      </div>
      <div class="modal-field">
        <label for="booking-area">Area / Landmark</label>
        <input type="text" id="booking-area" class="modal-input" placeholder="e.g. Mukhani, Kusumkhera, near canal road" autocomplete="address-level2" value="${_esc((sameRestoredContext && restored.locality) || _activeLocalityFilter || profile.locality || profile.area || "")}" oninput="HomePage.persistPendingBookingForm?.()" />
      </div>
      <div class="modal-field">
        <label for="booking-address">Full Address</label>
        <textarea id="booking-address" class="modal-textarea" placeholder="House number, street, nearby landmark" rows="2" autocomplete="street-address" oninput="HomePage.persistPendingBookingForm?.()">${_esc(restored.address || profile.address || "")}</textarea>
      </div>
      <div class="modal-field">
        <label for="booking-notes">Issue note</label>
        <textarea id="booking-notes" class="modal-textarea" placeholder="Example: ${_esc(_activeChipFilter || selectedService || "what needs fixing")}, urgency, call before coming…" rows="2" oninput="HomePage.persistPendingBookingForm?.()">${_esc((sameRestoredContext && restored.notes) || "")}</textarea>
      </div>
      <input type="hidden" id="booking-mode" value="${_esc(defaultMode)}" />
      <input type="hidden" id="booking-service-context" value="${_esc(selectedService)}" />
      <p class="service-note">After submission, admin sees work, area, urgency, mode and service context for assignment.</p>
    `;
    if (token !== _bookingOpenToken) return;
    document.getElementById("btn-confirm-booking")?.classList.remove("loading");
    const modal = document.getElementById("booking-modal");
    modal?.classList.remove("hidden");
    modal?.querySelector(".modal-sheet")?.scrollTo({ top: 0, behavior: "instant" });
    _lockModalBody("booking");
    _persistPendingBookingForm();
  }

  async function confirmBooking() {
    if (_isBookingSubmitting) return;
    if (!AUTH.isLoggedIn()) {
      UI.toast("Login with mobile OTP to request this service", "info");
      const pendingArea = document.getElementById("booking-area")?.value?.trim() || _activeLocalityFilter;
      if (pendingArea) _activeLocalityFilter = pendingArea;
      _persistHomeState();
      _savePendingBookingIntent(_currentService, _pendingBookingForm());
      closeBooking();
      ROUTER.go("login");
      return;
    }
    if (!_currentService) return;
    const dateVal = document.getElementById("booking-date")?.value;
    const name    = document.getElementById("booking-name")?.value?.trim() || "";
    const mobile  = document.getElementById("booking-mobile")?.value?.trim() || "";
    const area    = document.getElementById("booking-area")?.value?.trim() || "";
    const address = document.getElementById("booking-address")?.value?.trim() || "";
    const notes   = document.getElementById("booking-notes")?.value?.trim() || "";
    const serviceContext = document.getElementById("booking-service-context")?.value?.trim() || _currentService.quick_service || _currentService.name || "";
    const category = _categoryMeta(_currentService.category_slug || _currentService.category || _activeCategory);
    const bookingMode = _canonicalBookingMode(document.getElementById("booking-mode")?.value, _currentService, category);

    if (!dateVal) { _markInvalid("booking-date", "Please choose preferred date and time"); return; }
    if (Number.isNaN(new Date(dateVal).getTime()) || new Date(dateVal).getTime() <= Date.now()) {
      _markInvalid("booking-date", "Please choose a future date and time");
      return;
    }
    if (!mobile) { _markInvalid("booking-mobile", "Please enter mobile number"); return; }
    const mobileDigits = mobile.replace(/\D/g, "");
    if (mobileDigits.length !== 10) { _markInvalid("booking-mobile", "Please enter a valid 10-digit mobile number"); return; }
    if (!area) { _markInvalid("booking-area", "Please enter area or landmark"); return; }
    if (!address) { _markInvalid("booking-address", "Please enter full address"); return; }
    if (!notes) { _markInvalid("booking-notes", "Please add a short issue note"); return; }

    const btn = document.getElementById("btn-confirm-booking");
    _isBookingSubmitting = true;
    if (btn) { btn.disabled = true; btn.classList.add("loading"); }

    _persistCustomerProfile({ ...(name ? { name } : {}), phone: mobile, locality: area, address });

    const requestType = _requestType(bookingMode);
    const requestSource = _currentService.request_source || "category";
    const selectedWorker = bookingMode === "direct_vendor" ? {
      vendor_id: _currentService.vendor_id || null,
      vendor_name: _currentService.vendor_name || _currentService.name || "Requested worker",
    } : null;
    const operationalPayload = {
      request_type: requestType,
      category: category.slug || _activeCategory,
      category_label: category.label,
      subservice: serviceContext,
      locality: area,
      selected_nearby_area: _activeLocalityFilter || area,
      full_address: address,
      customer: {
        name: name || "WorkToGo Customer",
        phone: mobileDigits,
        auth_user_id: AUTH.getUser?.()?.id || null,
      },
      booking_mode: bookingMode,
      booking_mode_label: _bookingModeMeaning(bookingMode),
      issue_note: notes,
      preferred_time: new Date(dateVal).toISOString(),
      request_source: requestSource,
      selected_worker: selectedWorker,
      timestamp: new Date().toISOString(),
      session_context: _sessionContext(),
      tracking_state: _initialTrackingState(bookingMode),
    };

    const res = await API.createBooking({
        service_id: _currentService.id,
        ...(dateVal ? { scheduled_at: new Date(dateVal).toISOString() } : {}),
        booking_mode: bookingMode,
        lifecycle_type: bookingMode,
        request_type: requestType,
        request_source: requestSource,
        subservice: serviceContext,
        issue_note: notes,
        preferred_time: new Date(dateVal).toISOString(),
        booking_mode_label: _bookingModeMeaning(bookingMode),
        operational_tracking_state: operationalPayload.tracking_state,
        operational_request: operationalPayload,
        payment_method: bookingMode === "inspection" ? "online" : "cod",
        expected_payment_amount: bookingMode === "inspection" ? _inspectionPrice(_currentService, category) : 0,
        payment_status: "unpaid",
        category_slug: category.slug || _activeCategory,
        category_label: category.label,
        customer_name: name || "WorkToGo Customer",
        customer_mobile: mobileDigits,
        customer_locality: area,
        customer_address: address,
        vendor_id: selectedWorker?.vendor_id || null,
        notes: _adminRequestNotes(operationalPayload),
      }).catch(err => ({ ok: false, error: err?.message || "Network issue while sending booking." }));

      if (btn) { btn.disabled = false; btn.classList.remove("loading"); }
      _isBookingSubmitting = false;

    if (res.ok) {
      const paymentOk = await _handleInspectionCheckout(res.data, bookingMode);
      if (!paymentOk) {
        _isBookingSubmitting = false;
        if (btn) { btn.disabled = false; btn.classList.remove("loading"); }
        return;
      }
      closeBooking();
      _clearPendingBookingForm();
      _clearPendingBookingIntent();
      UI.toast(bookingMode === "inspection" ? "Inspection booked. Diagnosis visit is being confirmed." : bookingMode === "direct_vendor" ? "Worker request sent for confirmation." : "Free booking opened worker matching.", "success");
      setTimeout(() => ROUTER.go("bookings"), 800);
    } else {
      UI.toast(res.error || "Booking request is in coordination. Please use support if confirmation does not appear.", "error");
    }
  }

  function close() {
    document.getElementById("order-modal")?.classList.add("hidden");
    _currentProduct = null;
    _unlockModalBody("order");
  }

  function closeBooking() {
    _persistPendingBookingForm();
    _bookingOpenToken += 1;
    _isBookingSubmitting = false;
    document.getElementById("booking-modal")?.classList.add("hidden");
    _currentService = null;
    const btn = document.getElementById("btn-confirm-booking");
    if (btn) { btn.disabled = false; btn.classList.remove("loading"); }
    _unlockModalBody("booking");
  }

  function closeOnOverlay(e) {
    if (e.target !== e.currentTarget) return;
    if (e.currentTarget?.id === "booking-modal") closeBooking();
    else close();
  }

  function _isoNow() {
    return new Date().toISOString().slice(0, 16);
  }

  function _defaultScheduledLocal() {
    const d = new Date(Date.now() + 2 * 60 * 60 * 1000);
    d.setMinutes(Math.ceil(d.getMinutes() / 15) * 15, 0, 0);
    return d.toISOString().slice(0, 16);
  }

  function _markInvalid(id, message) {
    const el = document.getElementById(id);
    if (el) {
      el.classList.add("field-invalid");
      el.focus({ preventScroll: false });
      el.addEventListener("input", () => el.classList.remove("field-invalid"), { once: true });
    }
    UI.toast(message, "error");
  }

  function _lockModalBody(kind) {
    document.body.classList.add("modal-open");
    document.body.dataset.modalOpen = kind;
  }

  function _unlockModalBody(kind) {
    if (document.body.dataset.modalOpen && document.body.dataset.modalOpen !== kind) return;
    document.body.classList.remove("modal-open");
    delete document.body.dataset.modalOpen;
  }

  return { openOrder, changeQty, confirmOrder, openBooking, confirmBooking, close, closeBooking, closeOnOverlay };
})();

async function _handleInspectionCheckout(booking, bookingMode) {
  const paymentData = booking?.payment_data || booking?.paymentData || null;
  const bookingId = booking?.booking_id || booking?.id || paymentData?.booking_id || null;
  if (bookingMode !== "inspection" || !paymentData) return true;
  if (paymentData.success === false) {
    UI.toast(paymentData.message || "Payment session could not be created. Booking remains pending.", "error", 5000);
    return false;
  }
  const gatewayData = paymentData.gateway_data || paymentData.gatewayData || {};
  const sessionId = paymentData.payment_session_id || paymentData.paymentSessionId || paymentData.session_id || gatewayData.payment_session_id || gatewayData.paymentSessionId;
  const redirectUrl = paymentData.payment_link || paymentData.payment_url || paymentData.redirect_url || paymentData.url || gatewayData.payment_link || gatewayData.payment_url;
  try {
    if (window.Cashfree && sessionId) {
      const cashfree = typeof window.Cashfree === "function" ? window.Cashfree({ mode: paymentData.mode || "production" }) : window.Cashfree;
      const result = await cashfree.checkout({ paymentSessionId: sessionId, redirectTarget: "_modal" });
      const status = String(result?.paymentDetails?.payment_status || result?.paymentDetails?.status || result?.status || "").toUpperCase();
      if (result?.error || status === "FAILED" || status === "CANCELLED") {
        UI.toast("Payment was not completed. Your inspection booking remains pending.", "error", 5000);
        return false;
      }
      if (!status) {
        UI.toast("Payment result is pending verification. Your inspection booking remains pending.", "info", 5000);
        return false;
      }
      if (status && !["SUCCESS", "PAID", "COMPLETED"].includes(status)) {
        UI.toast("Payment is still pending. You can retry from Bookings if needed.", "info", 5000);
        return false;
      }
      const verified = await _waitForBookingPaymentTruth(bookingId);
      if (!verified) {
        UI.toast("Payment is pending backend verification. Booking remains pending until Cashfree confirms it.", "info", 6000);
        return false;
      }
      return true;
    }
    if (redirectUrl) {
      window.location.href = redirectUrl;
      return false;
    }
    UI.toast("Inspection booking is saved. Payment confirmation can be completed from Bookings/support.", "info", 5000);
    return false;
  } catch (err) {
    UI.toast("Inspection booking remains saved for payment verification.", "info", 5000);
    return false;
  }
}

async function _waitForBookingPaymentTruth(bookingId) {
  if (!bookingId || !API.getPaymentStatus) return false;
  for (let attempt = 0; attempt < 8; attempt += 1) {
    const res = await API.getPaymentStatus({ booking_id: bookingId }).catch(() => null);
    const status = String(res?.data?.payment_status || "").toLowerCase();
    if (status === "paid") return true;
    if (["failed", "refunded"].includes(status)) return false;
    await new Promise(resolve => setTimeout(resolve, 1500));
  }
  return false;
}

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
  if (title) title.textContent = _activeCategory ? `${_categoryMeta(_activeCategory).label} workers near you` : "Workers near you";

  if (!res.ok) {
    el.classList.remove("fallback-services-grid");
    el.innerHTML = `
      <div class="fallback-help-card service-recovery-card">
        <h3>Nearby worker confirmation active</h3>
        <p>${_esc(_friendlyServiceError(res.error))} Requests are still routed through WorkToGo confirmation.</p>
        <div class="recovery-actions">
          <button class="btn-ghost-inline" onclick="HomePage.bookCategoryCta('', 'free_lead')">Open worker matching</button>
          <button class="btn-ghost-inline" onclick="UI.openSupport('selector', { category: HomePage.activeCategoryLabel?.() })">Operations support</button>
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
    const inferred = _searchQuery ? _inferSearchMeta(_searchQuery) : null;
    const categoryFilter = _activeCategory || (inferred?.slug || "");
    if (categoryFilter) list = list.filter(s => _matchesCategory(s, categoryFilter));
    if (_activeChipFilter) list = list.filter(s => _serviceMatchesDiscovery(s, _activeChipFilter, _categoryMeta(_activeCategory)));
    if (_searchQuery) list = list.filter(s => _searchText(s).includes(_searchQuery));

    if (!list.length) {
      el.classList.remove("fallback-services-grid");
      el.innerHTML = `
      <div class="fallback-help-card">
        <h3>${_esc(_activeCategory ? `${_categoryMeta(_activeCategory).label} worker matching ready` : "Nearby worker matching ready")}</h3>
        <p>${_esc(_searchQuery || _activeChipFilter ? "Exact live card is not shown yet. Submit the request and WorkToGo will route it after confirmation." : "Booking opens matching with nearby workers after confirmation.")}</p>
        <button class="btn-ghost-inline" onclick="HomePage.bookCategoryCta('', 'free_lead')">Free booking</button>
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

function _renderQuickServiceCards() {
  const el = document.getElementById("quick-services-section");
  if (el) el.innerHTML = _serviceCardsHTML(_activeCategory);
}

function _renderOperatingFeed() {
  const el = document.getElementById("operating-feed");
  if (el) el.innerHTML = _operatingFeedHTML(_activeCategory);
}

function _renderFreeBookingStrip() {
  const el = document.getElementById("free-booking-strip");
  if (el) el.innerHTML = _freeBookingStripHTML(_activeCategory);
}

function _renderContextProof() {
  const trust = document.getElementById("trust-proof-section");
  if (trust) trust.innerHTML = _trustProofHTML(_activeCategory);
  const visual = document.getElementById("visual-proof-section");
  if (visual) visual.innerHTML = _visualProofHTML(_activeCategory);
}

function _renderHeroForCategory() {
  const el = document.getElementById("category-hero");
  if (el) el.innerHTML = _heroHTML(_activeCategory);
}

function _renderHeroStats() {
  const el = document.getElementById("hero-live-strip");
  if (!el) return;
  const stats = Array.isArray(_pilotConfig.hero_stats) ? _pilotConfig.hero_stats : [];
  el.innerHTML = stats.map(s => `<span><b>${_esc(s.value || "")}</b> ${_esc(s.label || "")}</span>`).join("");
  el.classList.toggle("hidden", !stats.length);
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
    el.innerHTML = UI.emptyState("📦", "Material support is secondary", "Dealer/material help appears after service scope is confirmed");
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

function _esc(str) {
  return UI.escapeHtml(str);
}

function _unwrapData(data) {
  return data?.data && typeof data.data === "object" && !Array.isArray(data.data) ? data.data : data;
}

function _servicePrice(service) {
  return service?.price ?? service?.base_price ?? service?.amount ?? service?.starting_price ?? 0;
}

function _categoryChips() {
  const dynamic = _serviceCategories.length ? _serviceCategories : [];
  // FALLBACK — use only if backend returns nothing.
  const fallback = [
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
  return dynamic.length ? _mergeCategories(dynamic, []) : _mergeCategories([], fallback);
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
  const meta = CATEGORY_META[key] || { icon: "🔧", label: _title(key), hero: `${_title(key)} services in ${_pilotConfig.city}`, subtitle: "Choose a local service and WorkToGo will confirm before visit.", examples: ["Inspection", "Repair", "Installation"], trust: "Verified local support", vendors: "Local provider assignment", inspection: true };
  return { slug: key, ...meta };
}

function _categoryEcosystemHTML(slug = "") {
  const meta = _categoryMeta(slug);
  const contextLabel = _activeContextLabel(meta);
  const visuals = meta.visuals || CATEGORY_META.all.visuals || [];
  const tags = meta.tags || meta.examples || [];
  const dealers = meta.dealers || CATEGORY_META.all.dealers || [];
  const materials = meta.materials || CATEGORY_META.all.materials || [];
  const brands = meta.brands || CATEGORY_META.all.brands || [];
  const locality = meta.locality || CATEGORY_META.all.locality || [];
  return `
    <details class="ecosystem-card ecosystem-world" ${slug ? "open" : ""}>
      <summary class="ecosystem-summary">
        <span class="ecosystem-icon">${meta.icon}</span>
        <strong>${_esc(slug ? `${meta.label} material support` : "Material support after scope")}</strong>
        <small>${_esc("Dealer help stays separate from worker execution")}</small>
      </summary>
      <div class="ecosystem-banner">
        <span class="ecosystem-icon">${meta.icon}</span>
        <div>
          <h3>${_esc(contextLabel)} material lane near ${_esc(_pilotConfig.city)}</h3>
          <p>${_esc("Worker handles service execution. Dealer support begins only after issue, quantity and scope are confirmed.")}</p>
        </div>
      </div>
      <div class="ecosystem-visual-rail">
        ${visuals.slice(0, 3).map(v => `<div class="ecosystem-info-chip"><span>${_esc(v.emoji || meta.icon)}</span><strong>${_esc(v.label)}</strong><small>${_esc(v.note || "nearby")}</small></div>`).join("")}
      </div>
      <div class="ecosystem-tag-row">
        ${tags.slice(0, 6).map(x => `<span>${_esc(x)}</span>`).join("")}
      </div>
      <div class="ecosystem-local-grid">
        <div><strong>Materials after scope</strong><span>${_esc(materials.slice(0, 3).join(" · ") || "Parts support")}</span></div>
        <div><strong>Dealer lane</strong><span>${_esc(dealers[0] || "Address + call support after worker check")}</span></div>
      </div>
    </details>`;
}

function _operatingFeedHTML(slug = "") {
  const meta = _categoryMeta(slug);
  const jobs = _contextItems(meta, "jobs", meta.examples || []).slice(0, 4);
  const places = meta.locality || CATEGORY_META.all.locality || ["nearby"];
  return `
    <div class="operating-head">
      <div>
        <h3>${_esc(_activeLocalityFilter ? `${_activeLocalityFilter} request context` : (slug ? `${meta.label} local routing` : `${_pilotConfig.city} service routing`))}</h3>
        <p>${_esc(_activeLocalityFilter ? `Requests will carry ${_activeLocalityFilter} as area context for admin assignment.` : (slug ? `${meta.label} requests are sorted by issue, area and visit mode.` : "Choose an area bubble to add local routing context. No live map or fake worker count is shown."))}</p>
      </div>
    </div>
    <div class="ops-ticker" aria-label="Nearby activity statuses">
      ${places.slice(0, 5).map((place, i) => `<button type="button" class="ops-status-pill ${_activeLocalityFilter === place ? "active" : ""}" onclick="HomePage.selectLocality('${_esc(place)}')"><strong>${_esc(place)}</strong><small>${_esc(_activeLocalityFilter === place ? "selected for routing" : (jobs[i % jobs.length] || "area context"))}</small></button>`).join("")}
    </div>`;
}

function _heroHTML(slug = "") {
  const meta = _categoryMeta(slug);
  const price = UI.formatCurrency(_inspectionPrice(null, meta));
  const primaryText = `${price} Inspection`;
  return `
    <div class="service-hero-copy">
      <p class="service-hero-kicker">${_esc(slug ? `${meta.label} operating lane` : `${_pilotConfig.city} local operating network`)}</p>
      <h1>${_esc(meta.hero || _pilotConfig.hero_title)}</h1>
      <p>${_esc(meta.subtitle || _pilotConfig.hero_subtitle)}</p>
      <div class="booking-distinction-grid">
        <div><strong>Inspection</strong><span>${_esc(`${price} · expert diagnosis first`)}</span></div>
        <div><strong>Free booking</strong><span>Nearby worker confirmation</span></div>
      </div>
      <div class="hero-cta-row">
        <button class="btn-primary marketplace-cta hero-primary" id="hero-category-cta" onclick="HomePage.bookCategoryCta('${_esc(meta.slug || "")}', 'inspection')">${_esc(primaryText)}</button>
        <button class="hero-secondary" onclick="HomePage.bookCategoryCta('${_esc(meta.slug || "")}', 'free_lead')">Free booking</button>
      </div>
    </div>`;
}

function _serviceCardsHTML(slug = "") {
  const meta = _categoryMeta(slug);
  const cards = (meta.examples?.length ? meta.examples : CATEGORY_META.all.examples).slice(0, 4);
  return `
    <div class="quick-service-rail">
      ${cards.map(name => `<button class="quick-service-card" onclick="HomePage.bookQuickService('${_esc(meta.slug || "")}', '${_esc(name)}')"><span>${meta.icon}</span><strong>${_esc(name)}</strong><small>${_esc(slug ? `${meta.label} lane` : "worker match")}</small></button>`).join("")}
    </div>`;
}

function _freeBookingStripHTML(slug = "") {
  const meta = _categoryMeta(slug);
  return `<div>
    <strong>${_esc(slug ? `Free ${meta.label} booking` : "Free booking")}</strong>
    <span>${_esc(slug ? `For clear ${meta.label.toLowerCase()} jobs: submit request, then WorkToGo confirms worker and time.` : "For clear jobs: submit request, then WorkToGo confirms nearby worker and time.")}</span>
  </div>
  <button onclick="HomePage.bookCategoryCta('${_esc(meta.slug || "")}', 'free_lead')">Request</button>`;
}

function _trustProofHTML(slug = "") {
  const meta = _categoryMeta(slug);
  return `
    <div><strong>After request</strong><span>WorkToGo checks service, area, issue note and visit mode before assignment</span></div>
    <div><strong>Worker confirmation</strong><span>${_esc(meta.vendors || "Admin assigns a suitable local worker; cards are request entry points, not live profiles")}</span></div>
    <div><strong>Payment rule</strong><span>Normal jobs pay after service; inspection is used when diagnosis or estimate is needed first</span></div>`;
}

function _visualProofHTML(slug = "") {
  const meta = _categoryMeta(slug);
  const baseProof = meta.beforeAfter || CATEGORY_META.all.beforeAfter;
  const beforeAfter = _activeChipFilter
    ? [...baseProof].sort((a, b) => Number(_proofMatches(b)) - Number(_proofMatches(a)))
    : baseProof;
  return `
    <div class="section-header">
      <div>
        <h3>Work proof</h3>
      </div>
    </div>
    <div class="proof-rail">
      ${beforeAfter.map((item, i) => `
        <div class="proof-tile proof-tone-${i % 3}">
          <div class="proof-split">
            <span>Before</span>
            <span>After</span>
          </div>
          <em>${_esc(meta.label)} proof</em>
          <strong>${_esc(item.title)}</strong>
          <p>${_esc(item.note)}</p>
        </div>
      `).join("")}
    </div>`;
}

function _proofMatches(item) {
  if (!_activeChipFilter) return false;
  return `${item.title || ""} ${item.note || ""}`.toLowerCase().includes(_activeChipFilter);
}

function _vendorCardHTML(service, support = false) {
  const meta = _categoryMeta(service.category_slug || service.slug || service.category || _activeCategory);
  const name = service.vendor_name || service.name || "Worker available after confirmation";
  const price = _servicePriceLabel(service);
  const rating = service.rating && service.rating_is_verified ? service.rating : "";
  const locality = service.locality || service.vendor_locality || service.area || meta.locality?.[0] || _pilotConfig.city;
  const exp = service.experience || "";
  const photo = service.image || service.photo || "";
  const semantics = _vendorSemantics(service, meta);
  const completed = service.completed_jobs || service.jobs_completed || service.total_jobs || service.completed || "";
  const verified = service.is_verified || service.verified || semantics.visibility !== "normal";
  const activeState = semantics.visibility === "quick" ? "Faster response lane" : "Admin assignment lane";
  return `
    <article class="vendor-card vendor-${_esc(semantics.visibility)}" data-vendor-state="${_esc(semantics.visibility)}" data-vendor-priority="${_esc(semantics.priority)}">
      <div class="vendor-media ${photo ? "has-img" : ""}">
        ${photo ? `<img src="${_esc(photo)}" alt="${_esc(name)}" loading="lazy"/>` : `<span>${service.icon || meta.icon || "🔧"}</span>`}
        <em>${_esc(verified ? "WorkToGo checked" : activeState)}</em>
      </div>
      <div class="vendor-body">
        <div class="vendor-head">
          <div class="vendor-avatar">${service.icon || meta.icon || "🔧"}</div>
            <div>
              <h4>${_esc(name)}</h4>
              <p>${[locality, "request routed by admin"].filter(Boolean).map(_esc).join(" · ")}</p>
            </div>
          </div>
          <div class="vendor-stats">
          ${rating ? `<span>★ ${_esc(rating)}</span>` : ""}
          <span>${_esc(verified ? "Checked" : meta.label)}</span>
          ${completed ? `<span>${_esc(completed)} past jobs</span>` : `<span>Assignment only</span>`}
          ${exp ? `<span>${_esc(exp)} exp</span>` : ""}
          ${price ? `<span>${_esc(price)}</span>` : ""}
        </div>
        <button class="vendor-book-btn" onclick="HomeModals.openBooking(${_jsonAttr({ ...service, request_source: "worker_card", category_slug: service.category_slug || service.slug || _activeCategory, icon: service.icon || meta.icon })})">Request via WorkToGo</button>
      </div>
    </article>`;
}

function _renderInstantSearch() {
  const panel = document.getElementById("search-results-panel");
  if (!panel) return;
  if (!_searchQuery) {
    const meta = _categoryMeta(_activeCategory);
    const suggestions = _searchSuggestionItems(meta).slice(0, 6);
    panel.classList.remove("hidden");
    panel.innerHTML = `
      <div class="instant-search-head"><strong>Search the local operating network</strong><span>ready</span></div>
      <div class="search-placeholder-grid">
        ${suggestions.map(item => `<button onclick="HomePage.searchServices('${_esc(item.query)}')"><span>${_esc(item.icon || meta.icon)}</span><strong>${_esc(item.label)}</strong><small>${_esc(item.note)}</small></button>`).join("")}
      </div>`;
    return;
  }
  const meta = _inferSearchMeta(_searchQuery);
  const candidates = (_searchRemoteServices.length ? _searchRemoteServices : _allServices).filter(s => _searchText(s).includes(_searchQuery)).slice(0, 3);
  panel.classList.remove("hidden");
  panel.innerHTML = `
    <div class="instant-search-head"><strong>${_esc(meta.label)} near ${_esc(_pilotConfig.city)}</strong><span>matching</span></div>
    <div class="instant-result-list">
      ${(candidates.length ? candidates : _categoryFallbackServices(meta.slug)).slice(0, 3).map(s => `
        <button onclick="HomePage.closeExploreOverlay(); ${s.id ? `HomeModals.openBooking(${_jsonAttr({ ...s, request_source: "search" })})` : `UI.openSupport('selector', { category: ${_jsString(meta.label)}, service: ${_jsString(s.name)} })`}">
          <span>${s.icon || meta.icon}</span><strong>${_esc(s.name)}</strong>${_servicePriceLabel(s) ? `<small>${_esc(_servicePriceLabel(s))}</small>` : ""}
        </button>
      `).join("")}
    </div>`;
}

function _setupExploreOverlay() {
  const section = document.querySelector(".market-search-section");
  const slot = document.getElementById("explore-search-slot");
  const overlay = document.getElementById("explore-overlay");
  if (!section || !slot || !overlay) return;
  slot.appendChild(section);
  section.classList.remove("top-search-hidden");
  section.removeAttribute("aria-hidden");
  overlay.addEventListener("click", e => { if (e.target === overlay) _closeExploreOverlay(); });
}

function _openExploreOverlay() {
  const overlay = document.getElementById("explore-overlay");
  if (!overlay) return;
  overlay.classList.remove("hidden");
  document.body.classList.add("explore-open");
  setTimeout(() => {
    const inp = document.getElementById("service-search");
    inp?.focus();
    _renderInstantSearch();
  }, 40);
}

function _closeExploreOverlay() {
  document.getElementById("explore-overlay")?.classList.add("hidden");
  document.body.classList.remove("explore-open");
}

function _servicePriceLabel(service) {
  if (service?.price) return String(service.price);
  const amount = service?.base_price ?? service?.amount ?? service?.starting_price;
  return amount ? UI.formatCurrency(amount) : "";
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
  const meta = _categoryMeta(wanted);
  const aliases = (meta.aliases || []).map(_slug);
  const values = [service.category_slug, service.category, service.category_name, service.name, service.description, service.short_desc, service.example, service.vendor_name, service.locality].map(_slug).filter(Boolean);
  return values.some(v => v === wanted || v.includes(wanted) || wanted.includes(v))
    || aliases.some(a => values.some(v => v === a || v.includes(a) || a.includes(v)));
}

function _searchText(service) {
  return [service.name, service.description, service.category, service.category_name, service.category_slug, service.slug, service.short_desc, service.example, service.vendor_name, service.locality].filter(Boolean).join(" ").toLowerCase();
}

function _serviceMatchesDiscovery(service, term, meta) {
  const haystack = [
    _searchText(service),
    ...(meta.examples || []),
    ...(meta.tags || []),
    ...(meta.dealers || CATEGORY_META.all.dealers || []),
    ...(meta.materials || CATEGORY_META.all.materials || []),
    ...(meta.brands || CATEGORY_META.all.brands || []),
    ...(meta.locality || CATEGORY_META.all.locality || []),
  ].join(" ").toLowerCase();
  return haystack.includes(String(term || "").toLowerCase());
}

function _contextItems(meta, key, fallback = []) {
  const all = Array.isArray(meta[key]) && meta[key].length ? meta[key] : fallback;
  if (!_activeChipFilter) return all;
  const primary = _title(_activeChipFilter);
  const filtered = all.filter(x => String(x).toLowerCase().includes(_activeChipFilter));
  return [primary, ...filtered, ...all.filter(x => !filtered.includes(x))].filter(Boolean);
}

function _activeContextLabel(meta) {
  if (_activeChipFilter && _activeDiscoveryKind) return `${_title(_activeChipFilter)} ${_title(_activeDiscoveryKind)} ${meta.label}`;
  return _activeChipFilter ? `${_title(_activeChipFilter)} ${meta.label}` : meta.label;
}

function _syncOperatingMode() {
  const page = document.querySelector(".home-page");
  if (!page) return;
  page.classList.toggle("category-operating-mode", Boolean(_activeCategory));
  document.querySelector('[data-feature="shopping-ui"]')?.classList.toggle("feature-hidden", Boolean(_activeCategory) || Boolean(CONFIG.FEATURES?.SERVICE_ONLY_MODE));
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

function _searchSuggestionItems(meta) {
  const base = meta.slug ? [meta, _categoryMeta("inspection")] : [_categoryMeta("electrician"), _categoryMeta("plumber"), _categoryMeta("painting"), _categoryMeta("waterproofing"), _categoryMeta("cctv"), _categoryMeta("carpentry")];
  const examples = (meta.examples || CATEGORY_META.all.examples || []).slice(0, 3).map(label => ({ icon: meta.icon, label, query: label, note: meta.slug ? `${meta.label} quick chip` : "trending service" }));
  return [...examples, ...base.map(c => ({ icon: c.icon, label: c.label, query: c.examples?.[0] || c.label, note: c.slug ? "nearby category" : "all services" }))];
}

function _premiumInspectionPanel(category) {
  const price = UI.formatCurrency(_inspectionPrice(null, category));
  return `<div class="premium-inspection-panel">
    <div class="premium-inspection-mark">🛡️</div>
    <div><strong>${_esc(price)} inspection</strong><p>Expert diagnosis first · scope clarity · right worker</p></div>
    <span>${_esc(price)}</span>
  </div>`;
}

function _bookingModeOption(value, label, note, selected, disabled = false, tone = "") {
  return `<button type="button" data-mode="${_esc(value)}" class="booking-mode-option booking-mode-${_esc(tone || value)} ${selected === value ? "active" : ""} ${disabled ? "disabled" : ""}" ${disabled ? "disabled aria-disabled=\"true\"" : ""} onclick="HomePage.selectBookingMode?.('${_esc(value)}') || (document.getElementById('booking-mode').value='${_esc(value)}')">
    <strong>${_esc(label)}</strong><small>${_esc(note)}</small>
  </button>`;
}

function _requestType(mode = "") {
  if (mode === "inspection") return "inspection";
  if (mode === "direct_vendor") return "direct_worker";
  return "free_match";
}

function _bookingModeMeaning(mode = "") {
  if (mode === "inspection") return "Inspection: technician checks the issue first, then final work is confirmed";
  if (mode === "direct_vendor") return "Direct worker request: WorkToGo asks the selected worker and confirms availability";
  return "Free match: WorkToGo starts nearby worker matching for a clear job";
}

function _initialTrackingState(mode = "") {
  if (mode === "inspection") return "payment_pending";
  if (mode === "direct_vendor") return "worker_requested";
  return "request_received";
}

function _sessionContext() {
  return {
    home_state: _safeSessionJSON("wtg_home_state"),
    pending_booking_form: _safeSessionJSON("wtg_pending_booking_form"),
    active_category: _activeCategory,
    selected_issue: _activeChipFilter,
    selected_locality: _activeLocalityFilter,
    discovery_kind: _activeDiscoveryKind,
    search_query: _searchQuery,
  };
}

function _safeSessionJSON(key) {
  try { return JSON.parse(sessionStorage.getItem(key) || "{}"); } catch { return {}; }
}

function _adminRequestNotes(payload = {}) {
  const worker = payload.selected_worker?.vendor_id ? `${payload.selected_worker.vendor_name || "Selected worker"} (#${payload.selected_worker.vendor_id})` : "Not selected";
  return [
    `Request type: ${payload.request_type}`,
    `Category: ${payload.category_label || payload.category}`,
    `Subservice: ${payload.subservice || "Not specified"}`,
    `Booking mode: ${payload.booking_mode}`,
    `Booking mode meaning: ${payload.booking_mode_label}`,
    `Tracking state: ${payload.tracking_state}`,
    `Request source: ${payload.request_source}`,
    `Customer: ${payload.customer?.name || "WorkToGo Customer"}`,
    `Mobile: ${payload.customer?.phone || ""}`,
    `Locality: ${payload.locality || ""}`,
    `Selected nearby area: ${payload.selected_nearby_area || ""}`,
    `Address: ${payload.full_address || ""}`,
    `Preferred time: ${payload.preferred_time || ""}`,
    `Selected worker: ${worker}`,
    `Issue note: ${payload.issue_note || ""}`,
  ].filter(Boolean).join("\n");
}

function _canonicalBookingMode(value, service, category) {
  const mode = String(value || "").toLowerCase();
  if (mode === "inspection") return "inspection";
  if (mode === "direct_vendor" && service?.vendor_id) return "direct_vendor";
  return "free_lead";
}

function _customerProfile() {
  return UI.customerProfile();
}

function _inspectionPrice(service = null, category = null) {
  return Number(service?.inspection_price ?? category?.inspection_price ?? _pilotConfig.inspection_price ?? CONFIG.SERVICE_ONLY?.INSPECTION_PRICE ?? 299);
}

function _persistCustomerProfile(profile) {
  try {
    const current = _customerProfile();
    localStorage.setItem("wtg_customer_profile", JSON.stringify({ ...current, ...profile }));
  } catch {}
}

function _vendorSemantics(service, meta) {
  const raw = String(service.vendor_visibility || service.visibility || service.vendor_state || "").toLowerCase();
  const featured = Boolean(service.is_featured || service.featured);
  const trusted = Boolean(service.is_trusted || service.trusted || Number(service.rating || 0) >= 4.7);
  const demand = Boolean(service.demand_priority || service.priority === "demand");
  const live = false;
  const quick = Boolean(service.quick_response || service.fast_response);
  const visibility = live ? "live" : featured ? "featured" : trusted ? "trusted" : demand ? "demand-priority" : quick ? "quick" : "normal";
  const badgeMap = { live: "WorkToGo lane", featured: "Featured", trusted: "Trusted", "demand-priority": "High demand", quick: "Faster response", normal: "" };
  return { visibility, priority: demand ? "demand" : "normal", badge: badgeMap[visibility] || badgeMap.normal };
}

function _restoreHomeState() {
  try {
    const state = JSON.parse(sessionStorage.getItem("wtg_home_state") || "{}");
    _activeCategory = state.category || _activeCategory || "";
    _activeChipFilter = state.chip || "";
    _activeLocalityFilter = state.locality || "";
    _activeDiscoveryKind = state.discovery || "";
    _searchQuery = state.query || "";
  } catch {}
}

function _persistHomeState() {
  try {
    sessionStorage.setItem("wtg_home_state", JSON.stringify({ category: _activeCategory, chip: _activeChipFilter, locality: _activeLocalityFilter, discovery: _activeDiscoveryKind, query: _searchQuery }));
  } catch {}
}

function _pendingBookingForm() {
  try { return JSON.parse(sessionStorage.getItem("wtg_pending_booking_form") || "{}"); } catch { return {}; }
}

function _persistPendingBookingForm() {
  try {
    if (!document.getElementById("booking-service-context")) return;
    sessionStorage.setItem("wtg_pending_booking_form", JSON.stringify({
      booking_mode: document.getElementById("booking-mode")?.value || "",
      service_context: document.getElementById("booking-service-context")?.value?.trim() || "",
      scheduled_at: document.getElementById("booking-date")?.value || "",
      name: document.getElementById("booking-name")?.value?.trim() || "",
      phone: document.getElementById("booking-mobile")?.value?.trim() || "",
      locality: document.getElementById("booking-area")?.value?.trim() || "",
      address: document.getElementById("booking-address")?.value?.trim() || "",
      notes: document.getElementById("booking-notes")?.value?.trim() || "",
    }));
  } catch {}
}

function _clearPendingBookingForm() {
  try { sessionStorage.removeItem("wtg_pending_booking_form"); } catch {}
}

function _clearPendingBookingIntent() {
  try {
    sessionStorage.removeItem("wtg_pending_booking");
    sessionStorage.removeItem("wtg_resume_booking_once");
  } catch {}
}

function _friendlyServiceError(error = "") {
  const msg = String(error || "").toLowerCase();
  if (msg.includes("internal server") || msg.includes("500")) return "Live worker list is being coordinated.";
  if (msg.includes("network") || msg.includes("failed")) return "Worker matching can continue through confirmation.";
  return "Worker matching is available through booking confirmation.";
}

function _savePendingBookingIntent(service, form = {}) {
  if (!service) return;
  try {
    const category = service.category_slug || service.category || _activeCategory || "";
    sessionStorage.setItem("wtg_pending_booking", JSON.stringify({ service: { ...service, category_slug: category }, category, form, ts: Date.now() }));
  } catch {}
}

function _resumePendingBooking() {
  if (!AUTH.isLoggedIn()) return;
  try {
    const raw = sessionStorage.getItem("wtg_pending_booking");
    if (!raw) return;
    if (sessionStorage.getItem("wtg_resume_booking_once") !== "1") return;
    const pending = JSON.parse(raw);
    if (!pending?.service || Date.now() - Number(pending.ts || 0) > 30 * 60 * 1000) return;
    if (pending.form) sessionStorage.setItem("wtg_pending_booking_form", JSON.stringify(pending.form));
    if (pending.form?.locality) _activeLocalityFilter = pending.form.locality;
    if (pending.category) {
      _activeCategory = pending.category;
      _renderCategoryChips();
      _renderCategoryEcosystem();
      _renderOperatingFeed();
      _renderHeroForCategory();
      _syncOperatingMode();
    }
    const current = pending.service.id ? _allServices.find(s => String(s.id) === String(pending.service.id)) : null;
    setTimeout(() => {
      try { sessionStorage.removeItem("wtg_resume_booking_once"); } catch {}
      HomeModals.openBooking(current || pending.service);
    }, 350);
  } catch {}
}

function _categoryFallbackServices(slug) {
  const meta = _categoryMeta(slug);
  return (meta.examples || []).slice(0, 4).map(name => ({ slug, icon: meta.icon, name, example: `${meta.label} local request` }));
}

function _slug(v = "") { return String(v || "").toLowerCase().trim().replace(/&/g, "and").replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, ""); }
function _title(v = "") { return String(v || "service").replace(/-/g, " ").replace(/\b\w/g, m => m.toUpperCase()); }
function _jsString(v = "") { return JSON.stringify(String(v || "")); }

function _fallbackServices() {
  return [
    { slug: "electrician", icon: "⚡", name: "Electrician", example: "Fan, switch, MCB repair" },
    { slug: "plumber", icon: "🚰", name: "Plumber", example: "Leakage, tap, fitting" },
    { slug: "ac-repair", icon: "❄️", name: "AC Repair", example: "Service and checkup" },
    { slug: "cleaning", icon: "🧹", name: "Cleaning", example: "Home/shop basic cleaning" },
    { slug: "appliance", icon: "🔧", name: "Appliance Repair", example: "Fridge, washer, RO check" },
    { slug: "custom", icon: "💬", name: "Other local help", example: "Ask WorkToGo support" },
  ];
}

// Safe JSON embed for onclick attribute — encode as single-quoted JS object
function _jsonAttr(obj) {
  return "JSON.parse(decodeURIComponent('" + encodeURIComponent(JSON.stringify(obj)) + "'))";
}

let _pilotLoaded = false;
let _activeCategory = "";
let _searchQuery = "";
let _activeChipFilter = "";
let _activeLocalityFilter = "";
let _activeDiscoveryKind = "";
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
  all: { slug: "", icon: "🧰", label: "All services", hero: "Trusted Haldwani Services", subtitle: "Nearby verified workers for repairs, painting, waterproofing, CCTV and home jobs.", ecosystemTitle: "Local workers available now", examples: ["Electrician", "Plumber", "Painting", "CCTV"], tags: ["fan", "leakage", "painter", "CCTV", "carpenter", "waterproofing"], visuals: [{ emoji: "⚡", label: "Fan fixed", note: "from ₹199" }, { emoji: "🎨", label: "Room painted", note: "estimate visit" }, { emoji: "💧", label: "Leak stopped", note: "inspection" }], beforeAfter: [{ title: "Damp wall restored", note: "Seepage inspection, repair and repaint flow" }, { title: "Old room refresh", note: "Putty, primer and clean finish by local painters" }], dealers: ["Mukhani", "Kusumkhera", "Kaladhungi Road"], materials: ["repair parts", "paint", "pipes"], brands: ["Asian", "Dr Fixit", "Havells"], locality: ["Mukhani", "Dahariya", "Lalpur Nayak"], trust: "Local coordination · pay after service · human confirmation", vendors: "Verified local provider network", inspection: true },
  electrician: { icon: "⚡", label: "Electrical", aliases: ["electrical", "electrician", "wiring"], hero: "Electrical workers near you", subtitle: "Fan, switch, MCB, wiring and light installation with quick local confirmation.", examples: ["Fan repair", "Switch board", "MCB issue", "Light installation"], tags: ["fan", "switch", "MCB", "wiring", "geyser", "inverter"], visuals: [{ emoji: "🌀", label: "Fan repair", note: "from ₹199" }, { emoji: "🔌", label: "Switch board", note: "same day" }, { emoji: "💡", label: "Lights", note: "install" }], beforeAfter: [{ title: "Dead fan running", note: "Local electrician visit with quick diagnosis" }, { title: "Unsafe board cleaned", note: "Switch replacement and wiring check" }], dealers: ["Havells point", "Kaladhungi Road electrical", "Mukhani hardware"], materials: ["switch", "MCB", "wire", "fan capacitor"], brands: ["Havells", "Anchor", "Polycab", "Syska"], locality: ["Mukhani", "Kusumkhera", "Nainital Road"], trust: "Safety-first local electricians · pay after service", vendors: "Electrician provider assignment", inspection: false },
  plumber: { icon: "🚰", label: "Plumbing", aliases: ["plumber", "plumbing", "pipe", "tap"], hero: "Plumbers for leakage and fittings", subtitle: "Tap, pipe, bathroom and kitchen plumbing help from nearby workers.", examples: ["Leakage repair", "Tap fitting", "Pipe blockage", "Bathroom fitting"], tags: ["tap leak", "pipe", "flush", "basin", "bathroom", "motor"], visuals: [{ emoji: "🚿", label: "Tap leak", note: "from ₹199" }, { emoji: "🧰", label: "Pipe fix", note: "nearby" }, { emoji: "🚽", label: "Bathroom", note: "fitting" }], beforeAfter: [{ title: "Leakage stopped", note: "Tap and joint repair by local plumber" }, { title: "Bathroom fitting done", note: "Clear scope confirmation before visit" }], dealers: ["Sanitary market", "Mukhani hardware", "Rampur Road pipes"], materials: ["CPVC pipe", "flush kit", "tap", "basin waste"], brands: ["Jaquar", "Astral", "Ashirvad", "Supreme"], locality: ["Kusumkhera", "Dahariya", "Mukhani"], trust: "Local plumbers · clear visit confirmation", vendors: "Plumber provider assignment", inspection: false },
  painting: { icon: "🎨", label: "Painting", aliases: ["paint", "painter", "painting"], hero: "Painting ecosystem for homes and shops", subtitle: "Painters, wall textures, putty, before/after work and expert visit for estimates.", examples: ["Room painting", "Wall putty", "Exterior painting", "Color consultation"], tags: ["texture", "putty", "primer", "room paint", "exterior", "rental repaint"], visuals: [{ emoji: "🧱", label: "Wall texture", note: "trending" }, { emoji: "🏠", label: "Room paint", note: "quote" }, { emoji: "🪣", label: "Putty repair", note: "before/after" }], beforeAfter: [{ title: "Bedroom repaint", note: "Old patches to clean warm finish" }, { title: "Texture wall upgrade", note: "Accent wall with painter estimate" }, { title: "Exterior refresh", note: "Weather coat and crack prep" }], dealers: ["Paint shop Mukhani", "Kusumkhera colors", "Nainital Road paint"], materials: ["putty", "primer", "emulsion", "texture"], brands: ["Asian Paints", "Nerolac", "Berger", "Dulux"], locality: ["Mukhani", "Lalpur Nayak", "Kaladhungi Road"], trust: "Site inspection option · local painters · estimate before work", vendors: "Painting teams and local contractors", inspection: true },
  waterproofing: { icon: "💧", label: "Waterproofing", aliases: ["waterproofing", "leakage", "seepage", "damp"], hero: "Leakage and seepage protection", subtitle: "Terrace, wall seepage, bathroom leakage and monsoon protection with inspection offers.", examples: ["Roof seepage", "Wall dampness", "Bathroom leakage", "Crack sealing"], tags: ["terrace", "seepage", "monsoon", "bathroom leak", "damp wall", "crack seal"], visuals: [{ emoji: "🌧️", label: "Monsoon cover", note: "inspection" }, { emoji: "🏚️", label: "Damp wall", note: "diagnosis" }, { emoji: "🧪", label: "Coating", note: "terrace" }], beforeAfter: [{ title: "Terrace leakage sealed", note: "Inspection-led waterproof coating" }, { title: "Seepage wall treated", note: "Dampness source checked before repair" }, { title: "Bathroom leak fixed", note: "Joint sealing and slope check" }], dealers: ["Waterproofing dealer Mukhani", "Paint chemical shop", "Hardware Kaladhungi Road"], materials: ["roof coat", "crack filler", "membrane", "sealant"], brands: ["Dr Fixit", "Sika", "Asian SmartCare", "Nerolac"], locality: ["Dahariya", "Kusumkhera", "Mukhani"], trust: "Inspection-led scope · local repair teams", vendors: "Waterproofing specialists", inspection: true },
  cctv: { icon: "📹", label: "CCTV", hero: "CCTV installation near you", subtitle: "Camera setup, wiring, DVR/NVR, shop and home security visits by local technicians.", examples: ["Camera install", "DVR setup", "Wiring", "Shop security"], tags: ["camera", "DVR", "NVR", "home CCTV", "shop CCTV", "wiring"], visuals: [{ emoji: "📹", label: "Camera install", note: "quote" }, { emoji: "🖥️", label: "DVR setup", note: "fast" }, { emoji: "🏪", label: "Shop CCTV", note: "nearby" }], beforeAfter: [{ title: "Shop camera live", note: "Camera angle and DVR configured" }, { title: "Home entry covered", note: "Wiring and mobile view setup" }], trust: "Security technician confirmation · clear install scope", vendors: "CCTV installers", inspection: true },
  carpentry: { icon: "🪚", label: "Carpentry", hero: "Carpenters for repair and renovation", subtitle: "Door, wardrobe, modular fixes, polish and furniture repair from local carpenters.", examples: ["Door repair", "Wardrobe", "Furniture fix", "Polish work"], tags: ["door", "wardrobe", "hinge", "modular", "polish", "furniture"], visuals: [{ emoji: "🚪", label: "Door repair", note: "from ₹249" }, { emoji: "🪵", label: "Furniture", note: "fix" }, { emoji: "🧱", label: "Wardrobe", note: "quote" }], beforeAfter: [{ title: "Door alignment fixed", note: "Hinge repair and smooth closing" }, { title: "Furniture restored", note: "Polish and repair work proof" }, { title: "Wardrobe repair", note: "Local carpenter estimate and visit" }], trust: "Local carpenters · inspection for custom work", vendors: "Carpentry workers", inspection: true },
  cleaning: { icon: "🧹", label: "Cleaning", hero: "Cleaning services in Haldwani", subtitle: "Home, shop, kitchen and deep cleaning requests with local coordination.", examples: ["Home cleaning", "Kitchen cleaning", "Shop cleaning", "Move-in cleaning"], trust: "Clear scope confirmation · pay after service", vendors: "Cleaning partners", inspection: false },
  "ac-repair": { icon: "❄️", label: "AC repair", hero: "AC service and repair", subtitle: "AC checkup, service, cooling issue and installation support with verified local help.", examples: ["AC service", "Cooling issue", "Gas check", "Installation"], trust: "Technician confirmation · pay after service", vendors: "AC technicians", inspection: true },
  appliance: { icon: "🔧", label: "Appliance", hero: "Appliance repair support", subtitle: "Fridge, washing machine, RO and common appliance checks coordinated locally.", examples: ["RO service", "Washer issue", "Fridge check", "Geyser repair"], trust: "Diagnosis-first support · local technicians", vendors: "Appliance technicians", inspection: true },
  tutor: { icon: "📚", label: "Tutor", hero: "Local tutor requests", subtitle: "Tell WorkToGo your class, subject and area. Team will help connect locally.", examples: ["Math tutor", "Science tutor", "Home tuition", "Spoken English"], trust: "Manual matching during pilot", vendors: "Local tutor coordination", inspection: false },
  inspection: { icon: "🛡️", label: "Inspection", hero: "Premium inspection before big work", subtitle: "For painting, waterproofing, AC, appliance and complex jobs where a site check builds trust.", examples: ["Site visit", "Problem diagnosis", "Estimate support", "Scope clarity"], trust: "Clear visit · clear scope · better estimate", vendors: "Specialist inspection coordination", inspection: true },
};
