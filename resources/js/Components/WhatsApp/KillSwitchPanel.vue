<template>
    <div class="rounded-xl border p-4" :class="state.effective
        ? 'border-red-300 dark:border-red-800 bg-red-50 dark:bg-red-900/20'
        : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40'">
        <div class="flex items-start justify-between gap-3 flex-wrap">
            <div class="min-w-0">
                <p class="text-sm font-bold" :class="state.effective ? 'text-red-700 dark:text-red-400' : 'text-gray-800 dark:text-gray-100'">
                    {{ state.effective ? '🛑 كل الإرسال موقوف' : '🟢 الإرسال يعمل' }}
                    <span v-if="state.effective" class="font-normal"> — {{ SUSPEND_SOURCES[state.source] || state.source }}</span>
                </p>
                <p v-if="state.reason" class="text-xs text-gray-600 dark:text-gray-400 mt-1">
                    {{ state.effective ? 'السبب' : 'آخر استئناف' }}: {{ state.reason }}
                    <template v-if="state.by_name"> · {{ state.by_name }}</template>
                    <template v-if="state.at"> · <bdi dir="ltr">{{ when(state.at) }}</bdi></template>
                </p>
                <p v-if="state.review_pending && state.db" class="text-xs font-bold text-red-700 dark:text-red-400 mt-1">
                    لا يُستأنف قبل اعتماد مراجعة ما بعد الاستعادة — <Link href="/settings/backups" class="underline">النسخ الاحتياطي</Link>
                </p>
            </div>
            <button v-if="!state.effective && can.suspend" type="button" @click="open('on')"
                    class="px-4 py-2 rounded-xl text-sm font-bold bg-red-600 text-white hover:bg-red-700">🛑 إيقاف كل الإرسال</button>
            <button v-else-if="state.effective && state.source !== 'env' && can.resume" type="button" @click="open('off')"
                    class="px-4 py-2 rounded-xl text-sm font-bold border border-green-600 text-green-700 dark:text-green-400 hover:bg-green-50 dark:hover:bg-green-900/20">▶️ استئناف الإرسال</button>
        </div>
        <p v-if="state.source === 'env'" class="text-xs text-blue-700 dark:text-blue-300 mt-2">الإيقاف مفروض من بيئة الخادم (WA_SUSPENDED) — يُرفع من هناك لا من هنا.</p>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">يمنع أي رسالة صادرة فوراً: البوت والموظفون والإشعارات. الإيقاف والاستئناف يُسجَّلان بالسبب والفاعل.</p>

        <div v-if="mode" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="mode = null">
            <form @submit.prevent="submit" class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md p-6 space-y-3" dir="rtl">
                <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">{{ mode === 'on' ? '🛑 إيقاف كل الإرسال' : '▶️ استئناف الإرسال' }}</h3>
                <div>
                    <label for="ks-reason" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">السبب (يُسجَّل ويُرسل للمدراء)</label>
                    <textarea id="ks-reason" v-model="reason" rows="2" required minlength="10" maxlength="500"
                              class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm dark:text-white"></textarea>
                </div>
                <template v-if="mode === 'off'">
                    <label v-if="['meta_event', 'graph_error'].includes(state.source)" class="flex items-start gap-2 text-sm font-bold text-red-700 dark:text-red-400">
                        <input v-model="acknowledge" type="checkbox" class="mt-1 w-4 h-4 rounded"/>
                        أُوقف بحدث من ميتا ({{ state.reason }}). راجعت حالة الحساب في WhatsApp Manager وأنه سليم.
                    </label>
                    <div>
                        <label for="ks-confirm" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">اكتب «استئناف» للتأكيد</label>
                        <input id="ks-confirm" v-model="confirmText" required autocomplete="off"
                               class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm dark:text-white"/>
                    </div>
                </template>
                <p v-if="error" class="text-xs text-red-600 dark:text-red-400">{{ error }}</p>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="mode = null" class="px-4 py-2 rounded-xl text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800">إلغاء</button>
                    <button type="submit" :disabled="busy || (mode === 'off' && confirmText.trim() !== 'استئناف')"
                            class="px-5 py-2 rounded-xl text-sm font-bold disabled:opacity-40" :class="mode === 'on' ? 'bg-red-600 text-white' : 'bg-green-600 text-white'">
                        {{ busy ? '⏳' : (mode === 'on' ? 'إيقاف' : 'استئناف') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { SUSPEND_SOURCES, when } from '@/utils/waSettings.js';

// الإيقاف الطارئ بمساره المستقل (لا من نموذج الإعدادات): سبب مكتوب، وكتابة «استئناف» للرفع
const props = defineProps({
    state: { type: Object, required: true },
    can: { type: Object, default: () => ({ suspend: false, resume: false }) },
});
const emit = defineEmits(['changed']);

const mode = ref(null);
const reason = ref('');
const confirmText = ref('');
const acknowledge = ref(false);
const busy = ref(false);
const error = ref('');

const open = (m) => { mode.value = m; reason.value = ''; confirmText.value = ''; acknowledge.value = false; error.value = ''; };

const submit = async () => {
    busy.value = true;
    error.value = '';
    try {
        const { data } = await axios.post('/api/whatsapp/settings/kill-switch', {
            state: mode.value, reason: reason.value, confirm_text: confirmText.value, acknowledge: acknowledge.value,
        });
        emit('changed', data.killSwitch);
        mode.value = null;
    } catch (e) {
        error.value = e.response?.data?.message && !e.response?.data?.errors
            ? e.response.data.message
            : Object.values(e.response?.data?.errors || {})[0]?.[0] || 'تعذّر التنفيذ';
    } finally {
        busy.value = false;
    }
};
</script>
