// تصدير طلبات التسعير CSV (E24): المدى الافتراضي من أول الشهر إلى اليوم، والحد سنة واحدة (366 يوماً، يفحصه الخادم أيضاً)
const pad = (n) => String(n).padStart(2, '0');
export const isoDate = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

export function defaultRange(today = new Date()) {
    return { from: isoDate(new Date(today.getFullYear(), today.getMonth(), 1)), to: isoDate(today) };
}

/** خطأ عربي أو '' إن صح المدى */
export function rangeError(from, to) {
    if (!from || !to) return 'اختر تاريخي البداية والنهاية.';
    const a = new Date(`${from}T00:00:00`);
    const b = new Date(`${to}T00:00:00`);
    if (Number.isNaN(a.getTime()) || Number.isNaN(b.getTime())) return 'تاريخ غير صالح.';
    if (b < a) return 'تاريخ النهاية قبل البداية.';
    if ((b - a) / 86400000 > 366) return 'المدى سنة واحدة على الأكثر.';
    return '';
}

/** رابط التصدير بفلاتر الصفحة الحالية (الحالة والبحث) */
export function exportUrl({ from, to, status, search }) {
    const q = new URLSearchParams({ from, to });
    if (status) q.set('status', status);
    if (search) q.set('search', search);
    return `/quote-requests/export?${q.toString()}`;
}
