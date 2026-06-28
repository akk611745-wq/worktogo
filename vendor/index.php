<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no"/>
<title>Login — WorkToGo Vendor</title>
  <link rel="icon" href="/app/assets/favicon.png"/>
<link rel="stylesheet" href="assets/style.css"/>
<script>
  window.WTG_BASE_URL = "<?php echo rtrim($_ENV['APP_URL'] ?? getenv('APP_URL') ?? 'https://worktogo.in', '/'); ?>";
</script>
<script defer src="config.js"></script>
<script defer src="shared/auth.js"></script>
<script defer src="shared/api.js"></script>
</head>
<body class="login-page">

<div class="login-card">
  <!-- Logo -->
  <div class="login-logo">
    <img src="/app/assets/icon-192.png" style="width:32px;height:32px;border-radius:8px" alt="WorkToGo"/>
    <div>
      <div class="logo-text">WorkToGo</div>
      <div class="logo-sub">Vendor Portal</div>
    </div>
  </div>

  <!-- Auth Tabs -->
  <div class="auth-tabs" style="margin-bottom:1.25rem;">
    <button type="button" id="tabSignIn" class="auth-tab active" onclick="showLoginForm();return false;">
      Sign In
    </button>
    <button type="button" id="tabRegister" class="auth-tab" onclick="showRegisterForm();return false;">
      Register
    </button>
  </div>

  <!-- Login Form -->
  <div id="loginSection">
    <h1 class="login-title">Welcome back</h1>
    <p class="login-sub">Sign in to manage your vendor account.</p>

    <!-- Info banner (e.g. redirected here from registration) -->
    <div id="loginInfo" style="display:none;background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;padding:0.65rem 0.9rem;border-radius:0.5rem;font-size:0.8rem;line-height:1.5;margin-bottom:1rem;"></div>

    <!-- Error message -->
    <div class="login-error" id="loginError"></div>

    <div class="form-group">
      <label class="form-label" for="email">Email or Phone Number</label>
      <input class="form-input" type="text" id="email" placeholder="you@example.com or 9876543210" autocomplete="username" required/>
    </div>

    <div class="form-group">
      <label class="form-label" for="password">Password</label>
      <input class="form-input" type="password" id="password" placeholder="••••••••" autocomplete="current-password" required/>
    </div>

    <button class="btn-login" id="loginBtn" onclick="handleLogin()">
      Sign In
    </button>

    <p style="margin-top:0.85rem;font-size:0.78rem;text-align:center;">
      <a href="#" onclick="showOtpLogin();return false;" style="color:#6b7280;font-weight:600;text-decoration:none;">Login with OTP</a>
      &nbsp;·&nbsp;
      <a href="#" onclick="showForgotPassword();return false;" style="color:#6b7280;font-weight:600;text-decoration:none;">Forgot Password?</a>
    </p>

    <p style="margin-top:1rem;font-size:0.75rem;color:#374151;text-align:center;">
      Having trouble? Contact your administrator.
    </p>
  </div>

  <!-- OTP Login -->
  <div id="otpLoginSection" style="display:none;">
    <h1 class="login-title">Login with OTP</h1>
    <p class="login-sub">We'll text a 6-digit code to your phone.</p>
    <div class="login-error" id="otpLoginError"></div>

    <div class="form-group">
      <label class="form-label" for="otpPhone">Phone Number</label>
      <input class="form-input" type="tel" id="otpPhone" placeholder="9876543210" autocomplete="tel"/>
    </div>
    <div class="form-group" id="otpCodeGroup" style="display:none;">
      <label class="form-label" for="otpCode">6-digit OTP</label>
      <input class="form-input" type="text" id="otpCode" placeholder="••••••" maxlength="6" autocomplete="one-time-code"/>
    </div>

    <button class="btn-login" id="otpSendBtn" onclick="sendOtpLogin()">Send OTP</button>
    <button class="btn-login" id="otpVerifyBtn" onclick="verifyOtpLogin()" style="display:none;margin-top:0.6rem;">Verify &amp; Login</button>

    <p style="margin-top:1rem;font-size:0.8rem;text-align:center;">
      <a href="#" onclick="showLoginForm();return false;" style="color:#4f46e5;font-weight:600;text-decoration:none;">Back to Sign In</a>
    </p>
  </div>

  <!-- Forgot Password -->
  <div id="forgotPasswordSection" style="display:none;">
    <h1 class="login-title">Reset Password</h1>
    <p class="login-sub">Verify your phone, then set a new password.</p>
    <div class="login-error" id="forgotError"></div>

    <div class="form-group">
      <label class="form-label" for="fpPhone">Phone Number</label>
      <input class="form-input" type="tel" id="fpPhone" placeholder="9876543210" autocomplete="tel"/>
    </div>
    <div id="fpStep2" style="display:none;">
      <div class="form-group">
        <label class="form-label" for="fpCode">6-digit OTP</label>
        <input class="form-input" type="text" id="fpCode" placeholder="••••••" maxlength="6" autocomplete="one-time-code"/>
      </div>
      <div class="form-group">
        <label class="form-label" for="fpNewPassword">New Password</label>
        <input class="form-input" type="password" id="fpNewPassword" placeholder="Min 8 characters" autocomplete="new-password"/>
      </div>
    </div>

    <button class="btn-login" id="fpSendBtn" onclick="sendForgotPasswordOtp()">Send OTP</button>
    <button class="btn-login" id="fpResetBtn" onclick="resetPasswordWithOtp()" style="display:none;margin-top:0.6rem;">Reset Password</button>

    <p style="margin-top:1rem;font-size:0.8rem;text-align:center;">
      <a href="#" onclick="showLoginForm();return false;" style="color:#4f46e5;font-weight:600;text-decoration:none;">Back to Sign In</a>
    </p>
  </div>

  <!-- Registration Form -->
  <div id="registerSection" style="display:none;">
    <h2 style="font-size:1.1rem;font-weight:700;color:#111827;margin:0 0 0.25rem;">Create Vendor Account</h2>
    <p style="font-size:0.8rem;color:#6b7280;margin:0 0 1rem;">Fill in your details to apply as a vendor.</p>
    <div class="login-error" id="registerError"></div>
    <div class="form-group" id="regNameGroup">
      <label class="form-label" for="reg-name">Full Name</label>
      <input class="form-input" type="text" id="reg-name" placeholder="Your full name" autocomplete="name"/>
    </div>
    <div class="form-group" id="regEmailGroup">
      <label class="form-label" for="reg-email">Email address</label>
      <input class="form-input" type="email" id="reg-email" placeholder="you@example.com" autocomplete="email"/>
    </div>
    <div class="form-group" id="regPasswordGroup">
      <label class="form-label" for="reg-password">Password</label>
      <input class="form-input" type="password" id="reg-password" placeholder="Min 8 characters" autocomplete="new-password"/>
    </div>
    <div class="form-group">
      <label class="form-label" for="reg-business">Business Name</label>
      <input class="form-input" type="text" id="reg-business" placeholder="Your business name" autocomplete="organization"/>
    </div>
    <div class="form-group" id="regCategoryGroup">
      <label class="form-label" for="reg-category">Category</label>
      <select class="form-input" id="reg-category">
        <option value="">Select category</option>
        <option value="electrician">Electrician</option>
        <option value="plumber">Plumber</option>
        <option value="carpenter">Carpenter</option>
        <option value="ac_repair">AC Repair</option>
        <option value="cleaning">Cleaning</option>
        <option value="painting">Painting</option>
        <option value="other">Other</option>
      </select>
    </div>
    <div class="form-group" id="regPhoneGroup">
      <label class="form-label" for="reg-phone">Phone Number</label>
      <input class="form-input" type="tel" id="reg-phone" placeholder="+91XXXXXXXXXX" autocomplete="tel"/>
    </div>
    <button class="btn-login" id="registerBtn" onclick="handleRegister()">Register</button>
    <p style="margin-top:0.75rem;font-size:0.8rem;text-align:center;">
      Already registered? <a href="#" onclick="showLoginForm();return false;" style="color:#4f46e5;font-weight:600;text-decoration:none;">Sign In</a>
    </p>
  </div>
