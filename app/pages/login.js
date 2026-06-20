/**
 * WorkToGo — Login Page
 * Real OTP flow: POST /auth/otp/send → POST /auth/otp/verify → JWT stored.
 */

export async function render(container) {
  const serviceOnly = Boolean(CONFIG.FEATURES?.SERVICE_ONLY_MODE);
  const locality = _loginLocalityContext();
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
        <p class="app-tagline">Trusted local services for ${_esc(locality.label)}</p>
      </header>

      <div class="login-card">
        <div class="login-tabs" role="tablist" aria-label="Login options">
          <button id="tab-phone" class="login-tab active" type="button" role="tab" aria-selected="true" onclick="LoginPage.switchAuthTab('phone')">
            Mobile OTP
          </button>
          <button id="tab-email" class="login-tab ${serviceOnly && !CONFIG.FEATURES?.EMAIL_AUTH ? "feature-hidden" : ""}" type="button" role="tab" aria-selected="false" onclick="LoginPage.switchAuthTab('email')">
            Email
          </button>
        </div>

        <div id="step-phone" class="login-step active">
          <h2>Welcome back</h2>
          <p class="step-hint">Enter your mobile number to book and track local services</p>
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
          <button id="btn-google-login" class="btn-google ${CONFIG.FEATURES?.GOOGLE_LOGIN ? "" : "feature-hidden"}" onclick="LoginPage.googleLogin()">
            <span class="google-mark">G</span>
            Continue with Google
          </button>
          <p class="login-note">We'll send a 6-digit OTP. No password needed.</p>
          <div class="trust-panel">
            <span>✅ ${_esc(locality.label)} service routing</span>
            <span>🛠️ Verified local providers</span>
            <span>💬 Human support available</span>
          </div>
        </div>

        <div id="step-email" class="login-step ${serviceOnly && !CONFIG.FEATURES?.EMAIL_AUTH ? "feature-hidden" : ""}">
          <h2>Login with email</h2>
          <p class="step-hint">Email login is secondary. Mobile OTP is recommended for faster support.</p>
          <div class="auth-field">
            <label for="inp-email">Email</label>
            <input id="inp-email" type="email" placeholder="you@example.com" autocomplete="email" />
          </div>
          <div class="auth-field">
            <label for="inp-password">Password</label>
            <input id="inp-password" type="password" placeholder="Your password" autocomplete="current-password" />
          </div>
          <button id="btn-email-login" class="btn-primary" onclick="LoginPage.emailLogin()">
            <span class="btn-label">Login with Email</span>
            <svg viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
          </button>
          <button id="btn-email-register" class="btn-text" onclick="LoginPage.showRegister()">
            Create a new account
          </button>
          <div class="auth-divider ${CONFIG.FEATURES?.SERVICE_ONLY_MODE && !CONFIG.FEATURES?.EMAIL_AUTH ? "feature-hidden" : ""}"><span>or</span></div>
        </div>

        <div id="step-register" class="login-step ${serviceOnly && !CONFIG.FEATURES?.EMAIL_AUTH ? "feature-hidden" : ""}">
          <h2>Create account</h2>
          <p class="step-hint">Enter your details to register</p>
          <div class="auth-field">
            <label for="inp-reg-name">Name</label>
            <input id="inp-reg-name" type="text" placeholder="Full name" autocomplete="name" />
          </div>
          <div class="auth-field">
            <label for="inp-reg-email">Email</label>
            <input id="inp-reg-email" type="email" placeholder="your@email.com" autocomplete="email" />
          </div>
          <div class="auth-field">
            <label for="inp-reg-password">Password</label>
            <input id="inp-reg-password" type="password" placeholder="Password" autocomplete="new-password" />
          </div>
          <button id="btn-email-register-submit" class="btn-primary" onclick="LoginPage.emailRegister()">
            <span class="btn-label">Create Account</span>
            <svg viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
          </button>
        </div>

        <div id="step-otp" class="login-step">
          <button class="btn-back" onclick="LoginPage.goBack()">
            <svg viewBox="0 0 24 24"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
            Back
          </button>
          <h2>Enter OTP</h2>
          <p class="step-hint" id="otp-sent-to">Sent to your number</p>
          <p class="login-note">Enter OTP to continue. If OTP is delayed, use resend or contact support.</p>
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

      <p class="login-footer">By continuing you agree to be contacted for booking confirmation and support.</p>
      <button class="continue-browsing-btn" onclick="ROUTER.go('home')">Continue browsing services</button>
    </div>
  `;

  LoginPage._init();
}

function _esc(str) {
  return UI.escapeHtml(str);
}

function _loginLocalityContext() {
  try {
    const saved = JSON.parse(localStorage.getItem("wtg_locality_context") || "{}");
    if (saved?.label) return { label: String(saved.label).trim() };
  } catch {}
  try {
    const homeState = JSON.parse(sessionStorage.getItem("wtg_home_state") || "{}");
    if (homeState?.locality) return { label: String(homeState.locality).trim() };
    if (homeState?.city) return { label: String(homeState.city).trim() };
  } catch {}
  const city = CONFIG.SERVICE_ONLY?.CITY || "your area";
  return { label: city };
}

window.LoginPage = (() => {
  let _phone = "";
  let _resendInterval = null;
  let _msg91ScriptLoaded = false;

  function _loadMsg91Widget() {
    if (_msg91ScriptLoaded) return Promise.resolve();
    return new Promise((resolve, reject) => {
      const urls = [
        "https://verify.msg91.com/otp-provider.js",
        "https://verify.phone91.com/otp-provider.js",
      ];
      let i = 0;
      function attempt() {
        const s = document.createElement("script");
        s.src = urls[i];
        s.async = true;
        s.onload = () => { _msg91ScriptLoaded = true; resolve(); };
        s.onerror = () => { i++; if (i < urls.length) attempt(); else reject(new Error("Widget unavailable")); };
        document.head.appendChild(s);
      }
      attempt();
    });
  }

  async function _launchWidget() {
    try {
      await _loadMsg91Widget();
    } catch {
      _setLoading("btn-send-otp", false);
      UI.toast("OTP service unavailable. Please try again.", "error");
      return;
    }
    if (typeof window.initSendOTP !== "function") {
      _setLoading("btn-send-otp", false);
      UI.toast("OTP widget failed to initialize. Please try again.", "error");
      return;
    }
    window.initSendOTP({
      widgetId:     window.WTG_WIDGET_ID,
      tokenAuth:    window.WTG_WIDGET_TOKEN,
      identifier:   "+91" + _phone,
      exposeMethods: false,
      success: (data) => {
        _onWidgetSuccess(data?.message || data?.token || data);
      },
      failure: (error) => {
        UI.toast((error && (error.message || error.description)) || "OTP verification failed. Please try again.", "error");
      },
    });
    _setLoading("btn-send-otp", false);
  }

  async function _onWidgetSuccess(accessToken) {
    if (!accessToken) {
      UI.toast("OTP verification failed. Please try again.", "error");
      return;
    }
    UI.toast("Verifying…", "info");
    const result = await AUTH.verifyWidgetToken(_phone, String(accessToken));
    if (result.ok) {
      _clearPendingOtpState();
      UI.toast("Login successful!", "success");
      redirectAfterLogin(result.user);
    } else {
      UI.toast(result.error || "Login failed. Please try again.", "error");
    }
  }

  function _setLoading(btnId, loading) {
    const btn = document.getElementById(btnId);
    if (!btn) return;
    btn.disabled = loading;
    btn.classList.toggle("loading", loading);
  }

  function _init() {
    const phoneInput = document.getElementById("inp-phone");
    const savedOtp = _pendingOtpState();
    if (savedOtp.phone) _phone = savedOtp.phone;
    if (phoneInput) {
      if (_phone) phoneInput.value = _phone;
      phoneInput.addEventListener("keydown", e => { if (e.key === "Enter") sendOtp(); });
      phoneInput.addEventListener("focus", () => phoneInput.select());
    }

    const emailInput = document.getElementById("inp-email");
    const passwordInput = document.getElementById("inp-password");
    [emailInput, passwordInput].forEach(inp => {
      inp?.addEventListener("keydown", e => { if (e.key === "Enter") emailLogin(); });
    });

    const regNameInput = document.getElementById("inp-reg-name");
    const regEmailInput = document.getElementById("inp-reg-email");
    const regPasswordInput = document.getElementById("inp-reg-password");
    [regNameInput, regEmailInput, regPasswordInput].forEach(inp => {
      inp?.addEventListener("keydown", e => { if (e.key === "Enter") emailRegister(); });
    });

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

    if (savedOtp.step === "otp" && _phone && Date.now() - Number(savedOtp.ts || 0) < 10 * 60 * 1000) {
      const sentTo = document.getElementById("otp-sent-to");
      if (sentTo) sentTo.textContent = `Sent to +91 ${_phone}`;
      document.getElementById("step-phone")?.classList.remove("active");
      document.getElementById("step-otp")?.classList.add("active");
      document.querySelectorAll(".otp-digit")[0]?.focus();
      _startResendTimer(Math.max(1, Number(savedOtp.resendRemaining || 1)));
    }

    if (window.WTG_OTP_METHOD === "widget") {
      _loadMsg91Widget().catch(() => {});
    }
  }

  function _pendingOtpState() {
    try { return JSON.parse(sessionStorage.getItem("wtg_pending_otp") || "{}"); } catch { return {}; }
  }

  function _savePendingOtpState(extra = {}) {
    try { sessionStorage.setItem("wtg_pending_otp", JSON.stringify({ phone: _phone, step: "otp", ts: Date.now(), ...extra })); } catch {}
  }

  function _clearPendingOtpState() {
    try { sessionStorage.removeItem("wtg_pending_otp"); } catch {}
  }

  function switchAuthTab(tab) {
    if (tab === "email" && CONFIG.FEATURES?.SERVICE_ONLY_MODE && !CONFIG.FEATURES?.EMAIL_AUTH) {
      UI.toast("Use Mobile OTP for service booking support.", "info");
      return;
    }
    const showEmail = tab === "email";

    document.getElementById("tab-phone")?.classList.toggle("active", !showEmail);
    document.getElementById("tab-email")?.classList.toggle("active", showEmail);
    document.getElementById("tab-phone")?.setAttribute("aria-selected", String(!showEmail));
    document.getElementById("tab-email")?.setAttribute("aria-selected", String(showEmail));

    document.getElementById("step-otp")?.classList.remove("active");
    document.getElementById("step-register")?.classList.remove("active");
    document.getElementById("step-phone")?.classList.toggle("active", !showEmail);
    document.getElementById("step-email")?.classList.toggle("active", showEmail);

    if (_resendInterval) { clearInterval(_resendInterval); _resendInterval = null; }
    document.getElementById(showEmail ? "inp-email" : "inp-phone")?.focus();
  }

  function showRegister() {
    if (CONFIG.FEATURES?.SERVICE_ONLY_MODE && !CONFIG.FEATURES?.EMAIL_AUTH) {
      UI.toast("Use Mobile OTP for service booking support.", "info");
      return;
    }
    document.getElementById("step-email")?.classList.remove("active");
    document.getElementById("step-register")?.classList.add("active");
    document.getElementById("inp-reg-name")?.focus();
  }

  function _getEmailCredentials() {
    const email = document.getElementById("inp-email")?.value?.trim() || "";
    const password = document.getElementById("inp-password")?.value || "";

    if (!email || !/^\S+@\S+\.\S+$/.test(email)) {
      UI.toast("Enter a valid email address", "error");
      return null;
    }
    if (!password || password.length < 6) {
      UI.toast("Password must be at least 6 characters", "error");
      return null;
    }
    return { email, password };
  }

  function _getRegisterCredentials() {
    const name = document.getElementById("inp-reg-name")?.value?.trim() || "";
    const email = document.getElementById("inp-reg-email")?.value?.trim() || "";
    const password = document.getElementById("inp-reg-password")?.value || "";

    if (!name) {
      UI.toast("Enter your full name", "error");
      return null;
    }
    if (!email || !/^\S+@\S+\.\S+$/.test(email)) {
      UI.toast("Enter a valid email address", "error");
      return null;
    }
    if (!password || password.length < 6) {
      UI.toast("Password must be at least 6 characters", "error");
      return null;
    }
    return { name, email, password };
  }

  function redirectAfterLogin(user) {
    if (AUTH.resolvePostLogin) AUTH.resolvePostLogin();
    else ROUTER.go('home');
  }

  async function emailLogin() {
    const credentials = _getEmailCredentials();
    if (!credentials) return;

    _setLoading("btn-email-login", true);
    const result = await AUTH.emailLogin(credentials.email, credentials.password);
    _setLoading("btn-email-login", false);

    if (result.ok) {
      UI.toast("Login successful!", "success");
      redirectAfterLogin(result.user);
    } else {
      UI.toast(result.error || "Email login failed. Try again.", "error");
    }
  }

  async function emailRegister() {
    const credentials = _getRegisterCredentials();
    if (!credentials) return;

    _setLoading("btn-email-register-submit", true);
    const result = await AUTH.emailRegister(credentials);
    _setLoading("btn-email-register-submit", false);

    if (result.ok) {
      UI.toast("Account created!", "success");
      redirectAfterLogin(result.user);
    } else {
      UI.toast(result.error || "Registration failed. Try again.", "error");
    }
  }

  function _handleGoogleCredential(response) {
    const credential = response?.credential;
    if (!credential) {
      UI.toast("Google login was cancelled", "error");
      return;
    }
    _completeGoogleLogin(credential);
  }

  async function _completeGoogleLogin(credential) {
    _setLoading("btn-google-login", true);
    const result = await AUTH.googleLogin(credential);
    _setLoading("btn-google-login", false);

    if (result.ok) {
      UI.toast("Login successful!", "success");
      redirectAfterLogin(result.user);
    } else {
      UI.toast(result.error || "Google login failed. Try again.", "error");
    }
  }

  function googleLogin() {
    const googleClientId = window.WTG_GOOGLE_CLIENT_ID || CONFIG.GOOGLE_CLIENT_ID || "";

    if (!googleClientId) {
      UI.toast("Google Sign-In is not configured", "error");
      return;
    }

    _launchGSI(googleClientId, 0);
  }

  function _launchGSI(clientId, elapsed) {
    if (window.google?.accounts?.id) {
      window.google.accounts.id.initialize({
        client_id: clientId,
        callback: _handleGoogleCredential,
      });
      window.google.accounts.id.prompt();
      return;
    }
    if (elapsed >= 3000) {
      UI.toast("Google Sign-In is not configured", "error");
      return;
    }
    setTimeout(() => _launchGSI(clientId, elapsed + 200), 200);
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

  // ── Send OTP ───────────────────────────────────────────────────────────

  async function sendOtp() {
    const inp = document.getElementById("inp-phone");
    _phone = inp?.value?.trim().replace(/\D/g, "");

    if (!_phone || _phone.length !== 10 || !/^[6-9]/.test(_phone)) {
      UI.toast("Enter a valid 10-digit mobile number", "error");
      return;
    }

    _setLoading("btn-send-otp", true);

    if (window.WTG_OTP_METHOD === "widget") {
      await _launchWidget();
      return;
    }

    // SMS path (OTP_METHOD=sms or default)
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

    _savePendingOtpState({ resendRemaining: 30 });
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
      _clearPendingOtpState();
      UI.toast("Login successful!", "success");
      redirectAfterLogin(result.user);
    } else {
      UI.toast(result.error || "Invalid OTP. Try again.", "error");
      document.querySelectorAll(".otp-digit").forEach(i => { i.value = ""; });
      document.querySelectorAll(".otp-digit")[0]?.focus();
    }
  }

  // ── Resend OTP ────────────────────────────────────────────────────────

  async function resendOtp() {
    if (!_phone) {
      UI.toast("Please go back and enter your number again.", "error");
      return;
    }

    if (window.WTG_OTP_METHOD === "widget") {
      await _launchWidget();
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
    _savePendingOtpState({ resendRemaining: 30 });
    _startResendTimer(30);
  }

  function goBack() {
    document.getElementById("step-otp")?.classList.remove("active");
    document.getElementById("step-register")?.classList.remove("active");
    document.getElementById("step-phone")?.classList.add("active");
    document.getElementById("step-email")?.classList.remove("active");
    document.getElementById("tab-phone")?.classList.add("active");
    document.getElementById("tab-email")?.classList.remove("active");
    document.getElementById("tab-phone")?.setAttribute("aria-selected", "true");
    document.getElementById("tab-email")?.setAttribute("aria-selected", "false");
    if (_resendInterval) { clearInterval(_resendInterval); _resendInterval = null; }
    _clearPendingOtpState();
  }

  return { sendOtp, verifyOtp, resendOtp, goBack, switchAuthTab, showRegister, emailLogin, emailRegister, googleLogin, _init };
})();
