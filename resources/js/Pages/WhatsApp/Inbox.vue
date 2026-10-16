<template>
  <AppLayout>
    <template #header>محادثات الواتساب</template>

    <!-- ارتفاع الشاشة ناقص رأس التطبيق وحشوته: القائمة والمحادثة تمرّران داخلهما (E16) -->
    <div class="flex flex-col gap-3 sm:gap-4 h-[calc(100dvh-var(--app-header)-2*var(--app-pad))] min-h-[28rem]">
      <InboxStatusBar :class="s.view === 'thread' ? 'hidden lg:block' : ''" class="shrink-0"
                      :status="status" :llm="s.llm" :stale-since="s.staleSince" :notice="s.notice" @dismiss="s.notice = ''"/>
      <InboxCards v-if="followup && s.summary" :class="s.view === 'thread' ? 'hidden lg:flex' : ''" class="shrink-0"
                  :summary="s.summary" :active="s.filter" @filter="(f) => { s.filter = f; inbox.refilter(); }"/>

      <div class="flex-1 min-h-0 grid grid-cols-1 lg:grid-cols-3 gap-4">
        <ConversationList :class="s.view === 'thread' ? 'hidden lg:flex' : 'flex'" class="lg:col-span-1"
                          :visible="s.view === 'list'" :items="s.list" :active-id="s.activeId" :has-more="s.listHasMore" :loading-more="s.loadingMore"
                          :filter="s.filter" :search="s.search" :topic="s.topic" :topics="topics" :sees-all="seesAll" :attention-reasons="attentionReasons" :followup="followup"
                          @update:filter="(v) => { s.filter = v; inbox.refilter(); }" @update:search="(v) => { s.search = v; inbox.refilter(); }"
                          @update:topic="(v) => { s.topic = v; inbox.refilter(); }" @open="inbox.openFromList" @load-more="inbox.loadMore"/>

        <ThreadView ref="threadView" :class="s.view === 'list' ? 'hidden lg:flex' : 'flex'" class="lg:col-span-2"
                    :thread="s.thread" :messages="s.messages" :notes="s.notes" :has-more="s.hasMore" :loading-older="s.loadingOlder" :new-below="s.newBelow"
                    v-model:draft="inbox.draft.value" :sending="s.sending" :error="s.sendError" :requesting="s.requesting"
                    :cannot-reach-phoneless="inbox.cannotReachPhoneless.value" :can-request-phone="inbox.canRequestPhone.value"
                    :can-reply="canReply" :can-assign="canAssign" :can-toggle-bot="canToggleBot" :can-link-client="canLinkClient"
                    :staff="staff" :topics="topics" :attention-reasons="attentionReasons"
                    :followup="followup" :can-reprocess="canReprocess" :quick-replies="quickReplies" @action="(n, p) => inbox.followAction(n, p, confirm)"
                    @back="inbox.back" @link="linkOpen = true" @unlink="unlink" @resolve="inbox.resolve" @toggle-bot="inbox.toggleBot"
                    @set-topic="inbox.setTopic" @assign="inbox.assignTo" @load-older="inbox.loadOlder" @jump-to-new="inbox.jumpToNew"
                    @reached-bottom="inbox.reachedBottom" @view-media="openViewer" @send="inbox.send(ask)" @request-phone="inbox.requestPhone"
                    @resend="(m) => inbox.copyToDraft(m, askResend)"
                    :media-policy="mediaPolicy" :recipient="inbox.recipient.value" :outbox="inbox.outboxItems.value"
                    @send-file="(p) => inbox.sendFile(p, ask)" @outbox-retry="(i) => inbox.retryFile(i, ask)" @outbox-dismiss="inbox.dismissFile"/>
      </div>
    </div>

    <LinkClientDialog :open="linkOpen" :conversation="s.thread || {}" @close="linkOpen = false" @linked="inbox.onLinked"/>
    <MediaViewer :open="viewer.open" :message-id="viewer.id" :media="viewer.media" :watermark="viewer.watermark" @close="viewer.open = false"/>
  </AppLayout>
