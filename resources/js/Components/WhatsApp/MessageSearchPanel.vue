<template>
  <!-- البحث في نص الرسائل (E30.7) داخل عمود القائمة؛ على الجوال يملأ الشاشة كالقائمة. النتائج من الخادم محصورة بالسياج -->
  <div class="flex flex-col min-h-0 flex-1" @keydown.esc.prevent="onEscape">
    <div class="p-3 border-b border-gray-200 dark:border-gray-700 space-y-2 shrink-0">
      <div class="flex items-center gap-2">
        <input ref="input" :value="s.q" @input="search.setQuery($event.target.value)" type="search" dir="auto" maxlength="100"
               placeholder="ابحث في نص الرسائل..." aria-label="بحث في نص الرسائل" data-test="message-search-input"
               class="flex-1 min-w-0 px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm dark:text-white [unicode-bidi:plaintext]"/>
        <button type="button" @click="$emit('close')" aria-label="إغلاق البحث في الرسائل" title="إغلاق البحث في الرسائل"
                class="shrink-0 min-w-11 min-h-11 rounded-xl text-gray-500 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800">✕</button>
      </div>
      <div class="grid grid-cols-3 gap-1.5" role="group" aria-label="تصفية نتائج البحث">
        <select v-model="filters.source" aria-label="المرسل" class="min-w-0 px-2 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-[11px] dark:text-white">
          <option value="">كل المرسلين</option>
          <option value="customer">العميل</option>
          <option value="bot">البوت</option>
          <option value="staff">الموظف</option>
          <option value="system">النظام</option>
        </select>
        <select v-model="filters.direction" aria-label="الاتجاه" class="min-w-0 px-2 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-[11px] dark:text-white">
          <option value="">وارد وصادر</option>
          <option value="in">الوارد</option>
          <option value="out">الصادر</option>
        </select>
        <select v-model="filters.type" aria-label="النوع" class="min-w-0 px-2 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-[11px] dark:text-white">
          <option value="">كل الأنواع</option>
          <option value="text">نص</option>
          <option value="media">صور وملفات</option>
          <option value="audio">صوت</option>
        </select>
      </div>
      <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-[11px] text-gray-600 dark:text-gray-300">
        <label class="flex items-center gap-1">من <input v-model="filters.date_from" type="date" dir="ltr" aria-label="من تاريخ"
               class="px-1.5 py-1 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-white"/></label>
        <label class="flex items-center gap-1">إلى <input v-model="filters.date_to" type="date" dir="ltr" aria-label="إلى تاريخ"
               class="px-1.5 py-1 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-white"/></label>
        <label v-if="activeId" class="flex items-center gap-1.5 min-h-8"><input v-model="filters.here" type="checkbox" class="rounded border-gray-300 dark:border-gray-600"/> هذه المحادثة فقط</label>
      </div>
      <p class="text-[11px] min-h-4" role="status" aria-live="polite">
        <span v-if="s.error" class="text-red-700 dark:text-red-400">{{ s.error }}</span>
        <span v-else-if="s.hint" class="text-gray-500 dark:text-gray-400">{{ s.hint }}</span>
        <span v-else-if="s.loading" class="text-gray-500 dark:text-gray-400">جارٍ البحث...</span>
        <span v-else-if="s.ran" class="text-gray-500 dark:text-gray-400" data-test="search-count">
          {{ s.capped ? '+1000' : s.total }} نتيجة <bdi dir="ltr">({{ s.tookMs }} ms)</bdi>
          <template v-if="s.engine === 'like'"> · بحث أبطأ (بلا فهرس)</template>
        </span>
      </p>
    </div>

    <ul class="overflow-y-auto flex-1 min-h-0" aria-label="نتائج البحث في الرسائل">
      <li v-for="h in s.hits" :key="h.message_id" class="border-b border-gray-100 dark:border-gray-800">
        <button type="button" @click="$emit('jump', h)" data-test="search-hit"
                class="block w-full text-start px-3 py-2.5 hover:bg-gold-50 dark:hover:bg-gold-900/10 focus-visible:bg-gold-50 dark:focus-visible:bg-gold-900/10">
          <span class="flex items-center justify-between gap-2 text-[11px]">
            <span class="font-bold text-gray-800 dark:text-gray-100 truncate"><bdi dir="auto">{{ h.conversation_name }}</bdi></span>
            <bdi dir="ltr" class="shrink-0 text-gray-400">{{ h.at }}</bdi>
          </span>
          <span class="mt-0.5 flex gap-1.5 text-xs text-gray-700 dark:text-gray-300">
            <span class="shrink-0" :title="SOURCES[h.source]?.[1] || h.source" aria-hidden="true">{{ SOURCES[h.source]?.[0] || '•' }}</span>
            <span class="sr-only">{{ SOURCES[h.source]?.[1] || h.source }}:</span>
            <span class="min-w-0 line-clamp-3"><HighlightedSnippet :segments="h.segments"/></span>
          </span>
        </button>
      </li>
      <li v-if="s.ran && !s.loading && !s.hits.length && !s.error" class="py-12 text-center text-gray-400 text-sm">لا نتائج</li>
      <li v-if="search.canLoadMore()" class="p-3 text-center">
        <button type="button" :disabled="s.loadingMore" @click="search.loadMore()"
                class="min-h-11 px-4 py-2 rounded-xl text-xs font-bold border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:border-gold-400 disabled:opacity-60">
          {{ s.loadingMore ? 'جارٍ التحميل...' : 'المزيد' }}
        </button>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import HighlightedSnippet from './HighlightedSnippet.vue';
import { createMessageSearch } from '@/composables/whatsapp/useMessageSearch.js';

const props = defineProps({
  // المحادثة المفتوحة: «هذه المحادثة فقط»
  activeId: { type: Number, default: null },
  // للاختبار
  searcher: { type: Object, default: null },
});
const emit = defineEmits(['close', 'jump']);

const SOURCES = { customer: ['👤', 'العميل'], bot: ['🤖', 'البوت'], staff: ['🧑‍💼', 'موظف'], system: ['⚙️', 'النظام'] };

const search = props.searcher || createMessageSearch();
const s = search.state;
const input = ref(null);
const filters = reactive({ source: '', direction: '', type: '', date_from: '', date_to: '', here: false });

// تغيير الفلاتر يعيد البحث؛ وفتح محادثة أخرى لا يعيده إلا مع «هذه المحادثة فقط»
let applied = '{}';
watch([filters, () => props.activeId], () => {
  const next = { ...filters, here: undefined, conversation_id: filters.here && props.activeId ? props.activeId : undefined };
  const key = JSON.stringify(next);
  if (key === applied) return;
  applied = key;
  search.setFilters(next);
}, { deep: true });

// Escape: يلغي الطلب ويغلق النتائج ويعيد التركيز لحقل البحث؛ وثانيةً (لا شيء مفتوح) تغلق اللوحة
function onEscape() {
  if (search.cancel()) input.value?.focus();
  else emit('close');
}

onMounted(() => nextTick(() => input.value?.focus()));
onUnmounted(() => search.dispose());
defineExpose({ focus: () => input.value?.focus() });
</script>
