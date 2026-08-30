/**
 * WorkToGo — Agent Chat UI (Phase 3)  ·  app/agent/agent.js
 * ----------------------------------------------------------------------------
 * A friendly, mobile-first TEXT chat where a registered user talks to the agent
 * and the agent OPENS REAL UI inline — vendor cards, package cards, an expert-
 * visit price, and a booking confirmation — from real tool results. It does not
 * just chat; it shows real things and takes actions on confirm.
 *
 * Clean scope: this page and its CSS live under app/agent/ only. It reuses the
 * app's shared primitives when present (CONFIG/API/AUTH/ROUTER) but degrades to
 * a self-contained MOCK so the whole flow renders in a browser with no backend
 * and no AI key (see agent/demo.html). All real work still goes through the
 * agent orchestrator (privacy wall + tools) on the server — this file only
 * renders and calls POST /api/agent/message.
 *
 * Flow per turn:
 *   user types ─▶ POST /api/agent/message ─▶ orchestrator (wall → AI → tool)
 *             ─▶ reply + real data ─▶ render bubble + inline cards
 *   a WRITE (book / expert visit) ─▶ inline "Confirm?" ─▶ on Yes, the real
 *   backend action runs (reads just render).
 *
 * Register-gated: the router only mounts this for a logged-in user; mock/demo
 * mode bypasses that for the static preview.
 */

const AG_VERSION = (typeof window !== "undefined" && window.WTG_ASSET_VERSION) || "1";

const S = { msgs: null, input: null, send: null, busy: false, mock: false, lastCategory: "painting" };

const CATS = {
  painter: "painting", paint: "painting", painting: "painting",
  plumber: "plumber", plumbing: "plumber", nal: "plumber",
  electrician: "electrician", electric: "electrician", bijli: "electrician",
  waterproof: "waterproofing", waterproofing: "waterproofing", seepage: "waterproofing",
  cctv: "cctv", camera: "cctv",
};

// ── Entry point (called by the router, or by the demo harness) ───────────────
export async function render(app, opts = {}) {
  injectCss();
  S.mock = opts.mock ?? mockEnabled();

  // Register-gate (defensive; the router already guards this route).
  if (!S.mock && typeof AUTH !== "undefined" && AUTH.isLoggedIn && !AUTH.isLoggedIn()) {
    if (AUTH.requireAuth) AUTH.requireAuth();
    return;
  }

  app.innerHTML = shell();
  S.msgs  = app.querySelector(".ag-msgs");
  S.input = app.querySelector(".ag-input");
  S.send  = app.querySelector(".ag-send");

  app.querySelector(".ag-back").addEventListener("click", goBack);
  S.send.addEventListener("click", onSend);
  S.input.addEventListener("keydown", (e) => {
    if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); onSend(); }
  });
  S.input.addEventListener("input", autoGrow);

  greet();
}

// ── Shell markup ─────────────────────────────────────────────────────────────
function shell() {
  return `
    <div class="ag-root">
      <header class="ag-header">
        <button class="ag-back" aria-label="Back">←</button>
        <div class="ag-avatar">🤖</div>
        <div class="ag-head-meta">
          <div class="ag-head-name">WorkToGo Assistant</div>
          <div class="ag-head-status"><span class="ag-dot"></span> Online${S.mock ? " · demo" : ""}</div>
        </div>
      </header>
      <div class="ag-msgs" aria-live="polite"></div>
      <div class="ag-composer">
        <textarea class="ag-input" rows="1" placeholder="Type a message… e.g. painter chahiye"></textarea>
        <button class="ag-send" aria-label="Send">➤</button>
      </div>
    </div>`;
}

function greet() {
  const name = friendlyName();
  addAgent(`Namaste ${name}! 👋 Main aapka WorkToGo assistant hoon.\nBataiye kya chahiye — main dhoond ke, dikhake, book bhi kar deta hoon.`);
  const chips = ["painter chahiye", "plumber chahiye", "painting packages", "expert visit"];
  const row = document.createElement("div");
  row.className = "ag-row agent";
  const wrap = document.createElement("div");
  wrap.className = "ag-chips";
  chips.forEach((c) => {
    const b = document.createElement("button");
    b.className = "ag-chip";
    b.textContent = c;
    b.addEventListener("click", () => { if (!S.busy) { S.input.value = c; onSend(); } });
    wrap.appendChild(b);
  });
  row.appendChild(wrap);
  S.msgs.appendChild(row);
  scrollDown();
}

