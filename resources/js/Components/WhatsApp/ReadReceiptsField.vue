<template>
    <fieldset class="rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 p-4 min-w-0"
              :class="dirty ? 'ring-1 ring-gold-400' : ''" data-test="read-receipts-field">
        <legend class="sr-only">{{ LABEL }}</legend>
        <div class="flex items-center gap-2 flex-wrap">
            <p class="text-sm font-bold text-gray-800 dark:text-gray-100" aria-hidden="true">👁 {{ LABEL }}</p>
            <span v-if="!flagOn" data-test="flag-off"
                  class="px-2 py-0.5 rounded-full text-xs font-bold bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                العلم read_receipts مطفأ: لا يصل ميتا شيء بعد
            </span>
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">ما يراه العميل في واتساب عند قراءة رسائله. يغيّر ما يراه كل العملاء، فيُطلب تأكيد عند الحفظ.</p>

        <div class="space-y-2 mt-3" role="radiogroup" :aria-label="LABEL">
            <label v-for="o in OPTIONS" :key="o.key"
                   class="flex items-start gap-3 p-3 rounded-xl border cursor-pointer bg-white dark:bg-gray-900"
                   :class="modelValue === o.key ? 'border-gold-400 bg-gold-50/60 dark:bg-gold-900/10' : 'border-gray-200 dark:border-gray-700'">
                <input type="radio" name="send-read-receipts" :value="o.key" :checked="modelValue === o.key"
                       @change="$emit('update:modelValue', o.key)" class="mt-1 w-4 h-4 text-gold-500 shrink-0"/>
                <span class="min-w-0">
                    <span class="block text-sm font-bold text-gray-800 dark:text-gray-100">{{ o.label }}</span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ o.hint }}</span>
                </span>
            </label>
        </div>

        <p role="note" class="mt-3 rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 px-3 py-2 text-xs text-amber-800 dark:text-amber-300">
            ⚠️ مؤشر الكتابة يُظهر العلامتين الزرقاوين حتماً، فلا يمكن تفعيل أحدهما دون الآخر. وظهور القراءة دون رد سريع قد يزعج العميل خارج الدوام.
        </p>
        <p v-if="error" class="text-xs text-red-600 dark:text-red-400 mt-1">{{ error }}</p>
    </fieldset>
</template>

<script setup>
// إشعار القراءة ومؤشر الكتابة (E35): اختيار ثلاثي يُحفظ مع بقية الإعدادات (PATCH برمز النسخة وتأكيد لأنه حساس)
defineProps({
    modelValue: { type: String, default: 'off' },
    flagOn: { type: Boolean, default: false },
    dirty: { type: Boolean, default: false },
    error: { type: String, default: '' },
});
defineEmits(['update:modelValue']);

const LABEL = 'إشعار القراءة ومؤشر الكتابة';

const OPTIONS = [
    { key: 'off', label: 'لا شيء (الافتراضي)', hint: 'لا يرى العميل أي علامة قراءة ولا مؤشر كتابة.' },
    { key: 'on_reply', label: 'عند الرد', hint: '«يكتب…» أثناء تحضير البوت للرد، وعلامتا القراءة عند رد الموظف.' },
    { key: 'on_open', label: 'عند الرد والفتح', hint: 'كل ما سبق، وعلامتا القراءة عند فتح الموظف الموكَّل للمحادثة.' },
];
</script>
