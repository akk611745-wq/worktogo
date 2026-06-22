// WorkToGo — Firebase Cloud Messaging Service Worker
// Handles push notifications received while the app tab is not focused.
// Registered from app/index.php after a successful login.
//
// This file is a static asset and cannot read .env, so the (non-secret)
// Firebase web config is passed in via the registration URL's query
// string instead of being hardcoded here — see app/index.php for the
// values, which it reads from getenv().

importScripts('https://www.gstatic.com/firebasejs/10.13.2/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.13.2/firebase-messaging-compat.js');

const _cfg = new URL(self.location.href).searchParams;

firebase.initializeApp({
  apiKey: _cfg.get('apiKey') || '',
  projectId: _cfg.get('projectId') || '',
  messagingSenderId: _cfg.get('senderId') || '',
  appId: _cfg.get('appId') || '',
});

const messaging = firebase.messaging();

messaging.onBackgroundMessage((payload) => {
  const title = payload.notification?.title || 'WorkToGo';
  const body = payload.notification?.body || '';
  const url = payload.fcmOptions?.link || payload.data?.url || '/app/';

  self.registration.showNotification(title, {
    body,
    icon: '/assets/icon-192.png',
    data: { url },
  });
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = event.notification.data?.url || '/app/';
  event.waitUntil(
    self.clients.matchAll({ type: 'window' }).then((clients) => {
      for (const client of clients) {
        if (client.url.includes(url) && 'focus' in client) {
          return client.focus();
        }
      }
      return self.clients.openWindow(url);
    })
  );
});
