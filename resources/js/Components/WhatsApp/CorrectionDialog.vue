<template>
  <!-- رسالة التصحيح (E33، C009): لا تعديل ولا حذف في واتساب الرسمي. تصل العميل رسالة جديدة مقتبسة من الخاطئة، والأصل
       يبقى كما هو عندنا وعنده. «أُرسل تصحيح» لا «عُدّل» -->
  <AppModal :open="open" title="✏️ إرسال تصحيح" :dirty="dirty" @close="$emit('close')">
    <div v-if="message" class="space-y-3 text-sm">
      <div>
        <p class="mb-1 text-xs font-bold text-gray-500 dark:text-gray-400">الرسالة الأصلية</p>
        <p dir="auto" data-test="original" class="p-2.5 rounded-xl bg-gray-50 dark:bg-gray-800 break-words line-clamp-4 [unicode-bidi:plaintext]">{{ snippet }}</p>
      </div>
      <p data-test="correction-warning" class="rounded-lg px-3 py-2 bg-amber-50 dark:bg-amber-900/30 text-amber-900 dark:text-amber-200 text-xs">
        ⚠️ لن يتغيّر نص الرسالة الأصلية عند العميل؛ ستصله رسالة تصحيح مقتبسة منها.
      </p>
      <label class="block">
        <span class="block mb-1 text-xs font-bold text-gray-600 dark:text-gray-300">نص التصحيح</span>
        <textarea v-model="text" data-autofocus rows="4" dir="auto" maxlength="4000" aria-label="نص التصحيح"
                  class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-white resize-none [unicode-bidi:plaintext]"></textarea>
      </label>
      <p v-if="error" role="alert" class="text-xs font-bold text-red-600 dark:text-red-400">❌ {{ error }}</p>
    </div>
    <template #footer>
      <button type="button" @click="$emit('close')"
              class="min-h-11 px-4 rounded-xl font-bold text-sm border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200">إلغاء</button>
      <button type="button" :disabled="!canSend" @click="send" data-test="send-correction"
              class="min-h-11 px-5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">
        {{ sending ? '...' : '📤 إرسال التصحيح' }}
      </button>
    </template>
  </AppModal>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import AppModal from '@/Components/AppModal.vue';
import { snippetOf } from '@/composables/whatsapp/useReplyTarget.js';
import { correct } from '@/composables/whatsapp/useRichSend.js';

const PREFIX = 'تصحيح: ';

const props = defineProps({
  open: Boolean,
  message: { type: Object, default: null },
  conversationId: { type: Number, default: null },
  // تحذير السياسة (409): Promise<boolean> من useConfirm
  confirmFn: { type: Function, default: null },
  // للاختبار: بديل النداء
  sendFn: { type: Function, default: null },
});
const emit = defineEmits(['close', 'sent']);

const text = ref(PREFIX);
const sending = ref(false);
const error = ref('');
watch(() => [props.open, props.message?.id], ([open]) => {
  if (open) {
    text.value = PREFIX;
    error.value = '';
  }
});

const snippet = computed(() => snippetOf(props.message));
const dirty = computed(() => text.value.trim() !== PREFIX.trim());
const canSend = computed(() => !sending.value && dirty.value && text.value.trim().length > 0);

async function send() {
  if (!canSend.value || !props.message) return;
  const call = props.sendFn || correct;
  sending.value = true;
  error.value = '';
  try {
    let res = await call(props.conversationId, props.message.id, text.value.trim());
    if (!res.ok && res.status === 409 && res.data?.code === 'POLICY_WARN') {
      if (!props.confirmFn || !(await props.confirmFn(res.data.error))) {
        error.value = 'لم يُرسل التصحيح.';
        return;
      }
      res = await call(props.conversationId, props.message.id, text.value.trim(), true);
    }
    if (res.ok || res.data?.unknown) {
      emit('sent', res.data);
      return;
    }
    error.value = res.error;
  } finally {
    sending.value = false;
  }
}
</script>
