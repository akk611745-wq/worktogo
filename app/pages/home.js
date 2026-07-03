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
  _restoreLocalityContext();

  container.innerHTML = `
    <div class="page home-page">
      <header class="top-bar marketplace-top-bar">
        <button class="location-pill" onclick="HomePage.openLocalitySelector()" aria-label="Change service locality">
          <span>📍</span>
          <strong id="home-locality-label">${_esc(_resolvedLocality().label)}</strong>
          <small id="home-locality-status">near you</small>
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
          <div class="category-chips-wrap">
            <div class="category-chips" id="category-chips">
              <button class="active" onclick="HomePage.setCategory('')"><span>🧰</span>All</button>
              ${_categoryChips().slice(0, 7).map(c => `<button onclick="HomePage.setCategory('${_esc(c.slug || '')}')"><span>${c.icon}</span>${_esc(c.label)}</button>`).join("")}
            </div>
          </div>
        </section>

        <section class="service-hero marketplace-hero" id="category-hero" data-pattern="${_esc(_activeCategory || "default")}">
          ${_heroHTML(_activeCategory)}
        </section>

        <section class="quick-services-section" id="quick-services-section">
          ${_serviceCardsHTML(_activeCategory)}
        </section>

        <section class="operating-feed" id="operating-feed">
          ${_operatingFeedHTML(_activeCategory)}
        </section>

        <section class="home-section near-me-vendors-section" id="services-section">
          <div class="section-header">
            <div>
              <h3 id="vendor-feed-title">Near Me Vendors</h3>
            </div>
            <button class="see-all" onclick="HomePage.setCategory('')">All</button>
          </div>
          <div id="services-grid" class="vendor-feed" style="display:block;width:100%">
            ${UI.skeleton(4, "card")}
          </div>
        </section>

        <section class="category-ecosystem" id="category-ecosystem" style="display:none">
          ${_categoryEcosystemHTML(_activeCategory)}
        </section>

        <section class="local-proof-grid marketplace-proof-grid" id="trust-proof-section" style="display:none">
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

    <div id="locality-modal" class="modal-overlay hidden locality-modal" onclick="HomePage.closeLocalitySelector(event)" role="dialog" aria-modal="true" aria-label="Choose service locality">
      <div class="modal-sheet locality-sheet" style="max-height:60vh;overflow-y:auto;">
        <div class="modal-handle"></div>
        <h3>Change area</h3>
        <div id="locality-selector-body">${_localitySelectorHTML()}</div>
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
      <div class="modal-sheet booking-bottom-sheet">
        <div class="modal-handle"></div>
        <button class="modal-close-icon" type="button" onclick="HomeModals.closeBooking()" aria-label="Close inspection request">×</button>
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

  window.HomePage = {
    scrollToServices() {
      document.getElementById("services-section")?.scrollIntoView({ behavior: "smooth", block: "start" });
    },
    toggleHeroInfoCard(key = "") {
      const grid = document.getElementById("hero-distinction-grid");
      if (!grid) return;
      const btn = grid.querySelector(`[data-key="${key}"]`);
      if (!btn) return;
      const willOpen = !btn.classList.contains("expanded");
      grid.querySelectorAll(".distinction-toggle").forEach(b => {
        b.classList.remove("expanded");
        b.setAttribute("aria-expanded", "false");
      });
      if (willOpen) {
        btn.classList.add("expanded");
        btn.setAttribute("aria-expanded", "true");
      }
      const note = document.getElementById("hero-distinction-note");
      if (note) {
        note.textContent = key === "inspection" ? "Unclear issue? choose inspection." : "Clear job? choose free booking.";
        note.style.display = willOpen ? "block" : "none";
      }
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
    openLocalitySelector() {
      _openLocalitySelector();
    },
    closeLocalitySelector(event) {
      if (event && event.target !== event.currentTarget) return;
      _closeLocalitySelector();
    },
    chooseLocality(locality = "", source = "selected") {
      _setManualLocality(locality, source);
    },
    chooseCity(city = "") {
      _setManualCity(city);
    },
    filterLocalitySuggestions(query = "") {
      _renderLocalitySelector(query);
      _searchPlacesAsync(query);
    },
    async submitLocalitySearch() {
      const input = document.getElementById("locality-manual-input");
      const value = input?.value?.trim() || "";
      if (!value) return;
      await _geocodeAndSaveTyped(value);
    },
    async saveTypedLocality() {
      const input = document.getElementById("locality-manual-input");
      const value = input?.value?.trim() || "";
      if (!value) { UI.toast("Type an area or choose a nearby area", "error"); return; }
      await _geocodeAndSaveTyped(value);
    },
    clearLocalitySelection() {
      _clearManualLocality();
    },
    useBrowserLocalityHint() {
      _useBrowserLocalityHint();
    },
    detectGPSLocality() {
      _detectGPSLocality();
    },
    onBookingAreaInput(value = "") {
      _commitTypedLocality(value);
      _persistPendingBookingForm();
    },
    setCategory(slug = "") {
      HomeModals.discardBookingDraft?.("category_change");
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
      _renderContextProof();
      _renderServices({ ok: true, data: { services: _allServices } });
      _syncOperatingMode();
      _persistHomeState();
    },
    filterEcosystem(term = "") {
      HomeModals.discardBookingDraft?.("discovery_change");
      _activeChipFilter = String(term || "").trim().toLowerCase();
      _searchQuery = "";
      _searchRemoteServices = [];
      const inp = document.getElementById("service-search");
      if (inp) inp.value = "";
      _renderInstantSearch();
      _renderCategoryEcosystem();
      _renderOperatingFeed();
      _renderHeroForCategory();
      _renderQuickServiceCards();
      _renderContextProof();
      _renderServices({ ok: true, data: { services: _allServices } });
      document.getElementById("services-section")?.scrollIntoView({ behavior: "smooth", block: "nearest" });
      _persistHomeState();
    },
    selectLocality(locality = "") {
      _setManualLocality(locality, "selected", { silent: true, keepModalOpen: true });
      _activeChipFilter = "";
      _activeDiscoveryKind = "locality";
      UI.toast(_activeLocalityFilter ? `${_activeLocalityFilter} near you` : "Area cleared", "info");
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
    viewMaterialNetwork(kind = "materials") {
      if (CONFIG.SERVICE_ONLY?.DISABLE_MATERIAL_ACTIONS !== false) {
        UI.toast("Material help starts after worker confirms scope.", "info");
        return;
      }
      const meta = _categoryMeta(_activeCategory);
      const source = kind === "dealers" ? (meta.dealers || CATEGORY_META.all.dealers || []) : (meta.materials || CATEGORY_META.all.materials || []);
      const seed = source[0] || meta.label;
      _activeDiscoveryKind = kind;
      HomePage.filterEcosystem(seed);
      document.getElementById("services-section")?.scrollIntoView({ behavior: "smooth", block: "nearest" });
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
    selectInspectionCategory(slug = "") {
      HomeModals.selectInspectionCategory?.(slug);
    },
    selectInspectionIssue(issue = "") {
      HomeModals.selectInspectionIssue?.(issue);
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
          _renderInstantSearch();
          return;
        }
        const res = await API.search(_searchQuery, "services", 20).catch(() => null);
        if (res?.ok) {
          const payload = _unwrapData(res.data);
          _searchRemoteServices = (payload?.results?.services || []).map(_normalizeSearchService);
          _renderServices({ ok: true, data: { services: _allServices } });
          _renderInstantSearch();
        }
      }, 260);
      _renderServices({ ok: true, data: { services: _allServices } });
      _renderInstantSearch();
      _persistHomeState();
    },
    clearSearch() {
      clearTimeout(_searchTimer);
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
          HomeModals.openBooking({ ...inspection, booking_mode: mode || "inspection", request_source: source, intake_category_required: true, category_slug: "", category: "", icon: inspection.icon || "🛡️" });
          return;
        }
      }
      const service = _allServices.find(s => _matchesCategory(s, meta.slug) && (!_activeChipFilter || _searchText(s).includes(_activeChipFilter)))
        || _allServices.find(s => _matchesCategory(s, meta.slug));
      if (service?.id) {
        HomeModals.openBooking({ ...service, booking_mode: mode || service.booking_mode, request_source: source, category_slug: service.category_slug || meta.slug, icon: service.icon || meta.icon });
        return;
      }
      const fallbackCategory = meta.slug || "";
      const fallbackService = _allServices.find(s => fallbackCategory ? _matchesCategory(s, fallbackCategory) : _matchesCategory(s, "inspection")) || _allServices[0] || {};
      const fallbackId = fallbackService.id || `intake-${mode || "free_lead"}-${fallbackCategory || "all"}`;
      HomeModals.openBooking({
        ...fallbackService,
        id: fallbackId,
        name: mode === "inspection" ? "Inspection" : "Free booking",
        booking_mode: mode || "free_lead",
        request_source: source,
        category_slug: fallbackCategory,
        category: fallbackCategory,
        icon: meta.icon || "🔧",
      });
    },
    bookQuickService(slug = "", serviceName = "") {
      const meta = _categoryMeta(slug || _activeCategory);
      const match = _allServices.find(s => _matchesCategory(s, meta.slug) && _searchText(s).includes(String(serviceName || "").toLowerCase()))
        || _allServices.find(s => _matchesCategory(s, meta.slug));
      if (match?.id) {
        HomeModals.openBooking({ ...match, booking_mode: "free_lead", request_source: "service_chip", category_slug: match.category_slug || meta.slug, category: match.category || meta.slug, icon: match.icon || meta.icon, quick_service: serviceName, selected_issues: [serviceName].filter(Boolean) });
        return;
      }
      _activeChipFilter = String(serviceName || "").trim().toLowerCase();
      HomeModals.openBooking({ id: `quick-${meta.slug || "all"}-${_slug(serviceName)}`, name: serviceName || meta.examples?.[0] || meta.label, booking_mode: "free_lead", request_source: "service_chip", category_slug: meta.slug || "", category: meta.slug || "", icon: meta.icon || "🔧", quick_service: serviceName, selected_issues: [serviceName].filter(Boolean) });
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
  _setupMobileChromeMotion();
  _syncOperatingMode();
  _renderInstantSearch();
  _restoreInspectionPaymentReturn();
  _maybeAutoDetectLocality();
}

// ── Modal Controller ────────────────────────────────────────────────────────

window.HomeModals = (() => {
  let _currentProduct = null;
  let _currentService = null;
  let _isBookingSubmitting = false;
  let _bookingOpenToken = 0;
  let _forceNewBooking  = false; // set true when user confirms "book new anyway"
  let _swipeCleanup = null;

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
      <div class="booking-context-strip order-locality-strip">
        <span>📍</span>
        <strong>${_esc(_localityRequestLine())}</strong>
        <small>${_esc(_localitySourceLine())} · address remains exactly as typed</small>
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
      notes: [notes || "", `Locality context: ${_resolvedLocality().label}`, `Locality source: ${_resolvedLocality().source}`].filter(Boolean).join("\n")
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
    const localityContext = _resolvedLocality();
    const intendedMode = _canonicalBookingMode(service.booking_mode || (service.vendor_id ? "direct_vendor" : "free_lead"), service, category);
    const savedInspectionPayment = intendedMode === "inspection" ? _readInspectionPaymentReturn() : null;
    if (savedInspectionPayment?.booking && _normalizePaymentState(savedInspectionPayment.status_state || savedInspectionPayment.booking.payment_status) !== "paid") {
      const _savedPayState = _normalizePaymentState(savedInspectionPayment.status_state || savedInspectionPayment.booking.payment_status);
      _currentService = null;
      _showInspectionVerificationState(savedInspectionPayment, { forceOpen: true });
      if (_savedPayState !== "failed" && _savedPayState !== "cancelled") _continueInspectionVerification(savedInspectionPayment);
      return;
    }
    _currentService = {
      ...service,
      category_slug: category.slug || _activeCategory || service.category_slug || service.category || "",
      category_label: category.label,
      selected_service: selectedService,
      request_source: service.request_source || "category",
    };
    const restored = _pendingBookingForm();
    const sameRestoredContext = _sameBookingContext(restored, selectedService, category);
    const defaultMode = _canonicalBookingMode(service.booking_mode || (sameRestoredContext ? restored.booking_mode : "") || (service.vendor_id ? "direct_vendor" : "free_lead"), _currentService, category);
    const inspectionPrice = _inspectionPrice(_currentService, category);
    _resetBookingActions(defaultMode, inspectionPrice);
    const modalTitleCategory = category.slug ? category.label : "Service";
    const isVendorDirect = service.request_source === "worker_card";
    const vendorDisplayName = _currentService.vendor_name || _currentService.name || category.label;
    document.getElementById("booking-modal-title").textContent = isVendorDirect
      ? `Book ${vendorDisplayName}`
      : defaultMode === "inspection" ? `${modalTitleCategory} Expert Visit` : defaultMode === "free_lead" ? `Book ${modalTitleCategory}` : `Book ${category.label}`;
    document.getElementById("booking-modal-body").innerHTML = isVendorDirect
      ? _vendorDirectFormHTML({ service: _currentService, category, selectedService, restored, profile })
      : (defaultMode === "inspection" || defaultMode === "free_lead") ? _intakeRequestHTML({ mode: defaultMode, service: _currentService, category, selectedService, restored, sameRestoredContext, inspectionPrice }) : `
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
        <small>${_esc(category.label)} context · ${_esc(localityContext.label)} routing · WorkToGo confirms worker and timing</small>
      </div>
      <div class="booking-context-strip locality-routing-strip">
        <span>📍</span>
        <strong>${_esc(_localityRequestLine())}</strong>
        <small>${_esc(_localitySourceLine())} · edit Area/Landmark only if this request is for another area</small>
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
        <input type="text" id="booking-area" class="modal-input" placeholder="e.g. Mukhani, Kusumkhera, near canal road" autocomplete="address-level2" value="${_esc((sameRestoredContext && restored.locality) || localityContext.label || "")}" oninput="HomePage.onBookingAreaInput?.(this.value)" />
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
      <input type="hidden" id="booking-category-context" value="${_esc(category.slug || _activeCategory || "")}" />
      <p class="service-note">After submission, admin sees work, area, urgency, mode and service context for assignment.</p>
    `;
    if (token !== _bookingOpenToken) return;
    document.getElementById("btn-confirm-booking")?.classList.remove("loading");
    const modal = document.getElementById("booking-modal");
    modal?.classList.remove("hidden");
    modal?.querySelector(".modal-sheet")?.scrollTo({ top: 0, behavior: "instant" });
    _lockModalBody("booking");
    _bindSwipeToClose(modal?.querySelector(".modal-sheet"));
    _persistPendingBookingForm();
    _attachAddressAutocomplete();
  }

  async function confirmBooking() {
    if (_isBookingSubmitting) return;
    let bookingMode = _canonicalBookingMode(document.getElementById("booking-mode")?.value, _currentService, _categoryMeta(document.getElementById("booking-category-context")?.value || _activeCategory));
    if (bookingMode === "inspection") _syncCurrentServiceForCategory(document.getElementById("booking-category-context")?.value || _activeCategory);
    if (!AUTH.isLoggedIn()) {
      const preName = document.getElementById("booking-name")?.value?.trim() || "";
      const preMobile = document.getElementById("booking-mobile")?.value?.trim() || "";
      const preDigits = preMobile.replace(/\D/g, "");
      if (!preName) { _markInvalid("booking-name", "Please enter name"); return; }
      if (preDigits.length !== 10 || !/^[6-9]/.test(preDigits)) { _markInvalid("booking-mobile", "Please enter a valid 10-digit mobile number"); return; }
      _persistCustomerProfile({ name: preName, phone: preDigits });
      UI.toast("Verify mobile once to send request", "info");
      const pendingArea = document.getElementById("booking-area")?.value?.trim() || _activeLocalityFilter;
      if (pendingArea) _commitTypedLocality(pendingArea);
      _persistHomeState();
      _persistLocalityContext();
      _savePendingBookingIntent(_currentService, _pendingBookingForm());
      closeBooking();
      ROUTER.go("login");
      return;
    }
    if (!_currentService) return;
    const dateField = document.getElementById("booking-date");
    let dateVal = dateField?.value;
    const name    = document.getElementById("booking-name")?.value?.trim() || "";
    const mobile  = document.getElementById("booking-mobile")?.value?.trim() || "";
    const area    = document.getElementById("booking-area")?.value?.trim() || _resolvedLocality().label || "";
    const address = document.getElementById("booking-address")?.value?.trim() || "";
    const notes   = document.getElementById("booking-notes")?.value?.trim() || "";
    const issueList = _normalizeIssueList(document.getElementById("booking-service-context")?.value || _currentService.selected_issues || _currentService.quick_service || _currentService.name || "");
    const serviceContext = issueList.join(", ") || _currentService.quick_service || _currentService.name || "";
    const issueSummary = _issueSummary(issueList.length ? issueList : serviceContext);
    const submittedCategorySlug = document.getElementById("booking-category-context")?.value || _currentService.category_slug || _currentService.category || _activeCategory || _inspectionCategorySlug("");
    const category = _categoryMeta(submittedCategorySlug);
    bookingMode = _canonicalBookingMode(document.getElementById("booking-mode")?.value, _currentService, category);
    // Vendor-card "BOOK NOW" (direct_vendor) is the only entry point that
    // should bind to one specific vendor's services.id — resolved via that
    // vendor's service_map ({categoryName: serviceId}, built server-side by
    // GET /api/services). "₹299 Inspection" and "Free booking" haven't had
    // a vendor chosen at all (admin assigns one later, after inspection for
    // the paid flow / manually for free_lead), so they must NOT resolve a
    // service through whichever vendor's card _currentService happens to
    // have been merged from (_syncCurrentServiceForCategory picks one
    // arbitrarily for display purposes only). Leaving service_id unresolved
    // here and sending category_slug instead lets the backend pick a
    // service for that category on its own — see POST /api/service/request.
    // Vendor-only cards (is_vendor_only) have no real services.id — their
    // _currentService.id is a "vendor-N" placeholder used only for DOM/list
    // matching. Sending that as service_id 404s, so leave it unresolved and
    // rely on vendor_id (sent separately below) for the backend to resolve
    // a real service itself.
    const resolvedServiceId = bookingMode === "direct_vendor"
      ? ((_currentService.service_map && _currentService.service_map[category.label]) || _currentService.service_id || (_currentService.is_vendor_only ? null : _currentService.id))
      : null;
    const savedInspectionPayment = bookingMode === "inspection" ? _readInspectionPaymentReturn() : null;
    if (savedInspectionPayment?.booking && _normalizePaymentState(savedInspectionPayment.status_state || savedInspectionPayment.booking.payment_status) !== "paid") {
      const _savedPayState = _normalizePaymentState(savedInspectionPayment.status_state || savedInspectionPayment.booking.payment_status);
      _showInspectionVerificationState(savedInspectionPayment, { forceOpen: true, checking: _savedPayState === "pending" });
      if (_savedPayState !== "failed" && _savedPayState !== "cancelled") _continueInspectionVerification(savedInspectionPayment);
      UI.toast(_savedPayState === "failed" ? "Previous payment did not go through. Use New booking to start fresh." : "Your existing inspection request is safely saved. No duplicate request was created.", "info", 5000);
      return;
    }
    // No date selected is no longer a hard block — fall back to the next
    // available slot (tomorrow) instead of forcing the user to pick one.
    if (!dateVal) dateVal = _tomorrowScheduledLocal();
    if (Number.isNaN(new Date(dateVal).getTime()) || new Date(dateVal).getTime() <= Date.now()) {
      // free_lead/direct_vendor flows carry booking-date as a hidden field
      // with no picker the user can fix — silently refresh it instead of
      // blocking on a field they can never see or edit. Only the visible
      // datetime-local input (inspection / generic modal) enforces the hard
      // block, since there the user really did pick a bad date.
      if (dateField && dateField.type === "hidden") {
        dateVal = _tomorrowScheduledLocal();
      } else {
        _markInvalid("booking-date", "Please choose a future date and time");
        return;
      }
    }
    if (!mobile) { _markInvalid("booking-mobile", "Please enter mobile number"); return; }
    const mobileDigits = mobile.replace(/\D/g, "");
    if (mobileDigits.length !== 10 || !/^[6-9]/.test(mobileDigits)) { _markInvalid("booking-mobile", "Please enter a valid 10-digit mobile number"); return; }
    if (!area) { _markInvalid("booking-area", "Please enter area or landmark"); return; }
    if (!address) { _markInvalid("booking-address", "Please enter full address"); return; }
    if (bookingMode !== "inspection" && !notes && !serviceContext) { _markInvalid("booking-notes", "Please add a short issue note"); return; }

    const btn = document.getElementById("btn-confirm-booking");
    _isBookingSubmitting = true;
    if (btn) { btn.disabled = true; btn.classList.add("loading"); }

    _persistCustomerProfile({ ...(name ? { name } : {}), phone: mobile, locality: area, address });
    _commitTypedLocality(area);

    const requestType = _requestType(bookingMode);
    const requestSource = _currentService.request_source || "category";
    const createdAt = new Date().toISOString();
    const clientRequestId = _clientRequestId(bookingMode, category.slug || _activeCategory);
    const trackingState = _initialTrackingState(bookingMode);
    const priority = _deriveRequestPriority({ bookingMode, category, issueList, notes });
    const selectedWorker = bookingMode === "direct_vendor" ? {
      vendor_id: _currentService.vendor_id || null,
      vendor_name: _currentService.vendor_name || _currentService.name || "Requested worker",
    } : null;
    const operationalPayload = {
      request_id: clientRequestId,
      client_request_id: clientRequestId,
      request_schema_version: 1,
      request_type: requestType,
      category: category.slug || _activeCategory,
      category_label: category.label,
      issue_list: issueList,
      issue_summary: issueSummary,
      subservice: serviceContext,
      issue_type: serviceContext,
      priority: priority.level,
      priority_score: priority.score,
      priority_reason: priority.reason,
      locality: area,
      selected_nearby_area: _resolvedLocality().label || area,
      selected_city: _resolvedLocality().city || _activeCity(),
      city: _resolvedLocality().city || _activeCity(),
      locality_source: _resolvedLocality().source,
      locality_confidence: _resolvedLocality().source === "fallback" ? "city" : "area",
      full_address: address,
      customer: {
        name: name || "WorkToGo Customer",
        phone: mobileDigits,
        auth_user_id: AUTH.getUser?.()?.id || null,
      },
      customer_name: name || "WorkToGo Customer",
      customer_mobile: mobileDigits,
      booking_mode: bookingMode,
      booking_mode_label: _bookingModeMeaning(bookingMode),
      payment_required: bookingMode === "inspection",
      payment_route: bookingMode === "inspection" ? "paid_inspection" : "free_request",
      lifecycle_state: trackingState,
      assignment_state: _initialAssignmentState(bookingMode),
      issue_note: notes,
      preferred_time: new Date(dateVal).toISOString(),
      request_source: requestSource,
      selected_worker: selectedWorker,
      created_at: createdAt,
      timestamp: createdAt,
      session_context: _sessionContext(),
      tracking_state: trackingState,
      timeline: _initialRequestTimeline({ mode: bookingMode, state: trackingState, createdAt, paymentRequired: bookingMode === "inspection" }),
      sorting_keys: _requestSortingKeys({ category, issueList, area, bookingMode, priority, trackingState }),
      routing_context: _routingContext({ category, issueList, area, selectedWorker, priority, bookingMode }),
      operational_tags: _operationalTags({ bookingMode, category, issueList, area, priority }),
    };

    if (!_isValidOperationalRequest(operationalPayload)) {
      UI.toast("Request structure is incomplete. Please review the form and try again.", "error");
      _isBookingSubmitting = false;
      if (btn) { btn.disabled = false; btn.classList.remove("loading"); }
      return;
    }

    const res = await API.createBooking({
        service_id: resolvedServiceId,
        ...(dateVal ? { scheduled_at: new Date(dateVal).toISOString() } : {}),
        booking_mode: bookingMode,
        lifecycle_type: bookingMode,
        request_type: requestType,
        request_source: requestSource,
        request_id: clientRequestId,
        client_request_id: clientRequestId,
        subservice: serviceContext,
        issue_type: serviceContext,
        issue_list: issueList,
        issue_summary: operationalPayload.issue_summary,
        request_schema_version: operationalPayload.request_schema_version,
        operational_tags: operationalPayload.operational_tags,
        priority: priority.level,
        priority_score: priority.score,
        lifecycle_state: trackingState,
        assignment_state: operationalPayload.assignment_state,
        timeline: operationalPayload.timeline,
        issue_note: notes,
        preferred_time: new Date(dateVal).toISOString(),
        booking_mode_label: _bookingModeMeaning(bookingMode),
        payment_required: bookingMode === "inspection",
        payment_route: bookingMode === "inspection" ? "paid_inspection" : "free_request",
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
        selected_nearby_area: operationalPayload.selected_nearby_area,
        selected_city: operationalPayload.selected_city,
        locality_source: operationalPayload.locality_source,
        customer_address: address,
        vendor_id: selectedWorker?.vendor_id || null,
        notes: _adminRequestNotes(operationalPayload),
        ...(_forceNewBooking ? { force_new_booking: true } : {}),
      }).catch(err => ({ ok: false, error: err?.message || "Network issue while sending booking." }));
    _forceNewBooking = false; // always reset after submission attempt

    if (res.ok) {
      // Engine-level failure (HTTP always 200 for internal calls; check payload success flag)
      if (res.data?.success === false) {
        _isBookingSubmitting = false;
        if (btn) { btn.disabled = false; btn.classList.remove("loading"); }
        UI.toast(res.data?.message || res.data?.error?.message || "Booking could not be created. Please try again.", "error");
        return;
      }
      // Existing active booking returned — warn and offer "Book new anyway"
      if (res.data?.duplicate_prevented) {
        _isBookingSubmitting = false;
        if (btn) { btn.disabled = false; btn.classList.remove("loading"); }
        _showDuplicateBookingConfirm(res.data, bookingMode, category);
        return;
      }
      const paymentOk = await _handleInspectionCheckout(res.data, bookingMode, operationalPayload);
      if (paymentOk !== true && bookingMode !== "inspection") {
        _isBookingSubmitting = false;
        if (btn) { btn.disabled = false; btn.classList.remove("loading"); }
        return;
      }
      _clearPendingBookingForm();
      _clearPendingBookingIntent();
      if (bookingMode === "inspection") {
        _isBookingSubmitting = false;
        if (btn) { btn.disabled = false; btn.classList.remove("loading"); }
        if (paymentOk === true) _showInspectionConfirmation(res.data, operationalPayload);
        return;
      }
      _isBookingSubmitting = false;
      if (btn) { btn.disabled = false; btn.classList.remove("loading"); }
      _showRequestSuccess(res.data, operationalPayload);
    } else {
      _isBookingSubmitting = false;
      if (btn) { btn.disabled = false; btn.classList.remove("loading"); }
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
    // User explicitly dismissed the payment verification popup — clear persisted
    // state so it doesn't auto-reopen on the next page load.
    if (document.querySelector("#booking-modal .inspection-verification-state")) {
      _clearInspectionPaymentReturn();
    }
    document.getElementById("booking-modal")?.classList.add("hidden");
    _currentService = null;
    if (_swipeCleanup) { _swipeCleanup(); _swipeCleanup = null; }
    const btn = document.getElementById("btn-confirm-booking");
    if (btn) { btn.disabled = false; btn.classList.remove("loading"); }
    _unlockModalBody("booking");
  }

  function discardBookingDraft(reason = "context_change") {
    const modal = document.getElementById("booking-modal");
    if (!modal || modal.classList.contains("hidden")) return;
    _bookingOpenToken += 1;
    _isBookingSubmitting = false;
    modal.classList.add("hidden");
    _currentService = null;
    if (_swipeCleanup) { _swipeCleanup(); _swipeCleanup = null; }
    const btn = document.getElementById("btn-confirm-booking");
    if (btn) { btn.disabled = false; btn.classList.remove("loading"); }
    if (reason === "category_change" || reason === "discovery_change") _clearPendingBookingForm();
    _unlockModalBody("booking");
  }

  function closeOnOverlay(e) {
    if (e.target !== e.currentTarget) return;
    if (e.currentTarget?.id === "booking-modal") closeBooking();
    else close();
  }

  function selectInspectionCategory(slug = "") {
    const category = _categoryMeta(slug || "electrician");
    _syncCurrentServiceForCategory(category.slug);
    const hidden = document.getElementById("booking-category-context");
    if (hidden) hidden.value = category.slug;
    document.querySelectorAll(".inspection-category-chip").forEach(btn => btn.classList.toggle("active", btn.dataset.category === category.slug));
    const issue = _inspectionIssues(category.slug)[0] || "inspection";
    const issueWrap = document.getElementById("inspection-issue-chips");
    if (issueWrap) issueWrap.innerHTML = _inspectionIssueChips(category.slug, [issue]);
    _setSelectedIssues([issue]);
    _persistPendingBookingForm();
  }

  function _initialIntakeCategory(category = {}, restored = {}, service = {}) {
    const candidate = category.slug || service.category_slug || service.category || _activeCategory || "";
    if (!candidate || candidate === "all" || candidate === "inspection" || service.intake_category_required) return "";
    return _inspectionCategorySlug(candidate);
  }

  function selectInspectionIssue(issue = "") {
    const clean = String(issue || "").trim();
    if (!clean) return;
    const current = _selectedIssuesFromValue(document.getElementById("booking-service-context")?.value || "");
    const next = current.includes(clean) ? current.filter(item => item !== clean) : [...current, clean];
    _setSelectedIssues(next.length ? next : [clean]);
    _persistPendingBookingForm();
  }

  function _setSelectedIssues(issues = []) {
    const normalized = _normalizeIssueList(issues);
    const hidden = document.getElementById("booking-service-context");
    if (hidden) hidden.value = normalized.join(", ");
    document.querySelectorAll(".inspection-issue-chip").forEach(btn => btn.classList.toggle("active", normalized.includes(btn.dataset.issue)));
  }

  function _intakeRequestHTML({ mode = "inspection", category, selectedService, restored, sameRestoredContext, inspectionPrice, service = {} }) {
    const profile = _customerProfile();
    const locality = _resolvedLocality();
    const activeCategory = _initialIntakeCategory(category, restored, service);
    const issues = activeCategory ? _inspectionIssues(activeCategory) : [];
    // Only resume a previously persisted issue selection when this open is
    // genuinely the same booking context (same category + same chip) — a
    // different quick-chip click must always win over a stale draft left
    // behind by closing the modal on a prior, different selection.
    const restoredIssues = sameRestoredContext ? _normalizeIssueList(restored.issue_list || restored.service_context || service.selected_issues || "") : [];
    const matchingRestoredIssues = restoredIssues.filter(issue => issues.includes(issue));
    const activeIssues = _normalizeIssueList(matchingRestoredIssues.length ? matchingRestoredIssues : (selectedService && issues.includes(selectedService) ? [selectedService] : issues.includes(service.quick_service) ? [service.quick_service] : (issues[0] ? [issues[0]] : [])));
    const authUser = AUTH.getUser?.() || {};
    const nameValue = restored.name || profile.name || authUser.name || "";
    const phoneValue = restored.phone || profile.phone || authUser.phone || authUser.mobile || "";
    const isInspection = mode === "inspection";
    return `
      <div class="inspection-flow" data-flow="${_esc(isInspection ? "inspection-request" : "free-booking-request")}">
        <p class="company-trust-line">✓ Company handles your job end-to-end</p>
        <div class="inspection-summary-row">
          <strong>${_esc(isInspection ? `${UI.formatCurrency(inspectionPrice)} Expert Visit` : `${category.label || "Service"} request`)}</strong>
          <span>${_esc(locality.label)}</span>
        </div>
        <section class="inspection-step compact-customer-step">
          <h4>Customer</h4>
          <div class="inspection-two-field">
            <div class="modal-field">
              <label for="booking-name">Name</label>
              <input type="text" id="booking-name" class="modal-input" placeholder="Name" autocomplete="name" value="${_esc(nameValue)}" oninput="HomePage.persistPendingBookingForm?.()" />
            </div>
            <div class="modal-field">
              <label for="booking-mobile">Mobile</label>
              <input type="tel" id="booking-mobile" class="modal-input" placeholder="Mobile" autocomplete="tel" value="${_esc(phoneValue)}" oninput="HomePage.persistPendingBookingForm?.()" />
            </div>
          </div>
        </section>
        <section class="inspection-step">
          <h4>Service</h4>
          <div class="inspection-chip-row" role="radiogroup" aria-label="Inspection category">
            ${_inspectionCategoryChips(activeCategory)}
          </div>
        </section>
        <section class="inspection-step">
          <h4>What's the issue?</h4>
          <div class="inspection-chip-row issue-multi-chip-row" id="inspection-issue-chips" role="group" aria-label="Issue types">
            ${activeCategory ? _inspectionIssueChips(activeCategory, activeIssues) : `<span class="service-note">Select a service to see matching issue options.</span>`}
          </div>
        </section>
        <section class="inspection-step">
          <h4>Address</h4>
          ${(locality.source !== "fallback" && locality.label && locality.label !== "your area")
            ? `<input type="hidden" id="booking-area" value="${_esc((sameRestoredContext && restored.locality) || locality.label)}" />`
            : `<div class="modal-field">
            <label for="booking-area">Area / locality</label>
            <input type="text" id="booking-area" class="modal-input" placeholder="Search or type locality" autocomplete="address-level2" value="${_esc((sameRestoredContext && restored.locality) || locality.label)}" oninput="HomePage.onBookingAreaInput?.(this.value)" />
          </div>`}
          <div class="modal-field">
            <label for="booking-address">Full address</label>
            <textarea id="booking-address" class="modal-textarea" placeholder="Address" rows="2" autocomplete="street-address" oninput="HomePage.persistPendingBookingForm?.()">${_esc(restored.address || profile.address || "")}</textarea>
          </div>
        </section>
        <section class="inspection-step">
          <div class="modal-field">
            <label for="booking-notes">Describe issue</label>
            <textarea id="booking-notes" class="modal-textarea" placeholder="Optional" rows="2" oninput="HomePage.persistPendingBookingForm?.()">${_esc((sameRestoredContext && restored.notes) || "")}</textarea>
          </div>
        </section>
        <p class="inspection-payment-note">${_esc(isInspection ? "UPI apps and UPI ID are supported at payment." : "We'll match you with a nearby worker and confirm before they arrive.")}</p>
      </div>
      ${isInspection ? `
        <section class="inspection-step">
          <div class="modal-field">
            <label for="booking-date">Preferred visit time</label>
            <input type="datetime-local" id="booking-date" class="modal-input"
              min="${_isoNow()}"
              value="${_esc((sameRestoredContext && restored.scheduled_at) || _defaultScheduledLocal())}"
              onchange="HomePage.persistPendingBookingForm?.()" />
          </div>
        </section>` : `<input type="hidden" id="booking-date" value="${_esc((sameRestoredContext && restored.scheduled_at) || _defaultScheduledLocal())}" />`}
      <input type="hidden" id="booking-mode" value="${_esc(isInspection ? "inspection" : "free_lead")}" />
      <input type="hidden" id="booking-service-context" value="${_esc(activeIssues.join(", "))}" />
      <input type="hidden" id="booking-category-context" value="${_esc(activeCategory)}" />
    `;
  }

  function _vendorDirectFormHTML({ service, category, selectedService, restored, profile }) {
    const locality = _resolvedLocality();
    const authUser = AUTH.getUser?.() || {};
    const phoneValue = restored.phone || profile.phone || authUser.phone || authUser.mobile || "";
    const nameValue = restored.name || profile.name || authUser.name || "";
    const addressValue = restored.address || profile.address || "";
    const rawSvcs = Array.isArray(service.vendor_services) && service.vendor_services.length
      ? service.vendor_services
      : Array.isArray(service.services) && service.services.length ? service.services : category.examples || [];
    const issueNames = rawSvcs.map(s => typeof s === "string" ? s : s.name || s.label || "").filter(Boolean);
    const defaultIssue = service.quick_service || selectedService || issueNames[0] || "";
    const activeIssues = defaultIssue ? [defaultIssue] : [];
    return `
      <div class="vendor-direct-form">
        <div class="modal-field">
          <label for="booking-mobile">Mobile</label>
          <input type="tel" id="booking-mobile" class="modal-input" placeholder="Mobile" autocomplete="tel" value="${_esc(phoneValue)}" oninput="HomePage.persistPendingBookingForm?.()" />
        </div>
        <div class="modal-field">
          <label>Select issue</label>
          <div class="vendor-issue-chips" id="vendor-issue-chips">
            ${issueNames.map(issue => `<button type="button" class="vendor-issue-chip${activeIssues.includes(issue) ? " active" : ""}" data-issue="${_esc(issue)}" onclick="HomeModals.toggleVendorIssue('${_esc(issue)}')">${_esc(issue)}</button>`).join("")}
          </div>
        </div>
        <div class="modal-field">
          <label for="booking-address">Address</label>
          <textarea id="booking-address" class="modal-textarea" rows="2" placeholder="Address" oninput="HomePage.persistPendingBookingForm?.()">${_esc(addressValue)}</textarea>
        </div>
        <div class="modal-field">
          <label for="booking-notes">Problem description <small>(optional)</small></label>
          <textarea id="booking-notes" class="modal-textarea" rows="2" placeholder="Brief description of the issue…" oninput="HomePage.persistPendingBookingForm?.()">${_esc(restored.notes || "")}</textarea>
        </div>
        <input type="text" id="booking-name" autocomplete="name" value="${_esc(nameValue)}" />
        <input type="hidden" id="booking-date" value="${_esc(_defaultScheduledLocal())}" />
        <input type="hidden" id="booking-area" value="${_esc(locality.label || "")}" />
        <input type="hidden" id="booking-mode" value="direct_vendor" />
        <input type="hidden" id="booking-service-context" value="${_esc(activeIssues.join(", "))}" />
        <input type="hidden" id="booking-category-context" value="${_esc(category.slug || _activeCategory || "")}" />
      </div>`;
  }

  function toggleVendorIssue(issue) {
    const clean = String(issue || "").trim();
    if (!clean) return;
    const chip = document.querySelector(`#vendor-issue-chips [data-issue]`);
    const allChips = document.querySelectorAll("#vendor-issue-chips .vendor-issue-chip");
    allChips.forEach(b => { if (b.dataset.issue === clean) b.classList.toggle("active"); });
    const active = [...allChips].filter(b => b.classList.contains("active")).map(b => b.dataset.issue);
    const hidden = document.getElementById("booking-service-context");
    if (hidden) hidden.value = active.join(", ");
    _persistPendingBookingForm();
  }

  function prepareVendorDirectSubmit(service, { phone = "", issues = "", address = "", notes = "", name = "", mode = "direct_vendor", preferredVendorName = "" } = {}) {
    if (!AUTH.isLoggedIn()) {
      const digits = phone.replace(/\D/g, "");
      if (digits) _persistCustomerProfile({ phone: digits, ...(name ? { name } : {}) });
      UI.toast("Verify mobile once to send request", "info");
      _savePendingBookingIntent(service, _pendingBookingForm());
      ROUTER.go("login");
      return;
    }
    const category = _categoryMeta(service.category_slug || service.category || _activeCategory);
    const selectedService = _normalizeIssueList(issues)[0] || service.name || category.label;
    _currentService = {
      ...service,
      category_slug: category.slug || _activeCategory,
      category_label: category.label,
      selected_service: selectedService,
      request_source: "worker_card",
    };
    _isBookingSubmitting = false;
    _bookingOpenToken += 1;
    const body = document.getElementById("booking-modal-body");
    if (body) {
      body.innerHTML = `
        <input type="hidden" id="booking-name" value="${_esc(name || "Customer")}" />
        <input type="hidden" id="booking-mobile" value="${_esc(phone)}" />
        <input type="hidden" id="booking-date" value="${_esc(_defaultScheduledLocal())}" />
        <input type="hidden" id="booking-area" value="${_esc(_resolvedLocality().label || "")}" />
        <input type="hidden" id="booking-address" value="${_esc(address)}" />
        <input type="hidden" id="booking-notes" value="${_esc(preferredVendorName ? `Requested worker: ${preferredVendorName}. ${notes}`.trim() : notes)}" />
        <input type="hidden" id="booking-mode" value="${_esc(mode)}" />
        <input type="hidden" id="booking-service-context" value="${_esc(issues || selectedService)}" />
        <input type="hidden" id="booking-category-context" value="${_esc(category.slug || _activeCategory || "")}" />
      `;
    }
    _resetBookingActions(mode, _inspectionPrice(_currentService, category));
    confirmBooking();
  }

  function _resetBookingActions(mode, inspectionPrice) {
    const actions = document.querySelector("#booking-modal .modal-actions");
    if (!actions) return;
    const isInspection = mode === "inspection";
    actions.innerHTML = isInspection
      ? `<button class="btn-primary inspection-pay-cta" id="btn-confirm-booking" onclick="HomeModals.confirmBooking()"><span class="btn-label">Proceed to ${UI.formatCurrency(inspectionPrice)} expert visit</span></button>`
      : `<button class="btn-secondary" onclick="HomeModals.closeBooking()">Cancel</button><button class="btn-primary" id="btn-confirm-booking" onclick="HomeModals.confirmBooking()"><span class="btn-label">Confirm Booking</span></button>`;
  }

  function _showInspectionConfirmation(booking = {}, payload = {}) {
    _showRequestSuccess(booking, { ...payload, booking_mode: "inspection", payment_required: true, payment_route: "paid_inspection", tracking_state: "inspection_queued" });
  }

  function _showRequestSuccess(booking = {}, payload = {}) {
    const mode = _canonicalBookingMode(payload.booking_mode || booking.booking_mode, payload.selected_worker || {}, _categoryMeta(payload.category));
    const bookingId = booking?.booking_number || booking?.booking_id || booking?.id || "WTG";
    const issues = _normalizeIssueList(payload.issue_list || payload.subservice || payload.issue_type || booking.subservice || booking.service);
    const locality = payload.locality || payload.selected_nearby_area || _resolvedLocality().label;
    const city = payload.selected_city || _resolvedLocality().city || _activeCity();
    const state = _successStateForMode(mode, payload.payment_required);
    document.getElementById("booking-modal-title").textContent = "Request received";
    document.getElementById("booking-modal-body").innerHTML = `
      <div class="inspection-confirmation-state request-success-state">
        <div class="inspection-success-mark">✓</div>
        <h4>${_esc(state.title)}</h4>
        <p>${_esc(state.message)}</p>
        <div class="request-success-pill-row">
          ${state.steps.map((step, i) => `<span class="request-state-pill ${i === 0 ? "active" : ""}">${_esc(step)}</span>`).join("")}
        </div>
        <div class="inspection-confirmation-list">
          <span><strong>Request ID</strong>${_esc(String(bookingId))}</span>
          <span><strong>Service</strong>${_esc(payload.category_label || payload.category || booking.service || "Service")}</span>
          <span><strong>Issues</strong>${_esc(issues.join(", ") || "Issue shared")}</span>
          <span><strong>Area</strong>${_esc(locality)}${city ? ` · ${_esc(city)}` : ""}</span>
          <span><strong>Next step</strong>${_esc(state.next)}</span>
        </div>
        <p class="inspection-payment-note">Saved in My Requests. WorkToGo will update this request when assignment changes.</p>
      </div>`;
    const actions = document.querySelector("#booking-modal .modal-actions");
    if (actions) actions.innerHTML = `
      <button class="btn-primary" onclick="HomeModals.closeBooking(); ROUTER.go('bookings')">Track request</button>
      <button class="btn-secondary" onclick="UI.sendSupport('service', { requestType: '${_esc(payload.request_type || _requestType(mode))}', bookingId: '${_esc(String(bookingId))}' })">WhatsApp support</button>
      <button class="btn-secondary" onclick="HomeModals.closeBooking(); ROUTER.go('home')">Back to home</button>`;
    const modal = document.getElementById("booking-modal");
    modal?.classList.remove("hidden");
    _lockModalBody("booking");
    UI.toast(mode === "inspection" ? "Expert visit payment verified. Request saved." : "Request saved. Worker matching started.", "success");
  }

  function _successStateForMode(mode = "free_lead", paymentRequired = false) {
    if (mode === "inspection" || paymentRequired) return {
      title: "Expert visit request saved",
      message: "Payment is verified. WorkToGo will review the issue and arrange your expert visit.",
      next: "Visit coordinator will contact shortly",
      steps: ["Payment received", "Visit queued", "Coordinator reviewing", "Visit assigned"],
    };
    return {
      title: "Request saved",
      message: "WorkToGo is processing your request and checking nearby suitable workers.",
      next: mode === "direct_vendor" ? "Selected worker confirmation pending" : "Nearby worker matching started",
      steps: ["Request received", "Searching nearby worker", "Worker confirmation pending", "Worker assigned"],
    };
  }

  function _bindSwipeToClose(sheet) {
    if (!sheet) return;
    if (_swipeCleanup) _swipeCleanup();
    let startY = 0;
    let currentY = 0;
    let dragging = false;
    const onDown = e => { startY = e.touches?.[0]?.clientY ?? e.clientY; currentY = startY; dragging = sheet.scrollTop <= 0; };
    const onMove = e => {
      if (!dragging) return;
      currentY = e.touches?.[0]?.clientY ?? e.clientY;
      const delta = Math.max(0, currentY - startY);
      if (delta > 8 && sheet.scrollTop <= 0) sheet.style.transform = `translateY(${Math.min(delta, 120)}px)`;
    };
    const onUp = () => {
      if (!dragging) return;
      const delta = currentY - startY;
      sheet.style.transform = "";
      dragging = false;
      if (delta > 90 && sheet.scrollTop <= 2) closeBooking();
    };
    sheet.addEventListener("touchstart", onDown, { passive: true });
    sheet.addEventListener("touchmove", onMove, { passive: true });
    sheet.addEventListener("touchend", onUp, { passive: true });
    _swipeCleanup = () => {
      sheet.removeEventListener("touchstart", onDown);
      sheet.removeEventListener("touchmove", onMove);
      sheet.removeEventListener("touchend", onUp);
      sheet.style.transform = "";
    };
  }

  function _syncCurrentServiceForCategory(slug = "") {
    const next = _allServices.find(s => _matchesCategory(s, slug)) || _currentService;
    if (next) _currentService = { ..._currentService, ...next, category_slug: slug || next.category_slug || next.category };
  }

  // datetime-local input values carry no timezone — the browser (and
  // new Date(value) downstream) reads them as local wall-clock time.
  // toISOString() returns UTC digits, so on any non-UTC timezone (e.g.
  // IST, UTC+5:30) the previous d.toISOString().slice(0,16) silently
  // shifted the displayed/default time by the local UTC offset. For the
  // free-booking hidden date field specifically, that shift could even
  // push the +2h default into the past once re-parsed as local time,
  // tripping confirmBooking()'s "must be a future datetime" check.
  function _toLocalInputValue(d) {
    const pad = n => String(n).padStart(2, "0");
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  }

  function _isoNow() {
    return _toLocalInputValue(new Date());
  }

  function _defaultScheduledLocal() {
    const d = new Date(Date.now() + 2 * 60 * 60 * 1000);
    d.setMinutes(Math.ceil(d.getMinutes() / 15) * 15, 0, 0);
    return _toLocalInputValue(d);
  }

  function _tomorrowScheduledLocal() {
    const d = new Date(Date.now() + 24 * 60 * 60 * 1000);
    d.setMinutes(Math.ceil(d.getMinutes() / 15) * 15, 0, 0);
    return _toLocalInputValue(d);
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

  // ── Duplicate booking: warn inline + "Book anyway" ───────────────────────
  function _showDuplicateBookingConfirm(data, mode, cat) {
    const container = document.getElementById("booking-modal-body");
    if (!container) return;
    container.querySelector(".duplicate-booking-warn")?.remove();
    const bookingRef = data.booking_number || `#${data.booking_id}`;
    const modeLabel  = mode === "inspection" ? `₹${_inspectionPrice()} inspection` : "free service request";
    const warn = document.createElement("div");
    warn.className = "duplicate-booking-warn";
    warn.style.cssText = "background:#fff8e1;border:1px solid #f59e0b;border-radius:0.75rem;padding:1rem;margin-bottom:1rem;";
    warn.innerHTML = `
      <p style="margin:0 0 0.625rem;font-size:0.875rem;font-weight:600;color:#92400e;">
        ⚠️ Active booking already exists (${_esc(bookingRef)})
      </p>
      <p style="margin:0 0 0.875rem;font-size:0.8125rem;color:#78350f;line-height:1.45;">
        You already have an active <strong>${_esc(data.service || (cat && cat.label) || "service")}</strong>
        ${_esc(modeLabel)} — currently <strong>${_esc(data.status || "active")}</strong>.
        Do you want to book a new one anyway?
      </p>
      <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
        <button class="btn-primary" style="font-size:0.8125rem;padding:0.4375rem 0.875rem;min-width:0;"
          onclick="HomeModals.confirmNewBookingAnyway()">Yes, book new</button>
        <button class="btn-ghost-inline" style="font-size:0.8125rem;padding:0.4375rem 0.75rem;min-width:0;"
          onclick="this.closest('.duplicate-booking-warn')?.remove()">Cancel</button>
      </div>`;
    container.prepend(warn);
    container.scrollTop = 0;
  }

  function confirmNewBookingAnyway() {
    // Clear cached request ID so a fresh one is generated for the new booking
    try {
      sessionStorage.removeItem("wtg_active_client_request");
      localStorage.removeItem("wtg_active_client_request");
    } catch {}
    _forceNewBooking = true;
    document.querySelector(".duplicate-booking-warn")?.remove();
    confirmBooking();
  }

  return { openOrder, changeQty, confirmOrder, openBooking, confirmBooking, close, closeBooking, discardBookingDraft, closeOnOverlay, selectInspectionCategory, selectInspectionIssue, toggleVendorIssue, prepareVendorDirectSubmit, showInspectionConfirmation: _showInspectionConfirmation, checkInspectionPaymentStatus: _checkInspectionPaymentStatus, resumeInspectionPayment: _resumeInspectionPayment, startFreshInspectionBooking: _startFreshInspectionBooking, confirmNewBookingAnyway };
})();

