<template>
  <!-- فلاتر الخيط (E30.8): المرسل والنوع والفترة. التطبيق يعيد التحميل من الأحدث، و«عرض مفلتر» ظاهر ما دام فلتر -->
  <div class="px-3 sm:px-4 py-2 border-b border-gray-200 dark:border-gray-700 bg-gray-50/70 dark:bg-gray-950/30 space-y-1.5 shrink-0" role="group" aria-label="تصفية رسائل المحادثة">
    <div class="flex flex-wrap items-center gap-1.5">
      <button v-for="o in SOURCES" :key="o.key" type="button" @click="pick('source', o.key)" :aria-pressed="(draft.source || '') === o.key"
              class="min-h-8 px-2.5 rounded-lg text-[11px] font-bold border" :class="chip((draft.source || '') === o.key)">{{ o.label }}</button>
      <span class="w-px h-5 bg-gray-200 dark:bg-gray-700 mx-0.5" aria-hidden="true"></span>
      <button v-for="o in TYPES" :key="o.key" type="button" @click="pick('msg_type', draft.msg_type === o.value ? '' : o.value)" :aria-pressed="draft.msg_type === o.value"
              class="min-h-8 px-2.5 rounded-lg text-[11px] font-bold border" :class="chip(draft.msg_type === o.value)">{{ o.label }}</button>
    </div>
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-[11px] text-gray-600 dark:text-gray-300">
      <label class="flex items-center gap-1">من <input :value="draft.date_from || ''" @change="pick('date_from', $event.target.value)" type="date" dir="ltr" aria-label="من تاريخ"
             class="px-1.5 py-1 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-white"/></label>
      <label class="flex items-center gap-1">إلى <input :value="draft.date_to || ''" @change="pick('date_to', $event.target.value)" type="date" dir="ltr" aria-label="إلى تاريخ"
             class="px-1.5 py-1 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 dark:text-white"/></label>
      <button v-if="active" type="button" @click="$emit('clear')" class="min-h-8 px-2 rounded-lg font-bold text-red-700 dark:text-red-300 hover:bg-red-50 dark:hover:bg-red-900/20">إلغاء الفلتر</button>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, watch } from 'vue';

const props = defineProps({ filters: { type: Object, default: () => ({}) } });
const emit = defineEmits(['apply', 'clear']);

const SOURCES = [
  { key: '', label: 'الكل' },
  { key: 'customer', label: '👤 العميل' },
  { key: 'bot', label: '🤖 البوت' },
  { key: 'staff', label: '🧑‍💼 الموظف' },
];
const TYPES = [
  { key: 'text', label: 'نص', value: 'text' },
  { key: 'media', label: '📷 صور وملفات', value: 'image,document,video,sticker' },
  { key: 'audio', label: '🎤 صوت', value: 'audio' },
];

const draft = reactive({ ...props.filters });
watch(() => props.filters, (f) => { Object.keys(draft).forEach((k) => delete draft[k]); Object.assign(draft, f); });
const active = computed(() => Object.values(props.filters || {}).some(Boolean));

function pick(key, value) {
  draft[key] = value || '';
  emit('apply', Object.fromEntries(Object.entries(draft).filter(([, v]) => v)));
}

const chip = (on) => (on
  ? 'bg-gold-50 dark:bg-gold-900/20 border-gold-400 text-gold-700 dark:text-gold-400'
  : 'border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 hover:border-gold-400');
</script>
