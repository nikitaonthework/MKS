// Service Worker веб-кабинета сотрудников. Не кэширует страницы (офлайн-режим
// не требуется) — единственная задача: получать push-уведомления о новых
// ответах IT-отдела и открывать/фокусировать заявку по клику на уведомление.

self.addEventListener('install', function (event) {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
    var data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { title: 'IT Service Desk', body: event.data ? event.data.text() : '' };
    }

    var title = data.title || 'IT Service Desk';
    var options = {
        body: data.body || '',
        icon: 'it/icon-192.png',
        badge: 'it/icon-192.png',
        data: { url: data.url || './dashboard.php' },
        tag: data.tag || undefined,
        renotify: !!data.tag,
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var url = (event.notification.data && event.notification.data.url) || './dashboard.php';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (windowClients) {
            for (var i = 0; i < windowClients.length; i++) {
                var client = windowClients[i];
                if ('focus' in client) {
                    client.focus();
                    if ('navigate' in client) {
                        client.navigate(url);
                    }
                    return;
                }
            }
            if (self.clients.openWindow) {
                return self.clients.openWindow(url);
            }
        })
    );
});
