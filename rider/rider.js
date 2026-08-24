/**
 * WorkToGo Rider — self-contained rider app (Commerce Phase 5, own scope).
 * Own login (users.role='delivery'), online toggle, live location ping, and the
 * assigned-deliveries queue with accept/pickup/deliver. Talks only to
 * /api/rider/* + /api/auth/*. No dependency on the customer/admin apps.
 */
const Rider = (() => {
  const TOKEN = "wtg_rider_token", USER = "wtg_rider_user";
  const tok = () => localStorage.getItem(TOKEN) || "";
  const app = () => document.getElementById("app");
  let _watchId = null, _pollTimer = null, _online = false;

  function toast(m) { const t = document.getElementById("toast"); t.textContent = m; t.classList.add("show"); setTimeout(() => t.classList.remove("show"), 2600); }
  const esc = s => String(s ?? "").replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
  const money = n => "₹" + Number(n || 0).toLocaleString("en-IN");

  async function api(method, path, body) {
    let res;
    try {
      res = await fetch(`/api${path}`, { method, headers: { "Content-Type": "application/json", ...(tok() ? { Authorization: `Bearer ${tok()}` } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) });
    } catch { return { ok: false, error: "You’re offline." }; }
    let j = {}; try { j = await res.json(); } catch {}
    if (res.status === 401) { logout(); return { ok: false, error: "Session expired" }; }
    if (!res.ok || j.success === false) return { ok: false, error: j.message || "Something went wrong" };
    return { ok: true, data: j.data ?? j };
  }

  /* ── Login ─────────────────────────────────────────────────────────────── */
  function renderLogin(msg) {
    document.getElementById("logout-btn").classList.add("hidden");
    app().innerHTML = `
      <div class="card">
        <div class="big" style="margin-bottom:4px">Rider login</div>
        <div class="muted" style="margin-bottom:16px">Sign in with your WorkToGo rider account.</div>
        ${msg ? `<div class="muted" style="color:#ffb4b6;margin-bottom:10px">${esc(msg)}</div>` : ""}
        <label>Email</label><input id="lg-email" type="email" inputmode="email" placeholder="rider@worktogo.in">
        <label>Password</label><input id="lg-pass" type="password" placeholder="••••••••">
        <button class="btn btn-primary btn-block" onclick="Rider.login()">Sign in</button>
      </div>`;
  }
  async function login() {
    const email = (document.getElementById("lg-email").value || "").trim();
    const password = document.getElementById("lg-pass").value || "";
    if (!email || !password) { toast("Enter email and password"); return; }
    const r = await api("POST", "/auth/email/login", { email, password });
    if (!r.ok) { renderLogin(r.error); return; }
    const d = r.data || {};
    const token = d.token || d.access_token || d.jwt;
    const user = d.user || d.profile || {};
    if (!token) { renderLogin("Login failed — no token returned."); return; }
    if (user.role && user.role !== "delivery") { renderLogin("This account is not a rider account."); return; }
    localStorage.setItem(TOKEN, token); localStorage.setItem(USER, JSON.stringify(user));
    boot();
  }
  function logout() {
    localStorage.removeItem(TOKEN); localStorage.removeItem(USER);
    stopWatch(); if (_pollTimer) clearInterval(_pollTimer);
    renderLogin();
  }

  /* ── Location ──────────────────────────────────────────────────────────── */
  function startWatch() {
    if (!navigator.geolocation || _watchId !== null) return;
    _watchId = navigator.geolocation.watchPosition(
      pos => api("POST", "/rider/location", { lat: pos.coords.latitude, lng: pos.coords.longitude }),
      () => {}, { enableHighAccuracy: true, maximumAge: 15000, timeout: 20000 }
    );
  }
  function stopWatch() { if (_watchId !== null) { navigator.geolocation.clearWatch(_watchId); _watchId = null; } }

  /* ── Dashboard ─────────────────────────────────────────────────────────── */
  async function renderDashboard() {
    document.getElementById("logout-btn").classList.remove("hidden");
    const me = await api("GET", "/rider/me");
    const r = me.ok ? (me.data.rider || {}) : {};
    _online = !!r.is_online;
    app().innerHTML = `
      <div class="card">
        <div class="row" style="display:flex;justify-content:space-between;align-items:center">
          <div><div class="big">${esc(r.name || "Rider")}</div><div class="muted">${_online ? "You’re online" : "You’re offline"}</div></div>
          <div class="switch"><div class="toggle ${_online ? "on" : ""}" id="online-toggle" onclick="Rider.toggleOnline()"></div></div>
        </div>
        <div class="stat" style="margin-top:14px">
          <div><div class="v">${money(r.earnings)}</div><div class="l">Earnings</div></div>
          <div><div class="v">${money(r.cash_in_hand)}</div><div class="l">Cash in hand</div></div>
          <div><div class="v">${r.active_count ?? 0}/${r.max_active ?? 3}</div><div class="l">Active</div></div>
        </div>
      </div>
      <div id="jobs"><div class="spin"></div></div>`;
    if (_online) startWatch();
    loadJobs();
  }
  async function toggleOnline() {
    _online = !_online;
    document.getElementById("online-toggle")?.classList.toggle("on", _online);
    const r = await api("POST", "/rider/online", { online: _online });
    if (!r.ok) { toast(r.error); _online = !_online; document.getElementById("online-toggle")?.classList.toggle("on", _online); return; }
    toast(_online ? "You’re online" : "You’re offline");
    if (_online) startWatch(); else stopWatch();
    loadJobs();
  }
  async function loadJobs() {
    const host = document.getElementById("jobs"); if (!host) return;
    const r = await api("GET", "/rider/deliveries");
    if (!r.ok) { host.innerHTML = `<div class="empty">${esc(r.error)}</div>`; return; }
    const jobs = r.data.deliveries || [];
    if (!jobs.length) {
      host.innerHTML = `<div class="empty"><div class="ic">📭</div><div style="margin-top:8px">${_online ? "No deliveries assigned yet." : "Go online to receive deliveries."}</div></div>`;
      return;
    }
    host.innerHTML = jobs.map(jobCard).join("");
  }
  function jobCard(j) {
    const nav = (lat, lng, label) => (lat && lng) ? `<a class="btn btn-ghost" href="https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}" target="_blank" rel="noopener">${label}</a>` : "";
    const actions = j.status === "assigned"
      ? `<button class="btn btn-danger" onclick="Rider.act(${j.id},'reject')">Reject</button>
         <button class="btn btn-primary" onclick="Rider.act(${j.id},'accept')">Accept</button>
         <button class="btn btn-accent" onclick="Rider.act(${j.id},'pickup')">Picked up</button>`
      : `<button class="btn btn-primary btn-block" onclick="Rider.act(${j.id},'deliver')">Mark delivered</button>`;
    return `<div class="job">
      <div class="row"><div><div class="nm">#${esc(j.order_number || j.order_id)}</div>
        <div class="sub">Pickup: ${esc(j.store_name || "Store")}${j.store_locality ? " · " + esc(j.store_locality) : ""}</div>
        <div class="sub">Drop: ${esc(j.drop_address || j.customer_name || "Customer")}</div></div>
        <div style="text-align:right"><div class="payout">${money(j.rider_payout)}</div><span class="pill ${j.status}">${esc(j.status)}</span></div></div>
      <div class="leg">📍 ${j.distance_km ? j.distance_km + " km" : "—"} ${j.customer_phone ? `· <a href="tel:${esc(j.customer_phone)}" style="color:var(--r-brand)">Call customer</a>` : ""}</div>
      <div class="job-actions">${nav(j.pickup_lat, j.pickup_lng, "Nav to store")}${nav(j.drop_lat, j.drop_lng, "Nav to drop")}</div>
      <div class="job-actions">${actions}</div>
    </div>`;
  }
  async function act(id, action) {
    if (action === "reject" && !confirm("Reject this delivery? It will be reassigned.")) return;
    const r = await api("POST", `/rider/deliveries/${id}/${action}`, {});
    if (!r.ok) { toast(r.error); return; }
    toast(action === "deliver" ? "Delivered 🎉" : action === "pickup" ? "Marked picked up" : action === "accept" ? "Accepted" : "Rejected");
    renderDashboard();
  }

  /* ── boot ──────────────────────────────────────────────────────────────── */
  function boot() {
    if (!tok()) { renderLogin(); return; }
    renderDashboard();
    if (_pollTimer) clearInterval(_pollTimer);
    _pollTimer = setInterval(() => { if (tok() && !document.hidden) loadJobs(); }, 15000);
  }

  document.addEventListener("DOMContentLoaded", boot);
  return { login, logout, toggleOnline, act };
})();
