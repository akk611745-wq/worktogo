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
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"/>
  <meta name="theme-color" content="#0C0D12"/>
  <meta name="apple-mobile-web-app-capable" content="yes"/>
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"/>
  <meta name="format-detection" content="telephone=no"/>
  <title>WorkToGo</title>

  <!-- Styles -->
  <link rel="stylesheet" href="css/main.css?v=<?php echo wtg_build_version(); ?>"/>

  <!-- Preconnect for fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>

  <!-- Google Identity Services -->
  <script src="https://accounts.google.com/gsi/client" async defer></script>
</head>
<body>

  <!-- App Root -->
  <div id="app">
    <div class="page-loading">
      <div class="spinner"></div>
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
  </script>
  <script src="js/config.js?v=<?php echo wtg_build_version(); ?>"></script>
  <script src="js/ui.js?v=<?php echo wtg_build_version(); ?>"></script>
  <script src="js/api.js?v=<?php echo wtg_build_version(); ?>"></script>
  <script src="js/auth.js?v=<?php echo wtg_build_version(); ?>"></script>
  <script src="js/router.js?v=<?php echo wtg_build_version(); ?>"></script>
  <script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>
  <script src="/app/pages/vendor-apply-modal.js?v=<?php echo wtg_build_version(); ?>"></script>

  <!-- Bootstrap -->
  <script>
    document.addEventListener("DOMContentLoaded", () => {
      ROUTER.init();
    });
  </script>

</body>
</html>
