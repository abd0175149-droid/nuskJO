<template>
  <!-- المسجّل (E34.7): نقرة للبدء من زر 🎤 في المنشئ ونقرة للإيقاف (بلا «اضغط مطوّلاً»)، ومؤقت ظاهر، وEsc يلغي، ومعاينة
       قبل الإرسال، ثم تأكيد المستلم بالاسم وآخر 4 أرقام كالملفات (E32). الميكروفون يُطفأ عند الإيقاف والحذف وتغيير المحادثة -->
  <div v-if="r.status !== 'idle' || r.error" data-test="voice-panel"
       class="mb-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/60 px-3 py-2 text-gray-800 dark:text-gray-100">
    <div v-if="r.status === 'starting' || r.status === 'recording'" class="flex flex-wrap items-center gap-2">
      <span class="inline-flex items-center gap-1.5 text-sm font-bold text-red-600 dark:text-red-400">
        <span class="w-2.5 h-2.5 rounded-full bg-red-600 dark:bg-red-400" :class="r.status === 'recording' ? 'animate-pulse' : 'opacity-50'" aria-hidden="true"></span>
        {{ r.status === 'starting' ? 'اسمح باستعمال الميكروفون…' : 'جارٍ التسجيل' }}
      </span>
      <span role="timer" aria-live="polite" aria-label="مدة التسجيل" dir="ltr" class="font-mono text-sm tabular-nums" data-test="voice-timer">{{ clock(r.elapsed) }} / {{ clock(maxSeconds) }}</span>
      <span class="flex-1"></span>
      <button type="button" data-test="voice-stop" :disabled="r.status !== 'recording'" @click="recorder.stop()"
              class="min-h-11 px-3 rounded-xl text-sm font-bold text-white bg-red-600 hover:bg-red-700 disabled:opacity-50">⏹ إيقاف</button>
      <button type="button" data-test="voice-cancel" @click="cancel"
              class="min-h-11 px-3 rounded-xl text-sm border border-gray-200 dark:border-gray-700 hover:border-gold-400">✖ إلغاء</button>
    </div>
    <div v-else-if="r.status === 'preview' || r.status === 'sending'" class="flex flex-wrap items-center gap-2">
      <audio :src="r.url || undefined" controls preload="metadata" aria-label="معاينة الرسالة الصوتية" data-test="voice-preview"
             class="w-full sm:w-auto sm:flex-1 min-w-0 h-11"></audio>
      <span dir="ltr" class="text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ clock(r.seconds) }}</span>
      <span class="flex-1 sm:hidden"></span>
      <button type="button" data-test="voice-send" :disabled="r.status === 'sending' || tooLarge" @click="confirming = true"
              class="min-h-11 px-4 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">
        {{ r.status === 'sending' ? '...' : (busy ? '🔁 أعد الإرسال' : '📤 إرسال') }}
      </button>
      <button type="button" data-test="voice-delete" :disabled="r.status === 'sending'" @click="cancel"
              class="min-h-11 px-3 rounded-xl text-sm border border-gray-200 dark:border-gray-700 hover:border-gold-400 disabled:opacity-50">🗑️ حذف</button>
    </div>
    <p v-if="r.error" role="alert" class="text-xs text-red-600 dark:text-red-400 flex items-start gap-2" :class="r.status === 'idle' ? '' : 'mt-1.5'" data-test="voice-error">
      <span class="flex-1">❌ {{ r.error }}</span>
      <button v-if="r.status === 'idle'" type="button" aria-label="إخفاء" @click="recorder.setError('')" class="shrink-0 px-1 opacity-70 hover:opacity-100">✖</button>
    </p>
  </div>

  <AppModal :open="confirming" title="تأكيد إرسال رسالة صوتية" @close="confirming = false">
    <div class="space-y-2 text-sm">
      <p data-test="voice-line">سترسل: 🎤 رسالة صوتية <bdi dir="ltr" class="text-xs text-gray-500 dark:text-gray-400">({{ clock(r.seconds) }})</bdi></p>
      <p data-test="voice-recipient">إلى: <bdi dir="auto" class="font-bold">{{ target.recipient?.name }}</bdi><template v-if="target.recipient?.last4"> • <bdi dir="ltr">…{{ target.recipient.last4 }}</bdi></template><template v-if="target.recipient?.clientCode"> • <bdi dir="ltr">{{ target.recipient.clientCode }}</bdi></template></p>
      <p class="text-xs text-gray-500 dark:text-gray-400">تصل العميل رسالة صوتية بزر تشغيل من رقم الشركة، وتبقى نسختها في سجل المحادثة.</p>
    </div>
    <template #footer>
      <button type="button" @click="confirming = false"
              class="min-h-11 px-4 rounded-xl font-bold text-sm border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200">إلغاء</button>
      <button type="button" data-autofocus data-test="voice-confirm" @click="confirmSend"
              class="min-h-11 px-5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md">📤 إرسال</button>
    </template>
  </AppModal>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AppModal from '@/Components/AppModal.vue';
