<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no"/>
<title>Profile — WorkToGo Vendor</title>
<link rel="stylesheet" href="assets/style.css"/>
<script>
  window.WTG_BASE_URL = "<?php echo rtrim($_ENV['APP_URL'] ?? getenv('APP_URL') ?? 'https://worktogo.in', '/'); ?>";
</script>
<script src="config.js"></script>
<script src="shared/auth.js"></script>
<script src="shared/api.js"></script>
<script src="shared/shell.js"></script>
<script src="assets/app.js"></script>
<script src="shared/realtime.js"></script>
<script src="shared/analytics.js"></script>
</head>
<body>
<script>
let profileData     = null;
let categoriesCache = [];

document.addEventListener("DOMContentLoaded", async () => {
  const user = initShell("Profile");
  if (!user) return;
  renderPage(user);
  await loadProfile();
});

function renderPage(user) {
  const el = document.getElementById("pageContent");
  el.innerHTML = `
    <div class="page-header">
      <div>
        <h1 class="page-title">My Profile</h1>
        <p class="page-sub">View and update your vendor details.</p>
      </div>
    </div>

    <!-- Hero -->
    <div class="profile-hero" id="profileHero">
      <div class="profile-avatar" id="profileAvatarBig">?</div>
      <div>
        <div class="profile-name" id="profileNameBig">Loading&hellip;</div>
        <div class="profile-email" id="profileEmailBig">&mdash;</div>
        <div class="profile-role-pill" id="profileRolePill">&mdash;</div>
      </div>
    </div>

    <!-- Details card -->
    <div class="card" style="max-width:640px;">
      <div class="card-header">
        <span class="card-title">Account Details</span>
        <button class="btn btn-primary btn-sm" onclick="enableEdit()">
          <svg viewBox="0 0 20 20" fill="currentColor"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/></svg>
          Edit Profile
        </button>
      </div>
      <div class="card-body">

        <!-- View mode -->
        <div id="viewMode">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div class="field"><label>Business / Full Name</label><div id="vName"   class="text-sm" style="padding:0.4rem 0;border-bottom:1px solid var(--border);">&mdash;</div></div>
            <div class="field"><label>Phone</label>               <div id="vPhone"  class="text-sm" style="padding:0.4rem 0;border-bottom:1px solid var(--border);">&mdash;</div></div>
            <div class="field"><label>Email</label>               <div id="vEmail"  class="text-sm" style="padding:0.4rem 0;border-bottom:1px solid var(--border);">&mdash;</div></div>
            <div class="field"><label>Role</label>                <div id="vRole"   class="text-sm" style="padding:0.4rem 0;border-bottom:1px solid var(--border);">&mdash;</div></div>
            <div class="field"><label>Status</label>              <div id="vStatus" class="text-sm" style="padding:0.4rem 0;border-bottom:1px solid var(--border);">&mdash;</div></div>
            <div class="field"><label>Member Since</label>        <div id="vSince"  class="text-sm" style="padding:0.4rem 0;border-bottom:1px solid var(--border);">&mdash;</div></div>
            <div class="field"><label>Category</label>            <div id="vCategory" class="text-sm" style="padding:0.4rem 0;border-bottom:1px solid var(--border);">&mdash;</div></div>
            <div class="field"><label>Photo</label>               <div id="vPhoto"  class="text-sm" style="padding:0.4rem 0;border-bottom:1px solid var(--border);">&mdash;</div></div>
            <div class="field" style="grid-column:1/-1"><label>Bio / Description</label>
              <div id="vBio" class="text-sm" style="padding:0.4rem 0;border-bottom:1px solid var(--border);white-space:pre-wrap;">&mdash;</div>
            </div>
          </div>
        </div>

        <!-- Edit mode -->
        <div id="editMode" style="display:none;">
          <div class="form-grid">
            <div class="field">
              <label for="eName">Full / Business Name</label>
              <input type="text" id="eName" placeholder="Your name or business name"/>
            </div>
            <div class="field">
              <label for="ePhone">Phone Number</label>
              <input type="tel" id="ePhone" placeholder="+91 XXXXX XXXXX"/>
            </div>
            <div class="field">
              <label for="eCategory">Category</label>
              <select id="eCategory"><option value="">— Select category —</option></select>
            </div>
            <div class="field">
              <label for="eLogoUrl">Photo URL</label>
              <input type="url" id="eLogoUrl" placeholder="https://example.com/photo.jpg"/>
            </div>
            <div class="field" style="grid-column:1/-1">
              <label for="eDescription">Bio / Description</label>
              <textarea id="eDescription" rows="3" placeholder="Tell customers about your business&hellip;" style="width:100%;resize:vertical;"></textarea>
            </div>
          </div>
          <div style="display:flex;gap:0.6rem;margin-top:1.25rem;">
            <button class="btn btn-ghost" onclick="cancelEdit()">Cancel</button>
            <button class="btn btn-primary" id="saveProfileBtn" onclick="saveProfile()">
              Save Changes
            </button>
          </div>
        </div>

      </div>
    </div>

    <!-- Security info card -->
    <div class="card" style="max-width:640px;margin-top:0;">
      <div class="card-header">
        <span class="card-title">Security</span>
      </div>
      <div class="card-body">
        <p class="text-sm text-muted" style="margin-bottom:0.75rem;">
          To change your password or email, please contact the system administrator.
        </p>
        <button class="btn btn-danger btn-sm" onclick="confirmLogout()">
          <svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px;"><path fill-rule="evenodd" d="M3 3a1 1 0 00-1 1v12a1 1 0 102 0V4a1 1 0 00-1-1zm10.293 9.293a1 1 0 001.414 1.414l3-3a1 1 0 000-1.414l-3-3a1 1 0 10-1.414 1.414L14.586 9H7a1 1 0 100 2h7.586l-1.293 1.293z"/></svg>
          Log Out from All Sessions
        </button>
      </div>
    </div>
  `;
}

