/**
 * WorkToGo — Commerce Storefront (Phase 4)
 * COMMERCE-SCOPED page module (hash route #shop). A self-contained mini-SPA:
 * browse → product → cart → checkout → order tracking, all within the shop
 * scope. Reuses the production-grade backend via /api/shop/* (ShopAPI). City is
 * auto/invisible; stores are shown company dark-store first. Never a dead
 * screen — skeletons on load, friendly empty states, error states with retry.
 */
import { ShopAPI } from "./api.js";

let _root = null, _view = "browse";
let _meta = { city: null, store_types: [], categories: [], payment_methods: ["cod"], default_method: "cod", platform_label: "WorkToGo", unit_ui: { default: {}, categories: {} }, seller_visibility: { default: "shown" } };
let _products = [], _stores = [], _activeType = "", _search = "";
let _cartMap = {}, _cartCount = 0;
let _product = null, _related = [], _orderId = null;
let _drop = { lat: null, lng: null }, _deliveryFee = null;
let _payMethod = null; // chosen at checkout (falls back to _meta.default_method)
// PB4 paint storefront state
let _pincode = ""; try { _pincode = localStorage.getItem("wtg_shop_pincode") || ""; } catch {}
let _paintCards = [], _activeFamily = "", _activeBrand = "", _paintQ = "";
let _paintProduct = null, _selVariant = null, _selPack = null;
let _addresses = [], _selAddr = null, _addingAddr = false, _gpsPin = { lat: null, lng: null };
const paintMode = () => !!(_meta && _meta.shop_enabled && Array.isArray(_meta.paint_families) && _meta.paint_families.length);

/* ── utils ─────────────────────────────────────────────────────────────── */
const esc = s => String(s ?? "").replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
const money = n => (window.UI && UI.formatCurrency) ? UI.formatCurrency(Number(n || 0)) : "₹" + Number(n || 0).toLocaleString("en-IN");
const toast = (m, t = "info") => { if (window.UI && UI.toast) UI.toast(m, t); };
const loggedIn = () => (window.AUTH && AUTH.isLoggedIn && AUTH.isLoggedIn());
const platformLabel = () => _meta.platform_label || "WorkToGo";
// S10: seller-hidden composer (server decides; client just renders what it's told).
// When the Unit hides the seller the product carries seller_hidden=true + fulfilled_by.
const sellerLine = p => p && p.seller_hidden
  ? `⚡ ${esc(p.fulfilled_by || platformLabel())}`
  : `${p && p.is_company ? "🏬 " : "🏪 "}${esc((p && p.store_name) || "")}`;
const payLabel = m => m === "online" ? "Pay online (UPI/Card)" : m === "cod" ? "Cash on Delivery" : esc(m);
// Cart items aren't server-composed; honour the global seller-visibility default
// so a "hide seller" Unit stays founder-free in the cart too.
const cartSeller = it => ((_meta.seller_visibility && _meta.seller_visibility.default === "hidden") || !it.vendor_name)
  ? `⚡ ${esc(platformLabel())}` : esc(it.vendor_name);

function ensureCss() {
  if (document.getElementById("commerce-css")) return;
  const l = document.createElement("link");
  l.id = "commerce-css"; l.rel = "stylesheet"; l.href = "/app/css/commerce.css?v=" + (window.WTG_ASSET_VERSION || "1");
  document.head.appendChild(l);
}

/* ── entry ─────────────────────────────────────────────────────────────── */
export async function render(container) {
  ensureCss();
  _root = container;
  container.innerHTML = `<div class="shop-app" id="shop-app"><div id="shop-view"></div>${tabBar()}</div>`;
  await loadMeta();
  await refreshCartState();
  // PB4: paint shop opens on the pincode gate until the customer gives a serviceable one.
  if (paintMode() && !_pincode) nav("pincode");
  else nav("browse");
}
export async function refresh() { if (_view === "orders") renderOrders(); }

function view() { return document.getElementById("shop-view"); }

function nav(v, opts = {}) {
  _view = v;
  const authViews = ["cart", "checkout", "orders", "order"];
  if (authViews.includes(v) && !loggedIn()) {
    try { sessionStorage.setItem("wtg_post_login_route", "shop"); } catch {}
    toast("Please log in to continue", "info");
    if (window.ROUTER) ROUTER.go("login");
    return;
  }
  syncTabs();
  if (v === "pincode")      renderPincodeGate();
  else if (v === "browse")  (paintMode() ? renderPaintHome() : renderBrowse());
  else if (v === "paintproduct") renderPaintProduct(opts.id);
  else if (v === "product") renderProduct(opts.id);
  else if (v === "cart")    renderCart();
  else if (v === "checkout")renderCheckout();
  else if (v === "orders")  renderOrders();
  else if (v === "order")   renderOrder(opts.id);
}

/* ── meta + cart state ─────────────────────────────────────────────────── */
async function loadMeta() {
  const r = await ShopAPI.meta();
  if (r.ok) _meta = r.data || _meta;
}
async function refreshCartState() {
  if (!loggedIn()) { _cartMap = {}; _cartCount = 0; syncTabs(); return; }
  const r = await ShopAPI.cart();
  applyCart(r.ok ? r.data : null);
}
function applyCart(cart) {
  _cartMap = {}; _cartCount = 0;
  if (cart && Array.isArray(cart.items)) {
    cart.items.forEach(it => { _cartMap[it.product_id] = it.quantity; _cartCount += it.quantity; });
  }
  syncTabs();
  return cart;
}

