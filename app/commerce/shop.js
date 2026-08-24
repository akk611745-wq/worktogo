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
let _meta = { city: null, store_types: [], categories: [] };
let _products = [], _stores = [], _activeType = "", _search = "";
let _cartMap = {}, _cartCount = 0;
let _product = null, _orderId = null;

/* ── utils ─────────────────────────────────────────────────────────────── */
const esc = s => String(s ?? "").replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
const money = n => (window.UI && UI.formatCurrency) ? UI.formatCurrency(Number(n || 0)) : "₹" + Number(n || 0).toLocaleString("en-IN");
const toast = (m, t = "info") => { if (window.UI && UI.toast) UI.toast(m, t); };
const loggedIn = () => (window.AUTH && AUTH.isLoggedIn && AUTH.isLoggedIn());

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
  nav("browse");
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
  if (v === "browse")       renderBrowse();
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
      <div class="prod-store">${p.is_company ? "🏬 " : "🏪 "}${esc(p.store_name || "")}</div>
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

/* ── PRODUCT DETAIL ────────────────────────────────────────────────────── */
async function renderProduct(id) {
  view().innerHTML = `<div class="shop-header"><div class="shop-header-row"><button class="shop-back" onclick="ShopUI.back()">←</button><div class="shop-title">Product</div></div></div>
    <div class="shop-body"><div class="shop-skel" style="height:300px;margin-bottom:12px"></div><div class="shop-skel" style="height:20px;width:70%;margin-bottom:8px"></div><div class="shop-skel" style="height:16px;width:40%"></div></div>`;
  const r = await ShopAPI.product(id);
  if (!r.ok) { view().innerHTML = shellWithBack("Product", errState(r.error, `ShopUI.open(${id})`)); return; }
  _product = r.data.product;
  const p = _product;
  const img = (p.images && p.images.length) ? (typeof p.images[0] === "string" ? p.images[0] : p.images[0].url) : (p.image || null);
  const hero = img ? `<img class="pd-hero" src="${esc(img)}">` : `<div class="pd-hero">🛍️</div>`;
  const off = (p.sale_price && p.price > p.sale_price) ? Math.round((1 - p.sale_price / p.price) * 100) : 0;
  const priceNow = p.sale_price || p.price;
  const store = p.store;
  view().innerHTML = `
    <div class="shop-header"><div class="shop-header-row"><button class="shop-back" onclick="ShopUI.back()">←</button><div class="shop-title">${esc(store ? store.name : "Product")}</div></div></div>
    ${hero}
    <div class="pd-body">
      <div class="pd-nm">${esc(p.name)}</div>
      ${store ? `<div class="pd-store">${store.is_company ? '<span class="badge-dark">Dark-store</span>' : '<span class="badge-local">Local</span>'} ${esc(store.name)}${store.locality ? " · " + esc(store.locality) : ""}</div>` : ""}
      <div class="pd-price"><span class="now">${money(priceNow)}</span>${off ? `<span class="was">${money(p.price)}</span><span class="off">${off}% OFF</span>` : ""}</div>
      ${p.unit ? `<div class="prod-store">Unit: ${esc(p.unit)}</div>` : ""}
      ${p.description ? `<div class="pd-desc">${esc(p.description)}</div>` : (p.short_desc ? `<div class="pd-desc">${esc(p.short_desc)}</div>` : "")}
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
      <div class="sub">${esc(it.vendor_name || "")}</div>
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
function renderCheckout() {
  const u = (window.AUTH && AUTH.getUser && AUTH.getUser()) || {};
  view().innerHTML = shellWithBack("Checkout", `<div class="shop-body">
    <div class="shop-section-title" style="margin-top:4px">Delivery address</div>
    <div class="shop-field"><label>Full name</label><input id="ck-name" value="${esc(u.name || "")}"></div>
    <div class="shop-field"><label>Phone</label><input id="ck-phone" value="${esc(u.phone || "")}" inputmode="tel"></div>
    <div class="shop-field"><label>Address (house, street, area)</label><textarea id="ck-line1" rows="2" placeholder="e.g. House 12, Mukhani"></textarea></div>
    <div style="display:flex;gap:10px">
      <div class="shop-field" style="flex:1"><label>City</label><input id="ck-city" value="${esc(_meta.city ? _meta.city.name : "")}"></div>
      <div class="shop-field" style="flex:1"><label>Pincode</label><input id="ck-pin" inputmode="numeric"></div>
    </div>
    <div class="shop-field"><label>State</label><input id="ck-state" value="Uttarakhand"></div>
    <div class="shop-field"><label>Order notes (optional)</label><input id="ck-notes" placeholder="Any instructions for delivery"></div>
    <div class="pay-note">💵 Cash on Delivery — pay when your order arrives</div>
    <div style="height:8px"></div>
  </div>`);
  const bar = document.createElement("div");
  bar.className = "sticky-bar";
  bar.innerHTML = `<button class="btn-primary-shop btn-block" onclick="ShopUI.placeOrder()">Place order (COD)</button>`;
  document.getElementById("shop-app").appendChild(bar);
}
async function placeOrder() {
  const g = id => (document.getElementById(id)?.value || "").trim();
  const addr = { name: g("ck-name"), phone: g("ck-phone"), line1: g("ck-line1"), city: g("ck-city"), state: g("ck-state"), pincode: g("ck-pin") };
  const missing = ["name", "phone", "line1", "city", "state", "pincode"].filter(k => !addr[k]);
  if (missing.length) { toast("Please fill: " + missing.join(", "), "error"); return; }
  const btn = document.querySelector(".sticky-bar .btn-primary-shop");
  if (btn) { btn.disabled = true; btn.textContent = "Placing order…"; }
  const r = await ShopAPI.checkout({ shipping_address: addr, notes: g("ck-notes") });
  if (!r.ok) { toast(r.error, "error"); if (btn) { btn.disabled = false; btn.textContent = "Place order (COD)"; } return; }
  await refreshCartState();
  toast("Order placed! 🎉", "success");
  nav("order", { id: r.data.order_id });
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
      <div class="ord-when">${esc((o.created_at || "").replace("T", " ").slice(0, 16))} · ${money(o.total)}</div>
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
  body.innerHTML = `
    <div class="ord-top" style="margin-bottom:12px"><div><div class="ord-no">#${esc(o.order_number)}</div>
      <div class="ord-when">${money(o.total)} · COD</div></div>
      <span class="pill-status ps-${esc(o.status)}">${esc(o.status)}</span></div>
    ${timeline}
    ${itemsHtml}
    <div class="summary"><div class="ln total"><span>Total</span><span>${money(o.total)}</span></div></div>
    ${canCancel ? `<div style="height:10px"></div><button class="btn-add" style="border-color:#eab;color:#b91c1c;background:#fff" onclick="ShopUI.cancelOrder(${o.id})">Cancel order</button>` : ""}
    <div style="height:8px"></div>`;
}
async function cancelOrder(id) {
  if (!confirm("Cancel this order?")) return;
  const r = await ShopAPI.cancelOrder(id);
  if (!r.ok) { toast(r.error, "error"); return; }
  toast("Order cancelled", "info"); renderOrder(id);
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
  const map = { browse: "tab-browse", product: "tab-browse", cart: "tab-cart", checkout: "tab-cart", orders: "tab-orders", order: "tab-orders" };
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
  back: () => { if (_view === "product" || _view === "checkout" || _view === "order") nav(_view === "checkout" ? "cart" : "browse"); else nav("browse"); },
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
};
