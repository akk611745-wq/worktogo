/**
 * WorkToGo — Seller app (S17). A one-page, glance-and-act installable PWA for the
 * external shop. Talks ONLY to the /api/commerce-seller/* backend (S15/S16); the
 * customer is shown only as the opaque Customer ID (no PII). Vanilla JS, no build.
 */
(function () {
  "use strict";
  var TOKEN_KEY = (window.CONFIG && window.CONFIG.TOKEN_KEY) || "wtg_token";
  var BASE = (window.CONFIG && window.CONFIG.BASE_URL) || "";
  var state = { tab: "orders", avail: "offline", timer: null };

  function token() { try { return localStorage.getItem(TOKEN_KEY) || ""; } catch (e) { return ""; } }
  function el(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? "" : s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;"); }
  function money(n) { return "₹" + Number(n || 0).toLocaleString("en-IN"); }
  function toast(m) { var t = el("toast"); if (!t) return; t.textContent = m; t.classList.add("show"); setTimeout(function () { t.classList.remove("show"); }, 2200); }

  async function API(method, path, body) {
    var res;
    try {
      res = await fetch(BASE + "/api/commerce-seller" + path, {
        method: method,
        headers: Object.assign({ "Content-Type": "application/json" }, token() ? { Authorization: "Bearer " + token() } : {}),
        body: body ? JSON.stringify(body) : undefined,
      });
    } catch (e) { return { ok: false, status: 0, error: "You appear to be offline." }; }
    var j = {}; try { j = await res.json(); } catch (e) {}
    if (!res.ok || j.success === false) return { ok: false, status: res.status, error: j.message || j.error || "Something went wrong", data: j.data };
    return { ok: true, status: res.status, data: j.data != null ? j.data : j };
  }

  /* ── boot ──────────────────────────────────────────────────────────────── */
  async function boot() {
    if (!token()) return renderLogin();
    var r = await API("GET", "/me");
    if (!r.ok) {
      if (r.status === 401 || r.status === 403) return renderLogin();
      el("app").innerHTML = errState(r.error);
      return;
    }
    state.avail = (r.data && r.data.status) || "offline";
    el("logout-btn").classList.remove("hidden");
    el("avail").classList.remove("hidden");
    el("tabs").classList.remove("hidden");
    syncAvail();
    go("orders");
    startAutoRefresh();
  }
  function renderLogin() {
    el("avail").classList.add("hidden"); el("tabs").classList.add("hidden");
    el("app").innerHTML = '<div class="empty"><div class="ic">🔑</div><h3>Please log in</h3>' +
      '<p class="muted">Open WorkToGo, sign in as a seller, then reopen this app.</p></div>';
  }
  function errState(msg) { return '<div class="empty"><div class="ic">😕</div><h3>Could not load</h3><p class="muted">' + esc(msg) + '</p><button class="btn btn-primary" onclick="Seller.boot()">Retry</button></div>'; }

  /* ── availability ──────────────────────────────────────────────────────── */
  function syncAvail() {
    ["online", "busy", "offline"].forEach(function (s) {
      var b = document.querySelector(".avail .s-" + s);
      if (b) b.classList.toggle("on", state.avail === s);
    });
  }
  async function setAvail(status) {
    var r = await API("POST", "/availability", { status: status });
    if (!r.ok) { toast(r.error); return; }
    state.avail = (r.data && r.data.status) || status; syncAvail();
    toast(status === "online" ? "You are online" : status === "busy" ? "Set to busy" : "You are offline");
  }

  /* ── tabs ──────────────────────────────────────────────────────────────── */
  function go(tab) {
    state.tab = tab;
    ["orders", "catalogue", "earnings"].forEach(function (t) { var b = el("tab-" + t); if (b) b.classList.toggle("on", t === tab); });
    if (tab === "orders") renderOrders();
    else if (tab === "catalogue") renderCatalogue();
    else if (tab === "earnings") renderEarnings();
  }

  /* ── orders inbox (accept / reject / stock-out) ────────────────────────── */
  async function renderOrders() {
    el("app").innerHTML = '<div class="spin"></div>';
    var r = await API("GET", "/orders");
    if (!r.ok) { el("app").innerHTML = errState(r.error); return; }
    var orders = (r.data && r.data.orders) || [];
    var badge = el("orders-badge"); if (badge) { badge.textContent = orders.length; badge.classList.toggle("hidden", orders.length === 0); }
    if (!orders.length) { el("app").innerHTML = '<div class="empty"><div class="ic">🧾</div><h3>No new orders</h3><p class="muted">New orders appear here when you are online.</p></div>'; return; }
    el("app").innerHTML = orders.map(orderCard).join("");
  }
  function orderCard(o) {
    var items = (o.items || []).map(function (it) {
      var shade = it.attributes && it.attributes.shade_code ? ' · shade ' + esc(it.attributes.shade_code) : "";
      return '<div class="sub">' + esc(it.product_name) + ' × ' + it.quantity + shade + '</div>';
    }).join("");
    return '<div class="ord"><div class="top"><div><div class="nm">#' + esc(o.order_number || o.sub_order_id) + '</div>' + items +
      '</div><div class="cref" onclick="Seller.history(\'' + esc(o.customer_ref) + '\')">' + esc(o.customer_ref) + ' ⓘ</div></div>' +
      '<div class="ord-actions">' +
        '<button class="btn btn-primary" onclick="Seller.accept(' + o.sub_order_id + ')">Accept</button>' +
        '<button class="btn btn-ghost" onclick="Seller.stockOut(' + o.sub_order_id + ')">No stock</button>' +
        '<button class="btn btn-danger" onclick="Seller.reject(' + o.sub_order_id + ')">Reject</button>' +
      '</div></div>';
  }
  async function accept(id) { var r = await API("POST", "/orders/" + id + "/accept"); toast(r.ok ? "Order accepted ✓" : r.error); renderOrders(); }
  async function reject(id) { if (!confirm("Reject this order?")) return; var r = await API("POST", "/orders/" + id + "/reject", { reason: "declined" }); toast(r.ok ? "Order declined" : r.error); renderOrders(); }
  async function stockOut(id) { var r = await API("POST", "/orders/" + id + "/stock-out", { reason: "out of stock" }); toast(r.ok ? "Marked out of stock — rerouting" : r.error); renderOrders(); }

  /* ── catalogue (add / edit base / restock) ─────────────────────────────── */
  async function renderCatalogue() {
    el("app").innerHTML = '<div class="spin"></div>';
    var r = await API("GET", "/catalogue");
    var offers = (r.ok && r.data && r.data.offers) || [];
    var form = '<div class="card"><label>Add a product offer</label>' +
      '<input id="of-name" placeholder="Product name (e.g. Berger Easy Clean 4L)">' +
      '<input id="of-base" inputmode="numeric" placeholder="Your base price (₹)">' +
      '<input id="of-stock" inputmode="numeric" placeholder="Stock quantity">' +
      '<button class="btn btn-primary btn-block" onclick="Seller.addOffer()">Submit for verification</button></div>';
    var list = offers.length ? offers.map(offerCard).join("") : '<div class="empty"><div class="ic">📦</div><h3>No offers yet</h3><p class="muted">Add your first product above.</p></div>';
    el("app").innerHTML = form + list;
  }
  function offerCard(o) {
    var base = o.seller_base != null ? "base " + money(o.seller_base) : "";
    return '<div class="card"><div class="top" style="display:flex;justify-content:space-between;gap:10px">' +
      '<div><div class="nm">' + esc(o.name) + '</div><div class="sub">' + base + ' · sells at ' + money(o.customer_price) + ' · stock ' + o.available_qty + '</div></div>' +
      '<span class="pill ' + esc(o.status) + '">' + esc(o.status) + '</span></div>' +
      '<div class="ord-actions"><input id="rs-' + o.product_id + '" inputmode="numeric" placeholder="New stock" style="margin:0">' +
      '<button class="btn btn-ghost" onclick="Seller.restock(' + o.product_id + ')">Update stock</button></div></div>';
  }
  async function addOffer() {
    var name = (el("of-name").value || "").trim(), base = parseFloat(el("of-base").value || "0"), stock = parseInt(el("of-stock").value || "0", 10);
    if (!name || !(base > 0)) { toast("Enter a name and base price"); return; }
    var r = await API("POST", "/catalogue", { name: name, base_price: base, stock: stock });
    toast(r.ok ? "Offer submitted — pending verification" : r.error);
    if (r.ok) renderCatalogue();
  }
  async function restock(id) { var v = parseInt((el("rs-" + id) || {}).value || "0", 10); var r = await API("POST", "/catalogue/" + id + "/stock", { stock: v }); toast(r.ok ? "Stock updated" : r.error); if (r.ok) renderCatalogue(); }

  /* ── earnings ──────────────────────────────────────────────────────────── */
  async function renderEarnings() {
    el("app").innerHTML = '<div class="spin"></div>';
    var r = await API("GET", "/earnings");
    if (!r.ok) { el("app").innerHTML = errState(r.error); return; }
    var e = r.data || {};
    el("app").innerHTML = '<div class="card"><div class="stat">' +
      tile(money(e.seller_earned), "Earned (delivered)") +
      tile(e.delivered_count || 0, "Delivered orders") +
      tile(e.in_progress || 0, "In progress") +
      tile(money(e.gross_delivered), "Gross delivered") +
      '</div></div><p class="muted">Payouts are settled to your account by WorkToGo (weekly).</p>';
  }
  function tile(v, l) { return '<div><div class="v">' + v + '</div><div class="l">' + esc(l) + '</div></div>'; }

  /* ── Customer-ID history (seller's own, no PII) ────────────────────────── */
  async function history(ref) {
    var r = await API("GET", "/customers/" + encodeURIComponent(ref) + "/history");
    var h = (r.ok && r.data) || { products: [], order_count: 0 };
    var rows = (h.products || []).map(function (p) { return '<div class="hrow"><span>' + esc(p.product_name) + '</span><span class="muted">' + p.times + '× · qty ' + p.total_qty + '</span></div>'; }).join("");
    if (!rows) rows = '<p class="muted">No past orders from this customer with you yet.</p>';
    el("sheet-root").innerHTML = '<div class="sheet-bg" onclick="Seller.closeSheet(event)"><div class="sheet" onclick="event.stopPropagation()">' +
      '<div class="top" style="display:flex;justify-content:space-between"><div class="nm">Customer ' + esc(ref) + '</div>' +
      '<button class="btn btn-ghost" style="padding:6px 10px" onclick="Seller.closeSheet()">✕</button></div>' +
      '<p class="muted">' + (h.order_count || 0) + ' order(s) with you · repeat-match reference</p>' + rows + '</div></div>';
  }
  function closeSheet(e) { if (e && e.target && !e.target.classList.contains("sheet-bg")) { /* allow ✕ button */ } el("sheet-root").innerHTML = ""; }

  /* ── auto-refresh (orders tab only) ────────────────────────────────────── */
  function startAutoRefresh() {
    if (state.timer) clearInterval(state.timer);
    state.timer = setInterval(function () { if (state.tab === "orders" && !document.hidden) renderOrders(); }, 20000);
  }
  function logout() { try { localStorage.removeItem(TOKEN_KEY); } catch (e) {} renderLogin(); }

  window.Seller = { boot: boot, go: go, setAvail: setAvail, accept: accept, reject: reject, stockOut: stockOut, addOffer: addOffer, restock: restock, history: history, closeSheet: closeSheet, logout: logout };
  if (document.readyState !== "loading") boot(); else document.addEventListener("DOMContentLoaded", boot);
})();
