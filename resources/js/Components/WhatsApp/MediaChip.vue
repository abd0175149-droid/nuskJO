<template>
    <div class="mt-1.5 rounded-xl border border-black/10 dark:border-white/10 bg-black/5 dark:bg-white/5 px-2.5 py-1.5 text-[11px]">
        <div class="flex items-center gap-1.5 min-w-0">
            <span aria-hidden="true">{{ KIND_ICONS[media.kind] || '📎' }}</span>
            <bdi dir="auto" class="truncate font-medium">{{ media.name || kindLabel }}</bdi>
            <span v-if="media.size" class="shrink-0 opacity-70" dir="ltr">{{ formatSize(media.size) }}</span>
        </div>
        <p v-if="label" class="mt-0.5 opacity-80">{{ label }}</p>
        <!-- ملف مرسل حُذف من خوادم ميتا بعد قراءته (E32.12): نسختنا باقية، والعميل لا يعيد تنزيله -->
        <p v-if="media.remote_deleted" class="mt-0.5 opacity-80" data-test="remote-deleted">🗑️ حُذف من خوادم واتساب بعد القراءة</p>

        <div v-if="media.viewable || media.refetchable" class="mt-1 flex flex-wrap items-center gap-1.5">
            <button v-if="media.viewable && media.previewable" type="button" @click="$emit('view')"
                    class="px-2 py-0.5 rounded-lg bg-white/80 dark:bg-gray-800 text-gray-800 dark:text-gray-100 border border-gray-200 dark:border-gray-700 hover:border-gold-400">
                👁️ عرض
            </button>
            <button v-if="media.viewable" type="button" :disabled="busy" @click="download"
                    class="px-2 py-0.5 rounded-lg bg-white/80 dark:bg-gray-800 text-gray-800 dark:text-gray-100 border border-gray-200 dark:border-gray-700 hover:border-gold-400 disabled:opacity-50">
                {{ busy ? '...' : '⬇️ تنزيل' }}
            </button>
            <template v-if="media.refetchable">
                <button v-if="!confirming" type="button" @click="confirming = true"
                        class="px-2 py-0.5 rounded-lg bg-white/80 dark:bg-gray-800 text-gray-800 dark:text-gray-100 border border-gray-200 dark:border-gray-700 hover:border-gold-400">
                    ⬇️ تنزيل يدوي (حتى 100MB)
                </button>
                <span v-else class="flex flex-wrap items-center gap-1.5" role="group" aria-label="تأكيد التنزيل اليدوي">
                    <span class="opacity-90">سيأخذ الملف مساحة كبيرة على الخادم. متأكد؟</span>
                    <button type="button" :disabled="busy" @click="refetch" class="px-2 py-0.5 rounded-lg font-bold text-black bg-gold-400 disabled:opacity-50">{{ busy ? '...' : 'نعم' }}</button>
                    <button type="button" @click="confirming = false" class="px-2 py-0.5 rounded-lg border border-gray-300 dark:border-gray-600">إلغاء</button>
                </span>
            </template>
        </div>
        <p v-if="error" class="mt-0.5 text-red-600 dark:text-red-300" role="alert">{{ error }}</p>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import axios from 'axios';
import { KIND_ICONS, formatSize, statusLabel, mediaUrl, fetchError } from '@/utils/media.js';

// بطاقة المرفق داخل الفقاعة (E14.7): لا تحميل تلقائي لأي صورة؛ العرض والتنزيل بنقرة، والخادم يفرض الصلاحية بنفسه
const props = defineProps({
    messageId: { type: Number, required: true },
    media: { type: Object, required: true },
});
const emit = defineEmits(['view', 'updated']);

const busy = ref(false);
const error = ref('');
const confirming = ref(false);
const label = computed(() => statusLabel(props.media));
const kindLabel = computed(() => ({ image: 'صورة', document: 'ملف', video: 'مقطع فيديو', audio: 'صوت', sticker: 'ملصق' }[props.media.kind] || 'مرفق'));

const download = async () => {
    busy.value = true;
    error.value = '';
    try {
        const { data } = await axios.get(mediaUrl(props.messageId, true), { responseType: 'blob', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const url = URL.createObjectURL(data);
        const a = document.createElement('a');
        a.href = url;
        a.download = props.media.name || `whatsapp-${props.messageId}`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (e) {
        error.value = fetchError(e.response?.status);
    } finally {
        busy.value = false;
    }
};

const refetch = async () => {
    busy.value = true;
    error.value = '';
    try {
        const { data } = await axios.post(`/api/whatsapp/messages/${props.messageId}/media/refetch`, {}, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        confirming.value = false;
        emit('updated', data.media);
    } catch (e) {
        error.value = e.response?.data?.message || fetchError(e.response?.status);
    } finally {
        busy.value = false;
    }
};
</script>
