// صحة القناة كآلة حالات (E22): تسميات مشتركة بين بطاقة الإعدادات وشريط الصندوق

// حالة القناة عند ميتا (App\Enums\WaHealth): التسمية واللون
export const HEALTH_STATES = {
    AVAILABLE: { label: 'تعمل', icon: '🟢', tone: 'green' },
    LIMITED: { label: 'قيد جزئي — ردود العملاء تعمل', icon: '🟠', tone: 'amber' },
    BLOCKED: { label: 'محجوبة من ميتا', icon: '⛔', tone: 'red' },
    TOKEN_INVALID: { label: 'رمز غير صالح', icon: '🔑', tone: 'red' },
    UNKNOWN: { label: 'لم تُفحص بعد', icon: '⚪', tone: 'gray' },
};

export const TONES = {
    green: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300 border-green-200 dark:border-green-800',
    amber: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300 border-amber-200 dark:border-amber-800',
    red: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300 border-red-200 dark:border-red-800',
    gray: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300 border-gray-200 dark:border-gray-700',
};

export const healthState = (state) => HEALTH_STATES[state] || HEALTH_STATES.UNKNOWN;

// أحداث حساب ميتا (wa_account_events)
export const EVENT_FIELDS = {
    account_update: 'تحديث الحساب',
    account_alerts: 'تنبيه الحساب',
    security: 'الأمان',
    business_capability_update: 'قدرات الحساب',
    phone_number_quality_update: 'جودة الرقم',
    phone_number_name_update: 'اسم الرقم',
    account_review_update: 'مراجعة الحساب',
};

export const SEVERITIES = {
    critical: { label: 'حرج', tone: 'red' },
    warn: { label: 'تحذير', tone: 'amber' },
    info: { label: 'معلومة', tone: 'gray' },
};

export const EFFECTS = {
    none: 'لا شيء',
    alert: 'تنبيه',
    poll: 'فحص من ميتا',
    limited: 'قيد جزئي',
    blocked: 'حجب',
    unblock_suggested: 'اقتراح رفع',
    ignored_foreign: 'لحساب أو رقم آخر',
};

export const QUALITY = {
    GREEN: { label: 'خضراء', tone: 'green' },
    YELLOW: { label: 'صفراء', tone: 'amber' },
    RED: { label: 'حمراء', tone: 'red' },
};

// «قبل N د» من وقت ISO (null: لم يحدث)
export function ago(iso, now = Date.now()) {
    if (!iso) return null;
    const minutes = Math.max(0, Math.floor((now - new Date(iso).getTime()) / 60000));
    if (minutes < 1) return 'قبل أقل من دقيقة';
    if (minutes < 60) return `قبل ${minutes} د`;
    const hours = Math.floor(minutes / 60);
    return hours < 48 ? `قبل ${hours} س` : `قبل ${Math.floor(hours / 24)} يوماً`;
}

// الوقت بتوقيت عمّان (الأحداث والانتهاء)
export function amman(iso) {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleString('en-GB', { timeZone: 'Asia/Amman', dateStyle: 'short', timeStyle: 'short' });
    } catch {
        return iso;
    }
}

// شارة الصندوق لمن يملك whatsapp.view: نص عام بلا سبب ولا رمز
export function inboxBadge(status) {
    if (!status || status.suspended) return null;
    if (status.health === 'BLOCKED') {
        return { tone: 'red', text: `⛔ ميتا تحجب الإرسال${status.health_until ? ` حتى ${amman(status.health_until)}` : ''} — أبلغ المدير.` };
    }
    if (status.health === 'TOKEN_INVALID') {
        return { tone: 'red', text: `🔑 رمز ميتا غير صالح — أبلغ المدير. ${status.health_blocks ? 'الإرسال متوقف.' : 'قد يفشل الإرسال.'}` };
    }
    if (status.health === 'LIMITED') {
        return { tone: 'amber', small: true, text: 'قيد جزئي من ميتا — الردود تعمل' };
    }
    return null;
}