// ── Vendor Card Controller ────────────────────────────────────────────────────

window.VendorCards = (function () {
  function toggleExpand(id) {
    const el = document.getElementById("vc-expand-" + id);
    if (!el) return;
    const isOpen = el.style.display === "block";
    document.querySelectorAll('[id^="vc-expand-"]').forEach(e => { e.style.display = "none"; });
    el.style.display = isOpen ? "none" : "block";
    if (!isOpen) {
      const card = document.getElementById("vc-" + id);
      if (card) card.scrollIntoView({ behavior: "smooth", block: "nearest" });
    }
  }

  function toggleIssue(btn, cardId) {
    if (!btn) return;
    btn.classList.toggle("active");
    const container = document.getElementById(`vdf-issues-${cardId}`);
    if (!container) return;
    const active = [...container.querySelectorAll(".vendor-issue-chip.active")].map(b => b.textContent.trim());
    const hidden = document.getElementById(`vdf-issues-val-${cardId}`);
    if (hidden) hidden.value = active.join(", ");
  }

  function submitInlineBooking(cardId, service) {
    const phone = (document.getElementById(`vdf-mobile-${cardId}`)?.value || "").trim();
    const issuesVal = (document.getElementById(`vdf-issues-val-${cardId}`)?.value || "").trim();
    const address = (document.getElementById(`vdf-address-${cardId}`)?.value || "").trim();
    const notes = (document.getElementById(`vdf-notes-${cardId}`)?.value || "").trim();
    const name = (document.getElementById(`vdf-name-${cardId}`)?.value || "").trim();
    const digits = phone.replace(/\D/g, "");
    if (!digits || digits.length !== 10 || !/^[6-9]/.test(digits)) {
      UI.toast("Please enter a valid 10-digit mobile number", "error");
      document.getElementById(`vdf-mobile-${cardId}`)?.focus();
      return;
    }
    if (!address) {
      UI.toast("Please enter your address", "error");
      document.getElementById(`vdf-address-${cardId}`)?.focus();
      return;
    }
    HomeModals.prepareVendorDirectSubmit(service, { phone: digits, issues: issuesVal, address, notes, name });
  }

  return { toggleExpand, toggleIssue, submitInlineBooking };
})();

