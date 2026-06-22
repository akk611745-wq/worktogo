/**
 * WorkToGo — Browser Push Registration (FCM)
 * Called once after a successful login (see auth.js saveSession()).
 * Requests notification permission, registers the service worker,
 * fetches an FCM token, and saves it server-side.
 */

async function WTG_registerPush() {
  try {
    if (!("serviceWorker" in navigator) || !("Notification" in window)) return;
    if (!window.WTG_FCM_API_KEY || !window.WTG_FCM_VAPID_KEY) return;

    const permission = await Notification.requestPermission();
    if (permission !== "granted") return;

    const swParams = new URLSearchParams({
      apiKey: window.WTG_FCM_API_KEY,
      projectId: window.WTG_FCM_PROJECT_ID,
      senderId: window.WTG_FCM_SENDER_ID,
      appId: window.WTG_FCM_APP_ID,
    });
    const registration = await navigator.serviceWorker.register(
      `pages/firebase-sw.js?${swParams.toString()}`
    );

    firebase.initializeApp({
      apiKey: window.WTG_FCM_API_KEY,
      projectId: window.WTG_FCM_PROJECT_ID,
      messagingSenderId: window.WTG_FCM_SENDER_ID,
      appId: window.WTG_FCM_APP_ID,
    });

    const messaging = firebase.messaging();
    const token = await messaging.getToken({
      vapidKey: window.WTG_FCM_VAPID_KEY,
      serviceWorkerRegistration: registration,
    });

    if (token) {
      await API.saveFcmToken(token, "web");
    }
  } catch (err) {
    console.warn("Push registration skipped:", err?.message || err);
  }
}
