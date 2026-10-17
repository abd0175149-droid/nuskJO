<template>
  <AppModal :open="open" title="⬇️ تصدير طلبات التسعير (CSV)" size="sm" @close="$emit('close')">
    <form id="quote-export" class="space-y-3" @submit.prevent="go">
      <p class="text-xs text-gray-600 dark:text-gray-300">
        رقم الطلب والتاريخ والنوع والحالة والعرض والموظفون والسعر والفاتورة فقط: لا هاتف ولا اسم عميل ولا تفاصيل. يُسجَّل التصدير في سجل التدقيق.
      </p>
      <div class="grid grid-cols-2 gap-3">
        <label class="block text-sm">
          <span class="block text-xs font-bold text-gray-600 dark:text-gray-300 mb-1">من</span>
          <input v-model="from" type="date" dir="ltr" data-test="from"
                 class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm dark:text-white"/>
        </label>
        <label class="block text-sm">
          <span class="block text-xs font-bold text-gray-600 dark:text-gray-300 mb-1">إلى</span>
          <input v-model="to" type="date" dir="ltr" data-test="to"
                 class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm dark:text-white"/>
        </label>
      </div>
      <label class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-200 cursor-pointer">
        <input v-model="onlyFilter" type="checkbox" data-test="only-filter" class="mt-0.5 w-4 h-4 rounded text-gold-500 shrink-0"/>
        <span>بفلتر الصفحة الحالي فقط (الحالة والبحث)؛ وإلا كل الطلبات في المدى.</span>
      </label>
      <p v-if="error" role="alert" class="text-xs text-red-600 dark:text-red-400" data-test="error">{{ error }}</p>
    </form>
    <template #footer>
      <button type="button" class="min-h-11 px-4 py-2 rounded-xl text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800" @click="$emit('close')">إلغاء</button>
      <button type="submit" form="quote-export" data-test="download"
              class="min-h-11 px-5 py-2 rounded-xl text-sm font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400">⬇️ تنزيل</button>
    </template>
  </AppModal>
</template>

<script setup>
import { computed, ref } from 'vue';
import AppModal from '@/Components/AppModal.vue';
import { defaultRange, exportUrl, rangeError } from '@/utils/quoteExport.js';

const props = defineProps({
  open: Boolean,
  status: { type: String, default: null },
  search: { type: String, default: null },
});
const emit = defineEmits(['close', 'navigate']);

const range = defaultRange();
const from = ref(range.from);
const to = ref(range.to);
const onlyFilter = ref(false);
const error = computed(() => rangeError(from.value, to.value));

// تنزيل عادي بالمتصفح (لا axios): التحقق المعزّز إن طُلب يحوّل الصفحة ثم يعود
function go() {
  if (error.value) return;
  const url = exportUrl(onlyFilter.value
    ? { from: from.value, to: to.value, status: props.status, search: props.search }
    : { from: from.value, to: to.value, status: 'all', search: null });
  emit('navigate', url);
  window.location.assign(url);
  emit('close');
}
</script>
