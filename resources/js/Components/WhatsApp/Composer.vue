<template>
  <div class="p-3 border-t border-gray-200 dark:border-gray-700 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
    <div v-if="!windowOpen" class="mb-2 text-xs text-amber-700 dark:text-amber-400">
      ⚠️ انتهت نافذة الـ24 ساعة — لا يمكن الإرسال حتى يراسلك العميل من جديد.
    </div>
    <div v-else-if="urgency(windowLeft) === 'warn' || urgency(windowLeft) === 'danger'" class="mb-2 text-xs font-bold"
         :class="urgency(windowLeft) === 'danger' ? 'text-red-600 dark:text-red-400' : 'text-amber-700 dark:text-amber-400'">
      ⏳ تبقّى {{ windowLabel(windowLeft) }} للرد على هذا العميل، وبعدها لا يمكن مراسلته حتى يكتب لنا من جديد.
    </div>
    <div v-if="cannotReachPhoneless" class="mb-2 text-xs text-amber-700 dark:text-amber-400">
      📵 رقم العميل غير ظاهر، والرد بالمعرّف غير مفعّل (وضع «حفظ فقط»). يفعّله المدير من إعدادات الواتساب.
    </div>
    <div v-else-if="canRequestPhone" class="mb-2 flex flex-wrap items-center gap-2">
      <button type="button" :disabled="requesting" @click="$emit('requestPhone')"
              class="min-h-11 px-3 py-1.5 rounded-lg text-xs font-bold border border-gold-400 text-gold-700 dark:text-gold-400 disabled:opacity-50">
        {{ requesting ? '...' : '📱 طلب الرقم' }}
      </button>
      <span class="text-[11px] text-gray-400">يصل العميلَ زرٌّ يشارك به رقمه، فيظهر هنا ويُربط بحسابه.</span>
    </div>

    <!-- رسالة صوتية (E34): التسجيل والمعاينة والتأكيد فوق خانة الكتابة، والمسودة النصية لا تتأثر -->
    <VoiceRecorder v-if="voiceEnabled" ref="voiceRef" :max-seconds="voicePolicy.max_seconds || 120" :max-bytes="voicePolicy.max_bytes || 3000000"
                   :conversation-id="conversationId" :recipient="recipient" :send="sendVoice" :env="voiceEnv" @state="(v) => (voiceState = v)"/>
    <form v-if="canReply" @submit.prevent="submit" class="relative flex items-end gap-2">
      <!-- الردود الجاهزة (E17): الزر، أو «/» في بداية خانة فارغة -->
      <button v-if="quickReplies" type="button" :disabled="blocked" @click="pickerOpen = true" aria-label="ردود جاهزة" title="ردود جاهزة (أو اكتب /)"
              class="shrink-0 min-w-11 min-h-11 px-2.5 rounded-xl text-sm border border-gray-200 dark:border-gray-700 hover:border-gold-400 disabled:opacity-50">⚡</button>
      <!-- مرفق (E32): صورة أو PDF أو فيديو، بحوار تأكيد إلزامي. Enter لا يرسل ملفاً أبداً -->
      <template v-if="attachEnabled">
        <button type="button" :disabled="blocked || preparing" @click="fileInput?.click()" aria-label="إرفاق ملف" title="إرفاق صورة أو PDF أو فيديو" data-test="attach"
                class="shrink-0 min-w-11 min-h-11 px-2.5 rounded-xl text-sm border border-gray-200 dark:border-gray-700 hover:border-gold-400 disabled:opacity-50">{{ preparing ? '…' : '📎' }}</button>
        <input ref="fileInput" type="file" class="hidden" :accept="mediaPolicy.accept" @change="onPick" data-test="file-input"/>
      </template>
      <button type="button" :disabled="blocked" @click="emojiOpen = !emojiOpen" aria-label="رموز تعبيرية" title="رموز تعبيرية" :aria-expanded="emojiOpen"
              class="shrink-0 min-w-11 min-h-11 px-2.5 rounded-xl text-sm border border-gray-200 dark:border-gray-700 hover:border-gold-400 disabled:opacity-50 hidden sm:inline-block">😊</button>
      <EmojiPicker v-if="emojiOpen" @pick="insertEmoji" @close="emojiOpen = false"/>
      <!-- نقرة للبدء ونقرة للإيقاف (لا «اضغط مطوّلاً»)؛ يُخفى بلا علم أو ffmpeg أو دعم المتصفح أو مع نافذة مغلقة -->
      <button v-if="voiceButton" type="button" :disabled="blocked || voiceState !== 'idle'" @click="voiceRef?.start()" data-test="voice-record"
              aria-label="تسجيل رسالة صوتية" :title="`تسجيل رسالة صوتية (حتى ${voiceLimit})`"
              class="shrink-0 min-w-11 min-h-11 px-2.5 rounded-xl text-sm border border-gray-200 dark:border-gray-700 hover:border-gold-400 disabled:opacity-50">🎤</button>
      <textarea ref="textarea" :value="modelValue" @input="onInput" @keydown.enter="onEnter"
                rows="2" dir="auto" :disabled="blocked" placeholder="اكتب ردّك..." aria-label="نص الرد"
                class="flex-1 min-w-0 px-3.5 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm resize-none dark:text-white disabled:opacity-50 [unicode-bidi:plaintext]"></textarea>
      <button type="submit" :disabled="blocked || !modelValue.trim() || unfilled.length > 0" aria-label="إرسال"
              class="min-w-11 min-h-11 px-4 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">
        {{ sending ? '...' : '📤' }}
      </button>
    </form>
    <p v-else class="text-xs text-gray-400">لا تملك صلاحية الردّ على المحادثات.</p>
    <!-- متغير لم يُملأ: لا يُرسل (والخادم يرفضه أيضاً) -->
    <p v-if="canReply && unfilled.length" class="mt-1.5 text-xs font-bold text-amber-700 dark:text-amber-400" role="status">
      أكمل قبل الإرسال: <bdi dir="ltr" class="font-mono">{{ unfilledLabel }}</bdi>
    </p>
    <QuickReplyPicker v-if="quickReplies" :open="pickerOpen" :conversation-id="conversationId" @close="pickerOpen = false" @insert="insert"/>
    <p v-if="canReply && fine" class="mt-1 text-[10px] text-gray-400 hidden lg:block">Enter للإرسال، وShift+Enter لسطر جديد</p>
    <p v-if="error" class="mt-1.5 text-xs text-red-600" role="alert">❌ {{ error }}</p>
    <p v-if="attachError" class="mt-1.5 text-xs text-red-600" role="alert" data-test="attach-error">❌ {{ attachError }}</p>
    <SendFileConfirm v-if="attachEnabled" :open="!!pending" :file="pending?.file" :kind="pending?.kind" :recipient="pending?.recipient"
                     :doc-kinds="mediaPolicy.doc_kinds || {}" :max-caption="mediaPolicy.max_caption || 1024"
                     @close="pending = null" @confirm="confirmFile"/>
  </div>
