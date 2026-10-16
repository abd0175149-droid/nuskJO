import { onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * تحديثات البيانات عبر WebSocket على القناة الخاصة staff (E28؛ كانت عامة).
 * عند الإزالة يُزال مستمع هذه الصفحة وحده (stopListening) لا الاشتراك: صفحتان قد تشتركان في القناة.
 * @param {string[]} entities - أنواع الكيانات المراد الاستماع لها
 */
export function useRealtimeUpdates(entities = []) {
    let channel = null;
    const handler = (data) => {
        if (entities.includes(data?.entity)) {
            router.reload({ preserveScroll: true });
        }
    };

    onMounted(() => {
        if (!window.Echo) return;
        channel = window.Echo.private('staff');
        channel.listen('.data.updated', handler);
    });

    onUnmounted(() => {
        channel?.stopListening('.data.updated', handler);
        channel = null;
    });
}
