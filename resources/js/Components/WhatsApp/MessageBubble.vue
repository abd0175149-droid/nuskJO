<template>
  <div class="flex" :class="m.is_system ? 'justify-center' : (m.direction === 'in' ? 'justify-start' : 'justify-end')">
    <!-- تحديث هوية من واتساب (E11): ليس رسالة من العميل -->
    <div v-if="m.is_system" dir="auto" class="max-w-[85%] rounded-full px-3 py-1 text-[11px] text-gray-600 dark:text-gray-300 bg-gray-200 dark:bg-gray-800 [unicode-bidi:plaintext]">
      {{ m.body }} <span dir="ltr" class="opacity-60">· {{ m.at }}</span>
    </div>
    <div v-else class="max-w-[85%] sm:max-w-[78%] rounded-2xl px-3.5 py-2 text-sm shadow-sm" :class="tone">
      <p v-if="m.sender" dir="auto" class="text-[10px] font-bold opacity-80 mb-0.5 break-words">👤 {{ m.sender }}</p>
      <!-- الاقتباس (E11، ويكمله E33) -->
      <slot name="quote">
        <p v-if="m.reply_to" dir="auto" class="mb-1 px-2 py-1 rounded-lg border-s-2 border-gray-400/60 bg-black/5 dark:bg-white/5 text-[11px] opacity-80 break-words line-clamp-2 [unicode-bidi:plaintext]">↩️ {{ m.reply_to }}</p>
      </slot>
      <WaText :text="m.body || ''"/>
      <!-- المرفق (E14) -->
      <slot name="media"/>
      <div class="flex items-center gap-1.5 mt-1 text-[10px] opacity-70 flex-wrap">
        <span dir="ltr">{{ m.at }}</span>
        <span v-if="m.source !== 'customer' && !m.sender">· {{ SOURCES[m.source] || m.source }}</span>
        <!-- حالة التسليم (يكملها E21) -->
        <slot name="status">
          <span v-if="m.status === 'failed'" class="font-bold" :title="m.error || ''">· فشل</span>
          <span v-else-if="m.status === 'blocked'" class="px-1.5 rounded bg-red-600 text-white font-bold" :title="m.error || ''">🛡️ حُجبت</span>
          <span v-else-if="m.status === 'unconfirmed'" title="قبلتها ميتا بلا معرّف رسالة: لا دليل على وصولها">· غير مؤكد</span>
        </slot>
      </div>
      <p v-if="(m.status === 'failed' || m.status === 'blocked') && m.error" class="mt-0.5 text-[10px] leading-4 opacity-80 break-words">{{ m.error }}</p>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import WaText from './WaText.vue';

const props = defineProps({ m: { type: Object, required: true } });

const SOURCES = { bot: 'بوت', staff: 'موظف', system: 'نظام', injected: 'محقونة' };

// الوارد أبيض، والبوت أخضر، والنظام رمادي، والموظف ذهبي، والمحجوبة حمراء (E15)
const tone = computed(() => {
  const m = props.m;
  if (m.direction === 'in') return 'bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 rounded-ss-sm';
  if (m.status === 'blocked') return 'bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200 border border-red-300 dark:border-red-700 rounded-se-sm';
  if (m.source === 'bot') return 'bg-emerald-600 text-white rounded-se-sm';
  if (m.source === 'system') return 'bg-gray-500 text-white rounded-se-sm';
  return 'bg-gold-500 text-black rounded-se-sm';
});
</script>