/* ── BROWSE ────────────────────────────────────────────────────────────── */
async function renderBrowse() {
  const loc = _meta.city ? `Delivering to <b>${esc(_meta.city.name)}</b>` : "Find products near you";
  view().innerHTML = `
    <div class="shop-header">
      <div class="shop-header-row">
        <div class="shop-loc">${loc}</div>
      </div>
      <div class="shop-search">
        <span>🔍</span>
        <input id="shop-q" placeholder="Search products & stores" value="${esc(_search)}">
      </div>
      <div style="height:10px"></div>
      <div class="shop-chips" id="shop-chips"></div>
    </div>
    <div class="shop-body" id="browse-body">${gridSkeleton()}</div>`;
  renderChips();
  const q = document.getElementById("shop-q");
  let t; q.addEventListener("input", e => { clearTimeout(t); _search = e.target.value; t = setTimeout(loadProducts, 300); });
  await Promise.all([loadStores(), loadProducts()]);
}
function renderChips() {
  const chips = [`<div class="shop-chip ${_activeType === "" ? "on" : ""}" onclick="ShopUI.setType('')">All</div>`]
    .concat((_meta.store_types || []).map(t => `<div class="shop-chip ${String(_activeType) === String(t.id) ? "on" : ""}" onclick="ShopUI.setType('${t.id}')">${esc(t.name)}</div>`));
  const el = document.getElementById("shop-chips"); if (el) el.innerHTML = chips.join("");
}
async function loadStores() {
  const r = await ShopAPI.stores(_activeType ? { store_type_id: _activeType } : {});
  _stores = r.ok ? (r.data.stores || []) : [];
  const host = document.getElementById("browse-body"); if (!host) return;
  // stores strip is injected above the product grid on first product load
}
async function loadProducts() {
  const host = document.getElementById("browse-body"); if (!host) return;
  host.innerHTML = gridSkeleton();
  const params = {}; if (_activeType) params.store_type_id = _activeType; // note: products filter by store_id/category; type filters stores
  const r = await ShopAPI.products(_search ? { search: _search } : {});
  if (!r.ok) { host.innerHTML = errState(r.error, "ShopUI.reload()"); return; }
  _products = r.data.products || [];
  const storeStrip = _stores.length ? `
    <div class="shop-section-title">Stores near you</div>
    <div class="store-strip">${_stores.map(storePill).join("")}</div>` : "";
  if (!_products.length) {
    host.innerHTML = storeStrip + emptyState("🛒", "Nothing here yet",
      _search ? "Try a different search." : "Products will appear as stores stock up.", _search ? "ShopUI.clearSearch()" : null, _search ? "Clear search" : null);
    return;
  }
  host.innerHTML = `${storeStrip}
    <div class="shop-section-title">${_search ? "Results" : "Popular near you"}</div>
    <div class="prod-grid">${_products.map(prodCard).join("")}</div>`;
}
function storePill(s) {
  const logo = s.logo_url ? `<img class="logo" src="${esc(s.logo_url)}">` : `<div class="logo">🏬</div>`;
  const tag = s.is_company ? `<span class="badge-dark">Dark-store</span>` : `<span class="badge-local">Local</span>`;
  return `<div class="store-pill" onclick="ShopUI.filterStore(${s.id})">${logo}
    <div class="nm">${esc(s.name)}</div><div class="mt">${tag}${s.product_count} items</div></div>`;
}
function prodCard(p) {
  const photo = p.image ? `<img class="prod-photo" src="${esc(p.image)}" onclick="ShopUI.open(${p.id})">` : `<div class="prod-photo" onclick="ShopUI.open(${p.id})">🛍️</div>`;
  const price = p.sale_price
    ? `<span class="now">${money(p.sale_price)}</span><span class="was">${money(p.price)}</span>`
    : `<span class="now">${money(p.price)}</span>`;
  return `<div class="prod-card">
    ${photo}
    <div class="prod-info">
      <div class="prod-nm" onclick="ShopUI.open(${p.id})">${esc(p.name)}</div>
      <div class="prod-store">${sellerLine(p)}</div>
      <div class="prod-price">${price}</div>
      <div class="prod-cta" id="cta-${p.id}">${ctaHtml(p)}</div>
    </div></div>`;
}
function ctaHtml(p) {
  if (!p.in_stock) return `<div class="oos">Out of stock</div>`;
  const q = _cartMap[p.id] || 0;
  if (q > 0) return stepperHtml(p.id, q);
  return `<button class="btn-add" onclick="ShopUI.add(${p.id})">ADD</button>`;
}
function stepperHtml(id, q, sm = false) {
  return `<div class="stepper ${sm ? "sm" : ""}"><button onclick="ShopUI.dec(${id})">−</button><span>${q}</span><button onclick="ShopUI.inc(${id})">+</button></div>`;
}
function updateCta(id) {
  const el = document.getElementById("cta-" + id); if (!el) return;
  const p = _products.find(x => x.id === id); if (p) el.innerHTML = ctaHtml(p);
}

/* ── cart mutations ────────────────────────────────────────────────────── */
async function cartAdd(id) {
  if (!loggedIn()) { toast("Log in to add items", "info"); if (window.ROUTER) ROUTER.go("login"); return; }
  const r = await ShopAPI.addToCart(id, 1);
  if (!r.ok) { toast(r.error, "error"); return; }
  applyCart(r.data); updateCta(id); if (_view === "cart") renderCart();
}
async function cartSet(id, qty) {
  const r = await ShopAPI.setQty(id, qty);
  if (!r.ok) { toast(r.error, "error"); return; }
  applyCart(r.data); updateCta(id); if (_view === "cart") renderCart();
}

/* ── PB4 PAINT: pincode gate ────────────────────────────────────────────── */
function renderPincodeGate(msg, notServed) {
  view().innerHTML = `<div class="shop-header"><div class="shop-header-row"><div class="shop-title">🎨 ${esc(platformLabel())} Paint</div></div></div>
    <div class="shop-body">
      <div class="shop-section-title" style="margin-top:6px">Where should we deliver?</div>
      <p class="pd-desc">Enter your 6-digit pincode so we show only paint that reaches you.</p>
      <div class="shop-field"><label>Delivery pincode</label><input id="pin-in" inputmode="numeric" maxlength="6" placeholder="e.g. 263139" value="${esc(_pincode)}"></div>
      ${msg ? `<div class="toast-inline" style="color:var(--shop-accent)">${esc(msg)}</div>` : ""}
      <button class="btn-primary-shop btn-block" onclick="ShopUI.checkPin()">Check & continue</button>
      ${notServed ? `
        <div class="summary" style="margin-top:16px">
          <div class="shop-section-title" style="margin-top:0">We don't deliver here yet</div>
          <p class="pd-desc">Leave your number and we'll tell you the moment we start.</p>
          <div class="shop-field"><label>Phone</label><input id="pin-phone" inputmode="tel" placeholder="10-digit mobile"></div>
          <button class="btn-add" style="width:100%" onclick="ShopUI.notifyMe()">🔔 Notify me</button>
        </div>` : ""}
    </div>`;
}
async function checkPin() {
  const pin = (document.getElementById("pin-in")?.value || "").replace(/\D+/g, "");
  if (pin.length !== 6) { toast("Enter a 6-digit pincode", "error"); return; }
  const r = await ShopAPI.serviceability(pin);
  if (!r.ok) { toast(r.error, "error"); return; }
  if (r.data && r.data.serviceable === false) { renderPincodeGate(r.data.message || "We don't deliver to your area yet.", true); return; }
  _pincode = pin; try { localStorage.setItem("wtg_shop_pincode", pin); } catch {}
  toast("Great — delivering to " + pin, "success");
  nav("browse");
}
async function notifyMe() {
  const phone = (document.getElementById("pin-phone")?.value || "").trim();
  const pin = (document.getElementById("pin-in")?.value || "").replace(/\D+/g, "");
  if (pin.length !== 6) { toast("Enter your pincode", "error"); return; }
  if (phone.replace(/\D+/g, "").length < 7) { toast("Enter a valid phone", "error"); return; }
  const r = await ShopAPI.serviceRequest(pin, phone);
  toast(r.ok ? (r.data && r.data.message) || "Thanks — we'll notify you!" : (r.error || "Couldn't save"), r.ok ? "success" : "error");
}

