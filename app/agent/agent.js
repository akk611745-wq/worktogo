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

const S = { msgs: null, input: null, send: null, busy: false, mock: false, role: "customer", lastCategory: "painting", orderingFor: null };

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
  S.role = opts.role || currentRole();

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

  openGreeting();
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

// Personalized opener. Real mode asks the backend (which reads OUR memory behind
// the wall) for a greeting; mock mode reads local mock-memory. New users get a
// warm generic greeting; returning users are recognised ("I know you like …").
async function openGreeting() {
  const name = friendlyName();
  const defaultChips = ["painter chahiye", "kapde chahiye", "behen ke liye gift", "expert visit"];

  if (S.mock) {
    const g = mockGreeting(name);
    addAgent(g.reply);
    renderChips(g.chips.length ? g.chips : defaultChips);
    return;
  }
  const typing = showTyping();
  let res = null;
  try { res = await transport({ event: "open" }); } catch {}
  typing.remove();
  if (res && res.reply && !res._error) {
    addAgent(res.reply);
    renderChips(Array.isArray(res.chips) && res.chips.length ? res.chips : defaultChips);
  } else {
    addAgent(`Namaste ${name}! 👋 Main aapka WorkToGo assistant hoon.\nBataiye kya chahiye?`);
    renderChips(defaultChips);
  }
}

