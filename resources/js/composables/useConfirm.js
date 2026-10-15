// تأكيد وإدخال موحّدان بدل confirm() وprompt() في المتصفح (E16.9). مثيل واحد من ConfirmDialog يُركَّب في AppLayout
// وEmployeeLayout ويعرض الطلب الحالي؛ والطلبات المتزامنة تنتظر دورها. صفحة بلا تخطيط يحمله (نادر) تعود لنوافذ المتصفح.
import { reactive } from 'vue';

export const confirmState = reactive({ current: null, hosts: [] });
const queue = [];
let seq = 0;

function enqueue(request) {
    return new Promise((resolve) => {
        const item = { ...request, id: ++seq, resolve };
        if (!confirmState.hosts.length) {
            resolve(fallback(item));
            return;
        }
        if (confirmState.current) queue.push(item);
        else confirmState.current = item;
    });
}

function fallback(item) {
    const text = [item.title, item.message].filter(Boolean).join('\n\n');
    if (item.kind === 'prompt') {
        const value = window.prompt(item.label || text, item.value || '');
        return value === null ? null : value.trim();
    }
    return !!window.confirm(text);
}

/** يُنهي الطلب الحالي بنتيجته ويعرض التالي */
export function settle(result) {
    const current = confirmState.current;
    if (!current) return;
    confirmState.current = queue.shift() || null;
    current.resolve(result);
}

/**
 * @param {{title: string, message?: string, confirmLabel?: string, cancelLabel?: string, danger?: boolean}} options
 * @returns {Promise<boolean>}
 */
export function confirm(options) {
    return enqueue({ kind: 'confirm', confirmLabel: 'تأكيد', cancelLabel: 'إلغاء', danger: false, ...options });
}

/**
 * سبب الرفض وما يشبهه.
 * @param {{title: string, label: string, message?: string, required?: boolean, maxLength?: number, value?: string, confirmLabel?: string, danger?: boolean}} options
 * @returns {Promise<string|null>} النص بلا مسافات طرفية، أو null عند الإلغاء
 */
export function prompt(options) {
    return enqueue({ kind: 'prompt', confirmLabel: 'تأكيد', cancelLabel: 'إلغاء', required: false, maxLength: 500, value: '', danger: false, ...options });
}

export function useConfirm() {
    return { confirm, prompt };
}

/** يسجّل ConfirmDialog مضيفاً؛ الأول يعرض. يعيد دالة الإلغاء (عند مغادرة الصفحة يُلغى المعلّق) */
export function registerHost() {
    const id = Symbol('confirm-host');
    confirmState.hosts.push(id);
    return {
        id,
        release() {
            const i = confirmState.hosts.indexOf(id);
            if (i !== -1) confirmState.hosts.splice(i, 1);
            // بعد انتهاء الإزالة: لا يُعاد رسم نافذة يُزال مضيفها
            queueMicrotask(() => {
                if (confirmState.hosts.length) return;
                while (confirmState.current) settle(confirmState.current.kind === 'prompt' ? null : false);
            });
        },
    };
}
