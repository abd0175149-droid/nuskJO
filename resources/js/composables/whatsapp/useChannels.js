import { onMounted, onUnmounted } from 'vue';

/** حالات الجلسة على بوابة OpenWA بالعربية */
export const STATUS = {
    created: 'أُنشئ',
    initializing: 'تبدأ الجلسة',
    qr_ready: 'بانتظار مسح QR',
    authenticating: 'يتحقق',
    ready: 'متصل',
    disconnected: 'منقطع',
    action_required: 'يحتاج إجراء على الهاتف',
    failed: 'متعطّل',
};

export const ROLE_LABELS = { sales: 'مبيعات', support: 'دعم', notifications: 'إشعارات', admin: 'إدارة' };
export const BOT_MODE_LABELS = { full: 'البوت كامل', limited: 'البوت محدود (يحوّل فقط)', off: 'بلا بوت' };
export const GROUP_LABELS = { invoice: 'الفواتير والسندات', trip: 'الرحلات', quote: 'عروض الأسعار', lead: 'المتابعات', admin: 'ملخص الإدارة' };

export function statusLabel(s) {
    return STATUS[s] || s || '—';
}

/** لون شارة الحالة */
export function statusTone(c) {
    if (c.paused_until) return 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300';
    if (c.ready) return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300';
    if (c.status === 'qr_ready' || c.status === 'initializing' || c.status === 'authenticating') return 'bg-sky-100 text-sky-800 dark:bg-sky-900/30 dark:text-sky-300';
    return 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300';
}

/**
 * حدث wa.channel على القناة الخاصة staff (ChannelStatusChanged): معرّف الرقم وحالته ووصول QR، بلا QR ولا هاتف.
 * @param {(e: {channelId: number, status: string, qr: boolean}) => void} handler
 */
export function useChannelEvents(handler) {
    let channel = null;
    const listener = (e) => handler(e || {});
    onMounted(() => {
        if (!window.Echo) return;
        channel = window.Echo.private('staff');
        channel.listen('.wa.channel', listener);
    });
    onUnmounted(() => {
        channel?.stopListening('.wa.channel', listener);
        channel = null;
    });
}
