<template>
  <!-- بطاقات أعلى الصندوق (E17): أعداد فقط بنطاق الموظف، والنقر يطبّق الفلتر. على الجوال صف أفقي يتمرر وحده -->
  <div class="flex gap-2 overflow-x-auto pb-1 -mb-1 min-w-0 [scrollbar-width:thin]" role="group" aria-label="ملخص الصندوق">
    <div class="shrink-0 flex items-center gap-2 px-3 py-2 rounded-xl border text-xs"
         :class="channelBad ? 'border-red-300 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-200'
                            : 'border-gray-200 bg-white text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300'">
      <span aria-hidden="true">{{ channelBad ? '🔴' : '🟢' }}</span>
      <span class="font-bold">{{ CHANNEL[summary.channel?.state] || 'القناة' }}</span>
    </div>
    <button v-for="card in cards" :key="card.key" type="button" @click="$emit('filter', active === card.filter ? 'all' : card.filter)"
            :aria-pressed="active === card.filter" :title="card.title"
            class="shrink-0 flex items-center gap-2 min-h-11 px-3 py-2 rounded-xl border text-xs transition-colors"
            :class="active === card.filter
              ? 'border-gold-400 bg-gold-50 text-gold-800 dark:bg-gold-900/20 dark:text-gold-200'
              : (card.count ? card.tone : 'border-gray-200 bg-white text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400')">
      <span aria-hidden="true">{{ card.icon }}</span>
      <span>{{ card.label }}</span>
      <span class="min-w-6 px-1.5 rounded-full font-bold text-center" :class="card.count ? 'bg-black/10 dark:bg-white/10' : ''">{{ card.count }}</span>
    </button>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  summary: { type: Object, default: () => ({}) },
  active: { type: String, default: 'all' },
});
defineEmits(['filter']);

const CHANNEL = {
  ok: 'القناة تعمل',
  suspended: 'الإرسال موقوف',
  blocked: 'القناة متوقفة من ميتا',
  not_ready: 'القناة غير مهيّأة',
  bot_off: 'البوت مطفأ',
};
const channelBad = computed(() => ['suspended', 'blocked', 'not_ready'].includes(props.summary.channel?.state));

const FAILED = { failed: 'فشل', unknown: 'غير مؤكد', blocked: 'حُجب' };
const failedTitle = computed(() => Object.entries(props.summary.failed_breakdown || {})
  .map(([k, n]) => `${FAILED[k] || k}: ${n}`).join(' · ') || 'لا رسائل فاشلة اليوم');

const cards = computed(() => {
  const s = props.summary || {};
  const list = [
    { key: 'waiting', filter: 'attention', icon: '👤', label: 'ينتظر موظفاً', count: s.waiting_staff || 0, title: 'محادثات تحتاج تدخّلاً',
      tone: 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-200' },
    { key: 'closing', filter: 'window_closing', icon: '⏳', label: 'نافذة تُغلق خلال 3س', count: s.window_closing || 0, title: 'عميل ينتظر ونافذة الرد عليه تُغلق قريباً',
      tone: 'border-red-300 bg-red-50 text-red-800 dark:border-red-700 dark:bg-red-900/20 dark:text-red-200' },
    { key: 'failed', filter: 'failed_today', icon: '❌', label: 'فشل اليوم', count: s.failed_today || 0, title: failedTitle.value,
      tone: 'border-red-300 bg-red-50 text-red-800 dark:border-red-700 dark:bg-red-900/20 dark:text-red-200' },
  ];
  if (s.stuck !== undefined) {
    list.push({ key: 'stuck', filter: 'stuck', icon: '🧩', label: 'عالقة', count: s.stuck || 0,
      title: `ردود آلية لم تصل منذ دقائق${s.failed_jobs ? ` · وظائف رد فشلت خلال 24 ساعة: ${s.failed_jobs}` : ''}`,
      tone: 'border-purple-300 bg-purple-50 text-purple-800 dark:border-purple-700 dark:bg-purple-900/20 dark:text-purple-200' });
  }
  return list;
});
</script>
