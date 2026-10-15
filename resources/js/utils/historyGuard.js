// اعتراض popstate قبل Inertia (E16). Inertia يعيد تركيب الصفحة عند كل رجوع (preserveState: false)، فصفحة تدير
// خطواتها في history بنفسها (الصندوق على الجوال: القائمة ← المحادثة) تسجّل هنا معالجاً يعيد true لما يخصّها.
// يُستورد في app.js قبل createInertiaApp: مستمعو window يُستدعون بترتيب تسجيلهم، فيسبق هذا مستمع Inertia.
const handlers = new Set();

export function installHistoryGuard(target = window) {
    target.addEventListener('popstate', (e) => {
        for (const handle of [...handlers]) {
            if (handle(e) === true) {
                e.stopImmediatePropagation();
                return;
            }
        }
    });
}

/** @returns دالة لإلغاء التسجيل (عند مغادرة الصفحة) */
export function onPopState(handle) {
    handlers.add(handle);
    return () => handlers.delete(handle);
}
