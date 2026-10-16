<template>
  <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden flex flex-col min-h-0" aria-label="المحادثة">
    <template v-if="thread">
      <ThreadHeader :thread="thread" :topics="topics" :staff="staff" :attention-reasons="attentionReasons"
                    :can-link-client="canLinkClient" :can-toggle-bot="canToggleBot" :can-assign="canAssign" show-back
                    :followup="followup" :can-reply="canReply" :can-reprocess="canReprocess" @action="(n, p) => $emit('action', n, p)"
                    @back="$emit('back')" @link="$emit('link')" @unlink="$emit('unlink')" @resolve="$emit('resolve')"
                    @toggle-bot="$emit('toggleBot')" @set-topic="(k, a) => $emit('setTopic', k, a)" @assign="(el) => $emit('assign', el)"
                    :can-filter="canFilter" :filters-open="filtersOpen || filtered" :can-export="canExport"
                    @toggle-filters="filtersOpen = !filtersOpen" @export="$emit('export')"/>
      <!-- التصنيفات والملاحظة المثبتة وبطاقة الحقائق (E29): داخلية لا يراها العميل -->
      <ThreadLabels v-if="flags.wa_labels" :conversation-id="thread.id" :current="thread.labels || []" :all="labels" :can-reply="canReply"
                    :can-manage="canManageLabels" @changed="(l) => $emit('labelsChanged', thread.id, l)"/>
      <FactCard v-if="flags.thread_pins" ref="factCard" :conversation-id="thread.id" :can-reply="canReply" @jump="jumpTo" @pins="(ids) => (pinnedIds = new Set(ids))"/>

      <!-- فلاتر الخيط (E30): تُطوى بزر «تصفية» فتتسع الرسائل على الجوال، وشريط «عرض مفلتر» باقٍ ما دام فلتر -->
      <ThreadFilters v-if="canFilter && filtersOpen" :filters="filters" @apply="(f) => $emit('setFilters', f)" @clear="$emit('clearFilters')"/>
      <div v-if="filtered" role="status" class="flex items-center justify-between gap-2 px-3 sm:px-4 py-1.5 text-[11px] bg-gold-50 dark:bg-gold-900/20 text-gold-800 dark:text-gold-300 shrink-0">
        <span>عرض مفلتر — لا تظهر كل الرسائل</span>
        <button type="button" @click="$emit('clearFilters')" class="min-h-8 px-2 rounded-lg font-bold hover:bg-gold-100 dark:hover:bg-gold-900/40">إلغاء</button>
      </div>

      <!-- الوضع on_open (E35): الموكَّل يعرف أن فتحه يُظهر القراءة للعميل -->
      <p v-if="thread.receipts_open_notice" data-test="open-receipt-notice"
         class="px-4 py-1 text-[11px] text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-900 border-b border-gray-100 dark:border-gray-800 shrink-0">
        👁 فتحك لهذه المحادثة يُظهر للعميل أنك قرأت رسائله
      </p>

      <div class="relative flex-1 min-h-0 flex flex-col">
        <div ref="scroller" @scroll.passive="onScroll" class="flex-1 overflow-y-auto p-3 sm:p-4 space-y-2 bg-gray-50 dark:bg-gray-950/40" aria-live="polite">
          <div v-if="hasMore || loadingOlder" class="text-center">
            <button type="button" :disabled="loadingOlder" @click="$emit('loadOlder')"
                    class="px-3 py-1 rounded-lg text-[11px] text-gray-500 dark:text-gray-400 hover:text-gold-700 dark:hover:text-gold-400 disabled:opacity-60">
              {{ loadingOlder ? 'جارٍ تحميل الأقدم...' : '↑ رسائل أقدم' }}
            </button>
          </div>
          <p v-if="jumpMiss" class="text-center text-[11px] text-amber-700 dark:text-amber-400" role="status">الرسالة المثبتة أقدم من المعروض — حمّل الرسائل الأقدم ثم أعد المحاولة.</p>
          <MessageBubble v-for="m in messages" :key="m.id" :m="m" :data-msg-id="m.id" :flash="flashId === m.id" :read-upto="thread.read_receipt_upto_id || null" @resend="(msg) => $emit('resend', msg)">
            <template v-if="m.media || m.voice" #media>
              <!-- رسالة الموظف الصوتية (E34، حقل voice مع العلم وحده): مشغّل ومدة وتفريغ بدل بطاقة المرفق -->
              <VoiceNote v-if="m.voice" :message-id="m.id" :media="m.media || null" :voice="m.voice"/>
              <MediaChip v-else-if="m.media" :message-id="m.id" :media="m.media" @view="$emit('viewMedia', m)" @updated="(media) => (m.media = media)"/>
            </template>
            <template v-if="flags.staff_translation || flags.thread_pins" #extras>
              <BubbleExtras :m="m" :conversation-id="thread.id" :can-reply="canReply" :translate="!!flags.staff_translation" :pins="!!flags.thread_pins"
                            :pinned="pinnedIds.has(m.id)" @translated="(t) => (m.translation = t)" @pinned="(p) => factCard?.setPinned(p)"/>
            </template>
          </MessageBubble>
          <p v-if="!messages.length && !outbox.length" class="text-center text-gray-400 text-sm py-10">{{ filtered ? 'لا رسائل تطابق الفلتر' : 'لا رسائل' }}</p>
          <!-- الوضع المنفصل (E30): ما بعد النافذة يُحمَّل بالنزول أو بالزر -->
          <div v-if="detached" class="text-center">
            <button type="button" :disabled="loadingNewer" @click="$emit('loadNewer')"
                    class="px-3 py-1 rounded-lg text-[11px] text-gray-500 dark:text-gray-400 hover:text-gold-700 dark:hover:text-gold-400 disabled:opacity-60">
              {{ loadingNewer ? 'جارٍ تحميل الأحدث...' : '↓ رسائل أحدث' }}
            </button>
          </div>
          <!-- ملفات الموظف في طريقها (E32): تُزال حين يظهر صفها في الخيط -->
          <PendingBubble v-for="o in outbox" :key="o.key" :item="o" @retry="(i) => $emit('outboxRetry', i)" @dismiss="(i) => $emit('outboxDismiss', i)"/>
        </div>
        <button v-if="detached" type="button" @click="$emit('backToLatest')" data-test="back-to-latest"
                class="absolute bottom-3 inset-x-0 mx-auto w-fit min-h-11 px-3.5 py-1.5 rounded-full text-xs font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md">
          ⬇ العودة إلى الأحدث<template v-if="pendingNew"> ({{ pendingNew }}{{ pendingMore ? '+' : '' }} جديدة)</template>
        </button>
        <button v-else-if="newBelow" type="button" @click="$emit('jumpToNew')"
                class="absolute bottom-3 inset-x-0 mx-auto w-fit min-h-11 px-3.5 py-1.5 rounded-full text-xs font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md">
          رسائل جديدة ({{ newBelow }}) ↓
        </button>
      </div>

      <div v-if="notes.length" class="px-4 py-2 border-t border-gray-100 dark:border-gray-800 bg-amber-50/50 dark:bg-amber-900/10 shrink-0">
        <p dir="auto" class="text-[11px] text-gray-500 dark:text-gray-400 [unicode-bidi:plaintext]">📝 {{ notes.join(' · ') }}</p>
      </div>

      <!-- «اكتب بالعربية وأترجم» (E29): «إرسال» يفتح معاينة الترجمة -->
      <TranslateReplyBar v-if="translateBar" class="shrink-0" v-model="translateMode" v-model:target="translateTarget" :targets="translateTargets"/>
      <Composer class="shrink-0" :model-value="draft" @update:model-value="(v) => $emit('update:draft', v)" @submit="onSubmit"
                @request-phone="$emit('requestPhone')" :can-reply="canReply" :window-open="!!thread.window_open" :window-left="thread.window_left"
                :sending="sending" :error="error" :cannot-reach-phoneless="cannotReachPhoneless" :can-request-phone="canRequestPhone" :requesting="requesting"
                :quick-replies="quickReplies" :conversation-id="thread.id"
                :media-policy="mediaPolicy" :recipient="recipient" @send-file="(p) => $emit('sendFile', p)"
                :voice-policy="voicePolicy" :send-voice="sendVoice"/>
      <TranslateReplyPanel :open="translateOpen" :conversation-id="thread.id" :source-text="draft" :target="translateTarget" :targets="translateTargets"
                           @close="translateOpen = false" @sent="onTranslatedSent"/>
    </template>

    <div v-else class="flex-1 grid place-items-center py-24 text-center">
      <div>
        <p class="text-5xl mb-3" aria-hidden="true">💬</p>
        <p class="text-gray-500 dark:text-gray-400 font-bold">اختر محادثة لعرضها</p>
        <p v-if="error" class="mt-2 text-xs text-red-600">❌ {{ error }}</p>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import BubbleExtras from './BubbleExtras.vue';
