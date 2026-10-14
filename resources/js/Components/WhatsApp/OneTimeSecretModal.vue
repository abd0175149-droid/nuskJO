<template>
    <div v-if="token" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg p-6 space-y-4" dir="rtl" role="dialog" aria-modal="true">
            <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">🔑 رمز التحقق الجديد — يظهر مرة واحدة</h3>
            <div class="flex gap-2">
                <input :value="token" readonly dir="ltr" class="flex-1 min-w-0 px-3 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-xs font-mono dark:text-white" @focus="$event.target.select()"/>
                <button type="button" @click="copy" class="shrink-0 px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 text-sm font-bold text-gray-700 dark:text-gray-200">{{ copied ? '✅' : '📋 نسخ' }}</button>
            </div>
            <ol class="list-decimal pr-5 text-sm text-gray-700 dark:text-gray-300 space-y-1">
                <li>في App Dashboard افتح WhatsApp › Configuration › Webhook › Edit.</li>
                <li>الصق الرابط نفسه والرمز الجديد في Verify Token، ثم «Verify and save».</li>
                <li>تأكد أن «آخر مصافحة ناجحة» تحدّث هنا. الرسائل الواردة لا تتأثر.</li>
            </ol>
            <label class="flex items-center gap-2 text-sm text-gray-800 dark:text-gray-100">
                <input v-model="done" type="checkbox" class="w-4 h-4 rounded text-gold-500"/> نسخته ولصقته في لوحة ميتا
            </label>
            <div class="flex justify-end">
                <button type="button" :disabled="!done" @click="$emit('close')" class="px-6 py-2 rounded-xl text-sm font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-40">إغلاق</button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, watch } from 'vue';

// الرمز المولَّد لحظة توليده فقط؛ يُمسح من الذاكرة عند الإغلاق (الأب يضبط token = null)
const props = defineProps({ token: { type: String, default: null } });
defineEmits(['close']);

const done = ref(false);
const copied = ref(false);
watch(() => props.token, () => { done.value = false; copied.value = false; });

const copy = async () => {
    try { await navigator.clipboard.writeText(props.token); copied.value = true; } catch { copied.value = false; }
};
</script>
