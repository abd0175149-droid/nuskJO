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
          <template v-if="status.suspended">⛔ الإرسال موقوف (قفل طارئ)</template>
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
                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold" :class="c.bot_enabled ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600'">
                  {{ c.bot_enabled ? (c.paused ? '🤖 موقوف مؤقتاً' : '🤖 بوت') : '👤 بشري' }}
                </span>
                <span v-if="c.client" class="px-1.5 py-0.5 rounded text-[10px] bg-blue-100 text-blue-700">عميل مسجّل</span>
                <span v-if="!c.window_open" class="px-1.5 py-0.5 rounded text-[10px] bg-amber-100 text-amber-700">نافذة مغلقة</span>
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
                  <p class="text-xs text-gray-400 font-mono" dir="ltr">{{ thread.phone }}<span v-if="thread.client"> · {{ thread.client }}</span></p>
                </div>
                <div class="flex items-center gap-2">
                  <button v-if="thread.needs_attention" @click="resolve" class="px-2.5 py-1 rounded-lg text-xs font-bold text-green-700 bg-green-50 dark:bg-green-900/20">✅ تمّت المعالجة</button>
                  <button @click="toggleBot" class="px-2.5 py-1 rounded-lg text-xs font-bold border"
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
                <select v-if="props.canAssign" :value="thread.assigned_to ?? ''" @change="assignTo($event.target.value)"
                        aria-label="توكيل المحادثة لموظف"
                        class="px-2 py-1 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-[11px] dark:text-white">
                  <option value="">— بلا توكيل</option>
                  <option v-for="u in props.staff" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
                <span v-else class="text-[11px] text-gray-500 dark:text-gray-400">{{ thread.assignee || '— بلا توكيل' }}</span>
              </div>
            </div>

            <!-- الفقاعات -->
            <div ref="scroller" class="flex-1 overflow-y-auto p-4 space-y-2 bg-gray-50 dark:bg-gray-950/40">
              <div v-for="m in messages" :key="m.id" class="flex" :class="m.direction === 'in' ? 'justify-start' : 'justify-end'">
                <div class="max-w-[78%] rounded-2xl px-3.5 py-2 text-sm shadow-sm" :class="bubble(m)">
                  <p v-if="m.sender" class="text-[10px] font-bold opacity-80 mb-0.5 break-words">👤 {{ m.sender }}</p>
                  <p class="whitespace-pre-wrap break-words">{{ m.body }}</p>
                  <div class="flex items-center gap-1.5 mt-1 text-[10px] opacity-70">
                    <span dir="ltr">{{ m.at }}</span>
                    <span v-if="m.source !== 'customer' && !m.sender">· {{ srcLabel(m.source) }}</span>
                    <span v-if="m.status === 'failed'" class="text-red-200">· فشل</span>
                  </div>
                </div>
              </div>
              <p v-if="!messages.length" class="text-center text-gray-400 text-sm py-10">لا رسائل</p>
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
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/SmartLayout.vue';

const props = defineProps({
  title: String,
  conversations: { type: Array, default: () => [] },
  filters: Object,
  status: { type: Object, default: () => ({}) },
  canReply: Boolean,
  canAssign: Boolean,
  seesAll: Boolean,
  topics: { type: Object, default: () => ({}) },
  staff: { type: Array, default: () => [] },
});

const list = ref([...props.conversations]);
const filter = ref(props.filters?.filter || 'all');
const search = ref(props.filters?.search || '');
const topic = ref(props.filters?.topic || '');
const newTopic = ref('');
const activeId = ref(null);
const thread = ref(null);
const messages = ref([]);
const notes = ref([]);
const draft = ref('');
const sending = ref(false);
const sendError = ref('');
const scroller = ref(null);
let timer = null, t = null;

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

const open = async (id) => {
  if (activeId.value !== id) newTopic.value = '';
  activeId.value = id;
  sendError.value = '';
  try {
    const { data } = await axios.get(`/api/whatsapp/conversations/${id}/messages`, api);
    thread.value = data.conversation;
    messages.value = data.messages || [];
    notes.value = data.notes || [];
    await nextTick();
    if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight;
    load();
  } catch (e) { sendError.value = 'تعذّر تحميل المحادثة'; }
};

const send = async () => {
  const body = draft.value.trim();
  if (!body || sending.value) return;
  sending.value = true; sendError.value = '';
  try {
    await axios.post(`/api/whatsapp/conversations/${activeId.value}/send`, { body }, api);
    draft.value = '';
    await open(activeId.value);
  } catch (e) {
    sendError.value = e.response?.data?.error || 'تعذّر الإرسال';
  } finally { sending.value = false; }
};

const toggleBot = async () => {
  try {
    const { data } = await axios.post(`/api/whatsapp/conversations/${activeId.value}/toggle-bot`, {}, api);
    thread.value.bot_enabled = data.bot_enabled;
    load();
  } catch (e) { /* noop */ }
};

const resolve = async () => {
  try {
    await axios.post(`/api/whatsapp/conversations/${activeId.value}/resolve`, {}, api);
    thread.value.needs_attention = false;
    load();
  } catch (e) { /* noop */ }
};

/** إضافة/إزالة وسم موضوع للمحادثة المفتوحة */
const setTopic = (key, attach) => {
  const id = activeId.value;
  if (!id || !key) return;
  router.post(`/api/whatsapp/conversations/${id}/topic`, { topic: key, attach }, {
    preserveScroll: true,
    preserveState: true,
    onFinish: () => { if (activeId.value === id) open(id); },
  });
};

const addTopic = () => {
  const key = newTopic.value;
  if (!key) return;
  newTopic.value = '';
  setTopic(key, true);
};

/** تحويل المحادثة لموظف آخر */
const assignTo = (value) => {
  const id = activeId.value;
  if (!id) return;
  router.post(`/api/whatsapp/conversations/${id}/assign`, { user_id: value === '' ? null : Number(value) }, {
    preserveScroll: true,
    preserveState: true,
    onFinish: () => { if (activeId.value === id) open(id); },
  });
};

const bubble = (m) => {
  if (m.direction === 'in') return 'bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 rounded-bl-sm';
  if (m.source === 'bot') return 'bg-emerald-600 text-white rounded-br-sm';
  if (m.source === 'system') return 'bg-gray-400 text-white rounded-br-sm';
  return 'bg-gold-500 text-black rounded-br-sm';   // staff
};
const srcLabel = (s) => ({ bot: 'بوت', staff: 'موظف', system: 'نظام', injected: 'محقونة' }[s] || s);

onMounted(() => {
  // فتح محادثة مباشرة من رابط طلب التسعير: ?conv=123
  const q = new URLSearchParams(window.location.search).get('conv');
  if (q) open(Number(q));
  timer = setInterval(() => { load(); if (activeId.value) open(activeId.value); }, 15000);
});
onUnmounted(() => { if (timer) clearInterval(timer); clearTimeout(t); });
</script>