</div>

<script>
// Set when registration finds an existing account (409) and redirects to
// Sign In instead of failing outright — picked up by handleLogin() so the
// vendor application that prompted this redirect actually gets submitted
// once the user proves account ownership via a real login.
let _pendingVendorApplication = null;

// Redirect if already logged in
window.addEventListener('DOMContentLoaded', function() {
  if (Auth.isLoggedIn()) window.location.href = "dashboard.php";

  const params = new URLSearchParams(window.location.search);
  if (params.get('apply') === '1') {
    showRegisterForm();
    _applyCustomerPrefill();
    if (_customerToken()) {
      // Customer is already logged into the main app — there's no need to
      // create a separate vendor-portal account just to apply. Submit the
      // application directly against their existing session (POST
      // /api/vendors accepts a customer-role JWT), so email/password are
      // irrelevant here — only business name + category are actually new.
      document.getElementById('regEmailGroup').style.display = 'none';
      document.getElementById('regPasswordGroup').style.display = 'none';
    }
  }
});

// The customer app stores its JWT under localStorage key "wtg_token" (see
// app/js/config.js TOKEN_KEY) — a different key than this portal's own
// "wtg_vendor_token" (vendor/config.js), so there's no collision reading it
// directly here.
function _customerToken() {
  try { return localStorage.getItem('wtg_token') || ''; } catch (_) { return ''; }
}