</template>

<script setup>
import { computed, nextTick, ref } from 'vue';
import { isFinePointer, sendOnEnter } from '@/composables/whatsapp/useInputMode.js';
import { insertReply, unfilledVariables } from '@/composables/whatsapp/useQuickReplyRender.js';
import { prepareAttachment } from '@/composables/whatsapp/useAttachment.js';
import QuickReplyPicker from './QuickReplyPicker.vue';
import EmojiPicker from './EmojiPicker.vue';
import SendFileConfirm from './SendFileConfirm.vue';
import VoiceRecorder from './VoiceRecorder.vue';
import { clock, voiceSupported } from '@/composables/whatsapp/useVoiceRecorder.js';
import { urgency, windowLabel } from '@/composables/whatsapp/useWindowCountdown.js';

const props = defineProps({
  modelValue: { type: String, default: '' },
  canReply: Boolean,
  windowOpen: Boolean,
  windowLeft: { type: Number, default: null },
  sending: Boolean,
  error: { type: String, default: '' },
  cannotReachPhoneless: Boolean,
  canRequestPhone: Boolean,
  requesting: Boolean,
  // للاختبار: يتجاوز اكتشاف المؤشر (undefined = اكتشاف من المتصفح)
  finePointer: { type: Boolean, default: undefined },
  // الردود الجاهزة (E17)
  quickReplies: Boolean,
  conversationId: { type: Number, default: null },
  // المرفقات (E32): سياسة الخادم (الأنواع والحدود والعلم)، والمستلم لحوار التأكيد {name, last4, clientCode}
  mediaPolicy: { type: Object, default: null },
  recipient: { type: Object, default: () => ({}) },
  // للاختبار: بديل التحويل إلى JPEG
  convertImage: { type: Function, default: undefined },
  // الرسائل الصوتية (E34): {enabled, max_seconds, max_bytes} من الخادم، ودالة الإرسال من الصندوق، ونافذة بديلة للاختبار
  voicePolicy: { type: Object, default: null },
  sendVoice: { type: Function, default: null },
  voiceEnv: { type: Object, default: undefined },
});
const emit = defineEmits(['update:modelValue', 'submit', 'requestPhone', 'sendFile']);

