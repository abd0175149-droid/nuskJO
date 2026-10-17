<template>
  <!-- إرسال الفاتورة PDF على واتساب (E32.9): محادثات عميل الفاتورة نفسه المرئية للموظف، بالاسم وآخر 4 أرقام والنافذة،
       وسطر تأكيد بارز. الخادم يطابق العميل والمحادثة المؤكَّدة، والرفع إلى ميتا في الطابور -->
  <AppModal :open="open" :title="`📤 إرسال الفاتورة ${invoice?.invoice_number || ''} على واتساب`" @close="$emit('close')">
    <div class="space-y-3 text-sm">
      <p v-if="loading" class="text-gray-500">جارٍ تحميل محادثات العميل…</p>
      <template v-else-if="done">
        <p class="font-bold text-green-700 dark:text-green-400" role="status">✅ في الطابور — تُرفع الفاتورة وتُرسل للعميل الآن.</p>
        <a :href="`/whatsapp/inbox?conv=${done}`" class="inline-block font-bold text-gold-700 dark:text-gold-400 underline">فتح المحادثة</a>
      </template>
      <template v-else>
        <p v-if="message" class="rounded-lg px-3 py-2 bg-amber-50 dark:bg-amber-900/30 text-amber-900 dark:text-amber-200" data-test="no-targets">{{ message }}</p>
        <p v-if="!sendable" class="rounded-lg px-3 py-2 bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200">لا تُرسل فاتورة غير معتمدة.</p>

        <fieldset v-if="targets.length" class="space-y-1.5">
          <legend class="mb-1 text-xs font-bold text-gray-600 dark:text-gray-300">المحادثة</legend>
          <label v-for="t in targets" :key="t.conversation_id"
                 class="flex items-center gap-2 min-h-11 px-3 rounded-xl border cursor-pointer"
                 :class="[selected === t.conversation_id ? 'border-gold-400 bg-gold-50 dark:bg-gold-900/30' : 'border-gray-200 dark:border-gray-700',
                          t.window_open ? '' : 'opacity-60 cursor-not-allowed']">
            <input v-model="selected" type="radio" name="wa-target" :value="t.conversation_id" :disabled="!t.window_open" class="w-4 h-4 shrink-0"/>
            <span class="min-w-0 flex-1">
              <bdi dir="auto" class="font-bold">{{ t.name }}</bdi>
              <template v-if="t.last4"> • <bdi dir="ltr">…{{ t.last4 }}</bdi></template>
              <template v-if="t.client_code"> • <bdi dir="ltr">{{ t.client_code }}</bdi></template>
            </span>
            <span class="shrink-0 text-[11px]" :class="t.window_open ? 'text-green-700 dark:text-green-400' : 'text-gray-500'">
              {{ t.window_open ? `النافذة: ${windowLabel(t.window_left)}` : 'النافذة مغلقة' }}
            </span>
          </label>
        </fieldset>

        <div class="flex flex-wrap items-center gap-2 text-xs">
          <span>الملف: <bdi dir="auto" class="font-bold">{{ filename }}</bdi></span>
          <a :href="`/invoices/${invoice?.id}/pdf`" target="_blank" rel="noopener" class="font-bold text-purple-700 dark:text-purple-300 underline">⬇️ معاينة PDF</a>
        </div>

        <label class="block">
          <span class="block mb-1 text-xs font-bold text-gray-600 dark:text-gray-300">التعليق</span>
          <textarea v-model="caption" maxlength="1024" rows="2" dir="auto" aria-label="تعليق الفاتورة"
                    class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-white resize-none [unicode-bidi:plaintext]"></textarea>
        </label>

        <p v-if="chosen" class="rounded-lg px-3 py-2 bg-gold-50 dark:bg-gold-900/30 font-bold" data-test="confirm-line">
          سترسل فاتورة <bdi dir="ltr">{{ invoice?.invoice_number }}</bdi> إلى <bdi dir="auto">{{ chosen.name }}</bdi><template v-if="chosen.last4"> • <bdi dir="ltr">…{{ chosen.last4 }}</bdi></template>
        </p>
        <p v-if="error" class="text-xs text-red-600 dark:text-red-400" role="alert">❌ {{ error }}</p>
      </template>
    </div>

    <template #footer>
      <button type="button" @click="$emit('close')" class="min-h-11 px-4 rounded-xl font-bold text-sm border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200">
        {{ done ? 'إغلاق' : 'إلغاء' }}
      </button>
      <button v-if="!done" type="button" data-autofocus :disabled="!canSend" @click="send" data-test="send-invoice"
              class="min-h-11 px-5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">
        {{ sending ? '...' : (retrying ? '🔁 إعادة المحاولة' : '📤 إرسال') }}
      </button>
    </template>
  </AppModal>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import AppModal from '@/Components/AppModal.vue';
