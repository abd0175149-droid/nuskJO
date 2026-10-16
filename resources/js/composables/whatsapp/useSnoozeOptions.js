// خيارات التأجيل (E17): لا تأجيل بعد إغلاق نافذة الـ24 ساعة ناقص ربع ساعة (لا قوالب لدينا)، ولا أقل من 10 دقائق.
// الخادم يفرض الحدود نفسها؛ هنا تُعطَّل الخيارات التي سيرفضها ويُشرح السبب.
export const SNOOZE_MARGIN = 15;
export const SNOOZE_MIN = 10;

/** أبعد تأجيل بالدقائق من الآن، أو 0 إن لم يعد التأجيل ممكناً */
export function maxSnoozeMinutes(windowLeft) {
    if (windowLeft === null || windowLeft === undefined) return 0;
    const max = Number(windowLeft) - SNOOZE_MARGIN;
    return max >= SNOOZE_MIN ? max : 0;
}

/**
 * @param windowLeft الدقائق الباقية في نافذة الرد (null = مغلقة)
 * @returns {Array<{key: string, label: string, minutes: number, disabled: boolean, hint: string}>} فارغة للنافذة المغلقة
 */
export function snoozeOptions(windowLeft) {
    const max = maxSnoozeMinutes(windowLeft);
    if (!max) return [];
    const hint = 'لا يمكن التأجيل بعد إغلاق النافذة';
    const option = (key, label, minutes) => ({
        key, label, minutes,
        disabled: minutes < SNOOZE_MIN || minutes > max,
        hint: minutes > max ? hint : '',
    });

    return [
        option('h1', 'ساعة', 60),
        option('h3', '3 ساعات', 180),
        option('before_close', 'قبل إغلاق النافذة بـ30 دقيقة', Number(windowLeft) - 30),
    ];
}

/** حدود حقل الوقت المخصص (datetime-local بتوقيت الجهاز) */
export function customBounds(windowLeft, now = new Date()) {
    const max = maxSnoozeMinutes(windowLeft);
    if (!max) return null;
    const local = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    return {
        min: local(new Date(now.getTime() + SNOOZE_MIN * 60000)),
        max: local(new Date(now.getTime() + max * 60000)),
    };
}