import Composer from './Composer.vue';
import VoiceNote from './VoiceNote.vue';
import FactCard from './FactCard.vue';
import ThreadLabels from './ThreadLabels.vue';
import TranslateReplyBar from './TranslateReplyBar.vue';
import TranslateReplyPanel from './TranslateReplyPanel.vue';
import MediaChip from './MediaChip.vue';
import MessageBubble from './MessageBubble.vue';
import ThreadFilters from './ThreadFilters.vue';
import PendingBubble from './PendingBubble.vue';
import ThreadHeader from './ThreadHeader.vue';
import { isNearBottom, preserveOnPrepend, scrollToBottom } from '@/composables/whatsapp/useStickToBottom.js';

const props = defineProps({
  thread: { type: Object, default: null },
  messages: { type: Array, default: () => [] },
  notes: { type: Array, default: () => [] },
  hasMore: Boolean,
  loadingOlder: Boolean,
  newBelow: { type: Number, default: 0 },
  draft: { type: String, default: '' },
  sending: Boolean,
  error: { type: String, default: '' },
  requesting: Boolean,
  cannotReachPhoneless: Boolean,
  canRequestPhone: Boolean,
  canReply: Boolean,
  canAssign: Boolean,
  canToggleBot: Boolean,
  canLinkClient: Boolean,
  staff: { type: Array, default: () => [] },
  topics: { type: Object, default: () => ({}) },
  attentionReasons: { type: Object, default: () => ({}) },
  // E17: المتابعة وزر الإعادة والردود الجاهزة
  followup: Boolean,
  canReprocess: Boolean,
  quickReplies: Boolean,
  // E29: الأعلام والتصنيفات الفعّالة ولغات ترجمة الرد
  flags: { type: Object, default: () => ({}) },
  labels: { type: Array, default: () => [] },
  canManageLabels: Boolean,
  translateTargets: { type: Object, default: () => ({}) },
  // E30: نافذة حول رسالة لم تلحق بالأحدث، وفلاتر الخيط، والتصدير
  detached: Boolean,
  loadingNewer: Boolean,
  pendingNew: { type: Number, default: 0 },
  pendingMore: Boolean,
  filtered: Boolean,
  filters: { type: Object, default: () => ({}) },
  canFilter: Boolean,
  canExport: Boolean,
  // E32: المرفقات وفقاعاتها المتفائلة
  mediaPolicy: { type: Object, default: null },
  recipient: { type: Object, default: () => ({}) },
  outbox: { type: Array, default: () => [] },
  // E34: المسجّل الصوتي ودالة إرساله
  voicePolicy: { type: Object, default: null },
  sendVoice: { type: Function, default: null },
});
const emit = defineEmits(['back', 'link', 'unlink', 'resolve', 'toggleBot', 'setTopic', 'assign', 'loadOlder', 'jumpToNew',
  'reachedBottom', 'viewMedia', 'update:draft', 'send', 'requestPhone', 'action', 'resend', 'labelsChanged', 'translatedSent',
  'loadNewer', 'backToLatest', 'setFilters', 'clearFilters', 'export', 'sendFile', 'outboxRetry', 'outboxDismiss']);

