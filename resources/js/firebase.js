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

const keyBytes = (b64url) => {
    const b64 = b64url.replace(/-/g, '+').replace(/_/g, '/');
    return Uint8Array.from(atob(b64 + '='.repeat((4 - (b64.length % 4)) % 4)), (c) => c.charCodeAt(0));
};
const sameKey = (buffer, b64url) => {
    const a = new Uint8Array(buffer);
    const b = keyBytes(b64url);
    return a.length === b.length && a.every((v, i) => v === b[i]);
};

// Firebase يعيد استعمال أي اشتراك قائم في النطاق مهما كان مفتاحه، فيُصدر توكناً لا تصله رسالة.
// الكود السابق كان يطلب الإذن تلقائياً ويشترك هنا بمفتاح Firebase الافتراضي — حال أغلب أجهزة الكمبيوتر.
// اشتراك بمفتاح غير مفتاحنا يُلغى، و reset يلغيه في كل حال (زر «إعادة المحاولة»)
const dropStaleSubscription = async (reg, reset) => {
    const sub = await reg.pushManager.getSubscription();
    const key = sub?.options?.applicationServerKey;
    if (sub && (reset || (key && !sameKey(key, vapidKey)))) {
        await sub.unsubscribe();
    }
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
let lastError = null;

/**
 * يسجّل توكن هذا الجهاز عند الخادم. لا يطلب الإذن: يعمل فقط إن كان ممنوحاً من قبل.
 * مرة واحدة لكل تحميل للتطبيق، لا مع كل تنقل بين الصفحات.
 * reset: اشتراك وتوكن جديدان من الصفر — لجهاز لا تصله الإشعارات رغم تفعيلها.
 */
export const syncPushToken = ({ force = false, reset = false } = {}) => {
    if (pushStatus() !== 'granted') return Promise.resolve(null);
    if (syncPromise && !force) return syncPromise;

    syncPromise = (async () => {
        lastError = null;
        const messaging = await getMessagingInstance();
        if (!messaging) {
            lastError = 'هذا المتصفح لا يدعم إشعارات Firebase';
            syncPromise = null;
            return null;
        }
        try {
            const { getToken } = await import('firebase/messaging');
            const reg = await registerWorker();
            await dropStaleSubscription(reg, reset);
            const token = await getToken(messaging, { vapidKey, serviceWorkerRegistration: reg });
            if (!token) return null;
            if (force || store.get() !== token) {
                await axios.post('/api/fcm-token', { token, device_type: deviceType() });
                store.set(token);
            }
            return token;
        } catch (e) {
            console.warn('FCM token request failed:', e.message);
            lastError = e.message;
            syncPromise = null; // يُعاد المحاولة في التحميل التالي
            return null;
        }
    })();

    return syncPromise;
};

/** يطلب الإذن (يجب أن يُستدعى من ضغطة المستخدم — شرط Safari والآيفون) ثم يسجّل التوكن */
export const enablePush = async ({ reset = false } = {}) => {
    const status = pushStatus();
    if (status === 'unsupported' || status === 'unconfigured') return status;
    const permission = status === 'granted' ? 'granted' : await Notification.requestPermission();
    if (permission !== 'granted') return permission;
    return (await syncPushToken({ force: true, reset })) ? 'granted' : 'error';
};

/** سبب فشل آخر محاولة تسجيل (نص المتصفح أو Firebase) — يُعرض تحت «إعادة المحاولة» */
export const pushError = () => lastError;

/** إشعار تجريبي لهذا الجهاز وحده؛ يرمي خطأ axios برسالة الخادم إن رفضه FCM */
export const sendTestPush = async () => {
    const token = await syncPushToken();
    if (!token) throw new Error(lastError || 'هذا الجهاز غير مسجّل للإشعارات');
    return axios.post('/api/fcm-token/test', { token });
};

/** توكن هذا الجهاز إن سُجّل في هذه الجلسة — يُرسل مع تسجيل الخروج ليُحذف */
export const currentPushToken = () => store.get();

/** بعد تسجيل الخروج: لا يُعاد استخدام توكن المستخدم السابق */
export const forgetPushToken = () => {
    store.clear();
    syncPromise = null;
};
