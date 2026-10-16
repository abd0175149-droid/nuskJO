<template>
  <div class="px-3 sm:px-4 py-3 border-b border-gray-200 dark:border-gray-700 space-y-2 shrink-0">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div class="flex items-center gap-2 min-w-0">
        <!-- على الجوال: رجوع إلى القائمة -->
        <button v-if="showBack" type="button" @click="$emit('back')" aria-label="رجوع إلى المحادثات"
                class="lg:hidden shrink-0 min-w-11 min-h-11 -ms-1 px-2 rounded-lg text-sm font-bold text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800">→ المحادثات</button>
        <span aria-hidden="true" class="shrink-0 w-9 h-9 rounded-full grid place-items-center text-xs font-bold text-white" :class="colorFor(thread.id)">{{ initials(thread.name) }}</span>
        <div class="min-w-0">
          <p class="font-bold text-sm truncate"><bdi dir="auto" class="[unicode-bidi:plaintext]">{{ thread.name }}</bdi></p>
          <p v-if="thread.phone" class="text-xs text-gray-400"><bdi dir="ltr" class="font-mono">{{ formatPhone(thread.phone) }}</bdi><span v-if="thread.client"> · <bdi dir="auto">{{ thread.client }}</bdi></span></p>
          <!-- عميل باسم مستخدم واتساب بلا رقم ظاهر (E11a) -->
          <p v-else class="text-xs text-amber-700 dark:text-amber-400">📵 رقم غير ظاهر<template v-if="thread.username"> · <bdi dir="ltr" class="font-mono">@{{ thread.username }}</bdi></template><span v-if="thread.client" class="text-gray-500 dark:text-gray-400"> · <bdi dir="auto">{{ thread.client }}</bdi></span></p>
          <p v-if="thread.profile_name && thread.profile_name !== thread.name" class="text-[11px] text-gray-400 dark:text-gray-500"
             title="اسم يضعه العميل بنفسه — غير موثّق">اسم واتساب: <bdi dir="auto">{{ thread.profile_name }}</bdi></p>
        </div>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <!-- ربط بعميل (E11.8) -->
        <template v-if="canLinkClient && !thread.is_admin">
          <button type="button" @click="$emit('link')" class="min-h-11 lg:min-h-0 px-2.5 py-1 rounded-lg text-xs font-bold border border-blue-300 text-blue-700 dark:border-blue-700 dark:text-blue-300">
            {{ thread.client_id ? '🔗 تغيير الربط' : '🔗 ربط بعميل' }}
          </button>
          <button v-if="thread.client_id" type="button" @click="$emit('unlink')" class="min-h-11 lg:min-h-0 px-2.5 py-1 rounded-lg text-xs border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300">فك الربط</button>
        </template>
        <span v-if="thread.needs_attention && thread.attention_reason" class="px-2 py-0.5 rounded-lg text-[11px] bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-300">{{ attentionReasons[thread.attention_reason] || thread.attention_reason }}</span>
        <button v-if="thread.needs_attention" type="button" @click="$emit('resolve')" class="min-h-11 lg:min-h-0 px-2.5 py-1 rounded-lg text-xs font-bold text-green-700 bg-green-50 dark:bg-green-900/20 dark:text-green-300">✅ تمّت المعالجة</button>
        <!-- حالة المتابعة (E17) -->
        <span v-if="thread.closed" class="px-2 py-0.5 rounded-lg text-[11px] bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200">🗂️ مغلقة</span>
        <span v-if="thread.snoozed_until" class="px-2 py-0.5 rounded-lg text-[11px] bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300">💤 حتى <bdi dir="ltr">{{ hhmm(thread.snoozed_until) }}</bdi></span>
                <button v-if="canToggleBot" type="button" @click="$emit('toggleBot')" class="min-h-11 lg:min-h-0 px-2.5 py-1 rounded-lg text-xs font-bold border"
                :class="thread.bot_enabled ? 'border-gray-300 text-gray-600 dark:text-gray-300' : 'border-green-400 text-green-700 bg-green-50 dark:bg-green-900/20 dark:text-green-300'">
          {{ thread.bot_enabled ? '⏸️ إيقاف البوت' : '▶️ تشغيل البوت' }}
        </button>
        <ThreadActionsMenu v-if="followup" :thread="thread" :can-reply="canReply" :can-reprocess="canReprocess" @action="(n, p) => $emit('action', n, p)"/>
      </div>
    </div>

    <!-- المواضيع -->
    <div class="flex flex-wrap items-center gap-1.5">
      <span class="text-[11px] text-gray-400 dark:text-gray-500">المواضيع:</span>
      <span v-for="tp in (thread.topics || [])" :key="tp.key" :title="TOPIC_SOURCES[tp.source] || TOPIC_SOURCES.bot"
            class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] leading-4"
            :class="tp.source === 'manual'
              ? 'bg-gold-50 dark:bg-gold-900/20 text-gold-700 dark:text-gold-300 ring-1 ring-gold-300 dark:ring-gold-700'
              : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300'">
        {{ tp.label }}
        <button type="button" @click="$emit('setTopic', tp.key, false)" :aria-label="`إزالة وسم ${tp.label}`" :title="`إزالة وسم ${tp.label}`"
                class="leading-none hover:text-red-600 dark:hover:text-red-400">✕</button>
      </span>
      <span v-if="!(thread.topics && thread.topics.length)" class="text-[10px] text-gray-400 dark:text-gray-500">بلا وسوم</span>
      <template v-if="addable.length">
        <select v-model="newTopic" aria-label="اختيار موضوع للإضافة"
                class="px-2 py-1 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-[11px] dark:text-white">
          <option value="">— اختر موضوعاً</option>
          <option v-for="tp in addable" :key="tp.key" :value="tp.key">{{ tp.label }}</option>
        </select>
        <button type="button" :disabled="!newTopic" @click="addTopic"
                class="px-2 py-1 rounded-lg text-[11px] font-bold border border-gold-400 text-gold-700 dark:text-gold-400 disabled:opacity-40">+ وسم</button>
      </template>
    </div>

    <!-- التوكيل -->
    <div class="flex flex-wrap items-center gap-1.5">
      <span class="text-[11px] text-gray-400 dark:text-gray-500">الموكل إليه:</span>
      <select v-if="canAssign" :value="thread.assigned_to ?? ''" @change="$emit('assign', $event.target)" aria-label="توكيل المحادثة لموظف"
              class="px-2 py-1 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-[11px] dark:text-white">
        <option value="">— بلا توكيل</option>
        <option v-for="u in staff" :key="u.id" :value="u.id">{{ u.name }}</option>
      </select>
      <span v-else class="text-[11px] text-gray-500 dark:text-gray-400">{{ thread.assignee || '— بلا توكيل' }}</span>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { colorFor, initials } from '@/composables/whatsapp/useInitials.js';