const scroller = ref(null);
const filtersOpen = ref(false);
const flashId = ref(null);
let flashTimer = null;
watch(() => props.thread?.id, () => { filtersOpen.value = false; });
onBeforeUnmount(() => clearTimeout(flashTimer));

// ===== E29: التثبيت داخل الخيط وترجمة الرد =====
const factCard = ref(null);
const pinnedIds = ref(new Set());
const jumpMiss = ref(false);
let jumpTimer = null;
function jumpTo(id) {
  const el = scroller.value?.querySelector(`[data-msg-id="${id}"]`);
  if (el) {
    el.scrollIntoView({ block: 'center', behavior: 'smooth' });
    return;
  }
  jumpMiss.value = true;
  clearTimeout(jumpTimer);
  jumpTimer = setTimeout(() => { jumpMiss.value = false; }, 5000);
}

const translateMode = ref(false);
const translateOpen = ref(false);
const translateBar = computed(() => !!props.flags.staff_translation && props.canReply && !!props.thread?.window_open && Object.keys(props.translateTargets).length > 0);
// اللغة الافتراضية: آخر لغة ترجمها الموظف من رسائل العميل
const lastSource = computed(() => {
  for (let i = props.messages.length - 1; i >= 0; i--) {
    const src = props.messages[i].translation?.src;
    if (src && props.translateTargets[src]) return src;
  }
  return '';
});
const translateTarget = ref('en');
watch(() => props.thread?.id, () => {
  translateMode.value = false;
  translateOpen.value = false;
  pinnedIds.value = new Set();
  translateTarget.value = lastSource.value || 'en';
});
watch(lastSource, (src) => { if (src && !translateMode.value) translateTarget.value = src; });
const onSubmit = () => (translateMode.value && translateBar.value ? (translateOpen.value = true) : emit('send'));
function onTranslatedSent(res) {
  translateOpen.value = false;
  emit('translatedSent', res);
}

