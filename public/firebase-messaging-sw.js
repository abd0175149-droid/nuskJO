importScripts('https://www.gstatic.com/firebasejs/10.9.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.9.0/firebase-messaging-compat.js');

const firebaseConfig = {
    apiKey: "AIzaSyDm_Auuyq1McWdd3bYkCWGD27R-8H2vDoQ",
    authDomain: "how-diploma-app.firebaseapp.com",
    projectId: "how-diploma-app",
    storageBucket: "how-diploma-app.firebasestorage.app",
    messagingSenderId: "260387617106",
    appId: "1:260387617106:web:12b5dcea1b82c0de68628b"
};

// Initialize Firebase
firebase.initializeApp(firebaseConfig);

// Retrieve an instance of Firebase Messaging so that it can handle background messages.
const messaging = firebase.messaging();

// Handle background messages
messaging.onBackgroundMessage((payload) => {
    console.log('[firebase-messaging-sw.js] Received background message ', payload);
    
    // Customize notification here
    const notificationTitle = payload.notification.title || 'إشعار جديد';
    const notificationOptions = {
        body: payload.notification.body,
        icon: '/images/logo-dark.png',
        badge: '/icons/icon-192.png',
        data: payload.data, // Contains URL to open on click
        dir: 'rtl',
        vibrate: [200, 100, 200]
    };

    self.registration.showNotification(notificationTitle, notificationOptions);
});

// Handle notification clicks
self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    
    // Try to open the URL passed in data, otherwise default to home
    const urlToOpen = event.notification.data?.url || '/';
    
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
            // Check if there is already a window/tab open with the target URL
            for (let i = 0; i < windowClients.length; i++) {
                const client = windowClients[i];
                // If so, just focus it.
                if (client.url === urlToOpen && 'focus' in client) {
                    return client.focus();
                }
            }
            // If not, then open the target URL in a new window/tab.
            if (clients.openWindow) {
                return clients.openWindow(urlToOpen);
            }
        })
    );
});
