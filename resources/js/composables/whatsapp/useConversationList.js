// قائمة المحادثات بصفحات (E16.6): الاستطلاع يعيد الصفحة الأولى فقط، فتُدمج مع ما حُمّل بـ«تحميل المزيد» بالمعرّف:
// ما صعد إلى الصفحة الأولى يُزال من موضعه السفلي، وما بعد آخر صف فيها يبقى كما حُمّل.

/** المثبّتة أولاً (E17، تأتي أعلى الصفحة الأولى خارج الترقيم)، ثم الأحدث: (sort_ts, id) تنازلياً */
export function compareRows(a, b) {
    return (b.pinned ? 1 : 0) - (a.pinned ? 1 : 0) || (b.sort_ts || 0) - (a.sort_ts || 0) || b.id - a.id;
}

/** الصفحة الأولى الجديدة مع ما بعدها مما حُمّل سابقاً */
export function mergeFirstPage(current, page, pageHasMore) {
    if (!pageHasMore || !page.length) return [...page];
    const tail = page[page.length - 1];
    const inPage = new Set(page.map((c) => c.id));
    const older = current.filter((c) => !inPage.has(c.id) && compareRows(tail, c) < 0);
    return [...page, ...older].sort(compareRows);
}

/** صفحة لاحقة تُلحق بلا تكرار */
export function appendPage(current, page) {
    const known = new Set(current.map((c) => c.id));
    return [...current, ...page.filter((c) => !known.has(c.id))];
}

/** مؤشر الصفحة التالية من آخر صف معروض */
export function cursorOf(rows) {
    const last = rows[rows.length - 1];
    return last ? `${last.sort_ts || 0}:${last.id}` : null;
}

/** أيقونة نوع آخر رسالة في القائمة */
export const LAST_TYPE_ICONS = {
    image: '📷', document: '📄', audio: '🎤', voice: '🎤', video: '🎬', location: '📍',
    contacts: '👤', contact: '👤', reaction: '↩️', sticker: '🖼️',
};

export function lastTypeIcon(type) {
    return LAST_TYPE_ICONS[type] || '';
}