// Pulls whatever the customer app already has saved (name/phone/email) so a
// customer applying to become a vendor doesn't have to retype it — only
// business name + category are new info we actually need from them.
function _customerPrefillData() {
  const data = {};
  try {
    const profile = JSON.parse(localStorage.getItem('wtg_customer_profile') || '{}');
    if (profile.name) data.name = profile.name;
  } catch (_) {}
  try {
    const user = JSON.parse(localStorage.getItem('wtg_user') || '{}');
    if (user.name && !data.name) data.name = user.name;
    if (user.email) data.email = user.email;
    if (user.phone) data.phone = user.phone;
  } catch (_) {}
  return data;
}

function _applyCustomerPrefill() {
  const data = _customerPrefillData();
  if (data.name) document.getElementById('reg-name').value = data.name;
  if (data.email) document.getElementById('reg-email').value = data.email;
  if (data.phone) document.getElementById('reg-phone').value = data.phone;

  if (data.name) document.getElementById('regNameGroup').style.display = 'none';
  if (data.email) document.getElementById('regEmailGroup').style.display = 'none';
  if (data.phone) document.getElementById('regPhoneGroup').style.display = 'none';
}

// Allow Enter key to submit (context-aware: login vs register)
document.addEventListener("keydown", e => {
  if (e.key !== "Enter") return;
  const reg = document.getElementById("registerSection");
  if (reg && reg.style.display !== "none") handleRegister();
  else handleLogin();
});

async function handleLogin() {
  const email    = document.getElementById("email").value.trim();
  const password = document.getElementById("password").value;
  const btn      = document.getElementById("loginBtn");
  const errEl    = document.getElementById("loginError");

  errEl.classList.remove("show");

  if (!email || !password) {
    showErr("Please enter your email and password.");
    return;
  }

  btn.disabled = true;
  btn.textContent = "Signing in…";

  const result = await API.Auth.login(email, password);

  btn.disabled = false;
  btn.textContent = "Sign In";

  if (!result.ok) {
    const msg = result.data?.message || result.error || "Login failed. Please check your credentials.";
    showErr(msg);
    return;
  }

  const { token, vendor, user } = result.data;
  const userData = vendor || user || result.data;

  if (!token) {
    showErr("Login response missing token. Please contact support.");
    return;
  }

  Auth.setSession(token, userData);

  // A registration attempt redirected here because this account already
  // existed — now that the real login succeeded, submit the vendor
  // application that prompted the redirect.
  if (_pendingVendorApplication) {
    const pending = _pendingVendorApplication;
    _pendingVendorApplication = null;
    try {
      const vendorRes = await fetch(window.WTG_BASE_URL + "/api/vendors", {
        method:  "POST",
        headers: { "Content-Type": "application/json", "Authorization": "Bearer " + token },
        body:    JSON.stringify({ business_name: pending.businessName, category: pending.category, type: "service" }),
      });
      const vendorData = await vendorRes.json().catch(() => ({}));
      if (!vendorRes.ok && vendorData?.error?.code !== 'VENDOR_ALREADY_EXISTS') {
        showErr(vendorData?.message || vendorData?.error || "Logged in, but the vendor application could not be linked. Please try registering again.");
        return;
      }
    } catch (_) {
      showErr("Logged in, but a network error stopped the vendor application from linking. Please try registering again.");
      return;
    }
  }

  // Role-based redirect
  const role = userData?.role || "";
  if (role === CONFIG.ROLES.SERVICE) {
    window.location.href = "bookings.php";
  } else {
    window.location.href = "dashboard.php";
  }
}

function showErr(msg) {
  const el = document.getElementById("loginError");
  el.textContent = msg;
  el.classList.add("show");
}

