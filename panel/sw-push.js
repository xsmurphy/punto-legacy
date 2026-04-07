/**
 * Service Worker — Web Push receiver
 * Registrar desde el panel con:
 *   navigator.serviceWorker.register('/sw-push.js')
 */

self.addEventListener('push', function(event) {
    if (!event.data) return;

    const data = event.data.json();

    const title   = data.title   || 'Punto';
    const options = {
        body:  data.message || '',
        icon:  '/images/iconincomesm.png',
        badge: '/images/iconincomesm.png',
        data:  { url: data.url || '/' },
    };

    event.waitUntil(
        self.registration.showNotification(title, options)
    );
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    const url = event.notification.data.url;
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(list) {
            for (const client of list) {
                if (client.url === url && 'focus' in client) return client.focus();
            }
            if (clients.openWindow) return clients.openWindow(url);
        })
    );
});
