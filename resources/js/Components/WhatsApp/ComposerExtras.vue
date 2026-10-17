<template>
  <!-- فوق المنشئ (E33): شريط «ترد على…» (✕ وEsc)، وخانة معاينة الرابط حين في النص رابط، وزرّا الموقع والبطاقة.
       مكوّن مستقل فلا يتغيّر Composer؛ الإرسال نفسه من المنشئ والخادم يعيد كل فحص -->
  <div v-if="visible" class="px-3 pt-2 space-y-1.5 border-t border-gray-200 dark:border-gray-700" data-test="composer-extras">
    <div v-if="replyTo" class="flex items-start gap-2 rounded-lg px-2.5 py-1.5 bg-gray-100 dark:bg-gray-800 border-s-2 border-gold-500" data-test="reply-bar">
      <div class="min-w-0 flex-1 text-xs">
        <p class="font-bold text-gray-700 dark:text-gray-200">↩️ ترد على: <bdi dir="auto" class="font-normal break-words [unicode-bidi:plaintext]">{{ replyTo.snippet }}</bdi></p>
        <p class="text-[10px] text-gray-500 dark:text-gray-400">قد لا تظهر فقاعة الاقتباس عند العميل لرسالة أقدم من نحو 30 يوماً.</p>
      </div>
      <button type="button" @click="$emit('cancelReply')" aria-label="إلغاء الرد المقتبس" title="إلغاء (Esc)" data-test="cancel-reply"
              class="shrink-0 min-w-8 min-h-8 rounded-lg text-gray-500 hover:text-gray-800 dark:hover:text-gray-100 hover:bg-gray-200 dark:hover:bg-gray-700">✕</button>
    </div>

    <div v-if="showPreview || canShare" class="flex flex-wrap items-center gap-2">
      <label v-if="showPreview" class="inline-flex items-center gap-1.5 min-h-8 text-xs text-gray-600 dark:text-gray-300" data-test="preview-toggle">
        <input type="checkbox" :checked="preview" @change="(e) => $emit('update:preview', e.target.checked)" class="w-4 h-4 rounded text-gold-500"/>
        معاينة الرابط
      </label>
      <span v-if="canShare" class="inline-flex items-center gap-1.5 ms-auto">
        <button v-if="shareItems.places?.length" type="button" :disabled="!windowOpen" @click="dialog = 'location'" data-test="share-location"
                class="min-h-9 px-2.5 rounded-lg text-xs font-bold border border-gray-200 dark:border-gray-700 hover:border-gold-400 disabled:opacity-50">📍 موقع</button>
        <button v-if="shareItems.cards?.length" type="button" :disabled="!windowOpen" @click="dialog = 'contact'" data-test="share-contact"
                class="min-h-9 px-2.5 rounded-lg text-xs font-bold border border-gray-200 dark:border-gray-700 hover:border-gold-400 disabled:opacity-50">👤 بطاقة</button>
      </span>
    </div>
    <p v-if="notice" role="status" class="text-[11px] text-amber-700 dark:text-amber-400" data-test="preview-notice">{{ notice }}</p>

    <ShareLocationDialog v-if="canShare" :open="dialog === 'location'" :places="shareItems.places || []" :conversation-id="conversationId"
                         :recipient="recipient" :reply-to="replyTo" @close="dialog = ''" @sent="onShared"/>
    <ShareContactDialog v-if="canShare" :open="dialog === 'contact'" :cards="shareItems.cards || []" :conversation-id="conversationId"
                        :recipient="recipient" :reply-to="replyTo" @close="dialog = ''" @sent="onShared"/>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ShareContactDialog from './ShareContactDialog.vue';
import ShareLocationDialog from './ShareLocationDialog.vue';
import { hasLink } from '@/composables/whatsapp/useRichSend.js';

const props = defineProps({
  conversationId: { type: Number, default: null },
  // من الخادم: {quote, react, preview, share}
  features: { type: Object, default: () => ({}) },
  replyTo: { type: Object, default: null },
  draft: { type: String, default: '' },
  preview: Boolean,
  notice: { type: String, default: '' },
  shareItems: { type: Object, default: null },
  recipient: { type: Object, default: () => ({}) },
  windowOpen: Boolean,
  canReply: Boolean,
});
const emit = defineEmits(['cancelReply', 'update:preview', 'shared']);

const dialog = ref('');
const showPreview = computed(() => props.canReply && !!props.features?.preview && hasLink(props.draft));
const canShare = computed(() => props.canReply && !!props.features?.share && !!(props.shareItems?.places?.length || props.shareItems?.cards?.length));
const visible = computed(() => !!props.replyTo || showPreview.value || canShare.value || !!props.notice);

function onShared(data) {
  dialog.value = '';
  emit('shared', data);
}

// Esc يلغي الاقتباس، إلا إن كانت نافذة مفتوحة (Esc لها)
function onKey(e) {
  if (e.key !== 'Escape' || !props.replyTo) return;
  if (typeof document !== 'undefined' && document.querySelector('dialog[open]')) return;
  emit('cancelReply');
}
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>