/* ── PB4 PAINT: home (families + brands + search) ───────────────────────── */
async function renderPaintHome() {
  const fams = _meta.paint_families || [];
  const brands = _meta.paint_brands || [];
  const locBar = _pincode
    ? `Delivering to <b>${esc(_pincode)}</b> · <a href="javascript:void(0)" onclick="ShopUI.changePin()" style="color:var(--shop-accent)">change</a>`
    : `<a href="javascript:void(0)" onclick="ShopUI.changePin()" style="color:var(--shop-accent)">Set your pincode</a>`;
  const famChips = [`<div class="shop-chip ${_activeFamily === "" ? "on" : ""}" onclick="ShopUI.setFamily('')">All paint</div>`]
    .concat(fams.map(f => `<div class="shop-chip ${String(_activeFamily) === String(f.id) ? "on" : ""}" onclick="ShopUI.setFamily('${f.id}')">${esc(f.name.replace(/^Paint[\s—-]*/i, "") || f.name)}</div>`)).join("");
  const brandChips = [`<div class="shop-chip ${_activeBrand === "" ? "on" : ""}" onclick="ShopUI.setBrand('')">All brands</div>`]
    .concat(brands.map(b => `<div class="shop-chip ${_activeBrand === b ? "on" : ""}" onclick="ShopUI.setBrand('${esc(b)}')">${esc(b)}</div>`)).join("");
  view().innerHTML = `
    <div class="shop-header">
      <div class="shop-header-row"><div class="shop-loc">${locBar}</div></div>
      <div class="shop-search"><span>🔍</span><input id="shop-q" placeholder="Search paint & brands" value="${esc(_paintQ)}"></div>
      <div style="height:8px"></div>
      <div class="shop-chips">${famChips}</div>
      <div style="height:6px"></div>
      <div class="shop-chips">${brandChips}</div>
    </div>
    <div class="shop-body" id="paint-body">${gridSkeleton()}</div>`;
  const q = document.getElementById("shop-q");
  let t; q.addEventListener("input", e => { clearTimeout(t); _paintQ = e.target.value; t = setTimeout(loadPaintHome, 300); });
  loadPaintHome();
}
async function loadPaintHome() {
  const host = document.getElementById("paint-body"); if (!host) return;
  host.innerHTML = gridSkeleton();
  const r = await ShopAPI.paintHome({ family: _activeFamily, brand: _activeBrand, q: _paintQ, pincode: _pincode });
  if (!r.ok) { host.innerHTML = errState(r.error, "ShopUI.reloadPaint()"); return; }
  _paintCards = (r.data && r.data.products) || [];
  if (!_paintCards.length) {
    host.innerHTML = emptyState("🎨", "No paint here yet",
      (_paintQ || _activeBrand || _activeFamily) ? "Try clearing a filter." : "We're stocking up — check back soon.",
      (_paintQ || _activeBrand || _activeFamily) ? "ShopUI.clearPaint()" : null,
      (_paintQ || _activeBrand || _activeFamily) ? "Clear filters" : null);
    return;
  }
  host.innerHTML = `<div class="prod-grid">${_paintCards.map(paintCard).join("")}</div>`;
}
function paintCard(p) {
  const photo = p.image
    ? `<img class="prod-photo" loading="lazy" src="${esc(p.image)}" onclick="ShopUI.openPaint(${p.parent_id})">`
    : `<div class="prod-photo" onclick="ShopUI.openPaint(${p.parent_id})">🪣</div>`;
  return `<div class="prod-card">
    ${photo}
    <div class="prod-info">
      <div class="prod-nm" onclick="ShopUI.openPaint(${p.parent_id})">${esc(p.name)}</div>
      <div class="prod-store">${esc(p.brand || p.family || "Paint")}</div>
      <div class="prod-price"><span class="now">from ${money(p.from_price)}</span></div>
      <div class="prod-cta">${p.in_stock ? `<button class="btn-add" onclick="ShopUI.openPaint(${p.parent_id})">View</button>` : `<div class="oos">Out of stock</div>`}</div>
    </div></div>`;
}

/* ── PB4 PAINT: product (variant chips + pack selector + ONE server price) ─ */
async function renderPaintProduct(id) {
  view().innerHTML = shellWithBack("Paint", `<div class="shop-body"><div class="shop-skel" style="height:280px;margin-bottom:12px"></div><div class="shop-skel" style="height:20px;width:70%;margin-bottom:8px"></div><div class="shop-skel" style="height:40px"></div></div>`);
  const r = await ShopAPI.paintProduct(id, _pincode);
  if (!r.ok) { view().innerHTML = shellWithBack("Paint", errState(r.error, `ShopUI.openPaint(${id})`)); return; }
  _paintProduct = r.data.product;
  const p = _paintProduct;
  _selVariant = (p.variants && p.variants[0]) ? p.variants[0].key : null;
  _selPack = firstPackFor(_selVariant);
  paintProductPaint();
}
function firstPackFor(vkey) {
  const packs = (_paintProduct.packs || []).filter(pk => pk.variant_key === vkey);
  return (packs.find(pk => pk.in_stock) || packs[0] || null);
}
function paintProductPaint() {
  const p = _paintProduct;
  const hero = p.image ? `<img class="pd-hero" loading="lazy" src="${esc(p.image)}">` : `<div class="pd-hero">🪣</div>`;
  const vchips = (p.variants || []).map(v =>
    `<div class="shop-chip ${v.key === _selVariant ? "on" : ""}" onclick="ShopUI.pickVariant('${esc(v.key)}')">${esc(v.label)}${v.finish ? " · " + esc(v.finish) : ""}</div>`).join("");
  const packs = (p.packs || []).filter(pk => pk.variant_key === _selVariant);
  const packBtns = packs.map(pk => {
    const on = _selPack && pk.product_id === _selPack.product_id;
    const oos = !pk.in_stock;
    return `<button class="pack-btn ${on ? "on" : ""} ${oos ? "oos" : ""}" ${oos ? "disabled" : ""} onclick="ShopUI.pickPack(${pk.product_id})">
      <span class="pk-sz">${esc(pk.pack_label)}</span><span class="pk-pr">${money(pk.price)}</span></button>`;
  }).join("");
  const sel = _selPack;
  const inStock = sel && sel.in_stock;
  const priceNow = sel ? sel.price : (p.packs[0] ? p.packs[0].price : 0);
  view().innerHTML = `
    <div class="shop-header"><div class="shop-header-row"><button class="shop-back" onclick="ShopUI.back()">←</button><div class="shop-title">${esc(platformLabel())}</div></div></div>
    ${hero}
    <div class="pd-body">
      <div class="pd-nm">${esc(p.name)}</div>
      <div class="pd-store"><span class="badge-fulfil">⚡ Fulfilled by ${esc(platformLabel())}</span>${p.brand ? " · " + esc(p.brand) : ""}${p.family ? " · " + esc(p.family) : ""}</div>
      ${vchips ? `<div class="shop-section-title" style="margin-top:14px">Shade / finish</div><div class="shop-chips">${vchips}</div>` : ""}
      <div class="shop-section-title" style="margin-top:14px">Pack size</div>
      <div class="pack-row">${packBtns || '<span class="oos">No packs available here</span>'}</div>
      <div style="margin-top:14px"><a href="javascript:void(0)" onclick="ShopUI.coverage()" style="color:var(--shop-accent);font-weight:600">📐 Coverage calculator — how much paint do I need?</a></div>
      <div id="cov-panel"></div>
    </div>
    <div class="sticky-bar">
      <div class="info"><div class="a">${inStock ? "In stock" : "Out of stock"}</div><div class="b" id="pd-price">${money(priceNow)}</div></div>
      <div id="pd-cta">${inStock ? `<button class="btn-primary-shop" onclick="ShopUI.addPack()">Add to cart</button>` : `<span class="oos">Out of stock</span>`}</div>
    </div>`;
}
function pickVariant(vkey) { _selVariant = vkey; _selPack = firstPackFor(vkey); paintProductPaint(); }
function pickPack(pid) { _selPack = (_paintProduct.packs || []).find(pk => pk.product_id === pid) || _selPack; paintProductPaint(); }
async function addSelectedPack() {
  if (!_selPack || !_selPack.in_stock) { toast("Pick an available pack", "info"); return; }
  await cartAdd(_selPack.product_id);
}
function coveragePanel() {
  const host = document.getElementById("cov-panel"); if (!host) return;
  if (host.innerHTML) { host.innerHTML = ""; return; } // toggle off
  const rate = Number(_meta.coverage_sqft_per_litre || 140);
  host.innerHTML = `
    <div class="summary" style="margin-top:12px">
      <div class="shop-field"><label>Wall area (sq ft)</label><input id="cov-area" inputmode="numeric" placeholder="e.g. 600"></div>
      <div class="shop-field"><label>Coats</label><input id="cov-coats" inputmode="numeric" value="2"></div>
      <button class="btn-add" style="width:100%" onclick="ShopUI.calcCoverage()">Calculate</button>
      <div id="cov-out" class="toast-inline" style="margin-top:8px">1 litre covers ≈ ${rate} sq ft / coat.</div>
    </div>`;
}
function calcCoverage() {
  const rate = Number(_meta.coverage_sqft_per_litre || 140);
  const area = Number((document.getElementById("cov-area") || {}).value || 0);
  const coats = Math.max(1, Number((document.getElementById("cov-coats") || {}).value || 1));
  const out = document.getElementById("cov-out"); if (!out) return;
  if (!area) { out.textContent = "Enter the wall area."; return; }
  const litres = Math.ceil((area * coats) / rate);
  out.innerHTML = `You'll need about <b>${litres} litre${litres > 1 ? "s" : ""}</b> for ${area} sq ft × ${coats} coat(s).`;
}

