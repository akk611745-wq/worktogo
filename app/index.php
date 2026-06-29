<?php
// Cache-busting version: derived automatically from the newest mtime among
// the app's CSS/JS files, so every deploy that touches any of them changes
// the version with zero manual steps (no hand-edited version string).
function wtg_build_version(): string {
    static $version = null;
    if ($version !== null) return $version;
    $latest = 0;
    $paths = array_merge(
        glob(__DIR__ . '/js/*.js') ?: [],
        glob(__DIR__ . '/css/*.css') ?: [],
        glob(__DIR__ . '/pages/*.js') ?: [],
        [__DIR__ . '/env.js']
    );
    foreach ($paths as $path) {
        $mtime = @filemtime($path);
        if ($mtime !== false && $mtime > $latest) $latest = $mtime;
    }
    $version = (string) ($latest > 0 ? $latest : time());
    return $version;
}

// app/index.php has no bootstrap; load .env manually so getenv() works here too.
(static function (): void {
    $envFile = dirname(__DIR__) . '/.env';
    if (!is_file($envFile)) return;
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name); $value = trim($value);
        if ($value !== '' && $value[0] !== '"' && $value[0] !== "'") {
            $pos = strpos($value, ' #');
            if ($pos !== false) $value = trim(substr($value, 0, $pos));
        }
        if (strlen($value) >= 2 &&
            (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
            $value = substr($value, 1, -1);
        }
        if (getenv($name) !== false) continue;
        putenv("{$name}={$value}");
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
})();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover"/>
  <meta name="theme-color" content="#0C0D12"/>
  <meta name="apple-mobile-web-app-capable" content="yes"/>
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"/>
  <meta name="format-detection" content="telephone=no"/>
  <title>WorkToGo</title>
  <style>html,body{background:#0C0D12;margin:0;}</style>

  <!-- Branding / PWA -->
  <link rel="icon" href="/app/assets/favicon.png"/>
  <link rel="apple-touch-icon" href="/app/assets/icon-192.png"/>
  <link rel="manifest" href="/app/manifest.json"/>

  <!-- SEO -->
  <meta name="description" content="WorkToGo — Haldwani ka trusted home service platform. Plumber, Electrician, Painter, Waterproofing, AC Repair. Verified local experts. Book in 60 seconds."/>
  <meta name="keywords" content="plumber haldwani, electrician haldwani, painter haldwani, waterproofing haldwani, ac repair haldwani, home services haldwani, worktogo"/>
  <meta name="robots" content="index, follow"/>
  <link rel="canonical" href="https://worktogo.in/app/"/>
  <meta property="og:title" content="WorkToGo — Trusted Home Services in Haldwani"/>
  <meta property="og:description" content="Book verified local experts in Haldwani. Plumber, Electrician, Painter, Waterproofing, AC Repair."/>
  <meta property="og:url" content="https://worktogo.in"/>
  <meta property="og:image" content="https://worktogo.in/app/assets/icon-512.png"/>

  <!-- Styles -->
  <link rel="stylesheet" href="css/main.css?v=<?php echo wtg_build_version(); ?>"/>
  <link rel="stylesheet" href="css/wtg-premium.css?v=<?php echo wtg_build_version(); ?>"/>

  <!-- Preconnect for fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>

  <!-- Google Identity Services -->
  <script src="https://accounts.google.com/gsi/client" async defer></script>

  <!-- Firebase Cloud Messaging (browser push notifications) -->
  <script defer src="https://www.gstatic.com/firebasejs/10.13.2/firebase-app-compat.js"></script>
  <script defer src="https://www.gstatic.com/firebasejs/10.13.2/firebase-messaging-compat.js"></script>
</head>
<body>

  <!-- App Root -->
  <!-- Inline shimmer skeleton (not main.css's .skel, which isn't loaded yet
       at this point) so the very first paint is a grey shimmer instead of a
       blank/black screen while CSS+JS are still downloading. ROUTER.init()
       replaces this content once the app boots. -->
  <style>
    #app-boot-skeleton { padding: 16px; }
    .boot-skel-bar { background: linear-gradient(90deg, #1c1d26 0%, #262833 50%, #1c1d26 100%);
      background-size: 800px 100%; animation: boot-shimmer 1.4s infinite; border-radius: 8px; }
    @keyframes boot-shimmer { 0% { background-position: -400px 0; } 100% { background-position: 400px 0; } }
    .boot-skel-search { height: 44px; margin-bottom: 16px; }
    .boot-skel-row { display: flex; gap: 12px; margin-bottom: 16px; }
    .boot-skel-chip { width: 64px; height: 64px; border-radius: 16px; flex-shrink: 0; }
    .boot-skel-card { height: 96px; margin-bottom: 12px; }
  </style>
  <div id="app">
    <div id="app-boot-skeleton" style="background:#0C0D12;min-height:100dvh;">
      <div class="boot-skel-bar boot-skel-search"></div>
      <div class="boot-skel-row">
        <div class="boot-skel-bar boot-skel-chip"></div>
        <div class="boot-skel-bar boot-skel-chip"></div>
        <div class="boot-skel-bar boot-skel-chip"></div>
        <div class="boot-skel-bar boot-skel-chip"></div>
      </div>
      <div class="boot-skel-bar boot-skel-card"></div>
      <div class="boot-skel-bar boot-skel-card"></div>
      <div class="boot-skel-bar boot-skel-card"></div>
    </div>
  </div>

  <!--
    ─────────────────────────────────────────────────────────────
    DEPLOYMENT: Edit env.js to set your real API base URL.
    Do NOT edit config.js directly in production.
    ─────────────────────────────────────────────────────────────
  -->

  <!-- Environment (set BASE_URL here) — MUST be first -->
  <script src="env.js?v=<?php echo wtg_build_version(); ?>"></script>

  <!-- Core Scripts (order matters) -->
  <script>
    window.WTG_BASE_URL         = "<?php echo rtrim($_ENV['APP_URL'] ?? getenv('APP_URL') ?? 'https://worktogo.in', '/'); ?>";
    window.WTG_GOOGLE_CLIENT_ID = "<?php echo htmlspecialchars(getenv('GOOGLE_CLIENT_ID') ?: ''); ?>";
    window.WTG_ASSET_VERSION    = "<?php echo wtg_build_version(); ?>";
    window.WTG_OTP_METHOD       = "<?php echo htmlspecialchars(getenv('OTP_METHOD') ?: 'sms'); ?>";
    window.WTG_WIDGET_ID        = "<?php echo htmlspecialchars(getenv('MSG91_WIDGET_ID') ?: ''); ?>";
    window.WTG_WIDGET_TOKEN     = "<?php echo htmlspecialchars(getenv('MSG91_WIDGET_TOKEN_AUTH') ?: ''); ?>";
    window.WTG_FCM_API_KEY      = "<?php echo htmlspecialchars(getenv('FCM_API_KEY') ?: ''); ?>";
    window.WTG_FCM_PROJECT_ID   = "<?php echo htmlspecialchars(getenv('FCM_PROJECT_ID') ?: ''); ?>";
    window.WTG_FCM_SENDER_ID    = "<?php echo htmlspecialchars(getenv('FCM_SENDER_ID') ?: ''); ?>";
    window.WTG_FCM_APP_ID       = "<?php echo htmlspecialchars(getenv('FCM_APP_ID') ?: ''); ?>";
    window.WTG_FCM_VAPID_KEY    = "<?php echo htmlspecialchars(getenv('FCM_VAPID_KEY') ?: ''); ?>";
  </script>
  <script src="js/config.js?v=<?php echo wtg_build_version(); ?>"></script>
  <script src="js/ui.js?v=<?php echo wtg_build_version(); ?>"></script>
  <script src="js/api.js?v=<?php echo wtg_build_version(); ?>"></script>
  <script src="js/auth.js?v=<?php echo wtg_build_version(); ?>"></script>
  <script src="js/router.js?v=<?php echo wtg_build_version(); ?>"></script>
  <script defer src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>
  <script src="js/push.js?v=<?php echo wtg_build_version(); ?>"></script>

  <!-- Bootstrap -->
  <script>
    document.addEventListener("DOMContentLoaded", () => {
      ROUTER.init();
    });
  </script>

  <script>
  if('serviceWorker' in navigator){
    window.addEventListener('load', function(){
      navigator.serviceWorker.register('/app/sw.js');
    });
  }
  </script>

  <script>
  let deferredPrompt;
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    setTimeout(() => {
      if(deferredPrompt){
        showInstallBanner();
      }
    }, 30000);
  });

  function showInstallBanner(){
    const banner = document.createElement('div');
    banner.id = 'wtg-install-banner';
    banner.innerHTML = `
      <div style="position:fixed;bottom:80px;left:16px;right:16px;
      background:#fff;border-radius:16px;padding:16px;
      box-shadow:0 4px 20px rgba(0,0,0,0.15);
      display:flex;align-items:center;gap:12px;z-index:9999;
      border-left:4px solid #e8681a;">
        <img src="/app/assets/icon-192.png"
             style="width:48px;height:48px;border-radius:10px;">
        <div style="flex:1">
          <div style="font-weight:700;font-size:14px;">
            Install WorkToGo App</div>
          <div style="font-size:12px;color:#666;">
            Book services faster from home screen</div>
        </div>
        <div style="display:flex;flex-direction:column;gap:6px;">
          <button onclick="installApp()"
            style="background:#e8681a;color:#fff;border:none;
            border-radius:8px;padding:6px 12px;
            font-size:12px;font-weight:700;cursor:pointer;">
            Install</button>
          <button onclick="dismissBanner()"
            style="background:transparent;border:none;
            color:#999;font-size:11px;cursor:pointer;">
            Not now</button>
        </div>
      </div>`;
    document.body.appendChild(banner);
  }

  function installApp(){
    if(deferredPrompt){
      deferredPrompt.prompt();
      deferredPrompt.userChoice.then(() => {
        deferredPrompt = null;
        dismissBanner();
      });
    }
  }

  function dismissBanner(){
    const b = document.getElementById('wtg-install-banner');
    if(b) b.remove();
  }
  </script>

</body>
</html>
