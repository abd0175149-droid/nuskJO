<template>
  <!-- بطاقات الاتصال (E33، C007): من البطاقات المعتمدة وحدها وحتى 3، وتأكيد يسمّي العميل. الأرقام لا تظهر هنا: يرسلها الخادم من الجدول -->
  <AppModal :open="open" title="👤 إرسال بطاقة اتصال" @close="$emit('close')">
    <div class="space-y-3 text-sm">
      <p data-test="recipient-line">إلى: <bdi dir="auto" class="font-bold">{{ recipient?.name }}</bdi><template v-if="recipient?.last4"> • <bdi dir="ltr">…{{ recipient.last4 }}</bdi></template></p>
      <p v-if="!cards.length" class="text-gray-500 dark:text-gray-400">لا بطاقات معتمدة بعد — يضيفها المدير من «المواقع وبطاقات الاتصال».</p>
      <fieldset v-else class="space-y-1.5">
        <legend class="text-xs text-gray-500 dark:text-gray-400">اختر حتى {{ max }} بطاقات</legend>
        <label v-for="c in cards" :key="c.id" class="flex items-start gap-2 p-2.5 rounded-xl border"
               :class="[selected.includes(c.id) ? 'border-gold-500 bg-gold-50 dark:bg-gold-900/20' : 'border-gray-200 dark:border-gray-700', disabled(c.id) ? 'opacity-50' : 'cursor-pointer']">
          <input v-model="selected" type="checkbox" :value="c.id" :disabled="disabled(c.id)" class="mt-1 shrink-0" data-test="card-option"/>
          <span class="min-w-0">
            <span dir="auto" class="block font-bold break-words [unicode-bidi:plaintext]">{{ c.label }}</span>
            <span dir="auto" class="block text-xs text-gray-500 dark:text-gray-400 break-words [unicode-bidi:plaintext]">{{ c.name }}<template v-if="c.org"> — {{ c.org }}</template></span>
          </span>
        </label>
      </fieldset>
      <p v-if="error" role="alert" class="text-xs font-bold text-red-600 dark:text-red-400">❌ {{ error }}</p>
    </div>
    <template #footer>
      <button type="button" @click="$emit('close')"
              class="min-h-11 px-4 rounded-xl font-bold text-sm border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200">إلغاء</button>
      <button type="button" :disabled="!selected.length || sending" @click="send" data-test="send-contacts"
              class="min-h-11 px-5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">{{ sending ? '...' : '📤 إرسال' }}</button>
    </template>
  </AppModal>
</template>

<script setup>
import { ref, watch } from 'vue';
import AppModal from '@/Components/AppModal.vue';
import { sendContacts } from '@/composables/whatsapp/useRichSend.js';

const props = defineProps({
  open: Boolean,
  cards: { type: Array, default: () => [] },
  conversationId: { type: Number, default: null },
  recipient: { type: Object, default: () => ({}) },
  replyTo: { type: Object, default: null },
  max: { type: Number, default: 3 },
  sendFn: { type: Function, default: null },
});
const emit = defineEmits(['close', 'sent']);

const selected = ref([]);
const sending = ref(false);
const error = ref('');
watch(() => props.open, (open) => {
  if (open) {
    selected.value = [];
    error.value = '';
  }
});
const disabled = (id) => selected.value.length >= props.max && !selected.value.includes(id);

async function send() {
  if (!selected.value.length) return;
  sending.value = true;
  error.value = '';
  try {
    const res = await (props.sendFn || sendContacts)(props.conversationId, [...selected.value], props.replyTo?.id || null);
    if (res.ok || res.data?.unknown) emit('sent', res.data);
    else error.value = res.error;
  } finally {
    sending.value = false;
  }
}
</script>
