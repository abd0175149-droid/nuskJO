// الالتصاق بأسفل الخيط وحفظ موضع القراءة عند تحميل الأقدم (E04 T7، مستخرجة في E16)

export function isNearBottom(el, threshold = 150) {
    return !el || el.scrollHeight - el.scrollTop - el.clientHeight < threshold;
}

export function scrollToBottom(el) {
    if (el) el.scrollTop = el.scrollHeight;
}

/**
 * يضيف رسائل في أعلى الخيط دون أن يقفز ما يقرؤه الموظف: يقيس الارتفاع قبل الإضافة ويزيح التمرير بالفرق بعدها.
 * mutate تضيف الرسائل، وtick تنتظر رسم Vue (nextTick).
 */
export async function preserveOnPrepend(el, mutate, tick) {
    const before = el ? el.scrollHeight : 0;
    await mutate();
    if (tick) await tick();
    if (el) el.scrollTop += el.scrollHeight - before;
}