import { formatPhone } from '@/composables/whatsapp/usePhoneFormat.js';
import ThreadActionsMenu from './ThreadActionsMenu.vue';

// مصدر الوسم: يدوي، أو آلي من البوت، أو من قاعدة حتمية (E37)
const TOPIC_SOURCES = { manual: 'وسم يدوي', bot: 'وسم آلي من البوت', rule: 'وسم آلي من قاعدة حتمية' };

const props = defineProps({
  thread: { type: Object, required: true },
  topics: { type: Object, default: () => ({}) },
  staff: { type: Array, default: () => [] },
  attentionReasons: { type: Object, default: () => ({}) },
  canLinkClient: Boolean,
  canToggleBot: Boolean,
  canAssign: Boolean,
  showBack: Boolean,
  followup: Boolean,
  canReply: Boolean,
  canReprocess: Boolean,
});
const emit = defineEmits(['back', 'link', 'unlink', 'resolve', 'toggleBot', 'setTopic', 'assign', 'action']);
const hhmm = (iso) => new Date(iso).toLocaleTimeString('ar-JO-u-nu-latn', { hour: '2-digit', minute: '2-digit' });

// المواضيع غير المرتبطة بالمحادثة للإضافة اليدوية
const newTopic = ref('');
watch(() => props.thread.id, () => { newTopic.value = ''; });
const addable = computed(() => {
  const attached = (props.thread.topics || []).map((t) => t.key);
  return Object.entries(props.topics).filter(([key]) => !attached.includes(key)).map(([key, label]) => ({ key, label }));
});
const addTopic = () => {
  if (!newTopic.value) return;
  const key = newTopic.value;
  newTopic.value = '';
  emit('setTopic', key, true);
};
</script>