// ── Sending ──────────────────────────────────────────────────────────────────
async function onSend() {
  const text = (S.input.value || "").trim();
  if (!text || S.busy) return;
  S.input.value = "";
  autoGrow();
  addUser(text);
  const cat = detectCat(text);
  if (cat) S.lastCategory = cat;
  await run({ message: text });
}

async function run(payload) {
  setBusy(true);
  const typing = showTyping();
  let res;
  try {
    res = await transport(payload);
  } catch (e) {
    res = { reply: "Sorry, kuch gadbad ho gayi. Thodi der baad try kijiye.", tool: null, _error: true };
  }
  typing.remove();
  handle(res);
  setBusy(false);
}

// ── Transport: real backend, or mock ─────────────────────────────────────────
async function transport(payload) {
  if (S.mock) return mockAgent(payload);
  if (typeof API === "undefined" || !API.agentMessage) {
    return { reply: "Assistant abhi available nahi hai.", tool: null, _error: true };
  }
  const r = await API.agentMessage(payload);
  if (!r.ok) {
    const msg = r.status === 503
      ? "Assistant abhi enable nahi hai. Thodi der baad try kijiye."
      : (r.error || "Kuch gadbad ho gayi. Dobara koshish kijiye.");
    return { reply: msg, tool: null, _error: true };
  }
  // API wraps as { success, message, data:<orchestrator result> }
  return (r.data && r.data.data) || r.data || { reply: "(no response)", tool: null };
}

// ── Handling one agent result → bubble + inline cards ────────────────────────
function handle(res) {
  if (!res) return;
  if (res._error) { addAgent(res.reply || "Kuch gadbad ho gayi.", "error"); return; }

  if (res.reply) addAgent(res.reply);
  const d = res.data || {};

  if (res.tool === "find_vendors" && Array.isArray(d.vendors)) {
    d.vendors.length ? renderVendorCards(d.vendors) : addAgent("Is category mein abhi koi vendor available nahi hai. 😔");
  } else if (res.tool === "get_packages" && Array.isArray(d.packages)) {
    d.packages.length ? renderPackageCards(d.packages) : addAgent("Is category ke liye abhi packages set nahi hain.");
  } else if (res.tool === "get_expert_visit_price" && (("amount" in d) || ("is_free" in d))) {
    renderExpertPrice(d, res._category || S.lastCategory);
  } else if (res.requires_confirmation) {
    renderConfirm(res.confirm_token, res.reply || "Confirm karein?", null);
  } else if (res.executed && res.tool === "create_booking") {
    renderBooking(d, "Booking");
  } else if (res.executed && res.tool === "request_expert_visit") {
    renderBooking(d, "Expert visit");
  }
  // rejected / degraded / chat: the reply bubble already said it.
}

// ── Cards ─────────────────────────────────────────────────────────────────────
function renderVendorCards(vendors) {
  const cards = cardWrap();
  vendors.slice(0, 6).forEach((v) => {
    const name = v.business_name || v.name || "Vendor";
    const rating = v.rating != null ? Number(v.rating).toFixed(1) : null;
    const cat = v.category_name || v.type || "";
    const exp = v.experience_years ? `${v.experience_years} yrs exp` : "";
    const verified = (v.is_verified === 1 || v.is_verified === true);
    const card = el(`
      <div class="ag-card">
        <div class="ag-card-head">
          <div class="ag-card-ava">${esc(initial(name))}</div>
          <div style="min-width:0">
            <div class="ag-card-title">${esc(name)}</div>
            <div class="ag-card-sub">${esc(cat)}</div>
          </div>
        </div>
        <div class="ag-badges">
          ${rating ? `<span class="ag-badge star">⭐ ${esc(rating)}</span>` : ""}
          ${exp ? `<span class="ag-badge">${esc(exp)}</span>` : ""}
          ${verified ? `<span class="ag-badge ok">✓ Verified</span>` : ""}
        </div>
        <div class="ag-card-actions">
          <button class="ag-btn primary" data-book="${esc(v.id)}" data-name="${esc(name)}">Book now</button>
        </div>
      </div>`);
    card.querySelector("[data-book]").addEventListener("click", (e) => {
      const id = e.currentTarget.getAttribute("data-book");
      proposeBooking(id, e.currentTarget.getAttribute("data-name"));
    });
    cards.appendChild(card);
  });
  mountCards(cards);
}