/* ── Data loaders ─────────────────────────────────────────── */

async function loadProfile() {
  // Fetch profile and categories in parallel — no sequential wait
  const [profileRes, catRes] = await Promise.all([
    API.Profile.get(),
    API.Categories.list(),
  ]);

  categoriesCache = catRes.data?.data?.categories || catRes.data?.categories || [];

  if (!profileRes.ok) {
    profileData = Auth.getUser() || {};
    showToast("Using cached profile data.", "warning");
  } else {
    profileData = profileRes.data?.data || profileRes.data || Auth.getUser() || {};
    const token = Auth.getToken();
    if (token) Auth.setSession(token, profileData);
  }
  populateProfile(profileData);
}

/* ── Display ──────────────────────────────────────────────── */

function populateProfile(p) {
  const name  = p.name || p.business_name || '—';
  const email = p.email || '—';
  const role  = (p.role || '').replace('vendor_', '').replace('_', ' ');

  // Hero — show photo thumbnail if logo_url is set
  const avatarEl = document.getElementById("profileAvatarBig");
  if (p.logo_url) {
    avatarEl.innerHTML = `<img src="${escHtml(p.logo_url)}" alt="${escHtml(name.charAt(0))}" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;">`;
    avatarEl.style.overflow = 'hidden';
  } else {
    avatarEl.textContent    = name.charAt(0).toUpperCase() || '?';
    avatarEl.style.overflow = '';
  }
  document.getElementById("profileNameBig").textContent  = name;
  document.getElementById("profileEmailBig").textContent = email;
  document.getElementById("profileRolePill").textContent = cap(role) + ' Vendor';

  // View fields — original 6
  document.getElementById("vName").textContent   = name;
  document.getElementById("vPhone").textContent  = p.phone || '—';
  document.getElementById("vEmail").textContent  = email;
  document.getElementById("vRole").innerHTML     = statusBadge(role + ' vendor');
  document.getElementById("vStatus").innerHTML   = statusBadge(p.status || 'active');
  document.getElementById("vSince").textContent  = fmtDate(p.created_at || p.createdAt) || '—';

  // View fields — new 3
  const catObj = categoriesCache.find(c => String(c.id) === String(p.category_id));
  document.getElementById("vCategory").textContent = catObj ? catObj.name : (p.category_id ? `Category #${p.category_id}` : '—');
  document.getElementById("vBio").textContent      = p.description || '—';

  const vPhoto = document.getElementById("vPhoto");
  if (p.logo_url) {
    vPhoto.innerHTML = `<img src="${escHtml(p.logo_url)}" alt="Logo" style="width:40px;height:40px;border-radius:6px;object-fit:cover;border:1px solid var(--border);vertical-align:middle;margin-right:6px;"><span class="text-muted" style="font-size:0.72rem;">Set</span>`;
  } else {
    vPhoto.textContent = '—';
  }

  // Edit prefill — original 2
  document.getElementById("eName").value  = p.name || p.business_name || '';
  document.getElementById("ePhone").value = p.phone || '';

  // Edit prefill — new 3
  document.getElementById("eDescription").value = p.description || '';
  document.getElementById("eLogoUrl").value      = p.logo_url    || '';
  populateCategorySelect();
}

function populateCategorySelect() {
  const sel = document.getElementById("eCategory");
  if (!sel || !categoriesCache.length) return;
  const current = profileData?.category_id;
  sel.innerHTML = '<option value="">— Select category —</option>' +
    categoriesCache
      .filter(c => (c.status === 'active' || c.status == null))
      .map(c => `<option value="${c.id}"${String(c.id) === String(current) ? ' selected' : ''}>${escHtml(c.name)}</option>`)
      .join('');
}

/* ── Edit mode ────────────────────────────────────────────── */

async function enableEdit() {
  document.getElementById("viewMode").style.display = 'none';
  document.getElementById("editMode").style.display = 'block';
  // If categories failed to load on page load, try once more
  if (!categoriesCache.length) {
    const catRes = await API.Categories.list();
    categoriesCache = catRes.data?.data?.categories || catRes.data?.categories || [];
  }
  populateCategorySelect();
}

function cancelEdit() {
  document.getElementById("viewMode").style.display = 'block';
  document.getElementById("editMode").style.display = 'none';
}

/* ── Save ─────────────────────────────────────────────────── */

async function saveProfile() {
  const name        = document.getElementById("eName").value.trim();
  const phone       = document.getElementById("ePhone").value.trim();
  const description = document.getElementById("eDescription").value.trim();
  const logo_url    = document.getElementById("eLogoUrl").value.trim();
  const category_id = parseInt(document.getElementById("eCategory").value) || null;

  if (!name) { showToast("Name is required.", "warning"); return; }

  const btn = document.getElementById("saveProfileBtn");
  btn.disabled    = true;
  btn.textContent = "Saving…";

  const payload = { name, phone, description, logo_url, category_id };
  const res = await API.Profile.update(payload);

  btn.disabled    = false;
  btn.textContent = "Save Changes";

  if (!res.ok) {
    showToast(res.data?.message || "Save failed.", "error");
    return;
  }

  showToast("Profile updated!", "success");
  profileData = { ...profileData, ...payload };
  Auth.setSession(Auth.getToken(), profileData);
  populateProfile(profileData);
  cancelEdit();
}

function confirmLogout() {
  if (confirmAction("Log out from all sessions?")) Auth.logout();
}

/* ── Helpers ──────────────────────────────────────────────── */

function escHtml(str) {
  return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function cap(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
</script>
</body>
</html>
