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

/** حالات المرسَل منذ آخر مزامنة (status وerror والمرفق) تُطبَّق في مكانها */
export function applyUpdates(messages, updates) {
    const byId = new Map(messages.map((m) => [m.id, m]));
    for (const u of updates || []) {
        const m = byId.get(u.id);
        if (!m) continue;
        m.status = u.status;
        m.error = u.error;
        if (u.media) m.media = u.media;
    }
    return messages;
}

/** ردّ الاستطلاع: gap يعني أن ما فات أكثر من طلب واحد فيُعاد فتح الخيط */
export function needsReload(data) {
    return !!data?.gap;
}