function renderPackageCards(packages) {
  const cards = cardWrap();
  packages.slice(0, 6).forEach((p) => {
    const price = p.rate_per_sqft != null ? `₹${p.rate_per_sqft}/sqft`
      : (p.base_price != null ? `₹${p.base_price}` : "");
    const feats = Array.isArray(p.features) ? p.features.slice(0, 4) : [];
    const card = el(`
      <div class="ag-card">
        <div class="ag-card-title">${esc(p.name || "Package")}</div>
        ${p.description ? `<div class="ag-card-sub">${esc(p.description)}</div>` : ""}
        ${price ? `<div class="ag-card-price">${esc(price)}</div>` : ""}
        ${feats.length ? `<ul class="ag-feat">${feats.map((f) => `<li>${esc(f)}</li>`).join("")}</ul>` : ""}
      </div>`);
    cards.appendChild(card);
  });
  mountCards(cards);
}

function renderExpertPrice(d, category) {
  const isFree = !!d.is_free;
  const amount = Number(d.amount || 0);
  const cards = cardWrap();
  const card = el(`
    <div class="ag-card">
      <div class="ag-card-title">Company Expert Visit</div>
      <div class="ag-card-sub">Ek expert aapke ghar aakar problem dekhega aur estimate dega.</div>
      <div class="ag-card-price">${isFree ? "FREE" : "₹" + esc(amount)}</div>
      <div class="ag-card-actions">
        <button class="ag-btn primary" data-expert="${esc(category || "")}">Request visit</button>
      </div>
    </div>`);
  card.querySelector("[data-expert]").addEventListener("click", (e) => {
    proposeExpertVisit(e.currentTarget.getAttribute("data-expert"));
  });
  cards.appendChild(card);
  mountCards(cards);
}

function renderConfirm(token, question, note) {
  const cards = cardWrap();
  const card = el(`
    <div class="ag-card ag-confirm">
      <div class="ag-confirm-q">${esc(question)}</div>
      ${note ? `<div class="ag-confirm-note">${esc(note)}</div>` : ""}
      <div class="ag-card-actions">
        <button class="ag-btn primary" data-yes>✅ Yes, confirm</button>
        <button class="ag-btn ghost" data-no>Cancel</button>
      </div>
    </div>`);
  const yes = card.querySelector("[data-yes]");
  const no = card.querySelector("[data-no]");
  yes.addEventListener("click", async () => {
    if (S.busy) return;
    yes.disabled = true; no.disabled = true;
    card.classList.add("done");
    await run({ confirm_token: token });
  });
  no.addEventListener("click", () => {
    yes.disabled = true; no.disabled = true;
    card.classList.add("cancelled");
    addAgent("Theek hai, cancel kar diya. Aur kuch chahiye? 🙂");
  });
  cards.appendChild(card);
  mountCards(cards);
}

function renderBooking(d, label) {
  const id = d.booking_id || d.id || "";
  const cards = cardWrap();
  const card = el(`
    <div class="ag-card ag-success">
      <div class="ag-card-head">
        <div class="ag-success-icon">✅</div>
        <div>
          <div class="ag-card-title">${esc(label)} confirmed</div>
          <div class="ag-card-sub">${id ? "Ref #" + esc(id) : ""} · ${esc(d.status || "pending")}</div>
        </div>
      </div>
      <div class="ag-card-actions">
        <button class="ag-btn ghost" data-track>Track my bookings</button>
      </div>
    </div>`);
  card.querySelector("[data-track]").addEventListener("click", () => {
    if (!S.mock && typeof ROUTER !== "undefined" && ROUTER.go) ROUTER.go("bookings");
    else addAgent("(demo) Yahan aapki bookings dikhengi.");
  });
  cards.appendChild(card);
  mountCards(cards);
}

// ── Card actions (writes: propose → confirm) ─────────────────────────────────
async function proposeBooking(vendorId, vendorName) {
  if (S.busy) return;
  const slot = defaultSlot();
  addUser(`Book ${vendorName}`);
  setBusy(true);
  const typing = showTyping();
  const res = await transport({ action: { tool: "create_booking", args: { vendor_id: Number(vendorId), scheduled_at: slot.iso } } });
  typing.remove();
  if (res && res.requires_confirmation) {
    if (res.reply) addAgent(res.reply);
    renderConfirm(res.confirm_token, `Book ${vendorName}?`, `${slot.human} · aap baad mein cancel bhi kar sakte hain.`);
  } else {
    handle(res);
  }
  setBusy(false);
}

