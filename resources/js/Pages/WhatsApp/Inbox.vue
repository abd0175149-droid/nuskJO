<template>
  <AppLayout>
    <template #header>محادثات الواتساب</template>

    <div class="space-y-4">
      <!-- شريط الحالة -->
      <div class="rounded-xl border p-3.5 text-sm flex flex-wrap items-center gap-x-4 gap-y-2"
           :class="status.suspended
             ? 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800'
             : (status.bot_ready ? 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800'
                                 : 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800')">
        <span class="font-bold">
          <template v-if="status.suspended">⛔ الإرسال موقوف — {{ SUSPEND_SOURCES[status.suspended_source] || 'قفل طارئ' }}</template>
          <template v-else-if="status.bot_ready">🟢 البوت يعمل</template>
          <template v-else-if="status.channel_ready">🟡 القناة مهيّأة والبوت مطفأ</template>
          <template v-else>🟡 القناة غير مهيّأة</template>
        </span>
        <span class="text-gray-600 dark:text-gray-400 text-xs">
          <template v-if="!status.channel_ready">أضف بيانات Meta ومفتاح Claude من صفحة الإعدادات لتشغيل النظام.</template>
          <template v-else-if="!status.bot_ready">المحادثات تعمل والموظفون يردّون؛ فعّل البوت من الإعدادات عند الجهوزية.</template>
          <template v-else>الموظف يمكنه الردّ في أي وقت — وردّه يوقف البوت مؤقتاً تلقائياً.</template>
        </span>
        <a href="/whatsapp/settings" class="text-gold-700 dark:text-gold-400 font-bold text-xs hover:underline mr-auto">⚙️ الإعدادات</a>
      </div>

      <!-- ميتا أوقفت القناة (فحص صحة القناة أو الرمز): عام بلا تفاصيل، والتفاصيل في الإعدادات -->
      <div v-if="status.meta_blocked" role="alert"
           class="rounded-xl border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-3.5 text-sm font-bold text-red-700 dark:text-red-300">
        ⛔ القناة متوقفة من جهة ميتا — أبلغ المدير.
      </div>

      <!-- محادثة لم تعد ضمن نطاقك -->
      <div v-if="notice" role="alert"
           class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-3.5 text-sm text-amber-800 dark:text-amber-300 flex items-start gap-3">
        <span class="flex-1">⚠️ {{ notice }}</span>
        <button type="button" @click="notice = ''" aria-label="إغلاق التنبيه" class="font-bold leading-none hover:text-amber-950 dark:hover:text-amber-100">✕</button>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        <!-- قائمة المحادثات -->
        <div class="lg:col-span-1 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden flex flex-col" style="max-height:72vh">
          <div class="p-3 border-b border-gray-200 dark:border-gray-700 space-y-2">
            <input v-model="search" type="text" placeholder="بحث بالاسم أو الرقم..."
                   class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm dark:text-white"
                   @input="debounceLoad"/>
            <div class="flex flex-wrap gap-1.5">
              <button v-for="f in filterTabs" :key="f.key" type="button" @click="filter = f.key; load()"
                      class="px-2.5 py-1 rounded-lg text-[11px] font-bold border"
                      :class="filter === f.key ? 'bg-gold-50 dark:bg-gold-900/20 border-gold-400 text-gold-700 dark:text-gold-400'
                                               : 'border-gray-200 dark:border-gray-700 text-gray-500 hover:border-gold-400'">
                {{ f.label }}
              </button>
            </div>
            <select v-model="topic" @change="load()" aria-label="تصفية بحسب الموضوع"
                    class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-xs dark:text-white">
              <option value="">كل المواضيع</option>
              <option v-for="(label, key) in props.topics" :key="key" :value="key">{{ label }}</option>
            </select>
            <p v-if="!props.seesAll" class="text-[11px] text-gray-500 dark:text-gray-400">
              تظهر لك المحادثات التي تخصّ مجالاتك فقط
            </p>
          </div>

          <div class="overflow-y-auto flex-1">
            <button v-for="c in list" :key="c.id" type="button" @click="open(c.id)"
                    class="w-full text-right px-4 py-3 border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors"
                    :class="activeId === c.id ? 'bg-gold-50/60 dark:bg-gold-900/10' : ''">
              <div class="flex items-center justify-between gap-2">
                <span class="font-bold text-sm truncate">{{ c.name }}</span>
                <span v-if="c.unread" class="shrink-0 min-w-5 h-5 px-1.5 rounded-full bg-red-500 text-white text-[10px] font-bold grid place-items-center">{{ c.unread }}</span>
              </div>
              <p class="text-xs text-gray-500 dark:text-gray-400 truncate mt-0.5">{{ c.preview || '—' }}</p>
              <div v-if="(c.topics && c.topics.length) || c.assignee" class="flex items-center gap-1 mt-1 flex-wrap">
                <span v-for="tp in (c.topics || [])" :key="tp.key"
                      :title="tp.source === 'manual' ? 'وسم يدوي' : 'وسم آلي من البوت'"
                      class="px-1.5 py-0.5 rounded text-[10px] leading-4"
                      :class="tp.source === 'manual'
                        ? 'bg-gold-50 dark:bg-gold-900/20 text-gold-700 dark:text-gold-300 ring-1 ring-gold-300 dark:ring-gold-700'
                        : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300'">
                  {{ tp.label }}
                </span>
                <span v-if="c.assignee" class="text-[10px] text-gray-400 dark:text-gray-500">‹{{ c.assignee }}›</span>
              </div>
              <div class="flex items-center gap-1.5 mt-1.5 flex-wrap">
                <span v-if="c.needs_attention" class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-red-100 text-red-700">⚠️ تدخّل</span>
                <span v-if="c.needs_attention && c.attention_reason" class="px-1.5 py-0.5 rounded text-[10px] bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-300">{{ reasonLabel(c.attention_reason) }}</span>
                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold" :class="c.bot_enabled ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600'">
                  {{ c.bot_enabled ? (c.paused ? '🤖 موقوف مؤقتاً' : '🤖 بوت') : '👤 بشري' }}
                </span>
                <span v-if="c.is_admin" class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-purple-100 text-purple-700" title="رقم إدارة — يخدمه المساعد الإداري">👔 إدارة</span>
                <span v-if="c.client" class="px-1.5 py-0.5 rounded text-[10px] bg-blue-100 text-blue-700">عميل مسجّل</span>
                <span v-if="!c.window_open" class="px-1.5 py-0.5 rounded text-[10px] bg-amber-100 text-amber-700">نافذة مغلقة</span>
                <span v-else-if="c.needs_attention && c.window_left !== null && c.window_left <= 360"
                      class="px-1.5 py-0.5 rounded text-[10px] font-bold"
                      :class="c.window_left <= 120 ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700'"
                      :title="'تُغلق نافذة الرد خلال ' + windowLabel(c.window_left)">⏳ {{ windowLabel(c.window_left) }}</span>
                <span class="text-[10px] text-gray-400 mr-auto">{{ c.last_at }}</span>
              </div>
            </button>
            <p v-if="!list.length" class="py-14 text-center text-gray-400 text-sm">لا محادثات</p>
          </div>
        </div>

        <!-- المحادثة -->
        <div class="lg:col-span-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden flex flex-col" style="max-height:72vh">
          <template v-if="thread">
            <!-- رأس المحادثة -->
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 space-y-2">
              <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                  <p class="font-bold text-sm">{{ thread.name }}</p>
                  <p v-if="thread.phone" class="text-xs text-gray-400 font-mono" dir="ltr">{{ thread.phone }}<span v-if="thread.client"> · {{ thread.client }}</span></p>
                  <!-- عميل باسم مستخدم واتساب بلا رقم ظاهر (E11a) -->
                  <p v-else class="text-xs text-amber-700 dark:text-amber-400">📵 رقم غير ظاهر<bdi v-if="thread.username" dir="ltr" class="font-mono"> · @{{ thread.username }}</bdi></p>
                </div>
                <div class="flex items-center gap-2">
                  <span v-if="thread.needs_attention && thread.attention_reason" class="px-2 py-0.5 rounded-lg text-[11px] bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-300">{{ reasonLabel(thread.attention_reason) }}</span>
                  <button v-if="thread.needs_attention" @click="resolve" class="px-2.5 py-1 rounded-lg text-xs font-bold text-green-700 bg-green-50 dark:bg-green-900/20">✅ تمّت المعالجة</button>
                  <button v-if="props.canToggleBot" @click="toggleBot" class="px-2.5 py-1 rounded-lg text-xs font-bold border"
                          :class="thread.bot_enabled ? 'border-gray-300 text-gray-600' : 'border-green-400 text-green-700 bg-green-50 dark:bg-green-900/20'">
                    {{ thread.bot_enabled ? '⏸️ إيقاف البوت' : '▶️ تشغيل البوت' }}
                  </button>
                </div>
              </div>

              <!-- المواضيع -->
              <div class="flex flex-wrap items-center gap-1.5">
                <span class="text-[11px] text-gray-400 dark:text-gray-500">المواضيع:</span>
                <span v-for="tp in (thread.topics || [])" :key="tp.key"
                      :title="tp.source === 'manual' ? 'وسم يدوي' : 'وسم آلي من البوت'"
                      class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] leading-4"
                      :class="tp.source === 'manual'
                        ? 'bg-gold-50 dark:bg-gold-900/20 text-gold-700 dark:text-gold-300 ring-1 ring-gold-300 dark:ring-gold-700'
                        : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300'">
                  {{ tp.label }}
                  <button type="button" @click="setTopic(tp.key, false)"
                          :aria-label="`إزالة وسم ${tp.label}`" :title="`إزالة وسم ${tp.label}`"
                          class="leading-none hover:text-red-600 dark:hover:text-red-400">✕</button>
                </span>
                <span v-if="!(thread.topics && thread.topics.length)" class="text-[10px] text-gray-400 dark:text-gray-500">بلا وسوم</span>

                <template v-if="addableTopics.length">
                  <select v-model="newTopic" aria-label="اختيار موضوع للإضافة"
                          class="px-2 py-1 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-[11px] dark:text-white">
                    <option value="">— اختر موضوعاً</option>
                    <option v-for="tp in addableTopics" :key="tp.key" :value="tp.key">{{ tp.label }}</option>
                  </select>
                  <button type="button" :disabled="!newTopic" @click="addTopic"
                          class="px-2 py-1 rounded-lg text-[11px] font-bold border border-gold-400 text-gold-700 dark:text-gold-400 disabled:opacity-40">
                    + وسم
                  </button>
                </template>
              </div>

              <!-- التوكيل -->
              <div class="flex flex-wrap items-center gap-1.5">
                <span class="text-[11px] text-gray-400 dark:text-gray-500">الموكل إليه:</span>
                <select v-if="props.canAssign" :value="thread.assigned_to ?? ''" @change="assignTo($event.target)"
                        aria-label="توكيل المحادثة لموظف"
                        class="px-2 py-1 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-[11px] dark:text-white">
                  <option value="">— بلا توكيل</option>
                  <option v-for="u in props.staff" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
                <span v-else class="text-[11px] text-gray-500 dark:text-gray-400">{{ thread.assignee || '— بلا توكيل' }}</span>
              </div>
            </div>

            <!-- الفقاعات -->
            <div class="relative flex-1 min-h-0 flex flex-col">
            <div ref="scroller" @scroll.passive="onScroll" class="flex-1 overflow-y-auto p-4 space-y-2 bg-gray-50 dark:bg-gray-950/40">
              <div v-if="hasMore || loadingOlder" class="text-center">
                <button type="button" :disabled="loadingOlder" @click="loadOlder"
                        class="px-3 py-1 rounded-lg text-[11px] text-gray-500 dark:text-gray-400 hover:text-gold-700 dark:hover:text-gold-400 disabled:opacity-60">
                  {{ loadingOlder ? 'جارٍ تحميل الأقدم...' : '↑ رسائل أقدم' }}
                </button>
              </div>
              <div v-for="m in messages" :key="m.id" class="flex" :class="m.direction === 'in' ? 'justify-start' : 'justify-end'">
                <div class="max-w-[78%] rounded-2xl px-3.5 py-2 text-sm shadow-sm" :class="bubble(m)">
                  <p v-if="m.sender" class="text-[10px] font-bold opacity-80 mb-0.5 break-words">👤 {{ m.sender }}</p>
                  <p class="whitespace-pre-wrap break-words">{{ m.body }}</p>
                  <div class="flex items-center gap-1.5 mt-1 text-[10px] opacity-70">
                    <span dir="ltr">{{ m.at }}</span>
                    <span v-if="m.source !== 'customer' && !m.sender">· {{ srcLabel(m.source) }}</span>
                    <span v-if="m.status === 'failed'" class="text-red-200" :title="m.error || ''">· فشل</span>
                  </div>
                  <p v-if="m.status === 'failed' && m.error" class="mt-0.5 text-[10px] leading-4 opacity-80 break-words">{{ m.error }}</p>
                </div>
              </div>
              <p v-if="!messages.length" class="text-center text-gray-400 text-sm py-10">لا رسائل</p>
            </div>
            <button v-if="newBelow" type="button" @click="jumpToNew"
                    class="absolute bottom-3 left-1/2 -translate-x-1/2 px-3.5 py-1.5 rounded-full text-xs font-bold text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md">
              رسائل جديدة ({{ newBelow }}) ↓
            </button>
            </div>

            <!-- ملاحظات -->
            <div v-if="notes.length" class="px-4 py-2 border-t border-gray-100 dark:border-gray-800 bg-amber-50/50 dark:bg-amber-900/10">
              <p class="text-[11px] text-gray-500 dark:text-gray-400">📝 {{ notes.join(' · ') }}</p>
            </div>

            <!-- الإرسال -->
            <div class="p-3 border-t border-gray-200 dark:border-gray-700">
              <div v-if="!thread.window_open" class="mb-2 text-xs text-amber-700 dark:text-amber-400">
                ⚠️ انتهت نافذة الـ24 ساعة — لا يمكن الإرسال حتى يراسلك العميل من جديد.
              </div>
              <div v-else-if="thread.window_left !== null && thread.window_left <= 360" class="mb-2 text-xs font-bold"
                   :class="thread.window_left <= 120 ? 'text-red-600 dark:text-red-400' : 'text-amber-700 dark:text-amber-400'">
                ⏳ تبقّى {{ windowLabel(thread.window_left) }} للرد على هذا العميل، وبعدها لا يمكن مراسلته حتى يكتب لنا من جديد.
              </div>
              <form v-if="canReply" @submit.prevent="send" class="flex items-end gap-2">
                <textarea v-model="draft" rows="2" :disabled="!thread.window_open || sending"
                          placeholder="اكتب ردّك..."
                          class="flex-1 px-3.5 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm resize-none dark:text-white disabled:opacity-50"
                          @keydown.enter.exact.prevent="send"></textarea>
                <button type="submit" :disabled="!draft.trim() || !thread.window_open || sending"
                        class="px-5 py-2.5 rounded-xl font-bold text-sm text-black bg-gradient-to-r from-gold-500 to-gold-400 shadow-md disabled:opacity-50">
                  {{ sending ? '...' : '📤' }}
                </button>
              </form>
              <p v-else class="text-xs text-gray-400">لا تملك صلاحية الردّ على المحادثات.</p>
              <p v-if="sendError" class="mt-1.5 text-xs text-red-600">❌ {{ sendError }}</p>
            </div>
          </template>

          <div v-else class="flex-1 grid place-items-center py-24 text-center">
            <div>
              <p class="text-5xl mb-3">💬</p>
              <p class="text-gray-500 dark:text-gray-400 font-bold">اختر محادثة لعرضها</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';
