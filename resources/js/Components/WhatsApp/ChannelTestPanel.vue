<template>
    <div data-test="channel-test" class="mt-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 p-4 text-sm">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <p class="font-bold text-gray-800 dark:text-gray-100">🔌 اختبار الاتصال</p>
            <button type="button" data-test="channel-test-run" :disabled="running" @click="run"
                    class="px-4 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm font-bold text-gray-700 dark:text-gray-200 hover:bg-gold-50 dark:hover:bg-gold-900/20 disabled:opacity-40 disabled:cursor-not-allowed">
                {{ running ? '⏳ جارٍ الاختبار…' : '🔌 اختبر الآن' }}
            </button>
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">قراءات رسمية من ميتا (لا تعديل)، ثم طلب موقّع إلى رابط الويبهوك العام نفسه. لا يصل شيء لأي عميل.</p>
        <p v-if="error" role="alert" class="text-xs text-red-700 dark:text-red-400 mt-2">{{ error }}</p>

        <ul v-if="result" class="mt-3 space-y-1.5" aria-live="polite">
            <li v-for="s in result.steps" :key="s.key" data-test="channel-step" class="rounded-lg border px-3 py-2 min-w-0" :class="STEP[mark(s.ok)].row">
                <p class="font-bold text-gray-800 dark:text-gray-100"><span :class="STEP[mark(s.ok)].text">{{ STEP[mark(s.ok)].icon }}</span> {{ s.label }}</p>
                <p class="text-xs text-gray-700 dark:text-gray-300 break-words">{{ s.detail }}</p>
                <p v-if="s.advice" class="text-xs text-gray-500 dark:text-gray-400 break-words">💡 {{ s.advice }}</p>
            </li>
        </ul>
        <p v-if="result" class="text-[11px] text-gray-400 mt-2">
            حدود الاختبار: الارتداد يوقّع بالسر المخزّن نفسه فلا يثبت صحته — الذي يثبتها خطوة «App Secret» و«آخر ويبهوك حقيقي».
            أي تصحيح يكون يدوياً من لوحة ميتا.
        </p>
    </div>
</template>

<script setup>
// «اختبار الاتصال» (E23، العلم channel_selftest): خمس خطوات بعلامة ✓ أو ✗ أو «غير ممكن» وتفسير عربي ونصيحة
import { ref } from 'vue';
import axios from 'axios';

const STEP = {
    ok: { icon: '✓', text: 'text-green-700 dark:text-green-400', row: 'border-green-200 dark:border-green-900/60 bg-white dark:bg-gray-900' },
    fail: { icon: '✗', text: 'text-red-700 dark:text-red-400', row: 'border-red-200 dark:border-red-900/60 bg-red-50 dark:bg-red-900/20' },
    na: { icon: '–', text: 'text-gray-500', row: 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900' },
};
const mark = (ok) => (ok === true ? 'ok' : ok === false ? 'fail' : 'na');

const result = ref(null);
const running = ref(false);
const error = ref('');

async function run() {
    running.value = true;
    error.value = '';
    try {
        const { data } = await axios.post('/api/whatsapp/channel-test', {}, { headers: { Accept: 'application/json' } });
        result.value = data;
    } catch (e) {
        error.value = e?.response?.status === 429 ? 'ست مرات في الدقيقة — انتظر قليلاً.' : 'تعذّر تشغيل الاختبار.';
    } finally {
        running.value = false;
    }
}
</script>