</template>

<script setup>
// منسّق الصندوق (E16): الحالة والنداءات في useInbox، والعرض في مكوّنات Components/WhatsApp.
import { onMounted, onUnmounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/SmartLayout.vue';
import ConversationList from '@/Components/WhatsApp/ConversationList.vue';
import InboxStatusBar from '@/Components/WhatsApp/InboxStatusBar.vue';
import InboxCards from '@/Components/WhatsApp/InboxCards.vue';
import LinkClientDialog from '@/Components/WhatsApp/LinkClientDialog.vue';
import MediaViewer from '@/Components/WhatsApp/MediaViewer.vue';
import ThreadView from '@/Components/WhatsApp/ThreadView.vue';
import { useInbox } from '@/composables/whatsapp/useInbox.js';
import { createPoller } from '@/composables/whatsapp/usePolling.js';
import { onPopState } from '@/utils/historyGuard';
import { useConfirm } from '@/composables/useConfirm';

const props = defineProps({
  title: String,
  conversations: { type: Array, default: () => [] },
  listMeta: { type: Object, default: () => ({ has_more: false, next_cursor: null }) },
  filters: Object,
  status: { type: Object, default: () => ({}) },
  canReply: Boolean,
  canAssign: Boolean,
  canToggleBot: Boolean,
  seesAll: Boolean,
  canSeeAdminChats: Boolean,
  canLinkClient: Boolean,
  canMedia: Boolean,
  llm: { type: Object, default: () => ({ state: 'closed' }) },
  identityMode: { type: String, default: 'store' },
  topics: { type: Object, default: () => ({}) },
  staff: { type: Array, default: () => [] },
  attentionReasons: { type: Object, default: () => ({}) },
  // E17: المتابعة والبطاقات وزر الإعادة والردود الجاهزة
  followup: Boolean,
  summary: { type: Object, default: null },
  canReprocess: Boolean,
  quickReplies: Boolean,
  // E32: إرسال الملفات (الأنواع والحدود والعلم)
  mediaPolicy: { type: Object, default: null },
});

const threadView = ref(null);
const inbox = useInbox(props, () => threadView.value);
const { s } = inbox;
const linkOpen = ref(false);

// تحذير السياسة (E15.9) وفك الربط بنافذة التأكيد الموحّدة (E16.9)
const { confirm } = useConfirm();
const ask = (message) => confirm({ title: 'تحذير قبل الإرسال', message, confirmLabel: 'إرسال رغم التحذير', danger: true });
// رسالة «غير مؤكدة» قد تكون وصلت: النسخ إلى المنشئ بعد تأكيد صريح (E19)
const askResend = (message) => confirm({ title: 'الإرسال مرة ثانية؟', message, confirmLabel: 'انسخ إلى خانة الكتابة', danger: true });
const unlink = async () => {
  if (await confirm({ title: 'فك ربط العميل', message: 'فك ربط هذه المحادثة بالعميل؟ لن يعيد النظام ربطها آلياً.', confirmLabel: 'فك الربط', danger: true })) inbox.unlinkClient();
};

// عارض المرفقات (E14): علامة مائية باسم الموظف ووقت الفتح
const viewer = ref({ open: false, id: null, media: null, watermark: '' });
const openViewer = (m) => {
  const name = usePage().props.auth?.user?.name || '';
  viewer.value = { open: true, id: m.id, media: m.media, watermark: `سرّي — ${name} — ${new Date().toLocaleString('ar-JO', { dateStyle: 'short', timeStyle: 'short' })}` };
};

// كل 15 ثانية، ويتوقف والصفحة مخفية ويستأنف بطلب فوري عند عودتها
const poller = createPoller(inbox.tick, { interval: 15000 });

let releaseHistory = () => {};
onMounted(() => {
  inbox.openFromUrl();
  releaseHistory = onPopState(inbox.onPopState);
  poller.start();
});
onUnmounted(() => {
  poller.stop();
  inbox.dispose();
  releaseHistory();
});
</script>