/* ── PRODUCT DETAIL ────────────────────────────────────────────────────── */
async function renderProduct(id) {
  view().innerHTML = `<div class="shop-header"><div class="shop-header-row"><button class="shop-back" onclick="ShopUI.back()">←</button><div class="shop-title">Product</div></div></div>
    <div class="shop-body"><div class="shop-skel" style="height:300px;margin-bottom:12px"></div><div class="shop-skel" style="height:20px;width:70%;margin-bottom:8px"></div><div class="shop-skel" style="height:16px;width:40%"></div></div>`;
  const r = await ShopAPI.product(id);
  if (!r.ok) { view().innerHTML = shellWithBack("Product", errState(r.error, `ShopUI.open(${id})`)); return; }
  _product = r.data.product;
  _related = Array.isArray(r.data.related) ? r.data.related : [];
  const p = _product;
  const img = (p.images && p.images.length) ? (typeof p.images[0] === "string" ? p.images[0] : p.images[0].url) : (p.image || null);
  const hero = img ? `<img class="pd-hero" src="${esc(img)}">` : `<div class="pd-hero">🛍️</div>`;
  const off = (p.sale_price && p.price > p.sale_price) ? Math.round((1 - p.sale_price / p.price) * 100) : 0;
  const priceNow = p.sale_price || p.price;
  const store = p.store;
  const hidden = !!p.seller_hidden;
  const titleName = hidden ? platformLabel() : (store ? store.name : "Product");
  const storeLine = hidden
    ? `<div class="pd-store"><span class="badge-fulfil">⚡ Fulfilled by ${esc(p.fulfilled_by || platformLabel())}</span></div>`
    : (store ? `<div class="pd-store">${store.is_company ? '<span class="badge-dark">Dark-store</span>' : '<span class="badge-local">Local</span>'} ${esc(store.name)}${store.locality ? " · " + esc(store.locality) : ""}</div>` : "");
  view().innerHTML = `
    <div class="shop-header"><div class="shop-header-row"><button class="shop-back" onclick="ShopUI.back()">←</button><div class="shop-title">${esc(titleName)}</div></div></div>
    ${hero}
    <div class="pd-body">
      <div class="pd-nm">${esc(p.name)}</div>
      ${storeLine}
      <div class="pd-price"><span class="now">${money(priceNow)}</span>${off ? `<span class="was">${money(p.price)}</span><span class="off">${off}% OFF</span>` : ""}</div>
      ${p.unit ? `<div class="prod-store">Unit: ${esc(p.unit)}</div>` : ""}
      ${p.description ? `<div class="pd-desc">${esc(p.description)}</div>` : (p.short_desc ? `<div class="pd-desc">${esc(p.short_desc)}</div>` : "")}
      ${relatedStrip()}
    </div>
    <div class="sticky-bar">
      <div class="info"><div class="a">${(p.available_qty > 0 || p.allow_backorder) ? "In stock" : "Out of stock"}</div><div class="b">${money(priceNow)}</div></div>
      <div id="pd-cta">${(p.available_qty > 0 || p.allow_backorder) ? pdCta(p.id) : `<span class="oos">Out of stock</span>`}</div>
    </div>`;
}
function pdCta(id) {
  const q = _cartMap[id] || 0;
  return q > 0 ? stepperHtml(id, q) : `<button class="btn-primary-shop" onclick="ShopUI.add(${id})">Add to cart</button>`;
}
function refreshPdCta(id) { const el = document.getElementById("pd-cta"); if (el && _product && _product.id === id) el.innerHTML = pdCta(id); }
/* S10: rule-based related products (same category, in-range, orderable). */
function relatedStrip() {
  if (!_related || !_related.length) return "";
  const card = p => {
    const img = p.image ? `<img src="${esc(p.image)}">` : `<div class="rel-ph">🛍️</div>`;
    const price = p.sale_price ? money(p.sale_price) : money(p.price);
    return `<div class="rel-card" onclick="ShopUI.open(${p.id})">${img}
      <div class="rel-nm">${esc(p.name)}</div>
      <div class="rel-sub">${sellerLine(p)}</div>
      <div class="rel-price">${price}</div></div>`;
  };
  return `<div class="shop-section-title" style="margin-top:18px">You may also like</div>
    <div class="rel-strip">${_related.map(card).join("")}</div>`;
}

/* ── CART ──────────────────────────────────────────────────────────────── */
async function renderCart() {
  view().innerHTML = shellWithBack("Your cart", `<div class="shop-body" id="cart-body">${listSkeleton(3)}</div>`);
  const r = await ShopAPI.cart();
  const body = document.getElementById("cart-body");
  if (!r.ok) { body.innerHTML = errState(r.error, "ShopUI.go('cart')"); return; }
  const cart = applyCart(r.data);
  if (!cart || !cart.items || !cart.items.length) {
    body.innerHTML = emptyState("🛒", "Your cart is empty", "Add products to get started.", "ShopUI.go('browse')", "Start shopping");
    return;
  }
  const rows = cart.items.map(cartRow).join("");
  const unavailable = (cart.unavailable && cart.unavailable.length)
    ? `<div class="toast-inline" style="color:var(--shop-accent)">Some items are unavailable and were adjusted.</div>` : "";
  body.innerHTML = `${rows}${unavailable}
    <div class="summary">
      <div class="ln"><span>Item total</span><span>${money(cart.subtotal)}</span></div>
      <div class="ln"><span>Delivery</span><span>Calculated at delivery</span></div>
      <div class="ln total"><span>To pay</span><span>${money(cart.subtotal)}</span></div>
    </div>
    <div style="height:8px"></div>`;
  // sticky checkout bar
  const bar = document.createElement("div");
  bar.className = "sticky-bar";
  bar.innerHTML = `<div class="info"><div class="a">${_cartCount} item(s)</div><div class="b">${money(cart.subtotal)}</div></div>
    <button class="btn-primary-shop" onclick="ShopUI.go('checkout')">Checkout →</button>`;
  document.getElementById("shop-app").appendChild(bar);
}
function cartRow(it) {
  const img = it.image ? `<img src="${esc(it.image)}">` : `<div class="ph">🛍️</div>`;
  return `<div class="cart-row">${img}
    <div class="mid"><div class="nm">${esc(it.name)}</div>
      <div class="sub">${cartSeller(it)}</div>
      <div style="margin-top:7px">${stepperHtml(it.product_id, it.quantity, true)}</div>
    </div>
    <div class="rt"><button class="shop-back" style="font-size:15px" onclick="ShopUI.removeItem(${it.product_id})">🗑️</button>
      <div class="lt">${money(it.line_total)}</div></div>
  </div>`;
}
async function cartRemove(id) {
  const r = await ShopAPI.removeFromCart(id);
  if (!r.ok) { toast(r.error, "error"); return; }
  applyCart(r.data); renderCart();
}

