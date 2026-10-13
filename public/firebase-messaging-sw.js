// عامل خدمة إشعارات الجهاز (FCM).
// لا يحمّل مكتبة Firebase: الصفحة تسجّل الجهاز (resources/js/firebase.js)، وهنا العرض وحده.
// الخادم يرسل رسائل «بيانات فقط» (PushService)، فلا يعرضها أحد غير هذا الملف — فلا تكرار.
// أي تعديل هنا: ارفع ?v= في SW_URL داخل firebase.js.

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch (e) {
        payload = {};
    }
    const d = payload.data || payload.notification || {};

    event.waitUntil(self.registration.showNotification(d.title || 'إشعار جديد', {
        body: d.body || '',
        icon: '/images/logo-dark.png',
        badge: '/icons/icon-192.png',
        dir: 'rtl',
        lang: 'ar',
        tag: d.tag || undefined,
        data: { url: d.url || '/' },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL(event.notification.data?.url || '/', self.location.origin).href;

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        const open = windows.find((w) => w.url === url);
        if (open && 'focus' in open) {
            return open.focus();
        }
        return self.clients.openWindow(url);
    })());
});
