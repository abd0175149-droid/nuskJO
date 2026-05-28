import { onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Composable للاستماع لتحديثات البيانات عبر WebSocket
 * @param {string[]} entities - أنواع الكيانات المراد الاستماع لها
 */
export function useRealtimeUpdates(entities = []) {
    let channel = null;

    onMounted(() => {
        if (!window.Echo) return;
        
        channel = window.Echo.channel('nusuk-updates')
            .listen('.data.updated', (data) => {
                if (entities.includes(data.entity)) {
                    router.reload({ preserveScroll: true });
                }
            });
    });

    onUnmounted(() => {
        if (window.Echo && channel) {
            window.Echo.leave('nusuk-updates');
        }
    });
}
