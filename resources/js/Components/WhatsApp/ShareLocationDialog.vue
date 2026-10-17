<template>
  <!-- دبوس موقع (E33، C006): من المواقع المعتمدة وحدها، وتأكيد يسمّي العميل كتأكيد الملفات (E32). رسالة خدمة مدفوعة -->
  <AppModal :open="open" title="📍 إرسال موقع" @close="$emit('close')">
    <div class="space-y-3 text-sm">
      <p data-test="recipient-line">إلى: <bdi dir="auto" class="font-bold">{{ recipient?.name }}</bdi><template v-if="recipient?.last4"> • <bdi dir="ltr">…{{ recipient.last4 }}</bdi></template></p>
      <p v-if="!places.length" class="text-gray-500 dark:text-gray-400">لا مواقع معتمدة بعد — يضيفها المدير من «المواقع وبطاقات الاتصال».</p>
      <fieldset v-else class="space-y-1.5">
        <legend class="sr-only">اختر الموقع</legend>
        <label v-for="p in places" :key="p.id" class="flex items-start gap-2 p-2.5 rounded-xl border cursor-pointer"
               :class="selected === p.id ? 'border-gold-500 bg-gold-50 dark:bg-gold-900/20' : 'border-gray-200 dark:border-gray-700'">
          <input v-model="selected" type="radio" name="place" :value="p.id" class="mt-1 shrink-0" data-test="place-option"/>
          <span class="min-w-0">
            <span dir="auto" class="block font-bold break-words [unicode-bidi:plaintext]">{{ p.name }}</span>
            <span v-if="p.address" dir="auto" class="block text-xs text-gray-500 dark:text-gray-400 break-words [unicode-bidi:plaintext]">{{ p.address }}</span>
          </span>
        </label>
      </fieldset>
      <p v-if="replyTo" class="text-xs text-gray-500 dark:text-gray-400">↩️ يُرسل مقتبساً من: <bdi dir="auto">{{ replyTo.snippet }}</bdi></p>
      <p v-if="error" role="alert" class="text-xs font-bold text-red-600 dark:text-red-400">❌ {{ error }}</p>
    </div>
    <template #footer>
      <button type="button" @click="$emit('close')"
              class="min-h-11 px-4 rounded-xl font-bold text-sm border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200">إلغاء</button>
      <button type="button" :disabled="!selected || sending" @click="send" data-test="send-location"
              class="min-h-11 px-5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">{{ sending ? '...' : '📤 إرسال الموقع' }}</button>
    </template>
  </AppModal>
</template>

<script setup>
import { ref, watch } from 'vue';
import AppModal from '@/Components/AppModal.vue';
import { sendLocation } from '@/composables/whatsapp/useRichSend.js';

const props = defineProps({
  open: Boolean,
  places: { type: Array, default: () => [] },
  conversationId: { type: Number, default: null },
  // {name, last4} لحظة الفتح
  recipient: { type: Object, default: () => ({}) },
  replyTo: { type: Object, default: null },
  sendFn: { type: Function, default: null },
});
const emit = defineEmits(['close', 'sent']);

const selected = ref(null);
const sending = ref(false);
const error = ref('');
watch(() => props.open, (open) => {
  if (open) {
    selected.value = props.places.length === 1 ? props.places[0].id : null;
    error.value = '';
  }
});

async function send() {
  if (!selected.value) return;
  sending.value = true;
  error.value = '';
  try {
    const res = await (props.sendFn || sendLocation)(props.conversationId, selected.value, props.replyTo?.id || null);
    if (res.ok || res.data?.unknown) emit('sent', res.data);
    else error.value = res.error;
  } finally {
    sending.value = false;
  }
}
</script>
