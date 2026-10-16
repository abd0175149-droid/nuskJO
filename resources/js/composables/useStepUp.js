// تأكيد الهوية قبل فعل حساس (E27). نافذة واحدة (StepUpModal في AppLayout وEmployeeLayout) تفتحها حالتان:
//  - axios يتلقى 423 STEP_UP_REQUIRED: تُعرض النافذة، وبعد النجاح يُعاد الطلب الأصلي مرة واحدة فلا يلاحظ المستدعي شيئاً.
//  - زيارة Inertia غير GET عادت بعلامة flash.stepup_required: تُعاد آخر زيارة ملتقطة (الطريقة والرابط والبيانات).
// الالتقاط على مستوى الوحدة (app.js) لا المكوّن، لأن التخطيط يُعاد تركيبه مع كل زيارة.
// صفحة بلا مضيف للنافذة (الدخول مثلاً) يعود فيها الخطأ كما هو.
import { reactive } from 'vue';

// replayable: فُتحت من زيارة ملتقطة ستُعاد. viaFlash بلا replayable: تأكيد فقط ثم يعيد المستخدم الفعل بنفسه
export const stepUpState = reactive({ open: false, hosts: [], viaFlash: false, replayable: false });

let waiters = [];
let lastMutation = null;

/** يفتح النافذة ويعيد وعداً: true بعد التأكيد، false عند الإلغاء. الطلبات المتزامنة تنتظر النافذة نفسها */
export function requestStepUp() {
    return new Promise((resolve) => {
        waiters.push(resolve);
        stepUpState.open = true;
    });
}

/** تُنهي النافذةُ كل من ينتظرها بالنتيجة نفسها */
export function settleStepUp(ok) {
    stepUpState.open = false;
    const pending = waiters;
    waiters = [];
    pending.forEach((resolve) => resolve(!!ok));
}

/** مضيف مركّب (StepUpModal)؛ الأول وحده يعرض (مضيفان معاً لحظة تبديل الصفحة). يعيد معرّفه ودالة الفك */
export function registerStepUpHost() {
    const id = Symbol('stepup-host');
    stepUpState.hosts.push(id);
    return {
        id,
        release() {
            const i = stepUpState.hosts.indexOf(id);
            if (i !== -1) stepUpState.hosts.splice(i, 1);
        },
    };
}

/** آخر زيارة Inertia غير GET (router.on('before')) لإعادتها بعد التأكيد */
export function rememberVisit(visit) {
    const method = String(visit?.method || 'get').toLowerCase();
    if (method === 'get') return;
    const url = visit.url instanceof URL ? visit.url.href : String(visit.url || '');
    lastMutation = { url, method, data: visit.data ?? {} };
}

/**
 * بعد كل زيارة (router.on('navigate')): العلامة تفتح النافذة وتعيد الزيارة الملتقطة بعد التأكيد عبر $visit،
 * وزيارة بلا علامة تُسقط الملتقط فلا يُعاد لاحقاً خطأً.
 */
export function handleNavigate(page, visit) {
    const pending = lastMutation;
    lastMutation = null;
    if (!page?.props?.flash?.stepup_required || stepUpState.open) return;

    stepUpState.viaFlash = true;
    stepUpState.replayable = !!pending;
    requestStepUp().then((ok) => {
        stepUpState.viaFlash = false;
        stepUpState.replayable = false;
        if (ok && pending) visit(pending.url, { method: pending.method, data: pending.data, preserveScroll: true });
    });
}

/** يربط الالتقاط بموجّه Inertia مرة واحدة (app.js) */
export function installStepUpVisits(router) {
    router.on('before', (event) => rememberVisit(event.detail.visit));
    router.on('navigate', (event) => handleNavigate(event.detail.page, (url, options) => router.visit(url, options)));
}

/**
 * معترض axios: 423 STEP_UP_REQUIRED → النافذة ثم إعادة الطلب مرة واحدة. 401 MFA_REQUIRED (جلسة لم تجتز التحدّي)
 * و403 MFA_ENROLL_REQUIRED (التسجيل مفروض) يستدعيان معالجيهما.
 */
export function installStepUpInterceptor(axios, { onMfaRequired, onEnrollRequired } = {}) {
    return axios.interceptors.response.use(undefined, async (error) => {
        const response = error?.response;
        const code = response?.data?.code;
        const config = error?.config;
        if (response?.status === 423 && code === 'STEP_UP_REQUIRED' && config && !config.stepUpRetried && stepUpState.hosts.length > 0) {
            if (await requestStepUp()) {
                return axios.request({ ...config, stepUpRetried: true });
            }
        } else if (response?.status === 401 && code === 'MFA_REQUIRED') {
            onMfaRequired?.();
        } else if (response?.status === 403 && code === 'MFA_ENROLL_REQUIRED') {
            onEnrollRequired?.();
        }
        return Promise.reject(error);
    });
}
