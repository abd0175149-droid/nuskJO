<template>
  <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden flex flex-col min-h-0" aria-label="المحادثة">
    <template v-if="thread">
      <ThreadHeader :thread="thread" :topics="topics" :staff="staff" :attention-reasons="attentionReasons"
                    :can-link-client="canLinkClient" :can-toggle-bot="canToggleBot" :can-assign="canAssign" show-back
                    :followup="followup" :can-reply="canReply" :can-reprocess="canReprocess" @action="(n, p) => $emit('action', n, p)"
                    @back="$emit('back')" @link="$emit('link')" @unlink="$emit('unlink')" @resolve="$emit('resolve')"
                    @toggle-bot="$emit('toggleBot')" @set-topic="(k, a) => $emit('setTopic', k, a)" @assign="(el) => $emit('assign', el)"
                    :can-block="canBlock" @block="$emit('block')"/>

      <!-- المحادثة المحظورة (E31): من حظر ومتى ولماذا، وفك الحظر بسبب لمن يملك الصلاحية -->
      <div v-if="thread.blocked" role="status" data-test="blocked-banner"
           class="px-3 sm:px-4 py-2 border-b border-red-200 dark:border-red-900 bg-red-50 dark:bg-red-900/20 text-xs text-red-800 dark:text-red-200 flex flex-wrap items-center gap-2 shrink-0">
        <span class="font-bold">⛔ محظور</span>
        <span v-if="thread.block?.since">منذ <bdi dir="ltr">{{ when(thread.block.since) }}</bdi></span>
        <span v-if="thread.block?.by">بواسطة <bdi dir="auto">{{ thread.block.by }}</bdi></span>
        <span v-if="thread.block?.reason_label">— السبب: {{ thread.block.reason_label }}<template v-if="thread.block.reason">: <bdi dir="auto" class="[unicode-bidi:plaintext]">{{ thread.block.reason }}</bdi></template></span>
        <span v-if="thread.block?.meta_state === 'not_found' || thread.block?.meta_state === 'mismatch'" class="font-bold text-amber-800 dark:text-amber-300">⚠️ غير مؤكد عند ميتا — راجع صفحة المحظورين</span>
        <button v-if="canBlock" type="button" @click="$emit('unblock')"
                class="ms-auto min-h-11 lg:min-h-0 px-2.5 py-1 rounded-lg text-xs font-bold border border-red-300 dark:border-red-700 bg-white dark:bg-gray-900 text-red-700 dark:text-red-300">فك الحظر</button>
      </div>

      <div class="relative flex-1 min-h-0 flex flex-col">
        <div ref="scroller" @scroll.passive="onScroll" class="flex-1 overflow-y-auto p-3 sm:p-4 space-y-2 bg-gray-50 dark:bg-gray-950/40" aria-live="polite">
          <div v-if="hasMore || loadingOlder" class="text-center">
            <button type="button" :disabled="loadingOlder" @click="$emit('loadOlder')"
                    class="px-3 py-1 rounded-lg text-[11px] text-gray-500 dark:text-gray-400 hover:text-gold-700 dark:hover:text-gold-400 disabled:opacity-60">
              {{ loadingOlder ? 'جارٍ تحميل الأقدم...' : '↑ رسائل أقدم' }}
            </button>
          </div>
          <MessageBubble v-for="m in messages" :key="m.id" :m="m" @resend="(msg) => $emit('resend', msg)">
            <template v-if="m.media" #media>
              <MediaChip :message-id="m.id" :media="m.media" @view="$emit('viewMedia', m)" @updated="(media) => (m.media = media)"/>
            </template>
          </MessageBubble>
          <p v-if="!messages.length" class="text-center text-gray-400 text-sm py-10">لا رسائل</p>
        </div>
        <button v-if="newBelow" type="button" @click="$emit('jumpToNew')"
                class="absolute bottom-3 inset-x-0 mx-auto w-fit min-h-11 px-3.5 py-1.5 rounded-full text-xs font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md">
          رسائل جديدة ({{ newBelow }}) ↓
        </button>
      </div>

      <div v-if="notes.length" class="px-4 py-2 border-t border-gray-100 dark:border-gray-800 bg-amber-50/50 dark:bg-amber-900/10 shrink-0">
        <p dir="auto" class="text-[11px] text-gray-500 dark:text-gray-400 [unicode-bidi:plaintext]">📝 {{ notes.join(' · ') }}</p>
      </div>

      <Composer class="shrink-0" :model-value="draft" @update:model-value="(v) => $emit('update:draft', v)" @submit="$emit('send')"
                @request-phone="$emit('requestPhone')" :can-reply="canReply" :window-open="!!thread.window_open" :window-left="thread.window_left"
                :sending="sending" :error="error" :cannot-reach-phoneless="cannotReachPhoneless" :can-request-phone="canRequestPhone" :requesting="requesting"
                :quick-replies="quickReplies" :conversation-id="thread.id" :blocked="!!thread.blocked"/>
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
import { nextTick, ref } from 'vue';
import Composer from './Composer.vue';
import MediaChip from './MediaChip.vue';
import MessageBubble from './MessageBubble.vue';
import ThreadHeader from './ThreadHeader.vue';
import { isNearBottom, preserveOnPrepend, scrollToBottom } from '@/composables/whatsapp/useStickToBottom.js';
import { when } from '@/utils/waSettings.js';

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
  // E31: الحظر الرسمي
  canBlock: Boolean,
});
const emit = defineEmits(['back', 'link', 'unlink', 'resolve', 'toggleBot', 'setTopic', 'assign', 'loadOlder', 'jumpToNew',
  'reachedBottom', 'viewMedia', 'update:draft', 'send', 'requestPhone', 'action', 'resend', 'block', 'unblock']);

const scroller = ref(null);

const onScroll = () => {
  const el = scroller.value;
  if (!el) return;
  if (el.scrollTop < 80 && props.hasMore && !props.loadingOlder) emit('loadOlder');
  if (props.newBelow && isNearBottom(el)) emit('reachedBottom');
};

// للمنسّق: الاستطلاع يسأل قبل الإلحاق، والفتح ينزل للأسفل، والأقدم يحفظ موضع القراءة
defineExpose({
  nearBottom: () => isNearBottom(scroller.value),
  toBottom: async () => { await nextTick(); scrollToBottom(scroller.value); },
  keepOnPrepend: (mutate) => preserveOnPrepend(scroller.value, mutate, nextTick),
});
</script>
