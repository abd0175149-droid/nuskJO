// الفقاعات المتفائلة لملفات الموظف (E32.10): فقاعة فوراً بمعاينة وشريط تقدم الرفع، ومفتاح client_key لكل محاولة يُرسل
// Idempotency-Key (منع التكرار في E19، لا عمود ثانٍ). الرد 202: «في الطابور» حتى يظهر صفها في الخيط فتُزال (الخيط يكمل
// الحالة: قيد الإرسال ثم أُرسلت أو فشلت أو غير مؤكد). انقطاع الشبكة: «إعادة المحاولة» بالمفتاح نفسه (ربما وصل الطلب،
// والخادم يعيد نتيجته الأولى). رفض الخادم: تُزال الفقاعة ويُعاد السبب، والمحاولة التالية فقاعة جديدة بمفتاح جديد.
import { reactive } from 'vue';
import { uuid } from './useSendKey.js';
import { clock } from './useVoiceRecorder.js';

const NETWORK = 'انقطع الاتصال أثناء رفع الملف — أعد المحاولة؛ لن يتكرر الملف إن كان وصل.';

/**
 * @param {{post: (convId: number, form: FormData, key: string, onProgress: Function) => Promise<{status: number, data: object}>,
 *          makeKey?: () => string, preview?: (file: File) => string|null, revoke?: (url: string) => void}} deps
 */
export function createOutbox({ post, makeKey = uuid, preview = defaultPreview, revoke = defaultRevoke }) {
    const items = reactive([]);

    function remove(item) {
        const i = items.indexOf(item);
        if (i !== -1) items.splice(i, 1);
        if (item.preview) revoke(item.preview);
    }

    function form(item, ack) {
        const fd = new FormData();
        fd.append('file', item.file, item.file?.name || item.name);
        fd.append('confirm_conversation_id', String(item.convId));
        // رسالة صوتية (E34): فرع voice في المسار نفسه، يحوّلها الخادم إلى ogg/opus
        if (item.voice) fd.append('voice', '1');
        if (item.caption) fd.append('caption', item.caption);
        if (item.docKind) fd.append('doc_kind', item.docKind);
        if (item.confirmIdentity) fd.append('confirm_identity', '1');
        if (item.replyTo) fd.append('reply_to_message_id', String(item.replyTo));
        if (ack) fd.append('ack_warnings', '1');
        return fd;
    }

    /** @returns {Promise<{ok: boolean, network?: boolean, error?: string, status?: number, code?: string}>} */
    async function attempt(item, confirmWarn, ack = false) {
        item.state = 'uploading';
        item.progress = 0;
        item.error = '';
        try {
            const { status, data } = await post(item.convId, form(item, ack), item.key, (e) => {
                item.progress = e?.total ? Math.min(100, Math.round((e.loaded * 100) / e.total)) : item.progress;
            });
            item.rowId = data?.row_id ?? item.rowId;
            item.warning = data?.warning || '';
            item.state = status === 200 ? 'sent' : 'queued';
            return { ok: true };
        } catch (e) {
            const res = e?.response;
            if (!res) {
                item.state = 'network';
                item.error = NETWORK;
                return { ok: false, network: true, error: NETWORK };
            }
            const data = res.data || {};
            if (res.status === 409 && data.code === 'POLICY_WARN' && confirmWarn && await confirmWarn(data.error)) {
                return attempt(item, confirmWarn, true);
            }
            remove(item);
            return { ok: false, status: res.status, code: data.code, error: errorOf(res) };
        }
    }

    return {
        items,
        forConversation: (id) => items.filter((i) => i.convId === id),
        /**
         * حمولة الحوار: {file, kind, caption, docKind, confirmIdentity, replyTo}. confirmWarn لتحذير السياسة (409).
         * الرسالة الصوتية (E34): {voice: true, key, seconds} بمفتاح التسجيل نفسه، فإعادة الإرسال بعد «المحوّل مشغول» لا تكرر
         */
        async send(convId, payload, confirmWarn) {
            const item = reactive({
                key: payload.key || makeKey(), convId, file: payload.file, voice: !!payload.voice,
                name: payload.voice ? `رسالة صوتية (${clock(payload.seconds)})` : (payload.file?.name || 'ملف'), size: payload.file?.size || 0,
                kind: payload.kind || 'document', caption: payload.caption || '', docKind: payload.docKind || '',
                confirmIdentity: !!payload.confirmIdentity, replyTo: payload.replyTo || null,
                preview: payload.kind === 'image' ? preview(payload.file) : null,
                state: 'uploading', progress: 0, error: '', warning: '', rowId: null,
            });
            items.push(item);
            return attempt(item, confirmWarn);
        },
        /** بعد انقطاع الشبكة وحده: المفتاح نفسه */
        retry(item, confirmWarn) {
            if (item.state !== 'network') return Promise.resolve({ ok: false });
            return attempt(item, confirmWarn);
        },
        dismiss: remove,
        /** صف الخيط ظهر برقمه: الخيط يتولى الحالة من هنا */
        reconcile(convId, messages) {
            const ids = new Set((messages || []).map((m) => m.id));
            [...items].filter((i) => i.convId === convId && i.rowId && ids.has(i.rowId)).forEach(remove);
        },
    };
}

function errorOf(res) {
    const d = res?.data || {};
    const text = d.error || Object.values(d.errors || {})[0]?.[0] || d.message;
    if (res?.status === 413) return d.error || 'الملف أكبر من حد الخادم.';
    if (res?.status === 403 && !/[؀-ۿ]/.test(text || '')) return 'ليست لديك صلاحية لهذا الإجراء';
    return text ? (d.action ? `${text} — ${d.action}` : text) : 'تعذّر إرسال الملف';
}

function defaultPreview(file) {
    try {
        return file && globalThis.URL?.createObjectURL ? URL.createObjectURL(file) : null;
    } catch {
        return null;
    }
}

function defaultRevoke(url) {
    try {
        globalThis.URL?.revokeObjectURL?.(url);
    } catch {
        // لا شيء
    }
}