import axios from 'axios';
import AppLayout from '@/Components/Layout/SmartLayout.vue';
import { useDrafts } from '@/composables/useDrafts';
import { SUSPEND_SOURCES } from '@/utils/waSettings.js';


const props = defineProps({
  title: String,
  conversations: { type: Array, default: () => [] },
  filters: Object,
  status: { type: Object, default: () => ({}) },
  canReply: Boolean,
  canAssign: Boolean,
  canToggleBot: Boolean,
  seesAll: Boolean,
  canSeeAdminChats: Boolean,
  topics: { type: Object, default: () => ({}) },
  staff: { type: Array, default: () => [] },
  attentionReasons: { type: Object, default: () => ({}) },
});

// سبب «تدخّل» بنص الرقاقة (ReplyState::REASONS)
const reasonLabel = (key) => props.attentionReasons[key] || key;

const OUT_OF_SCOPE = 'هذه المحادثة غير متاحة لك: ربما نُقلت لموظف آخر أو أُزيل وسم اختصاصك. اطلب من المدير توكيلها لك إن كنت تحتاجها';

const list = ref([...props.conversations]);

// الوقت الباقي في نافذة الـ24 ساعة بصيغة مقروءة: «5س 20د» أو «40د»
const windowLabel = (mins) => {
  const h = Math.floor(mins / 60), m = mins % 60;
  return h ? `${h}س${m ? ' ' + m + 'د' : ''}` : `${m}د`;
};
const filter = ref(props.filters?.filter || 'all');
const search = ref(props.filters?.search || '');
const topic = ref(props.filters?.topic || '');
const newTopic = ref('');
const activeId = ref(null);
const thread = ref(null);
const messages = ref([]);
const notes = ref([]);
const hasMore = ref(false);       // في الخادم رسائل أقدم من أول رسالة معروضة
const loadingOlder = ref(false);
const newBelow = ref(0);          // رسائل وصلت والموظف يقرأ أعلى الخيط
const notice = ref('');
let syncedAt = null;              // من آخر ردّ للخادم؛ يُعاد معه لجلب تغيّر حالات الرسائل المرسلة
// مسودة لكل محادثة: النص يتبع المحادثة المفتوحة ولا ينتقل إلى غيرها
const drafts = useDrafts();
const draft = computed({
  get: () => drafts.get(activeId.value),
  set: (text) => drafts.set(activeId.value, text),
});
const sending = ref(false);
const sendError = ref('');
const scroller = ref(null);
let timer = null, t = null;
// يزيد مع كل فتح أو إغلاق للخيط؛ أي ردّ وصل بعد تغيّره يخصّ محادثة سابقة فيُهمل
let seq = 0;
let inflight = null;

