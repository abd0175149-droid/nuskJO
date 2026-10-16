<template>
  <AppModal :open="open" :title="endpoint ? 'تعديل وجهة' : 'وجهة جديدة'" size="lg" :dirty="dirty" @close="$emit('close')">
    <form id="endpoint-form" @submit.prevent="submit" class="space-y-4">
      <p class="p-3 rounded-xl text-xs leading-relaxed bg-amber-50 dark:bg-amber-900/20 text-amber-900 dark:text-amber-200 border border-amber-200 dark:border-amber-800" data-test="never-leaves">
        🔒 لا نصوص رسائل ولا هواتف ولا تكاليف تخرج أبداً. تخرج الحقول المذكورة تحت كل حدث وحدها، بتوقيع Standard Webhooks.
      </p>
      <div>
        <label for="ep-name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">الاسم *</label>
        <input id="ep-name" data-autofocus v-model="form.name" maxlength="80" required dir="auto" placeholder="مثال: n8n الإنتاج"
               class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm"/>
        <p v-if="errors.name" class="mt-1 text-xs text-red-500" role="alert">{{ errors.name }}</p>
      </div>
      <div>
        <label for="ep-url" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">الرابط {{ endpoint ? '' : '*' }}</label>
        <input id="ep-url" v-model="form.url" maxlength="512" :required="!endpoint" dir="ltr" type="url" inputmode="url" autocomplete="off"
               :placeholder="endpoint ? 'اتركه فارغاً لإبقاء الرابط الحالي' : 'https://n8n.example.com/webhook/…'"
               class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-gray-100 text-sm font-mono"/>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">https وحده، واسم مضيف عام (لا عنوان IP ولا شبكة خاصة). لا يُعرض بعد الحفظ إلا مضيفه.</p>
        <p v-if="endpoint" class="text-xs text-amber-700 dark:text-amber-400 mt-1">تغيير الرابط يوقف الوجهة حتى ping ناجح على الرابط الجديد.</p>
        <p v-if="errors.url" class="mt-1 text-xs text-red-500" role="alert" data-test="url-error">{{ errors.url }}</p>
      </div>
      <fieldset>
        <legend class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">الأحداث *</legend>
        <div class="space-y-2">
          <label v-for="e in catalog" :key="e.key" class="flex items-start gap-3 rounded-xl border p-3 cursor-pointer"
                 :class="form.events.includes(e.key) ? 'border-gold-400 bg-gold-50/60 dark:bg-gold-900/10 dark:border-gold-700' : 'border-gray-200 dark:border-gray-700'">
            <input type="checkbox" v-model="form.events" :value="e.key" class="mt-1 w-4 h-4 rounded text-gold-500"/>
            <span class="min-w-0">
              <span class="block text-sm font-bold text-gray-800 dark:text-gray-100">{{ e.label }} <bdi dir="ltr" class="font-mono text-xs font-normal text-gray-500">{{ e.key }}</bdi></span>
              <span class="block text-xs text-gray-500 dark:text-gray-400 mt-1">الحقول التي ستخرج:</span>
              <span class="flex flex-wrap gap-1 mt-1" data-test="fields">
                <code v-for="f in fieldsFor(e, form.include_amounts)" :key="f" dir="ltr" class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-[11px] text-gray-700 dark:text-gray-300">{{ f }}</code>
              </span>
            </span>
          </label>
        </div>
        <p v-if="errors.events" class="mt-1 text-xs text-red-500" role="alert">{{ errors.events }}</p>
      </fieldset>
      <label class="flex items-start gap-2 text-sm text-gray-800 dark:text-gray-100">
        <input type="checkbox" v-model="form.include_amounts" class="mt-1 w-4 h-4 rounded text-gold-500" data-test="amounts"/>
        <span>إرسال إجمالي الفاتورة <code dir="ltr" class="text-xs">total_sell_jod</code> مع «اعتماد فاتورة»
          <span class="block text-xs text-gray-500 dark:text-gray-400">بقرار المالك لهذه الوجهة وحدها. التكلفة والربح لا يخرجان أبداً.</span></span>
      </label>
      <p v-if="errors.general" class="text-xs text-red-500" role="alert">{{ errors.general }}</p>
    </form>
    <template #footer>
      <button type="button" @click="$emit('close')" class="min-h-11 px-4 rounded-xl text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800">إلغاء</button>
      <button type="submit" form="endpoint-form" :disabled="busy || !form.events.length" class="min-h-11 px-5 rounded-xl text-sm font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">
        {{ busy ? '⏳' : (endpoint ? 'حفظ' : 'إضافة وتوليد السر') }}
      </button>
    </template>
  </AppModal>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import axios from 'axios';
import AppModal from '@/Components/AppModal.vue';
import { fieldsFor } from '@/composables/useIntegrations.js';

// نموذج الوجهة (E40.7): الرابط لا يعود من الخادم بعد الحفظ (قد يحمل معاملات سرية)، فالتعديل يطلبه كاملاً
const props = defineProps({
  open: Boolean,
  endpoint: { type: Object, default: null },
  catalog: { type: Array, default: () => [] },
});
const emit = defineEmits(['close', 'saved']);

const blank = () => ({ name: '', url: '', events: [], include_amounts: false });
const form = reactive(blank());
const errors = reactive({});
const busy = ref(false);
const baseline = ref('');
const dirty = computed(() => props.open && JSON.stringify(form) !== baseline.value);

watch(() => props.open, (open) => {
  if (!open) return;
  Object.assign(form, blank(), props.endpoint ? {
    name: props.endpoint.name, events: [...props.endpoint.events], include_amounts: !!props.endpoint.include_amounts,
  } : {});
  Object.keys(errors).forEach((k) => delete errors[k]);
  baseline.value = JSON.stringify(form);
}, { immediate: true });

async function submit() {
  busy.value = true;
  Object.keys(errors).forEach((k) => delete errors[k]);
  try {
    const payload = { ...form, events: [...form.events] };
    const { data } = props.endpoint
      ? await axios.put(`/settings/integrations/endpoints/${props.endpoint.id}`, payload)
      : await axios.post('/settings/integrations/endpoints', payload);
    baseline.value = JSON.stringify(form);
    emit('saved', data);
  } catch (e) {
    const bag = e?.response?.data?.errors;
    if (bag) {
      Object.entries(bag).forEach(([k, v]) => { errors[k.startsWith('events') ? 'events' : k] = [].concat(v)[0]; });
    } else {
      errors.general = e?.response?.data?.message || 'تعذّر الحفظ';
    }
  } finally {
    busy.value = false;
  }
}
</script>
