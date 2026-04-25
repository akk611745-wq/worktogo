/**
 * WorkToGo — Login Page
 * Real OTP flow: POST /auth/otp/send → POST /auth/otp/verify → JWT stored.
 */

export async function render(container) {
  container.innerHTML = `
    <div class="login-page">
      <div class="login-glow"></div>

      <header class="login-header">
        <div class="logo-mark">
          <svg viewBox="0 0 40 40" fill="none">
            <rect width="40" height="40" rx="12" fill="url(#lg)"/>
            <path d="M8 28L16 14l8 10 5-6 3 4" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
            <defs><linearGradient id="lg" x1="0" y1="0" x2="40" y2="40"><stop stop-color="#6C63FF"/><stop offset="1" stop-color="#A855F7"/></linearGradient></defs>
          </svg>
        </div>
        <h1 class="app-title">WorkToGo</h1>
        <p class="app-tagline">Your digital marketplace</p>
      </header>

      <div class="login-card">
        <div id="step-phone" class="login-step active">
          <h2>Welcome back</h2>
          <p class="step-hint">Enter your mobile number to continue</p>
          <div class="input-group">
            <span class="input-prefix">+91</span>
            <input
              id="inp-phone"
              type="tel"
              maxlength="10"
              placeholder="9876543210"
              inputmode="numeric"
              autocomplete="tel"
            />
          </div>
          <button id="btn-send-otp" class="btn-primary" onclick="LoginPage.sendOtp()">
            <span class="btn-label">Send OTP</span>
            <svg viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
          </button>
          <p class="login-note">We'll send a 6-digit OTP to your number</p>
        </div>

        <div id="step-otp" class="login-step">
          <button class="btn-back" onclick="LoginPage.goBack()">
            <svg viewBox="0 0 24 24"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
            Back
          </button>
          <h2>Enter OTP</h2>
          <p class="step-hint" id="otp-sent-to">Sent to your number</p>
          <div class="otp-inputs">
            <input class="otp-digit" type="tel" maxlength="1" inputmode="numeric"/>
            <input class="otp-digit" type="tel" maxlength="1" inputmode="numeric"/>
            <input class="otp-digit" type="tel" maxlength="1" inputmode="numeric"/>
            <input class="otp-digit" type="tel" maxlength="1" inputmode="numeric"/>
            <input class="otp-digit" type="tel" maxlength="1" inputmode="numeric"/>
            <input class="otp-digit" type="tel" maxlength="1" inputmode="numeric"/>
          </div>
          <button id="btn-verify" class="btn-primary" onclick="LoginPage.verifyOtp()">
            <span class="btn-label">Verify &amp; Login</span>
            <svg viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg>
          </button>
          <button class="btn-resend" id="btn-resend" onclick="LoginPage.resendOtp()" disabled>
            Resend OTP <span id="resend-timer">(30s)</span>
          </button>
        </div>
      </div>

      <p class="login-footer">By continuing you agree to our Terms of Service</p>
    </div>
  `;

  LoginPage._init();
}