async function proposeExpertVisit(category) {
  if (S.busy) return;
  const slot = defaultSlot();
  addUser(`Request expert visit (${category || "service"})`);
  setBusy(true);
  const typing = showTyping();
  const res = await transport({ action: { tool: "request_expert_visit", args: { category: category || S.lastCategory, scheduled_at: slot.iso } } });
  typing.remove();
  if (res && res.requires_confirmation) {
    if (res.reply) addAgent(res.reply);
    renderConfirm(res.confirm_token, `Book a company expert visit?`, `${slot.human}`);
  } else {
    handle(res);
  }
  setBusy(false);
}

// ── Bubbles / typing / helpers ───────────────────────────────────────────────
function addUser(text) { addBubble("user", text); }
function addAgent(text, cls) { addBubble("agent", text, cls); }

function addBubble(who, text, cls) {
  const row = document.createElement("div");
  row.className = `ag-row ${who}`;
  const b = document.createElement("div");
  b.className = `ag-bubble${cls ? " " + cls : ""}`;
  b.textContent = text;
  row.appendChild(b);
  S.msgs.appendChild(row);
  scrollDown();
}

function showTyping() {
  const row = document.createElement("div");
  row.className = "ag-row agent";
  row.innerHTML = `<div class="ag-bubble"><span class="ag-typing"><span></span><span></span><span></span></span></div>`;
  S.msgs.appendChild(row);
  scrollDown();
  return row;
}

function cardWrap() { const d = document.createElement("div"); d.className = "ag-cards"; return d; }
function mountCards(cards) {
  const row = document.createElement("div");
  row.className = "ag-row agent";
  row.appendChild(cards);
  S.msgs.appendChild(row);
  scrollDown();
}

function setBusy(b) { S.busy = b; if (S.send) S.send.disabled = b; }
function scrollDown() { requestAnimationFrame(() => { if (S.msgs) S.msgs.scrollTop = S.msgs.scrollHeight; }); }
function autoGrow() {
  if (!S.input) return;
  S.input.style.height = "auto";
  S.input.style.height = Math.min(S.input.scrollHeight, 120) + "px";
}
function goBack() {
  if (!S.mock && typeof ROUTER !== "undefined" && ROUTER.go) ROUTER.go("home");
  else history.length > 1 ? history.back() : (location.hash = "#home");
}

function friendlyName() {
  try {
    if (typeof AUTH !== "undefined" && AUTH.getUser) {
      const n = AUTH.getUser()?.name;
      if (n) return String(n).split(" ")[0];
    }
  } catch {}
  return "dost";
}

function detectCat(msg) {
  const m = String(msg).toLowerCase();
  for (const k of Object.keys(CATS)) if (m.includes(k)) return CATS[k];
  return null;
}

function defaultSlot() {
  const d = new Date(Date.now() + 24 * 3600 * 1000);
  d.setHours(11, 0, 0, 0);
  const p = (n) => String(n).padStart(2, "0");
  const iso = `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} 11:00:00`;
  const human = `Tomorrow, 11:00 AM`;
  return { iso, human };
}

