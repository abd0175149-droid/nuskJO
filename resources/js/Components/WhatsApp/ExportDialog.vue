<template>
  <!-- تصدير محادثة واحدة (E30.8): الصيغة والفترة وسبب إلزامي. الخادم يفرض الصلاحية والسياج ويدقّق قبل البث -->
  <AppModal :open="open" title="⬇️ تصدير المحادثة" :dirty="dirty" @close="$emit('close')">
    <form :id="formId" class="space-y-3 text-sm" @submit.prevent="submit">
      <fieldset class="space-y-1.5">
        <legend class="text-xs font-bold text-gray-600 dark:text-gray-300 mb-1">الصيغة</legend>
        <label class="flex items-center gap-2 min-h-8"><input v-model="form.format" type="radio" value="txt" name="export-format"/> نص (TXT) للقراءة والطباعة</label>
        <label class="flex items-center gap-2 min-h-8"><input v-model="form.format" type="radio" value="csv" name="export-format"/> جدول (CSV) يُفتح في Excel</label>
      </fieldset>
      <div class="grid grid-cols-2 gap-2">
        <label class="text-xs text-gray-600 dark:text-gray-300">من
          <input v-model="form.from" type="date" dir="ltr" class="mt-1 w-full px-2 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-white"/></label>
        <label class="text-xs text-gray-600 dark:text-gray-300">إلى
          <input v-model="form.to" type="date" dir="ltr" class="mt-1 w-full px-2 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-white"/></label>
      </div>
      <p class="text-[11px] text-gray-500 dark:text-gray-400">بلا فترة تُصدَّر المحادثة كاملة. الأوقات بتوقيت الأردن.</p>
      <label class="block text-xs font-bold text-gray-700 dark:text-gray-200">سبب التصدير <span class="text-red-600" aria-hidden="true">*</span>
        <textarea v-model="form.reason" data-autofocus rows="2" maxlength="200" required placeholder="مثال: طلب العميل نسخة لنزاع على الدفع"
                  class="mt-1 w-full px-3 py-2 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-white font-normal"></textarea>
      </label>
      <p role="note" class="rounded-lg px-3 py-2 text-xs bg-amber-50 text-amber-900 dark:bg-amber-900/30 dark:text-amber-200">
        ⚠️ الملف يحوي بيانات شخصية للعميل. احفظه في مكان آمن واحذفه بعد الحاجة. كل تصدير مسجّل باسمك.
      </p>
      <p v-if="error" role="alert" class="text-xs text-red-700 dark:text-red-400">{{ error }}</p>
    </form>
    <template #footer>
      <button type="button" @click="$emit('close')" class="min-h-11 px-4 rounded-xl text-sm font-bold border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300">إلغاء</button>
      <button type="submit" :form="formId" :disabled="busy || !ready" data-test="export-submit"
              class="min-h-11 px-4 rounded-xl text-sm font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">
        {{ busy ? 'جارٍ التصدير...' : '⬇️ تنزيل' }}
      </button>
    </template>
  </AppModal>
</template>

<script setup>
import { computed, reactive, ref, useId, watch } from 'vue';
import AppModal from '@/Components/AppModal.vue';
import { download, errorBody } from '@/composables/whatsapp/useDownload.js';

const props = defineProps({
  open: Boolean,
  conversation: { type: Object, default: () => ({}) },
  // للاختبار
  downloader: { type: Function, default: null },
});
const emit = defineEmits(['close', 'done']);

const formId = `export-${useId()}`;
const blank = () => ({ format: 'txt', from: '', to: '', reason: '' });
const form = reactive(blank());
const busy = ref(false);
const error = ref('');

watch(() => props.open, (v) => { if (v) { Object.assign(form, blank()); error.value = ''; } });
const dirty = computed(() => form.reason.trim() !== '' || !!form.from || !!form.to);
const ready = computed(() => [...form.reason.trim()].length >= 5 && (!form.from || !form.to || form.from <= form.to));

const MESSAGES = {
  403: 'ليست لديك صلاحية تصدير المحادثات',
  404: 'المحادثة غير متاحة لك أو التصدير مطفأ',
  429: 'صدّرت كثيراً خلال دقيقة؛ انتظر قليلاً',
};

async function submit() {
  if (!ready.value || busy.value || !props.conversation?.id) return;
  busy.value = true;
  error.value = '';
  const params = Object.fromEntries(Object.entries({ format: form.format, from: form.from, to: form.to, reason: form.reason.trim() }).filter(([, v]) => v));
  try {
    await (props.downloader || download)(`/api/whatsapp/conversations/${props.conversation.id}/export`, params,
      { fallbackName: `wa-conv-${props.conversation.id}.${form.format}` });
    emit('done');
    emit('close');
  } catch (e) {
    const status = e?.response?.status;
    const body = await errorBody(e);
    // 422: سبب ناقص أو فترة خاطئة أو محادثة أطول من الحد (ضيّق الفترة)
    const fromServer = status === 422 ? (Object.values(body.errors || {})[0]?.[0] || body.message) : null;
    error.value = MESSAGES[status] || fromServer || 'تعذّر التصدير، حاول مرة أخرى';
  } finally {
    busy.value = false;
  }
}
</script>
