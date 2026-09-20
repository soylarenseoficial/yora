const YORA_SW = 'yora-driver-v10';

self.addEventListener('install', (e) => {
  self.skipWaiting();
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.map((k) => caches.delete(k)))).then(() => self.clients.claim())
  );
});

// No interceptamos fetch: una PWA cacheada dejaba el login y el panel viejos
// y la gente no podía entrar. El SW solo sirve para las notificaciones push.

self.addEventListener('push', (event) => {
  let data = { title: 'Yora Driver', body: 'Tienes un aviso nuevo.', url: '/dashboard.php' };
  if (event.data) {
    try { data = Object.assign(data, event.data.json()); } catch (err) {}
  }
    event.waitUntil(self.registration.showNotification(data.title || 'Yora Driver', {
    body: data.body || 'Abre la app para ver el detalle.',
    icon: 'https://yoradelivery.com/uploads/Isotipo.png',
    badge: 'https://yoradelivery.com/uploads/Isotipo.png',
    vibrate: [400, 120, 400, 120, 600],
    tag: 'yora-pedido-' + Date.now(),
    renotify: true,
    requireInteraction: true,
    data: { url: data.url || '/dashboard.php' }
  }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const dest = (event.notification.data && event.notification.data.url) ? event.notification.data.url : '/dashboard.php';
  event.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
    for (const client of list) {
      if ('focus' in client) return client.focus();
    }
    return clients.openWindow(dest);
  }));
});
