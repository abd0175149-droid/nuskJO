import axios from 'axios';

const firebaseConfig = {
  apiKey: "AIzaSyAxjH7hHTj5TbTfT5WUyLFV0Jsc6nQx8_o",
  authDomain: "nusuk-930f3.firebaseapp.com",
  projectId: "nusuk-930f3",
  storageBucket: "nusuk-930f3.firebasestorage.app",
  messagingSenderId: "982933275772",
  appId: "1:982933275772:web:b7ec5412c43e63f21f35f9"
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
