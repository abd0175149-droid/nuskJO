import axios from 'axios';

// إعدادات مشروع Firebase (nuskjo) — تُكتب في الواجهة وقت البناء من VITE_FIREBASE_*
const firebaseConfig = {
    apiKey: import.meta.env.VITE_FIREBASE_API_KEY,
    authDomain: import.meta.env.VITE_FIREBASE_AUTH_DOMAIN,
    projectId: import.meta.env.VITE_FIREBASE_PROJECT_ID,
    storageBucket: import.meta.env.VITE_FIREBASE_STORAGE_BUCKET,
    messagingSenderId: import.meta.env.VITE_FIREBASE_MESSAGING_SENDER_ID,
    appId: import.meta.env.VITE_FIREBASE_APP_ID,
};
const vapidKey = import.meta.env.VITE_FIREBASE_VAPID_KEY;

// عامل خدمة الإشعارات في نطاق منفصل فلا يتحكم بصفحات الموقع.
// ?v= يتغيّر مع كل تعديل على الملف ليتجاوز كاش المتصفح وCloudflare
const SW_URL = '/firebase-messaging-sw.js?v=2';
const SW_SCOPE = '/firebase-cloud-messaging-push-scope';
const TOKEN_KEY = 'nusuk-push-token';

const store = {
    get: () => { try { return sessionStorage.getItem(TOKEN_KEY); } catch { return null; } },
    set: (t) => { try { sessionStorage.setItem(TOKEN_KEY, t); } catch { /* تصفح خاص */ } },
    clear: () => { try { sessionStorage.removeItem(TOKEN_KEY); } catch { /* تصفح خاص */ } },
};

const isConfigured = () => Boolean(
    firebaseConfig.apiKey && firebaseConfig.projectId && firebaseConfig.messagingSenderId && firebaseConfig.appId && vapidKey
);

const deviceType = () => (/iPhone|iPad|iPod|Macintosh/.test(navigator.userAgent) && 'ontouchend' in document ? 'ios'
    : /Android/.test(navigator.userAgent) ? 'android' : 'web');

/**
 * حالة إشعارات هذا الجهاز:
 * unsupported (المتصفح لا يدعمها) | unconfigured (إعدادات Firebase ناقصة في البناء)
 * default (لم يُسأل بعد) | granted | denied (حظرها المستخدم)
 */
export const pushStatus = () => {
    if (typeof window === 'undefined' || !('Notification' in window) || !('serviceWorker' in navigator) || !('PushManager' in window)) {
        return 'unsupported';
    }
    if (!isConfigured()) return 'unconfigured';
    return Notification.permission;
};

/** آيفون/آيباد في Safari العادي: الإشعارات تعمل فقط بعد «إضافة إلى الشاشة الرئيسية» */
export const needsIosInstall = () => /iPhone|iPad|iPod/.test(navigator.userAgent)
    && !window.navigator.standalone && !window.matchMedia?.('(display-mode: standalone)').matches;

let messagingPromise = null;
const getMessagingInstance = () => {
    messagingPromise ??= (async () => {
        const { initializeApp } = await import('firebase/app');
        const { getMessaging, isSupported } = await import('firebase/messaging');
        if (!(await isSupported())) return null;
        return getMessaging(initializeApp(firebaseConfig));
    })().catch((e) => {
        console.warn('Firebase init skipped:', e.message);
        return null;
    });
    return messagingPromise;
};

// الاشتراك يحتاج عامل خدمة نشطاً، والتسجيل الأول يعيده وهو ما زال يُثبَّت
const registerWorker = async () => {
    const reg = await navigator.serviceWorker.register(SW_URL, { scope: SW_SCOPE });
    const sw = reg.installing || reg.waiting;
    if (!reg.active && sw) {
        await new Promise((resolve) => {
            sw.addEventListener('statechange', () => { if (sw.state === 'activated') resolve(); });
        });
    }
    return reg;
};

let syncPromise = null;

/**
 * يسجّل توكن هذا الجهاز عند الخادم. لا يطلب الإذن: يعمل فقط إن كان ممنوحاً من قبل.
 * مرة واحدة لكل تحميل للتطبيق، لا مع كل تنقل بين الصفحات.
 */
export const syncPushToken = ({ force = false } = {}) => {
    if (pushStatus() !== 'granted') return Promise.resolve(null);
    if (syncPromise && !force) return syncPromise;

    syncPromise = (async () => {
        const messaging = await getMessagingInstance();
        if (!messaging) return null;
        try {
            const { getToken } = await import('firebase/messaging');
            const token = await getToken(messaging, { vapidKey, serviceWorkerRegistration: await registerWorker() });
            if (!token) return null;
            if (force || store.get() !== token) {
                await axios.post('/api/fcm-token', { token, device_type: deviceType() });
                store.set(token);
            }
            return token;
        } catch (e) {
            console.warn('FCM token request failed:', e.message);
            syncPromise = null; // يُعاد المحاولة في التحميل التالي
            return null;
        }
    })();

    return syncPromise;
};

/** يطلب الإذن (يجب أن يُستدعى من ضغطة المستخدم — شرط Safari والآيفون) ثم يسجّل التوكن */
export const enablePush = async () => {
    const status = pushStatus();
    if (status === 'unsupported' || status === 'unconfigured') return status;
    const permission = status === 'granted' ? 'granted' : await Notification.requestPermission();
    if (permission !== 'granted') return permission;
    return (await syncPushToken({ force: true })) ? 'granted' : 'error';
};

/** توكن هذا الجهاز إن سُجّل في هذه الجلسة — يُرسل مع تسجيل الخروج ليُحذف */
export const currentPushToken = () => store.get();

/** بعد تسجيل الخروج: لا يُعاد استخدام توكن المستخدم السابق */
export const forgetPushToken = () => {
    store.clear();
    syncPromise = null;
};
