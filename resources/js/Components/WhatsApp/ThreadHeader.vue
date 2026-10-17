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
          <p v-if="!panel && thread.profile_name && thread.profile_name !== thread.name" class="text-[11px] text-gray-400 dark:text-gray-500"
             title="اسم يضعه العميل بنفسه — غير موثّق">اسم واتساب: <bdi dir="auto">{{ thread.profile_name }}</bdi></p>
          <!-- مصدر المحادثة (E38): رمز أو رابط أو إعلان، قياس داخلي تقريبي -->
          <p v-if="!panel && thread.entry" data-test="entry-badge" class="text-[11px] text-sky-700 dark:text-sky-300 truncate"
             title="مصدر بدء المحادثة (قياس داخلي تقريبي)">
            <template v-if="thread.entry.channel === 'ad'">📣 من إعلان<template v-if="thread.entry.headline">: <bdi dir="auto">{{ thread.entry.headline }}</bdi></template></template>
            <template v-else>{{ thread.entry.channel === 'qr' ? '📷 من رمز' : '🔗 من رابط' }}<template v-if="thread.entry.title">: <bdi dir="auto">{{ thread.entry.title }}</bdi></template></template>
          </p>
        </div>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <!-- ربط بعميل (E11.8) -->
        <!-- مع اللوحة الجانبية (C128) تنتقل الإجراءات والتنظيم إليها، ويبقى هنا ما يُستعمل كل دقيقة -->
        <template v-if="!panel && canLinkClient && !thread.is_admin">
          <button type="button" @click="$emit('link')" class="min-h-11 lg:min-h-0 px-2.5 py-1 rounded-lg text-xs font-bold border border-blue-300 text-blue-700 dark:border-blue-700 dark:text-blue-300">
            {{ thread.client_id ? '🔗 تغيير الربط' : '🔗 ربط بعميل' }}
          </button>
          <button v-if="thread.client_id" type="button" @click="$emit('unlink')" class="min-h-11 lg:min-h-0 px-2.5 py-1 rounded-lg text-xs border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300">فك الربط</button>
        </template>
        <!-- المعاودة من هاتف الوكالة العادي (E39): الرقم للنسخ فقط، لا مكالمة من النظام -->
        <CallFromAgencyPhone v-if="calls && canReply && thread.phone" :phone="thread.phone"/>
        <span v-if="thread.needs_attention && thread.attention_reason" class="px-2 py-0.5 rounded-lg text-[11px] bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-300">{{ attentionReasons[thread.attention_reason] || thread.attention_reason }}</span>
        <!-- الحظر الرسمي (E31): بجانب «تجاوز الحد» لحظة المشكلة، ومعطّل خارج نافذة الـ24 ساعة (شرط ميتا) -->
        <button v-if="blockable && (!panel || urgentBlock)" type="button" data-test="block-btn" @click="$emit('block')" :disabled="!thread.window_open"
                :title="thread.window_open ? 'حظر هذا الرقم عند واتساب' : 'ميتا لا تسمح بالحظر إلا خلال 24 ساعة من آخر رسالة للعميل'"
                :aria-label="thread.window_open ? 'حظر هذا الرقم' : 'الحظر غير متاح: ميتا لا تسمح بالحظر إلا خلال 24 ساعة من آخر رسالة للعميل'"
                class="min-h-11 lg:min-h-0 px-2.5 py-1 rounded-lg text-xs font-bold border disabled:opacity-40 disabled:cursor-not-allowed"
                :class="thread.attention_reason === 'rate_limit' && thread.needs_attention
                  ? 'border-red-400 bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-300'
                  : 'border-red-300 text-red-700 dark:border-red-800 dark:text-red-300'">⛔ {{ thread.attention_reason === 'rate_limit' && thread.needs_attention ? 'حظر الرقم' : 'حظر' }}</button>
        <button v-if="thread.needs_attention" type="button" @click="$emit('resolve')" class="min-h-11 lg:min-h-0 px-2.5 py-1 rounded-lg text-xs font-bold text-green-700 bg-green-50 dark:bg-green-900/20 dark:text-green-300">✅ تمّت المعالجة</button>
        <!-- حالة المتابعة (E17) -->
        <span v-if="thread.closed" class="px-2 py-0.5 rounded-lg text-[11px] bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200">🗂️ مغلقة</span>
        <span v-if="thread.snoozed_until" class="px-2 py-0.5 rounded-lg text-[11px] bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300">💤 حتى <bdi dir="ltr">{{ hhmm(thread.snoozed_until) }}</bdi></span>
                <button v-if="canToggleBot" type="button" @click="$emit('toggleBot')" class="min-h-11 lg:min-h-0 px-2.5 py-1 rounded-lg text-xs font-bold border"
                :class="thread.bot_enabled ? 'border-gray-300 text-gray-600 dark:text-gray-300' : 'border-green-400 text-green-700 bg-green-50 dark:bg-green-900/20 dark:text-green-300'">
          {{ thread.bot_enabled ? '⏸️ إيقاف البوت' : '▶️ تشغيل البوت' }}
        </button>
        <!-- فلاتر الخيط والتصدير (E30) -->
        <button v-if="canFilter" type="button" @click="$emit('toggleFilters')" :aria-pressed="filtersOpen ? 'true' : 'false'" data-test="toggle-filters"
                class="min-h-11 lg:min-h-0 px-2.5 py-1 rounded-lg text-xs font-bold border"
                :class="filtersOpen ? 'border-gold-400 text-gold-700 dark:text-gold-400 bg-gold-50 dark:bg-gold-900/20' : 'border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300'">⏷ تصفية</button>
        <button v-if="!panel && canExport" type="button" @click="$emit('export')" data-test="open-export"
                class="min-h-11 lg:min-h-0 px-2.5 py-1 rounded-lg text-xs font-bold border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300">⬇️ تصدير</button>
        <ThreadActionsMenu v-if="!panel && followup" :thread="thread" :can-reply="canReply" :can-reprocess="canReprocess" @action="(n, p) => $emit('action', n, p)"/>
        <button v-if="panel" type="button" @click="$emit('togglePanel')" :aria-pressed="panelOpen ? 'true' : 'false'" data-test="panel-toggle"
                aria-label="لوحة العميل: الإجراءات والملاحظات والرحلات والفواتير" title="لوحة العميل"
                class="min-h-11 lg:min-h-0 px-2.5 py-1 rounded-lg text-xs font-bold border inline-flex items-center gap-1"
                :class="panelOpen ? 'border-gold-400 text-gold-800 dark:text-gold-300 bg-gold-50 dark:bg-gold-900/20' : 'border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200'">
          <span aria-hidden="true">ℹ️</span> العميل
          <span v-if="panelBadge" class="px-1.5 rounded-full text-[10px] bg-gold-500 text-black" data-test="panel-badge">{{ panelBadge }}</span>
        </button>
      </div>
    </div>

    <template v-if="!panel">
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
    </template>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { colorFor, initials } from '@/composables/whatsapp/useInitials.js';