window.LoginPage = (() => {
  let _phone = "";
  let _resendInterval = null;

  function _setLoading(btnId, loading) {
    const btn = document.getElementById(btnId);
    if (!btn) return;
    btn.disabled = loading;
    btn.classList.toggle("loading", loading);
  }

  function _init() {
    const phoneInput = document.getElementById("inp-phone");
    if (phoneInput) {
      phoneInput.addEventListener("keydown", e => { if (e.key === "Enter") sendOtp(); });
      phoneInput.addEventListener("focus", () => phoneInput.select());
    }

    const otpInputs = [...document.querySelectorAll(".otp-digit")];

    otpInputs.forEach((inp, i) => {
      inp.addEventListener("input", () => {
        inp.value = inp.value.replace(/\D/g, "").slice(-1);
        if (inp.value && i < otpInputs.length - 1) otpInputs[i + 1].focus();
        if (otpInputs.every(f => f.value)) verifyOtp();
      });

      inp.addEventListener("keydown", e => {
        if (e.key === "Backspace" && !inp.value && i > 0) otpInputs[i - 1].focus();
        if (e.key === "Enter") verifyOtp();
      });

      inp.addEventListener("paste", e => {
        e.preventDefault();
        const pasted = (e.clipboardData || window.clipboardData)
          .getData("text")
          .replace(/\D/g, "")
          .slice(0, 6);
        if (!pasted) return;
        otpInputs.forEach((field, idx) => { field.value = pasted[idx] || ""; });
        const firstEmpty = otpInputs.findIndex(f => !f.value);
        otpInputs[firstEmpty >= 0 ? firstEmpty : otpInputs.length - 1].focus();
        if (pasted.length === 6) verifyOtp();
      });
    });
  }

  function _startResendTimer(seconds = 30) {
    const btn   = document.getElementById("btn-resend");
    const timer = document.getElementById("resend-timer");
    if (!btn || !timer) return;
    if (_resendInterval) clearInterval(_resendInterval);
    let s = seconds;
    btn.disabled = true;
    timer.textContent = `(${s}s)`;
    _resendInterval = setInterval(() => {
      s--;
      if (s > 0) {
        timer.textContent = `(${s}s)`;
      } else {
        clearInterval(_resendInterval);
        _resendInterval = null;
        timer.textContent = "";
        btn.disabled = false;
      }
    }, 1000);
  }

  // ── Send OTP — calls real API ──────────────────────────────────────────

  async function sendOtp() {
    const inp = document.getElementById("inp-phone");
    _phone = inp?.value?.trim().replace(/\D/g, "");

    if (!_phone || _phone.length !== 10) {
      UI.toast("Enter a valid 10-digit mobile number", "error");
      return;
    }

    _setLoading("btn-send-otp", true);

    const result = await AUTH.sendOtp(_phone);

    _setLoading("btn-send-otp", false);

    if (!result.ok) {
      UI.toast(result.error || "Failed to send OTP. Try again.", "error");
      return;
    }

    const sentTo = document.getElementById("otp-sent-to");
    if (sentTo) sentTo.textContent = `Sent to +91 ${_phone}`;

    document.getElementById("step-phone")?.classList.remove("active");
    document.getElementById("step-otp")?.classList.add("active");
    document.querySelectorAll(".otp-digit")[0]?.focus();

    _startResendTimer(30);
    UI.toast("OTP sent!", "success");
  }

  // ── Verify OTP — calls real API ───────────────────────────────────────

  async function verifyOtp() {
    const digits = [...document.querySelectorAll(".otp-digit")]
      .map(i => i.value)
      .join("");

    if (digits.length < 6) {
      UI.toast("Enter the complete 6-digit OTP", "error");
      return;
    }

    _setLoading("btn-verify", true);
    const result = await AUTH.verifyAndLogin(_phone, digits);
    _setLoading("btn-verify", false);

    if (result.ok) {
      UI.toast("Login successful!", "success");
      ROUTER.go("home");
    } else {
      UI.toast(result.error || "Invalid OTP. Try again.", "error");
      document.querySelectorAll(".otp-digit").forEach(i => { i.value = ""; });
      document.querySelectorAll(".otp-digit")[0]?.focus();
    }
  }

  // ── Resend OTP — calls real API ───────────────────────────────────────

  async function resendOtp() {
    if (!_phone) {
      UI.toast("Please go back and enter your number again.", "error");
      return;
    }

    const btn = document.getElementById("btn-resend");
    if (btn) btn.disabled = true;

    const result = await AUTH.sendOtp(_phone);

    if (!result.ok) {
      UI.toast(result.error || "Failed to resend OTP. Try again.", "error");
      if (btn) btn.disabled = false;
      return;
    }

    UI.toast("OTP resent!", "success");
    document.querySelectorAll(".otp-digit").forEach(i => { i.value = ""; });
    document.querySelectorAll(".otp-digit")[0]?.focus();
    _startResendTimer(30);
  }

  function goBack() {
    document.getElementById("step-otp")?.classList.remove("active");
    document.getElementById("step-phone")?.classList.add("active");
    if (_resendInterval) { clearInterval(_resendInterval); _resendInterval = null; }
  }

  return { sendOtp, verifyOtp, resendOtp, goBack, _init };
})();
