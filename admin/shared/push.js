/**
 * WorkToGo Admin Panel — Browser Push Registration (FCM)
 * Called once per page load by Shell.init() when an admin is logged in.
 * Config is fetched from /api/auth/fcm-config (public, non-secret values
 * sourced server-side from .env) since this is a static JS file.
 */

const WTGPush = {
  _started: false,

  _loadScript(src) {
    return new Promise((resolve, reject) => {
      const el = document.createElement('script');
      el.src = src;
      el.onload = resolve;
      el.onerror = reject;
      document.head.appendChild(el);
    });
  },

  async register() {
    if (this._started) return;
    this._started = true;

    try {
      if (!("serviceWorker" in navigator) || !("Notification" in window)) return;

      if (typeof firebase === "undefined") {
        await this._loadScript('https://www.gstatic.com/firebasejs/10.13.2/firebase-app-compat.js');
        await this._loadScript('https://www.gstatic.com/firebasejs/10.13.2/firebase-messaging-compat.js');
      }

      const cfgRes = await API.get('/auth/fcm-config');
      const cfg = cfgRes?.data?.data || cfgRes?.data;
      if (!cfg?.apiKey || !cfg?.vapidKey) return;

      const permission = await Notification.requestPermission();
      if (permission !== "granted") return;

      const swParams = new URLSearchParams({
        apiKey: cfg.apiKey,
        projectId: cfg.projectId,
        senderId: cfg.senderId,
        appId: cfg.appId,
      });
      const registration = await navigator.serviceWorker.register(
        `/app/pages/firebase-sw.js?${swParams.toString()}`
      );

      firebase.initializeApp({
        apiKey: cfg.apiKey,
        projectId: cfg.projectId,
        messagingSenderId: cfg.senderId,
        appId: cfg.appId,
      });

      const messaging = firebase.messaging();
      const token = await messaging.getToken({
        vapidKey: cfg.vapidKey,
        serviceWorkerRegistration: registration,
      });

      if (token) {
        await API.post('/auth/device/fcm', { token, device_type: 'web' });
      }
    } catch (err) {
      console.warn("Push registration skipped:", err?.message || err);
    }
  },
};

// Already-logged-in admins (revisiting / reloading) also need the prompt —
// Shell.init() also calls register() on every page, but the _started guard
// makes this safe to call from both places.
if (localStorage.getItem("wtg_admin_token")) {
  WTGPush.register();
}