/* ── CHECKOUT ──────────────────────────────────────────────────────────── */
async function renderCheckout() {
  const methods = (_meta.payment_methods && _meta.payment_methods.length) ? _meta.payment_methods : ["cod"];
  if (!_payMethod || !methods.includes(_payMethod)) _payMethod = _meta.default_method && methods.includes(_meta.default_method) ? _meta.default_method : methods[0];
  view().innerHTML = shellWithBack("Checkout", `<div class="shop-body" id="ck-body">${listSkeleton(2)}</div>`);
  const r = await ShopAPI.addresses();
  _addresses = r.ok ? (r.data.addresses || []) : [];
  if (!_selAddr && _addresses.length) _selAddr = (_addresses.find(a => a.is_default) || _addresses[0]).id;
  if (!_addresses.length) _addingAddr = true;
  paintCheckout(methods);
}
function paintCheckout(methods) {
  methods = methods || ((_meta.payment_methods && _meta.payment_methods.length) ? _meta.payment_methods : ["cod"]);
  const body = document.getElementById("ck-body"); if (!body) return;
  const cards = _addresses.map(addressChoice).join("");
  const addBtn = `<button class="btn-add" style="width:100%;margin-top:6px" onclick="ShopUI.toggleAddAddr()">${_addingAddr ? "✕ Cancel new address" : "＋ Add a new address"}</button>`;
  body.innerHTML = `
    <div class="shop-section-title" style="margin-top:4px">Delivery address</div>
    ${cards ? `<div class="addr-list">${cards}</div>` : `<div class="toast-inline">Add your first delivery address.</div>`}
    ${addBtn}
    <div id="addr-form">${_addingAddr ? addressForm() : ""}</div>
    <div class="summary" id="ck-fee"><div class="ln"><span>Delivery fee</span><span id="ck-fee-val">Locating you…</span></div>
      <div class="ln" style="font-size:11px;color:var(--shop-muted)"><span id="ck-fee-note">Calculated by distance to the store.</span></div></div>
    <div class="shop-field"><label>Order notes (optional)</label><input id="ck-notes" placeholder="Any instructions for delivery"></div>
    <div class="shop-section-title" style="margin-top:4px">Payment</div>
    <div class="pay-opts" id="pay-opts">${methods.map(payOption).join("")}</div>
    <div style="height:70px"></div>`;
  let bar = document.querySelector("#shop-app .sticky-bar");
  if (!bar) { bar = document.createElement("div"); bar.className = "sticky-bar"; document.getElementById("shop-app").appendChild(bar); }
  bar.innerHTML = `<button class="btn-primary-shop btn-block" id="place-btn" onclick="ShopUI.placeOrder()">${placeBtnLabel()}</button>`;
  quoteDelivery();
}
function addressChoice(a) {
  const on = a.id === _selAddr;
  const lbl = { home: "🏠 Home", shop: "🏪 Shop", site: "🏗️ Site" }[a.label] || a.label;
  return `<div class="pay-opt ${on ? "on" : ""}" onclick="ShopUI.pickAddr(${a.id})">
    <div class="radio">${on ? "●" : ""}</div>
    <div class="pay-mid"><div class="pay-nm">${lbl}${a.is_default ? ' · <span style="color:var(--shop-brand-ink)">Default</span>' : ""}</div>
      <div class="pay-sub">${esc(a.name)} · ${esc(a.phone)}<br>${esc(a.line1)}, ${esc(a.city)} ${esc(a.pincode)}<br>📍 ${esc(a.landmark)}</div></div>
    <button class="shop-back" style="font-size:13px" onclick="event.stopPropagation();ShopUI.removeAddr(${a.id})">🗑️</button></div>`;
}
function addressForm() {
  const u = (window.AUTH && AUTH.getUser && AUTH.getUser()) || {};
  const chip = (k, t) => `<div class="shop-chip" id="al-${k}" onclick="ShopUI.addrLabel('${k}')">${t}</div>`;
  return `<div class="summary" style="margin-top:4px">
    <div class="shop-chips" id="addr-labels">${chip("home", "🏠 Home")}${chip("shop", "🏪 Shop")}${chip("site", "🏗️ Site")}</div>
    <div class="shop-field"><label>Full name</label><input id="ad-name" value="${esc(u.name || "")}"></div>
    <div class="shop-field"><label>Phone</label><input id="ad-phone" value="${esc(u.phone || "")}" inputmode="tel"></div>
    <div class="shop-field"><label>Address (house, street, area)</label><textarea id="ad-line1" rows="2" placeholder="e.g. House 12, Mukhani"></textarea></div>
    <div class="shop-field"><label>Landmark <span style="color:var(--shop-accent)">* required</span></label><input id="ad-landmark" placeholder="e.g. behind SBI, near red gate"></div>
    <div style="display:flex;gap:10px">
      <div class="shop-field" style="flex:1"><label>City</label><input id="ad-city" value="${esc(_meta.city ? _meta.city.name : "")}"></div>
      <div class="shop-field" style="flex:1"><label>Pincode</label><input id="ad-pin" inputmode="numeric" value="${esc(_pincode)}"></div>
    </div>
    <div class="shop-field"><label>State</label><input id="ad-state" value="Uttarakhand"></div>
    <button class="btn-add" style="width:100%" onclick="ShopUI.captureGps()" id="gps-btn">📍 Pin my exact location (optional)</button>
    <div id="gps-note" class="toast-inline" style="padding:6px"></div>
    <button class="btn-primary-shop btn-block" onclick="ShopUI.saveAddr()">Save address</button>
  </div>`;
}
function addrLabel(k) {
  _newAddrLabel = k;
  ["home", "shop", "site"].forEach(x => document.getElementById("al-" + x)?.classList.toggle("on", x === k));
}
let _newAddrLabel = "home";
function captureGps() {
  const note = document.getElementById("gps-note");
  if (!navigator.geolocation) { if (note) note.textContent = "Location not available on this device."; return; }
  if (note) note.textContent = "Locating…";
  navigator.geolocation.getCurrentPosition(
    pos => { _gpsPin = { lat: pos.coords.latitude, lng: pos.coords.longitude }; if (note) note.textContent = "✓ Location pinned."; },
    () => { if (note) note.textContent = "Couldn't get your location — you can still save."; },
    { enableHighAccuracy: true, timeout: 7000, maximumAge: 60000 }
  );
}
async function saveNewAddress() {
  const g = id => (document.getElementById(id)?.value || "").trim();
  const payload = { label: _newAddrLabel, name: g("ad-name"), phone: g("ad-phone"), line1: g("ad-line1"),
    landmark: g("ad-landmark"), city: g("ad-city"), state: g("ad-state"), pincode: g("ad-pin"),
    lat: _gpsPin.lat, lng: _gpsPin.lng };
  if (!payload.landmark) { toast("Landmark is required so the rider can find you", "error"); return; }
  const r = await ShopAPI.saveAddress(payload);
  if (!r.ok) { toast(r.error, "error"); return; }
  toast("Address saved", "success");
  _addingAddr = false; _gpsPin = { lat: null, lng: null };
  const rr = await ShopAPI.addresses(); _addresses = rr.ok ? (rr.data.addresses || []) : [];
  _selAddr = r.data && r.data.id ? r.data.id : _selAddr;
  paintCheckout();
}
function payOption(m) {
  const on = m === _payMethod;
  const icon = m === "online" ? "💳" : "💵";
  const sub = m === "online" ? "UPI, cards & wallets — pay now" : "Pay when your order arrives";
  return `<div class="pay-opt ${on ? "on" : ""}" onclick="ShopUI.setPay('${esc(m)}')">
    <div class="radio">${on ? "●" : ""}</div>
    <div class="pay-mid"><div class="pay-nm">${icon} ${payLabel(m)}</div><div class="pay-sub">${sub}</div></div></div>`;
}
function placeBtnLabel() { return _payMethod === "online" ? "Pay & place order" : "Place order (COD)"; }
function refreshPay() {
  const host = document.getElementById("pay-opts");
  if (host) host.innerHTML = ((_meta.payment_methods && _meta.payment_methods.length) ? _meta.payment_methods : ["cod"]).map(payOption).join("");
  const btn = document.getElementById("place-btn"); if (btn) btn.textContent = placeBtnLabel();
}
function quoteDelivery() {
  const setFee = (txt, note) => { const v = document.getElementById("ck-fee-val"), n = document.getElementById("ck-fee-note"); if (v) v.textContent = txt; if (n && note) n.textContent = note; };
  const doQuote = async () => {
    const r = await ShopAPI.deliveryQuote(_drop.lat, _drop.lng);
    if (!r.ok || !r.data) { setFee("At delivery", "We’ll confirm the fee when a rider is assigned."); return; }
    const q = r.data;
    if (!q.available) { setFee("Unavailable", q.reason || "This store doesn’t deliver to your area."); return; }
    _deliveryFee = q.price;
    setFee(q.price > 0 ? money(q.price) : "FREE", q.distance_km ? `≈ ${q.distance_km} km from the store` : "Estimate — confirmed at rider assignment");
  };
  if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
      pos => { _drop.lat = pos.coords.latitude; _drop.lng = pos.coords.longitude; doQuote(); },
      () => { _drop.lat = null; _drop.lng = null; doQuote(); },
      { enableHighAccuracy: true, timeout: 6000, maximumAge: 60000 }
    );
  } else doQuote();
}
async function placeOrder() {
  // PB15: order ships to the SELECTED saved address (full — incl. landmark + pin).
  const sel = _addresses.find(a => a.id === _selAddr);
  if (!sel) { toast(_addingAddr ? "Save the address first" : "Add a delivery address", "error"); return; }
  const addr = { name: sel.name, phone: sel.phone, line1: sel.line1, landmark: sel.landmark,
    city: sel.city, state: sel.state, pincode: sel.pincode, label: sel.label };
  if (sel.lat != null && sel.lng != null) { addr.lat = sel.lat; addr.lng = sel.lng; _drop = { lat: sel.lat, lng: sel.lng }; }
  const g = id => (document.getElementById(id)?.value || "").trim();
  const btn = document.querySelector(".sticky-bar .btn-primary-shop");
  if (btn) { btn.disabled = true; btn.textContent = _payMethod === "online" ? "Starting payment…" : "Placing order…"; }
  const r = await ShopAPI.checkout({ shipping_address: addr, notes: g("ck-notes"), drop_lat: _drop.lat, drop_lng: _drop.lng, payment_method: _payMethod });
  if (!r.ok) { toast(r.error, "error"); if (btn) { btn.disabled = false; btn.textContent = placeBtnLabel(); } return; }
  await refreshCartState();
  const d = r.data || {};
  // S10: online path — the order exists; open the gateway session (webhook finalises).
  if (_payMethod === "online" && d.payment && d.payment.success) {
    const redirect = d.payment.payment_link || d.payment.payment_url || d.payment.redirect_url || null;
    if (redirect) { toast("Redirecting to payment…", "info"); window.location.href = redirect; return; }
    toast("Payment started — complete it to confirm your order.", "info");
    nav("order", { id: d.order_id }); return;
  }
  if (_payMethod === "online" && d.payment_error) {
    toast("Couldn’t start online payment — order saved as Cash on Delivery.", "info");
    nav("order", { id: d.order_id }); return;
  }
  toast("Order placed! 🎉", "success");
  nav("order", { id: d.order_id });
}