import { clock, createVoiceRecorder } from '@/composables/whatsapp/useVoiceRecorder.js';

const props = defineProps({
  maxSeconds: { type: Number, default: 120 },
  maxBytes: { type: Number, default: 3_000_000 },
  conversationId: { type: Number, default: null },
  // {name, last4, clientCode} لحظة بدء التسجيل: لا يتغير المستلم بتبديل المحادثة
  recipient: { type: Object, default: () => ({}) },
  // (payload) => Promise<{ok, network?, status?, code?, error?}>
  send: { type: Function, required: true },
  // للاختبار: نافذة بديلة فيها MediaRecorder وnavigator وURL
  env: { type: Object, default: undefined },
  makeKey: { type: Function, default: undefined },
});
const emit = defineEmits(['state']);

const recorder = createVoiceRecorder({ maxSeconds: props.maxSeconds, maxBytes: props.maxBytes, env: props.env ?? globalThis,
  ...(props.makeKey ? { makeKey: props.makeKey } : {}) });
const r = recorder.state;
const confirming = ref(false);
const busy = ref(false);
const target = ref({ conversationId: null, recipient: {} });
const tooLarge = computed(() => r.size > props.maxBytes);

watch(() => r.status, (v) => emit('state', v));

async function start() {
  busy.value = false;
  target.value = { conversationId: props.conversationId, recipient: { ...props.recipient } };
  return recorder.start();
}

function cancel() {
  confirming.value = false;
  busy.value = false;
  recorder.discard();
}

async function confirmSend() {
  confirming.value = false;
  const file = recorder.file();
  if (!file || tooLarge.value) return;
  const key = r.key;
  recorder.setError('');
  recorder.setSending(true);
  let res;
  try {
    res = await props.send({ file, key, seconds: r.seconds, conversationId: target.value.conversationId });
  } catch {
    res = { ok: false, error: 'تعذّر إرسال الرسالة الصوتية.' };
  }
  // تغيّرت المحادثة أثناء الإرسال: المسجّل صُفّر، والفقاعة تكمل
  if (r.key !== key) return;
  if (res?.ok || res?.network) {
    busy.value = false;
    recorder.sent();
    return;
  }
  recorder.setSending(false);
  // «المحوّل مشغول»: التسجيل باقٍ بمفتاحه، و«أعد الإرسال» يعيده (لم يُحجز شيء)
  busy.value = res?.code === 'CONVERTER_BUSY';
  recorder.setError(res?.error || 'تعذّر إرسال الرسالة الصوتية.');
}

// Esc يلغي التسجيل الجاري
function onKey(e) {
  if (e.key === 'Escape' && (r.status === 'recording' || r.status === 'starting')) {
    e.preventDefault();
    cancel();
  }
}
watch(() => r.status === 'recording' || r.status === 'starting', (on) => {
  if (on) document.addEventListener('keydown', onKey);
  else document.removeEventListener('keydown', onKey);
});

watch(() => props.conversationId, () => cancel());
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKey);
  recorder.discard();
});

defineExpose({ start, cancel, state: r });
</script>
