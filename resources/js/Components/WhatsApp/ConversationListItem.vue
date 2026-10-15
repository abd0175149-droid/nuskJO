<template>
  <button type="button" @click="$emit('open', c.id)" :aria-current="active ? 'true' : undefined"
          class="w-full text-start px-3 sm:px-4 py-3 border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors flex gap-3"
          :class="active ? 'bg-gold-50/60 dark:bg-gold-900/10' : ''">
    <!-- صورة رمزية من الأحرف الأولى بلون ثابت (Cloud API لا يعطي صورة العميل) -->
    <span aria-hidden="true" class="shrink-0 w-10 h-10 rounded-full grid place-items-center text-sm font-bold text-white" :class="colorFor(c.id)">{{ initials(c.name) }}</span>
    <span class="min-w-0 flex-1 block">
      <span class="flex items-center justify-between gap-2">
        <bdi dir="auto" class="font-bold text-sm truncate [unicode-bidi:plaintext]">{{ c.name }}</bdi>
        <span v-if="c.unread" class="shrink-0 min-w-5 h-5 px-1.5 rounded-full bg-red-500 text-white text-[10px] font-bold grid place-items-center" :aria-label="`${c.unread} غير مقروءة`">{{ c.unread }}</span>
      </span>
      <!-- سطر الهوية (E11): الرقم منسّقاً، أو اسم المستخدم، أو «رقم غير ظاهر» -->
      <span class="block text-[11px] text-gray-400 dark:text-gray-500 truncate">
        <bdi v-if="c.phone" dir="ltr" class="font-mono">{{ formatPhone(c.phone) }}</bdi>
        <bdi v-else-if="c.username" dir="ltr" class="font-mono">@{{ c.username }}</bdi>
        <span v-else>رقم غير ظاهر</span>
      </span>
      <span class="block text-xs text-gray-500 dark:text-gray-400 truncate mt-0.5">
        <span v-if="lastTypeIcon(c.last_type)" class="me-1" aria-hidden="true">{{ lastTypeIcon(c.last_type) }}</span>
        <bdi dir="auto" class="[unicode-bidi:plaintext]">{{ preview || '—' }}</bdi>
      </span>
      <span v-if="(c.topics && c.topics.length) || c.assignee" class="flex items-center gap-1 mt-1 flex-wrap">
        <span v-for="tp in (c.topics || [])" :key="tp.key" :title="tp.source === 'manual' ? 'وسم يدوي' : 'وسم آلي من البوت'"
              class="px-1.5 py-0.5 rounded text-[10px] leading-4"
              :class="tp.source === 'manual'
                ? 'bg-gold-50 dark:bg-gold-900/20 text-gold-700 dark:text-gold-300 ring-1 ring-gold-300 dark:ring-gold-700'
                : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300'">{{ tp.label }}</span>
        <span v-if="c.assignee" class="text-[10px] text-gray-400 dark:text-gray-500">‹{{ c.assignee }}›</span>
      </span>
      <span class="flex items-center gap-1.5 mt-1.5 flex-wrap">
        <span v-if="c.needs_attention" class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300">⚠️ تدخّل</span>
        <span v-if="c.phoneless" class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300" title="العميل يراسل باسم مستخدم ورقمه غير ظاهر">📵 بلا رقم</span>
        <span v-if="c.from_ad" class="px-1.5 py-0.5 rounded text-[10px] bg-sky-100 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300" title="بدأت المحادثة من إعلان">📣 من إعلان</span>
        <span v-if="c.needs_attention && c.attention_reason" class="px-1.5 py-0.5 rounded text-[10px] bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-300">{{ attentionReasons[c.attention_reason] || c.attention_reason }}</span>
        <span class="px-1.5 py-0.5 rounded text-[10px] font-bold" :class="c.bot_enabled ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300' : 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300'">
          {{ c.bot_enabled ? (c.paused ? '🤖 موقوف مؤقتاً' : '🤖 بوت') : '👤 بشري' }}
        </span>
        <span v-if="c.is_admin" class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300" title="رقم إدارة — يخدمه المساعد الإداري">👔 إدارة</span>
        <span v-if="c.client" class="px-1.5 py-0.5 rounded text-[10px] bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">عميل مسجّل</span>
        <span v-if="!c.window_open" class="px-1.5 py-0.5 rounded text-[10px] bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">نافذة مغلقة</span>
        <span v-else-if="c.needs_attention && urgency(c.window_left) && urgency(c.window_left) !== 'ok'"
              class="px-1.5 py-0.5 rounded text-[10px] font-bold"
              :class="urgency(c.window_left) === 'danger' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700'"
              :title="'تُغلق نافذة الرد خلال ' + windowLabel(c.window_left)">⏳ {{ windowLabel(c.window_left) }}</span>
        <span class="text-[10px] text-gray-400 ms-auto" :title="c.last_at">{{ relativeTime(c.sort_ts, c.last_at) }}</span>
      </span>
    </span>
  </button>
</template>

<script setup>
import { computed } from 'vue';
import { colorFor, initials } from '@/composables/whatsapp/useInitials.js';
import { formatPhone } from '@/composables/whatsapp/usePhoneFormat.js';
import { toPlain } from '@/composables/whatsapp/useWaFormat.js';
import { urgency, windowLabel } from '@/composables/whatsapp/useWindowCountdown.js';
import { lastTypeIcon } from '@/composables/whatsapp/useConversationList.js';
import { relativeTime } from '@/composables/whatsapp/useRelativeTime.js';

const props = defineProps({
  c: { type: Object, required: true },
  active: Boolean,
  attentionReasons: { type: Object, default: () => ({}) },
});
defineEmits(['open']);

// المعاينة نص خام بلا علامات تنسيق ولا روابط
const preview = computed(() => toPlain(props.c.preview || ''));
</script>
