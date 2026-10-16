<template>
  <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden flex flex-col min-h-0" aria-label="المحادثات">
    <!-- البحث في نص الرسائل (E30) يحل محل القائمة داخل العمود نفسه، وتبقى حالته عند الرجوع من المحادثة -->
    <MessageSearchPanel v-if="messageSearch && searching" :active-id="activeId" @close="searching = false" @jump="(h) => $emit('jump', h)"/>
    <template v-else>
    <div class="p-3 border-b border-gray-200 dark:border-gray-700 space-y-2 shrink-0">
      <div class="flex items-center gap-2">
        <input :value="searchText" @input="onSearch($event.target.value)" type="search" dir="auto" placeholder="بحث بالاسم أو الرقم..."
               aria-label="بحث في المحادثات"
               class="flex-1 min-w-0 w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm dark:text-white [unicode-bidi:plaintext]"/>
        <button v-if="messageSearch" type="button" @click="searching = true" data-test="open-message-search" title="بحث في نص الرسائل"
                class="shrink-0 min-h-11 sm:min-h-0 px-2.5 py-2 rounded-xl text-xs font-bold border border-gold-400 text-gold-700 dark:text-gold-400 hover:bg-gold-50 dark:hover:bg-gold-900/20">🔎 في الرسائل</button>
      </div>
      <div class="flex flex-wrap gap-1.5" role="group" aria-label="تصفية المحادثات">
        <button v-for="f in filters" :key="f.key" type="button" @click="$emit('update:filter', f.key)" :aria-pressed="filter === f.key"
                class="px-2.5 py-1 rounded-lg text-[11px] font-bold border"
                :class="filter === f.key ? 'bg-gold-50 dark:bg-gold-900/20 border-gold-400 text-gold-700 dark:text-gold-400'
                                         : 'border-gray-200 dark:border-gray-700 text-gray-500 hover:border-gold-400'">{{ f.label }}</button>
      </div>
      <select :value="topic" @change="$emit('update:topic', $event.target.value)" aria-label="تصفية بحسب الموضوع"
              class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-xs dark:text-white">
        <option value="">كل المواضيع</option>
        <option v-for="(label, key) in topics" :key="key" :value="key">{{ label }}</option>
      </select>
      <!-- فلتر التصنيف (E29): يضيّق داخل نطاقك فقط -->
      <select v-if="labels.length" :value="label" @change="$emit('update:label', $event.target.value)" aria-label="تصنيف بحسب التصنيف الداخلي"
              class="w-full px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-xs dark:text-white" data-test="label-filter">
        <option value="">كل التصنيفات</option>
        <option v-for="l in labels" :key="l.id" :value="String(l.id)">{{ l.name }}</option>
      </select>
      <p v-if="!seesAll" class="text-[11px] text-gray-500 dark:text-gray-400">تظهر لك المحادثات التي تخصّ مجالاتك فقط</p>
    </div>

    <div ref="scroller" class="overflow-y-auto flex-1 min-h-0">
      <ConversationListItem v-for="c in items" :key="c.id" :c="c" :active="activeId === c.id" :attention-reasons="attentionReasons" @open="(id) => $emit('open', id)"/>
      <p v-if="!items.length" class="py-14 text-center text-gray-400 text-sm">لا محادثات</p>
      <div v-if="hasMore" class="p-3 text-center">
        <button type="button" :disabled="loadingMore" @click="$emit('loadMore')"
                class="min-h-11 px-4 py-2 rounded-xl text-xs font-bold border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:border-gold-400 disabled:opacity-60">
          {{ loadingMore ? 'جارٍ التحميل...' : 'تحميل المزيد' }}
        </button>
      </div>
    </div>
    </template>
  </section>
</template>

<script setup>
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';
import ConversationListItem from './ConversationListItem.vue';
import MessageSearchPanel from './MessageSearchPanel.vue';

const props = defineProps({
  items: { type: Array, default: () => [] },
  filter: { type: String, default: 'all' },
  search: { type: String, default: '' },
  topic: { type: String, default: '' },
  topics: { type: Object, default: () => ({}) },
  seesAll: Boolean,
  hasMore: Boolean,
  loadingMore: Boolean,
  activeId: { type: Number, default: null },
  attentionReasons: { type: Object, default: () => ({}) },
  // على الجوال تُخفى القائمة عند فتح محادثة: يُحفظ موضع التمرير ويُعاد عند الرجوع
  visible: { type: Boolean, default: true },
  // متابعة الصندوق (E17): تبويبات المغلقة والمؤجلة والمثبّتة وفلاتر البطاقات
  followup: Boolean,
  // التصنيفات الفعّالة (E29، فارغة والعلم مطفأ) والمختار منها
  labels: { type: Array, default: () => [] },
  label: { type: String, default: '' },
  // البحث في نص الرسائل (E30): العلم والصلاحية من الخادم
  messageSearch: Boolean,
  canBlock: Boolean,
});
const emit = defineEmits(['update:filter', 'update:search', 'update:topic', 'update:label', 'open', 'loadMore', 'jump']);
const searching = ref(false);

const BASE_FILTERS = [
  { key: 'all', label: 'الكل' },
  { key: 'unread', label: 'غير مقروء' },
  { key: 'attention', label: '⚠️ تدخّل' },
  { key: 'bot', label: '🤖 بوت' },
  { key: 'human', label: '👤 بشري' },
  { key: 'mine', label: '🙋 الموكلة لي' },
];
const FOLLOWUP_FILTERS = [
  { key: 'pinned', label: '📍 المثبّتة' },
  { key: 'window_closing', label: '⏳ نافذة تُغلق' },
  { key: 'failed_today', label: '❌ فشل اليوم' },
  { key: 'snoozed', label: '💤 مؤجلة' },
  { key: 'closed', label: '🗂️ مغلقة' },
];
// E31: تبويب المحظورين لمن يملك whatsapp.block (الخادم يفرضه)
const filters = computed(() => [...BASE_FILTERS, ...(props.followup ? FOLLOWUP_FILTERS : []), ...(props.canBlock ? [{ key: 'blocked', label: '⛔ المحظورون' }] : [])]);

// البحث بتأخير 400ms
const searchText = ref(props.search);
let t = null;
const onSearch = (v) => {
  searchText.value = v;
  clearTimeout(t);
  t = setTimeout(() => emit('update:search', v), 400);
};
onUnmounted(() => clearTimeout(t));

const scroller = ref(null);
let saved = 0;
// لوحة البحث تحل محل القائمة: يُحفظ موضع التمرير ويعود عند إغلاقها
watch(searching, async (on) => {
  if (on) { saved = scroller.value?.scrollTop ?? saved; return; }
  await nextTick();
  if (scroller.value) scroller.value.scrollTop = saved;
});
watch(() => props.visible, async (v) => {
  if (!scroller.value) return;
  if (!v) { saved = scroller.value.scrollTop; return; }
  await nextTick();
  scroller.value.scrollTop = saved;
});
</script>
