importScripts('https://www.gstatic.com/firebasejs/10.9.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.9.0/firebase-messaging-compat.js');

const firebaseConfig = {
    apiKey: "AIzaSyAxjH7hHTj5TbTfT5WUyLFV0Jsc6nQx8_o",
    authDomain: "nusuk-930f3.firebaseapp.com",
    projectId: "nusuk-930f3",
    storageBucket: "nusuk-930f3.firebasestorage.app",
    messagingSenderId: "982933275772",
    appId: "1:982933275772:web:b7ec5412c43e63f21f35f9"
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
