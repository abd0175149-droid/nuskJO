// تسميات طبقة إعدادات الواتساب (E10) المشتركة بين صفحة الإعدادات والصندوق

// من أوقف الإرسال (WaBotSetting::suspendSource)
export const SUSPEND_SOURCES = {
    env: 'من بيئة الخادم (WA_SUSPENDED)',
    manual: 'إيقاف طارئ يدوي',
    meta_event: 'حدث من ميتا على الحساب',
    graph_error: 'رمز خطأ من ميتا عند الإرسال',
    restore: 'وضع آمن بعد استعادة نسخة',
};

// مصدر القيمة (WaBotSetting::sources)
export const SOURCES = {
    env: { label: 'من بيئة الخادم', cls: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300' },
    db: { label: 'محفوظ', cls: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300' },
    default: { label: 'الافتراضي', cls: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' },
    unset: { label: 'غير مضبوط', cls: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300' },
};

// سبب تغيير سر (SettingsController: routine,leak,setup,other)
export const SECRET_REASONS = {
    routine: 'تدوير دوري',
    leak: 'اشتباه تسرّب',
    setup: 'إعداد أول',
    other: 'سبب آخر',
};

export const when = (value) => (value ? new Date(value).toLocaleString('en-GB', { dateStyle: 'short', timeStyle: 'short' }) : '—');