/* ── ORDERS + TRACKING ─────────────────────────────────────────────────── */
async function renderOrders() {
  view().innerHTML = shellWithBack("My orders", `<div class="shop-body" id="ord-body">${listSkeleton(3)}</div>`);
  const r = await ShopAPI.orders();
  const body = document.getElementById("ord-body");
  if (!r.ok) { body.innerHTML = errState(r.error, "ShopUI.go('orders')"); return; }
  const orders = r.data.orders || [];
  if (!orders.length) { body.innerHTML = emptyState("📦", "No orders yet", "Your orders will show up here.", "ShopUI.go('browse')", "Start shopping"); return; }
  body.innerHTML = orders.map(o => `
    <div class="ord-card" onclick="ShopUI.openOrder(${o.id})">
      <div class="ord-top"><div class="ord-no">#${esc(o.order_number)}</div>
        <span class="pill-status ps-${esc(o.status)}">${esc(o.status)}</span></div>
      <div class="ord-row">
        <div class="ord-when">${esc((o.created_at || "").replace("T", " ").slice(0, 16))} · ${money(o.total)}</div>
        <button class="btn-reorder" onclick="event.stopPropagation();ShopUI.reorder(${o.id})">🔁 Reorder</button>
      </div>
    </div>`).join("");
}
const TRACK_STEPS = [
  { k: "pending", l: "Order placed", s: "We’ve received your order" },
  { k: "confirmed", l: "Confirmed", s: "The store confirmed your order" },
  { k: "processing", l: "Preparing", s: "Being packed" },
  { k: "shipped", l: "Out for delivery", s: "On the way to you" },
  { k: "delivered", l: "Delivered", s: "Enjoy!" },
];
async function renderOrder(id) {
  _orderId = id;
  view().innerHTML = shellWithBack("Order", `<div class="shop-body" id="od-body">${listSkeleton(2)}</div>`);
  const r = await ShopAPI.order(id);
  const body = document.getElementById("od-body");
  if (!r.ok) { body.innerHTML = errState(r.error, `ShopUI.openOrder(${id})`); return; }
  const o = r.data.order;
  const cancelled = o.status === "cancelled";
  const idx = TRACK_STEPS.findIndex(s => s.k === o.status);
  const timeline = cancelled
    ? `<div class="track"><div class="track-step cur"><div class="track-dot">✕</div><div><div class="track-lbl">Order cancelled</div><div class="track-sub">This order was cancelled.</div></div></div></div>`
    : `<div class="track">${TRACK_STEPS.map((s, i) => {
        const cls = i < idx ? "done" : (i === idx ? "cur" : "");
        const dot = i < idx ? "✓" : (i === idx ? "●" : "");
        return `<div class="track-step ${cls}"><div class="track-dot">${dot}</div><div><div class="track-lbl">${s.l}</div><div class="track-sub">${s.s}</div></div></div>`;
      }).join("")}</div>`;
  const items = (o.vendor_orders || []).flatMap(v => v.items || []);
  const itemsHtml = items.length ? `<div class="shop-section-title">Items</div>${items.map(it => `
    <div class="cart-row"><div class="ph">🛍️</div><div class="mid"><div class="nm">${esc(it.name)}</div>
      <div class="sub">Qty ${it.quantity} · ${money(it.unit_price)}</div></div>
      <div class="rt"><div class="lt">${money(it.line_total)}</div></div></div>`).join("")}` : "";
  const canCancel = ["pending", "confirmed"].includes(o.status);
  const payTag = (o.payment_method ? String(o.payment_method) : "cod").toUpperCase();
  body.innerHTML = `
    <div class="ord-top" style="margin-bottom:12px"><div><div class="ord-no">#${esc(o.order_number)}</div>
      <div class="ord-when">${money(o.total)} · ${esc(payTag)}</div></div>
      <span class="pill-status ps-${esc(o.status)}">${esc(o.status)}</span></div>
    <div id="od-delivery"></div>
    ${timeline}
    ${itemsHtml}
    <div class="summary"><div class="ln total"><span>Total</span><span>${money(o.total)}</span></div></div>
    <div style="height:10px"></div>
    <button class="btn-primary-shop btn-block" onclick="ShopUI.reorder(${o.id})">🔁 Reorder</button>
    <div style="height:10px"></div><button class="btn-add" style="width:100%" onclick="ShopUI.invoice(${o.id})">🧾 Invoice / bill</button>
    ${canCancel ? `<div style="height:10px"></div><button class="btn-add" style="border-color:#eab;color:#b91c1c;background:#fff" onclick="ShopUI.cancelOrder(${o.id})">Cancel order</button>` : ""}
    <div style="height:8px"></div>`;
  loadDeliveryTrack(id);
}
async function loadDeliveryTrack(orderId) {
  const host = document.getElementById("od-delivery"); if (!host) return;
  const r = await ShopAPI.deliveryTrack(orderId);
  if (!r.ok || !r.data) return;
  const d = r.data;
  const feeLine = d.price != null ? `Delivery ${d.price > 0 ? money(d.price) : "FREE"}${d.distance_km ? ` · ${d.distance_km} km` : ""}` : "";
  const rider = d.rider ? `<div class="cart-row" style="margin-top:10px"><div class="ph">🛵</div>
      <div class="mid"><div class="nm">${esc(d.rider.name || "Your rider")}</div>
        <div class="sub">${d.status === "picked" ? "On the way to you" : "Assigned to your order"}${feeLine ? " · " + feeLine : ""}</div></div>
      ${d.rider.phone ? `<div class="rt"><a class="btn-add" style="padding:6px 10px" href="tel:${esc(d.rider.phone)}">Call</a></div>` : ""}</div>`
    : (d.status === "pending"
        ? `<div class="toast-inline">Finding a rider near the store…${feeLine ? " (" + feeLine + ")" : ""}</div>`
        : (feeLine ? `<div class="toast-inline">${feeLine}</div>` : ""));
  host.innerHTML = rider;
}
async function cancelOrder(id) {
  if (!confirm("Cancel this order?")) return;
  const r = await ShopAPI.cancelOrder(id);
  if (!r.ok) { toast(r.error, "error"); return; } // e.g. picked-up → "start a return instead"
  const c = (r.data && r.data.cancellation) || {};
  let msg = "Order cancelled";
  if (c.refund_amount > 0) msg = `Cancelled · ₹${Math.round(c.refund_amount)} refunded to source`;
  else if (c.fee > 0) msg = `Cancelled · ₹${Math.round(c.fee)} cancellation fee applied`;
  toast(msg, "info"); renderOrder(id);
}
/* PB11: GST-ready invoice — a printable bill the customer can view + download (print → PDF). */
async function showInvoice(id) {
  const r = await ShopAPI.invoice(id);
  if (!r.ok) { toast(r.error, "error"); return; }
  const v = r.data.invoice || {};
  const biz = v.business || {};
  const addr = v.bill_to || {};
  const rows = (v.lines || []).map(l => `<tr><td>${esc(l.name)}</td><td style="text-align:center">${l.quantity}</td><td style="text-align:right">${money(l.unit_price)}</td><td style="text-align:right">${money(l.line_total)}</td></tr>`).join("");
  const taxLine = (v.tax_rate > 0)
    ? `<tr><td colspan="3" style="text-align:right">Taxable value</td><td style="text-align:right">${money(v.taxable_value)}</td></tr>
       <tr><td colspan="3" style="text-align:right">GST @ ${esc(v.tax_rate)}%</td><td style="text-align:right">${money(v.tax)}</td></tr>` : "";
  const html = `<!doctype html><html><head><meta charset="utf-8"><title>Invoice ${esc(v.invoice_no)}</title>
    <style>body{font-family:system-ui,Arial,sans-serif;color:#1a1d24;max-width:720px;margin:24px auto;padding:0 16px}
    h1{font-size:20px;margin:0} .muted{color:#667} table{width:100%;border-collapse:collapse;margin-top:14px}
    th,td{padding:7px 6px;border-bottom:1px solid #e6e8ee;font-size:13px} th{text-align:left;background:#f7f8fa}
    .tot{font-weight:800;font-size:15px} .no-print{margin:16px 0}
    @media print{.no-print{display:none}}</style></head><body>
    <div class="no-print"><button onclick="window.print()" style="padding:9px 16px;font-weight:700">Print / Save as PDF</button></div>
    <div style="display:flex;justify-content:space-between;align-items:flex-start">
      <div><h1>${esc(biz.name || "WorkToGo")}</h1><div class="muted">${esc(biz.address || "")}</div>
        ${v.gstin ? `<div class="muted">GSTIN: ${esc(v.gstin)}</div>` : ""}</div>
      <div style="text-align:right"><div style="font-weight:800">TAX INVOICE</div>
        <div class="muted">No: ${esc(v.invoice_no)}</div><div class="muted">${esc((v.date || "").replace("T", " ").slice(0, 16))}</div></div>
    </div>
    <div style="margin-top:12px"><b>Bill to:</b> ${esc(addr.name || "")} ${addr.phone ? "· " + esc(addr.phone) : ""}<br>
      <span class="muted">${esc(addr.line1 || addr.address || "")}${addr.landmark ? ", " + esc(addr.landmark) : ""}${addr.city ? ", " + esc(addr.city) : ""} ${esc(addr.pincode || "")}</span></div>
    <table><thead><tr><th>Item</th><th style="text-align:center">Qty</th><th style="text-align:right">Rate</th><th style="text-align:right">Amount</th></tr></thead>
      <tbody>${rows}
        <tr><td colspan="3" style="text-align:right">Items</td><td style="text-align:right">${money(v.items_total)}</td></tr>
        ${v.delivery_fee ? `<tr><td colspan="3" style="text-align:right">Delivery</td><td style="text-align:right">${money(v.delivery_fee)}</td></tr>` : ""}
        ${taxLine}
        <tr class="tot"><td colspan="3" style="text-align:right">Grand total</td><td style="text-align:right">${money(v.grand_total)}</td></tr>
      </tbody></table>
    <p class="muted" style="margin-top:14px">Payment: ${esc((v.payment_method || "cod").toUpperCase())}${v.tax_rate > 0 ? "" : " · Tax not applicable"}.</p>
    </body></html>`;
  const w = window.open("", "_blank");
  if (!w) { toast("Allow pop-ups to view the invoice", "info"); return; }
  w.document.open(); w.document.write(html); w.document.close();
}