function renderChips(chips) {
  const row = document.createElement("div");
  row.className = "ag-row agent";
  const wrap = document.createElement("div");
  wrap.className = "ag-chips";
  chips.slice(0, 6).forEach((c) => {
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
  } else if (res.tool === "browse_products" && Array.isArray(d.products)) {
    d.products.length ? renderProductCards(d.products) : addAgent("Is search ke liye abhi koi product nahi mila. 😔");
  } else if (res.tool === "get_product" && d.product) {
    renderProductCards([d.product]);
  } else if (res.tool === "add_to_cart") {
    renderCartAdded(d);
  } else if (res.tool === "view_cart") {
    renderCart(d);
  } else if (res.tool === "list_orders" && Array.isArray(d.orders)) {
    d.orders.length ? renderOrders(d.orders) : addAgent("Abhi koi order nahi hai.");
  } else if (res.tool === "track_order" && d.order) {
    renderOrderDetail(d.order);
  } else if (res.tool === "my_status" && d.status) {
    renderStatus(d.status);
  } else if (res.tool === "list_reminders" && Array.isArray(d.reminders)) {
    d.reminders.length ? renderReminders(d.reminders) : addAgent("Abhi koi reminder set nahi hai. \"weekly reminder\" bolkar set kar sakte hain ⏰");
  } else if (res.tool === "list_people" && Array.isArray(d.people)) {
    renderPeoplePicker(d.people);
  } else if (res.widget) {
    renderWidget(res.widget);   // unified inline action: confirm / upload / otp / onboard / add_person
  } else if (res.requires_confirmation) {
    renderConfirm(res.confirm_token, res.reply || "Confirm karein?", null);
  } else if (res.executed && res.tool === "create_booking") {
    renderBooking(d, "Booking");
  } else if (res.executed && res.tool === "request_expert_visit") {
    renderBooking(d, "Expert visit");
  } else if (res.executed && res.tool === "checkout") {
    renderOrderPlaced(d);
  } else if (res.executed && res.tool === "save_listing") {
    renderListingSaved(d);
  } else if (res.executed && res.tool === "set_reminder") {
    renderReminderSet(d.reminder || {});
  } else if (res.executed && res.tool === "save_person" && d.person) {
    renderPersonSaved(d.person);
  } else if (res.executed && res.tool === "order_for") {
    renderOrderPlacedFor(d);
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

// ── Inline UI-action widgets (Phase 4) ───────────────────────────────────────
// The agent opens a real action right in the chat; it completes against the
// EXISTING backend endpoint (with the user's JWT) — no page change, no engine edit.
function renderWidget(w) {
  if (!w || !w.type) return;
  if (w.type === "confirm") return renderConfirm(w.confirm_token, w.question || "Confirm karein?", null);
  if (w.type === "upload")  return renderUpload(w);
  if (w.type === "otp")     return renderOtp(w);
  if (w.type === "onboard") return renderOnboard(w);
  if (w.type === "add_person") return renderAddPerson(w);
}

// ── Connections (Phase 8): people picker, add-person, order-for ──────────────
function setOrderingFor(person) {
  S.orderingFor = person ? { id: person.id, name: person.name } : null;
  if (person) addAgent(`Theek hai — ${person.name} ke liye. 🎁 Ab bataiye kya bhejein? (jaise "kapde chahiye")`);
}

function renderPeoplePicker(people) {
  const cards = cardWrap();
  const wrap = el(`<div class="ag-card"><div class="ag-card-title">Kiske liye order karna hai?</div><div class="ag-people"></div>
    <div class="ag-card-actions"><button class="ag-btn ghost" data-addnew>➕ Nayi person add karein</button></div></div>`);
  const list = wrap.querySelector(".ag-people");
  if (!people.length) {
    list.innerHTML = `<div class="ag-card-sub">Abhi koi saved person nahi hai — nayi add kijiye.</div>`;
  } else {
    people.forEach((p) => {
      const row = el(`<div class="ag-person">
          <div class="ag-card-ava">${esc(initial(p.name))}</div>
          <div style="flex:1;min-width:0"><div class="ag-card-title">${esc(p.name)}</div>
            <div class="ag-card-sub">${esc(p.relation || "")}${p.city ? " · " + esc(p.city) : ""}</div></div>
          <button class="ag-btn primary ag-person-pick" data-id="${esc(p.id)}" data-name="${esc(p.name)}">Order for</button>
        </div>`);
      row.querySelector(".ag-person-pick").addEventListener("click", (e) =>
        setOrderingFor({ id: Number(e.currentTarget.getAttribute("data-id")), name: e.currentTarget.getAttribute("data-name") }));
      list.appendChild(row);
    });
  }
  wrap.querySelector("[data-addnew]").addEventListener("click", () => run({ action: { tool: "add_person", args: {} } }));
  cards.appendChild(wrap); mountCards(cards);
}

function renderAddPerson(w) {
  const fields = Array.isArray(w.fields) ? w.fields : [];
  const cards = cardWrap();
  const card = el(`<div class="ag-card ag-widget"><div class="ag-card-title">${esc(w.title || "Add person")}</div>
      <div class="ag-addperson"></div>
      <div class="ag-card-actions"><button class="ag-btn primary" data-save>Save & continue</button></div>
      <div class="ag-widget-status"></div></div>`);
  const body = card.querySelector(".ag-addperson");
  fields.forEach((f) => {
    const inp = document.createElement("input");
    inp.className = "ag-textin"; inp.type = "text"; inp.dataset.key = f.key;
    inp.placeholder = (f.q || f.key) + (f.placeholder ? " — " + f.placeholder : "");
    body.appendChild(inp);
  });
  const status = card.querySelector(".ag-widget-status");
  card.querySelector("[data-save]").addEventListener("click", async () => {
    const args = {};
    body.querySelectorAll("input").forEach((i) => { if (i.value.trim() !== "") args[i.dataset.key] = i.value.trim(); });
    if (!args.name) { status.textContent = "Naam zaroori hai 🙂"; return; }
    card.classList.add("done");
    await run({ action: { tool: w.submit_tool || "save_person", args } });
  });
  cards.appendChild(card); mountCards(cards);
}

function renderPersonSaved(person) {
  const cards = cardWrap();
  const card = el(`<div class="ag-card ag-success"><div class="ag-card-title">${esc(person.name)} save ho gaye ✓</div>
      <div class="ag-card-sub">${esc(person.relation || "")}${person.city ? " · " + esc(person.city) : ""}</div></div>`);
  cards.appendChild(card); mountCards(cards);
  setOrderingFor(person);
}

function renderOrderPlacedFor(d) {
  const id = d.order_id || d.id || "";
  const who = S.orderingFor ? S.orderingFor.name : "them";
  const cards = cardWrap();
  const card = el(`<div class="ag-card ag-success">
      <div class="ag-card-head"><div class="ag-success-icon">🎁</div>
        <div><div class="ag-card-title">Order placed for ${esc(who)}</div>
        <div class="ag-card-sub">${id ? "Order #" + esc(id) : ""} · COD · unke address pe deliver hoga</div></div></div>
    </div>`);
  cards.appendChild(card); mountCards(cards);
  S.orderingFor = null;
}

async function proposeOrderFor() {
  if (S.busy || !S.orderingFor) return;
  addUser(`Checkout for ${S.orderingFor.name}`);
  setBusy(true);
  const typing = showTyping();
  const res = await transport({ action: { tool: "order_for", args: { person_id: S.orderingFor.id } } });
  typing.remove();
  if (res && res.requires_confirmation) {
    if (res.reply) addAgent(res.reply);
    renderConfirm(res.confirm_token, `${S.orderingFor.name} ke liye order place karein? (COD)`, "Unke saved address pe deliver hoga — agent paisa nahi leta.");
  } else { handle(res); }
  setBusy(false);
}

// ── Vendor-agent (Phase 6): onboarding stepper, status card, reminders ───────
// The vendor never learns a dashboard — one friendly question at a time.
function renderOnboard(w) {
  const steps = Array.isArray(w.steps) ? w.steps : [];
  if (!steps.length) { addAgent("Onboarding abhi available nahi hai."); return; }
  const answers = {};
  let i = 0;
  const cards = cardWrap();
  const card = el(`<div class="ag-card ag-widget ag-onboard">
      <div class="ag-onboard-progress"></div>
      <div class="ag-onboard-q"></div>
      <div class="ag-onboard-body"></div>
      <div class="ag-card-actions"><button class="ag-btn primary" data-next>Next →</button></div>
      <div class="ag-widget-status"></div>
    </div>`);
  const progress = card.querySelector(".ag-onboard-progress");
  const qEl = card.querySelector(".ag-onboard-q");
  const body = card.querySelector(".ag-onboard-body");
  const nextBtn = card.querySelector("[data-next]");
  const status = card.querySelector(".ag-widget-status");

  function paintStep() {
    const s = steps[i];
    progress.textContent = `Step ${i + 1} / ${steps.length}`;
    qEl.textContent = s.q || "";
    body.innerHTML = "";
    if (s.kind === "select") {
      const sel = document.createElement("select");
      sel.className = "ag-textin ag-onboard-input";
      sel.innerHTML = `<option value="">— choose —</option>` + (s.options || []).map((o) => `<option value="${esc(o.value)}">${esc(o.label)}</option>`).join("");
      body.appendChild(sel);
    } else if (s.kind === "multiselect") {
      const wrap = document.createElement("div"); wrap.className = "ag-chips ag-onboard-multi";
      (s.options || []).forEach((o) => {
        const b = document.createElement("button"); b.type = "button"; b.className = "ag-chip"; b.textContent = o.label; b.dataset.value = o.value;
        b.addEventListener("click", () => b.classList.toggle("sel"));
        wrap.appendChild(b);
      });
      body.appendChild(wrap);
    } else {
      const inp = document.createElement("input");
      inp.className = "ag-textin ag-onboard-input";
      inp.type = s.kind === "number" ? "number" : "text";
      if (s.placeholder) inp.placeholder = s.placeholder;
      body.appendChild(inp);
    }
    nextBtn.textContent = i === steps.length - 1 ? "Review →" : "Next →";
    status.textContent = "";
  }

  function readStep() {
    const s = steps[i];
    if (s.kind === "multiselect") {
      const vals = Array.from(body.querySelectorAll(".ag-chip.sel")).map((b) => b.dataset.value);
      return vals.join(",");
    }
    const inp = body.querySelector(".ag-onboard-input");
    return inp ? String(inp.value).trim() : "";
  }

  nextBtn.addEventListener("click", () => {
    const s = steps[i];
    const val = readStep();
    if ((s.kind === "text" || s.kind === "number" || s.kind === "select") && val === "" && s.key === "name") {
      status.textContent = "Yeh zaroori hai 🙂"; return;
    }
    if (val !== "") answers[s.key] = val;
    if (i < steps.length - 1) { i++; paintStep(); return; }
    // Last step → review + submit.
    card.remove();
    proposeSaveListing(answers, w.submit_tool || "save_listing", steps);
  });

  cards.appendChild(card); mountCards(cards); paintStep();
}

function proposeSaveListing(answers, submitTool, steps) {
  // Human-readable summary (labels, not ids) for the review.
  const labelFor = (key, val) => {
    const s = (steps || []).find((x) => x.key === key);
    if (s && (s.kind === "select" || s.kind === "multiselect") && Array.isArray(s.options)) {
      const parts = String(val).split(",").map((v) => (s.options.find((o) => String(o.value) === String(v)) || {}).label || v);
      return parts.join(", ");
    }
    return val;
  };
  const nice = { name: "Naam", category_id: "Kaam", area: "Area", experience_years: "Experience", languages: "Bhasha" };
  const lines = Object.keys(answers).map((k) => `${nice[k] || k}: ${esc(labelFor(k, answers[k]))}`).join("\n");
  addAgent("Bas ek nazar daal lijiye 👇\n" + lines);
  run({ action: { tool: submitTool, args: answers } });
}

function renderListingSaved(d) {
  const cards = cardWrap();
  const card = el(`<div class="ag-card ag-success">
      <div class="ag-card-head"><div class="ag-success-icon">🎉</div>
        <div><div class="ag-card-title">Listing ban gayi!</div>
        <div class="ag-card-sub">Admin approve karte hi customers ko dikhne lagegi.</div></div></div>
      <div class="ag-card-actions"><button class="ag-btn ghost" data-photo>Work photo add karein</button></div>
    </div>`);
  card.querySelector("[data-photo]").addEventListener("click", () => run({ message: "upload work photo" }));
  cards.appendChild(card); mountCards(cards);
}

function renderStatus(st) {
  const hints = Array.isArray(st.growth_hints) ? st.growth_hints : [];
  const cards = cardWrap();
  const card = el(`<div class="ag-card ag-status">
      <div class="ag-card-head">
        <div class="ag-card-ava">${esc(initial(st.business_name))}</div>
        <div><div class="ag-card-title">${esc(st.business_name || "Aap")}</div>
        <div class="ag-card-sub">Status: <span class="ag-badge ${st.status === "active" ? "ok" : ""}">${esc(st.status || "pending")}</span></div></div>
      </div>
      <div class="ag-stat-row">
        <div class="ag-stat"><b>${esc(st.completed_jobs || 0)}</b><span>jobs</span></div>
        <div class="ag-stat"><b>${st.rating ? esc(Number(st.rating).toFixed(1)) : "—"}</b><span>rating</span></div>
        <div class="ag-stat"><b>${esc(st.photo_count || 0)}</b><span>photos</span></div>
        <div class="ag-stat"><b>${esc(st.followers || 0)}</b><span>followers</span></div>
      </div>
      ${hints.length ? `<div class="ag-hints">${hints.map((h) => `<div class="ag-hint">💡 ${esc(h)}</div>`).join("")}</div>` : ""}
      <div class="ag-card-actions">
        <button class="ag-btn primary" data-photo>Add work photo</button>
        <button class="ag-btn ghost" data-remind>Weekly reminder</button>
      </div>
    </div>`);
  card.querySelector("[data-photo]").addEventListener("click", () => run({ message: "upload work photo" }));
  card.querySelector("[data-remind]").addEventListener("click", () => run({ message: "weekly photo reminder" }));
  cards.appendChild(card); mountCards(cards);
}

function renderReminderSet(r) {
  const cards = cardWrap();
  const card = el(`<div class="ag-card ag-success">
      <div class="ag-card-title">Reminder set ⏰</div>
      <div class="ag-card-sub">${esc(r.title || "Reminder")} · ${esc(r.cadence || "weekly")}</div>
    </div>`);
  cards.appendChild(card); mountCards(cards);
}

// Phase 9: show the user's upcoming reminders (the delivery cron fires them).
function renderReminders(list) {
  const cards = cardWrap();
  list.slice(0, 10).forEach((r) => {
    const when = String(r.next_at || "").replace("T", " ").slice(0, 16);
    cards.appendChild(el(`<div class="ag-card">
        <div class="ag-card-title">⏰ ${esc(r.title || "Reminder")}</div>
        <div class="ag-card-sub">${esc(r.cadence || "weekly")}${when ? " · agla: " + esc(when) : ""}</div>
      </div>`));
  });
  mountCards(cards);
}

function renderUpload(w) {
  const cards = cardWrap();
  const card = el(`
    <div class="ag-card ag-widget">
      <div class="ag-card-title">${esc(w.title || "Upload photo")}</div>
      <input type="file" class="ag-file" accept="${esc(w.accept || "image/*")}" />
      <input type="text" class="ag-textin ag-cap" placeholder="Caption (optional)" />
      <div class="ag-card-actions"><button class="ag-btn primary" data-up>Upload</button></div>
      <div class="ag-widget-status"></div>
    </div>`);
  const fileEl = card.querySelector(".ag-file");
  const capEl  = card.querySelector(".ag-cap");
  const btn    = card.querySelector("[data-up]");
  const status = card.querySelector(".ag-widget-status");
  btn.addEventListener("click", async () => {
    const f = fileEl.files && fileEl.files[0];
    if (!f) { status.textContent = "Pehle ek photo chuniye."; return; }
    btn.disabled = true; status.textContent = "Uploading…";
    let ok = false, msg = "";
    if (S.mock) { await sleep(600); ok = true; }
    else if (typeof API !== "undefined" && API.uploadMultipart) {
      const fd = new FormData();
      fd.append(w.field || "photo", f);
      if (w.caption_field && capEl.value.trim()) fd.append(w.caption_field, capEl.value.trim());
      const r = await API.uploadMultipart(w.endpoint, fd);
      ok = r.ok; msg = r.error || "";
    } else { msg = "Upload unavailable."; }
    if (ok) { card.classList.add("done"); status.textContent = w.success_text || "Uploaded ✓"; }
    else { btn.disabled = false; status.textContent = msg || "Upload fail hua. Dobara try kijiye."; }
  });
  cards.appendChild(card); mountCards(cards);
}

function renderOtp(w) {
  const cards = cardWrap();
  const card = el(`
    <div class="ag-card ag-widget">
      <div class="ag-card-title">${esc(w.title || "Verify your number")}</div>
      <input type="tel" class="ag-textin ag-phone" placeholder="Phone number" value="${esc(currentPhone())}" />
      <div class="ag-card-actions"><button class="ag-btn ghost" data-send>Send OTP</button></div>
      <div class="ag-otp-step" hidden>
        <input type="text" inputmode="numeric" class="ag-textin ag-otp" placeholder="Enter OTP" maxlength="6" />
        <div class="ag-card-actions"><button class="ag-btn primary" data-verify>Verify</button></div>
      </div>
      <div class="ag-widget-status"></div>
    </div>`);
  const phoneEl  = card.querySelector(".ag-phone");
  const step     = card.querySelector(".ag-otp-step");
  const otpEl    = card.querySelector(".ag-otp");
  const status   = card.querySelector(".ag-widget-status");
  const sendBtn  = card.querySelector("[data-send]");
  const verifyBtn= card.querySelector("[data-verify]");
  sendBtn.addEventListener("click", async () => {
    const ph = (phoneEl.value || "").trim();
    if (ph.replace(/\D/g, "").length < 10) { status.textContent = "Sahi number daaliye."; return; }
    sendBtn.disabled = true; status.textContent = "OTP bhej rahe hain…";
    let ok = true, msg = "";
    if (!S.mock && typeof API !== "undefined" && API.sendOtp) { const r = await API.sendOtp(ph); ok = r.ok; msg = r.error || ""; }
    else { await sleep(500); }
    if (!ok) { sendBtn.disabled = false; status.textContent = msg || "OTP send fail hua."; return; }
    step.hidden = false;
    status.textContent = S.mock ? "OTP bheja gaya (demo: koi bhi 4–6 digit)." : "OTP aapke number par bheja gaya hai.";
  });
  verifyBtn.addEventListener("click", async () => {
    const code = (otpEl.value || "").trim();
    if (!/^\d{4,6}$/.test(code)) { status.textContent = "4–6 digit OTP daaliye."; return; }
    verifyBtn.disabled = true; status.textContent = "Verifying…";
    let ok = false, msg = "";
    if (S.mock) { await sleep(500); ok = true; }
    else if (typeof API !== "undefined" && API.verifyOtp) { const r = await API.verifyOtp((phoneEl.value || "").trim(), code); ok = r.ok; msg = r.error || ""; }
    if (ok) { card.classList.add("done"); step.hidden = true; status.textContent = w.success_text || "Verified ✓"; }
    else { verifyBtn.disabled = false; status.textContent = msg || "Galat OTP. Dobara try kijiye."; }
  });
  cards.appendChild(card); mountCards(cards);
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

// ── Commerce (Phase 5): product cards, cart, checkout, orders ────────────────
function productImage(p) {
  let img = p.image || p.thumbnail || p.image_url || "";
  if (!img && p.images) {
    if (Array.isArray(p.images)) img = p.images[0] || "";
    else if (typeof p.images === "string") { try { const a = JSON.parse(p.images); img = Array.isArray(a) ? a[0] : p.images; } catch { img = p.images; } }
  }
  return typeof img === "string" ? img : (img && img.url) || "";
}
function priceHtml(p) {
  const price = Number(p.price || 0);
  const sale = p.sale_price != null && Number(p.sale_price) > 0 && Number(p.sale_price) < price ? Number(p.sale_price) : null;
  if (sale != null) return `<span class="ag-price">₹${esc(sale)}</span> <span class="ag-price-strike">₹${esc(price)}</span>`;
  return `<span class="ag-price">₹${esc(price)}</span>`;
}

function renderProductCards(products) {
  const cards = cardWrap();
  products.slice(0, 8).forEach((p) => {
    const name = p.name || "Product";
    const img = productImage(p);
    const rating = p.rating != null && Number(p.rating) > 0 ? Number(p.rating).toFixed(1) : null;
    const card = el(`
      <div class="ag-card ag-product">
        <div class="ag-product-row">
          ${img ? `<img class="ag-product-img" src="${esc(img)}" alt="" loading="lazy" onerror="this.style.display='none'"/>` : `<div class="ag-product-img ag-noimg">🛍️</div>`}
          <div class="ag-product-meta">
            <div class="ag-card-title">${esc(name)}</div>
            <div class="ag-card-sub">${esc(p.store_name || "")}${p.is_company ? ' · <span class="ag-badge ok">Company</span>' : ""}</div>
            <div class="ag-price-row">${priceHtml(p)} ${rating ? `<span class="ag-badge star">⭐ ${esc(rating)}</span>` : ""}</div>
          </div>
        </div>
        <div class="ag-card-actions">
          <button class="ag-btn primary" data-add="${esc(p.id)}" data-name="${esc(name)}">Add to cart</button>
        </div>
      </div>`);
    card.querySelector("[data-add]").addEventListener("click", (e) =>
      addToCart(e.currentTarget.getAttribute("data-add"), e.currentTarget.getAttribute("data-name")));
    cards.appendChild(card);
  });
  mountCards(cards);
}

function renderCartAdded(cart) {
  const count = cart && (cart.count != null ? cart.count : (Array.isArray(cart.items) ? cart.items.length : 0));
  const subtotal = cart && (cart.subtotal != null ? cart.subtotal : 0);
  const cards = cardWrap();
  const card = el(`
    <div class="ag-card ag-success">
      <div class="ag-card-title">Cart mein add ho gaya ✓</div>
      <div class="ag-card-sub">${esc(count)} item(s)${subtotal ? " · ₹" + esc(subtotal) : ""}</div>
      <div class="ag-card-actions">
        <button class="ag-btn primary" data-checkout>${checkoutLabel()}</button>
        <button class="ag-btn ghost" data-viewcart>View cart</button>
      </div>
    </div>`);
  card.querySelector("[data-checkout]").addEventListener("click", proposeCheckout);
  card.querySelector("[data-viewcart]").addEventListener("click", () => run({ action: { tool: "view_cart", args: {} } }));
  cards.appendChild(card); mountCards(cards);
}

function renderCart(cart) {
  const items = Array.isArray(cart && cart.items) ? cart.items : [];
  if (!items.length) { addAgent("Aapka cart abhi khali hai. 🛒 Kuch dhoondein?"); return; }
  const cards = cardWrap();
  const rows = items.slice(0, 10).map((it) =>
    `<div class="ag-cart-row"><span>${esc(it.name || it.product_name || "Item")} × ${esc(it.quantity || 1)}</span><span>₹${esc(it.line_total || it.price || 0)}</span></div>`).join("");
  const card = el(`
    <div class="ag-card">
      <div class="ag-card-title">Your cart 🛒</div>
      ${rows}
      <div class="ag-cart-row ag-cart-total"><span>Total</span><span>₹${esc(cart.subtotal || 0)}</span></div>
      <div class="ag-card-actions"><button class="ag-btn primary" data-checkout>${checkoutLabel()}</button></div>
    </div>`);
  card.querySelector("[data-checkout]").addEventListener("click", proposeCheckout);
  cards.appendChild(card); mountCards(cards);
}

function renderOrderPlaced(d) {
  const id = d.order_id || d.id || "";
  const cards = cardWrap();
  const card = el(`
    <div class="ag-card ag-success">
      <div class="ag-card-head">
        <div class="ag-success-icon">✅</div>
        <div>
          <div class="ag-card-title">Order placed</div>
          <div class="ag-card-sub">${id ? "Order #" + esc(id) : ""} · Cash on Delivery</div>
        </div>
      </div>
      <div class="ag-card-actions"><button class="ag-btn ghost" data-track="${esc(id)}">Track order</button></div>
    </div>`);
  card.querySelector("[data-track]").addEventListener("click", (e) => {
    const oid = e.currentTarget.getAttribute("data-track");
    if (oid) run({ action: { tool: "track_order", args: { id: Number(oid) } } });
  });
  cards.appendChild(card); mountCards(cards);
}

function renderOrders(orders) {
  const cards = cardWrap();
  orders.slice(0, 8).forEach((o) => {
    const id = o.id || o.order_id || "";
    const card = el(`
      <div class="ag-card">
        <div class="ag-card-title">Order #${esc(id)}</div>
        <div class="ag-card-sub">${esc(o.status || "pending")}${o.total != null ? " · ₹" + esc(o.total) : (o.grand_total != null ? " · ₹" + esc(o.grand_total) : "")}</div>
        <div class="ag-card-actions"><button class="ag-btn ghost" data-track="${esc(id)}">Track</button></div>
      </div>`);
    card.querySelector("[data-track]").addEventListener("click", (e) =>
      run({ action: { tool: "track_order", args: { id: Number(e.currentTarget.getAttribute("data-track")) } } }));
    cards.appendChild(card);
  });
  mountCards(cards);
}

function renderOrderDetail(o) {
  const id = o.id || o.order_id || "";
  const items = Array.isArray(o.items) ? o.items : [];
  const cards = cardWrap();
  const rows = items.slice(0, 10).map((it) => `<div class="ag-cart-row"><span>${esc(it.name || it.product_name || "Item")} × ${esc(it.quantity || 1)}</span><span>₹${esc(it.line_total || it.price || 0)}</span></div>`).join("");
  const card = el(`
    <div class="ag-card">
      <div class="ag-card-title">Order #${esc(id)}</div>
      <div class="ag-card-sub">Status: ${esc(o.status || "pending")}</div>
      ${rows}
    </div>`);
  cards.appendChild(card); mountCards(cards);
}

async function addToCart(productId, name) {
  if (S.busy) return;
  addUser(`Add ${name}`);
  await run({ action: { tool: "add_to_cart", args: { product_id: Number(productId), quantity: 1 } } });
}

function checkoutLabel() { return S.orderingFor ? `Checkout for ${esc(S.orderingFor.name)}` : "Checkout (COD)"; }

async function proposeCheckout() {
  if (S.busy) return;
  if (S.orderingFor) return proposeOrderFor();   // gifting → deliver to the saved person
  addUser("Checkout");
  setBusy(true);
  const typing = showTyping();
  const res = await transport({ action: { tool: "checkout", args: {} } });
  typing.remove();
  if (res && res.requires_confirmation) {
    if (res.reply) addAgent(res.reply);
    renderConfirm(res.confirm_token, "Order place karein? (Cash on Delivery)", "Aap delivery par payment karenge — agent paisa nahi leta.");
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

function sleep(ms) { return new Promise((r) => setTimeout(r, ms)); }

function currentRole() {
  try { const r = new URLSearchParams(location.search).get("role"); if (r) return r; } catch {}
  try {
    if (typeof AUTH !== "undefined" && AUTH.getRole) return AUTH.getRole();
    if (typeof AUTH !== "undefined" && AUTH.getUser) { const u = AUTH.getUser(); if (u && u.role) return u.role; }
  } catch {}
  return "customer";
}

function currentPhone() {
  try { if (typeof AUTH !== "undefined" && AUTH.getUser) { const u = AUTH.getUser(); if (u && u.phone) return String(u.phone); } } catch {}
  return "";
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
// Commerce demo data (city-scoped, dual-store: company + local vendor)
const MOCK_PRODUCTS = [
  { id: 9001, name: "Cotton Kurta (Men)",           store_name: "Haldwani Vastralay", is_company: true,  price: 899,  sale_price: 699,  rating: 4.6 },
  { id: 9002, name: "Silk Saree — Diwali Special",  store_name: "Rangoli Fabrics",    is_company: false, price: 2499, sale_price: 1999, rating: 4.8 },
  { id: 9003, name: "Kids Ethnic Set",              store_name: "Haldwani Vastralay", is_company: true,  price: 1199, rating: 4.5 },
  { id: 9004, name: "Festive Kurti (Women)",        store_name: "Trendz Boutique",    is_company: false, price: 1299, sale_price: 999,  rating: 4.7 },
];
const MOCK_CATEGORIES = [
  { value: 1, label: "Painting" }, { value: 2, label: "Plumber" }, { value: 3, label: "Electrician" },
  { value: 4, label: "Waterproofing" }, { value: 5, label: "CCTV" },
];
const MOCK_STATUS = {
  business_name: "Sharma Painters", status: "pending", rating: 4.6, experience_years: 6,
  languages: ["hindi"], completed_jobs: 12, bookings_today: 1, followers: 8, photo_count: 0,
  growth_hints: [
    "Abhi koi work photo nahi hai — 3 achhi photo add karo, customers ka trust 2x badhta hai. 📸",
    "Aapki listing abhi review mein hai — approve hote hi customers ko dikhne lagegi.",
  ],
};
const MOCK_PEOPLE = [
  { id: 501, name: "Priya", relation: "behen", city: "Haldwani", address_text: "12, Rajpur Road", phone: "9876500001", lat: 29.22, lng: 79.51 },
  { id: 502, name: "Rahul", relation: "dost", city: "Haldwani", address_text: "5, Kathgodam", phone: "9876500002" },
];
const MOCK_REMINDERS = [
  { title: "Weekly work-photo upload", cadence: "weekly", next_at: "2026-09-07 11:00:00" },
  { title: "Weekly business reminder", cadence: "weekly", next_at: "2026-09-07 11:00:00" },
];
let MOCK_PEOPLE_NEXT = 600;
const MOCK_CART = { items: [] };
function mockFindProduct(pid) { return MOCK_PRODUCTS.find((p) => p.id === Number(pid)) || null; }
function mockCartAdd(pid) {
  const p = mockFindProduct(pid); if (!p) return;
  const ex = MOCK_CART.items.find((i) => i.product_id === p.id);
  if (ex) ex.quantity++; else MOCK_CART.items.push({ product_id: p.id, name: p.name, price: p.sale_price || p.price, quantity: 1 });
}
function mockCartGet() {
  const items = MOCK_CART.items.map((i) => ({ ...i, line_total: i.price * i.quantity }));
  return { items, count: items.reduce((s, i) => s + i.quantity, 0), subtotal: items.reduce((s, i) => s + i.line_total, 0) };
}

// Mock memory (demo only) — persists in localStorage so a SECOND visit is
// personalized, mirroring the real server-side memory behind the wall.
function mockMem() { try { return JSON.parse(localStorage.getItem("wtg_agent_mock_mem") || "{}") || {}; } catch { return {}; } }
function mockMemSave(m) { try { localStorage.setItem("wtg_agent_mock_mem", JSON.stringify(m)); } catch {} }
function mockMemRecord(cat) { const m = mockMem(); m.turns = (m.turns || 0) + 1; if (cat) m.lastCat = cat; mockMemSave(m); }
function mockGreeting(name) {
  if (String(S.role).indexOf("vendor") === 0) {
    return {
      reply: `Namaste ${name}! 👋 Aap apni skill bataiye — main aapke liye premium customers laa dunga. Kuch seekhna nahi, bas baat kijiye 🙂`,
      chips: ["listing banao", "mera status", "photo upload", "weekly reminder", "mere reminders"],
    };
  }
  const m = mockMem();
  if ((m.turns || 0) > 0 && m.lastCat) {
    return {
      reply: `Welcome back, ${name}! 👋 Pichli baar aapne ${m.lastCat} dekha tha — aaj wahi chahiye ya kuch aur?`,
      chips: [`${m.lastCat} chahiye`, `book ${m.lastCat}`, `${m.lastCat} packages`, "expert visit"],
    };
  }
  if ((m.turns || 0) > 0) {
    return { reply: `Welcome back, ${name}! 👋 Bataiye aaj kya chahiye?`, chips: ["painter chahiye", "kapde chahiye", "behen ke liye gift", "expert visit"] };
  }
  return {
    reply: `Namaste ${name}! 👋 Main aapka WorkToGo assistant hoon.\nBataiye kya chahiye — main dhoond ke, dikhake, book bhi kar deta hoon.`,
    chips: ["painter chahiye", "kapde chahiye", "behen ke liye gift", "expert visit"],
  };
}

function mockAgent(payload) {
  return new Promise((resolve) => setTimeout(() => resolve(_mock(payload)), 480));
}
function _mock(payload) {
  if (payload.confirm_token) {
    const tool = String(payload.confirm_token).replace("mock-confirm-", "");
    if (tool === "request_expert_visit") {
      return { reply: "✅ Ho gaya! Expert visit request bhej di gayi. Team jaldi contact karegi.", tool, executed: true, data: { booking_id: 5502, status: "pending", booking_mode: "inspection" } };
    }
    if (tool === "checkout") {
      MOCK_CART.items = [];
      return { reply: "✅ Order place ho gaya! (Cash on Delivery)", tool: "checkout", executed: true, data: { order_id: 8801, status: "pending" } };
    }
    if (tool === "save_listing") {
      return { reply: "", tool: "save_listing", executed: true, data: { updated: true } };
    }
    if (tool === "set_reminder") {
      return { reply: "", tool: "set_reminder", executed: true, data: { reminder: { title: "Weekly work-photo upload", cadence: "weekly" } } };
    }
    if (tool === "order_for") {
      MOCK_CART.items = [];
      return { reply: "✅ Gift order place ho gaya!", tool: "order_for", executed: true, data: { order_id: 8802, status: "pending" } };
    }
    return { reply: "✅ Ho gaya! Aapki booking confirm ho gayi hai.", tool: "create_booking", executed: true, data: { booking_id: 5501, status: "pending", booking_mode: "direct_vendor" } };
  }
  if (payload.event === "open") {
    const g = mockGreeting(friendlyName());
    return { reply: g.reply, chips: g.chips, tool: null, executed: false };
  }
  if (payload.action && payload.action.tool) {
    const tool = payload.action.tool;
    const args = payload.action.args || {};
    // Read-mode actions execute immediately (no confirm) — mirrors the backend.
    if (tool === "add_to_cart") { mockCartAdd(args.product_id); return { reply: "", tool, executed: true, data: mockCartGet() }; }
    if (tool === "view_cart")   { return { reply: "", tool, executed: true, data: mockCartGet() }; }
    if (tool === "track_order") { return { reply: "Yeh raha aapka order 👇", tool, executed: true, data: { order: { id: args.id || 8801, status: "pending", items: mockCartGet().items } } }; }
    // Connections (read / light-write actions execute immediately).
    if (tool === "list_people") { return { reply: "Kiske liye order karna hai? 👇", tool, executed: true, data: { people: MOCK_PEOPLE } }; }
    if (tool === "get_person")  { return { reply: "", tool, executed: true, data: { person: MOCK_PEOPLE.find(p => p.id === Number(args.id)) || MOCK_PEOPLE[0] } }; }
    if (tool === "add_person")  { return { reply: "Unki detail bhar dijiye 👇", tool, executed: true, data: {}, widget: { type: "add_person", title: "Kiske liye order karna hai? Unki detail save kijiye", submit_tool: "save_person", fields: [
        { key: "name", q: "Naam", kind: "text", placeholder: "e.g. Priya" },
        { key: "relation", q: "Rishta", kind: "text", placeholder: "e.g. behen" },
        { key: "phone", q: "Phone (optional)", kind: "text", placeholder: "10-digit" },
        { key: "address_text", q: "Address", kind: "text", placeholder: "ghar ka pata" },
        { key: "city", q: "City (optional)", kind: "text", placeholder: "e.g. Haldwani" } ] } }; }
    if (tool === "save_person") { const p = { id: MOCK_PEOPLE_NEXT++, name: args.name || "New person", relation: args.relation || "", city: args.city || "", address_text: args.address_text || "", phone: args.phone || "" }; MOCK_PEOPLE.unshift(p); return { reply: "", tool, executed: true, data: { person: p } }; }
    // Writes → confirmation.
    return { reply: "Bas ek baar confirm kar dijiye 👍", tool, requires_confirmation: true, confirm_token: "mock-confirm-" + tool, args_summary: Object.keys(args), executed: false, widget: { type: "confirm", question: "Confirm karein?", confirm_token: "mock-confirm-" + tool } };
  }
  const msg = String(payload.message || "").toLowerCase();
  // Phase 9: "show my reminders" (any role) — must beat the vendor set-reminder rule.
  if (/(reminder|yaad|remind)/.test(msg) && /(mere|meri|my |list|dikha|show|pending|upcoming|batao|kaun|kitne)/.test(msg)) {
    return { reply: "Yeh rahe aapke reminders ⏰", tool: "list_reminders", executed: true, data: { reminders: MOCK_REMINDERS } };
  }
  // Vendor-agent (Phase 6)
  if (String(S.role).indexOf("vendor") === 0) {
    if (/(listing|onboard|register|profile ban|shuru|sign up|join)/.test(msg)) {
      return { reply: "Chaliye, kuch seekhna nahi — bas jawab dijiye 🙂", tool: "start_listing", executed: true, data: {}, widget: {
        type: "onboard", title: "Chaliye 2 minute mein aapki listing bana dete hain", submit_tool: "save_listing",
        steps: [
          { key: "name", q: "Aapka naam ya business ka naam?", kind: "text", placeholder: "e.g. Sharma Painters" },
          { key: "category_id", q: "Aap kya kaam karte ho?", kind: "select", options: MOCK_CATEGORIES },
          { key: "area", q: "Aap kis area/ilaake mein kaam karte ho?", kind: "text", placeholder: "e.g. Rajpur Road" },
          { key: "experience_years", q: "Kitne saal ka experience hai?", kind: "number", placeholder: "e.g. 5" },
          { key: "languages", q: "Aap kaunsi bhasha bolte ho?", kind: "multiselect", options: [
            { value: "hindi", label: "Hindi" }, { value: "english", label: "English" }, { value: "punjabi", label: "Punjabi" }, { value: "kumaoni", label: "Kumaoni" } ] },
        ],
      } };
    }
    if (/(status|kaisa chal|growth|kitne log|kitne customer|report)/.test(msg)) {
      return { reply: "Yeh raha aapka status 👇", tool: "my_status", executed: true, data: { status: MOCK_STATUS } };
    }
    if (/(remind|reminder|yaad)/.test(msg)) {
      const title = /photo/.test(msg) ? "Weekly work-photo upload" : "Weekly business reminder";
      return { reply: "Reminder set kar dein?", tool: "set_reminder", requires_confirmation: true, confirm_token: "mock-confirm-set_reminder", args_summary: ["title", "cadence"], executed: false, widget: { type: "confirm", question: `${title} — weekly reminder set karein?`, confirm_token: "mock-confirm-set_reminder" } };
    }
  }
  // Inline UI-action widgets
  if (String(S.role).indexOf("vendor") === 0 && /(upload|photo|foto)/.test(msg)) {
    return { reply: "Apni kaam ki photo upload kijiye 👇", tool: "upload_work_photo", executed: true, data: {}, widget: { type: "upload", title: "Apni kaam ki photo upload kijiye", endpoint: "/api/vendor/work-photo", field: "photo", accept: "image/*", caption_field: "caption", success_text: "Photo aapke profile mein add ho gayi ✓" } };
  }
  if (/(otp|verify|verification)/.test(msg)) {
    return { reply: "Chaliye aapka number verify karte hain 👇", tool: "verify_phone", executed: true, data: {}, widget: { type: "otp", title: "Apna number verify karein", send_path: "/api/auth/otp/send", verify_path: "/api/auth/otp/verify", success_text: "Number verified ✓" } };
  }
  if (/(package|packages|estimate|sqft)/.test(msg)) {
    mockMemRecord("painting");
    return { reply: "Yeh rahe painting ke packages 👇 (aap area sqft batayenge to exact cost bhi nikal denge)", tool: "get_packages", executed: true, data: { packages: MOCK_PACKAGES } };
  }
  if (/(expert|visit|company|inspection)/.test(msg)) {
    return { reply: "Company expert visit ka charge yeh hai 👇", tool: "get_expert_visit_price", executed: true, data: { is_free: false, amount: 299 }, _category: "painting" };
  }
  // Connections — gift/order for a saved person (before commerce, since "kapde" also matches).
  if (/(behen|bahan|bhai|sister|brother|friend|dost|papa|mummy|maa|wife|husband|beta|beti)/.test(msg)
      && /(order|gift|bhej|ke liye|kapde|kapda|clothes|kurta|rakhi|saree)/.test(msg)) {
    return { reply: "Kiske liye order karna hai? 👇", tool: "list_people", executed: true, data: { people: MOCK_PEOPLE } };
  }
  // Commerce
  if (/(cart|basket|tokri)/.test(msg)) {
    return { reply: "Yeh raha aapka cart 🛒", tool: "view_cart", executed: true, data: mockCartGet() };
  }
  if (/(my order|mere order|meri order|order status|order kahan|orders)/.test(msg)) {
    return { reply: "Yeh rahi aapki recent orders 📦", tool: "list_orders", executed: true, data: { orders: [{ id: 8801, status: "pending", total: 699 }] } };
  }
  if (/(kapde|kapda|clothes|kurta|kurti|saree|shirt|jeans|shoes|juta|grocery|saaman|saman|shop|kharid|khareed|buy|gift|diwali|product|shopping)/.test(msg)) {
    return { reply: "Dekhiye aapke area ke products 🛍️ (company + local stores) — kisi ko bhi \"Add to cart\" kijiye.", tool: "browse_products", executed: true, data: { products: MOCK_PRODUCTS } };
  }
  const cat = detectCat(msg);
  if (cat) {
    mockMemRecord(cat);   // remember for the next visit (demo mirror of real memory)
    return { reply: `Yeh rahe aapke area ke ${cat} experts 👇 Kisi ko bhi "Book now" karke turant book kar sakte hain.`, tool: "find_vendors", executed: true, data: { vendors: MOCK_VENDORS(cat) }, _category: cat };
  }
  return { reply: "Main aapki madad kar sakta hoon — painter, plumber, electrician dhoondhne mein, packages dikhane mein, ya booking karne mein. Bataiye kya chahiye? 🙂", tool: null, executed: false };
}