const onScroll = () => {
  const el = scroller.value;
  if (!el) return;
  if (el.scrollTop < 80 && props.hasMore && !props.loadingOlder) emit('loadOlder');
  if (props.newBelow && isNearBottom(el)) emit('reachedBottom');
  // الوضع المنفصل: النزول إلى آخر النافذة يحمّل ما بعدها (لا يقفز إلى الأحدث)
  if (props.detached && !props.loadingNewer && isNearBottom(el, 80)) emit('loadNewer');
};

/** يوسّط رسالة القفز ويبرزها 3 ثوانٍ (E30) */
async function scrollToMessage(id) {
  await nextTick();
  const el = scroller.value?.querySelector(`[data-msg-id="${Number(id)}"]`);
  el?.scrollIntoView?.({ block: 'center' });
  flashId.value = Number(id);
  clearTimeout(flashTimer);
  flashTimer = setTimeout(() => { flashId.value = null; }, 3000);
}

// للمنسّق: الاستطلاع يسأل قبل الإلحاق، والفتح ينزل للأسفل، والأقدم يحفظ موضع القراءة
defineExpose({
  nearBottom: () => isNearBottom(scroller.value),
  toBottom: async () => { await nextTick(); scrollToBottom(scroller.value); },
  keepOnPrepend: (mutate) => preserveOnPrepend(scroller.value, mutate, nextTick),
  scrollToMessage,
});
</script>