import { formatPhone } from '@/composables/whatsapp/usePhoneFormat.js';
import CallFromAgencyPhone from './CallFromAgencyPhone.vue';
import ThreadActionsMenu from './ThreadActionsMenu.vue';

// مصدر الوسم: يدوي، أو آلي من البوت، أو من قاعدة حتمية (E37)
const TOPIC_SOURCES = { manual: 'وسم يدوي', bot: 'وسم آلي من البوت', rule: 'وسم آلي من قاعدة حتمية', qr: 'وسم من رمز أو رابط العرض', ad: 'وسم من إعلان العرض' };

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
  // E30
  canFilter: Boolean,
  filtersOpen: Boolean,
  canExport: Boolean,
  // E31: الحظر لمن يملك whatsapp.block والعلم مفعّل، ولا يُحظر رقم إدارة ولا موظف
  canBlock: Boolean,
  // E39: زر «اتصل من هاتف الوكالة» مع العلم calls_webhook
  calls: Boolean,
  // C128: اللوحة الجانبية مفعّلة، وحالتها، وشارة الرحلة القادمة على زرها
  panel: Boolean,
  panelOpen: Boolean,
  panelBadge: { type: String, default: '' },
});
const emit = defineEmits(['back', 'link', 'unlink', 'resolve', 'toggleBot', 'setTopic', 'assign', 'action', 'toggleFilters', 'export', 'block', 'togglePanel']);
const blockable = computed(() => props.canBlock && !props.thread.blocked && !props.thread.is_admin && !props.thread.is_staff);
// الحظر يبقى في الرأس مع اللوحة لحظة «تجاوز الحد» وحدها
const urgentBlock = computed(() => props.thread.attention_reason === 'rate_limit' && !!props.thread.needs_attention);
const hhmm = (iso) => new Date(iso).toLocaleTimeString('ar-JO-u-nu-latn', { hour: '2-digit', minute: '2-digit' });
// مصدر الوسم: يدوي، أو قاعدة، أو البوت، أو رمز QR ورابط، أو إعلان (E38)

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