/* S10: one-tap reorder — re-adds the past order's still-orderable items to cart. */
async function reorder(id) {
  const r = await ShopAPI.reorder(id);
  if (!r.ok) { toast(r.error, "error"); return; }
  applyCart(r.data.cart);
  const added = (r.data.added || []).length, skipped = (r.data.skipped || []).length;
  if (!added) { toast("Those items aren’t available right now.", "info"); return; }
  toast(skipped ? `${added} item(s) added · ${skipped} unavailable` : `${added} item(s) added to cart`, "success");
  nav("cart");
}

/* ── shared view chrome + states ───────────────────────────────────────── */
function tabBar() {
  return `<div class="shop-tabs">
    <button class="shop-tab" id="tab-browse" onclick="ShopUI.go('browse')"><span class="ic">🛍️</span>Shop</button>
    <button class="shop-tab" id="tab-cart" onclick="ShopUI.go('cart')"><span class="ic">🛒</span>Cart<span class="cartbadge shop-hidden" id="cart-badge">0</span></button>
    <button class="shop-tab" id="tab-orders" onclick="ShopUI.go('orders')"><span class="ic">📦</span>Orders</button>
  </div>`;
}
function syncTabs() {
  const map = { pincode: "tab-browse", browse: "tab-browse", paintproduct: "tab-browse", product: "tab-browse", cart: "tab-cart", checkout: "tab-cart", orders: "tab-orders", order: "tab-orders" };
  ["tab-browse", "tab-cart", "tab-orders"].forEach(t => document.getElementById(t)?.classList.remove("on"));
  document.getElementById(map[_view])?.classList.add("on");
  const b = document.getElementById("cart-badge");
  if (b) { b.textContent = _cartCount; b.classList.toggle("shop-hidden", _cartCount <= 0); }
}
function shellWithBack(title, inner) {
  return `<div class="shop-header"><div class="shop-header-row"><button class="shop-back" onclick="ShopUI.back()">←</button><div class="shop-title">${esc(title)}</div></div></div>${inner}`;
}
function gridSkeleton() {
  return `<div class="prod-grid">${Array(6).fill(`<div class="prod-card"><div class="shop-skel" style="aspect-ratio:1/1"></div><div class="prod-info"><div class="shop-skel" style="height:12px"></div><div class="shop-skel" style="height:12px;width:60%"></div><div class="shop-skel" style="height:30px;margin-top:6px"></div></div></div>`).join("")}</div>`;
}
function listSkeleton(n) { return Array(n).fill(`<div class="shop-skel" style="height:74px;margin-bottom:10px"></div>`).join(""); }
function emptyState(ic, title, sub, action, actionLabel) {
  return `<div class="shop-empty"><div class="ic">${ic}</div><h3>${esc(title)}</h3><p>${esc(sub)}</p>
    ${action ? `<button class="btn-primary-shop" onclick="${action}">${esc(actionLabel || "Continue")}</button>` : ""}</div>`;
}
function errState(msg, retry) {
  return `<div class="shop-empty"><div class="ic">😕</div><h3>Couldn’t load</h3><p>${esc(msg || "Please try again.")}</p>
    <button class="btn-primary-shop" onclick="${retry}">Retry</button></div>`;
}

