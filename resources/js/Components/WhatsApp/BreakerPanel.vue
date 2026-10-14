<template>
    <div class="mt-5 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 p-4 space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm font-bold text-gray-800 dark:text-gray-100">الحالة الآن:</span>
                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold" :class="badge.cls">{{ badge.text }}</span>
                <span v-if="primary.kind && primary.state !== 'closed'" class="text-xs text-gray-600 dark:text-gray-300">{{ KINDS[primary.kind] || primary.kind }}</span>
            </div>
            <div class="flex gap-2">
                <button v-if="primary.state === 'closed'" type="button" :disabled="busy" @click="openNow"
                        class="px-3 py-1.5 rounded-lg text-xs font-bold border border-amber-400 text-amber-700 dark:text-amber-400 disabled:opacity-50">🔌 فتح يدوي (وضع التحويل)</button>
                <button v-else type="button" :disabled="busy" @click="closeNow"
                        class="px-3 py-1.5 rounded-lg text-xs font-bold border border-green-400 text-green-700 dark:text-green-400 disabled:opacity-50">✅ إغلاق الآن</button>
            </div>
        </div>
        <p v-if="primary.reason && primary.state !== 'closed'" class="text-xs text-gray-500 dark:text-gray-400 break-words" dir="auto">{{ primary.reason }}</p>
        <p v-if="fallback" class="text-xs text-gray-600 dark:text-gray-300">
            المزوّد الاحتياطي ({{ fallback.provider }}): {{ fallback.ready ? (fallback.state === 'closed' ? 'جاهز' : 'متعطّل أيضاً') : 'غير مكتمل' }}
        </p>
        <p v-if="error" class="text-xs text-red-600 dark:text-red-400" role="alert">{{ error }}</p>

        <details v-if="incidents.length" class="text-xs">
            <summary class="cursor-pointer font-bold text-gray-700 dark:text-gray-200">آخر {{ incidents.length }} حوادث</summary>
            <div class="mt-2 overflow-x-auto">
                <table class="min-w-full text-right">
                    <thead class="text-gray-500 dark:text-gray-400">
                        <tr><th class="py-1 px-2">البدء</th><th class="py-1 px-2">النوع</th><th class="py-1 px-2">المدة</th><th class="py-1 px-2">تحويلات</th><th class="py-1 px-2">الإغلاق</th></tr>
                    </thead>
                    <tbody class="text-gray-700 dark:text-gray-200">
                        <tr v-for="i in incidents" :key="i.id" class="border-t border-gray-200 dark:border-gray-700">
                            <td class="py-1 px-2 whitespace-nowrap" dir="ltr">{{ when(i.opened_at) }}</td>
                            <td class="py-1 px-2">{{ KINDS[i.kind] || i.kind }}<span v-if="i.shadow" class="text-gray-400"> · مراقبة</span><span v-if="i.http_status" class="text-gray-400" dir="ltr"> · {{ i.http_status }}</span></td>
                            <td class="py-1 px-2 whitespace-nowrap">{{ duration(i) }}</td>
                            <td class="py-1 px-2">{{ i.handoffs }}</td>
                            <td class="py-1 px-2">{{ i.closed_at ? (CLOSE[i.close_reason] || i.close_reason) : 'مفتوحة' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </details>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';

// breaker: {primary, fallback, incidents} من الخادم؛ لا بيانات عملاء
const props = defineProps({ breaker: { type: Object, default: () => ({}) } });
const emit = defineEmits(['changed']);

const KINDS = {
    unavailable: 'خدمة المزوّد غير متاحة', rate_limited: 'المزوّد يخنق الطلبات', auth_failed: 'مفتاح أو نموذج مرفوض',
    quota_exhausted: 'حصة أو فوترة منتهية', manual: 'فتح يدوي',
};
const CLOSE = { probe: 'عاد المزوّد', manual: 'يدوياً', settings_changed: 'تغيّر الإعداد' };

const busy = ref(false);
const error = ref('');
const primary = computed(() => props.breaker?.primary || { state: 'closed' });
const fallback = computed(() => props.breaker?.fallback || null);
const incidents = computed(() => props.breaker?.incidents || []);

const time = (iso) => (iso ? new Date(iso).toLocaleTimeString('ar-JO', { hour: '2-digit', minute: '2-digit' }) : '');
const when = (iso) => (iso ? new Date(iso).toLocaleString('ar-JO', { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—');
const duration = (i) => {
    const end = i.closed_at ? new Date(i.closed_at) : new Date();
    const mins = Math.max(1, Math.round((end - new Date(i.opened_at)) / 60000));
    return `${mins} د`;
};
const badge = computed(() => {
    const p = primary.value;
    if (p.mode === 'off') return { text: 'القاطع مطفأ', cls: 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200' };
    if (p.state === 'open') return { text: `مفتوح حتى ${time(p.open_until)}${p.mode === 'shadow' ? ' (مراقبة)' : ''}`, cls: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300' };
    if (p.state === 'half_open') return { text: 'بانتظار محاولة اختبار', cls: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300' };
    return { text: 'مغلق — البوت يعمل', cls: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300' };
});

const call = async (url, payload) => {
    busy.value = true; error.value = '';
    try {
        const { data } = await axios.post(url, payload);
        emit('changed', data.breaker);
    } catch (e) {
        error.value = e?.response?.data?.message || 'تعذّر التنفيذ';
    } finally { busy.value = false; }
};

const openNow = () => {
    const input = window.prompt('افتح وضع التحويل كم دقيقة؟ (5–240). كل عميل يُحوَّل لموظف برسالة ثابتة.', '15');
    if (input === null) return;
    const minutes = parseInt(input, 10);
    if (!(minutes >= 5 && minutes <= 240)) { error.value = 'المدة بين 5 و240 دقيقة'; return; }
    call('/api/whatsapp/settings/llm-breaker/open', { minutes });
};
const closeNow = () => {
    if (!window.confirm('إغلاق وضع التحويل الآن؟ يعود البوت للرد على الرسائل الجديدة.')) return;
    call('/api/whatsapp/settings/llm-breaker/close', {});
};
</script>