function _hideAllAuthSections() {
  document.getElementById("loginSection").style.display = "none";
  document.getElementById("registerSection").style.display = "none";
  document.getElementById("otpLoginSection").style.display = "none";
  document.getElementById("forgotPasswordSection").style.display = "none";
}

function showRegisterForm() {
  _hideAllAuthSections();
  document.getElementById("registerSection").style.display = "block";
  document.getElementById("registerError").classList.remove("show");
  setActiveTab("register");
}

function showLoginForm(prefill) {
  _hideAllAuthSections();
  document.getElementById("loginSection").style.display = "block";
  document.getElementById("loginError").classList.remove("show");
  setActiveTab("signin");

  const infoEl = document.getElementById("loginInfo");
  if (prefill && prefill.email) {
    document.getElementById("email").value = prefill.email;
  }
  if (prefill && prefill.message) {
    infoEl.textContent = prefill.message;
    infoEl.style.display = "block";
    document.getElementById("password")?.focus();
  } else {
    infoEl.style.display = "none";
  }
}

function showOtpLogin() {
  _hideAllAuthSections();
  document.getElementById("otpLoginSection").style.display = "block";
  setActiveTab("signin");
}

function showForgotPassword() {
  _hideAllAuthSections();
  document.getElementById("forgotPasswordSection").style.display = "block";
  setActiveTab("signin");
}

function _showOtpErr(elId, msg) {
  const el = document.getElementById(elId);
  el.textContent = msg;
  el.classList.add("show");
}

async function sendOtpLogin() {
  const phone = (document.getElementById("otpPhone")?.value || "").trim();
  const btn = document.getElementById("otpSendBtn");
  const errEl = document.getElementById("otpLoginError");
  errEl.classList.remove("show");

  if (!/^[6-9]\d{9}$/.test(phone.replace(/\D/g, "").slice(-10))) {
    _showOtpErr("otpLoginError", "Please enter a valid 10-digit mobile number.");
    return;
  }

  btn.disabled = true; btn.textContent = "Sending…";
  try {
    const res = await fetch(window.WTG_BASE_URL + "/api/auth/otp/send", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ phone }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      _showOtpErr("otpLoginError", data?.message || data?.error || "Could not send OTP. Please try again.");
      btn.disabled = false; btn.textContent = "Send OTP";
      return;
    }
    document.getElementById("otpCodeGroup").style.display = "block";
    document.getElementById("otpVerifyBtn").style.display = "block";
    btn.textContent = "Resend OTP";
    btn.disabled = false;
  } catch (_) {
    _showOtpErr("otpLoginError", "Network error. Please try again.");
    btn.disabled = false; btn.textContent = "Send OTP";
  }
}

async function verifyOtpLogin() {
  const phone = (document.getElementById("otpPhone")?.value || "").trim();
  const otp = (document.getElementById("otpCode")?.value || "").trim();
  const btn = document.getElementById("otpVerifyBtn");
  const errEl = document.getElementById("otpLoginError");
  errEl.classList.remove("show");

  if (otp.length !== 6) {
    _showOtpErr("otpLoginError", "Please enter the 6-digit OTP.");
    return;
  }

  btn.disabled = true; btn.textContent = "Verifying…";
  try {
    const res = await fetch(window.WTG_BASE_URL + "/api/auth/otp/verify", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ phone, otp }),
    });
    const data = await res.json().catch(() => ({}));
    btn.disabled = false; btn.textContent = "Verify & Login";
    if (!res.ok) {
      _showOtpErr("otpLoginError", data?.message || data?.error || "Incorrect or expired OTP.");
      return;
    }
    const payload = data?.data || data;
    const token = payload?.token;
    const userData = payload?.user || payload;
    if (!token) {
      _showOtpErr("otpLoginError", "Login response missing token. Please contact support.");
      return;
    }
    const role = userData?.role || "";
    if (role !== "vendor_service" && role !== "vendor_shopping") {
      // OTP login always succeeds for any phone with an account (customer,
      // vendor, or none yet) — but the vendor panel itself is only usable
      // by a vendor account, so a non-vendor (e.g. an existing Google/OTP
      // customer who hasn't applied yet) is told what to do next instead
      // of being dropped onto a dashboard their role can't access.
      _showOtpErr("otpLoginError", "This phone is registered, but not as a vendor yet. Use the Register tab to apply, or ask an admin to make you a vendor.");
      return;
    }
    Auth.setSession(token, userData);
    window.location.href = role === CONFIG.ROLES.SERVICE ? "bookings.php" : "dashboard.php";
  } catch (_) {
    btn.disabled = false; btn.textContent = "Verify & Login";
    _showOtpErr("otpLoginError", "Network error. Please try again.");
  }
}