import { useConfirm } from '@/composables/useConfirm';
import { uuid } from '@/composables/whatsapp/useSendKey.js';
import { windowLabel } from '@/composables/whatsapp/useWindowCountdown.js';
import { errorText } from '@/composables/whatsapp/useInbox.js';

const props = defineProps({
  open: Boolean,
  invoice: { type: Object, default: null },
});
defineEmits(['close']);

const api = { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
const { confirm } = useConfirm();

const loading = ref(false);
const targets = ref([]);
const sendable = ref(true);
const filename = ref('');
const caption = ref('');
const message = ref('');
const selected = ref(null);
const sending = ref(false);
const error = ref('');
const done = ref(null);
// مفتاح المحاولة: يبقى بعد انقطاع الشبكة (ربما وصل الطلب)، ويتجدد بعد رد صريح من الخادم
const key = ref(uuid());
const retrying = ref(false);

const chosen = computed(() => targets.value.find((t) => t.conversation_id === selected.value) || null);
const canSend = computed(() => !!chosen.value?.window_open && sendable.value && !sending.value && caption.value.length <= 1024);

async function load() {
  if (!props.invoice?.id) return;
  loading.value = true;
  error.value = '';
  try {
    const { data } = await axios.get(`/api/invoices/${props.invoice.id}/whatsapp-targets`, api);
    targets.value = data.targets || [];
    sendable.value = !!data.sendable;
    filename.value = data.filename || '';
    caption.value = data.caption || '';
    message.value = data.message || '';
    const open = targets.value.filter((t) => t.window_open);
    selected.value = open.length === 1 ? open[0].conversation_id : null;
  } catch (e) {
    error.value = errorText(e, 'تعذّر تحميل محادثات العميل');
  } finally {
    loading.value = false;
  }
}

watch(() => props.open, (v) => {
  if (!v) return;
  targets.value = [];
  done.value = null;
  selected.value = null;
  key.value = uuid();
  retrying.value = false;
  load();
}, { immediate: true });

async function send(ack = false) {
  if (!canSend.value && !ack) return;
  const conversationId = selected.value;
  sending.value = true;
  error.value = '';
  try {
    await axios.post(`/api/invoices/${props.invoice.id}/send-whatsapp`,
      { conversation_id: conversationId, confirm_conversation_id: conversationId, caption: caption.value, ...(ack ? { ack_warnings: true } : {}) },
      { headers: { ...api.headers, 'Idempotency-Key': key.value } });
    done.value = conversationId;
    retrying.value = false;
  } catch (e) {
    const res = e?.response;
    if (!res) {
      retrying.value = true;
      error.value = 'انقطع الاتصال — أعد المحاولة؛ لن تتكرر الفاتورة إن كانت وصلت.';
      return;
    }
    if (res.status === 409 && res.data?.code === 'POLICY_WARN'
      && await confirm({ title: 'تحذير قبل الإرسال', message: res.data.error, confirmLabel: 'إرسال رغم التحذير', danger: true })) {
      sending.value = false;
      return send(true);
    }
    key.value = uuid();
    retrying.value = false;
    error.value = errorText(e, 'تعذّر إرسال الفاتورة');
  } finally {
    sending.value = false;
  }
}
</script>
