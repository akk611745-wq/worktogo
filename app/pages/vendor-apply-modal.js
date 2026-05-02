window.VendorApplyModal = (() => {
  const MODAL_ID = "vendor-apply-modal";

  function show() {
    const user = AUTH.getUser?.() || {};
    let modal = document.getElementById(MODAL_ID);

    if (!modal) {
      modal = document.createElement("div");
      modal.id = MODAL_ID;
      document.body.appendChild(modal);
    }

    modal.className = "vendor-apply-overlay";
    modal.innerHTML = `
      <div class="vendor-apply-card" role="dialog" aria-modal="true" aria-labelledby="vendor-apply-title">
        <div class="vendor-apply-header">
          <h3 id="vendor-apply-title">Become a Vendor</h3>
          <button class="vendor-apply-close" type="button" onclick="VendorApplyModal.close()" aria-label="Close">×</button>
        </div>

        <div class="vendor-apply-form">
          <label for="va-name">Full Name</label>
          <input id="va-name" type="text" value="${_escapeAttr(user.name || "")}" autocomplete="name" />

          <label for="va-email">Email</label>
          <input id="va-email" type="email" value="${_escapeAttr(user.email || "")}" autocomplete="email" />

          <label for="va-phone">Phone</label>
          <input id="va-phone" type="tel" value="${_escapeAttr(user.phone || "")}" autocomplete="tel" />

          <label for="va-password">Password</label>
          <input id="va-password" type="password" autocomplete="new-password" />

          <label for="va-business">Business Name</label>
          <input id="va-business" type="text" autocomplete="organization" />

          <label for="va-type">Vendor Type</label>
          <select id="va-type">
            <option value="vendor_shopping">Shopping Vendor</option>
            <option value="vendor_service">Service Vendor</option>
          </select>

          <div class="vendor-apply-actions">
            <button class="vendor-apply-submit" type="button" onclick="VendorApplyModal.submit()">Submit</button>
            <button class="vendor-apply-cancel" type="button" onclick="VendorApplyModal.close()">Close</button>
          </div>
        </div>
      </div>
    `;
  }

  function close() {
    const modal = document.getElementById(MODAL_ID);
    if (modal) modal.remove();
  }

  async function submit() {
    const name = _value("va-name");
    const email = _value("va-email");
    const phone = _value("va-phone");
    const password = _value("va-password");
    const business_name = _value("va-business");
    const role = _value("va-type");

    if (!name || !email || !phone || !password || !business_name || !role) {
      UI.toast("Please fill all fields", "error");
      return;
    }

    const submitBtn = document.querySelector(".vendor-apply-submit");
    if (submitBtn) submitBtn.disabled = true;

    try {
      const res = await fetch(`${CONFIG.BASE_URL}/api/auth/register`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ name, phone, email, password, role, business_name }),
      });

      const data = await _parseJSON(res);

      if (!res.ok) {
        UI.toast(data?.message || data?.error || "Application failed. Please try again.", "error");
        return;
      }

      UI.toast("Application submitted! Admin will review shortly.", "success");
      close();
    } catch (err) {
      UI.toast("Network error. Please try again.", "error");
    } finally {
      if (submitBtn) submitBtn.disabled = false;
    }
  }

  function _value(id) {
    return document.getElementById(id)?.value.trim() || "";
  }

  async function _parseJSON(res) {
    const text = await res.text();
    try { return JSON.parse(text); } catch { return null; }
  }

  function _escapeAttr(str) {
    return String(str || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  return { show, close, submit };
})();
