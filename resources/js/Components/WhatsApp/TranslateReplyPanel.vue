<template>
  <!-- معاينة ترجمة رد الموظف (E29): النص العربي للقراءة، والترجمة قابلة للتعديل، ومقارنة الأرقام محلياً مع كل تعديل.
       «إرسال الترجمة» معطّل ما لم تتطابق الأرقام، والخادم يعيد الفحص ويبقى الحَكَم -->
  <AppModal :open="open" title="🌐 معاينة ترجمة الرد" size="lg" @close="$emit('close')">
    <div class="space-y-3">
      <div>
        <p class="text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">نصّك بالعربية</p>
        <p dir="auto" class="p-2.5 rounded-xl bg-gray-50 dark:bg-gray-800 text-sm whitespace-pre-line break-words [unicode-bidi:plaintext]" data-test="source">{{ sourceText }}</p>
      </div>

      <div>
        <div class="flex items-center justify-between gap-2 mb-1">
          <label :for="textId" class="text-xs font-bold text-gray-500 dark:text-gray-400">الترجمة إلى {{ targets[target] || target }} (يمكنك تعديلها)</label>
          <button type="button" :disabled="loading || sending" @click="translate" class="text-[11px] font-bold text-gold-700 dark:text-gold-400 disabled:opacity-50">🔄 أعد الترجمة</button>
        </div>
        <p v-if="loading" class="py-4 text-center text-sm text-gray-400">⏳ جارٍ الترجمة...</p>
        <textarea v-else :id="textId" v-model="translation" rows="5" dir="auto" maxlength="4000" data-test="translation"
                  class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 text-sm dark:text-white resize-y [unicode-bidi:plaintext]"></textarea>
      </div>

      <div v-if="!loading && translation" class="space-y-1 text-xs" data-test="numbers">
        <p class="flex flex-wrap items-center gap-1">
          <span class="font-bold">الأرقام:</span>
          <span class="text-gray-500 dark:text-gray-400">الأصل</span>
          <span v-for="(n, i) in sourceChips" :key="'s' + i" :class="n.ok ? OK : BAD" class="px-1.5 rounded font-mono" dir="ltr">{{ n.value }}</span>
          <span v-if="!sourceChips.length" class="text-gray-400">—</span>
          <span class="text-gray-500 dark:text-gray-400">↔ الترجمة</span>
          <span v-for="(n, i) in translatedChips" :key="'t' + i" :class="n.ok ? OK : BAD" class="px-1.5 rounded font-mono" dir="ltr">{{ n.value }}</span>
          <span v-if="!translatedChips.length" class="text-gray-400">—</span>
        </p>
        <p v-if="check.spelled" class="font-bold text-red-700 dark:text-red-300" role="status" data-test="spelled">
          أرقام مكتوبة بالحروف في الترجمة: <bdi dir="auto">{{ spelledList }}</bdi> — اكتبها أرقاماً.
        </p>
        <p v-else-if="!check.ok" class="font-bold text-red-700 dark:text-red-300" role="status">أرقام الترجمة لا تطابق نصّك: صحّحها قبل الإرسال.</p>
        <p v-else class="font-bold text-green-700 dark:text-green-400" role="status">✅ الأرقام متطابقة</p>
      </div>
      <p v-if="error" class="text-xs font-bold text-red-600 dark:text-red-400" role="alert" data-test="error">❌ {{ error }}</p>
    </div>
    <template #footer>
      <button type="button" @click="$emit('close')" class="min-h-11 px-4 rounded-xl text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800">إلغاء</button>
      <button type="button" :disabled="!canSend" @click="send" data-test="send"
              class="min-h-11 px-5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">
        {{ sending ? '...' : '📤 إرسال الترجمة' }}
      </button>
    </template>
  </AppModal>
</template>

<script setup>
import { computed, ref, useId, watch } from 'vue';
import axios from 'axios';
import AppModal from '@/Components/AppModal.vue';
import { useConfirm } from '@/composables/useConfirm';
import { errorText } from '@/composables/whatsapp/useInbox.js';
import { createSendKeys } from '@/composables/whatsapp/useSendKey.js';
import { compare } from '@/utils/numberGuard.js';

