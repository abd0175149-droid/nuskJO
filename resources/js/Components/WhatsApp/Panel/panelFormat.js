// تنسيق التواريخ والمبالغ في اللوحة الجانبية (C128): أرقام لاتينية وأسماء الأشهر كما يكتبها الأردن
const LOCALE = 'ar-JO-u-nu-latn';

/** «الخميس 20 تشرين الثاني» من تاريخ YYYY-MM-DD، والسنة تُضاف إن لم تكن الحالية */
export function day(iso) {
    if (!iso) return '';
    const d = new Date(`${String(iso).slice(0, 10)}T12:00:00`);
    if (Number.isNaN(d.getTime())) return String(iso);
    const opts = { weekday: 'short', day: 'numeric', month: 'long' };
    if (d.getFullYear() !== new Date().getFullYear()) opts.year = 'numeric';
    return d.toLocaleDateString(LOCALE, opts);
}

/** تاريخ قصير للصفوف: 20/11/2026 */
export function shortDay(iso) {
    if (!iso) return '';
    const d = new Date(`${String(iso).slice(0, 10)}T12:00:00`);
    return Number.isNaN(d.getTime()) ? String(iso) : d.toLocaleDateString(LOCALE, { day: '2-digit', month: '2-digit', year: 'numeric' });
}

/** «1,250.500» بثلاث خانات كالدينار */
export function jod(amount) {
    const n = Number(amount);
    return Number.isFinite(n) ? n.toLocaleString('en-US', { minimumFractionDigits: 3, maximumFractionDigits: 3 }) : String(amount ?? '');
}
