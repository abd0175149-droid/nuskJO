<template>
  <div class="space-y-3">
    <div class="rounded-xl border p-3.5 text-sm flex flex-wrap items-center gap-x-4 gap-y-2"
         :class="status.suspended
           ? 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800'
           : (status.bot_ready ? 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800'
                               : 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800')">
      <span class="font-bold">
        <template v-if="status.suspended">⛔ الإرسال موقوف — {{ SUSPEND_SOURCES[status.suspended_source] || 'قفل طارئ' }}</template>
        <template v-else-if="status.bot_ready">🟢 البوت يعمل</template>
        <template v-else-if="status.channel_ready">🟡 الأرقام مربوطة والبوت مطفأ</template>
        <template v-else>🟡 لا رقم مربوطاً</template>
      </span>
      <span class="text-gray-600 dark:text-gray-400 text-xs hidden sm:inline">
        <template v-if="!status.channel_ready">اربط رقماً من صفحة الأرقام، وأضف مفتاح الذكاء من الإعدادات لتشغيل البوت.</template>
        <template v-else-if="!status.bot_ready">المحادثات تعمل والموظفون يردّون؛ فعّل البوت من الإعدادات عند الجهوزية.</template>
        <template v-else>الموظف يمكنه الردّ في أي وقت — وردّه يوقف البوت مؤقتاً تلقائياً.</template>
      </span>
      <a href="/whatsapp/settings" class="text-gold-700 dark:text-gold-400 font-bold text-xs hover:underline ms-auto">⚙️ الإعدادات</a>
    </div>

    <!-- أرقام منقطعة أو موقوفة (2026-11-06): أسماؤها وحدها، والتفاصيل في صفحة الأرقام -->
    <div v-if="!status.suspended && channels.down.length" role="alert" data-test="channels-down"
         class="rounded-xl border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-3.5 text-sm font-bold text-red-700 dark:text-red-300 break-words">
      ⛔ {{ channels.down.length === 1 ? 'رقم غير متصل' : 'أرقام غير متصلة' }}: <bdi dir="auto">{{ channels.down.join('، ') }}</bdi> — لا يصل منها ولا إليها شيء حتى يُعاد ربطها.
      <a href="/whatsapp/channels" class="ms-1 underline">الأرقام</a>
    </div>
    <div v-if="!status.suspended && channels.paused.length" role="status" data-test="channels-paused"
         class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-2.5 text-xs font-bold text-amber-800 dark:text-amber-300 break-words">
      ⏸️ {{ channels.paused.length === 1 ? 'رقم موقوف مؤقتاً' : 'أرقام موقوفة مؤقتاً' }}: <bdi dir="auto">{{ channels.paused.join('، ') }}</bdi> — الرسائل الصادرة منها تنتظر في الطابور.
    </div>

    <!-- تجربة البوت (2026-11-06): نطاق الرد محصور في المحادثات المختارة -->
    <div v-if="!status.suspended && pilot && pilot.scope === 'pilot'" role="status" data-test="bot-pilot"
         class="rounded-xl border border-violet-200 dark:border-violet-800 bg-violet-50 dark:bg-violet-900/20 p-2.5 text-xs font-bold text-violet-800 dark:text-violet-300">
      🧪 البوت في وضع التجربة: يرد على {{ pilotText }} فقط، والباقي للموظفين.
      <a href="/whatsapp/bot-permissions" class="ms-1 underline">نطاق الرد</a>
    </div>

        <!-- وضع التحويل (E12): مزوّد الذكاء متعطّل -->
    <div v-if="llm.state && llm.state !== 'closed'" role="alert"
         class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-3.5 text-sm font-bold text-amber-800 dark:text-amber-300">
      🔌 البوت في وضع التحويل<span v-if="llm.opened_at"> منذ {{ hhmm(llm.opened_at) }}</span>. مزوّد الذكاء متعطّل — ردّوا على العملاء يدوياً.
    </div>

    <!-- البث اللحظي متوقف (E28): القائمة تبقى بالاستطلاع السريع، فلا حاجة لإجراء من الموظف -->
    <div v-if="realtime === 'unavailable' || realtime === 'failed'" role="status" data-test="realtime-off"
         class="rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 p-2.5 text-xs text-gray-600 dark:text-gray-300">
      ⚡ التحديث اللحظي متوقف — القائمة تتحدّث كل 15 ثانية حتى يعود الاتصال.
    </div>

    <div v-if="staleSince" role="status"
         class="rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 p-2.5 text-xs text-gray-600 dark:text-gray-300">
      ⚠️ تعذّر التحديث منذ {{ staleSince }} — تحقّق من الاتصال، وستتحدّث القائمة تلقائياً عند عودته.
    </div>

    <div v-if="notice" role="alert"
         class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-3.5 text-sm text-amber-800 dark:text-amber-300 flex items-start gap-3">
      <span class="flex-1">⚠️ {{ notice }}</span>
      <button type="button" @click="$emit('dismiss')" aria-label="إغلاق التنبيه" class="font-bold leading-none hover:text-amber-950 dark:hover:text-amber-100">✕</button>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { SUSPEND_SOURCES } from '@/utils/waSettings.js';

const props = defineProps({
  status: { type: Object, default: () => ({}) },
  llm: { type: Object, default: () => ({ state: 'closed' }) },
  staleSince: { type: String, default: '' },
  notice: { type: String, default: '' },
  // حالة المقبس للصندوق (E28): connected | connecting | unavailable | failed | disabled
  realtime: { type: String, default: 'disabled' },
});
defineEmits(['dismiss']);

const channels = computed(() => ({ down: props.status.channels?.down || [], paused: props.status.channels?.paused || [] }));

const pilot = computed(() => usePage()?.props?.waBotPilot);
const pilotText = computed(() => {
  const c = pilot.value?.conversations || 0;
  const p = pilot.value?.phones || 0;
  if (!c && !p) return 'لا محادثة (لم يُختر أحد بعد)';
  return [c ? `${c} محادثة` : '', p ? `${p} رقم` : ''].filter(Boolean).join(' و');
});

const hhmm = (iso) => new Date(iso).toLocaleTimeString('ar-JO', { hour: '2-digit', minute: '2-digit' });
</script>