const props = defineProps({
  open: Boolean,
  conversationId: { type: Number, default: null },
  sourceText: { type: String, default: '' },
  target: { type: String, default: 'en' },
  targets: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['close', 'sent']);

const OK = 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200';
const BAD = 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200';
const api = { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
const textId = `tr-text-${useId()}`;
const { confirm } = useConfirm();
// مفتاح Idempotency-Key لكل نص (E19): نفسه لإعادة النص نفسه، وجديد إن عُدّلت الترجمة
const sendKeys = createSendKeys();

const translation = ref('');
const token = ref('');
const loading = ref(false);
const sending = ref(false);
const error = ref('');

const check = computed(() => compare(props.sourceText, translation.value, props.target));
const spelledList = computed(() => check.value.spelled_words.join('، '));
const canSend = computed(() => !!token.value && !!translation.value.trim() && check.value.ok && !sending.value && !loading.value);

/** شرائح القائمة: ما يقابله في الطرف الآخر أخضر، والناقص أو الزائد أحمر (بتعدده) */
function chips(list, unmatched) {
  const left = new Map();
  unmatched.forEach((n) => left.set(n, (left.get(n) || 0) + 1));
  return list.map((value) => {
    const bad = (left.get(value) || 0) > 0;
    if (bad) left.set(value, left.get(value) - 1);
    return { value, ok: !bad };
  });
}
const sourceChips = computed(() => chips(check.value.source, check.value.missing));
const translatedChips = computed(() => chips(check.value.translated, check.value.extra));

async function translate() {
  if (!props.conversationId) return;
  loading.value = true;
  error.value = '';
  token.value = '';
  try {
    const { data } = await axios.post(`/api/whatsapp/conversations/${props.conversationId}/translate-reply`, { text: props.sourceText, target: props.target }, api);
    translation.value = data.translation || '';
    token.value = data.check_token || '';
  } catch (e) {
    translation.value = '';
    error.value = errorText(e, 'تعذّرت الترجمة');
  } finally {
    loading.value = false;
  }
}

async function send() {
  if (!canSend.value) return;
  const id = props.conversationId;
  const body = translation.value.trim();
  const payload = { body, translation: { source_text: props.sourceText, target: props.target, check_token: token.value } };
  const headers = { ...api.headers, 'Idempotency-Key': sendKeys.keyFor(id, body) };
  const url = `/api/whatsapp/conversations/${id}/send-translated`;
  sending.value = true;
  error.value = '';
  try {
    let res;
    try {
      res = await axios.post(url, payload, { headers });
    } catch (e) {
      if (e?.response?.status !== 409 || e.response.data?.code !== 'POLICY_WARN') throw e;
      if (!(await confirm({ title: 'تحذير قبل الإرسال', message: e.response.data.error, confirmLabel: 'إرسال رغم التحذير', danger: true }))) return;
      res = await axios.post(url, { ...payload, ack_warnings: true }, { headers });
    }
    sendKeys.done(id);
    emit('sent', { unknown: !!res?.data?.unknown, message: res?.data?.unknown ? (res.data.error || 'قد تكون الرسالة وصلت.') : '' });
  } catch (e) {
    const d = e?.response?.data || {};
    error.value = d.code === 'NUMBERS_MISMATCH' && (d.missing?.length || d.extra?.length)
      ? `${d.error}: الناقص ${(d.missing || []).join('، ') || '—'}، والزائد ${(d.extra || []).join('، ') || '—'}`
      : errorText(e, 'تعذّر الإرسال');
    if (d.code === 'IDEMPOTENCY_MISMATCH') sendKeys.rotate(id);
  } finally {
    sending.value = false;
  }
}

watch(() => props.open, (v) => {
  if (!v) return;
  translation.value = '';
  token.value = '';
  error.value = '';
  translate();
});
</script>