// ── Bottom Sheet Booking System ───────────────────────────────────────────────

window.WtgSheet = (function () {
  let _vendors = [];
  let _overlay = null;
  let _sheet = null;

  function _ensureDOM() {
    if (_overlay) return;
    _overlay = document.createElement('div');
    _overlay.className = 'wtg-sheet-overlay';
    _overlay.onclick = function (e) { if (e.target === _overlay) close(); };
    _sheet = document.createElement('div');
    _sheet.className = 'wtg-sheet';
    let _swipeStartY = 0;
    _sheet.addEventListener('touchstart', e => { _swipeStartY = e.touches[0].clientY; }, { passive: true });
    _sheet.addEventListener('touchend', e => { if (e.changedTouches[0].clientY - _swipeStartY > 80) close(); });
    _overlay.appendChild(_sheet);
    document.body.appendChild(_overlay);
  }

  function setVendors(list) { _vendors = list || []; }

  function open(id) {
    _ensureDOM();
    const s = _vendors.find(v => String(v.id || v.vendor_id) === String(id));
    if (!s) return;

    const name = s.vendor_name || s.name || 'Vendor';
    const cat  = s.category || s.category_name || s.category_slug || '';
    const svcs = Array.isArray(s.vendor_services) ? s.vendor_services
      : Array.isArray(s.services) ? s.services : [];
    const svcNames = svcs.map(x => typeof x === 'string' ? x : x.name || x.label || '').filter(Boolean);

    let savedPhone = '', savedAddress = '', savedName = '';
    try { savedPhone   = localStorage.getItem('wtg_user_phone')   || ''; } catch {}
    try { savedAddress = localStorage.getItem('wtg_user_address') || ''; } catch {}
    try { savedName    = localStorage.getItem('wtg_user_name')    || ''; } catch {}

    const safeId = String(id).replace(/['"\\]/g, '');
    const chipsHTML = svcNames.map((n, i) =>
      `<button type="button" class="wtg-issue-chip${i === 0 ? ' sel' : ''}" onclick="WtgSheet.toggleChip(this)">${UI.escapeHtml(n)}</button>`
    ).join('');

    _sheet.innerHTML = `
      <div class="wtg-sheet-handle"></div>
      <div style="display:flex;justify-content:flex-end;margin-bottom:8px;">
        <button onclick="WtgSheet.close()" style="background:none;border:none;font-size:22px;color:#999;cursor:pointer;padding:0 4px;">✕</button>
      </div>
      <div class="wtg-sheet-vendor">${UI.escapeHtml(name)}</div>
      ${cat ? `<div class="wtg-sheet-cat">${UI.escapeHtml(cat)}</div>` : ''}
      ${chipsHTML ? `<div class="wtg-sheet-label">Select issue</div><div class="wtg-sheet-issue-chips">${chipsHTML}</div>` : ''}
      <div class="wtg-sheet-label">Your name</div>
      <input id="wtg-sh-name" class="wtg-sheet-input" type="text"
        placeholder="Full name"
        value="${UI.escapeHtml(savedName)}">
      <div class="wtg-sheet-label">Mobile number</div>
      <div class="wtg-sheet-phone-group">
        <span class="wtg-sheet-phone-prefix">+91</span>
        <input id="wtg-sh-phone" class="wtg-sheet-input wtg-sheet-phone-input" type="tel"
          placeholder="Mobile" autocomplete="off" inputmode="numeric" maxlength="10"
          value="${UI.escapeHtml(savedPhone)}">
      </div>
      <div class="wtg-sheet-label">Address</div>
      <textarea id="wtg-sh-addr" class="wtg-sheet-input" rows="2"
        placeholder="Address"
        style="resize:vertical">${UI.escapeHtml(savedAddress)}</textarea>
      <div class="wtg-sheet-label">Describe your problem
        <span style="font-weight:400;color:#aaa">(optional)</span></div>
      <textarea id="wtg-sh-note" class="wtg-sheet-input" rows="2"
        placeholder="Any extra details" style="resize:vertical"></textarea>
      <button class="wtg-sheet-submit"
        onclick="WtgSheet.submit('${safeId}')">CONFIRM BOOKING</button>
    `;

    _overlay.classList.add('open');
    requestAnimationFrame(() => _sheet.classList.add('open'));
    history.pushState({ wtgSheet: true }, '');
    window._wtgSheetOpen = true;
  }

  function close() {
    if (!_overlay) return;
    _sheet.classList.remove('open');
    setTimeout(() => _overlay.classList.remove('open'), 300);
    window._wtgSheetOpen = false;
  }

  function toggleChip(btn) { btn && btn.classList.toggle('sel'); }

  function submit(vendorId) {
    if (!_sheet) return;
    const btn = _sheet.querySelector('.wtg-sheet-submit');
    if (btn) {
      if (btn.disabled) return;
      btn.disabled = true;
      btn.textContent = 'Sending...';
    }
    try {
      const uname = (_sheet.querySelector('#wtg-sh-name')?.value  || '').trim();
      const phone = (_sheet.querySelector('#wtg-sh-phone')?.value || '').trim();
      const addr  = (_sheet.querySelector('#wtg-sh-addr')?.value  || '').trim();
      const note  = (_sheet.querySelector('#wtg-sh-note')?.value  || '').trim();
      const selectedChips = [..._sheet.querySelectorAll('.wtg-issue-chip.sel')]
        .map(c => c.textContent.trim());
      const selected = selectedChips.join(', ');

      if (!uname || uname.length < 2) {
        UI.toast('Please enter your name', 'error');
        _sheet.querySelector('#wtg-sh-name')?.focus();
        if (btn) { btn.disabled = false; btn.textContent = 'CONFIRM BOOKING'; }
        return;
      }
      try { localStorage.setItem('wtg_user_name', uname); } catch(e) {}

      const digits = phone.replace(/\D/g, '');
      if (!digits || digits.length !== 10 || !/^[6-9]/.test(digits)) {
        UI.toast('Please enter a valid 10-digit mobile number', 'error');
        _sheet.querySelector('#wtg-sh-phone')?.focus();
        if (btn) { btn.disabled = false; btn.textContent = 'CONFIRM BOOKING'; }
        return;
      }
      if (!addr) {
        UI.toast('Please enter your address', 'error');
        _sheet.querySelector('#wtg-sh-addr')?.focus();
        if (btn) { btn.disabled = false; btn.textContent = 'CONFIRM BOOKING'; }
        return;
      }

      const svcBase = _vendors.find(v => String(v.id || v.vendor_id) === String(vendorId))
        || { id: vendorId, vendor_id: vendorId, request_source: 'worker_card' };

      // Resolve the real services.id via service_map ({categoryName: serviceId},
      // built server-side by GET /api/services) — same pattern as
      // confirmBooking()'s resolvedServiceId. Key must be the vendor's
      // category name (svcBase.category_name, the same value the server used
      // to build service_map's keys), NOT the selected issue-chip text:
      // selectedChips holds issue labels like "leakage"/"wall repaint", which
      // never matched a service_map key, so this always fell through to
      // svcBase.id (the vendor's id, not a service id) and 404'd on checkout.
      const svc = { ...svcBase };
      const mapped = svcBase.service_map && svcBase.category_name
        ? svcBase.service_map[svcBase.category_name]
        : null;
      if (mapped) svc.id = mapped;

      // Vendor-only cards (backfilled from /api/vendors, no real services
      // row) have no service_map entry to resolve and svc.id is a fake
      // "vendor-N" placeholder, not a real services.id. The backend now
      // accepts a bare vendor_id for direct_vendor bookings (it resolves a
      // representative service row itself, see POST /api/service/request),
      // so submit as a real direct_vendor request bound to this vendor
      // instead of falling back to free_lead — svc.vendor_id (set in
      // _mergeVendorOnlyCards) carries the real vendor id through, and
      // resolvedServiceId in confirmBooking() skips svc.id for is_vendor_only
      // cards so the fake placeholder never gets sent as service_id.
      if (!mapped && svcBase.is_vendor_only) {
        if (window.HomeModals && HomeModals.prepareVendorDirectSubmit) {
          HomeModals.prepareVendorDirectSubmit(
            svc,
            { phone: digits, issues: selected, address: addr, notes: note, name: uname, mode: 'direct_vendor', preferredVendorName: svcBase.vendor_name || svcBase.name || '' }
          );
        }
        if (btn) { btn.disabled = false; btn.textContent = 'CONFIRM BOOKING'; }
        close();
        return;
      }

      if (window.HomeModals && HomeModals.prepareVendorDirectSubmit) {
        HomeModals.prepareVendorDirectSubmit(
          svc,
          { phone: digits, issues: selected, address: addr, notes: note, name: uname }
        );
      }
      if (btn) { btn.disabled = false; btn.textContent = 'CONFIRM BOOKING'; }
      close();
    } catch(e) {
      if (btn) { btn.disabled = false; btn.textContent = 'CONFIRM BOOKING'; }
    }
  }

  window.addEventListener('popstate', function (e) {
    if (window._wtgSheetOpen) {
      WtgSheet.close();
      window._wtgSheetOpen = false;
    }
  });

  return { setVendors, open, close, toggleChip, submit };
})();

async function _handleInspectionCheckout(booking, bookingMode, payload = {}) {
  const paymentData = booking?.payment_data || booking?.paymentData || null;
  const bookingId = booking?.booking_id || booking?.id || paymentData?.booking_id || null;
  if (bookingMode !== "inspection" || !paymentData) return true;
  if (paymentData.success === false) {
    const saved = _saveInspectionPaymentReturn(booking, { payload, state: "failed", message: paymentData.message || "Payment session could not be created. Your request is safely saved." });
    _showInspectionVerificationState(saved, { forceOpen: true });
    UI.toast(paymentData.message || "Payment session could not be created. Your request is safely saved.", "error", 6000);
    return "pending";
  }
  const gatewayData = paymentData.gateway_data || paymentData.gatewayData || {};
  const sessionId = paymentData.payment_session_id || paymentData.paymentSessionId || paymentData.session_id || gatewayData.payment_session_id || gatewayData.paymentSessionId;
  const redirectUrl = paymentData.payment_link || paymentData.payment_url || paymentData.redirect_url || paymentData.url || gatewayData.payment_link || gatewayData.payment_url;
  let saved = _saveInspectionPaymentReturn(booking, { payload, state: "pending" });
  try {
    if (window.Cashfree && sessionId) {
      if (!_markInspectionPaymentOpening(saved)) return "pending";
      _showInspectionVerificationState(saved, { forceOpen: true, checking: true });
      const cashfree = typeof window.Cashfree === "function" ? window.Cashfree({ mode: paymentData.mode || "production" }) : window.Cashfree;
      const result = await cashfree.checkout({ paymentSessionId: sessionId, redirectTarget: "_modal", components: ["order-details", "card", "netbanking", "app", "upi"] });
      const status = _normalizePaymentState(result?.paymentDetails?.payment_status || result?.paymentDetails?.status || result?.status || (result?.error ? "failed" : "pending"));
      if (status === "failed" || status === "cancelled") {
        saved = _saveInspectionPaymentReturn(booking, { payload, state: status, message: "Payment was not completed. Your request is safely saved." });
        _showInspectionVerificationState(saved, { forceOpen: true });
        UI.toast("Payment was not completed. Your inspection request is safely saved.", "error", 6000);
        return "pending";
      }
      saved = _saveInspectionPaymentReturn(booking, { payload, state: "verifying" });
      _showInspectionVerificationState(saved, { forceOpen: true, checking: true });
      const verified = await _waitForBookingPaymentTruth(bookingId, { onPending: state => _showInspectionVerificationState({ ...saved, status_state: state }, { checking: true }) });
      if (verified === "paid") return true;
      UI.toast("Payment verification is still in progress. Your request is safely saved.", "info", 7000);
      _continueInspectionVerification(saved);
      return "pending";
    }
    if (redirectUrl) {
      saved = _saveInspectionPaymentReturn(booking, { payload, state: "redirecting" });
      window.location.href = redirectUrl;
      return "pending";
    }
    saved = _saveInspectionPaymentReturn(booking, { payload, state: "pending", message: "Payment session is not available yet. Your request is safely saved." });
    _showInspectionVerificationState(saved, { forceOpen: true });
    UI.toast("Expert visit request is saved. Payment confirmation can be checked from here or My Requests.", "info", 6000);
    return "pending";
  } catch (err) {
    saved = _saveInspectionPaymentReturn(booking, { payload, state: "verifying", message: "Payment app was interrupted. Your request is safely saved." });
    _showInspectionVerificationState(saved, { forceOpen: true, checking: true });
    _continueInspectionVerification(saved);
    UI.toast("Payment check is continuing. Your inspection request is safely saved.", "info", 6000);
    return "pending";
  }
}

function _saveInspectionPaymentReturn(booking = {}, meta = {}) {
  const current = _readInspectionPaymentReturn() || {};
  const record = {
    ...current,
    booking: { ...(current.booking || {}), ...booking },
    payload: meta.payload || current.payload || {},
    locality: meta.locality || current.locality || _resolvedLocality(),
    status_state: meta.state || current.status_state || "pending",
    message: meta.message || current.message || "Payment verification in progress. Your request is safely saved.",
    ts: Date.now(),
  };
  try {
    sessionStorage.setItem("wtg_inspection_payment_return", JSON.stringify(record));
    localStorage.setItem("wtg_inspection_payment_return", JSON.stringify(record));
  } catch {}
  return record;
}

async function _restoreInspectionPaymentReturn() {
  try {
    const saved = _readInspectionPaymentReturn();
    if (!saved || !AUTH.isLoggedIn()) return;
    if (!saved?.booking || Date.now() - Number(saved.ts || 0) > 24 * 60 * 60 * 1000) return;
    const bookingId = saved.booking.booking_id || saved.booking.id;
    if (!bookingId) return;
    _showInspectionVerificationState(saved, { forceOpen: true, checking: true });
    const verified = await _waitForBookingPaymentTruth(bookingId, { onPending: state => _showInspectionVerificationState({ ...saved, status_state: state }, { checking: true }) });
    if (verified !== "paid") {
      _continueInspectionVerification(saved);
      return;
    }
    _clearInspectionPaymentReturn();
    const payload = {
      category_label: saved.payload?.category_label || saved.booking.service || "Expert Visit",
      subservice: saved.payload?.subservice || saved.payload?.issue_type || saved.booking.service || "Expert Visit",
      locality: saved.locality?.label || _resolvedLocality().label,
    };
    HomeModals.showInspectionConfirmation?.(saved.booking, payload);
    document.getElementById("booking-modal")?.classList.remove("hidden");
  } catch {}
}

async function _waitForBookingPaymentTruth(bookingId, opts = {}) {
  if (!bookingId || !API.getPaymentStatus) return false;
  for (let attempt = 0; attempt < (opts.attempts || 8); attempt += 1) {
    const res = await API.getPaymentStatus({ booking_id: bookingId }).catch(() => null);
    const status = _normalizePaymentState(res?.data?.payment_status || res?.data?.status || res?.payment_status);
    if (status === "paid") return "paid";
    if (["failed", "cancelled", "refunded"].includes(status)) return status;
    opts.onPending?.(status || "pending", attempt);
    await new Promise(resolve => setTimeout(resolve, opts.delayMs || 1500));
  }
  return "pending";
}

function _readInspectionPaymentReturn() {
  try {
    const raw = sessionStorage.getItem("wtg_inspection_payment_return") || localStorage.getItem("wtg_inspection_payment_return");
    return raw ? JSON.parse(raw) : null;
  } catch { return null; }
}

function _clearInspectionPaymentReturn() {
  try {
    sessionStorage.removeItem("wtg_inspection_payment_return");
    localStorage.removeItem("wtg_inspection_payment_return");
  } catch {}
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

function _showInspectionVerificationState(saved = {}, opts = {}) {
  const booking = saved.booking || {};
  const bookingId = booking.booking_number || booking.booking_id || booking.id || "WTG";
  const payload = saved.payload || {};
  const state = _normalizePaymentState(saved.status_state || saved.payment_status || "pending");
  const locality = saved.locality?.label || payload.locality || _resolvedLocality().label;
  const service = payload.subservice || payload.issue_type || payload.category_label || booking.service || "Expert Visit";
  const title = state === "paid" ? "Payment verified" : opts.checking || state === "pending" ? "Payment verification in progress" : state === "cancelled" ? "Payment not completed" : "Payment needs attention";
  const note = state === "paid"
    ? "Your expert visit payment is verified. WorkToGo will confirm the next operational step."
    : state === "failed" || state === "cancelled"
      ? "Your request is safely saved. Payment is not verified yet, so assignment will wait until payment is resolved."
      : "Your request is safely saved. We are checking payment confirmation from the gateway.";
  const modal = document.getElementById("booking-modal");
  const body = document.getElementById("booking-modal-body");
  if (!modal || !body) return;
  document.getElementById("booking-modal-title").textContent = title;
  body.innerHTML = `
    <div class="inspection-confirmation-state inspection-verification-state">
      <div class="inspection-success-mark">${state === "paid" ? "✓" : state === "failed" ? "✗" : "…"}</div>
      <h4>${_esc(title)}</h4>
      <p>${_esc(note)}</p>
      <div class="inspection-confirmation-list">
        <span><strong>Request ID</strong>${_esc(String(bookingId))}</span>
        <span><strong>Service</strong>${_esc(service)}</span>
        <span><strong>Area</strong>${_esc(locality)}</span>
        <span><strong>Next step</strong>${state === "paid" ? "Expert visit confirmation" : state === "failed" ? "Start a fresh booking below" : "Payment verification, then expert visit assignment"}</span>
      </div>
      <p class="inspection-payment-note">${state === "failed" ? "Payment did not go through. Your slot is still open — start a fresh booking." : "No duplicate request is needed. Use Check status to continue verification safely."}</p>
    </div>`;
  const actions = document.querySelector("#booking-modal .modal-actions");
  if (actions) actions.innerHTML = state === "failed"
    ? `<button class="btn-primary" onclick="HomeModals.startFreshInspectionBooking()">New booking</button>
       <button class="btn-ghost-inline" onclick="HomeModals.closeBooking()">Dismiss</button>`
    : `<button class="btn-primary" onclick="HomeModals.checkInspectionPaymentStatus()">Check status</button>
       ${state === "pending" ? `<button class="btn-secondary" onclick="HomeModals.resumeInspectionPayment()">Continue payment</button>` : ""}
       <button class="btn-secondary" onclick="HomeModals.closeBooking(); ROUTER.go('bookings')">My Requests</button>`;
  if (opts.forceOpen) {
    modal.classList.remove("hidden");
    document.body.classList.add("modal-open");
    document.body.dataset.modalOpen = "booking";
  }
}

async function _checkInspectionPaymentStatus() {
  const saved = _readInspectionPaymentReturn();
  const bookingId = saved?.booking?.booking_id || saved?.booking?.id;
  if (!bookingId) { UI.toast("No saved inspection payment check found.", "info"); return; }
  _showInspectionVerificationState(saved, { forceOpen: true, checking: true });
  const status = await _waitForBookingPaymentTruth(bookingId, { attempts: 5, delayMs: 1200, onPending: state => _showInspectionVerificationState({ ...saved, status_state: state }, { checking: true }) });
  if (status === "paid") {
    _clearInspectionPaymentReturn();
    HomeModals.showInspectionConfirmation?.(saved.booking, { ...(saved.payload || {}), locality: saved.locality?.label || _resolvedLocality().label });
    return;
  }
  const updated = _saveInspectionPaymentReturn(saved.booking, { payload: saved.payload, locality: saved.locality, state: status, message: "Payment verification is still in progress. Your request is safely saved." });
  _showInspectionVerificationState(updated, { forceOpen: true });
  UI.toast(status === "failed" || status === "cancelled" ? "Payment is not verified. Your request is saved." : "Still verifying payment. Your request is saved.", "info", 5000);
}

async function _resumeInspectionPayment() {
  const saved = _readInspectionPaymentReturn();
  if (!saved?.booking) { UI.toast("No saved payment session found.", "info"); return; }
  await _handleInspectionCheckout(saved.booking, "inspection", saved.payload || {});
}

function _markInspectionPaymentOpening(saved = {}) {
  const now = Date.now();
  if (Number(saved.checkout_lock_until || 0) > now) {
    UI.toast("Payment window is already opening. Please wait.", "info", 3500);
    return false;
  }
  const next = { ...saved, checkout_lock_until: now + 12000, ts: now };
  try {
    sessionStorage.setItem("wtg_inspection_payment_return", JSON.stringify(next));
    localStorage.setItem("wtg_inspection_payment_return", JSON.stringify(next));
  } catch {}
  return true;
}

function _continueInspectionVerification(saved = {}) {
  const bookingId = saved?.booking?.booking_id || saved?.booking?.id;
  if (!bookingId || window.__wtgInspectionPaymentPoll === bookingId) return;
  window.__wtgInspectionPaymentPoll = bookingId;
  setTimeout(async () => {
    const status = await _waitForBookingPaymentTruth(bookingId, { attempts: 12, delayMs: 5000 });
    window.__wtgInspectionPaymentPoll = null;
    if (status === "paid") {
      _clearInspectionPaymentReturn();
      HomeModals.showInspectionConfirmation?.(saved.booking, { ...(saved.payload || {}), locality: saved.locality?.label || _resolvedLocality().label });
      document.getElementById("booking-modal")?.classList.remove("hidden");
    } else if (_readInspectionPaymentReturn()) {
      // Only re-persist if state wasn't already cleared by the user dismissing.
      _saveInspectionPaymentReturn(saved.booking, { payload: saved.payload, locality: saved.locality, state: status });
    }
  }, 3000);
}

function _startFreshInspectionBooking() {
  _clearInspectionPaymentReturn();
  try {
    sessionStorage.removeItem("wtg_active_client_request");
    localStorage.removeItem("wtg_active_client_request");
  } catch {}
  HomeModals.closeBooking();
  UI.toast("Previous payment cleared. Tap the service to book again.", "info", 4000);
}

// ── Loaders ─────────────────────────────────────────────────────────────────

async function _loadServices() {
  // Fetch vendor cards, the full category list, and the raw vendor directory
  // in parallel. Categories come from the dedicated endpoint (all active,
  // vendor-independent) so filter chips always show every admin-defined
  // category. The vendor directory backfills any approved vendor that has
  // no /api/services row yet, so clicking their category still shows them.
  const [res, catRes, vendorRes] = await Promise.all([
    API.getServices(),
    API.getServiceCategories().catch(() => null),
    fetch(`${CONFIG.BASE_URL}/api/vendors?_t=${Date.now()}`).then(r => r.json()).catch(() => null),
  ]);
  const allCats = catRes?.ok
    ? (catRes.data?.data?.categories || catRes.data?.categories || [])
    : [];
  if (allCats.length) {
    _serviceCategories = allCats.map(c => ({ slug: c.slug, label: c.name, icon: c.icon || '', status: c.status }));
    _renderCategoryChips();
  }
  _renderServices(res);
  _mergeVendorOnlyCards(vendorRes);
  _resumePendingBooking();
}

// Backfills vendor cards for approved vendors who don't have a /api/services
// row yet (e.g. just approved, hasn't added a bookable service). Without
// this, a vendor never appears in their category's listing no matter how
// many times the customer taps that category, since the home feed only
// renders from _allServices, sourced from /api/services.
function _mergeVendorOnlyCards(vendorRes) {
  const vendors = vendorRes?.data?.vendors;
  if (!Array.isArray(vendors) || !vendors.length) return;
  const known = new Set(_allServices.map(s => String(s.vendor_id || s.id)));
  const extras = vendors
    .filter(v => v.status === 'active' && !known.has(String(v.id)))
    .map(v => ({
      id: `vendor-${v.id}`,
      vendor_id: v.id,
      vendor_name: v.business_name,
      name: v.business_name,
      category_name: v.category_name || '',
      category_slug: _slug(v.category_name || ''),
      category_slugs: v.category_name ? [_slug(v.category_name)] : [],
      vendor_services: v.category_name ? [v.category_name] : [],
      rating: v.rating,
      logo_url: v.logo_url,
      is_verified: v.is_verified,
      experience_years: v.experience_years,
      service_localities: v.service_localities,
      request_source: 'worker_card',
      is_vendor_only: true,
    }));
  if (!extras.length) return;
  _allServices = [..._allServices, ...extras];
  _renderServices({ ok: true, data: { services: _allServices } });
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
    el.classList.remove("fallback-services-grid");
    el.innerHTML = `
      <div class="fallback-help-card service-recovery-card">
        <h3>Area-based worker confirmation active</h3>
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
  if (!_serviceCategories.length && Array.isArray(payload?.categories) && payload.categories.length) {
    _serviceCategories = payload.categories.map(c => ({ slug: c.slug, label: c.name, icon: c.icon || "", status: c.status }));
    _renderCategoryChips();
  }
  if (list.length) _allServices = list;
  if (_searchQuery && _searchRemoteServices.length) list = _searchRemoteServices;
    const inferred = _searchQuery ? _inferSearchMeta(_searchQuery) : null;
    const categoryFilter = _activeCategory || (inferred?.slug || "");
    if (categoryFilter) list = list.filter(s => _matchesCategory(s, categoryFilter) || (Array.isArray(s.category_slugs) && s.category_slugs.includes(categoryFilter)));
    if (_activeChipFilter) list = list.filter(s => _serviceMatchesDiscovery(s, _activeChipFilter, _categoryMeta(_activeCategory)));
    if (_searchQuery) list = list.filter(s => _searchText(s).includes(_searchQuery));

    if (!list.length) {
      el.classList.remove("fallback-services-grid");
      el.innerHTML = `
      <div class="fallback-help-card">
        <h3>${_esc(_activeCategory ? `${_categoryMeta(_activeCategory).label} worker matching ready` : "Area-based worker matching ready")}</h3>
        <p>${_esc(_searchQuery || _activeChipFilter ? "Exact worker card is not shown yet. Submit the request and WorkToGo will route it after confirmation." : "Booking opens area-based worker matching after confirmation.")}</p>
        <button class="btn-ghost-inline" onclick="HomePage.bookCategoryCta('', 'free_lead')">Free booking</button>
      </div>`;
      return;
  }
  el.classList.remove("fallback-services-grid");

  el.innerHTML = list.map(s => _vendorCardHTML(s)).join("");
  if (window.WtgSheet) WtgSheet.setVendors(list);
}

async function _loadPilotConfig() {
  if (_pilotLoaded) return;
  _pilotLoaded = true;
  const res = await API.getPublicSettings().catch(() => null);
  const data = res?.ok ? _unwrapData(res.data) : null;
  if (data?.pilot_public_config) _pilotConfig = { ..._pilotConfig, ...data.pilot_public_config };
  if (data?.inspection_price !== undefined) _pilotConfig.inspection_price = Number(data.inspection_price);
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

function _renderContextProof() {
  const trust = document.getElementById("trust-proof-section");
  if (trust) trust.innerHTML = _trustProofHTML(_activeCategory);
  const visual = document.getElementById("visual-proof-section");
  if (visual) visual.innerHTML = _visualProofHTML(_activeCategory);
}

function _renderHeroForCategory() {
  const el = document.getElementById("category-hero");
  if (!el) return;
  el.innerHTML = _heroHTML(_activeCategory);
  el.dataset.pattern = _activeCategory || "default";
}

function _renderLocalityHeader() {
  const label = document.getElementById("home-locality-label");
  const status = document.getElementById("home-locality-status");
  if (label) label.textContent = _resolvedLocality().label;
  if (status) status.textContent = "near you";
}

function _renderLocalitySelector(query = "", { full = false } = {}) {
  const el = document.getElementById("locality-selector-body");
  if (!el) return;
  if (!full && el.querySelector("#locality-manual-input")) {
    const list = document.getElementById("locality-suggestions");
    if (list) list.innerHTML = _localitySuggestionsHTML(query);
    return;
  }
  el.innerHTML = _localitySelectorHTML(query);
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

// Fixed display order for the homepage category grid — highest-demand
// services first, regardless of what order the backend/fallback list
// returns them in. Anything not listed here keeps its original order,
// appended after these.
const CATEGORY_ORDER = ["waterproofing", "plumber", "painting", "electrician", "ac-repair", "cctv"];

// Single source of truth for the Haldwani pilot's known local areas — used
// by both the locality picker suggestions and the city-locality defaults.
const LOCAL_AREAS = ["Mukhani", "Dahariya", "Kathgodam", "Tikonia", "Lalkuan", "Bhimtal", "Ramnagar", "Bazpur", "Kashipur"];

function _sortCategories(list) {
  return [...list].sort((a, b) => {
    const ia = CATEGORY_ORDER.indexOf(a.slug);
    const ib = CATEGORY_ORDER.indexOf(b.slug);
    if (ia === -1 && ib === -1) return 0;
    if (ia === -1) return 1;
    if (ib === -1) return -1;
    return ia - ib;
  });
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
  const merged = dynamic.length ? _mergeCategories(dynamic, []) : _mergeCategories([], fallback);
  return _sortCategories(merged);
}

function _mergeCategories(dynamic, base) {
  const map = new Map();
  [...dynamic, ...base].forEach(c => {
    if (c.status === 'inactive') return;
    const slug = _slug(c.slug || c.label || c.name);
    if (!slug || map.has(slug)) return;
    const meta = CATEGORY_META[slug] || {};
    const label = c.label || c.name || meta.label || slug;
    map.set(slug, { slug, icon: c.icon || meta.icon || _matchCategoryIcon(`${slug} ${label}`), label });
  });
  return [...map.values()];
}

function _categoryMeta(slug = "") {
  const key = _slug(slug);
  if (!key) return CATEGORY_META.all;
  const meta = CATEGORY_META[key] || { icon: _matchCategoryIcon(key), label: _title(key), hero: `${_title(key)} services for selected area`, subtitle: "Choose a local service and WorkToGo will confirm before visit.", examples: ["Inspection", "Repair", "Installation"], trust: "Verified local support", vendors: "Local provider assignment", inspection: true };
  return { slug: key, ...meta };
}

function _categoryEcosystemHTML(slug = "") {
  if (CONFIG.SERVICE_ONLY?.DISABLE_FAKE_NETWORKS !== false) {
    const meta = _categoryMeta(slug);
    const localityContext = _resolvedLocality();
    return `
      <details class="ecosystem-card ecosystem-world" ${slug ? "open" : ""}>
        <summary class="ecosystem-summary">
          <span class="ecosystem-icon">${meta.icon}</span>
          <strong>${_esc("Material support after visit")}</strong>
          <small>${_esc("Informational only · no live dealer routing")}</small>
        </summary>
        <div class="ecosystem-banner">
          <span class="ecosystem-icon">${meta.icon}</span>
          <div>
            <h3>${_esc(`${meta.label} material context for ${localityContext.label}`)}</h3>
            <p>${_esc("Worker first checks quantity and fit. WorkToGo can guide material help after scope is confirmed.")}</p>
          </div>
        </div>
      </details>`;
  }
  const meta = _categoryMeta(slug);
  const contextLabel = _activeContextLabel(meta);
  const localityContext = _resolvedLocality();
  const visuals = (meta.visuals || CATEGORY_META.all.visuals || []).slice(0, 4);
  const tags = meta.tags || meta.examples || [];
  const dealers = meta.dealers || CATEGORY_META.all.dealers || [];
  const materials = meta.materials || CATEGORY_META.all.materials || [];
  const brands = meta.brands || CATEGORY_META.all.brands || [];
  const locality = meta.locality || CATEGORY_META.all.locality || [];
  return `
    <details class="ecosystem-card ecosystem-world" ${slug ? "open" : ""}>
      <summary class="ecosystem-summary">
        <span class="ecosystem-icon">${meta.icon}</span>
        <strong>${_esc(slug ? `${meta.label} nearby material network` : "Nearby material network")}</strong>
        <small>${_esc("Informational supply lane · worker request stays separate")}</small>
      </summary>
      <div class="ecosystem-banner">
        <span class="ecosystem-icon">${meta.icon}</span>
        <div>
          <h3>${_esc(contextLabel)} supply lane for ${_esc(localityContext.label)}</h3>
          <p>${_esc("Use this to understand nearby dealer/material context. WorkToGo confirms supply needs only after worker checks scope.")}</p>
        </div>
      </div>
      <div class="ecosystem-visual-rail">
        ${visuals.map(v => `<div class="ecosystem-info-chip" aria-label="Informational supply context"><span>${_esc(v.emoji || meta.icon)}</span><strong>${_esc(v.label)}</strong><small>${_esc(v.note || "nearby lane")}</small></div>`).join("")}
      </div>
      <div class="ecosystem-tag-row">
        ${tags.slice(0, 6).map(x => `<span>${_esc(x)}</span>`).join("")}
      </div>
      <div class="ecosystem-local-grid">
        <button type="button" onclick="HomePage.viewMaterialNetwork('materials')"><strong>Nearby materials</strong><span>${_esc(materials.slice(0, 3).join(" · ") || "Parts after worker check")}</span></button>
        <button type="button" onclick="HomePage.viewMaterialNetwork('dealers')"><strong>Local dealer network</strong><span>${_esc(dealers.slice(0, 2).join(" · ") || "Dealer context after scope")}</span></button>
        <div><strong>Locality context</strong><span>${_esc(localityContext.label + " · " + _localitySourceLine())}</span></div>
        <div><strong>Supply rule</strong><span>Worker confirms quantity before dealer help starts</span></div>
      </div>
    </details>`;
}

function _operatingFeedHTML(slug = "") {
  const meta = _categoryMeta(slug);
  const jobs = _contextItems(meta, "jobs", meta.examples || []).slice(0, 4);
  const places = meta.locality || CATEGORY_META.all.locality || ["nearby"];
  const localityContext = _resolvedLocality();
  return `
    <div style="display:none">
    <div class="operating-head">
      <div>
        <h3>${_esc(`${localityContext.label} request context`)}</h3>
        <p>${_esc(`Requests carry ${localityContext.label} as ${_localitySourceLine().toLowerCase()} for admin assignment. Area can be changed before booking.`)}</p>
      </div>
    </div>
    <div class="ops-ticker" aria-label="Nearby activity statuses">
      ${places.slice(0, 5).map((place, i) => `<button type="button" class="ops-status-pill ${_activeLocalityFilter === place ? "active" : ""}" onclick="HomePage.selectLocality('${_esc(place)}')"><strong>${_esc(place)}</strong><small>${_esc(_activeLocalityFilter === place ? "selected for routing" : (jobs[i % jobs.length] || "area context"))}</small></button>`).join("")}
    </div>
    </div>`;
}

function _heroHTML(slug = "") {
  const meta = _categoryMeta(slug);
  const localityContext = _resolvedLocality();
  const price = UI.formatCurrency(_inspectionPrice(null, meta));
  const primaryText = `${price} Inspection`;
  return `
    <div class="service-hero-copy">
      <h1>${_esc(slug ? `${meta.label} services for ${localityContext.label}` : `Trusted services for ${localityContext.label}`)}</h1>
      <p>Verified local experts. Book in 60 seconds.</p>
      <div class="booking-distinction-grid" id="hero-distinction-grid">
        <button type="button" class="distinction-toggle" data-key="inspection" aria-expanded="false" onclick="HomePage.toggleHeroInfoCard('inspection')">
          <span class="distinction-head"><strong>Inspection</strong><span class="distinction-arrow">⌄</span></span>
          <span class="distinction-detail">Our agent visits you first and checks the actual problem. Then the right worker gets assigned for your job.</span>
        </button>
        <button type="button" class="distinction-toggle" data-key="free_lead" aria-expanded="false" onclick="HomePage.toggleHeroInfoCard('free_lead')">
          <span class="distinction-head"><strong>Book for Free</strong><span class="distinction-arrow">⌄</span></span>
          <span class="distinction-detail">We assign the best available worker in your area right away — no visit needed first.</span>
        </button>
      </div>
      <div class="hero-cta-row">
        <button class="btn-primary marketplace-cta hero-primary" id="hero-category-cta" style="display:none" onclick="HomePage.bookCategoryCta('${_esc(meta.slug || "")}', 'inspection')">${_esc(primaryText)}</button>
        <button class="hero-secondary company-service-cta" onclick="HomePage.bookCategoryCta('${_esc(meta.slug || "")}', 'free_lead')">Get Company Service</button>
      </div>
      <p class="action-hierarchy-note" id="hero-distinction-note" style="display:none"></p>
    </div>`;
}

function _serviceCardsHTML(slug = "") {
  // "All" has no single category, so its examples would just repeat the
  // category names already shown in the top "What do you need?" bar — skip.
  if (!slug) return "";
  const meta = _categoryMeta(slug);
  const cards = (meta.examples?.length ? meta.examples : CATEGORY_META.all.examples).slice(0, 4);
  return `
    <div class="quick-service-rail">
      ${cards.map(name => `<button class="quick-service-card" onclick="HomePage.bookQuickService('${_esc(meta.slug || "")}', '${_esc(name)}')"><span>${_matchIssueIcon(name)}</span><strong>${_esc(name)}</strong></button>`).join("")}
    </div>`;
}

function _trustProofHTML(slug = "") {
  const meta = _categoryMeta(slug);
  const localityContext = _resolvedLocality();
  return `
    <div><strong>After request</strong><span>WorkToGo checks service, ${_esc(localityContext.label)} area context, issue note and visit mode before assignment</span></div>
    <div><strong>Worker confirmation</strong><span>${_esc(meta.vendors || "Admin assigns a suitable local worker; cards are request entry points, not live profiles")}</span></div>
    <div><strong>Payment rule</strong><span>Normal jobs pay after service; inspection is used when diagnosis or estimate is needed first</span></div>`;
}

function _visualProofHTML(slug = "") {
  if (CONFIG.SERVICE_ONLY?.DISABLE_PROOF_TILES !== false) return "";
  const meta = _categoryMeta(slug);
  const localityContext = _resolvedLocality();
  const baseProof = meta.beforeAfter || CATEGORY_META.all.beforeAfter;
  const beforeAfter = _activeChipFilter
    ? [...baseProof].sort((a, b) => Number(_proofMatches(b)) - Number(_proofMatches(a)))
    : baseProof;
  return `
    <div class="section-header">
      <div>
        <h3>${_esc(meta.label)} work proof for ${_esc(localityContext.label)}</h3>
        <p class="section-note">Informational examples: service type, selected locality context and completed outcome.</p>
      </div>
    </div>
    <div class="proof-rail">
      ${beforeAfter.map((item, i) => `
        <div class="proof-tile proof-tone-${i % 3}" aria-label="Informational work proof">
          <div class="proof-split">
            <span>${_esc(localityContext.label)}</span>
            <span>Completed</span>
          </div>
          <em>${_esc(meta.label)} · ${_esc(localityContext.label)} context example</em>
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

function _vendorCardHTML(s) {
  if (!document.getElementById('wtg-vc-styles')) {
    const st = document.createElement('style');
    st.id = 'wtg-vc-styles';
    st.textContent = `
      .wtg-vc { background:#fff; border-radius:16px;
        padding:16px; margin:0 0 12px 0;
        box-shadow:0 4px 18px rgba(0,0,0,0.09); width:100%;
        animation:fadeInUp 0.2s ease both; }
      .wtg-vc-top { display:flex; align-items:center; gap:12px; }
      .wtg-vc-avatar { width:48px; height:48px; border-radius:50%;
        background:#FF6B35; color:#fff; display:flex;
        align-items:center; justify-content:center;
        font-size:20px; font-weight:700; flex-shrink:0;
        overflow:hidden; }
      .wtg-vc-avatar img { width:100%; height:100%; object-fit:cover; }
      .wtg-vc-info { flex:1; }
      .wtg-vc-name { font-size:16px; font-weight:700; color:#1a1a1a; }
      .wtg-vc-meta { font-size:13px; color:#888; margin-top:2px; }
      .wtg-vc-new-badge { color:#FF6B35; font-weight:600; }
      .wtg-vc-avail { width:10px; height:10px; border-radius:50%;
        background:#22c55e; flex-shrink:0; }
      .wtg-vc-verified { color:#16a34a; font-weight:800; font-size:12px;
        margin-left:6px; white-space:nowrap; }
      .wtg-vc-bio { font-size:13px; color:#666; margin-top:10px;
        line-height:1.4; display:-webkit-box; -webkit-line-clamp:2;
        -webkit-box-orient:vertical; overflow:hidden; }
      .wtg-vc-bio.expanded { display:block; -webkit-line-clamp:unset;
        overflow:visible; }
      .wtg-vc-bio-more { font-size:12px; color:#FF6B35; font-weight:600;
        cursor:pointer; margin-top:4px; display:inline-block; }
      .wtg-vc-chips { display:flex; flex-wrap:wrap; gap:6px;
        margin-top:10px; }
      .wtg-vc-chip { padding:5px 12px; border-radius:20px;
        background:#fff; color:#6b7280; font-size:12px;
        border:1px solid #e5e7eb; }
      .wtg-vc-exp-chip { padding:5px 12px; border-radius:20px;
        background:#FFF3EF; color:#FF6B35; font-size:12px; font-weight:600; }
      .wtg-vc-areas { display:flex; flex-wrap:wrap; gap:6px;
        margin-top:8px; }
      .wtg-vc-area-tag { padding:3px 9px; border-radius:20px;
        background:#F5F5F5; color:#6b7280; font-size:10.5px; }
      .wtg-vc-area-more { padding:3px 9px; border-radius:20px;
        background:#F5F5F5; color:#6b7280; font-size:10.5px;
        font-weight:600; cursor:pointer; }
      .wtg-vc-area-extra { display:none; }
      .wtg-vc-area-extra.expanded { display:contents; }
      .wtg-vc-book { width:100%; margin-top:14px; padding:0;
        min-height:48px; max-height:48px;
        background:linear-gradient(135deg, #FF6B35, #ff8a00);
        color:#fff; border:none; letter-spacing:0.5px;
        text-transform:uppercase;
        border-radius:10px; font-size:15px; font-weight:700;
        cursor:pointer; }
      .wtg-vc-book:active { transform:scale(0.97); }

      /* Bottom Sheet */
      .wtg-sheet-overlay { position:fixed; inset:0;
        background:rgba(0,0,0,0.45); z-index:1000;
        display:none; }
      .wtg-sheet-overlay.open { display:block; }
      .wtg-sheet { position:fixed; bottom:0; left:0; right:0;
        background:#fff; border-radius:20px 20px 0 0;
        padding:20px; z-index:1001; max-height:85vh;
        overflow-y:auto; transform:translateY(100%);
        transition:transform 0.25s ease; }
      .wtg-sheet.open { transform:translateY(0); }
      .wtg-sheet-handle { width:40px; height:4px;
        background:#ddd; border-radius:2px;
        margin:0 auto 16px; }
      .wtg-sheet-vendor { font-size:18px; font-weight:800;
        color:#1a1a1a; margin-bottom:2px; }
      .wtg-sheet-cat { font-size:13px; color:#FF6B35; font-weight:600;
        margin-bottom:16px; }
      .wtg-sheet-label { font-size:11px; font-weight:700;
        text-transform:uppercase; letter-spacing:0.05em;
        color:#888; margin:14px 0 8px; }
      .wtg-sheet-issue-chips { display:flex; flex-wrap:wrap; gap:8px; }
      .wtg-issue-chip { padding:8px 14px; border-radius:20px;
        border:1.5px solid #e0e0e0; background:#fff;
        font-size:13px; cursor:pointer; color:#444; }
      .wtg-issue-chip.sel { border-color:#FF6B35;
        background:#FFF3EF; color:#FF6B35; font-weight:600; }
      .wtg-sheet-input { width:100%; min-height:48px; padding:13px 16px;
        border:1.5px solid #e0e0e0; border-radius:10px;
        font-size:15px; box-sizing:border-box; margin-top:0;
        transition:border-color 0.15s ease; }
      .wtg-sheet-input:focus { outline:none; border-color:#FF6B35; }
      .wtg-sheet-phone-group { display:flex; align-items:center; min-height:48px;
        border:1.5px solid #e0e0e0; border-radius:10px; overflow:hidden;
        transition:border-color 0.15s ease; }
      .wtg-sheet-phone-group:focus-within { border-color:#FF6B35; }
      .wtg-sheet-phone-prefix { padding:13px 0 13px 16px;
        font-size:15px; color:#555; font-weight:600; }
      .wtg-sheet-phone-input { border:none; border-radius:0; min-height:46px; }
      .wtg-sheet-submit { width:100%; margin-top:20px; min-height:52px;
        padding:16px; background:#FF6B35; color:#fff;
        border:none; border-radius:12px; font-size:16px;
        font-weight:700; cursor:pointer; }
      .wtg-sheet-submit:active { transform:scale(0.97); }
    `;
    document.head.appendChild(st);
  }

  const id = s.id || s.vendor_id;
  const name = s.vendor_name || s.name || 'Vendor';
  const initial = name.charAt(0).toUpperCase();
  const photo = s.image || s.photo || s.logo_url || '';
  const jobs = s.jobs_done || s.completed_jobs || s.jobs_count || 0;
  const totalReviews = parseInt(s.total_reviews, 10) || 0;
  const rating = totalReviews > 0
    ? `⭐ ${parseFloat(s.rating || 0).toFixed(1)} (${totalReviews} review${totalReviews === 1 ? '' : 's'})`
    : '';
  const meta = [rating, jobs ? `${jobs} jobs` : ''].filter(Boolean).join(' · ');
  const available = s.available_today;
  const isVerified = s.is_verified === true || s.is_verified === 1 || s.is_verified === '1';
  const experienceYears = parseInt(s.experience_years, 10) || 0;

  const bioRaw = (s.description || '').trim();
  const bioId = `wtg-vc-bio-${_esc(String(id))}`;
  const bioNeedsExpand = bioRaw.length > 110;
  const bioHTML = bioRaw
    ? `<div class="wtg-vc-bio" id="${bioId}">${_esc(bioRaw)}</div>` +
      (bioNeedsExpand
        ? `<span class="wtg-vc-bio-more" onclick="var b=document.getElementById('${bioId}'); var exp=b.classList.toggle('expanded'); this.textContent = exp ? 'Read less' : 'Read more';">Read more</span>`
        : '')
    : '';

  let areaNames = [];
  try {
    const parsed = typeof s.service_localities === 'string'
      ? JSON.parse(s.service_localities)
      : s.service_localities;
    if (Array.isArray(parsed)) areaNames = parsed.filter(Boolean);
  } catch (_) { /* leave areaNames empty on malformed data */ }
  const visibleAreas = areaNames.slice(0, 3);
  const hiddenAreas = areaNames.slice(3);
  const areaExtraId  = `wtg-vc-area-extra-${_esc(String(id))}`;
  const areaToggleId = `wtg-vc-area-toggle-${_esc(String(id))}`;
  const visibleAreaHTML = visibleAreas.map(a => `<span class="wtg-vc-area-tag">📍 ${_esc(a)}</span>`).join('');
  const hiddenAreaHTML = hiddenAreas.map(a => `<span class="wtg-vc-area-tag">📍 ${_esc(a)}</span>`).join('');
  const areaMoreLabel = `▼ +${hiddenAreas.length} more`;
  const areaMoreHTML = hiddenAreas.length
    ? `<span class="wtg-vc-area-more" id="${areaToggleId}" onclick="var ex=document.getElementById('${areaExtraId}'); var exp=ex.classList.toggle('expanded'); this.textContent = exp ? '▲ Show less' : '${areaMoreLabel}';">${areaMoreLabel}</span>`
    : '';
  const areaHTML = areaNames.length
    ? `<div class="wtg-vc-areas">${visibleAreaHTML}${areaMoreHTML}<span id="${areaExtraId}" class="wtg-vc-area-extra">${hiddenAreaHTML}</span></div>`
    : '';

  const svcs = Array.isArray(s.vendor_services) ? s.vendor_services
    : Array.isArray(s.services) ? s.services : [];
  const svcNames = svcs.map(x => x.name || x).filter(Boolean);
  const extra = svcNames.length > 3 ? `+${svcNames.length - 3} more` : '';
  const expChipHTML = experienceYears
    ? `<span class="wtg-vc-exp-chip">${experienceYears} yrs exp</span>` : '';
  const chipHTML = expChipHTML + svcNames.slice(0, 3)
    .map(n => `<span class="wtg-vc-chip">${_esc(n)}</span>`).join('')
    + (extra ? `<span class="wtg-vc-chip">${extra}</span>` : '');

  const avatarInner = photo
    ? `<img src="${_esc(photo)}" alt="${_esc(name)}">`
    : _esc(initial);

  return `
    <div class="wtg-vc" id="vc-${_esc(String(id))}">
      <div class="wtg-vc-top">
        <div class="wtg-vc-avatar">${avatarInner}</div>
        <div class="wtg-vc-info">
          <div class="wtg-vc-name">${_esc(name)}${isVerified ? '<span class="wtg-vc-verified">&#10003; Verified</span>' : ''}</div>
          ${meta ? `<div class="wtg-vc-meta">${_esc(meta)}</div>` : ''}
        </div>
        ${available ? '<div class="wtg-vc-avail"></div>' : ''}
      </div>
      ${bioHTML}
      ${chipHTML ? `<div class="wtg-vc-chips">${chipHTML}</div>` : ''}
      ${areaHTML}
      <button class="wtg-vc-book"
        onclick="WtgSheet.open('${_esc(String(id))}')">
        BOOK NOW
      </button>
    </div>`;
}

function _renderInstantSearch() {
  const panel = document.getElementById("search-results-panel");
  if (!panel) return;
  const hasQuery = _searchQuery.length > 0;
  // With a query, prefer the backend's broader match (vendor name, category,
  // locality — see commit a6bcbe2) once it has landed; below the 2-char
  // minimum (or before the debounced call resolves) fall back to an instant
  // client-side filter over whatever's already loaded, same field set as the
  // home feed's own fallback (_searchText). No query: show everything, same
  // as before.
  let list = _allServices.length ? _allServices : [];
  if (hasQuery) {
    list = _searchRemoteServices.length ? _searchRemoteServices : list.filter(s => _searchText(s).includes(_searchQuery));
  }
  panel.classList.remove("hidden");
  panel.innerHTML = `
    <div class="instant-search-head"><strong>${hasQuery ? `Results for "${_esc(_searchQuery)}"` : "Available Vendors"}</strong></div>
    <div style="padding:4px 0">
      ${list.length
        ? list.map(s => _vendorCardHTML(s)).join("")
        : `<p style="color:#888;text-align:center;padding:20px">${hasQuery ? "No vendors match that search." : "No vendors available in your area."}</p>`}
    </div>`;
  if (window.WtgSheet) WtgSheet.setVendors(list);
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

function _setupMobileChromeMotion() {
  if (window.__wtgChromeMotionBound) return;
  window.__wtgChromeMotionBound = true;
  let lastY = window.scrollY || 0;
  let ticking = false;
  window.addEventListener("scroll", () => {
    if (document.body.classList.contains("modal-open") || document.body.classList.contains("explore-open")) return;
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(() => {
      const y = window.scrollY || 0;
      const down = y > lastY + 8 && y > 90;
      const up = y < lastY - 8 || y < 40;
      document.body.classList.toggle("chrome-hidden", down && !up);
      lastY = y;
      ticking = false;
    });
  }, { passive: true });
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
  if (service?.price && Number(service.price) > 0) return String(service.price);
  const amount = service?.base_price ?? service?.amount ?? service?.starting_price;
  return (amount && Number(amount) > 0) ? UI.formatCurrency(amount) : "";
}

function _localityViewportHandler() {
  if (document.body.dataset.modalOpen !== "locality") {
    // Modal was closed or page navigated away — self-remove to prevent leak
    window.visualViewport?.removeEventListener("resize", _localityViewportHandler);
    return;
  }
  const sheet = document.querySelector(".locality-modal .modal-sheet");
  if (!sheet) return;
  const vv = window.visualViewport;
  if (!vv) return;
  const offset = window.innerHeight - vv.height;
  sheet.style.maxHeight = `calc(${vv.height}px - 40px)`;
  sheet.style.marginBottom = offset > 0 ? `${offset}px` : "0";
}

function _openLocalitySelector() {
  _renderLocalitySelector("", { full: true });
  document.getElementById("locality-modal")?.classList.remove("hidden");
  document.body.classList.add("modal-open");
  document.body.dataset.modalOpen = "locality";
  const current = _resolvedLocality();
  if (current.source === "fallback") {
    const hint = _browserLocalityHint(false);
    if (hint?.label) {
      _activeLocalityFilter = hint.label;
      _localityContext = hint;
      _persistLocalityContext();
      _persistHomeState();
      _refreshLocalitySurfaces();
      _renderLocalitySelector("", { full: true });
    }
  }
  if (window.visualViewport) {
    window.visualViewport.addEventListener("resize", _localityViewportHandler);
  }
  setTimeout(() => document.getElementById("locality-manual-input")?.focus({ preventScroll: true }), 40);
}

function _closeLocalitySelector() {
  document.getElementById("locality-modal")?.classList.add("hidden");
  if (document.body.dataset.modalOpen === "locality") {
    document.body.classList.remove("modal-open");
    delete document.body.dataset.modalOpen;
  }
  if (window.visualViewport) {
    window.visualViewport.removeEventListener("resize", _localityViewportHandler);
  }
  // Reset any inline styles set by the viewport handler
  const sheet = document.querySelector(".locality-modal .modal-sheet");
  if (sheet) { sheet.style.maxHeight = ""; sheet.style.marginBottom = ""; }
}

function _localitySelectorHTML(query = "") {
  return `
    <div class="locality-search-wrap">
      <span class="locality-search-icon">🔍</span>
      <input type="text" id="locality-manual-input" class="locality-search-input" placeholder="Search your area..." value="${_esc(query)}" autocomplete="off" oninput="HomePage.filterLocalitySuggestions(this.value)" onkeydown="if(event.key==='Enter'){HomePage.submitLocalitySearch()}">
      <button id="gps-locate-btn" class="locality-gps-btn" type="button" onclick="HomePage.detectGPSLocality()">📍</button>
    </div>
    <div id="locality-suggestions">${_localitySuggestionsHTML(query)}</div>`;
}

function _localitySuggestionsHTML(query = "") {
  const search = _cleanLocality(query).toLowerCase();
  const rowStyle = "min-height:56px;padding:14px 12px;display:flex;align-items:center;gap:10px;width:100%;border:none;border-bottom:1px solid var(--clr-border);background:transparent;color:var(--clr-text-1);font-size:15px;text-align:left;cursor:pointer;box-sizing:border-box;";
  if (!search) {
    return `<p style="padding:14px 16px;color:var(--clr-text-2);font-size:14px;margin:0;">Type to search your area</p>`;
  }
  if (search.length < 2) {
    return `<p style="padding:14px 16px;color:var(--clr-text-2);font-size:14px;margin:0;">Keep typing to search…</p>`;
  }
  // >= 2 chars: show placeholder while _searchPlacesAsync() fetches and updates the div
  return `<p style="padding:14px 16px;color:var(--clr-text-2);font-size:14px;margin:0;">Searching areas…</p>`;
}

let _placesDebounceTimer = null;

function _searchPlacesAsync(query = "") {
  clearTimeout(_placesDebounceTimer);
  const search = _cleanLocality(query).toLowerCase();
  if (search.length < 2) return;

  _placesDebounceTimer = setTimeout(() => {
    const listEl = document.getElementById("locality-suggestions");
    if (!listEl) return;

    const rowStyle = "min-height:56px;padding:14px 12px;display:flex;align-items:center;gap:10px;width:100%;border:none;border-bottom:1px solid var(--clr-border);background:transparent;color:var(--clr-text-1);font-size:15px;text-align:left;cursor:pointer;box-sizing:border-box;";

    if (!window.google?.maps?.places) {
      listEl.innerHTML = `<p style="padding:14px 16px;color:var(--clr-text-2);font-size:14px;margin:0;">Could not search right now — try again</p>`;
      return;
    }

    if (!window._wtgPlacesService) {
      window._wtgPlacesService = new google.maps.places.AutocompleteService();
    }

    window._wtgPlacesService.getPlacePredictions({
      input: query,
      componentRestrictions: { country: "in" },
      types: ["(regions)"],
    }, (predictions, status) => {
      const el = document.getElementById("locality-suggestions");
      if (!el) return;
      if (status === "OK" && predictions && predictions.length) {
        el.innerHTML = predictions.map(pred => {
          const main = _esc(pred.structured_formatting?.main_text || pred.description);
          const secondary = _esc(pred.structured_formatting?.secondary_text || "");
          const rawMain = pred.structured_formatting?.main_text || pred.description;
          const rawSecondary = pred.structured_formatting?.secondary_text || "";
          return `<button type="button" style="${rowStyle}" onclick="window._wtgOnPlacePick('${_esc(rawMain)}','${_esc(rawSecondary)}')">
            <span>📍</span>
            <span style="flex:1;min-width:0;overflow:hidden;">
              <strong style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${main}</strong>
              ${secondary ? `<small style="font-size:12px;color:var(--clr-text-2);display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${secondary}</small>` : ""}
            </span>
          </button>`;
        }).join("");
      } else {
        el.innerHTML = `<p style="padding:14px 16px;color:var(--clr-text-2);font-size:14px;margin:0;">No matching area found. Try a different spelling or nearby landmark.</p>`;
      }
    });
  }, 300);
}

window._wtgOnPlacePick = function(label, secondaryText) {
  const clean = _cleanLocality(label);
  if (!clean) return;
  const cityRaw = _cleanLocality((secondaryText || "").split(",")[0]);
  _activeLocalityFilter = clean;
  _localityContext = { label: clean, city: cityRaw || _inferCityForLocality(clean), source: "places_autocomplete", updated_at: Date.now() };
  _persistLocalityContext();
  _persistHomeState();
  _refreshLocalitySurfaces();
  _closeLocalitySelector();
  UI.toast(`${clean} area set`, "success");
};

function _localityOptions(meta = {}) {
  const profile = _customerProfile();
  const saved = _readSavedLocality();
  const city = _activeCity();
  const values = [
    _activeLocalityFilter,
    saved?.label,
    profile.locality,
    profile.area,
    ...(_cityLocalities(city) || []),
    ...(meta.locality || []),
    ...(CATEGORY_META.all.locality || []),
    city,
  ].map(v => String(v || "").trim()).filter(Boolean);
  const seen = new Set();
  return values.filter(v => {
    const key = _slug(v);
    if (!key || seen.has(key)) return false;
    seen.add(key);
    return true;
  }).slice(0, 8);
}

function _localitySearchOptions(meta = {}, search = "") {
  const active = _resolvedLocality();
  const values = [
    { label: active.label, type: "area", note: "Current area" },
    { label: active.city || _activeCity(), type: "city", note: "Current city" },
    ..._cityOptions().map(label => ({ label, type: "city", note: "Search your city" })),
    ..._localityOptions(meta).map(label => ({ label, type: "area", note: "Nearby area" })),
    ..._cityOptions().flatMap(city => _cityLocalities(city).map(label => ({ label, type: "area", note: city }))),
  ];
  const seen = new Set();
  return values
    .map(item => ({ ...item, label: _cleanLocality(item.label) }))
    .filter(item => {
      const key = _slug(item.label);
      if (!key || seen.has(key)) return false;
      if (search && !`${item.label} ${item.note}`.toLowerCase().includes(search)) return false;
      seen.add(key);
      return true;
    });
}

function _recentLocalityLabel() {
  const saved = _readSavedLocality();
  const active = _resolvedLocality();
  if (saved?.label && _slug(saved.label) !== _slug(active.label)) return saved.label;
  return "";
}

function _setManualCity(city = "") {
  const label = _cleanLocality(city);
  if (!label) return;
  const defaultArea = _cityLocalities(label)[0] || label;
  _activeLocalityFilter = defaultArea;
  _localityContext = { label: defaultArea, city: label, source: "manual_city", updated_at: Date.now() };
  _persistLocalityContext();
  _persistHomeState();
  _refreshLocalitySurfaces();
  _closeLocalitySelector();
  UI.toast(`${defaultArea} near you`, "success");
}

function _setManualLocality(locality = "", source = "selected", opts = {}) {
  const label = _cleanLocality(locality);
  if (!label) return;
  _activeLocalityFilter = label;
  _localityContext = { label, city: _inferCityForLocality(label), source: source === "typed" ? "manual_typed" : "manual_selected", updated_at: Date.now() };
  _persistLocalityContext();
  _persistHomeState();
  _refreshLocalitySurfaces();
  if (!opts.keepModalOpen) _closeLocalitySelector();
  if (!opts.silent) UI.toast(`${label} near you`, "success");
}

function _clearManualLocality() {
  _activeLocalityFilter = "";
  _localityContext = null;
  try { localStorage.removeItem("wtg_locality_context"); } catch {}
  _persistHomeState();
  _refreshLocalitySurfaces();
  _renderLocalitySelector();
  UI.toast(`${_pilotConfig.city} fallback city active`, "info");
}

function _useBrowserLocalityHint() {
  const hint = _browserLocalityHint(false);
  if (!hint?.label) {
    UI.toast("No browser locality hint is available. Please choose or type an area.", "info");
    return;
  }
  _activeLocalityFilter = hint.label;
  _localityContext = hint;
  _persistLocalityContext();
  _persistHomeState();
  _refreshLocalitySurfaces();
  _renderLocalitySelector();
  UI.toast(`${hint.label} browser hint active`, "info");
}

function _commitTypedLocality(value = "") {
  const label = _cleanLocality(value);
  if (!label) return;
  _activeLocalityFilter = label;
  _localityContext = { label, city: _inferCityForLocality(label), source: "typed", updated_at: Date.now() };
  _persistLocalityContext();
  _persistHomeState();
  _renderLocalityHeader();
}

function _refreshLocalitySurfaces() {
  _renderLocalityHeader();
  _renderOperatingFeed();
  _renderCategoryEcosystem();
  _renderQuickServiceCards();
  _renderContextProof();
  _renderInstantSearch();
  _renderServices({ ok: true, data: { services: _allServices } });
}

function _resolvedLocality() {
  const selected = _cleanLocality(_activeLocalityFilter || _localityContext?.label);
  if (selected) return { label: selected, city: _localityContext?.city || _inferCityForLocality(selected), source: _localityContext?.source || "manual_selected" };
  const saved = _readSavedLocality();
  if (saved?.label) return saved;
  const hint = _browserLocalityHint(false);
  if (hint?.label) return hint;
  const fallbackCity = _pilotConfig.city || "your area";
  return { label: fallbackCity, city: fallbackCity, source: "fallback" };
}

function _readSavedLocality() {
  try {
    const saved = JSON.parse(localStorage.getItem("wtg_locality_context") || "{}");
    if (saved?.label) return { label: _cleanLocality(saved.label), city: _cleanLocality(saved.city) || _inferCityForLocality(saved.label), source: saved.source || "saved" };
  } catch {}
  const profile = _customerProfile();
  const label = _cleanLocality(profile.locality || profile.area);
  return label ? { label, city: _inferCityForLocality(label), source: "saved" } : null;
}

function _browserLocalityHint(create = true) {
  try {
    const raw = sessionStorage.getItem("wtg_browser_locality_hint");
    const parsed = raw ? JSON.parse(raw) : null;
    if (parsed?.label) return { label: _cleanLocality(parsed.label), city: _cleanLocality(parsed.city) || _inferCityForLocality(parsed.label), source: "browser_hint" };
  } catch {}
  if (!create) return null;
  const label = _cleanLocality(_pilotConfig.browser_hint || CONFIG.SERVICE_ONLY?.BROWSER_LOCALITY_HINT || "");
  if (!label) return null;
  const hint = { label, city: _inferCityForLocality(label), source: "browser_hint", updated_at: Date.now() };
  try { sessionStorage.setItem("wtg_browser_locality_hint", JSON.stringify(hint)); } catch {}
  return hint;
}

async function _geocodeAndSaveTyped(value = "") {
  const key = CONFIG.GOOGLE_MAPS_KEY || "";
  if (!key) {
    UI.toast("Could not find that area. Please select from search suggestions instead.", "error");
    return false;
  }
  try {
    const url = `https://maps.googleapis.com/maps/api/geocode/json?address=${encodeURIComponent(value)}&components=country:IN&key=${encodeURIComponent(key)}&language=en`;
    const res = await fetch(url);
    const data = await res.json();
    if (data.status === "OK" && data.results?.[0]) {
      const components = data.results[0].address_components || [];
      const findC = type => components.find(c => Array.isArray(c.types) && c.types.includes(type))?.long_name || "";
      const area = _cleanLocality(findC("sublocality_level_1") || findC("sublocality") || findC("neighborhood") || findC("locality"));
      if (!area) {
        UI.toast("Could not find that area. Please select from search suggestions instead.", "error");
        return false;
      }
      _setManualLocality(area, "geocoded");
      return true;
    }
  } catch {}
  UI.toast("Could not find that area. Please select from search suggestions instead.", "error");
  return false;
}

async function _detectGPSLocality() {
  if (!navigator.geolocation) {
    UI.toast("Location not supported by your browser.", "error");
    return;
  }
  const btn = document.getElementById("gps-locate-btn");
  if (btn) { btn.disabled = true; btn.innerHTML = '⏳'; }

  navigator.geolocation.getCurrentPosition(
    async pos => {
      const { latitude, longitude } = pos.coords;
      try {
        let area = "";
        let city = "";

        // Try Google Geocoding first if a key is configured
        const key = CONFIG.GOOGLE_MAPS_KEY || "";
        if (key) {
          const gUrl = `https://maps.googleapis.com/maps/api/geocode/json?latlng=${latitude},${longitude}&key=${encodeURIComponent(key)}&language=en&region=IN`;
          const gRes = await fetch(gUrl);
          const gData = await gRes.json();
          if (gData.status === "OK" && gData.results?.[0]) {
            const components = gData.results[0].address_components || [];
            const findC = type => components.find(c => Array.isArray(c.types) && c.types.includes(type))?.long_name || "";
            area = _cleanLocality(findC("sublocality_level_1") || findC("sublocality") || findC("neighborhood"));
            city = _cleanLocality(findC("locality"));
          }
        }

        // Fallback to OSM Nominatim when key absent or Google returned no result
        if (!area && !city) {
          const osmUrl = `https://nominatim.openstreetmap.org/reverse?lat=${latitude}&lon=${longitude}&format=json&accept-language=en`;
          const osmRes = await fetch(osmUrl, { headers: { "User-Agent": "WorkToGo/1.0" } });
          const osmData = await osmRes.json();
          const addr = osmData.address || {};
          area = _cleanLocality(addr.suburb || addr.neighbourhood || addr.village || "");
          city = _cleanLocality(addr.city || addr.town || addr.county || addr.state_district || "");
        }

        const label = area && city ? `${area}, ${city}` : (area || city);
        if (!label) {
          UI.toast("Could not identify area. Select manually.", "error");
          _resetGPSBtn();
          return;
        }
        _setManualLocality(label, "browser_hint");
        UI.toast(`Location set: ${label}`, "success");
      } catch {
        UI.toast("Location lookup failed. Select manually.", "error");
        _resetGPSBtn();
      }
    },
    err => {
      UI.toast(err.code === 1 ? "Location permission denied." : "Could not get location.", "error");
      _resetGPSBtn();
    },
    { timeout: 8000, maximumAge: 0 }
  );
}

function _maybeAutoDetectLocality() {
  let saved = null;
  try { saved = JSON.parse(localStorage.getItem("wtg_locality_context") || "null"); } catch {}
  if (!saved?.label) _detectGPSLocality();
}

function _attachAddressAutocomplete() {
  const input = document.getElementById("booking-address");
  if (!input || !window.google?.maps?.places) return;
  const autocomplete = new google.maps.places.Autocomplete(input, {
    componentRestrictions: { country: "in" },
    fields: ["formatted_address", "geometry", "name", "address_components"],
    types: ["address"],
  });
  autocomplete.addListener("place_changed", () => {
    const place = autocomplete.getPlace();
    if (place?.formatted_address) {
      input.value = place.formatted_address;
      _persistPendingBookingForm();
    }
  });
}

function _resetGPSBtn() {
  const btn = document.getElementById("gps-locate-btn");
  if (btn) { btn.disabled = false; btn.innerHTML = '📍'; }
}

function _restoreLocalityContext() {
  const saved = _readSavedLocality();
  const sessionLabel = _cleanLocality(_activeLocalityFilter);
  if (sessionLabel) {
    _localityContext = {
      label: sessionLabel,
      city: _localityContext?.city || saved?.city || _inferCityForLocality(sessionLabel),
      source: _localityContext?.source || "manual_selected",
      updated_at: Date.now(),
    };
    return;
  }
  if (saved?.label) {
    _activeLocalityFilter = saved.label;
    _localityContext = saved;
  }
}

function _persistLocalityContext() {
  const current = _resolvedLocality();
  if (current.source === "fallback") return;
  try { localStorage.setItem("wtg_locality_context", JSON.stringify({ ...current, updated_at: Date.now() })); } catch {}
}

function _localityStatusLine() {
  const current = _resolvedLocality();
  return `${current.label} near you`;
}

function _localityRequestLine() {
  return `${_resolvedLocality().label} area active`;
}

function _localitySourceLine() {
  const source = _resolvedLocality().source;
  if (source === "manual_selected" || source === "manual_typed" || source === "typed") return "Current area";
  if (source === "manual_city") return "Current city";
  if (source === "saved") return "Recent area";
  if (source === "browser_hint") return "Approximate city";
  return "Current area";
}

function _cleanLocality(value = "") {
  return String(value || "").replace(/\s+/g, " ").trim().slice(0, 80);
}

function _activeCity() {
  return _cleanLocality(_resolvedLocality().city || _pilotConfig.city || "your area");
}

function _cityOptions() {
  const configured = Array.isArray(_pilotConfig.cities) ? _pilotConfig.cities : [];
  const base = ["Haldwani"];
  const values = [...configured, ...base, _pilotConfig.city].map(_cleanLocality).filter(Boolean);
  const seen = new Set();
  return values.filter(city => {
    const key = _slug(city);
    if (!key || seen.has(key)) return false;
    seen.add(key);
    return true;
  }).slice(0, 8);
}

function _cityLocalities(city = "") {
  const key = _slug(city);
  const configured = _pilotConfig.city_localities?.[city] || _pilotConfig.city_localities?.[key] || [];
  const defaults = {
    haldwani: [...LOCAL_AREAS],
  };
  return (Array.isArray(configured) && configured.length ? configured : defaults[key] || []).map(_cleanLocality).filter(Boolean);
}

function _inferCityForLocality(locality = "") {
  const label = _cleanLocality(locality);
  const labelKey = _slug(label);
  for (const city of _cityOptions()) {
    const cityKey = _slug(city);
    if (labelKey === cityKey) return city;
    if (_cityLocalities(city).some(area => _slug(area) === labelKey)) return city;
  }
  return _pilotConfig.city || "your area";
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

function _inspectionCategorySlug(slug = "") {
  const normalized = _slug(slug);
  const cats = _inspectionCategories();
  const allowed = cats.map(c => c.slug);
  if (allowed.includes(normalized)) return normalized;
  if (normalized === "carpenter" && allowed.includes("carpentry")) return "carpentry";
  if (_activeCategory && allowed.includes(_slug(_activeCategory))) return _slug(_activeCategory);
  return allowed[0] || "electrician";
}

function _inspectionCategories() {
  if (_serviceCategories.length) {
    return _serviceCategories.map(c => ({ slug: c.slug, label: c.label }));
  }
  return [
    { slug: "electrician", label: "Electrician" },
    { slug: "plumber", label: "Plumbing" },
    { slug: "painting", label: "Painting" },
    { slug: "cctv", label: "CCTV" },
    { slug: "waterproofing", label: "Waterproofing" },
    { slug: "carpentry", label: "Carpenter" },
    { slug: "ac-repair", label: "AC Repair" },
  ];
}

function _inspectionIssues(slug = "") {
  // Reuse the same example labels shown on the category's quick-service
  // chips (CATEGORY_META[...].examples) instead of a separate hardcoded
  // list — keeping a single source of truth means a clicked quick-chip
  // (e.g. "Gas check" under AC repair) always matches an Issue-type option
  // by exact label, so the modal highlights and submits the right one.
  const resolved = _inspectionCategorySlug(slug);
  const meta = _categoryMeta(resolved);
  return meta.examples?.length ? meta.examples : _categoryMeta("electrician").examples;
}

function _inspectionCategoryChips(active = "") {
  return _inspectionCategories().map(c => `<button type="button" class="inspection-category-chip ${active === c.slug ? "active" : ""}" data-category="${_esc(c.slug)}" onclick="HomePage.selectInspectionCategory('${_esc(c.slug)}')">${_esc(c.label)}</button>`).join("");
}

function _inspectionIssueChips(category = "", active = []) {
  const activeIssues = _normalizeIssueList(active);
  return _inspectionIssues(category).map(issue => `<button type="button" class="inspection-issue-chip ${activeIssues.includes(issue) ? "active" : ""}" data-issue="${_esc(issue)}" aria-pressed="${activeIssues.includes(issue) ? "true" : "false"}" onclick="HomePage.selectInspectionIssue('${_esc(issue)}')">${_esc(issue)}</button>`).join("");
}

function _selectedIssuesFromValue(value = "") {
  return _normalizeIssueList(value);
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

function _issueSummary(value = "") {
  return _normalizeIssueList(value)
    .map(item => item.replace(/\b(issue|problem|service|work)\b$/i, "").replace(/\s+/g, " ").trim())
    .filter(Boolean)
    .slice(0, 4)
    .join(" + ");
}

function _operationalTags({ bookingMode = "", category = {}, issueList = [], area = "", priority = {} } = {}) {
  const tags = new Set(["local_request"]);
  tags.add(bookingMode === "inspection" ? "paid" : "free");
  if (bookingMode === "inspection") tags.add("inspection");
  const categoryTag = _slug(category.slug || category.label);
  if (categoryTag) tags.add(categoryTag);
  if ((priority.score || 0) >= 60 || priority.level === "high_priority") tags.add("urgent");
  _normalizeIssueList(issueList).map(_slug).filter(Boolean).forEach(tag => tags.add(tag));
  const localityTag = _slug(area || _resolvedLocality().label);
  if (localityTag) tags.add(`area_${localityTag}`);
  return [...tags].filter(tag => /^[a-z0-9_]+$/.test(tag));
}

function _isValidOperationalRequest(payload = {}) {
  const requiredScalar = [
    "request_id", "client_request_id", "created_at", "request_type", "lifecycle_state", "assignment_state",
    "customer_name", "customer_mobile", "category", "category_label", "issue_summary", "city", "locality", "full_address",
    "payment_route", "priority", "priority_score", "request_schema_version"
  ];
  if (!requiredScalar.every(key => payload[key] !== undefined && payload[key] !== null && String(payload[key]).trim() !== "")) return false;
  if (!Array.isArray(payload.issue_list) || payload.issue_list.length < 1) return false;
  if (typeof payload.payment_required !== "boolean") return false;
  if (!payload.routing_context || typeof payload.routing_context !== "object") return false;
  if (!payload.sorting_keys || typeof payload.sorting_keys !== "object") return false;
  if (!Array.isArray(payload.timeline) || payload.timeline.length < 1) return false;
  if (!Array.isArray(payload.operational_tags) || payload.operational_tags.length < 1) return false;
  return true;
}

function _requestType(mode = "") {
  if (mode === "inspection") return "inspection";
  if (mode === "direct_vendor") return "direct_worker";
  return "free_match";
}

function _bookingModeMeaning(mode = "") {
  if (mode === "inspection") return "Inspection request: paid visit, issue verification and worker assignment";
  if (mode === "direct_vendor") return "Direct worker request: WorkToGo asks the selected worker and confirms availability";
  return "Free match: WorkToGo starts nearby worker matching for a clear job";
}

  function _initialTrackingState(mode = "") {
    if (mode === "inspection") return "payment_pending";
    if (mode === "direct_vendor") return "worker_requested";
    return "request_received";
  }

function _initialAssignmentState(mode = "") {
  if (mode === "inspection") return "payment_pending";
  if (mode === "direct_vendor") return "selected_worker_pending";
  return "unassigned_searching";
}

function _clientRequestId(mode = "", category = "") {
  const context = `${_slug(mode || "request")}:${_slug(category || "service")}`;
  try {
    const raw = sessionStorage.getItem("wtg_active_client_request") || localStorage.getItem("wtg_active_client_request");
    const saved = raw ? JSON.parse(raw) : null;
    if (saved?.id && saved.context === context && Date.now() - Number(saved.ts || 0) < 30 * 60 * 1000) return saved.id;
  } catch {}
  const stamp = Date.now().toString(36);
  const rand = Math.random().toString(36).slice(2, 7);
  const id = `wtg-${_slug(mode || "request")}-${_slug(category || "service")}-${stamp}-${rand}`;
  try {
    const record = JSON.stringify({ id, context, ts: Date.now() });
    sessionStorage.setItem("wtg_active_client_request", record);
    localStorage.setItem("wtg_active_client_request", record);
  } catch {}
  return id;
}

function _deriveRequestPriority({ bookingMode = "", category = {}, issueList = [], notes = "" } = {}) {
  const haystack = [..._normalizeIssueList(issueList), category.slug, category.label, notes].join(" ").toLowerCase();
  let score = bookingMode === "inspection" ? 35 : 15;
  if (/leak|leakage|seepage|bathroom leakage|roof seepage|pipe burst|water/.test(haystack)) score += 45;
  if (/short circuit|sparking|spark|mcb|wiring|inverter down|inverter|electric shock|burn/.test(haystack)) score += 45;
  if (/not working|blockage|motor issue|fan issue|geyser|camera not working/.test(haystack)) score += 20;
  if (/painting|paint|repaint|decorative|consultation|color|texture|putty/.test(haystack)) score -= 10;
  const level = score >= 60 ? "high_priority" : score >= 35 ? "medium_priority" : "normal_priority";
  const reason = level === "high_priority" ? "service_issue_risk" : bookingMode === "inspection" ? "paid_inspection" : "standard_request";
  return { level, score: Math.max(0, Math.min(score, 100)), reason };
}

function _initialRequestTimeline({ mode = "free_lead", state = "request_received", createdAt = new Date().toISOString(), paymentRequired = false } = {}) {
  const base = [{ event: "request_created", state, at: createdAt, actor: "customer", visible_to_customer: true }];
  if (mode === "inspection" || paymentRequired) {
    base.push({ event: "payment_pending", state: "payment_pending", at: createdAt, actor: "system", visible_to_customer: true });
    return base;
  }
  base.push({ event: "searching_worker", state: mode === "direct_vendor" ? "worker_requested" : "searching_worker", at: createdAt, actor: "system", visible_to_customer: true });
  return base;
}

function _requestSortingKeys({ category = {}, issueList = [], area = "", bookingMode = "", priority = {}, trackingState = "" } = {}) {
  const locality = _cleanLocality(area || _resolvedLocality().label);
  return {
    city: _slug(_inferCityForLocality(locality)),
    locality: _slug(locality),
    category: _slug(category.slug || category.label),
    issue_type: _normalizeIssueList(issueList).map(_slug).filter(Boolean).join("|"),
    priority: priority.level || "normal_priority",
    payment_type: bookingMode === "inspection" ? "paid_inspection" : "free_request",
    assignment_state: _initialAssignmentState(bookingMode),
    lifecycle_state: trackingState,
  };
}

function _routingContext({ category = {}, issueList = [], area = "", selectedWorker = null, priority = {}, bookingMode = "" } = {}) {
  return {
    assignment_mode: selectedWorker?.vendor_id ? "selected_worker" : "admin_queue",
    category_slug: _slug(category.slug || category.label),
    issue_keys: _normalizeIssueList(issueList).map(_slug).filter(Boolean),
    locality_key: _slug(area || _resolvedLocality().label),
    city_key: _slug(_inferCityForLocality(area || _resolvedLocality().label)),
    priority: priority.level || "normal_priority",
    payment_route: bookingMode === "inspection" ? "paid_inspection" : "free_request",
    vendor_id: selectedWorker?.vendor_id || null,
    route_ready: true,
  };
}

function _sessionContext() {
  const locality = _resolvedLocality();
  return {
    home_state: _safeSessionJSON("wtg_home_state"),
    pending_booking_form: _safeSessionJSON("wtg_pending_booking_form"),
    active_category: _activeCategory,
    selected_issue: _activeChipFilter,
    selected_locality: locality.label,
    selected_city: locality.city || _activeCity(),
    locality_source: locality.source,
    discovery_kind: _activeDiscoveryKind,
    search_query: _searchQuery,
  };
}

function _safeSessionJSON(key) {
  try { return JSON.parse(sessionStorage.getItem(key) || "{}"); } catch { return {}; }
}

function _adminRequestNotes(payload = {}) {
  const worker = payload.selected_worker?.vendor_id ? `${payload.selected_worker.vendor_name || "Selected worker"} (#${payload.selected_worker.vendor_id})` : "Not selected";
  const issueList = _normalizeIssueList(payload.issue_list || payload.issue_type || payload.subservice).join(", ");
  return [
    `Request ID: ${payload.request_id || payload.client_request_id || ""}`,
    `Client request ID: ${payload.client_request_id || payload.request_id || ""}`,
    `Request schema version: ${payload.request_schema_version || 1}`,
    `Request type: ${payload.request_type}`,
    `Category: ${payload.category_label || payload.category}`,
    `Issue list: ${issueList || "Not specified"}`,
    `Issue summary: ${payload.issue_summary || issueList || "Not specified"}`,
    `Priority: ${payload.priority || "normal_priority"}`,
    `Priority score: ${payload.priority_score ?? ""}`,
    `Booking mode: ${payload.booking_mode}`,
    `Payment required: ${payload.payment_required ? "yes" : "no"}`,
    `Payment route: ${payload.payment_route || (payload.booking_mode === "inspection" ? "paid_inspection" : "free_request")}`,
    `Lifecycle state: ${payload.lifecycle_state || payload.tracking_state}`,
    `Assignment state: ${payload.assignment_state || ""}`,
    `Request source: ${payload.request_source}`,
    `Customer: ${payload.customer?.name || "WorkToGo Customer"}`,
    `Mobile: ${payload.customer?.phone || ""}`,
    `Locality: ${payload.locality || ""}`,
    `City: ${payload.city || payload.selected_city || ""}`,
    `Address: ${payload.full_address || ""}`,
    `Preferred time: ${payload.preferred_time || ""}`,
    `Selected worker: ${worker}`,
    `Issue note: ${payload.issue_note || ""}`,
    `Operational tags: ${(payload.operational_tags || []).join(", ")}`,
    `Timeline: ${JSON.stringify(payload.timeline || [])}`,
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
  const live = !!(service.vendor_state === "live" || service.is_live === true);
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
    if (state.locality_source && _activeLocalityFilter) _localityContext = { label: _activeLocalityFilter, city: state.city || _inferCityForLocality(_activeLocalityFilter), source: state.locality_source };
    _activeDiscoveryKind = state.discovery || "";
    _searchQuery = state.query || "";
  } catch {}
}

function _persistHomeState() {
  try {
    const locality = _resolvedLocality();
    sessionStorage.setItem("wtg_home_state", JSON.stringify({ category: _activeCategory, chip: _activeChipFilter, locality: locality.source === "fallback" ? "" : locality.label, city: locality.city || _activeCity(), locality_source: locality.source, discovery: _activeDiscoveryKind, query: _searchQuery }));
  } catch {}
}

function _pendingBookingForm() {
  try { return JSON.parse(sessionStorage.getItem("wtg_pending_booking_form") || "{}"); } catch { return {}; }
}

function _sameBookingContext(restored = {}, selectedService = "", category = {}) {
  if (!restored.service_context && !restored.category) return true;
  const restoredCategory = _slug(restored.category || "");
  const currentCategory = _slug(category.slug || _activeCategory || "");
  const sameService = !restored.service_context || restored.service_context === selectedService;
  const sameCategory = !restoredCategory || restoredCategory === currentCategory;
  return sameService && sameCategory;
}

function _persistPendingBookingForm() {
  try {
    if (!document.getElementById("booking-service-context")) return;
    sessionStorage.setItem("wtg_pending_booking_form", JSON.stringify({
      category: document.getElementById("booking-category-context")?.value?.trim() || "",
      booking_mode: document.getElementById("booking-mode")?.value || "",
      service_context: document.getElementById("booking-service-context")?.value?.trim() || "",
      issue_list: _normalizeIssueList(document.getElementById("booking-service-context")?.value || ""),
      scheduled_at: document.getElementById("booking-date")?.value || "",
      name: document.getElementById("booking-name")?.value?.trim() || "",
      phone: document.getElementById("booking-mobile")?.value?.trim() || "",
      locality: document.getElementById("booking-area")?.value?.trim() || "",
      address: document.getElementById("booking-address")?.value?.trim() || "",
      notes: document.getElementById("booking-notes")?.value?.trim() || "",
      locality_context: _resolvedLocality(),
    }));
  } catch {}
}

function _clearPendingBookingForm() {
  try {
    sessionStorage.removeItem("wtg_pending_booking_form");
    sessionStorage.removeItem("wtg_active_client_request");
    localStorage.removeItem("wtg_active_client_request");
  } catch {}
}

function _clearPendingBookingIntent() {
  try {
    sessionStorage.removeItem("wtg_pending_booking");
    sessionStorage.removeItem("wtg_resume_booking_once");
  } catch {}
}

function _friendlyServiceError(error = "") {
  const msg = String(error || "").toLowerCase();
  if (msg.includes("internal server") || msg.includes("500")) return "Worker list is being coordinated.";
  if (msg.includes("network") || msg.includes("failed")) return "Worker matching can continue through confirmation.";
  return "Worker matching is available through booking confirmation.";
}

function _savePendingBookingIntent(service, form = {}) {
  if (!service) return;
  try {
    const category = service.category_slug || service.category || _activeCategory || "";
    sessionStorage.setItem("wtg_pending_booking", JSON.stringify({ service: { ...service, category_slug: category }, category, form, locality_context: _resolvedLocality(), ts: Date.now(), token: _bookingOpenToken }));
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
    if (pending.locality_context?.label) {
      _activeLocalityFilter = pending.locality_context.label;
      _localityContext = pending.locality_context;
    } else if (pending.form?.locality) _commitTypedLocality(pending.form.locality);
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

// Keyword → icon rules so any new admin-created category automatically gets a
// sensible emoji at render time, with no per-category code change required.
const _CATEGORY_ICON_RULES = [
  [/plumb|\bpipe\b|\btap\b|faucet/, "🚰"],
  [/electric|wiring|\bmcb\b|switch/, "⚡"],
  [/paint/, "🎨"],
  [/water.?proof|seepage|damp|leak/, "💧"],
  [/cctv|camera|security/, "📹"],
  [/carpent|\bwood|furniture|wardrobe|\bdoor\b/, "🪚"],
  [/\bac\b|air.?condition|cooling|\bcool\b/, "❄️"],
  [/clean/, "🧹"],
  [/applianc|fridge|washer|washing|geyser|\bro\b/, "🔧"],
  [/tutor|tuition|\bteach/, "📚"],
  [/inspect/, "🛡️"],
];
const _CATEGORY_ICON_DEFAULT = "🧰";

function _matchCategoryIcon(name = "") {
  const n = String(name || "").toLowerCase();
  for (const [pattern, icon] of _CATEGORY_ICON_RULES) if (pattern.test(n)) return icon;
  return _CATEGORY_ICON_DEFAULT;
}

// Issue/example-level keyword rules so the per-category quick-service chips
// (e.g. CCTV's Camera install/DVR setup/Wiring/Shop security) get distinct
// icons instead of all repeating the category's single icon. Checked before
// falling back to the category-level matcher, then the generic default.
const _ISSUE_ICON_RULES = [
  [/camera/, "📷"],
  [/\bdvr\b|\bnvr\b|wiring|\bwire\b/, "🔌"],
  [/security/, "🛡️"],
  [/\bfan\b/, "🌀"],
  [/switch/, "🔌"],
  [/\bmcb\b/, "⚡"],
  [/light/, "💡"],
  [/\btap\b/, "🚿"],
  [/pipe/, "🧰"],
  [/bathroom/, "🚽"],
  [/leak/, "💧"],
  [/\broom\b/, "🏠"],
  [/putty/, "🪣"],
  [/exterior/, "🧱"],
  [/colou?r/, "🎨"],
  [/roof|monsoon/, "🌧️"],
  [/damp/, "🏚️"],
  [/crack|seal/, "🧪"],
  [/\bdoor\b/, "🚪"],
  [/wardrobe/, "🧱"],
  [/furniture/, "🪵"],
  [/polish/, "🪚"],
  [/kitchen/, "🍳"],
  [/\bhome\b/, "🏠"],
  [/move/, "📦"],
  [/cooling/, "🌬️"],
  [/\bgas\b/, "🧪"],
  [/fridge/, "🧊"],
  [/washer|washing/, "🧺"],
  [/geyser/, "🔥"],
  [/\bro\b/, "💧"],
  [/math/, "➗"],
  [/science/, "🔬"],
  [/spoken|english|tuition/, "🗣️"],
  [/\bsite\b/, "🧭"],
  [/diagnosis/, "🔍"],
  [/estimate/, "🧾"],
  [/scope/, "🛡️"],
  [/\binstall/, "🔧"],
];

function _matchIssueIcon(name = "") {
  const n = String(name || "").toLowerCase();
  for (const [pattern, icon] of _ISSUE_ICON_RULES) if (pattern.test(n)) return icon;
  return _matchCategoryIcon(name);
}
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
let _localityContext = null;
let _activeDiscoveryKind = "";
let _searchTimer = null;
let _searchRemoteServices = [];
let _showAllCategories = false;
let _allServices = [];
let _serviceCategories = [];
let _pilotConfig = {
  city: CONFIG.SERVICE_ONLY?.CITY || "your area",
  hero_title: "Book trusted local services for your area",
  hero_subtitle: "Browse local services first. Login only when you request or track a booking.",
  trust_badges: ["Local providers", "Pay after service", "Manual confirmation"],
  support_label: "Need help?",
  support_phone: CONFIG.SERVICE_ONLY?.SUPPORT_PHONE || "",
  whatsapp_url: CONFIG.SERVICE_ONLY?.WHATSAPP_URL || "",
  featured_services_label: "Area-based services",
  fallback_title: "Need another service?",
  fallback_text: "Tell us on WhatsApp. We are manually coordinating pilot requests.",
  manual_fallback_label: "Manual assistance",
};

const CATEGORY_META = {
  all: { slug: "", icon: "🧰", label: "All services", hero: "Trusted services for your area", subtitle: "Area-based verified workers for repairs, painting, waterproofing, CCTV and home jobs.", ecosystemTitle: "Local workers available now", examples: ["Electrician", "Plumber", "Painting", "CCTV"], tags: ["fan", "leakage", "painter", "CCTV", "carpenter", "waterproofing"], visuals: [{ emoji: "⚡", label: "Fan fixed", note: "from ₹199" }, { emoji: "🎨", label: "Room painted", note: "estimate visit" }, { emoji: "💧", label: "Leak stopped", note: "inspection" }], beforeAfter: [{ title: "Damp wall restored", note: "Seepage inspection, repair and repaint flow" }, { title: "Old room refresh", note: "Putty, primer and clean finish by local painters" }], dealers: ["Mukhani", "Kusumkhera", "Kaladhungi Road"], materials: ["repair parts", "paint", "pipes"], brands: ["Asian", "Dr Fixit", "Havells"], locality: ["Mukhani", "Dahariya", "Lalpur Nayak"], trust: "Local coordination · pay after service · human confirmation", vendors: "Verified local provider network", inspection: true },
  electrical: { icon: "⚡", label: "Electrical", inspection: false },
  electrician: { icon: "⚡", label: "Electrical", aliases: ["electrical", "electrician", "wiring"], hero: "Electrical workers for your selected area", subtitle: "Fan, switch, MCB, wiring and light installation with quick local confirmation.", examples: ["Fan repair", "Switch board", "MCB issue", "Light installation"], tags: ["fan", "switch", "MCB", "wiring", "geyser", "inverter"], visuals: [{ emoji: "🌀", label: "Fan repair", note: "from ₹199" }, { emoji: "🔌", label: "Switch board", note: "same day" }, { emoji: "💡", label: "Lights", note: "install" }], beforeAfter: [{ title: "Dead fan running", note: "Local electrician visit with quick diagnosis" }, { title: "Unsafe board cleaned", note: "Switch replacement and wiring check" }], dealers: ["Havells point", "Kaladhungi Road electrical", "Mukhani hardware"], materials: ["switch", "MCB", "wire", "fan capacitor"], brands: ["Havells", "Anchor", "Polycab", "Syska"], locality: ["Mukhani", "Kusumkhera", "Nainital Road"], trust: "Safety-first local electricians · pay after service", vendors: "Electrician provider assignment", inspection: false },
  plumber: { icon: "🚰", label: "Plumbing", aliases: ["plumber", "plumbing", "pipe", "tap"], hero: "Plumbers for leakage and fittings", subtitle: "Tap, pipe, bathroom and kitchen plumbing help from nearby workers.", examples: ["Leakage repair", "Tap fitting", "Pipe blockage", "Bathroom fitting"], tags: ["tap leak", "pipe", "flush", "basin", "bathroom", "motor"], visuals: [{ emoji: "🚿", label: "Tap leak", note: "from ₹199" }, { emoji: "🧰", label: "Pipe fix", note: "nearby" }, { emoji: "🚽", label: "Bathroom", note: "fitting" }], beforeAfter: [{ title: "Leakage stopped", note: "Tap and joint repair by local plumber" }, { title: "Bathroom fitting done", note: "Clear scope confirmation before visit" }], dealers: ["Sanitary market", "Mukhani hardware", "Rampur Road pipes"], materials: ["CPVC pipe", "flush kit", "tap", "basin waste"], brands: ["Jaquar", "Astral", "Ashirvad", "Supreme"], locality: ["Kusumkhera", "Dahariya", "Mukhani"], trust: "Local plumbers · clear visit confirmation", vendors: "Plumber provider assignment", inspection: false },
  painting: { icon: "🎨", label: "Painting", aliases: ["paint", "painter", "painting"], hero: "Painting ecosystem for homes and shops", subtitle: "Painters, wall textures, putty, before/after work and expert visit for estimates.", examples: ["Room painting", "Wall putty", "Exterior painting", "Color consultation"], tags: ["texture", "putty", "primer", "room paint", "exterior", "rental repaint"], visuals: [{ emoji: "🧱", label: "Wall texture", note: "trending" }, { emoji: "🏠", label: "Room paint", note: "quote" }, { emoji: "🪣", label: "Putty repair", note: "before/after" }], beforeAfter: [{ title: "Bedroom repaint", note: "Old patches to clean warm finish" }, { title: "Texture wall upgrade", note: "Accent wall with painter estimate" }, { title: "Exterior refresh", note: "Weather coat and crack prep" }], dealers: ["Paint shop Mukhani", "Kusumkhera colors", "Nainital Road paint"], materials: ["putty", "primer", "emulsion", "texture"], brands: ["Asian Paints", "Nerolac", "Berger", "Dulux"], locality: ["Mukhani", "Lalpur Nayak", "Kaladhungi Road"], trust: "Site inspection option · local painters · estimate before work", vendors: "Painting teams and local contractors", inspection: true },
  waterproofing: { icon: "💧", label: "Waterproofing", aliases: ["waterproofing", "leakage", "seepage", "damp"], hero: "Leakage and seepage protection", subtitle: "Terrace, wall seepage, bathroom leakage and monsoon protection with inspection offers.", examples: ["Roof seepage", "Wall dampness", "Bathroom leakage", "Crack sealing"], tags: ["terrace", "seepage", "monsoon", "bathroom leak", "damp wall", "crack seal"], visuals: [{ emoji: "🌧️", label: "Monsoon cover", note: "inspection" }, { emoji: "🏚️", label: "Damp wall", note: "diagnosis" }, { emoji: "🧪", label: "Coating", note: "terrace" }], beforeAfter: [{ title: "Terrace leakage sealed", note: "Inspection-led waterproof coating" }, { title: "Seepage wall treated", note: "Dampness source checked before repair" }, { title: "Bathroom leak fixed", note: "Joint sealing and slope check" }], dealers: ["Waterproofing dealer Mukhani", "Paint chemical shop", "Hardware Kaladhungi Road"], materials: ["roof coat", "crack filler", "membrane", "sealant"], brands: ["Dr Fixit", "Sika", "Asian SmartCare", "Nerolac"], locality: ["Dahariya", "Kusumkhera", "Mukhani"], trust: "Inspection-led scope · local repair teams", vendors: "Waterproofing specialists", inspection: true },
  cctv: { icon: "📹", label: "CCTV", hero: "CCTV installation for your selected area", subtitle: "Camera setup, wiring, DVR/NVR, shop and home security visits by local technicians.", examples: ["Camera install", "DVR setup", "Wiring", "Shop security"], tags: ["camera", "DVR", "NVR", "home CCTV", "shop CCTV", "wiring"], visuals: [{ emoji: "📹", label: "Camera install", note: "quote" }, { emoji: "🖥️", label: "DVR setup", note: "fast" }, { emoji: "🏪", label: "Shop CCTV", note: "nearby" }], beforeAfter: [{ title: "Shop camera live", note: "Camera angle and DVR configured" }, { title: "Home entry covered", note: "Wiring and mobile view setup" }], trust: "Security technician confirmation · clear install scope", vendors: "CCTV installers", inspection: true },
  carpenter: { icon: "🪚", label: "Carpentry", inspection: true },
  carpentry: { icon: "🪚", label: "Carpentry", hero: "Carpenters for repair and renovation", subtitle: "Door, wardrobe, modular fixes, polish and furniture repair from local carpenters.", examples: ["Door repair", "Wardrobe", "Furniture fix", "Polish work"], tags: ["door", "wardrobe", "hinge", "modular", "polish", "furniture"], visuals: [{ emoji: "🚪", label: "Door repair", note: "from ₹249" }, { emoji: "🪵", label: "Furniture", note: "fix" }, { emoji: "🧱", label: "Wardrobe", note: "quote" }], beforeAfter: [{ title: "Door alignment fixed", note: "Hinge repair and smooth closing" }, { title: "Furniture restored", note: "Polish and repair work proof" }, { title: "Wardrobe repair", note: "Local carpenter estimate and visit" }], trust: "Local carpenters · inspection for custom work", vendors: "Carpentry workers", inspection: true },
  cleaning: { icon: "🧹", label: "Cleaning", hero: "Cleaning services for your selected area", subtitle: "Home, shop, kitchen and deep cleaning requests with local coordination.", examples: ["Home cleaning", "Kitchen cleaning", "Shop cleaning", "Move-in cleaning"], trust: "Clear scope confirmation · pay after service", vendors: "Cleaning partners", inspection: false },
  "ac-repair": { icon: "❄️", label: "AC repair", hero: "AC service and repair", subtitle: "AC checkup, service, cooling issue and installation support with verified local help.", examples: ["AC service", "Cooling issue", "Gas check", "Installation"], trust: "Technician confirmation · pay after service", vendors: "AC technicians", inspection: true },
  appliance: { icon: "🔧", label: "Appliance", hero: "Appliance repair support", subtitle: "Fridge, washing machine, RO and common appliance checks coordinated locally.", examples: ["RO service", "Washer issue", "Fridge check", "Geyser repair"], trust: "Diagnosis-first support · local technicians", vendors: "Appliance technicians", inspection: true },
  tutor: { icon: "📚", label: "Tutor", hero: "Local tutor requests", subtitle: "Tell WorkToGo your class, subject and area. Team will help connect locally.", examples: ["Math tutor", "Science tutor", "Home tuition", "Spoken English"], trust: "Manual matching during pilot", vendors: "Local tutor coordination", inspection: false },
  inspection: { icon: "🛡️", label: "Inspection", hero: "Premium inspection before big work", subtitle: "For painting, waterproofing, AC, appliance and complex jobs where a site check builds trust.", examples: ["Site visit", "Problem diagnosis", "Estimate support", "Scope clarity"], trust: "Clear visit · clear scope · better estimate", vendors: "Specialist inspection coordination", inspection: true },
};
