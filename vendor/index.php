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
  <div class="auth-tabs" style="display:flex;gap:0.5rem;margin-bottom:1.25rem;border-bottom:1px solid #e5e7eb;">
    <button type="button" id="tabSignIn" class="auth-tab active" onclick="showLoginForm();return false;"
      style="flex:1;padding:0.65rem 0;background:none;border:none;border-bottom:2px solid #f5a623;font-family:inherit;font-size:0.9rem;font-weight:700;color:#111827;cursor:pointer;">
      Sign In
    </button>
    <button type="button" id="tabRegister" class="auth-tab" onclick="showRegisterForm();return false;"
      style="flex:1;padding:0.65rem 0;background:none;border:none;border-bottom:2px solid transparent;font-family:inherit;font-size:0.9rem;font-weight:700;color:#6b7280;cursor:pointer;">
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

    <p style="margin-top:1.5rem;font-size:0.75rem;color:#374151;text-align:center;">
      Having trouble? Contact your administrator.
    </p>
  </div>

  <!-- Registration Form -->
  <div id="registerSection" style="display:none;">
    <h2 style="font-size:1.1rem;font-weight:700;color:#111827;margin:0 0 0.25rem;">Create Vendor Account</h2>
    <p style="font-size:0.8rem;color:#6b7280;margin:0 0 1rem;">Fill in your details to apply as a vendor.</p>
    <div class="login-error" id="registerError"></div>
    <div class="form-group">
      <label class="form-label" for="reg-name">Full Name</label>
      <input class="form-input" type="text" id="reg-name" placeholder="Your full name" autocomplete="name"/>
    </div>
    <div class="form-group">
      <label class="form-label" for="reg-email">Email address</label>
      <input class="form-input" type="email" id="reg-email" placeholder="you@example.com" autocomplete="email"/>
    </div>
    <div class="form-group">
      <label class="form-label" for="reg-password">Password</label>
      <input class="form-input" type="password" id="reg-password" placeholder="Min 8 characters" autocomplete="new-password"/>
    </div>
    <div class="form-group">
      <label class="form-label" for="reg-business">Business Name</label>
      <input class="form-input" type="text" id="reg-business" placeholder="Your business name" autocomplete="organization"/>
    </div>
    <div class="form-group">
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
});

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
        body:    JSON.stringify({ business_name: pending.businessName, type: "service" }),
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

function showRegisterForm() {
  document.getElementById("loginSection").style.display = "none";
  document.getElementById("registerSection").style.display = "block";
  document.getElementById("registerError").classList.remove("show");
  setActiveTab("register");
}

function showLoginForm(prefill) {
  document.getElementById("registerSection").style.display = "none";
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

function setActiveTab(which) {
  const tabSignIn   = document.getElementById("tabSignIn");
  const tabRegister = document.getElementById("tabRegister");
  const signInActive = which === "signin";
  tabSignIn.style.borderBottomColor   = signInActive ? "#f5a623" : "transparent";
  tabSignIn.style.color               = signInActive ? "#111827" : "#6b7280";
  tabRegister.style.borderBottomColor = signInActive ? "transparent" : "#f5a623";
  tabRegister.style.color             = signInActive ? "#6b7280" : "#111827";
}

async function handleRegister() {
  const name         = (document.getElementById("reg-name")?.value     || "").trim();
  const email        = (document.getElementById("reg-email")?.value    || "").trim();
  const password     =  document.getElementById("reg-password")?.value || "";
  const businessName = (document.getElementById("reg-business")?.value || "").trim();
  const phone        = (document.getElementById("reg-phone")?.value    || "").trim();
  const btn          =  document.getElementById("registerBtn");
  const errEl        =  document.getElementById("registerError");

  errEl.classList.remove("show");

  if (!name || !email || !password || !businessName || !phone) {
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
        _pendingVendorApplication = { businessName, phone };
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
      body:    JSON.stringify({ business_name: businessName, type: "service" }),
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
