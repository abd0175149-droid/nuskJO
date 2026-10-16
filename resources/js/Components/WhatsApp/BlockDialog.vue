<template>
  <!-- حوار الحظر الرسمي (E31): سبب وتفاصيل وتأكيد بآخر 4 أرقام (أو «حظر» لمحادثة بلا رقم). الخادم يعيد كل الفحوص -->
  <AppModal :open="open" title="⛔ حظر جهة الاتصال على واتساب" :dirty="dirty" size="md" @close="close">
    <form :id="formId" class="space-y-4" @submit.prevent="submit">
      <div class="rounded-xl bg-gray-50 dark:bg-gray-800/60 px-3 py-2 text-sm">
        <p class="font-bold text-gray-800 dark:text-gray-100"><bdi dir="auto" class="[unicode-bidi:plaintext]">{{ conversation.name }}</bdi></p>
        <p class="text-xs text-gray-500 dark:text-gray-400">
          <template v-if="last4">الرقم ينتهي بـ <bdi dir="ltr" class="font-mono">…{{ last4 }}</bdi></template>
          <template v-else>📵 رقم غير ظاهر (الحظر بالمعرّف)</template>
        </p>
      </div>

      <fieldset>
        <legend class="text-xs font-bold text-gray-600 dark:text-gray-300 mb-1.5">سبب الحظر</legend>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
          <label v-for="(label, key) in reasons" :key="key"
                 class="flex items-center gap-2 px-3 py-2 rounded-xl border cursor-pointer text-sm"
                 :class="code === key ? 'border-red-400 bg-red-50 dark:bg-red-900/20 text-red-800 dark:text-red-200' : 'border-gray-200 dark:border-gray-700'">
            <input v-model="code" type="radio" name="block-reason" :value="key" class="w-4 h-4 text-red-600 shrink-0"/>
            <span>{{ label }}</span>
          </label>
        </div>
      </fieldset>

      <div>
        <label :for="`${formId}-reason`" class="block text-xs font-bold text-gray-600 dark:text-gray-300 mb-1">التفاصيل</label>
        <textarea :id="`${formId}-reason`" v-model="reason" rows="3" maxlength="300" dir="auto"
                  class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm dark:text-white resize-none [unicode-bidi:plaintext]"></textarea>
        <p class="mt-1 flex justify-between gap-2 text-[11px]">
          <span class="text-amber-700 dark:text-amber-400">لا تكتب بيانات شخصية (رقم، جواز، عنوان).</span>
          <span class="tabular-nums" :class="reasonLen >= 10 ? 'text-gray-400' : 'text-red-600 dark:text-red-400'"><bdi dir="ltr">{{ reasonLen }}/300</bdi></span>
        </p>
      </div>

      <p class="rounded-xl border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 px-3 py-2 text-xs text-red-800 dark:text-red-200">
        لن يستطيع مراسلتنا ولن نستطيع مراسلته. البوت يتوقف لهذه المحادثة. يمكن فك الحظر لاحقاً من صفحة المحظورين.
      </p>

      <div>
        <label :for="`${formId}-confirm`" class="block text-xs font-bold text-gray-600 dark:text-gray-300 mb-1">
          {{ last4 ? 'اكتب آخر 4 أرقام للتأكيد' : 'اكتب كلمة «حظر» للتأكيد' }}
        </label>
        <input :id="`${formId}-confirm`" v-model="confirmText" data-autofocus autocomplete="off" :inputmode="last4 ? 'numeric' : 'text'"
               :dir="last4 ? 'ltr' : 'auto'" maxlength="20"
               class="w-full sm:w-40 px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm font-mono dark:text-white"/>
      </div>

      <p v-if="error" class="text-xs text-red-600 dark:text-red-400" role="alert">❌ {{ error }}</p>
    </form>

    <template #footer>
      <button type="button" class="min-h-11 px-4 py-2 rounded-xl text-sm border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200" @click="close">إلغاء</button>
      <button type="submit" :form="formId" :disabled="!ready || saving"
              class="min-h-11 px-5 py-2 rounded-xl text-sm font-bold text-white bg-red-600 hover:bg-red-700 disabled:opacity-50">
        {{ saving ? '...' : '⛔ حظر' }}
      </button>
    </template>
  </AppModal>
</template>

<script setup>
import { computed, ref, useId, watch } from 'vue';
import axios from 'axios';
import AppModal from '@/Components/AppModal.vue';
import { errorText } from '@/composables/whatsapp/useInbox.js';

const props = defineProps({
  open: Boolean,
  // {id, name, phone, phoneless}
  conversation: { type: Object, default: () => ({}) },
  reasons: {
    type: Object,
    default: () => ({ spam: 'إزعاج متكرر', abuse: 'إساءة', fraud: 'احتيال أو انتحال', other: 'أخرى' }),
  },
});
const emit = defineEmits(['close', 'blocked']);

const formId = `block-form-${useId()}`;
const code = ref('');
const reason = ref('');
const confirmText = ref('');
const saving = ref(false);
const error = ref('');

const last4 = computed(() => {
  const digits = String(props.conversation.phone || '').replace(/\D+/g, '');
  return digits.length >= 4 ? digits.slice(-4) : '';
});
const reasonLen = computed(() => reason.value.trim().length);
const dirty = computed(() => !!(code.value || reason.value.trim() || confirmText.value.trim()));
const ready = computed(() => !!code.value && reasonLen.value >= 10 && reasonLen.value <= 300 && confirmText.value.trim() !== '');

watch(() => props.open, (v) => {
  if (!v) return;
  code.value = '';
  reason.value = '';
  confirmText.value = '';
  error.value = '';
  saving.value = false;
});

const close = () => {
  if (!saving.value) emit('close');
};

async function submit() {
  if (!ready.value || saving.value) return;
  saving.value = true;
  error.value = '';
  try {
    const { data } = await axios.post(`/api/whatsapp/conversations/${props.conversation.id}/block`,
      { reason_code: code.value, reason: reason.value.trim(), confirm: confirmText.value.trim() },
      { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    emit('blocked', data);
  } catch (e) {
    error.value = errorText(e, 'تعذّر الحظر');
  } finally {
    saving.value = false;
  }
}
</script>
