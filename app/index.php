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
  <link rel="stylesheet" href="css/main.css?v=20260524-customer-stabilization"/>

  <!-- Preconnect for fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
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
  <script src="env.js?v=20260524-customer-stabilization"></script>

  <!-- Core Scripts (order matters) -->
  <script>
    window.WTG_BASE_URL = "<?php echo rtrim($_ENV['APP_URL'] ?? getenv('APP_URL') ?? 'https://worktogo.in', '/'); ?>";
    window.WTG_ASSET_VERSION = "20260524-customer-stabilization";
  </script>
  <script src="js/config.js?v=20260524-customer-stabilization"></script>
  <script src="js/ui.js?v=20260524-customer-stabilization"></script>
  <script src="js/api.js?v=20260524-customer-stabilization"></script>
  <script src="js/auth.js?v=20260524-customer-stabilization"></script>
  <script src="js/router.js?v=20260524-customer-stabilization"></script>
  <script src="/app/pages/vendor-apply-modal.js?v=20260524-customer-stabilization"></script>

  <!-- Bootstrap -->
  <script>
    document.addEventListener("DOMContentLoaded", () => {
      ROUTER.init();
    });
  </script>

</body>
</html>