async function sendForgotPasswordOtp() {
  const phone = (document.getElementById("fpPhone")?.value || "").trim();
  const btn = document.getElementById("fpSendBtn");
  const errEl = document.getElementById("forgotError");
  errEl.classList.remove("show");

  if (!/^[6-9]\d{9}$/.test(phone.replace(/\D/g, "").slice(-10))) {
    _showOtpErr("forgotError", "Please enter a valid 10-digit mobile number.");
    return;
  }

  btn.disabled = true; btn.textContent = "Sending…";
  try {
    const res = await fetch(window.WTG_BASE_URL + "/api/auth/otp/send", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ phone }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      _showOtpErr("forgotError", data?.message || data?.error || "Could not send OTP. Please try again.");
      btn.disabled = false; btn.textContent = "Send OTP";
      return;
    }
    document.getElementById("fpStep2").style.display = "block";
    document.getElementById("fpResetBtn").style.display = "block";
    btn.textContent = "Resend OTP";
    btn.disabled = false;
  } catch (_) {
    _showOtpErr("forgotError", "Network error. Please try again.");
    btn.disabled = false; btn.textContent = "Send OTP";
  }
}

async function resetPasswordWithOtp() {
  const phone = (document.getElementById("fpPhone")?.value || "").trim();
  const otp = (document.getElementById("fpCode")?.value || "").trim();
  const newPassword = document.getElementById("fpNewPassword")?.value || "";
  const btn = document.getElementById("fpResetBtn");
  const errEl = document.getElementById("forgotError");
  errEl.classList.remove("show");

  if (otp.length !== 6) {
    _showOtpErr("forgotError", "Please enter the 6-digit OTP.");
    return;
  }
  if (newPassword.length < 8) {
    _showOtpErr("forgotError", "New password must be at least 8 characters.");
    return;
  }

  btn.disabled = true; btn.textContent = "Resetting…";
  try {
    const res = await fetch(window.WTG_BASE_URL + "/api/auth/otp/verify", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ phone, otp, new_password: newPassword }),
    });
    const data = await res.json().catch(() => ({}));
    btn.disabled = false; btn.textContent = "Reset Password";
    if (!res.ok) {
      _showOtpErr("forgotError", data?.message || data?.error || "Incorrect or expired OTP.");
      return;
    }
    showLoginForm({ message: "Password reset. Please sign in with your new password." });
  } catch (_) {
    btn.disabled = false; btn.textContent = "Reset Password";
    _showOtpErr("forgotError", "Network error. Please try again.");
  }
}

function setActiveTab(which) {
  const tabSignIn   = document.getElementById("tabSignIn");
  const tabRegister = document.getElementById("tabRegister");
  const signInActive = which === "signin";
  tabSignIn.classList.toggle("active", signInActive);
  tabRegister.classList.toggle("active", !signInActive);
}