function initial(name) { return String(name || "?").trim().charAt(0).toUpperCase() || "?"; }
function esc(s) {
  return String(s == null ? "" : s).replace(/[&<>"']/g, (c) =>
    ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
}
function el(html) { const t = document.createElement("template"); t.innerHTML = html.trim(); return t.content.firstElementChild; }

function mockEnabled() {
  try {
    if (typeof window !== "undefined" && window.WTG_AGENT_MOCK) return true;
    return new URLSearchParams(location.search).get("mock") === "1";
  } catch { return false; }
}

function injectCss() {
  if (document.getElementById("ag-css")) return;
  const base = new URL(".", import.meta.url).href; // app/agent/
  const link = document.createElement("link");
  link.id = "ag-css";
  link.rel = "stylesheet";
  link.href = `${base}agent.css?v=${encodeURIComponent(AG_VERSION)}`;
  document.head.appendChild(link);
}

// ── MOCK agent (mirrors the real orchestrator's response shapes) ─────────────
// Lets the entire flow render in a browser with NO backend and NO AI key.
const MOCK_VENDORS = (cat) => {
  const map = {
    painting: [
      { id: 101, business_name: "Sharma Painters", category_name: "Painting", rating: 4.8, experience_years: 8, is_verified: 1 },
      { id: 102, business_name: "City Paint Co", category_name: "Painting", rating: 4.5, experience_years: 5, is_verified: 0 },
      { id: 103, business_name: "Rangoli Wall Art", category_name: "Painting", rating: 4.6, experience_years: 6, is_verified: 1 },
    ],
    plumber: [
      { id: 201, business_name: "Verma Plumbing", category_name: "Plumber", rating: 4.7, experience_years: 10, is_verified: 1 },
      { id: 202, business_name: "QuickFix Nal Sewa", category_name: "Plumber", rating: 4.4, experience_years: 4, is_verified: 0 },
    ],
    electrician: [
      { id: 301, business_name: "Bijli Ghar Electricals", category_name: "Electrician", rating: 4.9, experience_years: 12, is_verified: 1 },
    ],
    waterproofing: [{ id: 401, business_name: "DryShield Waterproofing", category_name: "Waterproofing", rating: 4.6, experience_years: 7, is_verified: 1 }],
    cctv: [{ id: 501, business_name: "SecureView CCTV", category_name: "CCTV", rating: 4.5, experience_years: 5, is_verified: 1 }],
  };
  return map[cat] || map.painting;
};
const MOCK_PACKAGES = [
  { name: "Standard", description: "1 coat premium emulsion, putty + primer.", rate_per_sqft: 12, features: ["Putty + primer", "1 coat emulsion", "7-day finish"] },
  { name: "Premium", description: "2 coats, minor crack repair, taped edges.", rate_per_sqft: 18, features: ["Crack repair", "2 coats", "Taped edges", "Cleanup"] },
  { name: "Luxury", description: "Texture/designer finish, 3 coats, full prep.", rate_per_sqft: 28, features: ["Designer finish", "3 coats", "Full surface prep", "Warranty"] },
];

function mockAgent(payload) {
  return new Promise((resolve) => setTimeout(() => resolve(_mock(payload)), 480));
}
function _mock(payload) {
  if (payload.confirm_token) {
    const tool = String(payload.confirm_token).replace("mock-confirm-", "");
    if (tool === "request_expert_visit") {
      return { reply: "✅ Ho gaya! Expert visit request bhej di gayi. Team jaldi contact karegi.", tool, executed: true, data: { booking_id: 5502, status: "pending", booking_mode: "inspection" } };
    }
    return { reply: "✅ Ho gaya! Aapki booking confirm ho gayi hai.", tool: "create_booking", executed: true, data: { booking_id: 5501, status: "pending", booking_mode: "direct_vendor" } };
  }
  if (payload.action && payload.action.tool) {
    const tool = payload.action.tool;
    return { reply: "Bas ek baar confirm kar dijiye 👍", tool, requires_confirmation: true, confirm_token: "mock-confirm-" + tool, args_summary: Object.keys(payload.action.args || {}), executed: false };
  }
  const msg = String(payload.message || "").toLowerCase();
  if (/(package|packages|estimate|sqft)/.test(msg)) {
    return { reply: "Yeh rahe painting ke packages 👇 (aap area sqft batayenge to exact cost bhi nikal denge)", tool: "get_packages", executed: true, data: { packages: MOCK_PACKAGES } };
  }
  if (/(expert|visit|company|inspection)/.test(msg)) {
    return { reply: "Company expert visit ka charge yeh hai 👇", tool: "get_expert_visit_price", executed: true, data: { is_free: false, amount: 299 }, _category: "painting" };
  }
  const cat = detectCat(msg);
  if (cat) {
    return { reply: `Yeh rahe aapke area ke ${cat} experts 👇 Kisi ko bhi "Book now" karke turant book kar sakte hain.`, tool: "find_vendors", executed: true, data: { vendors: MOCK_VENDORS(cat) }, _category: cat };
  }
  return { reply: "Main aapki madad kar sakta hoon — painter, plumber, electrician dhoondhne mein, packages dikhane mein, ya booking karne mein. Bataiye kya chahiye? 🙂", tool: null, executed: false };
}
