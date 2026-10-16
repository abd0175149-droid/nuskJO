// معالجة الانقطاع والأخطاء على مستوى التطبيق (E28، العلم connection_resilience عبر features.resilience):
// - 502/503/504/52x من زيارة Inertia: شريط «النظام يُعاد تشغيله» بدل نافذة Inertia بصفحة nginx أو Cloudflare الخام.
// - خطأ شبكة في زيارة أو في axios: الشريط نفسه. والخطأ يصل صاحبه كما هو (لا يُبتلع).
// - فشل تحميل جزء بعد النشر: إعادة تحميل واحدة (reloadOnce)، أو «تحديث متاح» فوق نموذج غير محفوظ.
// - خطأ في مكوّن: بطاقة «حدث خطأ في هذه الصفحة» بدل الصفحة البيضاء.
import {
    clearPageError, isServerDownError, isServerDownStatus, markPageError, markServerDown, markServerUp, markUpdateAvailable,
} from '@/composables/useConnectionStatus.js';
import { hasDirtyForm, isChunkLoadError, reloadOnce } from './reloadOnce.js';

let enabled = false;

export const resilienceEnabled = () => enabled;

/** من خصائص الصفحة المشتركة (features.resilience) عند الإقلاع وعند كل تنقّل */
export function setResilience(on) {
    enabled = !!on;
}

/** فشل تحميل صفحة: إعادة تحميل واحدة، وإلا يُرمى الخطأ لصاحبه */
export function recoverPageLoad(error, { win = window } = {}) {
    if (!enabled || !isChunkLoadError(error)) return false;
    if (hasDirtyForm(win)) {
        markUpdateAvailable();
        return false;
    }
    if (reloadOnce('page', { win })) return true;
    markUpdateAvailable();
    return false;
}

export function installConnectionGuard({ router, axios, app, win = window }) {
    router.on('navigate', (event) => {
        setResilience(event?.detail?.page?.props?.features?.resilience);
        clearPageError();
    });

    router.on('httpException', (event) => {
        if (enabled && isServerDownStatus(event?.detail?.response?.status)) {
            markServerDown();
            return false;
        }
    });

    router.on('networkError', (event) => {
        if (!enabled) return;
        if (isChunkLoadError(event?.detail?.error)) {
            markUpdateAvailable();
            return false;
        }
        markServerDown();
        return false;
    });

    router.on('success', () => {
        if (enabled) markServerUp();
    });

    axios.interceptors.response.use(
        (response) => {
            if (enabled) markServerUp();
            return response;
        },
        (error) => {
            if (enabled && isServerDownError(error)) markServerDown();
            return Promise.reject(error);
        },
    );

    if (app) {
        app.config.errorHandler = (error) => {
            console.error(error);
            // أخطاء الشبكة يغطيها الشريط؛ البطاقة لخطأ في الصفحة نفسها
            if (enabled && !error?.isAxiosError) markPageError();
        };
    }

    win.addEventListener('vite:preloadError', (event) => {
        if (!enabled) return;
        if (hasDirtyForm(win)) {
            event.preventDefault();
            markUpdateAvailable();
            return;
        }
        if (reloadOnce('chunk', { win })) event.preventDefault();
        else markUpdateAvailable();
    });
}