const filterTabs = [
  { key: 'all', label: 'الكل' },
  { key: 'unread', label: 'غير مقروء' },
  { key: 'attention', label: '⚠️ تدخّل' },
  { key: 'bot', label: '🤖 بوت' },
  { key: 'human', label: '👤 بشري' },
  { key: 'mine', label: '🙋 الموكلة لي' },
];

/** المواضيع غير المرتبطة بالمحادثة المفتوحة — للإضافة اليدوية */
const addableTopics = computed(() => {
  const attached = (thread.value?.topics || []).map((t) => t.key);
  return Object.entries(props.topics)
    .filter(([key]) => !attached.includes(key))
    .map(([key, label]) => ({ key, label }));
});

const api = { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
const threadUrl = (id, action) => `/api/whatsapp/conversations/${id}/${action}`;
const gone = (e) => [403, 404].includes(e?.response?.status);
const errorText = (e, fallback) => {
  const d = e?.response?.data || {};
  const text = d.error || Object.values(d.errors || {})[0]?.[0] || d.message;
  // رسائل Laravel الافتراضية إنجليزية؛ رسائلنا عربية
  if (e?.response?.status === 403 && !/[\u0600-\u06FF]/.test(text || '')) return 'ليست لديك صلاحية لهذا الإجراء';
  return text || fallback;
};

const load = async () => {
  try {
    const { data } = await axios.get('/api/whatsapp/conversations', {
      ...api,
      params: {
        filter: filter.value,
        search: search.value || undefined,
        topic: topic.value || undefined,
      },
    });
    list.value = data.conversations || [];
  } catch (e) { /* تجاهل — يُعاد في الدورة التالية */ }
};

const debounceLoad = () => { clearTimeout(t); t = setTimeout(load, 400); };

// ==================== الخيط ====================

const lastId = () => messages.value[messages.value.length - 1]?.id ?? 0;
const nearBottom = () => {
  const el = scroller.value;
  return !el || el.scrollHeight - el.scrollTop - el.clientHeight < 150;
};
const scrollToBottom = async () => {
  await nextTick();
  if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight;
};
// الموظف يرى الشاشة فعلاً، لا تبويباً منسياً في الخلفية
const viewing = () => document.visibilityState === 'visible' && document.hasFocus();

const resetThread = () => {
  seq++;
  activeId.value = null;
  thread.value = null;
  messages.value = [];
  notes.value = [];
  hasMore.value = false;
  loadingOlder.value = false;
  newBelow.value = 0;
  syncedAt = null;
};

/** المحادثة خرجت من نطاق الموظف (نُقلت أو أُزيل وسمه): تُغلق ويُشرح السبب */
const closeOutOfScope = () => {
  resetThread();
  notice.value = OUT_OF_SCOPE;
  const url = new URL(window.location.href);
  if (url.searchParams.has('conv')) {
    url.searchParams.delete('conv');
    window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
  }
  load();
};

/** التصفير صريح: عند الفتح، أو زر «رسائل جديدة»، أو النزول لآخر الخيط */
const markRead = async (id) => {
  if (!props.canReply || !id || !(thread.value?.unread > 0)) return;
  try {
    await axios.post(threadUrl(id, 'read'), {}, api);
    if (activeId.value === id && thread.value) thread.value.unread = 0;
    const row = list.value.find((c) => c.id === id);
    if (row) row.unread = 0;
  } catch (e) {
    if (e?.response?.status === 404 && activeId.value === id) closeOutOfScope();
  }
};

/** التحميل الأول: أحدث الرسائل ثم النزول إلى الأسفل */
const open = async (id, { read = true } = {}) => {
  const mine = ++seq;
  if (activeId.value !== id) newTopic.value = '';
  activeId.value = id;
  sendError.value = '';
  notice.value = '';
  try {
    const { data } = await axios.get(threadUrl(id, 'messages'), api);
    if (mine !== seq) return;
    thread.value = data.conversation;
    messages.value = data.messages || [];
    notes.value = data.notes || [];
    hasMore.value = !!data.has_more;
    syncedAt = data.synced_at;
    newBelow.value = 0;
    await scrollToBottom();
    if (read) markRead(id);
    load();
  } catch (e) {
    if (mine !== seq) return;
    if (gone(e)) return closeOutOfScope();
    if (thread.value?.id === id) {
      sendError.value = 'تعذّر تحميل المحادثة';
    } else {
      resetThread();
      notice.value = 'تعذّر تحميل المحادثة، حاول مرة أخرى.';
    }
  }
};

/** الجديد فقط بعد آخر رسالة، مع تغيّر حالات المرسَل منذ آخر مزامنة؛ لا يصفّر غير المقروء */
const pollOnce = async () => {
  const id = activeId.value;
  if (!id || !thread.value) return;
  const mine = seq;
  try {
    const { data } = await axios.get(threadUrl(id, 'messages'), {
      ...api,
      params: { after: lastId(), since: syncedAt || undefined },
    });
    if (mine !== seq) return;
    if (data.gap) return open(id, { read: false });   // فاتنا أكثر مما يعود في طلب واحد

    const atBottom = nearBottom();
    const known = new Set(messages.value.map((m) => m.id));
    const fresh = (data.messages || []).filter((m) => !known.has(m.id));
    for (const u of data.updates || []) {
      const m = messages.value.find((x) => x.id === u.id);
      if (m) { m.status = u.status; m.error = u.error; }
    }
    if (fresh.length) messages.value.push(...fresh);
    thread.value = { ...thread.value, ...data.conversation };
    syncedAt = data.synced_at;

    if (fresh.length && !atBottom) {
      newBelow.value += fresh.length;
    } else {
      if (fresh.length) await scrollToBottom();
      if (viewing()) markRead(id);
    }
  } catch (e) {
    if (mine === seq && gone(e)) closeOutOfScope();
  }
};

// طلب واحد في كل مرة، حتى لا يُضاف ردّ قديم فوق ردّ أحدث
const poll = async () => {
  while (inflight) await inflight;
  inflight = pollOnce().finally(() => { inflight = null; });
  return inflight;
};

/** رسائل أقدم عند الوصول لأعلى الخيط، مع حفظ موضع القراءة */
const loadOlder = async () => {
  const id = activeId.value;
  const before = messages.value[0]?.id;
  if (!id || !before || !hasMore.value || loadingOlder.value) return;
  const mine = seq;
  loadingOlder.value = true;
  try {
    const { data } = await axios.get(threadUrl(id, 'messages'), { ...api, params: { before } });
    if (mine !== seq) return;
    const el = scroller.value;
    const prev = el ? el.scrollHeight : 0;
    const known = new Set(messages.value.map((m) => m.id));
    messages.value = [...(data.messages || []).filter((m) => !known.has(m.id)), ...messages.value];
    hasMore.value = !!data.has_more;
    loadingOlder.value = false;
    await nextTick();
    if (el) el.scrollTop += el.scrollHeight - prev;
  } catch (e) {
    if (mine === seq && gone(e)) closeOutOfScope();
  } finally {
    if (mine === seq) loadingOlder.value = false;
  }
};

const onScroll = () => {
  const el = scroller.value;
  if (!el) return;
  if (el.scrollTop < 80 && hasMore.value && !loadingOlder.value) loadOlder();
  if (newBelow.value && nearBottom()) {
    newBelow.value = 0;
    markRead(activeId.value);
  }
};

const jumpToNew = async () => {
  newBelow.value = 0;
  await scrollToBottom();
  markRead(activeId.value);
};

const send = async () => {
  // رقم المحادثة يُثبَّت لحظة الضغط، فلو انتقل الموظف لمحادثة أخرى أثناء الطلب لا يُنسب النص لها
  const id = activeId.value;
  const body = drafts.get(id).trim();
  if (!id || !body || sending.value) return;
  sending.value = true; sendError.value = '';
  try {
    await axios.post(threadUrl(id, 'send'), { body }, api);
    drafts.clear(id);
    if (activeId.value === id) {
      await poll();
      newBelow.value = 0;
      await scrollToBottom();
    }
  } catch (e) {
    if (e?.response?.status === 404 && activeId.value === id) return closeOutOfScope();
    sendError.value = errorText(e, 'تعذّر الإرسال');
  } finally { sending.value = false; }
};

/** إجراء على المحادثة المفتوحة؛ 404 تعني أنها خرجت من النطاق */
const act = async (action, payload, onDone, fallback) => {
  const id = activeId.value;
  if (!id) return;
  sendError.value = '';
  try {
    const { data } = await axios.post(threadUrl(id, action), payload, api);
    if (activeId.value === id && thread.value) await onDone?.(data, id);
    load();
    return true;
  } catch (e) {
    if (activeId.value !== id) return false;
    if (e?.response?.status === 404) closeOutOfScope();
    else sendError.value = errorText(e, fallback);
    return false;
  }
};

const toggleBot = () => act('toggle-bot', {}, (data) => { thread.value.bot_enabled = data.bot_enabled; }, 'تعذّر تغيير حالة البوت');

const resolve = () => act('resolve', {}, () => { thread.value.needs_attention = false; thread.value.attention_reason = null; }, 'تعذّر حفظ المعالجة');

/** إضافة/إزالة وسم موضوع للمحادثة المفتوحة */
const setTopic = (key, attach) => {
  if (!key) return;
  return act('topic', { topic: key, attach }, (data) => {
    thread.value.topics = data.topics;
    if (!attach) poll();   // إزالة وسم اختصاصك قد تخرج المحادثة من نطاقك
  }, 'تعذّر تعديل الوسم');
};

const addTopic = () => {
  const key = newTopic.value;
  if (!key) return;
  newTopic.value = '';
  setTopic(key, true);
};

/** تحويل المحادثة لموظف آخر؛ عند الرفض تعود القائمة لقيمتها المحفوظة */
const assignTo = async (el) => {
  const value = el.value;
  const ok = await act('assign', { user_id: value === '' ? null : Number(value) }, () => poll(), 'تعذّر التوكيل');
  if (!ok) el.value = String(thread.value?.assigned_to ?? '');
};

const bubble = (m) => {
  if (m.direction === 'in') return 'bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 rounded-bl-sm';
  if (m.source === 'bot') return 'bg-emerald-600 text-white rounded-br-sm';
  if (m.source === 'system') return 'bg-gray-400 text-white rounded-br-sm';
  return 'bg-gold-500 text-black rounded-br-sm';   // staff
};
const srcLabel = (s) => ({ bot: 'بوت', staff: 'موظف', system: 'نظام', injected: 'محقونة' }[s] || s);

const onVisible = () => { if (document.visibilityState === 'visible') poll(); };

onMounted(() => {
  // فتح محادثة مباشرة من رابط طلب التسعير: ?conv=123
  const q = new URLSearchParams(window.location.search).get('conv');
  if (q) open(Number(q));
  timer = setInterval(() => { load(); poll(); }, 15000);
  document.addEventListener('visibilitychange', onVisible);
});
onUnmounted(() => {
  if (timer) clearInterval(timer);
  clearTimeout(t);
  seq++;
  document.removeEventListener('visibilitychange', onVisible);
});
</script>