async function handleRegister() {
  const businessName = (document.getElementById("reg-business")?.value  || "").trim();
  const category      = (document.getElementById("reg-category")?.value || "").trim();
  const btn          =  document.getElementById("registerBtn");
  const errEl        =  document.getElementById("registerError");
  const customerToken = _customerToken();
  const isApplyFlow  = new URLSearchParams(window.location.search).get('apply') === '1';

  errEl.classList.remove("show");

  // A customer arriving via "Become a Vendor" already has an account and a
  // valid session — submit the application straight to POST /api/vendors
  // with that token instead of running them through a second account
  // creation. No name/email/password needed; only the two new fields.
  if (isApplyFlow && customerToken) {
    if (!businessName || !category) {
      showRegErr("Please fill in business name and category.");
      return;
    }
    btn.disabled = true;
    btn.textContent = "Submitting…";
    try {
      const vendorRes  = await fetch(window.WTG_BASE_URL + "/api/vendors", {
        method:  "POST",
        headers: { "Content-Type": "application/json", "Authorization": "Bearer " + customerToken },
        body:    JSON.stringify({ business_name: businessName, category, type: "service" }),
      });
      const vendorData = await vendorRes.json().catch(() => ({}));
      btn.disabled = false;
      btn.textContent = "Register";
      if (!vendorRes.ok) {
        if (vendorRes.status === 409) {
          showRegErr("Aapki application already submitted hai aur review mein hai.");
        } else {
          showRegErr(vendorData?.message || vendorData?.error || "Application failed. Please try again.");
        }
        return;
      }
    } catch (_) {
      btn.disabled = false;
      btn.textContent = "Register";
      showRegErr("Network error. Please try again.");
      return;
    }

    document.getElementById("registerSection").innerHTML =
      "<p style='text-align:center;color:#166534;background:#dcfce7;border:1px solid #bbf7d0;" +
      "padding:1rem 1.25rem;border-radius:0.5rem;font-size:0.875rem;line-height:1.6;'>" +
      "Application submitted! Aapki vendor application review mein hai." +
      "</p>";
    return;
  }

  const name         = (document.getElementById("reg-name")?.value     || "").trim();
  const email        = (document.getElementById("reg-email")?.value    || "").trim();
  const password     =  document.getElementById("reg-password")?.value || "";
  const phone        = (document.getElementById("reg-phone")?.value    || "").trim();

  if (!name || !email || !password || !businessName || !category || !phone) {
    showRegErr("Please fill in all fields.");
    return;
  }
  if (password.length < 8) {
    showRegErr("Password must be at least 8 characters.");
    return;
  }

  btn.disabled    = true;
  btn.textContent = "Registering…";

  // Step 1 — Create user account
  let token;
  try {
    const authRes  = await fetch(window.WTG_BASE_URL + "/api/auth/email/register", {
      method:  "POST",
      headers: { "Content-Type": "application/json" },
      body:    JSON.stringify({ name, email, password, phone }),
    });
    const authData = await authRes.json().catch(() => ({}));
    if (!authRes.ok) {
      btn.disabled = false; btn.textContent = "Register";
      if (authRes.status === 409) {
        // Account already exists under this email/phone — sending them
        // back into the same form to fail again isn't useful. Send them to
        // Sign In instead, pre-filled, so a real login (not a re-typed
        // guess at their existing password) is what links the vendor
        // application to their account. handleLogin() submits the vendor
        // application below once that real login succeeds.
        _pendingVendorApplication = { businessName, category, phone };
        showLoginForm({
          email,
          message: "Aapka account pehle se hai. Login karein — vendor account automatically link ho jaayega.",
        });
        return;
      }
      showRegErr(authData?.message || authData?.error || "Account creation failed. Please try again.");
      return;
    }
    token = authData?.token || authData?.data?.token;
    if (!token) {
      showRegErr("Registration succeeded but no token was returned. Please contact support.");
      btn.disabled = false; btn.textContent = "Register";
      return;
    }
  } catch (_) {
    showRegErr("Network error. Please check your connection and try again.");
    btn.disabled = false; btn.textContent = "Register";
    return;
  }

  // Step 2 — Submit vendor application
  try {
    const vendorRes  = await fetch(window.WTG_BASE_URL + "/api/vendors", {
      method:  "POST",
      headers: { "Content-Type": "application/json", "Authorization": "Bearer " + token },
      body:    JSON.stringify({ business_name: businessName, category, type: "service" }),
    });
    const vendorData = await vendorRes.json().catch(() => ({}));
    if (!vendorRes.ok) {
      showRegErr(vendorData?.message || vendorData?.error || "Vendor application failed. Please contact support.");
      btn.disabled = false; btn.textContent = "Register";
      return;
    }
  } catch (_) {
    showRegErr("Network error during vendor application. Please contact support.");
    btn.disabled = false; btn.textContent = "Register";
    return;
  }

  // Success — replace form with confirmation message
  document.getElementById("registerSection").innerHTML =
    "<p style='text-align:center;color:#166534;background:#dcfce7;border:1px solid #bbf7d0;" +
    "padding:1rem 1.25rem;border-radius:0.5rem;font-size:0.875rem;line-height:1.6;'>" +
    "Registration successful! Your application is under review.<br/>Login once approved." +
    "</p>" +
    "<p style='margin-top:0.75rem;font-size:0.8rem;text-align:center;'>" +
    "<a href='#' onclick=\"showLoginForm();return false;\" " +
    "style='color:#4f46e5;font-weight:600;text-decoration:none;'>Back to Sign In</a>" +
    "</p>";
}

function showRegErr(msg) {
  const el = document.getElementById("registerError");
  if (!el) return;
  el.textContent = msg;
  el.classList.add("show");
}
</script>
</body>
</html>
