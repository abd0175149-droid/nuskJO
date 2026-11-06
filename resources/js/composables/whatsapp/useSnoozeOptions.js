// خيارات التأجيل (E17): لا نافذة 24 ساعة منذ OpenWA (2026-11-06)، فالتأجيل حتى 30 يوماً، ولا أقل من 10 دقائق.
// الخادم يفرض الحدود نفسها (InboxFollowup::snoozeLimit).
export const SNOOZE_MIN = 10;
export const SNOOZE_MAX = 30 * 24 * 60;

/** أبعد تأجيل بالدقائق من الآن */
export function maxSnoozeMinutes() {
    return SNOOZE_MAX;
}

/** @returns {Array<{key: string, label: string, minutes: number, disabled: boolean, hint: string}>} */
export function snoozeOptions() {
    const option = (key, label, minutes) => ({ key, label, minutes, disabled: false, hint: '' });

    return [
        option('h1', 'ساعة', 60),
        option('h3', '3 ساعات', 180),
        option('tomorrow', 'غداً', 24 * 60),
        option('week', 'أسبوع', 7 * 24 * 60),
    ];
}

/** حدود حقل الوقت المخصص (datetime-local بتوقيت الجهاز) */
export function customBounds(_ = null, now = new Date()) {
    const local = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    return {
        min: local(new Date(now.getTime() + SNOOZE_MIN * 60000)),
        max: local(new Date(now.getTime() + SNOOZE_MAX * 60000)),
    };
}
