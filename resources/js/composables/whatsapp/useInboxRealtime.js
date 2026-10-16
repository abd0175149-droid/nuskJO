// الصندوق اللحظي (E28، العلم inbox_realtime): أحداث wa.changed على قناة المستخدم user.{id} (يشترك بها التخطيط)،
// وحمولتها رقم المحادثة ونوع التغيّر فقط، فكل جلب يمر بمسارات REST المسيّجة. لا يصفّر غير المقروء أبداً.
// - status: الخيط المفتوح وحده يُستطلع.
// - message أو meta: القائمة مرة واحدة بعد 400ms مهما تتابعت الأحداث، والخيط إن كانت المحادثة مفتوحة.
// - عودة الاتصال بعد انقطاع: القائمة والخيط (سد الفجوة).
// - الاستطلاع احتياطي: كل 60 ثانية متصلاً، وكل 15 منقطعاً أو والعلم مطفأ أو بلا Echo.
import { onMounted, onUnmounted } from 'vue';
import { setRealtime } from '@/composables/useConnectionStatus.js';

export const FALLBACK_CONNECTED_MS = 60000;
export const FALLBACK_DOWN_MS = 15000;
export const LIST_DEBOUNCE_MS = 400;

const UP = 'connected';
const DOWN = { unavailable: 'unavailable', failed: 'failed', disconnected: 'unavailable' };

/**
 * @param {object} o
 * @param {() => number|null} o.activeId المحادثة المفتوحة
 * @param {() => any} o.reloadList الصفحة الأولى من القائمة (useInbox.load)
 * @param {() => any} o.pollThread الجديد في الخيط المفتوح بـ after/since (useInbox.poll)
 * @param {{ setInterval(ms: number): void }} [o.poller] استطلاع E16
 * @param {boolean} o.enabled العلم من الخادم
 * @param {number|null} o.userId
 * @param {() => object|undefined} [o.echo]
 */
export function createInboxRealtime({ activeId, reloadList, pollThread, poller, enabled, userId, echo = () => globalThis.window?.Echo }) {
    let channel = null;
    let conn = null;
    let timer = null;
    let lost = false;

    const listSoon = () => {
        clearTimeout(timer);
        timer = setTimeout(() => { timer = null; reloadList(); }, LIST_DEBOUNCE_MS);
    };

    function handler(e) {
        const id = Number(e?.c);
        if (!id) return;
        if (e.k === 'status') {
            if (id === activeId()) pollThread();
            return;
        }
        listSoon();
        if (id === activeId()) pollThread();
    }

    function onState({ previous, current } = {}) {
        if (current === UP) {
            setRealtime('connected');
            poller?.setInterval(FALLBACK_CONNECTED_MS);
            if (lost) {
                lost = false;
                reloadList();
                pollThread();
            }
            return;
        }
        if (previous === UP || DOWN[current]) lost = true;
        if (DOWN[current]) {
            setRealtime(DOWN[current]);
            poller?.setInterval(FALLBACK_DOWN_MS);
        } else {
            setRealtime('connecting');
        }
    }

    // رفض الاشتراك (تفويض أو حد): المقبس متصل لكن لا أحداث، فالاستطلاع السريع
    function onSubscriptionError() {
        setRealtime('failed');
        poller?.setInterval(FALLBACK_DOWN_MS);
    }

    return {
        handler,
        onState,
        start() {
            const Echo = echo();
            if (!enabled || !Echo || !userId) {
                setRealtime('disabled');
                poller?.setInterval(FALLBACK_DOWN_MS);
                return false;
            }
            channel = Echo.private(`user.${userId}`);
            channel.listen('.wa.changed', handler);
            channel.subscription?.bind?.('pusher:subscription_error', onSubscriptionError);
            conn = Echo.connector?.pusher?.connection || null;
            conn?.bind?.('state_change', onState);
            // الحالة الآن: إن لم يكن متصلاً بعدُ فعند اتصاله تُجلب القائمة والخيط مرة
            const now = conn?.state || 'connecting';
            if (now !== UP) lost = true;
            onState({ previous: null, current: now });
            return true;
        },
        stop() {
            clearTimeout(timer);
            timer = null;
            // لا leave: التخطيط يملك الاشتراك في user.{id} (الجرس)
            channel?.stopListening?.('.wa.changed', handler);
            channel?.subscription?.unbind?.('pusher:subscription_error', onSubscriptionError);
            conn?.unbind?.('state_change', onState);
            channel = null;
            conn = null;
            poller?.setInterval(FALLBACK_DOWN_MS);
            setRealtime('disabled');
        },
    };
}

/** في مكوّن: يبدأ عند التركيب ويتوقف عند الإزالة */
export function useInboxRealtime(options) {
    const rt = createInboxRealtime(options);
    onMounted(() => rt.start());
    onUnmounted(() => rt.stop());
    return rt;
}
