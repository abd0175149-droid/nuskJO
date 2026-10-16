<template>
    <div id="doctor" class="rounded-xl border bg-white dark:bg-gray-900 shadow-sm scroll-mt-20"
         :class="hasError ? 'border-red-200 dark:border-red-900/60' : 'border-gray-200 dark:border-gray-700'">
        <div class="flex items-center justify-between gap-3 flex-wrap p-4 md:px-6">
            <button type="button" class="flex items-center gap-2 min-w-0 text-start" :aria-expanded="open" aria-controls="doctor-body" @click="open = !open">
                <span class="text-lg font-bold text-gray-800 dark:text-gray-100">🩺 فحص الإعدادات</span>
                <span v-if="summary" data-test="doctor-summary" class="text-xs font-bold px-2.5 py-1 rounded-full" :class="summaryClass">{{ summary }}</span>
                <span class="text-gray-400 text-sm" aria-hidden="true">{{ open ? '▲' : '▼' }}</span>
            </button>
            <button type="button" data-test="doctor-run" :disabled="running" @click="run"
                    class="px-4 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm font-bold text-gray-700 dark:text-gray-200 hover:bg-gold-50 dark:hover:bg-gold-900/20 disabled:opacity-40 disabled:cursor-not-allowed">
                {{ running ? '⏳ جارٍ الفحص…' : '▶️ شغّل الفحص' }}
            </button>
        </div>

        <div v-show="open" id="doctor-body" class="px-4 md:px-6 pb-5">
            <p v-if="message" role="status" class="text-xs mb-2" :class="messageOk ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400'">{{ message }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
                <template v-if="result">آخر فحص كامل: <bdi dir="ltr">{{ when(result.at) }}</bdi> · {{ result.duration_ms }} ms<template v-if="result.offline"> · بلا قراءات ميتا</template></template>
                <template v-else>لم يُشغَّل فحص كامل بعد — يعمل يومياً وعند إقلاع الحاوية، أو بالزر. أدناه فحوص الإعداد السريعة.</template>
            </p>

            <label v-if="result" class="inline-flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300 mb-2 cursor-pointer">
                <input v-model="problemsOnly" type="checkbox" class="rounded border-gray-300 dark:border-gray-600"/> المشكلات فقط
            </label>

            <p v-if="!rows.length" class="text-sm text-green-700 dark:text-green-400">✅ لا ملاحظات.</p>
            <ul v-else class="space-y-1.5">
                <li v-for="(c, i) in rows" :key="c.section + c.code + i" data-test="doctor-row"
                    class="rounded-lg border px-3 py-2 text-sm min-w-0" :class="LEVELS[c.level].row">
                    <div class="flex items-start gap-2 min-w-0">
                        <span aria-hidden="true">{{ LEVELS[c.level].icon }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-gray-800 dark:text-gray-100 break-words">{{ c.message }}</p>
                            <p v-if="c.advice" class="text-xs text-gray-600 dark:text-gray-400 mt-0.5 break-words">💡 {{ c.advice }}</p>
                            <p class="text-[11px] text-gray-400 mt-0.5"><span class="sr-only">{{ LEVELS[c.level].label }} · </span>{{ SECTIONS[c.section] || c.section }} · <bdi dir="ltr" class="font-mono">{{ c.code }}</bdi></p>
                        </div>
                    </div>
                </li>
            </ul>
            <p class="text-[11px] text-gray-400 mt-3">قراءة فقط: لا يغيّر شيئاً عند ميتا ولا حالة القناة، ولا يعرض أي قيمة سرية. ثلاث مرات كل 10 دقائق.</p>
        </div>
    </div>
</template>

<script setup>
// لوحة «🩺 فحص الإعدادات» (E23): آخر حصيلة لـ whatsapp:doctor (يومياً وعند الإقلاع وبالزر)، وفحوص الإعداد السريعة قبلها
import { computed, ref } from 'vue';
import axios from 'axios';
import { when } from '@/utils/waSettings.js';

const props = defineProps({
    // فحوص الإعداد السريعة عند فتح الصفحة (ChannelDoctor::checks)
    quick: { type: Array, default: () => [] },
    // آخر فحص كامل محفوظ (ChannelDoctor::last)
    last: { type: Object, default: null },
});

const LEVELS = {
    error: { icon: '❌', label: 'خطأ', row: 'border-red-200 dark:border-red-900/60 bg-red-50 dark:bg-red-900/20' },
    warn: { icon: '⚠️', label: 'تحذير', row: 'border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20' },
    ok: { icon: '✅', label: 'سليم', row: 'border-gray-200 dark:border-gray-700' },
    skip: { icon: '⏭️', label: 'لم يُفحص', row: 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40' },
};
const SECTIONS = { config: 'الإعداد', webhook: 'الويبهوك', local: 'البيئة', remote: 'ميتا' };

const result = ref(props.last);
const running = ref(false);
const message = ref('');
const messageOk = ref(true);
const problemsOnly = ref(true);

const quickRows = computed(() => props.quick.map((c) => ({ section: 'config', advice: null, ...c, level: c.level === 'error' ? 'error' : 'warn' })));
const rows = computed(() => {
    if (!result.value) return quickRows.value;
    const all = result.value.checks || [];
    return problemsOnly.value ? all.filter((c) => c.level === 'error' || c.level === 'warn') : all;
});
const counts = computed(() => result.value?.counts || {
    error: quickRows.value.filter((c) => c.level === 'error').length,
    warn: quickRows.value.filter((c) => c.level === 'warn').length,
});
const hasError = computed(() => (counts.value.error || 0) > 0);
const summary = computed(() => {
    const e = counts.value.error || 0;
    const w = counts.value.warn || 0;
    if (!e && !w) return result.value ? 'سليم' : '';
    return [e ? `${e} خطأ` : '', w ? `${w} تحذير` : ''].filter(Boolean).join(' · ');
});
const summaryClass = computed(() => (hasError.value
    ? 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300'
    : (counts.value.warn ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300' : 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300')));
// مفتوحة افتراضياً حين يوجد خطأ أو تحذير
const open = ref(hasError.value || (counts.value.warn || 0) > 0);

async function run() {
    running.value = true;
    message.value = '';
    try {
        const { data } = await axios.post('/api/whatsapp/doctor', {}, { headers: { Accept: 'application/json' } });
        result.value = data.doctor;
        open.value = true;
        messageOk.value = true;
        message.value = '✅ اكتمل الفحص.';
    } catch (e) {
        messageOk.value = false;
        message.value = e?.response?.status === 429 ? 'ثلاث مرات كل 10 دقائق — حاول بعد قليل.' : 'تعذّر تشغيل الفحص.';
    } finally {
        running.value = false;
    }
}
</script>
