import axios from 'axios';

const firebaseConfig = {
  apiKey: import.meta.env.VITE_FIREBASE_API_KEY,
  authDomain: import.meta.env.VITE_FIREBASE_AUTH_DOMAIN,
  projectId: import.meta.env.VITE_FIREBASE_PROJECT_ID,
  storageBucket: import.meta.env.VITE_FIREBASE_STORAGE_BUCKET,
  messagingSenderId: import.meta.env.VITE_FIREBASE_MESSAGING_SENDER_ID,
  appId: import.meta.env.VITE_FIREBASE_APP_ID,
};

let firebaseApp = null;
let messaging = null;
let initialized = false;

async function initFirebase() {
    if (initialized) return;
    initialized = true;
    
    try {
        const { initializeApp } = await import("firebase/app");
        const { getMessaging } = await import("firebase/messaging");
        
        firebaseApp = initializeApp(firebaseConfig);
        
        if (typeof window !== 'undefined' && 'serviceWorker' in navigator) {
            messaging = getMessaging(firebaseApp);
        }
    } catch (error) {
        console.warn('Firebase initialization skipped:', error.message);
    }
}

export const requestFirebaseToken = async () => {
    await initFirebase();
    if (!messaging) return null;
    
    try {
        const { getToken } = await import("firebase/messaging");
        
        if (Notification.permission === 'denied') return null;
        if (localStorage.getItem('fcm_permission_asked') === 'true' && Notification.permission === 'default') {
            return null;
        }
        localStorage.setItem('fcm_permission_asked', 'true');
        
        const permission = await Notification.requestPermission();
        if (permission === 'granted') {
            const currentToken = await getToken(messaging, {});
            
            if (currentToken) {
                await axios.post('/api/fcm-token', {
                    token: currentToken,
                    device_type: /Mac|iPod|iPhone|iPad/.test(navigator.userAgent) ? 'ios' : 
                                 /Android/.test(navigator.userAgent) ? 'android' : 'web'
                });
                return currentToken;
            }
        }
    } catch (error) {
        console.warn('FCM token request failed:', error.message);
    }
    return null;
};

export const onMessageListener = () =>
  new Promise(async (resolve) => {
    await initFirebase();
    if (messaging) {
        const { onMessage } = await import("firebase/messaging");
        onMessage(messaging, (payload) => {
            resolve(payload);
        });
    }
  });
