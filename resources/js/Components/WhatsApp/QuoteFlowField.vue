<template>
    <fieldset class="rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 p-4 min-w-0"
              :class="dirty ? 'ring-1 ring-gold-400' : ''" data-test="quote-flow-field">
        <legend class="sr-only">{{ LABEL }}</legend>
        <div class="flex items-center gap-2 flex-wrap">
            <p class="text-sm font-bold text-gray-800 dark:text-gray-100" aria-hidden="true">📝 {{ LABEL }}</p>
            <span v-if="!flagOn" data-test="flag-off"
                  class="px-2 py-0.5 rounded-full text-xs font-bold bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                العلم quote_flow مطفأ: لا يُعرض النموذج للبوت
            </span>
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
            نموذج ثابت بلا نقطة نهاية يعبّئه العميل داخل واتساب، فيصل طلب التسعير مكتملاً. يُنشأ يدوياً في WhatsApp Manager
            من الملف resources/whatsapp/flows/quote_request_v1.json، ويحتاج توثيق النشاط التجاري لدى ميتا.
            <a href="https://developers.facebook.com/documentation/business-messaging/whatsapp/flows" target="_blank" rel="noopener noreferrer nofollow"
               class="font-bold text-gold-700 dark:text-gold-400 underline" data-test="meta-steps">خطوات ميتا</a>
        </p>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
            <div class="min-w-0">
                <label for="wa-quote-flow-id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">معرّف الـ Flow (Flow ID)</label>
                <input id="wa-quote-flow-id" type="text" inputmode="numeric" dir="ltr" maxlength="32" autocomplete="off"
                       :value="flowId ?? ''" @input="onInput" placeholder="مثل 1234567890123"
                       class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm font-mono focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white"/>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">أرقام فقط، من صفحة الـ Flow بعد نشره. فارغ = لا نموذج.</p>
                <p v-if="errors.wa_quote_flow_id" class="text-xs text-red-600 dark:text-red-400 mt-1">{{ errors.wa_quote_flow_id }}</p>
            </div>
            <div class="min-w-0">
                <label for="wa-quote-flow-mode" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">الوضع</label>
                <select id="wa-quote-flow-mode" :value="mode || 'published'" @change="$emit('update:mode', $event.target.value)"
                        class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm focus:ring-2 focus:ring-gold-500 focus:outline-none dark:text-white">
                    <option value="draft">تجربة (draft): لرقم الاختبار وحده</option>
                    <option value="published">منشور (published): للعملاء</option>
                </select>
                <p v-if="errors.wa_quote_flow_mode" class="text-xs text-red-600 dark:text-red-400 mt-1">{{ errors.wa_quote_flow_mode }}</p>
            </div>
        </div>

        <p role="note" class="mt-3 rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 px-3 py-2 text-xs text-amber-800 dark:text-amber-300">
            ⚠️ لا تضف إلى النموذج أي حقل جواز أو هوية. النماذج لا تعمل على واتساب ويب، والبوت يُبقي الكتابة بديلاً دائماً.
        </p>
    </fieldset>
</template>

<script setup>
// نموذج طلب التسعير (E36): معرّف الـ Flow المنشور ووضعه، يُحفظان مع بقية الإعدادات (PATCH برمز النسخة)
defineProps({
    flowId: { type: String, default: null },
    mode: { type: String, default: 'published' },
    flagOn: { type: Boolean, default: false },
    dirty: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['update:flowId', 'update:mode']);

const LABEL = 'نموذج طلب التسعير (Flow)';

// أرقام فقط (لاتينية)، والفارغ null
const clean = (v) => {
    const digits = String(v || '').replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d))).replace(/\D/g, '').slice(0, 32);
    return digits === '' ? null : digits;
};
const onInput = (e) => {
    const v = clean(e.target.value);
    e.target.value = v ?? '';
    emit('update:flowId', v);
};
</script>
