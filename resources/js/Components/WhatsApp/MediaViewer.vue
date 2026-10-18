<template>
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-3" @keydown.esc="close" @click.self="close">
        <div ref="panel" class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-4xl max-h-[92vh] flex flex-col" dir="rtl"
             role="dialog" aria-modal="true" aria-labelledby="media-viewer-title" tabindex="-1">
            <div class="flex items-center justify-between gap-2 px-4 py-2.5 border-b border-gray-200 dark:border-gray-700">
                <h3 id="media-viewer-title" class="text-sm font-bold text-gray-800 dark:text-gray-100 truncate"><bdi dir="auto">{{ media?.name || 'مرفق' }}</bdi></h3>
                <div class="flex items-center gap-1.5 shrink-0">
                    <template v-if="kind === 'image' && url">
                        <button type="button" @click="zoom = Math.min(zoom + 0.25, 4)" aria-label="تكبير" class="px-2 py-1 rounded-lg text-sm border border-gray-200 dark:border-gray-700 dark:text-gray-100">＋</button>
                        <button type="button" @click="zoom = Math.max(zoom - 0.25, 0.5)" aria-label="تصغير" class="px-2 py-1 rounded-lg text-sm border border-gray-200 dark:border-gray-700 dark:text-gray-100">－</button>
                        <button type="button" @click="rotation = (rotation + 90) % 360" aria-label="تدوير" class="px-2 py-1 rounded-lg text-sm border border-gray-200 dark:border-gray-700 dark:text-gray-100">⟳</button>
                    </template>
                    <template v-if="kind === 'pdf' && pages">
                        <button type="button" :disabled="page <= 1" @click="go(page - 1)" aria-label="الصفحة السابقة" class="px-2 py-1 rounded-lg text-sm border border-gray-200 dark:border-gray-700 dark:text-gray-100 disabled:opacity-40">→</button>
                        <span class="text-xs text-gray-600 dark:text-gray-300" dir="ltr">{{ page }} / {{ pages }}</span>
                        <button type="button" :disabled="page >= pages" @click="go(page + 1)" aria-label="الصفحة التالية" class="px-2 py-1 rounded-lg text-sm border border-gray-200 dark:border-gray-700 dark:text-gray-100 disabled:opacity-40">←</button>
                    </template>
                    <button ref="closeBtn" type="button" @click="close" aria-label="إغلاق" class="px-2 py-1 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">✕</button>
                </div>
            </div>

            <div class="relative flex-1 min-h-[40vh] overflow-auto bg-gray-100 dark:bg-gray-950 flex items-start justify-center p-3">
                <p v-if="loading" class="self-center text-sm text-gray-500 dark:text-gray-400">جارٍ التحميل...</p>
                <p v-else-if="error" class="self-center text-sm text-red-600 dark:text-red-400" role="alert">❌ {{ error }}</p>
                <img v-else-if="kind === 'image' && url" :src="url" alt="مرفق من العميل" draggable="false"
                     class="max-w-full transition-transform select-none" :style="{ transform: `scale(${zoom}) rotate(${rotation}deg)` }"/>
                <canvas v-show="kind === 'pdf' && !loading && !error" ref="canvas" class="max-w-full shadow bg-white"></canvas>
                <!-- علامة مائية: اسم الموظف والوقت، فوق المحتوى ولا تلتقط النقر -->
                <div v-if="watermark && !loading && !error" class="pointer-events-none absolute inset-0 overflow-hidden select-none" aria-hidden="true">
                    <div class="absolute inset-0 flex flex-wrap content-around justify-around -rotate-12 opacity-[0.12] text-gray-900 dark:text-white text-sm font-bold">
                        <span v-for="i in 12" :key="i" class="px-6 py-8 whitespace-nowrap">{{ watermark }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onUnmounted } from 'vue';
import axios from 'axios';
import { mediaUrl, fetchError } from '@/utils/media.js';
import { loadPdfJs } from '@/composables/usePdfTemplate.js';

// عارض المرفقات (E14.7): الملف يُجلب blob عند الطلب ويُعرض داخل الصفحة (صورة بـ img، وPDF بـ pdf.js على canvas بلا eval).
// لا شيء inline من المتصفح، ولا يُخزَّن الملف في أي مخزن للمتصفح، ويُحرَّر كل شيء عند الإغلاق.
const props = defineProps({
    open: Boolean,
    messageId: { type: Number, default: null },
    media: { type: Object, default: null },
    watermark: { type: String, default: '' },
});
const emit = defineEmits(['close']);

const panel = ref(null);
const closeBtn = ref(null);
const canvas = ref(null);
const url = ref('');
const loading = ref(false);
const error = ref('');
const zoom = ref(1);
const rotation = ref(0);
const page = ref(1);
const pages = ref(0);
let pdfDoc = null;
let renderTask = null;

const kind = computed(() => (props.media?.mime === 'application/pdf' ? 'pdf' : (props.media?.mime || '').startsWith('image/') ? 'image' : 'other'));

const release = () => {
    if (url.value) URL.revokeObjectURL(url.value);
    url.value = '';
    renderTask?.cancel?.();
    renderTask = null;
    pdfDoc?.destroy?.();
    pdfDoc = null;
    if (canvas.value) {
        canvas.value.getContext('2d')?.clearRect(0, 0, canvas.value.width, canvas.value.height);
        canvas.value.width = 0;
        canvas.value.height = 0;
    }
    pages.value = 0;
    page.value = 1;
    zoom.value = 1;
    rotation.value = 0;
};

const renderPage = async (n) => {
    if (!pdfDoc || !canvas.value) return;
    renderTask?.cancel?.();
    const p = await pdfDoc.getPage(n);
    const viewport = p.getViewport({ scale: Math.min(2, (canvas.value.parentElement?.clientWidth || 800) / p.getViewport({ scale: 1 }).width) });
    canvas.value.width = Math.floor(viewport.width);
    canvas.value.height = Math.floor(viewport.height);
    renderTask = p.render({ canvasContext: canvas.value.getContext('2d'), canvas: canvas.value, viewport });
    try { await renderTask.promise; } catch { /* أُلغي بتغيير الصفحة */ }
};

const go = async (n) => {
    page.value = n;
    await renderPage(n);
};

const load = async () => {
    release();
    error.value = '';
    loading.value = true;
    try {
        const { data } = await axios.get(mediaUrl(props.messageId), { responseType: 'blob', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (kind.value === 'pdf') {
            // تحميل كسول: pdf.js لا يدخل حزمة الصندوق إلا عند فتح أول PDF. المحمّل المشترك (البناء legacy، ورابط العامل
            // بإصداره فلا يبقى المتصفح على رد قديم)
            const pdfjs = await loadPdfJs();
            pdfDoc = await pdfjs.getDocument({ data: new Uint8Array(await data.arrayBuffer()), isEvalSupported: false, enableXfa: false }).promise;
            pages.value = pdfDoc.numPages;
            loading.value = false;
            await nextTick();
            await renderPage(1);
        } else {
            url.value = URL.createObjectURL(data);
        }
    } catch (e) {
        error.value = e.response ? fetchError(e.response.status) : 'تعذّر عرض الملف';
    } finally {
        loading.value = false;
    }
};

const close = () => {
    release();
    emit('close');
};

watch(() => [props.open, props.messageId], async ([open]) => {
    if (open && props.messageId) {
        await nextTick();
        closeBtn.value?.focus();
        load();
    } else {
        release();
    }
});

onUnmounted(release);
</script>
