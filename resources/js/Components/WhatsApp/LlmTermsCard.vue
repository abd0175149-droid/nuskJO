<template>
  <!-- تأكيد الطبقة المدفوعة لمزوّد الذكاء (E29): شرط ترجمة الرسائل للموظف. يُسجَّل في التدقيق، ويُلغى عند تغيير المفتاح أو المزوّد -->
  <div class="rounded-xl border p-4 space-y-2" :class="state.valid ? 'border-green-200 dark:border-green-800' : 'border-amber-200 dark:border-amber-800'" data-test="llm-terms">
    <p class="text-sm font-bold text-gray-800 dark:text-gray-100">💳 تأكيد الطبقة المدفوعة لمزوّد الذكاء الاصطناعي</p>
    <p class="text-xs leading-relaxed text-gray-600 dark:text-gray-300">
      ترجمة رسائل العملاء للموظف ترسل نص المحادثة إلى {{ state.provider }}. في الطبقة المجانية قد يُستخدم المحتوى لتحسين منتجات المزوّد ويراجعه بشر،
      وشروط واتساب تمنع ذلك. أكّد فقط بعد التحقق أن مشروع المفتاح مربوط بحساب فوترة نشط.
    </p>
    <p v-if="!state.required" class="text-xs font-bold text-green-700 dark:text-green-400">✅ مزوّد Anthropic لا يستخدم بيانات الواجهة للتدريب افتراضياً، فلا يلزم تأكيد.</p>
    <template v-else>
      <p v-if="state.valid" class="text-xs font-bold text-green-700 dark:text-green-400" data-test="llm-terms-state">
        ✅ أكّده {{ state.by_name || '—' }}<template v-if="state.at"> · <bdi dir="ltr">{{ when(state.at) }}</bdi></template>
      </p>
      <p v-else-if="state.stale" class="text-xs font-bold text-amber-700 dark:text-amber-400" data-test="llm-terms-state">⚠️ تغيّر المفتاح بعد التأكيد: أكّد من جديد للمفتاح الحالي.</p>
      <p v-else class="text-xs font-bold text-amber-700 dark:text-amber-400" data-test="llm-terms-state">لم يُؤكَّد بعد: الترجمة متوقفة.</p>
      <div class="flex flex-wrap gap-2">
        <button v-if="!state.valid" type="button" :disabled="busy || !state.has_key" @click="set(true)"
                class="min-h-11 px-3 rounded-lg text-xs font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 disabled:opacity-50">أؤكد أن المفتاح على الطبقة المدفوعة</button>
        <button v-if="state.confirmed" type="button" :disabled="busy" @click="set(false)"
                class="min-h-11 px-3 rounded-lg text-xs font-bold border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 disabled:opacity-50">إلغاء التأكيد</button>
      </div>
      <p v-if="!state.has_key" class="text-[11px] text-gray-500 dark:text-gray-400">أضف مفتاح المزوّد أولاً.</p>
      <p class="text-[11px] text-gray-500 dark:text-gray-400">تغيير المزوّد أو المفتاح يلغي التأكيد تلقائياً.</p>
    </template>
    <p v-if="error" class="text-xs text-red-600" role="alert">❌ {{ error }}</p>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue';
import axios from 'axios';
import { useConfirm } from '@/composables/useConfirm';
import { errorText } from '@/composables/whatsapp/useInbox.js';

const props = defineProps({ terms: { type: Object, default: () => ({}) } });
const emit = defineEmits(['changed']);

const { confirm } = useConfirm();
const state = ref({ ...props.terms });
watch(() => props.terms, (v) => { state.value = { ...v }; });
const busy = ref(false);
const error = ref('');
const when = (iso) => new Date(iso).toLocaleString('ar-JO-u-nu-latn', { dateStyle: 'medium', timeStyle: 'short' });

async function set(on) {
  if (on && !(await confirm({
    title: 'تأكيد الطبقة المدفوعة',
    message: 'هل تحققت في Google Cloud أن مشروع المفتاح مربوط بحساب فوترة نشط؟ يُسجَّل هذا التأكيد باسمك.',
    confirmLabel: 'نعم، أؤكد',
  }))) return;
  busy.value = true;
  error.value = '';
  try {
    const { data } = await axios.post('/api/whatsapp/settings/llm-terms', { confirm: on });
    state.value = data.llmTerms;
    emit('changed', data.llmTerms);
  } catch (e) {
    error.value = errorText(e, 'تعذّر الحفظ');
  } finally {
    busy.value = false;
  }
}
</script>
