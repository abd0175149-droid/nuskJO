// حالة الاتصال على مستوى الوحدة (E28): شريط عام واحد لانقطاع الخادم (ConnectionBanner)، وحالة المقبس للصندوق
// (InboxStatusBar)، وبطاقة «خطأ في الصفحة» (PageErrorCard)، و«تحديث متاح». بلا نص استثناء ولا HTML خام أبداً.
import { reactive, readonly } from 'vue';

/** ردود تعني أن النظام يُعاد تشغيله أو غير متاح (nginx وCloudflare أثناء النشر)، لا خطأ في الطلب نفسه */
export const SERVER_DOWN_STATUSES = [502, 503, 504, 520, 521, 522, 523, 524];
export const PROBE_MS = 10000;
/** connected | connecting | unavailable | failed | disabled */
export const REALTIME_STATES = ['connected', 'connecting', 'unavailable', 'failed', 'disabled'];

const state = reactive({ serverDown: false, since: null, realtime: 'disabled', pageError: false, updateAvailable: false });
let probe = null;
let fetchImpl = (...args) => globalThis.fetch(...args);

export const isServerDownStatus = (status) => SERVER_DOWN_STATUSES.includes(Number(status));

/** خطأ axios: بلا رد (شبكة أو مهلة) أو رد من القائمة. الإلغاء ليس انقطاعاً */
export function isServerDownError(error) {
    if (!error || error.__CANCEL__ || error.code === 'ERR_CANCELED') return false;
    if (!error.response) return true;
    return isServerDownStatus(error.response.status);
}

export function markServerDown() {
    if (!state.serverDown) {
        state.serverDown = true;
        state.since = Date.now();
    }
    startProbe();
}

export function markServerUp() {
    if (state.serverDown) {
        state.serverDown = false;
        state.since = null;
    }
    stopProbe();
}

export function setRealtime(value) {
    state.realtime = REALTIME_STATES.includes(value) ? value : 'unavailable';
}

export const markPageError = () => { state.pageError = true; };
export const clearPageError = () => { state.pageError = false; };
export const markUpdateAvailable = () => { state.updateAvailable = true; };

/** فحص /up: أي رد خارج القائمة يعني أن الخادم عاد (500 من فحص الصحة يعني أنه يجيب) */
export async function probeNow() {
    try {
        const res = await fetchImpl('/up', { cache: 'no-store', credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!isServerDownStatus(res.status)) markServerUp();
    } catch {
        // ما زال غير متاح
    }
}

function startProbe() {
    if (probe) return;
    probe = setInterval(() => {
        if (globalThis.document?.visibilityState !== 'hidden') probeNow();
    }, PROBE_MS);
}

function stopProbe() {
    clearInterval(probe);
    probe = null;
}

export function useConnectionStatus() {
    return { state: readonly(state), markServerDown, markServerUp, setRealtime, probeNow };
}

/** للاختبارات وحدها */
export function resetConnectionStatus({ fetch } = {}) {
    stopProbe();
    Object.assign(state, { serverDown: false, since: null, realtime: 'disabled', pageError: false, updateAvailable: false });
    fetchImpl = fetch || ((...args) => globalThis.fetch(...args));
}
