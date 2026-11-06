// ألوان الحالة والأوقات النسبية، مشتركة بين صفحات الحالة والعمليات

export const TONES = {
    green: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300 border-green-200 dark:border-green-800',
    amber: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300 border-amber-200 dark:border-amber-800',
    red: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300 border-red-200 dark:border-red-800',
    gray: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300 border-gray-200 dark:border-gray-700',
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
