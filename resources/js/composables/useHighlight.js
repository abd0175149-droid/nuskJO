import { ref, onMounted, nextTick } from 'vue';

/**
 * Composable للتعامل مع تأثير التوهج على الصف المحدد من الإشعار
 * يقرأ highlight من الـ URL ويطبق glow على الصف المطابق
 */
export function useHighlight() {
    const highlightId = ref(null);

    onMounted(() => {
        const params = new URLSearchParams(window.location.search);
        const id = params.get('highlight');
        if (id) {
            highlightId.value = parseInt(id);
            // Scroll to the highlighted row after render
            nextTick(() => {
                setTimeout(() => {
                    const el = document.querySelector(`[data-row-id="${id}"]`);
                    if (el) {
                        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }, 300);
                // إزالة التوهج بعد 5 ثواني
                setTimeout(() => {
                    highlightId.value = null;
                }, 5000);
            });
        }
    });

    const isHighlighted = (rowId) => highlightId.value === rowId;

    return { highlightId, isHighlighted };
}
