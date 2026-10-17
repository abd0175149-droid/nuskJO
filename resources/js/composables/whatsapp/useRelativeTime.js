// الوقت النسبي لآخر رسالة بالعربية (E16.8): كان نص diffForHumans الإنجليزي من الخادم فيُقلب في سياق RTL («minutes ago 18»).
// الأرقام لاتينية كبقية الصندوق، وبلا Intl أو بلا وقت يعود إلى نص الخادم.
const STEPS = [
    ['second', 60],
    ['minute', 60],
    ['hour', 24],
    ['day', 7],
    ['week', 4.345],
    ['month', 12],
    ['year', Infinity],
];

let fmt = null;
function formatter() {
    if (fmt === null) {
        try {
            fmt = new Intl.RelativeTimeFormat('ar-u-nu-latn', { numeric: 'auto' });
        } catch {
            fmt = false;
        }
    }
    return fmt;
}

/** @param ts ثوانٍ (sort_ts)، @param now ميلي ثانية */
export function relativeTime(ts, fallback = '', now = Date.now()) {
    const f = formatter();
    if (!ts || !f) return fallback || '';
    let value = Math.min(0, ts - Math.floor(now / 1000));
    if (value > -45) return f.format(0, 'second');
    for (const [unit, size] of STEPS) {
        if (Math.abs(value) < size) return f.format(Math.round(value), unit);
        value /= size;
    }
    return fallback || '';
}