/* ── public bridge for inline handlers ─────────────────────────────────── */
window.ShopUI = {
  go: v => nav(v),
  open: id => nav("product", { id }),
  openOrder: id => nav("order", { id }),
  back: () => { if (_view === "product" || _view === "paintproduct" || _view === "checkout" || _view === "order") nav(_view === "checkout" ? "cart" : "browse"); else nav("browse"); },
  // PB4 paint
  checkPin: () => checkPin(),
  notifyMe: () => notifyMe(),
  changePin: () => nav("pincode"),
  setFamily: id => { _activeFamily = id; loadPaintHome(); renderPaintHome(); },
  setBrand: b => { _activeBrand = b; loadPaintHome(); renderPaintHome(); },
  clearPaint: () => { _activeFamily = ""; _activeBrand = ""; _paintQ = ""; renderPaintHome(); },
  reloadPaint: () => loadPaintHome(),
  openPaint: id => nav("paintproduct", { id }),
  pickVariant: k => pickVariant(k),
  pickPack: id => pickPack(id),
  addPack: () => addSelectedPack(),
  coverage: () => coveragePanel(),
  calcCoverage: () => calcCoverage(),
  // PB15 addresses
  pickAddr: id => { _selAddr = id; _addingAddr = false; paintCheckout(); },
  toggleAddAddr: () => { _addingAddr = !_addingAddr; paintCheckout(); if (_addingAddr) setTimeout(() => addrLabel(_newAddrLabel || "home"), 0); },
  addrLabel: k => addrLabel(k),
  captureGps: () => captureGps(),
  saveAddr: () => saveNewAddress(),
  removeAddr: async id => { if (!confirm("Remove this address?")) return; const r = await ShopAPI.removeAddress(id); if (!r.ok) { toast(r.error, "error"); return; } if (_selAddr === id) _selAddr = null; const rr = await ShopAPI.addresses(); _addresses = rr.ok ? (rr.data.addresses || []) : []; if (!_selAddr && _addresses.length) _selAddr = (_addresses.find(a => a.is_default) || _addresses[0]).id; paintCheckout(); },
  setType: id => { _activeType = id; renderChips(); loadStores().then(loadProducts); },
  filterStore: id => { location.hash = "#shop"; _search = ""; ShopAPI.products({ store_id: id }).then(r => { if (r.ok) { _products = r.data.products || []; const host = document.getElementById("browse-body"); if (host) host.innerHTML = `<div class="shop-section-title">Store products</div><div class="prod-grid">${_products.map(prodCard).join("")}</div>`; } }); },
  clearSearch: () => { _search = ""; const q = document.getElementById("shop-q"); if (q) q.value = ""; loadProducts(); },
  reload: () => loadProducts(),
  add: async id => { await cartAdd(id); refreshPdCta(id); },
  inc: async id => { const q = (_cartMap[id] || 0) + 1; await cartSet(id, q); refreshPdCta(id); },
  dec: async id => { const q = (_cartMap[id] || 0) - 1; await cartSet(id, Math.max(0, q)); refreshPdCta(id); },
  removeItem: id => cartRemove(id),
  placeOrder: () => placeOrder(),
  cancelOrder: id => cancelOrder(id),
  reorder: id => reorder(id),
  invoice: id => showInvoice(id),
  setPay: m => { _payMethod = m; refreshPay(); },
};
