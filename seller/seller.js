/**
 * WorkToGo — Seller app (S17). A one-page, glance-and-act installable PWA for the
 * external shop. Talks ONLY to the /api/commerce-seller/* backend (S15/S16); the
 * customer is shown only as the opaque Customer ID (no PII). Vanilla JS, no build.
 */
(function () {
  "use strict";
  var TOKEN_KEY = (window.CONFIG && window.CONFIG.TOKEN_KEY) || "wtg_token";
  var BASE = (window.CONFIG && window.CONFIG.BASE_URL) || "";
  var state = { tab: "orders", avail: "offline", timer: null, kyc: {} };

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
    if (tab !== "orders") stopAlarm(); // PB7: only ring on the orders tab
    ["orders", "catalogue", "earnings", "kyc"].forEach(function (t) { var b = el("tab-" + t); if (b) b.classList.toggle("on", t === tab); });
    if (tab === "orders") renderOrders();
    else if (tab === "catalogue") renderCatalogue();
    else if (tab === "earnings") renderEarnings();
    else if (tab === "kyc") renderKyc();
  }

  /* ── KYC (S18): the seller's own onboarding application ────────────────────── */
  async function uploadDoc(inputId, field, btnId) {
    var inp = el(inputId); if (!inp || !inp.files || !inp.files[0]) { toast("Choose a file first"); return; }
    var fd = new FormData(); fd.append("file", inp.files[0]); fd.append("purpose", "kyc");
    var btn = el(btnId); if (btn) { btn.disabled = true; btn.textContent = "Uploading…"; }
    var res;
    try {
      res = await fetch(BASE + "/api/media/upload", { method: "POST", headers: token() ? { Authorization: "Bearer " + token() } : {}, body: fd });
    } catch (e) { toast("Offline"); if (btn) { btn.disabled = false; btn.textContent = "Upload"; } return; }
    var j = {}; try { j = await res.json(); } catch (e) {}
    if (!res.ok || j.success === false) { toast((j.message || j.error || "Upload failed")); if (btn) { btn.disabled = false; btn.textContent = "Upload"; } return; }
    state.kyc[field] = (j.data && j.data.media_id) || j.media_id;
    if (btn) { btn.disabled = false; btn.textContent = "Uploaded ✓"; btn.classList.add("btn-primary"); }
    toast("Uploaded");
  }
  async function renderKyc() {
    el("app").innerHTML = '<div class="spin"></div>';
    var r = await API("GET", "/kyc");
    var k = (r.ok && r.data && r.data.kyc) || {};
    state.kyc = { shop_photo_media_id: k.shop_photo_media_id, id_proof_media_id: k.id_proof_media_id, address_proof_media_id: k.address_proof_media_id };
    var st = k.status || "draft";
    var badge = '<span class="pill ' + (st === "approved" ? "active" : st === "submitted" ? "draft" : "") + '">' + esc(st) + '</span>';
    var locked = (st === "submitted" || st === "approved");
    var fld = function (id, label, val, extra) { return '<label>' + esc(label) + '</label><input id="' + id + '" value="' + esc(val || "") + '" ' + (locked ? "disabled" : "") + ' ' + (extra || "") + '>'; };
    var doc = function (id, label, has) { return '<label>' + esc(label) + (has ? ' ✓' : '') + '</label><div class="ord-actions" style="margin-bottom:10px"><input type="file" id="f-' + id + '" accept="image/*,application/pdf" style="margin:0" ' + (locked ? "disabled" : "") + '><button class="btn btn-ghost" id="b-' + id + '" onclick="Seller.uploadDoc(\'f-' + id + '\',\'' + id + '\',\'b-' + id + '\')" ' + (locked ? "disabled" : "") + '>Upload</button></div>'; };
    el("app").innerHTML =
      '<div class="card"><div class="top" style="display:flex;justify-content:space-between"><div class="nm">Shop KYC</div>' + badge + '</div>' +
      '<p class="muted">Fill this so WorkToGo can verify your shop and pay you. ' + (st === "rejected" && k.review_note ? 'Rejected: ' + esc(k.review_note) : '') + '</p>' +
      fld("k-owner_name", "Owner name", k.owner_name) +
      fld("k-shop_address", "Shop address", k.shop_address) +
      fld("k-pincode", "Pincode", k.pincode, 'inputmode="numeric"') +
      fld("k-gst_number", "GST no. (or leave blank)", k.gst_number) +
      fld("k-enrolment_no", "Enrolment no. (if no GST)", k.enrolment_no) +
      fld("k-pan", "PAN", k.pan) +
      '<div class="muted" style="margin:6px 0">Payout (bank OR UPI):</div>' +
      fld("k-bank_account_name", "Account holder", k.bank_account_name) +
      fld("k-bank_account_number", "Account number", k.bank_account_number, 'inputmode="numeric"') +
      fld("k-bank_ifsc", "IFSC", k.bank_ifsc) +
      fld("k-upi_id", "UPI id", k.upi_id) +
      fld("k-operating_hours", "Operating hours", k.operating_hours) +
      fld("k-service_area", "Service area", k.service_area) +
      doc("shop_photo_media_id", "Shop photo", k.shop_photo_media_id) +
      doc("id_proof_media_id", "ID proof", k.id_proof_media_id) +
      doc("address_proof_media_id", "Address proof", k.address_proof_media_id) +
      (locked ? '<p class="muted">' + (st === "approved" ? "Approved ✓ — you're live." : "Submitted — under review.") + '</p>'
              : '<button class="btn btn-ghost btn-block" onclick="Seller.saveKyc()">Save draft</button>' +
                '<div style="height:8px"></div><button class="btn btn-primary btn-block" onclick="Seller.submitKyc()">Submit for verification</button>') +
      '</div>';
  }
  function kycFields() {
    var o = {}; ["owner_name","shop_address","pincode","gst_number","enrolment_no","pan","bank_account_name","bank_account_number","bank_ifsc","upi_id","operating_hours","service_area"].forEach(function (f) { var e2 = el("k-" + f); if (e2) o[f] = e2.value.trim(); });
    ["shop_photo_media_id","id_proof_media_id","address_proof_media_id"].forEach(function (f) { if (state.kyc[f]) o[f] = state.kyc[f]; });
    return o;
  }
  async function saveKyc() { var r = await API("POST", "/kyc", kycFields()); toast(r.ok ? "Saved" : r.error); }
  async function submitKyc() {
    await API("POST", "/kyc", kycFields());
    var r = await API("POST", "/kyc/submit");
    if (!r.ok) { toast(r.error || "Incomplete"); return; }
    toast("Submitted for verification ✓"); renderKyc();
  }

  /* ── PB7: loud new-order alarm — own tone + vibration, repeats until the
     inbox is empty (i.e. every new order is accepted / rejected). ─────────── */
  var _ac = null, _alarmTimer = null, _alarmOn = false;
  function _beep() {
    try {
      if (!_ac) { var AC = window.AudioContext || window.webkitAudioContext; if (!AC) return; _ac = new AC(); }
      if (_ac.state === "suspended") _ac.resume();
      var t = _ac.currentTime;
      [0, 0.22].forEach(function (off) {            // urgent double-beep
        var o = _ac.createOscillator(), g = _ac.createGain();
        o.type = "square"; o.frequency.value = 880;
        g.gain.setValueAtTime(0.0001, t + off);
        g.gain.exponentialRampToValueAtTime(0.5, t + off + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, t + off + 0.16);
        o.connect(g); g.connect(_ac.destination); o.start(t + off); o.stop(t + off + 0.18);
      });
    } catch (e) {}
    if (navigator.vibrate) { try { navigator.vibrate([200, 100, 200]); } catch (e) {} }
  }
  function startAlarm() {
    if (_alarmOn) return; _alarmOn = true;
    _beep(); _alarmTimer = setInterval(_beep, 2500);   // repeats until stopped
  }
  function stopAlarm() {
    _alarmOn = false;
    if (_alarmTimer) { clearInterval(_alarmTimer); _alarmTimer = null; }
    if (navigator.vibrate) { try { navigator.vibrate(0); } catch (e) {} }
  }

  /* ── orders inbox (accept / reject / stock-out) ────────────────────────── */
  async function renderOrders() {
    el("app").innerHTML = '<div class="spin"></div>';
    var r = await API("GET", "/orders");
    if (!r.ok) { el("app").innerHTML = errState(r.error); stopAlarm(); return; }
    var orders = (r.data && r.data.orders) || [];
    var badge = el("orders-badge"); if (badge) { badge.textContent = orders.length; badge.classList.toggle("hidden", orders.length === 0); }
    if (!orders.length) { stopAlarm(); el("app").innerHTML = '<div class="empty"><div class="ic">🧾</div><h3>No new orders</h3><p class="muted">New orders appear here when you are online.</p></div>'; return; }
    el("app").innerHTML = orders.map(orderCard).join("");
    startAlarm(); // loud until the seller clears every new order
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

  /* ── catalogue: add product (brand → ready shade → packs) + my products ───── */
  var _pm = null;
  async function paintMeta() { if (_pm) return _pm; var r = await API("GET", "/paint-meta"); _pm = (r.ok && r.data) || { brands: [], categories: [], density: 1.25 }; return _pm; }

  async function renderCatalogue() {
    el("app").innerHTML = '<div class="spin"></div>';
    var meta = await paintMeta();
    var r = await API("GET", "/products");
    var products = (r.ok && r.data && r.data.products) || [];
    state.newPhoto = null;
    var brandOpts = (meta.brands || []).map(function (b) { return '<option value="' + esc(b) + '">'; }).join("");
    var catOpts = (meta.categories || []).map(function (c) { return '<option value="' + c.id + '">' + esc(c.name) + '</option>'; }).join("");
    var dens = meta.density || 1.25;
    var packRows = [1, 4, 10, 20].map(function (s) {
      var w = Math.round((s * dens + 0.3) * 10) / 10;
      return '<div class="row" style="gap:6px;align-items:center;margin-bottom:6px">' +
        '<div style="width:44px;font-weight:800">' + s + 'L</div>' +
        '<input id="pk-price-' + s + '" inputmode="numeric" placeholder="price ₹" style="margin:0">' +
        '<input id="pk-stock-' + s + '" inputmode="numeric" placeholder="stock" style="margin:0;max-width:74px">' +
        '<input id="pk-wt-' + s + '" inputmode="decimal" value="' + w + '" title="weight kg" style="margin:0;max-width:60px">' +
        '</div>';
    }).join("");
    var form = '<div class="card"><div class="nm" style="margin-bottom:8px">Add a product</div>' +
      '<label>Brand</label><input id="cp-brand" list="cp-brands" placeholder="Type or pick a brand"><datalist id="cp-brands">' + brandOpts + '</datalist>' +
      '<label>Category</label><select id="cp-cat">' + catOpts + '</select>' +
      '<label>Product line</label><input id="cp-line" placeholder="e.g. Easy Clean">' +
      '<label>Photo (one per product)</label><div class="ord-actions"><input type="file" id="cp-file" accept="image/*" style="margin:0"><button class="btn btn-ghost" id="cp-upbtn" onclick="Seller.catPhoto()">Upload</button></div><div id="cp-preview" class="muted" style="margin:6px 0"></div>' +
      '<label>Ready shade</label><input id="cp-shade" value="White" placeholder="e.g. White / Ivory">' +
      '<label>Packs (fill the sizes you stock — weight auto-suggested, editable)</label>' + packRows +
      '<div style="height:8px"></div><button class="btn btn-primary btn-block" onclick="Seller.catAdd()">Submit for verification</button></div>';
    var list = products.length ? products.map(productCard).join("") : '<div class="empty"><div class="ic">📦</div><h3>No products yet</h3><p class="muted">Add your first product above.</p></div>';
    el("app").innerHTML = form + '<div class="nm" style="margin:12px 2px 6px">My products</div>' + list;
  }

  function chip(st, reason) {
    var map = { draft: ['⏳ Waiting for verification', 'draft'], active: ['● Live', 'active'], inactive: ['✖ Rejected' + (reason ? ' · ' + esc(reason) : ''), 'draft'], archived: ['Archived', ''] };
    var c = map[st] || [esc(st), '']; return '<span class="pill ' + c[1] + '">' + c[0] + '</span>';
  }
  function productCard(p) {
    var ph = p.photo ? '<img src="' + esc(p.photo) + '" style="width:44px;height:44px;border-radius:9px;object-fit:cover">' : '<div style="width:44px;height:44px;border-radius:9px;background:var(--s-line);display:flex;align-items:center;justify-content:center">🪣</div>';
    var head = '<div class="top" style="display:flex;justify-content:space-between;gap:10px"><div style="display:flex;gap:10px;align-items:center">' + ph +
      '<div><div class="nm">' + esc((p.brand || '') + ' ' + (p.line || '')) + '</div></div></div>' + chip(p.status, null) + '</div>';
    var skus = (p.skus || []).map(function (s) {
      return '<div class="row" style="gap:6px;align-items:center;margin-top:8px">' +
        '<div style="width:44px;font-weight:800">' + esc(s.pack) + '</div>' +
        '<input id="ep-' + s.product_id + '" inputmode="numeric" value="' + (s.seller_base != null ? s.seller_base : '') + '" placeholder="base ₹" style="margin:0">' +
        '<input id="es-' + s.product_id + '" inputmode="numeric" value="' + s.stock + '" style="margin:0;max-width:66px">' +
        '<button class="btn btn-ghost" style="padding:8px 10px;margin:0" onclick="Seller.catSave(' + s.product_id + ')">Save</button>' +
        chip(s.status, s.reject_reason) + '</div>' +
        '<div class="muted" style="font-size:11px;margin-left:50px">sells at ' + money(s.customer_price) + '</div>';
    }).join("");
    return '<div class="card">' + head + skus + '</div>';
  }

  async function catPhoto() {
    var inp = el("cp-file"); if (!inp || !inp.files || !inp.files[0]) { toast("Choose a photo first"); return; }
    var fd = new FormData(); fd.append("file", inp.files[0]); fd.append("purpose", "product");
    var btn = el("cp-upbtn"); if (btn) { btn.disabled = true; btn.textContent = "Uploading…"; }
    var res; try { res = await fetch(BASE + "/api/media/upload", { method: "POST", headers: token() ? { Authorization: "Bearer " + token() } : {}, body: fd }); }
    catch (e) { toast("Offline"); if (btn) { btn.disabled = false; btn.textContent = "Upload"; } return; }
    var j = {}; try { j = await res.json(); } catch (e) {}
    if (!res.ok || j.success === false) { toast(j.message || j.error || "Upload failed"); if (btn) { btn.disabled = false; btn.textContent = "Upload"; } return; }
    state.newPhoto = (j.data && j.data.public_url) || j.public_url;
    el("cp-preview").innerHTML = '<img src="' + esc(state.newPhoto) + '" style="width:72px;height:72px;border-radius:9px;object-fit:cover"> uploaded ✓';
    if (btn) { btn.disabled = false; btn.textContent = "Change"; }
  }
  async function catAdd() {
    var brand = (el("cp-brand").value || "").trim(), cat = el("cp-cat").value, line = (el("cp-line").value || "").trim(), shade = (el("cp-shade").value || "White").trim();
    if (!brand || !line) { toast("Enter brand and product line"); return; }
    var packs = [1, 4, 10, 20].map(function (s) { return { size: s, base: parseFloat((el("pk-price-" + s) || {}).value || "0"), stock: parseInt((el("pk-stock-" + s) || {}).value || "0", 10), weight: parseFloat((el("pk-wt-" + s) || {}).value || "0") }; }).filter(function (p) { return p.base > 0; });
    if (!packs.length) { toast("Enter a price for at least one pack"); return; }
    var pr = await API("POST", "/products", { brand: brand, line: line, category_id: cat, photo: state.newPhoto });
    if (!pr.ok) { toast(pr.error); return; }
    var pid = pr.data.product_id;
    var vr = await API("POST", "/products/" + pid + "/variants", { variant_type: "ready_shade", label: shade, shade_mode: "ready" });
    if (!vr.ok) { toast(vr.error); return; }
    var vid = vr.data.variation_id;
    for (var i = 0; i < packs.length; i++) { var p = packs[i]; await API("POST", "/products/" + pid + "/variants/" + vid + "/packs", { pack_size: String(p.size), pack_unit: "L", base_price: p.base, stock: p.stock, weight: p.weight }); }
    toast("Submitted — waiting for verification"); renderCatalogue();
  }
  async function catSave(id) {
    var base = parseFloat((el("ep-" + id) || {}).value || "0"), stock = parseInt((el("es-" + id) || {}).value || "0", 10);
    if (base > 0) { await API("POST", "/packs/" + id + "/price", { base_price: base }); }
    await API("POST", "/packs/" + id + "/stock", { stock: stock });
    toast("Saved"); renderCatalogue();
  }

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
  function logout() { stopAlarm(); try { localStorage.removeItem(TOKEN_KEY); } catch (e) {} renderLogin(); }

  window.Seller = { boot: boot, go: go, setAvail: setAvail, accept: accept, reject: reject, stockOut: stockOut, catPhoto: catPhoto, catAdd: catAdd, catSave: catSave, history: history, closeSheet: closeSheet, logout: logout, uploadDoc: uploadDoc, saveKyc: saveKyc, submitKyc: submitKyc };
  if (document.readyState !== "loading") boot(); else document.addEventListener("DOMContentLoaded", boot);
})();