// على اللمس Enter سطر جديد والإرسال بالزر؛ على سطح المكتب Enter يرسل
const fine = props.finePointer ?? isFinePointer();
const blocked = computed(() => !props.windowOpen || props.sending || props.cannotReachPhoneless);

const unfilled = computed(() => unfilledVariables(props.modelValue));
const unfilledLabel = computed(() => unfilled.value.map((v) => `{{${v}}}`).join(' '));
const pickerOpen = ref(false);

const submit = () => {
  if (!blocked.value && props.modelValue.trim() && !unfilled.value.length) emit('submit');
};
const onInput = (e) => {
  const v = e.target.value;
  emit('update:modelValue', v);
  if (props.quickReplies && !blocked.value && v === '/') pickerOpen.value = true;
};
const insert = (text) => {
  pickerOpen.value = false;
  emit('update:modelValue', insertReply(props.modelValue, text));
};
const onEnter = (e) => {
  if (!sendOnEnter(e, fine)) return;
  e.preventDefault();
  submit();
};

// ===== المرفق (E32): فحص فوري في المتصفح، ثم حوار التأكيد. المحادثة والمستلم يُثبَّتان لحظة الاختيار =====
const attachEnabled = computed(() => !!props.mediaPolicy?.enabled);
const fileInput = ref(null);
const preparing = ref(false);
const attachError = ref('');
const pending = ref(null);

const onPick = async (e) => {
  const file = e.target.files?.[0];
  e.target.value = '';
  if (!file) return;
  attachError.value = '';
  preparing.value = true;
  try {
    const ready = await prepareAttachment(file, props.mediaPolicy, props.convertImage);
    if (!ready.ok) {
      attachError.value = ready.error;
      return;
    }
    pending.value = { file: ready.file, kind: ready.kind, conversationId: props.conversationId, recipient: { ...props.recipient } };
  } finally {
    preparing.value = false;
  }
};

const confirmFile = (choice) => {
  const p = pending.value;
  pending.value = null;
  if (p) emit('sendFile', { file: p.file, kind: p.kind, conversationId: p.conversationId, ...choice });
};

// ===== الرسالة الصوتية (E34) =====
const voiceEnabled = computed(() => !!props.voicePolicy?.enabled && !!props.sendVoice);
const voiceButton = computed(() => voiceEnabled.value && props.windowOpen && voiceSupported(props.voiceEnv ?? globalThis));
const voiceLimit = computed(() => clock(props.voicePolicy?.max_seconds || 120));
const voiceRef = ref(null);
const voiceState = ref('idle');

// ===== الإيموجي: عند موضع المؤشر =====
const emojiOpen = ref(false);
const textarea = ref(null);
const insertEmoji = async (emoji) => {
  const el = textarea.value;
  const text = props.modelValue || '';
  const start = el?.selectionStart ?? text.length;
  const end = el?.selectionEnd ?? start;
  emit('update:modelValue', text.slice(0, start) + emoji + text.slice(end));
  emojiOpen.value = false;
  await nextTick();
  if (el) {
    el.focus();
    el.setSelectionRange(start + emoji.length, start + emoji.length);
  }
};
</script>
