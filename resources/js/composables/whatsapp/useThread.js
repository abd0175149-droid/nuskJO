// دمج رسائل الخيط بلا تكرار وبالترتيب، وتحديثات الحالة (E04 T7، مستخرجة في E16)

export function lastId(messages) {
    return messages.length ? messages[messages.length - 1].id : 0;
}

/** الجديد بعد آخر رسالة: يعيد الرسائل المدمجة وما أُضيف فعلاً (للعدّاد «رسائل جديدة ↓») */
export function mergeNewer(messages, incoming) {
    const known = new Set(messages.map((m) => m.id));
    const fresh = (incoming || []).filter((m) => !known.has(m.id)).sort((a, b) => a.id - b.id);
    return { messages: fresh.length ? [...messages, ...fresh] : messages, fresh };
}

/** الأقدم في أعلى الخيط */
export function mergeOlder(messages, older) {
    const known = new Set(messages.map((m) => m.id));
    return [...(older || []).filter((m) => !known.has(m.id)).sort((a, b) => a.id - b.id), ...messages];
}

// حقول الحالة التي يعيدها الاستطلاع كاملة لكل رسالة تغيّرت: سبب E18، ومحاولات E19، وعلامات E21.
// ما غاب منها في التحديث يُمحى (فاشلة صارت مُسلّمة لا تُبقي سببها)
const STATUS_FIELDS = ['error_code', 'category', 'action', 'fbtrace_id', 'attempts', 'can_resend', 'status_at', 'multi_fail', 'meta_code'];

/** حالات المرسَل منذ آخر مزامنة (status وerror وحقولهما والمرفق) تُطبَّق في مكانها */
export function applyUpdates(messages, updates) {
    const byId = new Map(messages.map((m) => [m.id, m]));
    for (const u of updates || []) {
        const m = byId.get(u.id);
        if (!m) continue;
        m.status = u.status;
        m.error = u.error;
        for (const k of STATUS_FIELDS) {
            if (k in u) m[k] = u[k];
            else delete m[k];
        }
        if (u.media) m.media = u.media;
        // رسالة الموظف الصوتية (E34): التفريغ يصل بعد الإرسال فيُحدَّث نصها وحقلها
        if (u.voice) {
            m.voice = u.voice;
            if (typeof u.body === 'string') m.body = u.body;
        }
    }
    return messages;
}

/** ردّ الاستطلاع: gap يعني أن ما فات أكثر من طلب واحد فيُعاد فتح الخيط */
export function needsReload(data) {
    return !!data?.gap;
}
